<?php

declare(strict_types=1);

namespace App\Services\Covenants;

use App\Models\{Covenant, CovenantPlan, PatientImportCovenantMapping};
use App\Support\ImportNameNormalizer;

/**
 * Fonte única da resolução de convênios da importação de pacientes: prévia, relatório e processamento usam o mesmo
 * catálogo e o mesmo de-para e não divergem. `load()` roda UMA vez por import (3 consultas no máximo); `forRow()` e
 * `forSource()` só consultam memória — nenhuma query por linha.
 *
 * Catálogo = convênios da clínica + globais, não excluídos; a chave é ImportNameNormalizer::covenantKey (SulAmérica =
 * SULAMERICA = Sul América). Ordem do mapa: inativos primeiro, depois globais, da clínica por último — o último vence:
 * o ativo ganha do inativo de mesmo nome e, entre ativos, o da clínica ganha do global (igual ao loadCovenantsMap de
 * antes). O de-para da clínica prevalece sobre o catálogo; o plano mapeado é opcional e vale para todas as linhas do
 * convênio (a coluna "plano" da planilha só é lida pelo PatientImportService quando não há plano mapeado).
 */
class PatientImportCovenantResolver
{
    /** Chave (covenantKey) do convênio usado quando a coluna vem vazia. */
    private const PARTICULAR_KEY = 'particular';

    private string $entityId = '';

    private ?string $particularId = null;

    /** @var array<string, array{id: string, name: string, code: ?string, active: bool, is_own: bool}> covenantKey → convênio */
    private array $byName = [];

    /** @var array<string, array{id: string, name: string, code: ?string, active: bool, is_own: bool}> */
    private array $byId = [];

    /** @var array<string, array{source_name: string, covenant_id: string, covenant_plan_id: ?string}> covenantKey → de-para */
    private array $mappings = [];

    /** @var array<string, array{id: string, covenant_id: string, name: string, ans_code: ?string, active: bool, entity_id: ?string}> */
    private array $plans = [];

    /**
     * Carrega catálogo + de-para da clínica. `$snapshot` (de-para congelado no confirm/reprocessamento) substitui a
     * tabela quando não é null; `[]` significa "sem de-para" e também vale — só imports antigos (null) leem a tabela.
     *
     * @param array<string, array<string, mixed>>|null $snapshot
     */
    public function load(string $entityId, ?array $snapshot = null): void
    {
        $this->entityId = $entityId;
        $this->loadCatalog();
        $this->loadMappings($snapshot);
    }

    /**
     * Resolução de uma linha para o processamento. Linha sem convênio → Particular; caso contrário o de-para vence e,
     * sem de-para, vale o catálogo. Falha lança CovenantResolutionException (a linha vira erro reprocessável).
     *
     * @return array{covenant_id: string, mapped_plan_id: ?string, is_particular: bool}
     */
    public function forRow(?string $sourceName): array
    {
        $name = trim((string) $sourceName);

        if ($name === '') {
            return $this->particularRow();
        }

        $key     = ImportNameNormalizer::covenantSourceKey($name);
        $mapping = $this->mappings[$key] ?? null;

        if ($mapping !== null) {
            return $this->mappedRow($name, $key, $mapping);
        }

        $covenant = $this->byName[$key] ?? null;

        if ($covenant === null) {
            throw new CovenantResolutionException(
                "Convênio \"{$name}\" não encontrado. Cadastre-o em Configurações › Convênios ou corrija a planilha.",
                $name,
                $key,
                CovenantResolutionException::NOT_FOUND,
            );
        }

        // Vínculo novo só com convênio ativo (mesma regra do cadastro manual, App\Rules\ActiveCovenant).
        if (! $covenant['active']) {
            throw new CovenantResolutionException(
                __('validation.custom.covenant.inactive_named', ['name' => $name]),
                $name,
                $key,
                CovenantResolutionException::INACTIVE,
                $covenant['id'],
            );
        }

        return ['covenant_id' => $covenant['id'], 'mapped_plan_id' => null, 'is_particular' => $covenant['id'] === $this->particularId];
    }

    /**
     * Situação de um nome do arquivo para a prévia e o relatório (sem lançar): found|unmatched|inactive|mapped|
     * particular|invalid_mapping, mais o alvo atual. `retryable` = as linhas deste nome falharão por convênio até
     * haver de-para (unmatched/inactive/invalid_mapping); o relatório de um import já processado usa as contagens reais.
     *
     * @return array{source_name: string, source_name_normalized: string, status: string, covenant: ?array<string, mixed>, plan: ?array<string, mixed>, mapping: ?array<string, mixed>, retryable: bool}
     */
    public function forSource(string $sourceName): array
    {
        $name = trim($sourceName);
        $key  = ImportNameNormalizer::covenantSourceKey($name);
        $item = ['source_name' => $name, 'source_name_normalized' => $key, 'status' => '', 'covenant' => null, 'plan' => null, 'mapping' => null, 'retryable' => false];

        if ($name === '') {
            return [...$item, 'status' => 'particular', 'covenant' => $this->covenantPayload($this->particularId)];
        }

        $mapping = $this->mappings[$key] ?? null;

        if ($mapping !== null) {
            return [...$item, ...$this->mappedSource($mapping)];
        }

        $covenant = $this->byName[$key] ?? null;

        if ($covenant === null) {
            return [...$item, 'status' => 'unmatched', 'retryable' => true];
        }

        return [
            ...$item,
            'status'    => $covenant['active'] ? 'found' : 'inactive',
            'covenant'  => $this->covenantPayload($covenant['id']),
            'retryable' => ! $covenant['active'],
        ];
    }

