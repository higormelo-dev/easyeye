<?php

declare(strict_types=1);

namespace App\Domains\AI\Contracts;

use App\Domains\AI\Models\AiModelPrice;
use App\Enums\AI\AiProvider;

interface AiModelPriceRepositoryInterface
{
    public function findActive(AiProvider $provider, string $model): ?AiModelPrice;
}
