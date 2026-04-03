<?php

namespace App\Services;

use App\Enums\ReportSettingStatus;
use App\Models\{Entity, ReportSetting, ReportSettingContent};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReportSettingService
{
    /**
     * Garante que a clínica possua cópia adotada de todos os templates globais
     * publicados/ativos. Operação idempotente.
     *
     * @return int Quantidade de templates adotados nesta execução.
     */
    public function adoptPublishedGlobalsForEntity(string $entityId): int
    {
        if ($entityId === '') {
            return 0;
        }

        $entity = Entity::query()->find($entityId);
        if (! $entity || ! $entity->isClient()) {
            return 0;
        }

        $globalTemplates = ReportSetting::global()
            ->published()
            ->active()
            ->with('contents.variables')
            ->orderBy('title')
            ->get();

        if ($globalTemplates->isEmpty()) {
            return 0;
        }

        $alreadyAdoptedSourceIds = ReportSetting::withTrashed()
            ->forEntity($entityId)
            ->whereNotNull('source_setting_id')
            ->pluck('source_setting_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $adopted = 0;

        foreach ($globalTemplates as $globalTemplate) {
            if (in_array((string) $globalTemplate->id, $alreadyAdoptedSourceIds, true)) {
                continue;
            }

            $this->adopt($globalTemplate, $entityId);
            $adopted++;
        }

        return $adopted;
    }

    /**
     * Executa adoção padrão para todas as clínicas (tenants cliente).
     *
     * @return array{entities: int, adopted: int}
     */
    public function adoptPublishedGlobalsForAllClientEntities(): array
    {
        $entities = Entity::query()
            ->where('is_client', true)
            ->pluck('id');

        $adoptedTotal = 0;

        foreach ($entities as $entityId) {
            $adoptedTotal += $this->adoptPublishedGlobalsForEntity((string) $entityId);
        }

        return [
            'entities' => $entities->count(),
            'adopted'  => $adoptedTotal,
        ];
    }

    // ─── Queries ────────────────────────────────────────────────────────────

    /**
     * Templates da clínica (próprios + adotados). Nunca retorna globals diretamente.
     */
    public function getEntityTemplates(string $entityId, ?string $categoryId = null): Collection
    {
        return ReportSetting::forEntity($entityId)
            ->active()
            ->with(['category', 'contents' => fn ($q) => $q->where('active', true)])
            ->when($categoryId, fn ($q) => $q->where('report_category_id', $categoryId))
            ->orderBy('title')
            ->get();
    }

    /**
     * Templates globais publicados, disponíveis para adoção.
     */
    public function getPublishedGlobalTemplates(?string $categoryId = null): Collection
    {
        return ReportSetting::global()
            ->published()
            ->active()
            ->with('category')
            ->when($categoryId, fn ($q) => $q->where('report_category_id', $categoryId))
            ->orderBy('title')
            ->get();
    }

    /**
     * Templates globais para o painel manager (todos os status).
     */
    public function getGlobalTemplates(?string $status = null): Collection
    {
        return ReportSetting::global()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('category')
            ->orderBy('title')
            ->get();
    }

    /**
     * Templates da clínica que foram adotados de um global específico.
     */
    public function getAdoptedCopies(string $sourceSettingId): Collection
    {
        return ReportSetting::where('source_setting_id', $sourceSettingId)
            ->with('entity')
            ->get();
    }

    // ─── Publicação (SaaS Admin) ────────────────────────────────────────────

    /**
     * Publica um template global (draft→published ou archived→published).
     */
    public function publish(ReportSetting $setting): ReportSetting
    {
        if (! $setting->isGlobal()) {
            throw new InvalidArgumentException('Apenas templates globais podem ser publicados.');
        }

        $currentStatus = $setting->status ?? ReportSettingStatus::Draft;

        if (! $currentStatus->canTransitionTo(ReportSettingStatus::Published)) {
            throw new InvalidArgumentException(
                "Não é possível publicar um template com status '{$currentStatus->value}'.",
            );
        }

        $setting->update([
            'status'       => ReportSettingStatus::Published,
            'published_at' => now(),
            'version'      => $setting->version + 1,
        ]);

        // Ao publicar global, novas clínicas (ou clínicas sem essa origem) recebem
        // automaticamente a adoção padrão sem ação manual.
        $this->adoptGlobalTemplateForAllClientEntities(
            $setting->fresh(['contents.variables']),
        );

        return $setting->fresh();
    }

    /**
     * Arquiva um template global (published→archived).
     */
    public function archive(ReportSetting $setting): ReportSetting
    {
        if (! $setting->isGlobal()) {
            throw new InvalidArgumentException('Apenas templates globais podem ser arquivados.');
        }

        $currentStatus = $setting->status ?? ReportSettingStatus::Published;

        if (! $currentStatus->canTransitionTo(ReportSettingStatus::Archived)) {
            throw new InvalidArgumentException(
                "Não é possível arquivar um template com status '{$currentStatus->value}'.",
            );
        }

        $setting->update([
            'status' => ReportSettingStatus::Archived,
        ]);

        return $setting->fresh();
    }

    // ─── Adoção (Clínica) ───────────────────────────────────────────────────

    /**
     * Adota (cópia profunda) um template global para a clínica.
     */
    public function adopt(ReportSetting $globalSetting, string $entityId): ReportSetting
    {
        if (! $globalSetting->isGlobal()) {
            throw new InvalidArgumentException('Apenas templates globais podem ser adotados.');
        }

        if ($globalSetting->status !== ReportSettingStatus::Published) {
            throw new InvalidArgumentException('Apenas templates publicados podem ser adotados.');
        }

        return DB::transaction(function () use ($globalSetting, $entityId) {
            $globalSetting->loadMissing('contents.variables');

            // 1. Clonar o setting
            $copy = $globalSetting->replicate([
                'id', 'entity_id', 'source_setting_id', 'source_version',
                'status', 'published_at', 'created_at', 'updated_at',
                'deleted_at', 'created_by', 'updated_by',
            ]);
            $copy->entity_id         = $entityId;
            $copy->source_setting_id = $globalSetting->id;
            $copy->source_version    = $globalSetting->version;
            $copy->status            = ReportSettingStatus::Published;
            $copy->version           = 1;
            $copy->save();

            // 2. Clonar contents
            foreach ($globalSetting->contents as $content) {
                $contentCopy = $content->replicate([
                    'id', 'report_setting_id', 'created_at', 'updated_at',
                    'deleted_at', 'created_by', 'updated_by',
                ]);
                $contentCopy->report_setting_id = $copy->id;
                $contentCopy->save();

                // 3. Clonar variables
                foreach ($content->variables as $variable) {
                    $varCopy = $variable->replicate([
                        'id', 'report_setting_content_id', 'created_at', 'updated_at',
                    ]);
                    $varCopy->report_setting_content_id = $contentCopy->id;
                    $varCopy->save();
                }
            }

            return $copy->load('contents.variables');
        });
    }

    /**
     * Adota um template global específico para todas as clínicas que ainda
     * não possuem cópia dessa origem.
     *
     * @return int Quantidade de adoções realizadas.
     */
    public function adoptGlobalTemplateForAllClientEntities(ReportSetting $globalSetting): int
    {
        if (! $globalSetting->isGlobal()) {
            throw new InvalidArgumentException('Apenas templates globais podem ser adotados em massa.');
        }

        if ($globalSetting->status !== ReportSettingStatus::Published || ! $globalSetting->active) {
            return 0;
        }

        $clientEntityIds = Entity::query()
            ->where('is_client', true)
            ->pluck('id');

        if ($clientEntityIds->isEmpty()) {
            return 0;
        }

        $alreadyAdoptedEntityIds = ReportSetting::withTrashed()
            ->whereIn('entity_id', $clientEntityIds)
            ->where('source_setting_id', $globalSetting->id)
            ->pluck('entity_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $adopted = 0;

        foreach ($clientEntityIds as $entityId) {
            if (in_array((string) $entityId, $alreadyAdoptedEntityIds, true)) {
                continue;
            }

            $this->adopt($globalSetting, (string) $entityId);
            $adopted++;
        }

        return $adopted;
    }

    /**
     * Reimporta o conteúdo de um template global atualizado para a cópia local.
     * Mantém entity_id e configurações de layout, substitui conteúdos e variáveis.
     */
    public function reimport(ReportSetting $localSetting): ReportSetting
    {
        if (! $localSetting->isAdopted()) {
            throw new InvalidArgumentException('Apenas templates adotados podem ser reimportados.');
        }

        $source = $localSetting->sourceSetting;

        if (! $source) {
            throw new InvalidArgumentException('Template global original não encontrado.');
        }

        return DB::transaction(function () use ($localSetting, $source) {
            $source->loadMissing('contents.variables');

            // 1. Remover conteúdos e variáveis antigos
            foreach ($localSetting->contents as $content) {
                $content->variables()->delete();
                $content->forceDelete();
            }

            // 2. Copiar novos conteúdos do global
            foreach ($source->contents as $content) {
                $contentCopy = $content->replicate([
                    'id', 'report_setting_id', 'created_at', 'updated_at',
                    'deleted_at', 'created_by', 'updated_by',
                ]);
                $contentCopy->report_setting_id = $localSetting->id;
                $contentCopy->save();

                foreach ($content->variables as $variable) {
                    $varCopy = $variable->replicate([
                        'id', 'report_setting_content_id', 'created_at', 'updated_at',
                    ]);
                    $varCopy->report_setting_content_id = $contentCopy->id;
                    $varCopy->save();
                }
            }

            // 3. Atualizar versão de referência
            $localSetting->update([
                'source_version' => $source->version,
            ]);

            return $localSetting->fresh('contents.variables');
        });
    }

    // ─── Sync Contents ──────────────────────────────────────────────────────

    /**
     * Sincroniza os conteúdos de um template (cria, atualiza, remove).
     */
    public function syncContents(ReportSetting $setting, array $contents): void
    {
        $keptIds = [];

        foreach ($contents as $row) {
            $content = isset($row['id'])
                ? ReportSettingContent::find($row['id'])
                : null;

            $data = [
                'type'    => $row['type'],
                'label'   => $row['label'],
                'content' => $row['content'],
                'active'  => $row['active'] ?? true,
            ];

            if ($content) {
                $content->update($data);
            } else {
                $content = $setting->contents()->create($data);
            }

            $keptIds[] = $content->id;
        }

        // Soft-delete contents removidos
        $setting->contents()
            ->whereNotIn('id', $keptIds)
            ->each(fn (ReportSettingContent $c) => $c->delete());
    }
}
