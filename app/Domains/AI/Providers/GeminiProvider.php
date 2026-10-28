<?php

declare(strict_types=1);

namespace App\Domains\AI\Providers;

use App\Domains\AI\Contracts\AiProviderInterface;
use App\Domains\AI\Services\AiProviderSettings;
use App\Domains\AI\Support\{PromptComposer, ProviderErrorSanitizer};
use App\DTOs\AI\{AiProviderResponseData, AiRequestData, AiUsageData};
use App\Enums\AI\AiProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiProvider implements AiProviderInterface
{
    public function generate(AiRequestData $request): AiProviderResponseData
    {
        $payload     = $this->buildPayload($request);
        $requestHash = $this->hashPayload($payload);
        $startedAt   = microtime(true);

        $response = Http::baseUrl($this->baseUrl())
            ->withOptions(config('ai.http_force_ipv4') ? ['force_ip_resolve' => 'v4'] : [])
            ->acceptJson()
            ->asJson()
            ->withHeaders(['x-goog-api-key' => $this->apiKey()])
            ->withUserAgent($this->userAgent())
            ->connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->timeoutSeconds())
            ->retry($this->retryTimes(), $this->retrySleepMilliseconds(), null, false)
            ->post($this->endpoint(), $payload);

        if (! $response->successful()) {
            throw $this->toProviderException($response);
        }

        $json         = (array) $response->json();
        $content      = $this->extractTextContent($json);
        $responseHash = hash('sha256', $content);

        return new AiProviderResponseData(
            provider: $this->provider(),
            model: (string) data_get($json, 'modelVersion', $this->model()),
            content: $content,
            usage: new AiUsageData(
                inputTokens: (int) data_get($json, 'usageMetadata.promptTokenCount', 0),
                outputTokens: (int) data_get($json, 'usageMetadata.candidatesTokenCount', 0),
                reasoningTokens: (int) data_get($json, 'usageMetadata.thoughtsTokenCount', 0),
                toolCallsCount: $this->countToolCalls($json),
                rawCostUsd: null,
            ),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            requestHash: $requestHash,
            responseHash: $responseHash,
            rawResponse: [
                'responseId'     => data_get($json, 'responseId'),
                'modelVersion'   => data_get($json, 'modelVersion'),
                'usageMetadata'  => data_get($json, 'usageMetadata'),
                'promptFeedback' => data_get($json, 'promptFeedback'),
            ],
            metadata: [
                'response_id'         => data_get($json, 'responseId'),
                'prompt_block_reason' => data_get($json, 'promptFeedback.blockReason'),
            ],
            finishReason: $this->extractFinishReason($json),
        );
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function supportsJsonMode(): bool
    {
        return true;
    }

    public function provider(): AiProvider
    {
        return AiProvider::Gemini;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AiRequestData $request): array
    {
        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => $this->buildParts($request),
                ],
            ],
        ];

        if ($request->systemPrompt) {
            $payload['system_instruction'] = [
                'parts' => [
                    ['text' => $request->systemPrompt],
                ],
            ];
        }

        if ($request->maxOutputTokens || $request->expectsJson) {
            $payload['generationConfig'] = [];

            if ($request->maxOutputTokens) {
                $payload['generationConfig']['maxOutputTokens'] = $request->maxOutputTokens;
            }

            if ($request->expectsJson) {
                $payload['generationConfig']['response_mime_type'] = 'application/json';
            }
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildParts(AiRequestData $request): array
    {
        $parts = [
            [
                'text' => PromptComposer::composeUserContent($request),
            ],
        ];

        foreach ($request->attachments as $attachment) {
            $mime = (string) ($attachment['mime_type'] ?? 'image/jpeg');
            $data = (string) ($attachment['data'] ?? '');

            // Imagem inline (base64): o Gemini NÃO busca URLs arbitrárias (ex.: S3),
            // então o fluxo Eye Image envia a imagem como inline_data.
            if ($data !== '') {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mime,
                        'data'      => $data,
                    ],
                ];

                continue;
            }

            // file_uri só funciona com URIs da Files API do Gemini.
            $fileUri = (string) ($attachment['file_uri'] ?? $attachment['image_url'] ?? $attachment['url'] ?? '');

            if ($fileUri === '') {
                continue;
            }

            $parts[] = [
                'file_data' => [
                    'mime_type' => $mime,
                    'file_uri'  => $fileUri,
                ],
            ];
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractTextContent(array $json): string
    {
        $chunks = [];

        foreach ((array) data_get($json, 'candidates', []) as $candidate) {
            foreach ((array) data_get($candidate, 'content.parts', []) as $part) {
                $text = (string) data_get($part, 'text', '');

                if ($text !== '') {
                    $chunks[] = $text;
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * @param array<string, mixed> $json
     */
    private function countToolCalls(array $json): int
    {
        $count = 0;

        foreach ((array) data_get($json, 'candidates', []) as $candidate) {
            foreach ((array) data_get($candidate, 'content.parts', []) as $part) {
                if (isset($part['functionCall'])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractFinishReason(array $json): ?string
    {
        $reason = data_get($json, 'candidates.0.finishReason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('ai.providers.gemini.base_url', 'https://generativelanguage.googleapis.com'), '/');
    }

    private function model(): string
    {
        // Modelo efetivo: escolha do painel do Manager com fallback pro env.
        return (string) (app(AiProviderSettings::class)->model('gemini')
            ?? config('ai.providers.gemini.model', 'gemini-2.0-flash'));
    }

    /**
     * Endpoint da Gemini API. Antes incluíamos a API key como `?key=...` na URL,
     * mas isso vaza a chave em logs de proxy/CDN/access logs. Usamos o header
     * `x-goog-api-key` (suportado oficialmente pelo Generative Language API) que
     * mantém a chave fora da URL.
     */
    private function endpoint(): string
    {
        // Garante que apiKey() valide antes de montar a URL (mantém o mesmo
        // ponto de falha rápida quando a chave não está configurada).
        $this->apiKey();
        $model = $this->model();

        return "/v1beta/models/{$model}:generateContent";
    }

    private function apiKey(): string
    {
        $apiKey = (string) trim((string) config('services.gemini.api_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('Gemini API key não configurada.');
        }

        return $apiKey;
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('ai.providers.gemini.timeout_seconds', 20));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('ai.providers.gemini.connect_timeout_seconds', 5));
    }

    private function retryTimes(): int
    {
        return max(0, (int) config('ai.providers.gemini.retry_times', 1));
    }

    private function retrySleepMilliseconds(): int
    {
        return max(0, (int) config('ai.providers.gemini.retry_sleep_ms', 250));
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
        $rawMessage = (string) data_get($response->json(), 'error.message', '');
        $status     = $response->status();

        $safeMessage = ProviderErrorSanitizer::sanitize($rawMessage, 'Falha na integração Gemini.');

        return new RuntimeException("Gemini request failed [{$status}]: {$safeMessage}");
    }
}