    /**
     * De-para dos nomes pedidos (formato do `covenant_mapping_snapshot`): só os que têm de-para, chaveado pela covenantKey.
     *
     * @param list<string> $sourceNames
     *
     * @return array<string, array{source_name: string, covenant_id: string, covenant_plan_id: ?string}>
     */
    public function snapshot(array $sourceNames): array
    {
        $snapshot = [];

        foreach ($sourceNames as $name) {
            $key = ImportNameNormalizer::covenantSourceKey((string) $name);

            if ($key !== '' && isset($this->mappings[$key])) {
                $snapshot[$key] = $this->mappings[$key];
            }
        }

        return $snapshot;
    }

    /**
     * covenantKey → id do catálogo carregado (próprios + globais, não excluídos; o ativo/da clínica vence).
     *
     * @return array<string, string>
     */
    public function catalogMap(): array
    {
        return array_map(fn (array $covenant): string => $covenant['id'], $this->byName);
    }

    public function particularId(): ?string
    {
        return $this->particularId;
    }

    /**
     * Convênio que pode ser alvo de um de-para: visível (da clínica ou global), não excluído e ativo — o Particular vale
     * mesmo inativo, como sempre.
     *
     * @return ?array{id: string, name: string, code: ?string, active: bool, is_own: bool}
     */
    public function usableCovenant(string $covenantId): ?array
    {
        return $this->targetCovenant($covenantId);
    }

    private function loadCatalog(): void
    {
        $this->byName = $this->byId = [];

        // Inativos primeiro, depois globais, da clínica por último: o último sobrescreve.
        $covenants = Covenant::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('entity_id', $this->entityId)->orWhereNull('entity_id'))
            ->whereNull('deleted_at')
            ->orderBy('active')
            ->orderByRaw('entity_id IS NOT NULL')
            ->get(['id', 'name', 'active', 'code', 'entity_id']);

        foreach ($covenants as $covenant) {
            $row = [
                'id'     => (string) $covenant->id,
                'name'   => (string) $covenant->name,
                'code'   => $covenant->code !== null ? (string) $covenant->code : null,
                'active' => (bool) $covenant->active,
                'is_own' => $covenant->entity_id !== null,
            ];

            $this->byId[$row['id']]                                        = $row;
            $this->byName[ImportNameNormalizer::covenantKey($row['name'])] = $row;
        }

