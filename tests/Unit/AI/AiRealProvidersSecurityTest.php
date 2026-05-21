<?php

declare(strict_types=1);

use App\Domains\AI\Providers\AnthropicProvider;
use App\Domains\AI\Providers\GeminiProvider;
use App\Domains\AI\Providers\OpenAiProvider;
use App\Domains\AI\Support\ProviderErrorSanitizer;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\AiRiskLevel;
use App\Enums\AI\AiRunMode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    config()->set('services.openai.api_key', 'openai-test-key');
    config()->set('ai.providers.openai.model', 'gpt-5-mini');
    config()->set('ai.providers.openai.base_url', 'https://api.openai.com/v1');

    config()->set('services.anthropic.api_key', 'anthropic-test-key');
    config()->set('ai.providers.anthropic.model', 'claude-sonnet-4-5');
    config()->set('ai.providers.anthropic.base_url', 'https://api.anthropic.com');

    config()->set('services.gemini.api_key', 'gemini-test-key');
    config()->set('ai.providers.gemini.model', 'gemini-2.0-flash');
    config()->set('ai.providers.gemini.base_url', 'https://generativelanguage.googleapis.com');
});

function basicRequest(): AiRequestData
{
    return new AiRequestData(
        workflow: 'report_drafting',
        mode: AiRunMode::Economy,
        userPrompt: 'Conteúdo sensível: paciente João, CPF 123.456.789-00, queixa principal X.',
        riskLevel: AiRiskLevel::Medium,
    );
}

// ── Sanitização de erros ─────────────────────────────────────────────────────

test('OpenAI: erro HTTP é sanitizado (quebras de linha, controle, tamanho)', function () {
    $rawMessage = "Linha 1\nLinha 2\r\nLinha 3 com \x00 caracter de controle " . str_repeat('x', 800);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'error' => ['code' => 'rate_limited', 'message' => $rawMessage],
        ], 429),
    ]);

    $provider = new OpenAiProvider();

    try {
        $provider->generate(basicRequest());
        $this->fail('Era esperado RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('OpenAI request failed [429/rate_limited]:');
        expect($e->getMessage())->not->toContain("\n");
        expect($e->getMessage())->not->toContain("\r");
        expect($e->getMessage())->not->toContain("\x00");
        expect(mb_strlen($e->getMessage()))->toBeLessThan(700);
    }
});

test('Anthropic: erro HTTP é sanitizado e usa fallback para mensagem vazia', function () {
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'error' => ['type' => 'authentication_error', 'message' => ''],
        ], 401),
    ]);

    $provider = new AnthropicProvider();

    try {
        $provider->generate(basicRequest());
        $this->fail('Era esperado RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Anthropic request failed [401/authentication_error]:');
        expect($e->getMessage())->toContain('Falha na integração Anthropic.');
    }
});

test('Gemini: erro HTTP é sanitizado', function () {
    $longMessage = str_repeat('Detalhe sensível ', 100);

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent' => Http::response([
            'error' => ['message' => $longMessage],
        ], 400),
    ]);

    $provider = new GeminiProvider();

    try {
        $provider->generate(basicRequest());
        $this->fail('Era esperado RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Gemini request failed [400]:');
        expect(mb_strlen($e->getMessage()))->toBeLessThan(600);
    }
});

test('erro do provider que ecoa payload PHI é truncado (segurança LGPD)', function () {
    // Simula um provider que ecoa o prompt no erro — cenário real em APIs imaturas
    // ou quando o provider devolve "Bad request: prompt contains ... <PHI>".
    $phiPrompt = 'Paciente João Silva, CPF 123.456.789-00. Achado: descolamento de retina à OD.';
    $providerEchoMessage = "Bad request: prompt rejected. Content snippet: \"{$phiPrompt}\". " . str_repeat('extra data ', 80);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'error' => ['code' => 'invalid_request', 'message' => $providerEchoMessage],
        ], 400),
    ]);

    $provider = new OpenAiProvider();

    try {
        $provider->generate(basicRequest());
        $this->fail('Era esperado RuntimeException');
    } catch (RuntimeException $e) {
        // Não deve expor identificadores diretos quando o provedor ecoa conteúdo
        // do prompt em erro.
        expect($e->getMessage())->not->toContain('123.456.789-00');
        expect(
            str_contains($e->getMessage(), '[REDACTED:CPF]')
            || str_contains($e->getMessage(), '[REDACTED:PAYLOAD]')
        )->toBeTrue();
        expect(mb_strlen($e->getMessage()))->toBeLessThan(700);
    }
});

