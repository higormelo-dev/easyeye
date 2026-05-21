<?php

declare(strict_types=1);

namespace App\Domains\AI\Contracts;

use App\DTOs\AI\AiProviderResponseData;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\AiProvider;

interface AiProviderInterface
{
    public function generate(AiRequestData $request): AiProviderResponseData;

    public function supportsVision(): bool;

    public function supportsJsonMode(): bool;

    public function provider(): AiProvider;
}
