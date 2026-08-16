<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DiagnosisType;
use App\Models\{Cid10Code, EntityCustomDiagnosis, EntityDiagnosisUsage};
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de diagnósticos do "Diagnóstico do exame" (Gerenciador de Imagens):
 * combina o catálogo global CID-10 (cid10_codes) com diagnósticos
 * customizados por clínica (entity_custom_diagnoses), ranqueados por
 * frequência de uso da entidade (entity_diagnosis_usages).
 */
class DiagnosisCatalogService
{
    /**
     * Busca combinada CID-10 (global) + customizados (escopados por entity),
     * ordenada por uso (desc) e, em empate, pela relevância de cada busca de
     * origem (match de código/prefixo primeiro para CID-10, ordem alfabética
     * para customizados). O sort por use_count é estável (PHP 8+), então a
     * ordem de relevância original de cada bloco é preservada dentro de cada
     * faixa de uso.
     *
     * @return Collection<int, array{type: string, code: ?string, custom_diagnosis_id: ?string, description: string, use_count: int}>
     */
    public function search(string $entityId, string $term, int $limit = 15): Collection
    {
        $poolSize = max($limit * 3, $limit);

        $cid10Results = Cid10Code::query()
            ->search($term)
            ->limit($poolSize)
            ->get(['id', 'code', 'description']);

        $customResults = EntityCustomDiagnosis::query()
            ->where('entity_id', $entityId)
            ->search($term)
            ->orderBy('name')
            ->limit($poolSize)
            ->get(['id', 'name']);

        $cid10UseCounts = $this->useCountsFor(
            $entityId,
            DiagnosisType::Cid10,
            'cid10_code_id',
            $cid10Results->pluck('id'),
        );

        $customUseCounts = $this->useCountsFor(
            $entityId,
            DiagnosisType::Custom,
            'entity_custom_diagnosis_id',
            $customResults->pluck('id'),
        );

        $items = $cid10Results
            ->map(fn (Cid10Code $c) => [
                'type'                => DiagnosisType::Cid10->value,
                'code'                => $c->code,
                'custom_diagnosis_id' => null,
                'description'         => $c->description,
                'use_count'           => (int) ($cid10UseCounts[$c->id] ?? 0),
            ])
            ->concat($customResults->map(fn (EntityCustomDiagnosis $d) => [
                'type'                => DiagnosisType::Custom->value,
                'code'                => null,
                'custom_diagnosis_id' => (string) $d->id,
                'description'         => $d->name,
                'use_count'           => (int) ($customUseCounts[$d->id] ?? 0),
            ]));

        return $items
            ->sortByDesc('use_count')
            ->take($limit)
            ->values();
    }

    /**
     * Top N diagnósticos mais usados pela entidade (sugestões rápidas sem
     * digitação), trazendo code/description resolvidos via relação.
     *
     * @return Collection<int, array{type: string, code: ?string, custom_diagnosis_id: ?string, description: string, use_count: int}>
     */
    public function mostUsed(string $entityId, int $limit = 10): Collection
    {
        return EntityDiagnosisUsage::query()
            ->where('entity_id', $entityId)
            ->with(['cid10Code:id,code,description', 'customDiagnosis:id,name'])
            ->orderByDesc('use_count')
            ->limit($limit)
            ->get()
            ->filter(fn (EntityDiagnosisUsage $u) => $u->diagnosis_type === DiagnosisType::Cid10
                ? $u->cid10Code !== null
                : $u->customDiagnosis !== null)
            ->map(fn (EntityDiagnosisUsage $u) => [
                'type'                => $u->diagnosis_type->value,
                'code'                => $u->diagnosis_type === DiagnosisType::Cid10 ? $u->cid10Code?->code : null,
                'custom_diagnosis_id' => $u->diagnosis_type === DiagnosisType::Custom ? (string) $u->customDiagnosis?->id : null,
                'description'         => $u->diagnosis_type === DiagnosisType::Cid10
                    ? (string) $u->cid10Code?->description
                    : (string) $u->customDiagnosis?->name,
                'use_count' => (int) $u->use_count,
            ])
            ->values();
    }

    /**
     * Busca (ou cria) um diagnóstico customizado da clínica pelo nome,
     * normalizado (minúsculas + trim) para permitir dedupe case/acento
     * insensível por entity via o índice único (entity_id, normalized_name).
     *
     * Protege contra corrida entre requisições concorrentes: se o insert
     * disparar a violação do índice único, refaz o find em vez de propagar
     * o erro pro chamador.
     */
    public function findOrCreateCustom(string $entityId, string $name, string $userId): EntityCustomDiagnosis
    {
        $name           = trim($name);
        $normalizedName = mb_strtolower($name, 'UTF-8');

        try {
            return EntityCustomDiagnosis::query()->firstOrCreate(
                ['entity_id' => $entityId, 'normalized_name' => $normalizedName],
                ['name' => $name, 'created_by' => $userId, 'updated_by' => $userId],
            );
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return EntityCustomDiagnosis::query()
                ->where('entity_id', $entityId)
                ->where('normalized_name', $normalizedName)
                ->firstOrFail();
        }
    }

