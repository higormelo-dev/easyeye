<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/** An explicit namespace never falls back to a different clinical identity. */
class IntegratorClinicalIdentifier
{
    public static function matches(string $model, string $entityId, string $identifier, string $prefix, mixed $namespace = null): Collection
    {
        $query = $model::query()->where('entity_id', $entityId);

        if ($namespace !== null && ! in_array($namespace, ['uuid', 'system', 'import'], true)) {
            return $query->whereRaw('1 = 0')->get();
        }

        if ($namespace === 'uuid' || ($namespace === null && Str::isUuid($identifier))) {
            return Str::isUuid($identifier) ? $query->whereKey($identifier)->limit(2)->get() : $query->whereRaw('1 = 0')->get();
        }

        if ($namespace === 'import') {
            return $query->where('import_code', $identifier)->limit(2)->get();
        }

        $code = ctype_digit($identifier)
            ? sprintf('%s-%010d', $prefix, (int) $identifier)
            : (preg_match('/^' . preg_quote($prefix, '/') . '-(\d{1,10})$/i', $identifier, $match)
                ? sprintf('%s-%010d', $prefix, (int) $match[1]) : $identifier);

        if ($namespace === 'system') {
            return $query->where('code', $code)->limit(2)->get();
        }

        return $query->where(fn ($q) => $q->where('code', $code)->orWhere('import_code', $identifier))->limit(2)->get();
    }
}
