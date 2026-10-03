<?php

declare(strict_types=1);

namespace App\Domains\AI\Providers;

use App\Domains\AI\Contracts\AiProviderInterface;
use App\Domains\AI\Services\AiProviderSettings;
use App\Domains\AI\Support\{PromptComposer, ProviderErrorSanitizer};
use App\DTOs\AI\{AiProviderResponseData, AiRequestData, AiUsageData};
use App\Enums\AI\AiProvider;
use Illuminate\Http\Client\{PendingRequest, Response};
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Driver dos provedores que falam o protocolo "compatível com OpenAI"
 * (POST {base_url}/chat/completions): Mistral, Groq, xAI, Azure OpenAI
 * (API v1, header "api-key") e Maritaca. Uma
 * instância por provedor — URL, modelo e timeouts vêm de config/ai.php
 * (providers.{código}); a chave, de config/services.php (.env).
 */
class OpenAiCompatibleProvider implements AiProviderInterface
{
    public function __construct(private readonly AiProvider $provider)
    {
        if (! $provider->isOpenAiCompatible()) {
            throw new InvalidArgumentException("Provider IA [{$provider->value}] tem integração própria.");
        }
    }

    public function generate(AiRequestData $request): AiProviderResponseData
    {
        // Exame de imagem nunca vai a quem não tem visão de verdade (a
        // Maritaca converte imagem em texto por OCR, que pode usar
        // fornecedores fora do Brasil). Falha antes de enviar: a cadeia de
        // fallback tenta o próximo provedor.
        if ($request->attachments !== [] && ! $this->supportsVision()) {
            throw new RuntimeException("{$this->provider->label()} não processa imagens — o exame não foi enviado a este provedor.");
        }

        $payload     = $this->buildPayload($request);
        $requestHash = $this->hashPayload($payload);
        $startedAt   = microtime(true);

        $response = $this->authenticate(Http::baseUrl($this->baseUrl()))
            ->withOptions(config('ai.http_force_ipv4') ? ['force_ip_resolve' => 'v4'] : [])
            ->acceptJson()
            ->asJson()
            ->withUserAgent($this->userAgent())
            ->connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->timeoutSeconds())
            ->retry($this->retryTimes(), $this->retrySleepMilliseconds(), null, false)
            ->post('/chat/completions', $payload);

        if (! $response->successful()) {
            throw $this->toProviderException($response);
        }

        $json         = (array) $response->json();
        $content      = $this->extractTextContent($json);
        $responseHash = hash('sha256', $content);

        [$outputTokens, $reasoningTokens] = $this->outputTokens($json);