    /**
     * Registra o uso de cada diagnóstico do payload (incrementa contador e
     * atualiza last_used_at) — sinal de ranking consumido por search().
     * Idempotente por chave (entity_id, diagnosis_type, cid10_code_id,
     * entity_custom_diagnosis_id) — mesma unicidade da tabela.
     *
     * @param array<int, array{code?: ?string, custom_diagnosis_id?: ?string}> $items
     */
    public function recordUsage(string $entityId, array $items): void
    {
        if (empty($items)) {
            return;
        }

        DB::transaction(function () use ($entityId, $items): void {
            foreach ($items as $item) {
                $isCustom = ! empty($item['custom_diagnosis_id']);

                if ($isCustom) {
                    $this->bumpUsage($entityId, DiagnosisType::Custom, null, (string) $item['custom_diagnosis_id']);

                    continue;
                }

                $code = $item['code'] ?? null;

                if (! $code) {
                    continue;
                }

                $cid10CodeId = Cid10Code::query()->where('code', $code)->value('id');

                if ($cid10CodeId === null) {
                    // Código não existe no catálogo global — nada a contabilizar.
                    continue;
                }

                $this->bumpUsage($entityId, DiagnosisType::Cid10, (string) $cid10CodeId, null);
            }
        });
    }

    /**
     * Valida posse (custom_diagnosis_id pertence à entity corrente), rejeita
     * duplicatas e normaliza is_primary do payload de diagnósticos do exame
     * antes da persistência em patient_exams.diagnosis_cids.
     *
     * A checagem de posse aqui é defesa em profundidade além da regra
     * `Rule::exists(...)->where('entity_id', ...)` já aplicada nos
     * FormRequests (UpdateExamDiagnosisRequest/ImportExternalExamRequest):
     * qualquer chamador futuro deste service (ou um FormRequest alterado por
     * engano) continua blindado contra IDOR cross-tenant de
     * entity_custom_diagnoses.
     *
     * @param array<int, array{code?: ?string, custom_diagnosis_id?: ?string, description: string, is_primary?: bool}> $items
     *
     * @return array<int, array{code?: ?string, custom_diagnosis_id?: ?string, description: string, is_primary: bool}>
     */
    public function prepareDiagnoses(string $entityId, array $items): array
    {
        $this->assertCustomDiagnosesBelongToEntity($entityId, $items);
        $this->rejectDuplicates($items);

        return $this->normalizePrimary($items);
    }

    private function bumpUsage(string $entityId, DiagnosisType $type, ?string $cid10CodeId, ?string $customDiagnosisId): void
    {
        $keys = [
            'entity_id'                  => $entityId,
            'diagnosis_type'             => $type->value,
            'cid10_code_id'              => $cid10CodeId,
            'entity_custom_diagnosis_id' => $customDiagnosisId,
        ];

        $usage = EntityDiagnosisUsage::query()->firstOrNew($keys);
        $usage->fill($keys);
        $usage->use_count    = ((int) ($usage->use_count ?? 0)) + 1;
        $usage->last_used_at = now();
        $usage->save();
    }

    /**
     * Garante que todo custom_diagnosis_id do payload pertence a
     * entity_custom_diagnoses.entity_id = $entityId — bloqueia IDOR
     * cross-tenant (ver DiagnosisRegistrationTest/ExternalExamImportTest,
     * teste "custom_diagnosis_id de outra entidade é rejeitado").
     *
     * @param array<int, array{custom_diagnosis_id?: ?string}> $items
     */
    private function assertCustomDiagnosesBelongToEntity(string $entityId, array $items): void
    {
        $ids = collect($items)
            ->pluck('custom_diagnosis_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $ownedCount = EntityCustomDiagnosis::query()
            ->where('entity_id', $entityId)
            ->whereIn('id', $ids)
            ->count();

        if ($ownedCount !== $ids->count()) {
            throw ValidationException::withMessages([
                'diagnoses' => 'Diagnóstico customizado inválido para esta entidade.',
            ]);
        }
    }

    /**
     * Rejeita payload com o mesmo code ou o mesmo custom_diagnosis_id
     * repetido duas vezes.
     *
     * @param array<int, array{code?: ?string, custom_diagnosis_id?: ?string}> $items
     */
    private function rejectDuplicates(array $items): void
    {
        $seenCodes   = [];
        $seenCustoms = [];

        foreach ($items as $item) {
            $code   = $item['code'] ?? null;
            $custom = $item['custom_diagnosis_id'] ?? null;

            if ($code) {
                if (isset($seenCodes[$code])) {
                    throw ValidationException::withMessages([
                        'diagnoses' => "Diagnóstico CID-10 duplicado: {$code}.",
                    ]);
                }

                $seenCodes[$code] = true;
            }

            if ($custom) {
                if (isset($seenCustoms[$custom])) {
                    throw ValidationException::withMessages([
                        'diagnoses' => 'Diagnóstico customizado duplicado.',
                    ]);
                }

                $seenCustoms[$custom] = true;
            }
        }
    }

    /**
     * Garante exatamente um item com is_primary = true: mantém o primeiro
     * marcado (se vier mais de um) ou marca o primeiro item (se nenhum
     * vier marcado).
     *
     * @param array<int, array{code?: ?string, custom_diagnosis_id?: ?string, description: string, is_primary?: bool}> $items
     *
     * @return array<int, array{code?: ?string, custom_diagnosis_id?: ?string, description: string, is_primary: bool}>
     */
    private function normalizePrimary(array $items): array
    {
        $primaryIndex = 0;

        foreach ($items as $i => $item) {
            if (! empty($item['is_primary'])) {
                $primaryIndex = $i;

                break;
            }
        }

        foreach ($items as $i => &$item) {
            $item['is_primary'] = ($i === $primaryIndex);
        }

        unset($item);

        return array_values($items);
    }

    /**
     * @return array<string, int>
     */
    private function useCountsFor(string $entityId, DiagnosisType $type, string $column, Collection $ids): array
    {
        $ids = $ids->filter()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return EntityDiagnosisUsage::query()
            ->where('entity_id', $entityId)
            ->where('diagnosis_type', $type->value)
            ->whereIn($column, $ids)
            ->pluck('use_count', $column)
            ->all();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
