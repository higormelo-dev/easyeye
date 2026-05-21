<?php

declare(strict_types=1);

namespace App\Domains\AI\Providers;

use App\Domains\AI\Contracts\AiProviderInterface;
use App\Domains\AI\Support\ProviderErrorSanitizer;
use App\DTOs\AI\AiProviderResponseData;
use App\DTOs\AI\AiRequestData;
use App\DTOs\AI\AiUsageData;
use App\Enums\AI\AiProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicProvider implements AiProviderInterface
{
    public function generate(AiRequestData $request): AiProviderResponseData
    {
        $payload = $this->buildPayload($request);
        $requestHash = $this->hashPayload($payload);
        $startedAt = microtime(true);

        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-api-key' => $this->apiKey(),
                'anthropic-version' => $this->apiVersion(),
            ])
            ->withUserAgent($this->userAgent())
            ->connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->timeoutSeconds())
            ->retry($this->retryTimes(), $this->retrySleepMilliseconds(), null, false)
            ->post('/v1/messages', $payload);

        if (! $response->successful()) {
            throw $this->toProviderException($response);
        }

        $json = (array) $response->json();
        $content = $this->extractTextContent($json);
        $responseHash = hash('sha256', $content);

        return new AiProviderResponseData(
            provider: $this->provider(),
            model: (string) data_get($json, 'model', $this->model()),
            content: $content,
            usage: new AiUsageData(
                inputTokens: (int) data_get($json, 'usage.input_tokens', 0),
                outputTokens: (int) data_get($json, 'usage.output_tokens', 0),
                // Anthropic Messages API hoje NÃO expõe reasoning tokens em campo
                // próprio — quando extended thinking está ativo, esses tokens são
                // cobrados como output_tokens. Mantemos `data_get` com default 0
                // para ser forward-compatible: se a Anthropic publicar o campo no
                // futuro (provável nome: usage.reasoning_tokens ou usage.thinking_tokens),
                // basta atualizar a chave aqui. TODO: revisar quando Claude expor esse dado.
                reasoningTokens: (int) data_get($json, 'usage.reasoning_tokens', 0),
                toolCallsCount: $this->countToolCalls($json),
                rawCostUsd: null,
            ),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            requestHash: $requestHash,
            responseHash: $responseHash,
            rawResponse: [
                'id' => data_get($json, 'id'),
                'type' => data_get($json, 'type'),
                'model' => data_get($json, 'model'),
                'usage' => data_get($json, 'usage'),
                'stop_reason' => data_get($json, 'stop_reason'),
            ],
            metadata: [
                'message_id' => data_get($json, 'id'),
                'stop_reason' => data_get($json, 'stop_reason'),
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
        return AiProvider::Anthropic;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AiRequestData $request): array
    {
        $payload = [
            'model' => $this->model(),
            'max_tokens' => $request->maxOutputTokens ?? $this->defaultMaxTokens(),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->buildContentItems($request),
                ],
            ],
        ];

        if ($request->systemPrompt) {
            $payload['system'] = $request->systemPrompt;
        }

        if ($request->expectsJson) {
            $payload['metadata'] = ['expects_json' => true];
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildContentItems(AiRequestData $request): array
    {
        $items = [
            [
                'type' => 'text',
                'text' => $this->composePromptWithContext($request),
            ],
        ];

        foreach ($request->attachments as $attachment) {
            $imageUrl = (string) ($attachment['image_url'] ?? $attachment['url'] ?? '');

            if ($imageUrl === '') {
                continue;
            }

            $items[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'url',
                    'url' => $imageUrl,
                ],
            ];
        }

        return $items;
    }

    private function composePromptWithContext(AiRequestData $request): string
    {
        $prompt = $request->userPrompt;

        if ($request->expectsJson) {
            $prompt .= "\n\nResponda em JSON válido.";
        }

        if ($request->context === []) {
            return $prompt;
        }

        $contextJson = json_encode($request->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contextBlock = $contextJson !== false ? $contextJson : '{}';

        return trim($prompt . "\n\nContexto clínico (JSON):\n" . $contextBlock);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractTextContent(array $json): string
    {
        $chunks = [];

        foreach ((array) data_get($json, 'content', []) as $item) {
            if (data_get($item, 'type') === 'text') {
                $text = (string) data_get($item, 'text', '');

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

        foreach ((array) data_get($json, 'content', []) as $item) {
            $type = (string) data_get($item, 'type', '');

            if ($type === 'tool_use') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractFinishReason(array $json): ?string
    {
        $reason = data_get($json, 'stop_reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('ai.providers.anthropic.base_url', 'https://api.anthropic.com'), '/');
    }

    private function model(): string
    {
        return (string) config('ai.providers.anthropic.model', 'claude-sonnet-4-5');
    }

    private function apiKey(): string
    {
        $apiKey = (string) config('services.anthropic.api_key', '');

        if ($apiKey === '') {
            throw new RuntimeException('Anthropic API key não configurada.');
        }

        return $apiKey;
    }

    private function apiVersion(): string
    {
        return (string) config('services.anthropic.version', '2023-06-01');
    }

    private function defaultMaxTokens(): int
    {
        return max(128, (int) config('ai.providers.anthropic.default_max_tokens', 800));
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('ai.providers.anthropic.timeout_seconds', 20));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('ai.providers.anthropic.connect_timeout_seconds', 5));
    }

    private function retryTimes(): int
    {
        return max(0, (int) config('ai.providers.anthropic.retry_times', 1));
    }

    private function retrySleepMilliseconds(): int
    {
        return max(0, (int) config('ai.providers.anthropic.retry_sleep_ms', 250));
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
        $type = (string) data_get($response->json(), 'error.type', 'unknown');
        $status = $response->status();

        $safeType = ProviderErrorSanitizer::sanitize($type, 'unknown');
        $safeMessage = ProviderErrorSanitizer::sanitize($rawMessage, 'Falha na integração Anthropic.');

        return new RuntimeException("Anthropic request failed [{$status}/{$safeType}]: {$safeMessage}");
    }
}

