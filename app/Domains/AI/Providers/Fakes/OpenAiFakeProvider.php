<?php

declare(strict_types=1);

namespace App\Domains\AI\Providers\Fakes;

use App\Enums\AI\AiProvider;

class OpenAiFakeProvider extends AbstractFakeAiProvider
{
    public function __construct()
    {
        parent::__construct(
            model: 'gpt-fake-5',
            inputUsdPerMillion: 2.5,
            outputUsdPerMillion: 10.0,
            reasoningUsdPerMillion: 1.5,
            toolCallUsd: 0.0005,
            latencyMs: 140,
        );
    }

    public function provider(): AiProvider
    {
        return AiProvider::OpenAI;
    }
}
