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
        $exact = $this->queryActive($provider, $model);

        if ($exact) {
            return $exact;
        }

        // Provedores devolvem o id do snapshot datado (ex.: gpt-4o-2024-08-06).
        // Cai para o modelo-base (gpt-4o) cadastrado no price table.
        $base = $this->baseModel($model);

        return $base !== $model ? $this->queryActive($provider, $base) : null;
    }

    private function queryActive(AiProvider $provider, string $model): ?AiModelPrice
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

    private function baseModel(string $model): string
    {
        return preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model) ?? $model;
    }
}
