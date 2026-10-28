<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\EntityScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\{Builder, Model};

/**
 * Marca um model como pertencente a uma entity (tenant) e ativa o isolamento:
 *
 *  - EntityScope global: leituras filtram pela entity ativa quando há tenant
 *    vinculado (inerte quando não há — ver EntityScope/TenantContext).
 *  - creating: preenche entity_id automaticamente a partir do TenantContext
 *    quando ainda em branco (não sobrescreve valor explícito).
 *
 * Não remove os where('entity_id') manuais existentes — é camada extra.
 * Models cujo entity_id NÃO é coluna de posse (Entity, User, pivôs) NÃO devem
 * usar este trait.
 *
 * @property string|null $entity_id
 */
trait BelongsToEntity
{
    public static function bootBelongsToEntity(): void
    {
        static::addGlobalScope(new EntityScope());

        static::creating(function (Model $model): void {
            if (blank($model->getAttribute($model->getEntityColumn()))) {
                $entityId = app(TenantContext::class)->id();

                if ($entityId !== null) {
                    $model->setAttribute($model->getEntityColumn(), $entityId);
                }
            }
        });
    }

    /** Coluna de tenant. Sobrescreva se o model usar outro nome. */
    public function getEntityColumn(): string
    {
        return 'entity_id';
    }

    /** Filtro explícito por entity (opt-in), independente do scope global. */
    public function scopeForEntity(Builder $query, string $entityId): Builder
    {
        return $query->where($this->qualifyColumn($this->getEntityColumn()), $entityId);
    }
}
