<?php

declare(strict_types=1);

namespace App\DTOs\AI;

use App\Enums\AI\AiProvider;

readonly class AiProviderResponseData
{
    /**
     * @param array<string, mixed>|null $rawResponse
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public AiProvider $provider,
        public string $model,
        public string $content,
        public AiUsageData $usage,
        public int $latencyMs,
        public ?string $requestHash = null,
        public ?string $responseHash = null,
        public ?array $rawResponse = null,
        public array $metadata = [],
        public ?string $finishReason = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'provider'      => $this->provider->value,
            'model'         => $this->model,
            'content'       => $this->content,
            'usage'         => $this->usage->toArray(),
            'latency_ms'    => $this->latencyMs,
            'request_hash'  => $this->requestHash,
            'response_hash' => $this->responseHash,
            'raw_response'  => $this->rawResponse,
            'metadata'      => $this->metadata,
            'finish_reason' => $this->finishReason,
        ];
    }
}
