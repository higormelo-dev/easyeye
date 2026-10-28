<?php

declare(strict_types=1);

namespace App\Domains\AI\Providers\Fakes;

use App\Enums\AI\AiProvider;

class AnthropicFakeProvider extends AbstractFakeAiProvider
{
    public function __construct()
    {
        parent::__construct(
            model: 'claude-fake-sonnet',
            inputUsdPerMillion: 3.0,
            outputUsdPerMillion: 12.0,
            reasoningUsdPerMillion: 2.0,
            toolCallUsd: 0.0006,
            latencyMs: 170,
        );
    }

    public function provider(): AiProvider
    {
        return AiProvider::Anthropic;
    }
}
