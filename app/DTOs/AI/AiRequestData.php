<?php

declare(strict_types=1);

namespace App\DTOs\AI;

use App\Enums\AI\{AiRiskLevel, AiRunMode};

readonly class AiRequestData
{
    /**
     * @param array<string, mixed>       $context
     * @param list<array<string, mixed>> $attachments
     * @param array<string, mixed>       $metadata
     */
    public function __construct(
        public string $workflow,
        public AiRunMode $mode,
        public string $userPrompt,
        public ?string $systemPrompt = null,
        public AiRiskLevel $riskLevel = AiRiskLevel::Low,
        public array $context = [],
        public array $attachments = [],
        public bool $expectsJson = false,
        public ?int $maxOutputTokens = null,
        public array $metadata = [],
    ) {
    }

    public function fullPrompt(): string
    {
        return trim(($this->systemPrompt ? $this->systemPrompt . "\n\n" : '') . $this->userPrompt);
    }

    public function toArray(): array
    {
        return [
            'workflow'          => $this->workflow,
            'mode'              => $this->mode->value,
            'risk_level'        => $this->riskLevel->value,
            'system_prompt'     => $this->systemPrompt,
            'user_prompt'       => $this->userPrompt,
            'context'           => $this->context,
            'attachments'       => $this->attachments,
            'expects_json'      => $this->expectsJson,
            'max_output_tokens' => $this->maxOutputTokens,
            'metadata'          => $this->metadata,
        ];
    }
}
