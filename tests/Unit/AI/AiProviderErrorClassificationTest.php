<?php

declare(strict_types=1);

use App\Domains\AI\Exceptions\AiProviderRequestException as E;
use App\Domains\AI\Providers\OpenAiProvider;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\{AiProvider, AiRiskLevel, AiRunMode};
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\{ConnectionException, RequestException, Response};
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Item 4 das notas do Leitor de Edital: limite por minuto e falta de crédito
 * chegam como o mesmo 429 e pedem respostas opostas. Corpos e headers no
 * formato documentado de cada provedor (montados à mão — não são respostas
 * gravadas da API real).
 */
function providerError(AiProvider $provider, int $status, array $body, array $headers = []): E
{
    return E::fromResponse($provider, new Response(new PsrResponse($status, $headers, json_encode($body))), 'msg');
}

describe('classificação', function () {
    it('OpenAI 429 rate_limit_exceeded: limite, com a espera do retry-after-ms', function () {
        $e = providerError(AiProvider::OpenAI, 429, ['error' => ['code' => 'rate_limit_exceeded', 'type' => 'tokens', 'message' => 'Rate limit reached for gpt-5-mini on tokens per min (TPM).']], ['retry-after-ms' => '1500']);

        expect($e->category)->toBe(E::CATEGORY_RATE_LIMIT)
            ->and($e->retryAfterSeconds)->toBe(1.5)
            ->and($e->isRetryable())->toBeTrue()
            ->and($e->needsHumanAction())->toBeFalse();
    });

    it('OpenAI 429 sem header: lê o "try again in" do texto (minutos e segundos)', function () {
        $e = providerError(AiProvider::OpenAI, 429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached for requests per day (RPD). Please try again in 6m0s.']]);

        expect($e->retryAfterSeconds)->toBe(360.0);
    });

    it('OpenAI 429 só com x-ratelimit-reset-*: usa o maior (não se sabe qual limite estourou)', function () {
        $e = providerError(AiProvider::OpenAI, 429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached.']], [
            'x-ratelimit-reset-requests' => '1s',
            'x-ratelimit-reset-tokens'   => '20ms',
        ]);

        expect($e->retryAfterSeconds)->toBe(1.0);
    });

    it('OpenAI 429 insufficient_quota: sem crédito — não repete, precisa de uma pessoa', function () {
        $e = providerError(AiProvider::OpenAI, 429, ['error' => ['code' => 'insufficient_quota', 'type' => 'insufficient_quota', 'message' => 'You exceeded your current quota, please check your plan and billing details.']], ['retry-after' => '20']);

        expect($e->category)->toBe(E::CATEGORY_QUOTA)
            ->and($e->accountWide)->toBeTrue() // código estruturado: vale para a conta toda
            ->and($e->retryAfterSeconds)->toBeNull()
            ->and($e->isRetryable())->toBeFalse()
            ->and($e->needsHumanAction())->toBeTrue()
            ->and($e->errorCode)->toBe('insufficient_quota');
    });

    it('OpenAI 429 "Request too large" (maior que o TPM do modelo): esperar não adianta', function () {
        $e = providerError(AiProvider::OpenAI, 429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Request too large for gpt-4o on tokens per min (TPM): Limit 30000, Requested 52000.']]);

        expect($e->category)->toBe(E::CATEGORY_TOO_LARGE)
            ->and($e->isRetryable())->toBeFalse();
    });

    it('OpenAI 401 invalid_api_key: chave recusada, da conta toda; 403 sem código: chave, mas não da conta', function () {
        $key       = providerError(AiProvider::OpenAI, 401, ['error' => ['code' => 'invalid_api_key', 'message' => 'Incorrect API key provided.']]);
        $forbidden = providerError(AiProvider::OpenAI, 403, ['error' => ['message' => 'Forbidden.']]);

        expect($key->category)->toBe(E::CATEGORY_AUTH)
            ->and($key->accountWide)->toBeTrue()
            ->and($forbidden->category)->toBe(E::CATEGORY_AUTH)
            ->and($forbidden->accountWide)->toBeFalse();
    });

    it('Anthropic sem saldo vem como 400 invalid_request_error: é falta de crédito', function () {
        $e = providerError(AiProvider::Anthropic, 400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.']]);

        // Só o texto diz "sem saldo": a categoria é a mesma, mas não suspende as outras clínicas.
        expect($e->category)->toBe(E::CATEGORY_QUOTA)
            ->and($e->accountWide)->toBeFalse();
    });

    it('Anthropic 429 rate_limit_error com retry-after em segundos; 529 overloaded_error é passageiro', function () {
        $limit = providerError(AiProvider::Anthropic, 429, ['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'Number of request tokens has exceeded your per-minute rate limit.']], ['retry-after' => '20']);
        $busy  = providerError(AiProvider::Anthropic, 529, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]);

        expect($limit->category)->toBe(E::CATEGORY_RATE_LIMIT)
            ->and($limit->retryAfterSeconds)->toBe(20.0)
            ->and($busy->category)->toBe(E::CATEGORY_OVERLOADED)
            ->and($busy->isRetryable())->toBeTrue();
    });

    it('Gemini 429 RESOURCE_EXHAUSTED por minuto: limite, com o RetryInfo; por dia: cota', function () {
        $details = fn (string $quotaId) => [
            ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => $quotaId]]],
            ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '34s'],
        ];
        $minute = providerError(AiProvider::Gemini, 429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota.', 'details' => $details('GenerateRequestsPerMinutePerProjectPerModel')]]);
        $day    = providerError(AiProvider::Gemini, 429, ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota.', 'details' => $details('GenerateRequestsPerDayPerProjectPerModel-FreeTier')]]);

        expect($minute->category)->toBe(E::CATEGORY_RATE_LIMIT)
            ->and($minute->retryAfterSeconds)->toBe(34.0)
            ->and($day->category)->toBe(E::CATEGORY_QUOTA)
            ->and($day->accountWide)->toBeTrue(); // QuotaFailure estruturado
    });

    it('Gemini 400 "API key not valid": chave recusada, inferida do texto (não da conta)', function () {
        $e = providerError(AiProvider::Gemini, 400, ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid. Please pass a valid API key.']]);

        expect($e->category)->toBe(E::CATEGORY_AUTH)
            ->and($e->accountWide)->toBeFalse();
    });

    it('5xx é passageiro; demais 4xx são erro do pedido', function () {
        expect(providerError(AiProvider::Mistral, 503, ['message' => 'Service unavailable'])->category)->toBe(E::CATEGORY_OVERLOADED)
            ->and(providerError(AiProvider::Mistral, 400, ['message' => 'Invalid model'])->category)->toBe(E::CATEGORY_REQUEST);
    });
});

it('circuito aberto: a exceção traz o motivo da última falha e a espera até reabrir', function () {
    $quota = E::circuitOpen(AiProvider::OpenAI, 'quota', now()->addMinutes(10));
    $other = E::circuitOpen(AiProvider::OpenAI, 'server_error', now()->addSeconds(30));

    expect($quota->category)->toBe(E::CATEGORY_QUOTA)
        ->and($quota->httpStatus)->toBe(0)
        ->and($quota->retryAfterSeconds)->toBeGreaterThan(590.0)
        ->and($other->category)->toBe(E::CATEGORY_OVERLOADED)
        ->and($other->retryAfterSeconds)->toBeLessThanOrEqual(30.0);
});

it('retry HTTP imediato só para conexão caída e 5xx/408 — nunca 429 nem outros 4xx', function () {
    $failed = fn (int $status) => new RequestException(new Response(new PsrResponse($status)));

    expect(E::shouldRetryHttp(new ConnectionException('cURL error 7')))->toBeTrue()
        ->and(E::shouldRetryHttp($failed(503)))->toBeTrue()
        ->and(E::shouldRetryHttp($failed(408)))->toBeTrue()
        ->and(E::shouldRetryHttp($failed(429)))->toBeFalse()
        ->and(E::shouldRetryHttp($failed(400)))->toBeFalse();
});

describe('provedor real (OpenAI)', function () {
    beforeEach(function () {
        config()->set('services.openai.api_key', 'openai-key-test');
        config()->set('ai.providers.openai.model', 'gpt-5-mini');
        config()->set('ai.providers.openai.base_url', 'https://api.openai.com/v1');
        config()->set('ai.providers.openai.retry_times', 2);
        config()->set('ai.providers.openai.retry_sleep_ms', 0);
    });

    $request = fn () => new AiRequestData(
        workflow: 'medicine_posology',
        mode: AiRunMode::Economy,
        userPrompt: 'Sugira a posologia.',
        systemPrompt: 'Apoio.',
        riskLevel: AiRiskLevel::Medium,
    );

    it('sem crédito: UMA requisição (sem retry), exceção classificada e a mesma mensagem de sempre', function () use ($request) {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['error' => ['code' => 'insufficient_quota', 'type' => 'insufficient_quota', 'message' => 'You exceeded your current quota.']], 429)]);

        try {
            (new OpenAiProvider())->generate($request());
            $this->fail('deveria lançar');
        } catch (E $e) {
            expect($e->category)->toBe(E::CATEGORY_QUOTA)
                ->and($e->getMessage())->toStartWith('OpenAI request failed [429/insufficient_quota]:');
        }

        Http::assertSentCount(1);
    });

    it('503 passageiro: tenta de novo e segue', function () use ($request) {
        Http::fakeSequence('https://api.openai.com/v1/responses')
            ->push(['error' => ['message' => 'The server had an error.']], 503)
            ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ok']]]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200);

        expect((new OpenAiProvider())->generate($request())->content)->toBe('ok');

        Http::assertSentCount(2);
    });
});
