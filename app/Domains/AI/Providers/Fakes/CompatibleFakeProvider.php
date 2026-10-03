<?php

declare(strict_types=1);

namespace App\Domains\AI\Providers\Fakes;

use App\Enums\AI\AiProvider;

/** Fake dos provedores "compatíveis com OpenAI" (runtime fake: dev/testes). */
class CompatibleFakeProvider extends AbstractFakeAiProvider
{
    public function __construct(private readonly AiProvider $provider)
    {
        parent::__construct(
            model: $provider->value . '-fake',
            inputUsdPerMillion: 0.5,
            outputUsdPerMillion: 1.5,
            reasoningUsdPerMillion: 0.5,
            toolCallUsd: 0.0002,
            latencyMs: 110,
        );
    }

    public function provider(): AiProvider
    {
        return $this->provider;
    }
}
