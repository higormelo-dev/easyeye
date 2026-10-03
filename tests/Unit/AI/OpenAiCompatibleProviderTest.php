<?php

declare(strict_types=1);

use App\Domains\AI\Providers\Fakes\CompatibleFakeProvider;
use App\Domains\AI\Providers\OpenAiCompatibleProvider;
use App\Domains\AI\Services\{AiProviderManager, AiProviderSettings};
use App\Domains\AI\Support\ProviderErrorSanitizer;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\{AiProvider, AiRiskLevel, AiRunMode};
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Driver "compatível com OpenAI" (Mistral, Groq, xAI, Azure OpenAI, Maritaca):
 * protocolo /chat/completions, separação saída × raciocínio para a cobrança,
 * erros sanitizados e chave só do .env.
 */
beforeEach(function () {
    config()->set('services.groq.api_key', 'gsk_groqtestkey1234');
    config()->set('ai.providers.groq.base_url', 'https://api.groq.com/openai/v1');
    config()->set('ai.providers.groq.model', 'llama-3.3-70b-versatile');
});

function compatibleRequest(array $overrides = []): AiRequestData
{
    return new AiRequestData(...[
        'workflow'        => 'report_drafting',
        'mode'            => AiRunMode::Economy,
        'userPrompt'      => 'Gerar rascunho de laudo.',
        'systemPrompt'    => 'Apoio clínico ao médico.',
        'riskLevel'       => AiRiskLevel::Medium,
        'expectsJson'     => true,
        'maxOutputTokens' => 500,
        ...$overrides,
    ]);
}

function chatCompletion(array $usage, mixed $content = '{"ok":true}', string $model = 'llama-3.3-70b-versatile'): array
{
    return [
        'id'      => 'chatcmpl-1',
        'model'   => $model,
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage'   => $usage,
    ];
}

it('envia /chat/completions com chave, modelo, mensagens e modo JSON e lê a resposta', function () {
    Http::fake(['https://api.groq.com/openai/v1/chat/completions' => Http::response(chatCompletion([
        'prompt_tokens' => 120, 'completion_tokens' => 80, 'total_tokens' => 200,
    ]))]);

    $result = (new OpenAiCompatibleProvider(AiProvider::Groq))->generate(compatibleRequest());

    expect($result->provider)->toBe(AiProvider::Groq)
        ->and($result->model)->toBe('llama-3.3-70b-versatile')
        ->and($result->content)->toBe('{"ok":true}')
        ->and($result->usage->inputTokens)->toBe(120)
        ->and($result->usage->outputTokens)->toBe(80)
        ->and($result->usage->reasoningTokens)->toBe(0)
        ->and($result->usage->rawCostUsd)->toBeNull()
        ->and($result->finishReason)->toBe('stop');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer gsk_groqtestkey1234')
            && $data['model'] === 'llama-3.3-70b-versatile'
            && $data['messages'][0] === ['role' => 'system', 'content' => 'Apoio clínico ao médico.']
            && $data['messages'][1]['role'] === 'user'
            // Sem imagem: texto puro (formato aceito por todos os compatíveis).
            && is_string($data['messages'][1]['content'])
            && str_contains($data['messages'][1]['content'], 'JSON')
            && $data['max_tokens'] === 500
            && $data['response_format'] === ['type' => 'json_object'];
    });
});

it('raciocínio contado DENTRO da saída (padrão OpenAI): a saída fica só com o texto visível', function () {
    Http::fake(['*' => Http::response(chatCompletion([
        'prompt_tokens'             => 100,
        'completion_tokens'         => 300,
        'total_tokens'              => 400,
        'completion_tokens_details' => ['reasoning_tokens' => 220],
    ]))]);

    $usage = (new OpenAiCompatibleProvider(AiProvider::Groq))->generate(compatibleRequest())->usage;

    expect($usage->outputTokens)->toBe(80)->and($usage->reasoningTokens)->toBe(220);
});

it('raciocínio contado À PARTE (total = entrada + saída + raciocínio): saída mantida', function () {
    config()->set('services.xai.api_key', 'xai-test-key-000000');
    config()->set('ai.providers.xai.base_url', 'https://api.x.ai/v1');
    Http::fake(['https://api.x.ai/v1/chat/completions' => Http::response(chatCompletion([
        'prompt_tokens'             => 32,
        'completion_tokens'         => 9,
        'total_tokens'              => 135,
        'completion_tokens_details' => ['reasoning_tokens' => 94],
    ], 'ok', 'grok-4.5'))]);

    $usage = (new OpenAiCompatibleProvider(AiProvider::XAi))->generate(compatibleRequest())->usage;

    expect($usage->outputTokens)->toBe(9)->and($usage->reasoningTokens)->toBe(94);
});

