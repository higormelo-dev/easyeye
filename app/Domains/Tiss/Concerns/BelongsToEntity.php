<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Concerns;

use App\Models\Concerns\BelongsToEntity as BaseBelongsToEntity;

trait BelongsToEntity
{
    // Herda EntityScope global + auto-set de entity_id + scopeForEntity.
    use BaseBelongsToEntity;

    public function resolveRouteBinding($value, $field = null): ?self
    {
        $query = static::where($field ?? $this->getRouteKeyName(), $value);

        if ($entityId = session('selected_entity_id')) {
            $query->where('entity_id', $entityId);
        }

        return $query->firstOrFail();
    }
}