        return new AiProviderResponseData(
            provider: $this->provider,
            model: (string) data_get($json, 'model', $this->model()),
            content: $content,
            usage: new AiUsageData(
                inputTokens: (int) data_get($json, 'usage.prompt_tokens', 0),
                outputTokens: $outputTokens,
                reasoningTokens: $reasoningTokens,
                toolCallsCount: count((array) data_get($json, 'choices.0.message.tool_calls', [])),
                rawCostUsd: null,
            ),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            requestHash: $requestHash,
            responseHash: $responseHash,
            rawResponse: [
                'id'    => data_get($json, 'id'),
                'model' => data_get($json, 'model'),
                'usage' => data_get($json, 'usage'),
            ],
            metadata: [
                'response_id' => data_get($json, 'id'),
            ],
            finishReason: $this->extractFinishReason($json),
        );
    }

    /** A Maritaca faz OCR da imagem (não é visão). */
    public function supportsVision(): bool
    {
        return $this->provider !== AiProvider::Maritaca;
    }

    public function supportsJsonMode(): bool
    {
        return (bool) config("ai.providers.{$this->provider->value}.json_mode", true);
    }

    public function provider(): AiProvider
    {
        return $this->provider;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AiRequestData $request): array
    {
        $messages = [];

        if ($request->systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $this->userContent($request)];

        $payload = [
            'model'    => $this->model(),
            'messages' => $messages,
        ];

        if ($request->maxOutputTokens) {
            $payload['max_tokens'] = $request->maxOutputTokens;
        }

        if ($request->expectsJson && $this->supportsJsonMode()) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $payload;
    }

    /**
     * Texto puro quando não há imagem — formato aceito por todos os
     * compatíveis. Com imagem, a lista de partes do padrão OpenAI.
     *
     * @return string|list<array<string, mixed>>
     */
    private function userContent(AiRequestData $request): string|array
    {
        $text   = PromptComposer::composeUserContent($request);
        $images = [];

        foreach ($request->attachments as $attachment) {
            $data = (string) ($attachment['data'] ?? '');
            $mime = (string) ($attachment['mime_type'] ?? 'image/jpeg');

            $imageUrl = $data !== ''
                ? "data:{$mime};base64,{$data}"
                : (string) ($attachment['image_url'] ?? $attachment['url'] ?? '');

            if ($imageUrl !== '') {
                $images[] = ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]];
            }
        }

        if ($images === []) {
            return $text;
        }

        return [['type' => 'text', 'text' => $text], ...$images];
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractTextContent(array $json): string
    {
        $content = data_get($json, 'choices.0.message.content');

        if (is_string($content)) {
            return trim($content);
        }

        // Alguns provedores (ex.: Mistral) devolvem a resposta em partes.
        $chunks = [];

        foreach ((array) $content as $part) {
            if (data_get($part, 'type') === 'text' && is_string($text = data_get($part, 'text')) && $text !== '') {
                $chunks[] = $text;
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * Saída (só o texto visível) e raciocínio, separados: a cobrança soma os
     * dois. Uns provedores contam o raciocínio DENTRO de completion_tokens
     * (padrão OpenAI); outros à parte (total = prompt + completion + raciocínio).
     *
     * @param array<string, mixed> $json
     *
     * @return array{0: int, 1: int}
     */
    private function outputTokens(array $json): array
    {
        $prompt     = (int) data_get($json, 'usage.prompt_tokens', 0);
        $completion = (int) data_get($json, 'usage.completion_tokens', 0);
        $total      = (int) data_get($json, 'usage.total_tokens', 0);
        $reasoning  = (int) data_get($json, 'usage.completion_tokens_details.reasoning_tokens', 0);

        if ($reasoning <= 0) {
            return [$completion, 0];
        }

        $separate = $total >= $prompt + $completion + $reasoning;

        return [$separate ? $completion : max(0, $completion - $reasoning), $reasoning];
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractFinishReason(array $json): ?string
    {
        $reason = data_get($json, 'choices.0.finish_reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("ai.providers.{$this->provider->value}.{$key}", $default);
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) $this->config('base_url', ''), '/');

        // Azure: cada recurso tem o próprio endereço (sem padrão).
        if ($url === '') {
            throw new RuntimeException("{$this->provider->label()}: endereço da API não configurado.");
        }

        return $url;
    }

    /** Bearer (padrão) ou header "api-key" (Azure OpenAI). */
    private function authenticate(PendingRequest $request): PendingRequest
    {
        return $this->config('auth') === 'api-key'
            ? $request->withHeaders(['api-key' => $this->apiKey()])
            : $request->withToken($this->apiKey());
    }

    private function model(): string
    {
        // Modelo efetivo: escolha do painel do Manager com fallback pro env.
        return (string) (app(AiProviderSettings::class)->model($this->provider->value) ?? $this->config('model', ''));
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) config("services.{$this->provider->value}.api_key", ''));

        if ($apiKey === '') {
            throw new RuntimeException("{$this->provider->label()} API key não configurada.");
        }

        return $apiKey;
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) $this->config('timeout_seconds', 30));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) $this->config('connect_timeout_seconds', 5));
    }

    private function retryTimes(): int
    {
        return max(0, (int) $this->config('retry_times', 1));
    }

    private function retrySleepMilliseconds(): int
    {
        return max(0, (int) $this->config('retry_sleep_ms', 250));
    }

    private function userAgent(): string
    {
        return (string) config('ai.user_agent', 'EasyEye/1.0');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hashPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $json !== false ? $json : '');
    }

    private function toProviderException(Response $response): RuntimeException
    {
        $json = $response->json();

        // Formatos de erro variam: {error: {message, code|type}} (OpenAI e
        // maioria), {message, type} (Mistral) ou {detail} (alguns gateways).
        $rawMessage = data_get($json, 'error.message') ?? data_get($json, 'message') ?? data_get($json, 'detail');
        $code       = data_get($json, 'error.code') ?? data_get($json, 'error.type') ?? data_get($json, 'type');
        $safeCode   = is_scalar($code) ? (string) $code : 'unknown';
        $label      = $this->provider->label();

        // Chave sem prefixo reconhecível (ex.: Mistral) ecoada pelo provedor:
        // remove o valor exato antes de sanitizar.
        $rawMessage = is_string($rawMessage) ? $rawMessage : '';
        $key        = trim((string) config("services.{$this->provider->value}.api_key", ''));

        if ($key !== '') {
            $rawMessage = str_replace($key, '[REDACTED:KEY]', $rawMessage);
        }

        $safeMessage = ProviderErrorSanitizer::sanitize($rawMessage, "Falha na integração {$label}.");

        return new RuntimeException("{$label} request failed [{$response->status()}/{$safeCode}]: {$safeMessage}");
    }
}
