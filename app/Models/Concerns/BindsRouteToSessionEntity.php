<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Route model binding sempre na clínica da sessão: SubstituteBindings roda
 * ANTES do tenant.bind (EntityScope ainda inerte), então o binding padrão
 * deixaria resolver o id de outra clínica. UUID inválido ou de outra clínica
 * = 404 (nunca 403: não confirma que o registro existe).
 */
trait BindsRouteToSessionEntity
{
    public function resolveRouteBinding($value, $field = null): ?self
    {
        abort_unless(Str::isUuid((string) $value), 404);

        return static::query()
            ->where($this->qualifyColumn($field ?? $this->getRouteKeyName()), $value)
            ->where($this->qualifyColumn('entity_id'), (string) session('selected_entity_id'))
            ->firstOrFail();
    }
}
