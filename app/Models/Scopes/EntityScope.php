<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\{Builder, Model, Scope};

/**
 * Global scope multi-tenant: filtra por entity_id da entity ativa
 * (TenantContext) APENAS quando há um tenant vinculado.
 *
 * Sem vínculo (manager SaaS, webhook, job, CLI) o scope é INERTE — não adiciona
 * cláusula alguma — preservando o comportamento atual desses fluxos. É camada
 * de defesa em profundidade: não substitui os where('entity_id') existentes.
 */
class EntityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $entityId = app(TenantContext::class)->id();

        if ($entityId === null) {
            return; // não vinculado -> inerte
        }

        // Coluna do trait BelongsToEntity quando presente; senão, o padrão
        // 'entity_id' (models registrados via TenantScopeServiceProvider).
        $column    = method_exists($model, 'getEntityColumn') ? $model->getEntityColumn() : 'entity_id';
        $qualified = $model->qualifyColumn($column);

        // entity_id da entity ativa OU registros GLOBAIS (entity_id NULL) —
        // catálogos compartilhados (medicines, procedures, tipos clínicos) têm
        // linhas globais visíveis a todos os tenants. Em tabelas NOT NULL
        // (clínico/financeiro) não há NULL, então o filtro é efetivamente estrito.
        $builder->where(function (Builder $query) use ($qualified, $entityId): void {
            $query->where($qualified, $entityId)->orWhereNull($qualified);
        });
    }

    public function extend(Builder $builder): void
    {
        // Escape hatch explícito: Model::query()->withoutEntityScope()
        $builder->macro('withoutEntityScope', static fn (Builder $b): Builder => $b->withoutGlobalScope(static::class));
    }
}