test('ProviderErrorSanitizer: sanitize aplica fallback para null e string vazia', function () {
    expect(ProviderErrorSanitizer::sanitize(null))->toBe('Falha desconhecida na integração com provedor de IA.');
    expect(ProviderErrorSanitizer::sanitize(''))->toBe('Falha desconhecida na integração com provedor de IA.');
    expect(ProviderErrorSanitizer::sanitize('   '))->toBe('Falha desconhecida na integração com provedor de IA.');
    expect(ProviderErrorSanitizer::sanitize(null, 'custom default'))->toBe('custom default');
});

test('ProviderErrorSanitizer: trunca mensagens longas a 500 chars com elipse', function () {
    $long = str_repeat('a', 1000);
    $result = ProviderErrorSanitizer::sanitize($long);

    expect(mb_strlen($result))->toBe(500);
    expect(str_ends_with($result, '...'))->toBeTrue();
});

test('ProviderErrorSanitizer: redige CNPJ, email, telefone e CNS', function () {
    $raw = 'CPF 123.456.789-00; CNPJ 12.345.678/0001-90; e-mail joao.silva@clinicax.com.br; telefone +55 (11) 98888-7777; CNS 123456789012345';
    $result = ProviderErrorSanitizer::sanitize($raw);

    expect($result)->toContain('[REDACTED:CPF]');
    expect($result)->toContain('[REDACTED:CNPJ]');
    expect($result)->toContain('[REDACTED:EMAIL]');
    expect($result)->toContain('[REDACTED:PHONE]');
    expect($result)->toContain('[REDACTED:CNS]');
    expect($result)->not->toContain('123.456.789-00');
    expect($result)->not->toContain('12.345.678/0001-90');
    expect($result)->not->toContain('joao.silva@clinicax.com.br');
    expect($result)->not->toContain('+55 (11) 98888-7777');
    expect($result)->not->toContain('123456789012345');
});

// ── API key ausente ──────────────────────────────────────────────────────────

test('OpenAI lança exceção explícita quando API key não configurada', function () {
    config()->set('services.openai.api_key', '');

    $provider = new OpenAiProvider();

    expect(fn () => $provider->generate(basicRequest()))
        ->toThrow(\RuntimeException::class, 'OpenAI API key não configurada.');
});

test('Anthropic lança exceção explícita quando API key não configurada', function () {
    config()->set('services.anthropic.api_key', '');

    $provider = new AnthropicProvider();

    expect(fn () => $provider->generate(basicRequest()))
        ->toThrow(\RuntimeException::class, 'Anthropic API key não configurada.');
});

test('Gemini lança exceção explícita quando API key não configurada', function () {
    config()->set('services.gemini.api_key', '');

    $provider = new GeminiProvider();

    expect(fn () => $provider->generate(basicRequest()))
        ->toThrow(\RuntimeException::class, 'Gemini API key não configurada.');
});

// ── Hashes determinísticos ───────────────────────────────────────────────────

test('OpenAI: requestHash é determinístico para o mesmo payload', function () {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_xxx',
            'model' => 'gpt-5-mini',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ok']]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200),
    ]);

    $provider = new OpenAiProvider();

    $request = new AiRequestData(
        workflow: 'wf',
        mode: AiRunMode::Economy,
        userPrompt: 'mesmo prompt',
        riskLevel: AiRiskLevel::Low,
    );

    $first = $provider->generate($request);
    $second = $provider->generate($request);

    expect($first->requestHash)->toBe($second->requestHash);
    expect($first->requestHash)->toHaveLength(64); // SHA-256 hex
});

// ── Anexos de visão (vision) ─────────────────────────────────────────────────

test('OpenAI: anexos de imagem viram input_image no payload', function () {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'r1', 'model' => 'gpt-5-mini',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ok']]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200),
    ]);

    (new OpenAiProvider())->generate(new AiRequestData(
        workflow: 'exam_assistant',
        mode: AiRunMode::Economy,
        userPrompt: 'Descreva achados da retina.',
        attachments: [
            ['image_url' => 'https://files.example/r1.jpg', 'detail' => 'high'],
        ],
    ));

    Http::assertSent(function (Request $request): bool {
        $content = data_get($request->data(), 'input.0.content', []);
        $images = array_filter($content, fn ($c) => ($c['type'] ?? null) === 'input_image');
        $first = array_values($images)[0] ?? null;

        return count($images) === 1
            && $first['image_url'] === 'https://files.example/r1.jpg'
            && $first['detail'] === 'high';
    });
});

