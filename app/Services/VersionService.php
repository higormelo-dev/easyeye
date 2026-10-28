<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RecordVersion;
use App\Support\AuditContext;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class VersionService
{
    /** Índice único (versionable_type, versionable_id, version) — migration 2026_03_22_200002. */
    private const VERSION_UNIQUE_INDEX = 'record_versions_versionable_type_versionable_id_version_unique';

    /** Tentativas quando outra atualização simultânea do MESMO registro pega o mesmo número. */
    private const MAX_VERSION_ATTEMPTS = 3;

    /**
     * Cria um snapshot do estado atual do model antes de uma atualização.
     * Deve ser chamado no evento `updating`, antes do save().
     *
     * Concorrência: o número é max+1 sem lock (o update do prontuário nem
     * sempre roda em transação). Dois saves simultâneos do mesmo registro
     * (duplo clique, duas abas) calculavam o MESMO número e o segundo estourava
     * o índice único => HTTP 500 e o save perdido. Agora a colisão nesse índice
     * vira nova tentativa (limitada) relendo o último número.
     *
     * @param Model       $model  O model sendo atualizado
     * @param string|null $reason Motivo opcional da alteração
     */
    public function snapshot(Model $model, ?string $reason = null): void
    {
        $exclude = method_exists($model, 'getVersionExclude')
            ? $model->getVersionExclude()
            : [];

        $data = array_diff_key($model->getOriginal(), array_flip($exclude));

        for ($attempt = 1;; $attempt++) {
            try {
                // SAVEPOINT por tentativa: no PostgreSQL o INSERT que falha
                // aborta a transação do chamador — a nova tentativa precisa
                // começar limpa (e o novo comando enxerga a versão já commitada).
                DB::transaction(fn () => RecordVersion::create([
                    'entity_id'        => $this->resolveEntityId($model),
                    'user_id'          => AuditContext::userId(),
                    'versionable_type' => get_class($model),
                    'versionable_id'   => $model->getKey(),
                    'version'          => $this->nextVersion($model),
                    'data'             => $data,
                    'reason'           => $reason,
                    'created_at'       => now(),
                ]));

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_VERSION_ATTEMPTS || ! $this->isVersionCollision($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Sem escopos globais: o índice único é global por registro, e uma versão
     * gravada sem sessão (job/CLI, entity_id do model ou null) sumiria do
     * EntityScope — o número colidiria em toda tentativa.
     */
    private function nextVersion(Model $model): int
    {
        return (int) RecordVersion::query()
            ->withoutGlobalScopes()
            ->where('versionable_type', get_class($model))
            ->where('versionable_id', $model->getKey())
            ->max('version') + 1;
    }

    /** Só a colisão do número da versão é refeita; outra violação sobe. */
    private function isVersionCollision(UniqueConstraintViolationException $e): bool
    {
        return UniqueViolation::violates($e, self::VERSION_UNIQUE_INDEX)
            || UniqueViolation::violatesColumns($e, 'record_versions', 'versionable_type', 'versionable_id', 'version');
    }

    /**
     * Resolve o entity_id para a versão.
     * Prioridade: sessão ativa → coluna entity_id no model → null.
     */
    private function resolveEntityId(Model $model): ?string
    {
        return session('selected_entity_id')
            ?? ($model->getAttribute('entity_id') ?: null);
    }
}
