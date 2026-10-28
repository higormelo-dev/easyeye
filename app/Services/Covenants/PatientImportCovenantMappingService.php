<?php

declare(strict_types=1);

namespace App\Services\Covenants;

use App\Enums\{CovenantSource, Permission};
use App\Models\{Covenant, CovenantPlan, Entity, PatientImport, PatientImportCovenantMapping, User};
use App\Support\ImportNameNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Grava, em lote e tudo-ou-nada, o de-para de convênios de uma importação de pacientes (por clínica): mapear para um
 * convênio/plano do catálogo, criar o convênio na hora (exige `settings.manage`), importar como Particular ou limpar.
 *
 * Valida contra o relatório COMPLETO do arquivo (nunca o resumo truncado da prévia). O de-para vale para as próximas
 * importações; no import ainda não confirmado, também atualiza o snapshot e o resumo da prévia. Depois do confirm o
 * snapshot em uso não muda (o job já congelou o de-para do momento do confirm).
 */
class PatientImportCovenantMappingService
{
    public const ACTIONS = ['map', 'create', 'particular', 'clear'];

    public function __construct(
        private readonly PatientImportCovenantReportService $report,
        private readonly PatientImportCovenantResolver $resolver,
    ) {
    }

    /**
     * Snapshot do de-para de HOJE para todos os nomes do arquivo — gravado no confirm, para o job usar o de-para do momento
     * em que o usuário confirmou (mesmo que ele mude enquanto o import espera na fila). Lê o relatório COMPLETO, não o
     * resumo truncado da prévia.
     *
     * Arquivo com mais nomes distintos do que o teto de coleta (`names_capped`): devolve null — um snapshot só dos nomes
     * coletados seria incompleto e o job deixaria de aplicar o de-para dos demais; sem snapshot ele lê o de-para atual.
     *
     * @return array<string, array{source_name: string, covenant_id: string, covenant_plan_id: ?string}>|null
     *
     * @throws ValidationException arquivo indisponível (expirado/ausente): não há o que confirmar
     */
    public function snapshotForConfirm(PatientImport $import): ?array
    {
        $analysis = $this->report->analysis($import);

        if (! $analysis['available']) {
            throw ValidationException::withMessages(['import' => [__('imports.patients.covenant_mapping.file_missing')]]);
        }

        if ($analysis['names_capped']) {
            return null;
        }

        $this->resolver->load((string) $import->entity_id);

        return $this->resolver->snapshot($analysis['names']);
    }

    /**
     * @param list<array<string, mixed>> $mappings [{source_name, action, covenant_id?, covenant_plan_id?}]
     *
     * @return array{saved: int, created_covenants: int, reused_covenants: int, cleared: int}
     *
     * @throws AuthorizationException `create` sem `settings.manage`
     * @throws ValidationException    erros por linha (`mappings.{i}.{campo}`); nada é gravado
     */
    public function save(PatientImport $import, array $mappings, User $user): array
    {
        $entityId = (string) $import->entity_id;
        $rows     = $this->rows($mappings);

        if (in_array('create', array_column($rows, 'action'), true)
            && ! $user->hasPermissionInEntity(Entity::query()->findOrFail($entityId), Permission::SettingsManage)) {
            throw new AuthorizationException();
        }

        $fileNames = $this->fileNames($import);
        $this->resolver->load($entityId);
        $this->assertValid($rows, $fileNames, $entityId);

        return DB::transaction(function () use ($import, $entityId, $rows, $fileNames): array {
            // Serializa dois PUTs do mesmo import e lê o estado de confirmação já com a linha travada.
            $locked = PatientImport::withoutGlobalScopes()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            $meta   = ['saved' => 0, 'created_covenants' => 0, 'reused_covenants' => 0, 'cleared' => 0];
            $used   = $this->usedColors($entityId);
            $pool   = in_array('create', array_column($rows, 'action'), true) ? $this->candidates($entityId) : [];

            foreach ($rows as $row) {
                $name = $fileNames[$row['key']];

                match ($row['action']) {
                    'map'        => $this->saveMapping($entityId, $row['key'], $name, (string) $row['covenant_id'], $row['covenant_plan_id'], $meta),
                    'particular' => $this->saveMapping($entityId, $row['key'], $name, (string) $this->resolver->particularId(), null, $meta),
                    'create'     => $this->saveMapping($entityId, $row['key'], $name, $this->createOrReuse($entityId, $name, $row['key'], $pool, $used, $meta), null, $meta),
                    'clear'      => $meta['cleared'] += $this->clearMapping($entityId, $row['key']),
                };
            }

            if ($locked->confirmed_at === null) {
                $this->refreshPreview($locked, $entityId, $fileNames);
            }

            return $meta;
        });
    }

