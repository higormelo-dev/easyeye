<?php

declare(strict_types=1);

namespace App\Traits;

use App\Support\AuditContext;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Auto-preenche created_by, updated_by e deleted_by com o usuário autenticado.
 *
 * Aplicar em models cujas tabelas possuam essas três colunas.
 */
trait HasAuditColumns
{
    private static function resolveUserId(): ?string
    {
        return AuditContext::userId();
    }

    public static function bootHasAuditColumns(): void
    {
        static::creating(function (self $model): void {
            $userId = static::resolveUserId();

            if (blank($model->created_by)) {
                $model->created_by = $userId;
            }

            if (blank($model->updated_by)) {
                $model->updated_by = $userId;
            }
        });

        static::updating(function (self $model): void {
            $model->updated_by = static::resolveUserId();
        });

        // Preenche deleted_by somente se o model usar SoftDeletes
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::deleting(function (self $model): void {
                if (! $model->isForceDeleting()) {
                    $model->deleted_by = static::resolveUserId();
                    $model->saveQuietly();
                }
            });
        }
    }
}
