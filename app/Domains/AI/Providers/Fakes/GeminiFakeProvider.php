<?php

declare(strict_types=1);

namespace App\Domains\AI\Providers\Fakes;

use App\Enums\AI\AiProvider;

class GeminiFakeProvider extends AbstractFakeAiProvider
{
    public function __construct()
    {
        parent::__construct(
            model: 'gemini-fake-pro',
            inputUsdPerMillion: 1.8,
            outputUsdPerMillion: 7.5,
            reasoningUsdPerMillion: 1.0,
            toolCallUsd: 0.0004,
            latencyMs: 130,
        );
    }

    public function provider(): AiProvider
    {
        return AiProvider::Gemini;
    }
}
