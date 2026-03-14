<?php

namespace App\Concerns;

trait HasEntityCode
{
    protected static function bootHasEntityCode(): void
    {
        static::creating(function (self $model) {
            if (blank($model->code)) {
                $prefix = $model->entity_id
                    ? $model->codePrefix
                    : $model->codePrefixGlobal;

                $last = static::withoutGlobalScopes()
                    ->when(
                        $model->entity_id !== null,
                        fn ($q) => $q->where('entity_id', $model->entity_id),
                        fn ($q) => $q->whereNull('entity_id')
                    )
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                $newNumber = $last
                    ? ((int) substr($last->code, strlen($prefix) + 1)) + 1
                    : 1;

                $model->code = sprintf('%s-%010d', $prefix, $newNumber);
            }
        });
    }
}
