<?php

declare(strict_types=1);

namespace App\Domains\AI\Repositories;

use App\Domains\AI\Contracts\AiModelPriceRepositoryInterface;
use App\Domains\AI\Models\AiModelPrice;
use App\Enums\AI\AiProvider;
use Illuminate\Database\Eloquent\Builder;

class EloquentAiModelPriceRepository implements AiModelPriceRepositoryInterface
{
    public function findActive(AiProvider $provider, string $model): ?AiModelPrice
    {
        $now = now();

        return AiModelPrice::query()
            ->where('provider', $provider->value)
            ->where('model', $model)
            ->where('active', true)
            ->where('effective_from', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $now);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
