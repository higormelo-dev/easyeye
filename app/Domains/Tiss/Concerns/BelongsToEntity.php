<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToEntity
{
    public function scopeForEntity(Builder $query, string $entityId): Builder
    {
        return $query->where('entity_id', $entityId);
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $query = static::where($field ?? $this->getRouteKeyName(), $value);

        if ($entityId = session('selected_entity_id')) {
            $query->where('entity_id', $entityId);
        }

        return $query->firstOrFail();
    }
}