    /**
     * @param list<array<string, mixed>> $mappings
     *
     * @return list<array{index: int, source_name: string, key: string, action: string, covenant_id: ?string, covenant_plan_id: ?string}>
     */
    private function rows(array $mappings): array
    {
        $rows = [];

        foreach (array_values($mappings) as $index => $mapping) {
            $name = trim((string) ($mapping['source_name'] ?? ''));

            $rows[] = [
                'index'            => $index,
                'source_name'      => $name,
                'key'              => ImportNameNormalizer::covenantKey($name),
                'action'           => (string) ($mapping['action'] ?? ''),
                'covenant_id'      => filled($mapping['covenant_id'] ?? null) ? (string) $mapping['covenant_id'] : null,
                'covenant_plan_id' => filled($mapping['covenant_plan_id'] ?? null) ? (string) $mapping['covenant_plan_id'] : null,
            ];
        }

        return $rows;
    }

    /** @return array<string, string> covenantKey → grafia do nome no arquivo */
    private function fileNames(PatientImport $import): array
    {
        $names = [];

        foreach ($this->report->sourceNames($import) as $name) {
            $key = ImportNameNormalizer::covenantKey($name);

            if ($key !== '') {
                $names[$key] ??= $name;
            }
        }

        return $names;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string>      $fileNames
     */
    private function assertValid(array $rows, array $fileNames, string $entityId): void
    {
        $errors = [];
        $plans  = $this->plans($rows);
        $seen   = [];

        foreach ($rows as $row) {
            $i = $row['index'];

            if (! in_array($row['action'], self::ACTIONS, true)) {
                $errors["mappings.{$i}.action"][] = __('imports.patients.covenant_mapping.invalid_action');

                continue;
            }

            if ($row['key'] === '' || ! isset($fileNames[$row['key']])) {
                $errors["mappings.{$i}.source_name"][] = __('imports.patients.covenant_mapping.source_not_in_file');
            } elseif (isset($seen[$row['key']])) {
                $errors["mappings.{$i}.source_name"][] = __('validation.distinct', ['attribute' => 'source_name']);
            } else {
                $seen[$row['key']] = true;
            }

            if ($row['action'] === 'map') {
                $this->assertMapTarget($row, $plans, $entityId, $errors);

                continue;
            }

            foreach (['covenant_id', 'covenant_plan_id'] as $field) {
                if ($row[$field] !== null) {
                    $errors["mappings.{$i}.{$field}"][] = __('validation.prohibited', ['attribute' => $field]);
                }
            }

            if ($row['action'] === 'particular' && $this->resolver->particularId() === null) {
                $errors["mappings.{$i}.action"][] = __('imports.patients.covenant_mapping.target_unavailable', ['name' => $row['source_name']]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param array<string, mixed>                $row
     * @param array<string, array<string, mixed>> $plans
     * @param array<string, list<string>>         $errors
     */
    private function assertMapTarget(array $row, array $plans, string $entityId, array &$errors): void
    {
        $i = $row['index'];

        if ($row['covenant_id'] === null) {
            $errors["mappings.{$i}.covenant_id"][] = __('validation.required', ['attribute' => 'covenant_id']);

            return;
        }

        if (! Str::isUuid($row['covenant_id'])) {
            $errors["mappings.{$i}.covenant_id"][] = __('validation.uuid', ['attribute' => 'covenant_id']);

            return;
        }

        if ($this->resolver->usableCovenant($row['covenant_id']) === null) {
            $errors["mappings.{$i}.covenant_id"][] = __('imports.patients.covenant_mapping.target_unavailable', ['name' => $row['source_name']]);

            return;
        }

        if ($row['covenant_plan_id'] === null) {
            return;
        }

        $plan = $plans[$row['covenant_plan_id']] ?? null;

        // O Particular não guarda plano; o plano precisa ser do convênio escolhido, ativo e global ou da clínica.
        $usable = $plan !== null
            && $row['covenant_id'] !== $this->resolver->particularId()
            && $plan['covenant_id'] === $row['covenant_id']
            && $plan['active']
            && ($plan['entity_id'] === null || $plan['entity_id'] === $entityId);

        if (! $usable) {
            $errors["mappings.{$i}.covenant_plan_id"][] = __('imports.patients.covenant_mapping.plan_unavailable', ['name' => $row['source_name']]);
        }
    }

    /**
     * Planos citados no lote, numa consulta só.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array{covenant_id: string, active: bool, entity_id: ?string}>
     */
    private function plans(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(
            array_column(array_filter($rows, fn (array $row): bool => $row['action'] === 'map'), 'covenant_plan_id'),
            fn (?string $id): bool => $id !== null && Str::isUuid($id),
        )));

        if ($ids === []) {
            return [];
        }

        return CovenantPlan::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->get(['id', 'covenant_id', 'active', 'entity_id'])
            ->mapWithKeys(fn (CovenantPlan $plan): array => [(string) $plan->id => [
                'covenant_id' => (string) $plan->covenant_id,
                'active'      => (bool) $plan->active,
                'entity_id'   => $plan->entity_id !== null ? (string) $plan->entity_id : null,
            ]])
            ->all();
    }

    /** @param array{saved: int, created_covenants: int, reused_covenants: int, cleared: int} $meta */
    private function saveMapping(string $entityId, string $key, string $sourceName, string $covenantId, ?string $planId, array &$meta): void
    {
        $attributes = ['entity_id' => $entityId, 'source_name_normalized' => $key];
        $values     = ['source_name' => $sourceName, 'covenant_id' => $covenantId, 'covenant_plan_id' => $planId];

        try {
            // Savepoint: a violação do índice único (duas importações gravando o mesmo nome) não aborta a transação inteira.
            DB::transaction(fn () => PatientImportCovenantMapping::withoutGlobalScopes()->updateOrCreate($attributes, $values));
        } catch (UniqueConstraintViolationException) {
            PatientImportCovenantMapping::withoutGlobalScopes()->where($attributes)->firstOrFail()->update($values);
        }

        $meta['saved']++;
    }

    private function clearMapping(string $entityId, string $key): int
    {
        $mapping = PatientImportCovenantMapping::withoutGlobalScopes()->where('entity_id', $entityId)->where('source_name_normalized', $key)->first();

        $mapping?->delete();

        return $mapping === null ? 0 : 1;
    }

    /**
     * Convênio para `create`, idempotente — ordem: próprio ativo → global ativo → próprio inativo (reativa) → próprio
     * excluído (restaura) → cria. A criação não cria plano.
     *
     * @param array<string, list<array<string, mixed>>> $pool
     * @param array<string, true>                       $used
     * @param array<string, int>                        $meta
     */
    private function createOrReuse(string $entityId, string $name, string $key, array &$pool, array &$used, array &$meta): string
    {
        $candidates = $pool[$key] ?? [];
        $find       = fn (callable $test): ?array => collect($candidates)->first($test);

        $own    = $find(fn (array $c): bool => $c['own'] && ! $c['deleted'] && $c['active']);
        $global = $find(fn (array $c): bool => ! $c['own'] && ! $c['deleted'] && $c['active']);

        if ($own !== null || $global !== null) {
            $meta['reused_covenants']++;

            return (string) ($own ?? $global)['id'];
        }

        $inactive = $find(fn (array $c): bool => $c['own'] && ! $c['deleted']);
        $deleted  = $find(fn (array $c): bool => $c['own'] && $c['deleted']);

        if ($inactive !== null || $deleted !== null) {
            $covenant = Covenant::withoutGlobalScopes()->findOrFail(($inactive ?? $deleted)['id']);

            if ($covenant->trashed()) {
                $covenant->restore();
            }

            $covenant->update(['active' => true]);
            $meta['reused_covenants']++;
            $pool[$key] = [['id' => (string) $covenant->id, 'own' => true, 'deleted' => false, 'active' => true]];

            return (string) $covenant->id;
        }

        $covenant = Covenant::create([
            'entity_id' => $entityId,
            'name'      => $name,
            'color'     => $this->nextColor($key, $used),
            'table'     => false,
            'active'    => true,
            'source'    => CovenantSource::Manual,
        ]);
        $meta['created_covenants']++;
        $pool[$key] = [['id' => (string) $covenant->id, 'own' => true, 'deleted' => false, 'active' => true]];

        return (string) $covenant->id;
    }

    /**
     * Convênios da clínica (inclusive inativos e excluídos) e globais, por covenantKey do nome.
     *
     * @return array<string, list<array{id: string, own: bool, deleted: bool, active: bool}>>
     */
    private function candidates(string $entityId): array
    {
        $pool = [];

        Covenant::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->get(['id', 'name', 'active', 'entity_id', 'deleted_at'])
            ->each(function (Covenant $covenant) use (&$pool): void {
                $pool[ImportNameNormalizer::covenantKey((string) $covenant->name)][] = [
                    'id'      => (string) $covenant->id,
                    'own'     => $covenant->entity_id !== null,
                    'deleted' => $covenant->deleted_at !== null,
                    'active'  => (bool) $covenant->active,
                ];
            });

        return $pool;
    }

    /** @return array<string, true> cores (#RRGGBB maiúsculas) dos convênios não excluídos da clínica */
    private function usedColors(string $entityId): array
    {
        return Covenant::withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->pluck('color')
            ->mapWithKeys(fn ($color): array => [strtoupper((string) $color) => true])
            ->all();
    }

    /**
     * Cor determinística do nome (matiz derivado do hash, passo de 47° até achar uma que a clínica ainda não use —
     * a tela de convênios exige cor única por clínica).
     *
     * @param array<string, true> $used
     */
    private function nextColor(string $key, array &$used): string
    {
        $hue   = crc32($key) % 360;
        $color = '#3699FF';

        for ($step = 0; $step < 360; $step++) {
            $color = $this->hslToHex(($hue + $step * 47) % 360, 0.62, 0.5);

            if (! isset($used[$color])) {
                break;
            }
        }

        $used[$color] = true;

        return $color;
    }

    private function hslToHex(int $hue, float $saturation, float $lightness): string
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x      = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $m      = $lightness - $chroma / 2;

        [$r, $g, $b] = match (intdiv($hue, 60)) {
            0       => [$chroma, $x, 0],
            1       => [$x, $chroma, 0],
            2       => [0, $chroma, $x],
            3       => [0, $x, $chroma],
            4       => [$x, 0, $chroma],
            default => [$chroma, 0, $x],
        };

        return sprintf('#%02X%02X%02X', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }

    /**
     * Import ainda não confirmado: o snapshot passa a refletir o de-para salvo e a prévia mostra os novos status sem reload.
     *
     * @param array<string, string> $fileNames
     */
    private function refreshPreview(PatientImport $import, string $entityId, array $fileNames): void
    {
        $this->resolver->load($entityId);
        $report = $this->report->forImport($import->refresh());

        $import->update([
            'covenant_mapping_snapshot' => $this->resolver->snapshot(array_values($fileNames)),
            'preview'                   => [
                ...(is_array($import->preview) ? $import->preview : []),
                'covenant_summary' => PatientImportCovenantReportService::summaryPayload($report['items'], $report['totals']['file_rows']),
            ],
        ]);
    }
}