        $this->particularId = $this->byName[self::PARTICULAR_KEY]['id'] ?? null;
    }

    /** @param array<string, array<string, mixed>>|null $snapshot */
    private function loadMappings(?array $snapshot): void
    {
        $this->mappings = $this->plans = [];

        $rows = $snapshot ?? PatientImportCovenantMapping::withoutGlobalScopes()
            ->where('entity_id', $this->entityId)
            ->get(['source_name', 'source_name_normalized', 'covenant_id', 'covenant_plan_id'])
            ->mapWithKeys(fn (PatientImportCovenantMapping $mapping) => [(string) $mapping->source_name_normalized => [
                'source_name' => $mapping->source_name, 'covenant_id' => $mapping->covenant_id, 'covenant_plan_id' => $mapping->covenant_plan_id,
            ]])
            ->all();

        foreach ($rows as $key => $row) {
            if (blank($row['covenant_id'] ?? null)) {
                continue;
            }

            $this->mappings[(string) $key] = [
                'source_name'      => (string) ($row['source_name'] ?? $key),
                'covenant_id'      => (string) $row['covenant_id'],
                'covenant_plan_id' => filled($row['covenant_plan_id'] ?? null) ? (string) $row['covenant_plan_id'] : null,
            ];
        }

        $planIds = array_values(array_unique(array_filter(array_column($this->mappings, 'covenant_plan_id'))));

        if ($planIds === []) {
            return;
        }

        // Uma consulta para todos os planos mapeados (CovenantPlan::selectableFor é por convênio, não serve em lote).
        CovenantPlan::withoutGlobalScopes()
            ->whereIn('id', $planIds)
            ->whereNull('deleted_at')
            ->get(['id', 'covenant_id', 'name', 'ans_code', 'active', 'entity_id'])
            ->each(function (CovenantPlan $plan): void {
                $this->plans[(string) $plan->id] = [
                    'id'          => (string) $plan->id,
                    'covenant_id' => (string) $plan->covenant_id,
                    'name'        => (string) $plan->name,
                    'ans_code'    => $plan->ans_code !== null ? (string) $plan->ans_code : null,
                    'active'      => (bool) $plan->active,
                    'entity_id'   => $plan->entity_id !== null ? (string) $plan->entity_id : null,
                ];
            });
    }

    /** @return array{covenant_id: string, mapped_plan_id: ?string, is_particular: bool} */
    private function particularRow(): array
    {
        if ($this->particularId === null) {
            throw new CovenantResolutionException(
                'Convênio não informado e nenhum convênio "Particular" cadastrado.',
                '',
                '',
                CovenantResolutionException::PARTICULAR_MISSING,
            );
        }

        return ['covenant_id' => $this->particularId, 'mapped_plan_id' => null, 'is_particular' => true];
    }

    /**
     * @param array{source_name: string, covenant_id: string, covenant_plan_id: ?string} $mapping
     *
     * @return array{covenant_id: string, mapped_plan_id: ?string, is_particular: bool}
     */
    private function mappedRow(string $name, string $key, array $mapping): array
    {
        if ($this->targetCovenant($mapping['covenant_id']) === null) {
            throw new CovenantResolutionException(
                __('imports.patients.covenant_mapping.target_unavailable', ['name' => $name]),
                $name,
                $key,
                CovenantResolutionException::MAPPING_COVENANT_UNAVAILABLE,
                $mapping['covenant_id'],
                $mapping['covenant_plan_id'],
            );
        }

        // Particular não guarda plano (nem carteirinha): o plano do de-para é ignorado.
        if ($mapping['covenant_id'] === $this->particularId) {
            return ['covenant_id' => $mapping['covenant_id'], 'mapped_plan_id' => null, 'is_particular' => true];
        }

        if ($mapping['covenant_plan_id'] !== null && ! $this->planUsable($mapping['covenant_plan_id'], $mapping['covenant_id'])) {
            throw new CovenantResolutionException(
                __('imports.patients.covenant_mapping.plan_unavailable', ['name' => $name]),
                $name,
                $key,
                CovenantResolutionException::MAPPING_PLAN_UNAVAILABLE,
                $mapping['covenant_id'],
                $mapping['covenant_plan_id'],
            );
        }

        return ['covenant_id' => $mapping['covenant_id'], 'mapped_plan_id' => $mapping['covenant_plan_id'], 'is_particular' => false];
    }

    /**
     * @param array{source_name: string, covenant_id: string, covenant_plan_id: ?string} $mapping
     *
     * @return array<string, mixed>
     */
    private function mappedSource(array $mapping): array
    {
        $payload = [
            'covenant' => $this->covenantPayload($mapping['covenant_id']),
            'plan'     => null,
            'mapping'  => ['covenant_id' => $mapping['covenant_id'], 'covenant_plan_id' => $mapping['covenant_plan_id']],
        ];

        $covenantOk   = $this->targetCovenant($mapping['covenant_id']) !== null;
        $isParticular = $mapping['covenant_id'] === $this->particularId;
        $planOk       = $isParticular || $mapping['covenant_plan_id'] === null || $this->planUsable($mapping['covenant_plan_id'], $mapping['covenant_id']);

        if (! $isParticular && $mapping['covenant_plan_id'] !== null && isset($this->plans[$mapping['covenant_plan_id']])) {
            $plan            = $this->plans[$mapping['covenant_plan_id']];
            $payload['plan'] = ['id' => $plan['id'], 'name' => $plan['name'], 'ans_code' => $plan['ans_code'], 'active' => $plan['active']];
        }

        if (! $covenantOk || ! $planOk) {
            return [...$payload, 'status' => 'invalid_mapping', 'retryable' => true];
        }

        return [...$payload, 'status' => $isParticular ? 'particular' : 'mapped', 'retryable' => false];
    }

    /** Alvo do de-para: visível (da clínica ou global), não excluído e ativo — exceto o Particular, aceito como sempre. */
    private function targetCovenant(string $covenantId): ?array
    {
        $covenant = $this->byId[$covenantId] ?? null;

        if ($covenant === null || (! $covenant['active'] && $covenantId !== $this->particularId)) {
            return null;
        }

        return $covenant;
    }

    /** Plano do de-para: do convênio mapeado, ativo, não excluído e global ou da clínica. */
    private function planUsable(string $planId, string $covenantId): bool
    {
        $plan = $this->plans[$planId] ?? null;

        return $plan !== null
            && $plan['covenant_id'] === $covenantId
            && $plan['active']
            && ($plan['entity_id'] === null || $plan['entity_id'] === $this->entityId);
    }

    /** @return ?array{id: string, name: string, code: ?string, active: bool, is_own: bool} */
    private function covenantPayload(?string $covenantId): ?array
    {
        return $covenantId !== null ? ($this->byId[$covenantId] ?? null) : null;
    }
}