it('com imagem envia a lista de partes (texto + image_url em data URL)', function () {
    config()->set('services.mistral.api_key', 'mistral-test-key-0000');
    config()->set('ai.providers.mistral.base_url', 'https://api.mistral.ai/v1');
    Http::fake(['*' => Http::response(chatCompletion(['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]))]);

    (new OpenAiCompatibleProvider(AiProvider::Mistral))->generate(compatibleRequest([
        'attachments' => [['data' => 'QUJD', 'mime_type' => 'image/png']],
    ]));

    Http::assertSent(function (Request $request): bool {
        $content = $request->data()['messages'][1]['content'];

        return is_array($content)
            && $content[0]['type'] === 'text'
            && $content[1] === ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QUJD']];
    });
});

it('junta a resposta em partes (formato do Mistral)', function () {
    Http::fake(['*' => Http::response(chatCompletion(
        ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        [['type' => 'thinking', 'thinking' => '...'], ['type' => 'text', 'text' => 'Parte 1'], ['type' => 'text', 'text' => 'Parte 2']],
    ))]);

    $result = (new OpenAiCompatibleProvider(AiProvider::Groq))->generate(compatibleRequest());

    expect($result->content)->toBe("Parte 1\nParte 2");
});

it('modo JSON desligado por provedor não manda response_format', function () {
    config()->set('ai.providers.groq.json_mode', false);
    Http::fake(['*' => Http::response(chatCompletion(['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]))]);

    (new OpenAiCompatibleProvider(AiProvider::Groq))->generate(compatibleRequest());

    Http::assertSent(fn (Request $request) => ! array_key_exists('response_format', $request->data()));
});

it('usa o modelo escolhido no painel (fallback .env)', function () {
    // Teste unitário sem banco: o setting do painel vem do cache.
    Cache::put('subscription_setting:' . AiProviderSettings::MODELS_SETTING_KEY, json_encode(['groq' => 'openai/gpt-oss-120b']), 600);
    Http::fake(['*' => Http::response(chatCompletion(['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]))]);

    (new OpenAiCompatibleProvider(AiProvider::Groq))->generate(compatibleRequest());

    Http::assertSent(fn (Request $request) => $request->data()['model'] === 'openai/gpt-oss-120b');
});

it('[SEGURANÇA] erro HTTP vira mensagem sanitizada com o provedor e o status — sem a chave', function () {
    Http::fake(['*' => Http::response([
        'error' => ['message' => "Authentication Fails\n(key gsk_groqtestkey1234 invalid) contato joao@clinica.com", 'type' => 'authentication_error'],
    ], 401)]);

    try {
        (new OpenAiCompatibleProvider(AiProvider::Groq))->generate(compatibleRequest());
        $this->fail('Deveria lançar exceção.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Groq request failed [401/authentication_error]')
            ->not->toContain("\n")
            ->not->toContain('gsk_groqtestkey1234')
            ->not->toContain('joao@clinica.com');
    }
});

it('[SEGURANÇA] chave sem prefixo conhecido (Mistral) ecoada no erro é removida', function () {
    config()->set('services.mistral.api_key', 'chave-de-teste-sem-prefixo-conhecido');
    config()->set('ai.providers.mistral.base_url', 'https://api.mistral.ai/v1');
    Http::fake(['*' => Http::response(['message' => 'Unauthorized key chave-de-teste-sem-prefixo-conhecido', 'type' => 'auth'], 401)]);

    try {
        (new OpenAiCompatibleProvider(AiProvider::Mistral))->generate(compatibleRequest());
        $this->fail('Deveria lançar exceção.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('[REDACTED:KEY]')->not->toContain('chave-de-teste-sem-prefixo-conhecido');
    }
});

it('[SEGURANÇA] sanitizador reconhece chaves Groq (gsk_) e xAI (xai-)', function () {
    $message = ProviderErrorSanitizer::sanitize('bad key gsk_ABCdef1234567890 and xai-ZZZ999yyy888xxx');

    expect($message)->not->toContain('gsk_ABCdef1234567890')->not->toContain('xai-ZZZ999yyy888xxx')
        ->and(substr_count($message, '[REDACTED:KEY]'))->toBe(2);
});

it('formato de erro do Mistral ({message, type}) também é lido', function () {
    config()->set('services.mistral.api_key', 'mistral-test-key-0000');
    config()->set('ai.providers.mistral.base_url', 'https://api.mistral.ai/v1');
    Http::fake(['*' => Http::response(['object' => 'error', 'message' => 'Invalid model', 'type' => 'invalid_model'], 400)]);

    expect(fn () => (new OpenAiCompatibleProvider(AiProvider::Mistral))->generate(compatibleRequest()))
        ->toThrow(RuntimeException::class, 'Mistral AI request failed [400/invalid_model]: Invalid model');
});

it('[SEGURANÇA] sem chave no .env lança antes de qualquer chamada', function () {
    config()->set('services.groq.api_key', null);
    Http::fake();

    expect(fn () => (new OpenAiCompatibleProvider(AiProvider::Groq))->generate(compatibleRequest()))
        ->toThrow(RuntimeException::class, 'Groq API key não configurada.');

    Http::assertNothingSent();
});

it('recusa provedor com integração própria', function () {
    expect(fn () => new OpenAiCompatibleProvider(AiProvider::OpenAI))->toThrow(InvalidArgumentException::class);
});

it('o gerenciador registra a lista pronta inteira (fake e real)', function () {
    $manager = app(AiProviderManager::class);

    foreach (AiProvider::cases() as $provider) {
        expect($manager->get($provider)->provider())->toBe($provider);
    }

    expect($manager->get(AiProvider::Groq))->toBeInstanceOf(CompatibleFakeProvider::class);

    config()->set('ai.provider_runtime', 'real');
    app()->forgetInstance(AiProviderManager::class);

    expect(app(AiProviderManager::class)->get(AiProvider::XAi))->toBeInstanceOf(OpenAiCompatibleProvider::class);
});

describe('Azure OpenAI e Maritaca', function () {
    it('Azure: autentica pelo header "api-key" (sem Bearer) e usa o deployment como modelo', function () {
        config()->set('services.azure_openai.api_key', 'azure-test-key-0000000000');
        config()->set('ai.providers.azure_openai.base_url', 'https://easyeye-se.openai.azure.com/openai/v1');
        config()->set('ai.providers.azure_openai.model', 'gpt-4o-mini');
        Http::fake(['*' => Http::response(chatCompletion(['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2], model: 'gpt-4o-mini'))]);

        (new OpenAiCompatibleProvider(AiProvider::AzureOpenAI))->generate(compatibleRequest());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://easyeye-se.openai.azure.com/openai/v1/chat/completions'
            && $request->header('api-key') === ['azure-test-key-0000000000']
            && ! $request->hasHeader('Authorization')
            && $request->data()['model'] === 'gpt-4o-mini');
    });

    it('Azure sem endereço no .env falha antes de enviar qualquer dado', function () {
        config()->set('services.azure_openai.api_key', 'azure-test-key-0000000000');
        config()->set('ai.providers.azure_openai.base_url', null);
        Http::fake();

        expect(fn () => (new OpenAiCompatibleProvider(AiProvider::AzureOpenAI))->generate(compatibleRequest()))
            ->toThrow(RuntimeException::class, 'endereço da API não configurado');

        Http::assertNothingSent();
        expect(app(AiProviderSettings::class)->isConfigured('azure_openai'))->toBeFalse();
    });

    it('Maritaca: Bearer no endereço padrão com o modelo Sabiá do Brasil', function () {
        config()->set('services.maritaca.api_key', 'maritaca-test-key-000000');
        Http::fake(['*' => Http::response(chatCompletion(['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2], model: 'sabia-4-br-sp'))]);

        (new OpenAiCompatibleProvider(AiProvider::Maritaca))->generate(compatibleRequest());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://chat.maritaca.ai/api/chat/completions'
            && $request->header('Authorization') === ['Bearer maritaca-test-key-000000']
            && $request->data()['model'] === 'sabia-4-br-sp');
    });

    it('[LGPD] exame com imagem nunca vai a quem não tem visão (Maritaca faria OCR com terceiros)', function () {
        config()->set('services.maritaca.api_key', 'maritaca-test-key-000000');
        Http::fake();

        expect(fn () => (new OpenAiCompatibleProvider(AiProvider::Maritaca))->generate(compatibleRequest([
            'attachments' => [['data' => 'QUJD', 'mime_type' => 'image/png']],
        ])))->toThrow(RuntimeException::class, 'não processa imagens');

        Http::assertNothingSent();
        expect((new OpenAiCompatibleProvider(AiProvider::Groq))->supportsVision())->toBeTrue();
    });
});