test('Anthropic: anexos de imagem viram type=image com source url', function () {
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'm1', 'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200),
    ]);

    (new AnthropicProvider())->generate(new AiRequestData(
        workflow: 'exam_assistant',
        mode: AiRunMode::Economy,
        userPrompt: 'analise',
        attachments: [['image_url' => 'https://files.example/r1.jpg']],
    ));

    Http::assertSent(function (Request $request): bool {
        $content = data_get($request->data(), 'messages.0.content', []);
        $images = array_filter($content, fn ($c) => ($c['type'] ?? null) === 'image');
        $first = array_values($images)[0] ?? null;

        return count($images) === 1
            && data_get($first, 'source.type') === 'url'
            && data_get($first, 'source.url') === 'https://files.example/r1.jpg';
    });
});

test('Gemini: anexos viram parts.file_data com mime_type', function () {
    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent' => Http::response([
            'responseId' => 'g1', 'modelVersion' => 'gemini-2.0-flash',
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'ok']]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ], 200),
    ]);

    (new GeminiProvider())->generate(new AiRequestData(
        workflow: 'exam_assistant',
        mode: AiRunMode::Economy,
        userPrompt: 'analise',
        attachments: [['file_uri' => 'https://files.example/r1.jpg', 'mime_type' => 'image/png']],
    ));

    Http::assertSent(function (Request $request): bool {
        $parts = data_get($request->data(), 'contents.0.parts', []);
        $fileParts = array_filter($parts, fn ($p) => isset($p['file_data']));
        $first = array_values($fileParts)[0] ?? null;

        return count($fileParts) === 1
            && data_get($first, 'file_data.file_uri') === 'https://files.example/r1.jpg'
            && data_get($first, 'file_data.mime_type') === 'image/png';
    });
});

// ── Tool calls counting ──────────────────────────────────────────────────────

test('OpenAI: countToolCalls reconhece function_call e tool_call no output', function () {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'r1', 'model' => 'gpt-5-mini',
            'output' => [
                ['type' => 'function_call', 'name' => 'lookup'],
                ['type' => 'reasoning_tool_call'],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'final']]],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200),
    ]);

    $result = (new OpenAiProvider())->generate(basicRequest());

    expect($result->usage->toolCallsCount)->toBe(2);
});

test('Anthropic: countToolCalls reconhece tool_use no content', function () {
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'm1', 'model' => 'claude-sonnet-4-5',
            'content' => [
                ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'lookup'],
                ['type' => 'text', 'text' => 'final'],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200),
    ]);

    $result = (new AnthropicProvider())->generate(basicRequest());

    expect($result->usage->toolCallsCount)->toBe(1);
});

// ── User-Agent (identificação ao provider) ───────────────────────────────────

test('OpenAI envia User-Agent configurado no header', function () {
    config()->set('ai.user_agent', 'EasyEye/1.0 (+https://easyeye.com.br)');

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'r1', 'model' => 'gpt-5-mini',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ok']]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200),
    ]);

    (new OpenAiProvider())->generate(basicRequest());

    Http::assertSent(fn (Request $request): bool =>
        $request->hasHeader('User-Agent', 'EasyEye/1.0 (+https://easyeye.com.br)')
    );
});

test('Anthropic envia User-Agent configurado no header', function () {
    config()->set('ai.user_agent', 'EasyEye/1.0 (+https://easyeye.com.br)');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'm1', 'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200),
    ]);

    (new AnthropicProvider())->generate(basicRequest());

    Http::assertSent(fn (Request $request): bool =>
        $request->hasHeader('User-Agent', 'EasyEye/1.0 (+https://easyeye.com.br)')
    );
});

test('Gemini envia User-Agent configurado no header', function () {
    config()->set('ai.user_agent', 'EasyEye/1.0 (+https://easyeye.com.br)');

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent' => Http::response([
            'responseId' => 'g1', 'modelVersion' => 'gemini-2.0-flash',
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'ok']]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ], 200),
    ]);

    (new GeminiProvider())->generate(basicRequest());

    Http::assertSent(fn (Request $request): bool =>
        $request->hasHeader('User-Agent', 'EasyEye/1.0 (+https://easyeye.com.br)')
    );
});
