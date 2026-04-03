<?php

declare(strict_types = 1);

namespace App\Traits;

use App\Services\AuditService;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Registra automaticamente alterações no model na tabela audit_logs.
 *
 * Configure campos a ignorar declarando no model:
 *   protected array $auditExclude = ['internal_field'];
 */
trait Auditable
{
    /**
     * Armazena o diff capturado no evento `updating` para uso no `updated`.
     * Necessário porque após o save() getDirty() já está limpo.
     */
    private array $_auditPendingDiff = [];

    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            app(AuditService::class)->log(
                event: 'created',
                model: $model,
                old: null,
                new: $model->getAttributes(),
            );
        });

        static::updating(function (self $model): void {
            // Captura o diff ANTES do save — após o save getDirty() é zerado
            $dirty    = $model->getDirty();
            $original = $model->getOriginal();

            $old = array_intersect_key($original, $dirty);
            $new = $dirty;

            $model->_auditPendingDiff = ['old' => $old, 'new' => $new];
        });

        static::updated(function (self $model): void {
            if (empty($model->_auditPendingDiff)) {
                return;
            }

            app(AuditService::class)->log(
                event: 'updated',
                model: $model,
                old: $model->_auditPendingDiff['old'],
                new: $model->_auditPendingDiff['new'],
            );

            $model->_auditPendingDiff = [];
        });

        static::deleted(function (self $model): void {
            app(AuditService::class)->log(
                event: 'deleted',
                model: $model,
                old: $model->getAttributes(),
                new: null,
            );
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restoring(function (self $model): void {
                app(AuditService::class)->log(
                    event: 'restored',
                    model: $model,
                    old: null,
                    new: $model->getAttributes(),
                );
            });
        }
    }

    /**
     * Campos a excluir da auditoria neste model específico.
     * Será mesclado com a lista global definida em AuditService.
     */
    public function getAuditExclude(): array
    {
        return $this->auditExclude ?? [];
    }
}
