<?php

declare(strict_types=1);

namespace App\Domains\AI\Providers;

use App\Domains\AI\Contracts\AiProviderInterface;
use App\Domains\AI\Services\AiProviderSettings;
use App\Domains\AI\Support\ProviderErrorSanitizer;
use App\DTOs\AI\{AiProviderResponseData, AiRequestData, AiUsageData};
use App\Enums\AI\AiProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiProvider implements AiProviderInterface
{
    public function generate(AiRequestData $request): AiProviderResponseData
    {
        $payload     = $this->buildPayload($request);
        $requestHash = $this->hashPayload($payload);
        $startedAt   = microtime(true);

        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->withToken($this->apiKey())
            ->withUserAgent($this->userAgent())
            ->connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->timeoutSeconds())
            ->retry($this->retryTimes(), $this->retrySleepMilliseconds(), null, false)
            ->post('/responses', $payload);

        if (! $response->successful()) {
            throw $this->toProviderException($response);
        }

        $json         = (array) $response->json();
        $content      = $this->extractTextContent($json);
        $responseHash = hash('sha256', $content);

        return new AiProviderResponseData(
            provider: $this->provider(),
            model: (string) data_get($json, 'model', $this->model()),
            content: $content,
            usage: new AiUsageData(
                inputTokens: (int) data_get($json, 'usage.input_tokens', 0),
                outputTokens: (int) data_get($json, 'usage.output_tokens', 0),
                reasoningTokens: (int) data_get($json, 'usage.output_tokens_details.reasoning_tokens', 0),
                toolCallsCount: $this->countToolCalls($json),
                rawCostUsd: null,
            ),
            latencyMs: (int) round((microtime(true) - $startedAt) * 1000),
            requestHash: $requestHash,
            responseHash: $responseHash,
            rawResponse: [
                'id'     => data_get($json, 'id'),
                'status' => data_get($json, 'status'),
                'model'  => data_get($json, 'model'),
                'usage'  => data_get($json, 'usage'),
            ],
            metadata: [
                'response_id' => data_get($json, 'id'),
                'status'      => data_get($json, 'status'),
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
        return AiProvider::OpenAI;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AiRequestData $request): array
    {
        $payload = [
            'model' => $this->model(),
            'input' => [
                [
                    'role'    => 'user',
                    'content' => $this->buildContentItems($request),
                ],
            ],
        ];

        if ($request->systemPrompt) {
            $payload['instructions'] = $request->systemPrompt;
        }

        if ($request->maxOutputTokens) {
            $payload['max_output_tokens'] = $request->maxOutputTokens;
        }

        if ($request->expectsJson) {
            $payload['text'] = [
                'format' => ['type' => 'json_object'],
            ];
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
                'type' => 'input_text',
                'text' => $this->composePromptWithContext($request),
            ],
        ];

        foreach ($request->attachments as $attachment) {
            // Imagem inline (base64) tem prioridade — usada no fluxo Eye Image,
            // onde o backend resolve o arquivo do S3 e envia como data URL.
            $data = (string) ($attachment['data'] ?? '');
            $mime = (string) ($attachment['mime_type'] ?? 'image/jpeg');

            $imageUrl = $data !== ''
                ? "data:{$mime};base64,{$data}"
                : (string) ($attachment['image_url'] ?? $attachment['url'] ?? '');

            if ($imageUrl === '') {
                continue;
            }

            $items[] = [
                'type'      => 'input_image',
                'image_url' => $imageUrl,
                'detail'    => (string) ($attachment['detail'] ?? 'auto'),
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

        $contextJson  = json_encode($request->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contextBlock = $contextJson !== false ? $contextJson : '{}';

        return trim(
            $prompt
            . "\n\n"
            . "Contexto clínico (JSON):\n"
            . $contextBlock,
        );
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractTextContent(array $json): string
    {
        $chunks      = [];
        $outputItems = (array) data_get($json, 'output', []);

        foreach ($outputItems as $outputItem) {
            $contents = (array) data_get($outputItem, 'content', []);

            foreach ($contents as $content) {
                if (data_get($content, 'type') === 'output_text') {
                    $text = (string) data_get($content, 'text', '');

                    if ($text !== '') {
                        $chunks[] = $text;
                    }
                }
            }
        }

        if ($chunks !== []) {
            return trim(implode("\n", $chunks));
        }

        $fallback = (string) data_get($json, 'output_text', '');

        return $fallback !== '' ? trim($fallback) : '';
    }

    /**
     * @param array<string, mixed> $json
     */
    private function countToolCalls(array $json): int
    {
        $count = 0;

        foreach ((array) data_get($json, 'output', []) as $item) {
            $type = (string) data_get($item, 'type', '');

            if (str_contains($type, 'tool_call') || $type === 'function_call') {
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
        $status = data_get($json, 'status');

        return is_string($status) && $status !== '' ? $status : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('ai.providers.openai.base_url', 'https://api.openai.com/v1'), '/');
    }

    private function model(): string
    {
        // Modelo efetivo: escolha do painel do Manager com fallback pro env.
        return (string) (app(AiProviderSettings::class)->model('openai')
            ?? config('ai.providers.openai.model', 'gpt-5-mini'));
    }

    private function apiKey(): string
    {
        $apiKey = (string) trim((string) config('services.openai.api_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key não configurada.');
        }

        return $apiKey;
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('ai.providers.openai.timeout_seconds', 20));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('ai.providers.openai.connect_timeout_seconds', 5));
    }

    private function retryTimes(): int
    {
        return max(0, (int) config('ai.providers.openai.retry_times', 1));
    }

    private function retrySleepMilliseconds(): int
    {
        return max(0, (int) config('ai.providers.openai.retry_sleep_ms', 250));
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
        $code       = data_get($response->json(), 'error.code');
        $status     = $response->status();
        $safeCode   = is_scalar($code) ? (string) $code : 'unknown';

        $safeMessage = ProviderErrorSanitizer::sanitize($rawMessage, 'Falha na integração OpenAI.');

        return new RuntimeException("OpenAI request failed [{$status}/{$safeCode}]: {$safeMessage}");
    }
}
