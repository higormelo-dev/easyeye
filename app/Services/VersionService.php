<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RecordVersion;
use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Model;

class VersionService
{
    /**
     * Cria um snapshot do estado atual do model antes de uma atualização.
     * Deve ser chamado no evento `updating`, antes do save().
     *
     * @param  Model        $model   O model sendo atualizado
     * @param  string|null  $reason  Motivo opcional da alteração
     */
    public function snapshot(Model $model, ?string $reason = null): void
    {
        $nextVersion = RecordVersion::query()
            ->where('versionable_type', get_class($model))
            ->where('versionable_id', $model->getKey())
            ->max('version') + 1;

        $exclude = method_exists($model, 'getVersionExclude')
            ? $model->getVersionExclude()
            : [];

        $data = array_diff_key($model->getOriginal(), array_flip($exclude));

        RecordVersion::create([
            'entity_id'        => $this->resolveEntityId($model),
            'user_id'          => AuditContext::userId(),
            'versionable_type' => get_class($model),
            'versionable_id'   => $model->getKey(),
            'version'          => $nextVersion,
            'data'             => $data,
            'reason'           => $reason,
            'created_at'       => now(),
        ]);
    }

    /**
     * Resolve o entity_id para a versão.
     * Prioridade: sessão ativa → coluna entity_id no model → null
     */
    private function resolveEntityId(Model $model): ?string
    {
        return session('selected_entity_id')
            ?? ($model->getAttribute('entity_id') ?: null);
    }
}
