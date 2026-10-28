<?php

namespace App\Services;

use App\Enums\ReportSettingStatus;
use App\Models\{Entity, ReportSetting, ReportSettingContent};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mews\Purifier\Facades\Purifier;

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

            // 2. Clonar contents (+ variables), cada um marcado com a versão
            // do padrão de que veio.
            foreach ($globalSetting->contents as $content) {
                $this->copyContent($content, (string) $copy->id);
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
            $localSetting->loadMissing('contents');

            $keptIds = [];

            // Sem slug (variante do gerenciador, syncContents() não exige
            // slug pra variante nova) não dá pra casar por chave — mais de
            // uma variante sem slug no padrão sempre achava a MESMA cópia
            // local (firstWhere nunca "consome" o que já casou), sobrescrevia
            // ela de novo a cada variante e apagava as outras por nunca
            // entrarem em keptIds (12ª revisão Codex, P2). Casa pela POSIÇÃO
            // entre as sem-slug dos dois lados — é o único critério que resta.
            $slugless  = $localSetting->contents->whereNull('slug')->values();
            $slugIndex = 0;

            // 1. Mesmo slug no padrão → atualiza NO MESMO registro, preservando
            //    o ID (applyGlobalContent(), igual ao sync). Recriar do zero
            //    (forceDelete + copyContent) desligava o modelo PESSOAL do
            //    médico que aponta pra esse conteúdo (DoctorDocumentTemplate.
            //    report_setting_content_id é nullOnDelete) — o médico perdia o
            //    modelo do seletor mesmo com a base ainda existindo, só com
            //    outro ID (3ª revisão Codex, P2).
            foreach ($source->contents as $sourceContent) {
                $localContent = $sourceContent->slug !== null
                    ? $localSetting->contents->firstWhere('slug', $sourceContent->slug)
                    : $slugless->get($slugIndex++);

                if ($localContent) {
                    $this->applyGlobalContent($sourceContent, $localContent);
                    // applyGlobalContent() não toca type/active (o sync nunca
                    // muda isso num slug existente); reimport SUBSTITUI tudo.
                    $localContent->forceFill(['type' => $sourceContent->type, 'active' => $sourceContent->active])->save();
                    $keptIds[] = $localContent->id;

                    continue;
                }

                $keptIds[] = $this->copyContent($sourceContent, (string) $localSetting->id)->id;
            }

            // 2. Slug que saiu do padrão → remove (nada pra preservar).
            foreach ($localSetting->contents as $content) {
                if (! in_array($content->id, $keptIds, true)) {
                    $content->variables()->delete();
                    $content->forceDelete();
                }
            }

            // 3. Atualizar versão de referência
            $localSetting->update([
                'source_version' => $source->version,
            ]);

            return $localSetting->fresh('contents.variables');
        });
    }

    // ─── Backfill (F4d) ─────────────────────────────────────────────────────

    /**
     * Sincroniza contents/variables das cópias adoptadas com o template global.
     *
     * Regra (sem apagar personalização da clínica):
     *   - Slug presente no global mas ausente na cópia → CRIA (marcado com a
     *     versão do padrão).
     *   - Slug presente em ambos, global mudou desde a versão da cópia:
     *       • cópia INTOCADA (texto ainda igual à versão de que veio —
     *         `source_content_hash`) → ATUALIZA (preserva o ID; documentos já
     *         emitidos guardam o próprio texto) e grava a nova versão;
     *       • cópia PERSONALIZADA (sem versão ou editada depois) → NÃO TOCA:
     *         fica com "atualização disponível" na tela de Modelos
     *         (aplicar ou manter o meu) e conta em `contents_skipped_customized`;
     *       • cópia já igual ao global → só religa a versão.
     *   - Slug só na cópia → NÃO TOCA.
     *
     * Idempotente. Roda no deploy (seeders) e em `reports:sync-adopted`.
     *
     * @return array{settings_synced:int,contents_created:int,contents_updated:int,contents_skipped_customized:int}
     */
    public function syncAdoptedContentsWithGlobal(): array
    {
        $stats = ['settings_synced' => 0, 'contents_created' => 0, 'contents_updated' => 0, 'contents_skipped_customized' => 0];

        $globals = ReportSetting::global()
            ->published()
            ->active()
            ->with('contents.variables')
            ->get();

        foreach ($globals as $global) {
            $copies = ReportSetting::query()
                ->withoutEntityScope()
                ->where('source_setting_id', $global->id)
                ->with('contents.variables')
                ->get();

            if ($copies->isEmpty()) {
                continue;
            }

            $diff = false;

            foreach ($copies as $copy) {
                $touched = $this->syncCopyContentsAgainstGlobal($global, $copy, $stats);

                if ($touched) {
                    $diff = true;
                }
            }

            if ($diff) {
                $stats['settings_synced']++;
            }
        }

        return $stats;
    }

    /**
     * @param array{settings_synced:int,contents_created:int,contents_updated:int,contents_skipped_customized:int} &$stats
     */
    private function syncCopyContentsAgainstGlobal(ReportSetting $global, ReportSetting $copy, array &$stats): bool
    {
        return DB::transaction(function () use ($global, $copy, &$stats) {
            $touched = false;

            foreach ($global->contents as $globalContent) {
                $localContent = $copy->contents->firstWhere('slug', $globalContent->slug);

                if (! $localContent) {
                    $this->copyContent($globalContent, (string) $copy->id);
                    $stats['contents_created']++;
                    $touched = true;

                    continue;
                }

                $globalHash = $globalContent->currentFingerprint();

                // Cópia já está na versão atual do padrão (editada ou não).
                if ($localContent->source_content_hash === $globalHash) {
                    continue;
                }

                // Texto da cópia já igual ao padrão: só religa a versão.
                if ($localContent->currentFingerprint() === $globalHash) {
                    $localContent->forceFill(['source_content_hash' => $globalHash, 'dismissed_source_hash' => null])->saveQuietly();

                    continue;
                }

                if ($localContent->isUntouchedCopy()) {
                    $this->applyGlobalContent($globalContent, $localContent);
                    $stats['contents_updated']++;
                    $touched = true;

                    continue;
                }

                // Personalizada pela clínica: não sobrescreve (aviso na tela).
                $stats['contents_skipped_customized']++;
            }

            return $touched;
        });
    }

    // ─── Atualização do modelo padrão (clínica decide) ─────────────────────

    /**
     * Conteúdos da cópia com atualização do modelo padrão esperando decisão
     * da clínica (personalizados, padrão mudou, não dispensados).
     *
     * @return array<string, ReportSettingContent> id do conteúdo da cópia → conteúdo do padrão
     */
    public function pendingUpstreamUpdates(ReportSetting $copy): array
    {
        return $this->pendingUpstreamUpdatesFor(collect([$copy]))[(string) $copy->id] ?? [];
    }

    /**
     * Mesmo cálculo para várias cópias (listagem) — duas consultas.
     *
     * @param \Illuminate\Support\Collection<int, ReportSetting> $copies
     *
     * @return array<string, array<string, ReportSettingContent>> id da cópia → (id do conteúdo → padrão)
     */
    public function pendingUpstreamUpdatesFor(\Illuminate\Support\Collection $copies): array
    {
        $copies = $copies->filter(fn (ReportSetting $c) => $c->source_setting_id !== null)->values();

        if ($copies->isEmpty()) {
            return [];
        }

        $globalContents = ReportSettingContent::query()
            ->whereIn('report_setting_id', $copies->pluck('source_setting_id')->unique()->values())
            ->whereNotNull('slug')
            // `active` sozinho não basta: arquivar muda `status`, não `active`
            // — um padrão arquivado e editado depois continuava oferecendo a
            // "atualização" (adoção e a sincronização automática já exigem
            // published(); isto tinha ficado de fora) (13ª revisão Codex, P2).
            ->whereHas('reportSetting', fn ($q) => $q->withoutEntityScope()->whereNull('entity_id')->where('status', ReportSettingStatus::Published)->where('active', true))
            ->get()
            ->groupBy(fn (ReportSettingContent $c) => (string) $c->report_setting_id);

        $localContents = ReportSettingContent::query()
            ->whereIn('report_setting_id', $copies->pluck('id'))
            ->whereNotNull('slug')
            ->get()
            ->groupBy(fn (ReportSettingContent $c) => (string) $c->report_setting_id);

        $out = [];

        foreach ($copies as $copy) {
            $globals = ($globalContents->get((string) $copy->source_setting_id) ?? collect())->keyBy('slug');

            foreach ($localContents->get((string) $copy->id) ?? [] as $local) {
                $global = $globals->get($local->slug);

                if ($global === null) {
                    continue;
                }

                $globalHash = $global->currentFingerprint();

                if ($local->source_content_hash === $globalHash
                    || $local->dismissed_source_hash === $globalHash
                    || $local->currentFingerprint() === $globalHash) {
                    continue;
                }

                $out[(string) $copy->id][(string) $local->id] = $global;
            }
        }

        return $out;
    }

    /**
     * "Aplicar atualização": a clínica troca o texto dela pelo do padrão
     * atual (com variáveis) — fica marcada com essa versão. Auditoria.
     */
    public function applyUpstreamUpdate(ReportSetting $copy, ReportSettingContent $local): ReportSettingContent
    {
        $global = $this->pendingUpstreamUpdates($copy)[(string) $local->id] ?? null;

        if ($global === null) {
            throw new InvalidArgumentException('Sem atualização do modelo padrão para este conteúdo.');
        }

        $old = $local->only(['label', 'content']);

        DB::transaction(fn () => $this->applyGlobalContent($global, $local));

        app(AuditService::class)->log(
            event: 'template_update_applied',
            model: $local,
            old: $old,
            new: [...$local->fresh()->only(['label', 'content']), 'source_content_hash' => $global->currentFingerprint()],
        );

        return $local->fresh();
    }

    /**
     * "Manter o meu": dispensa ESTA versão do padrão — o aviso volta quando o
     * padrão mudar de novo. O texto da clínica não muda. Auditoria.
     */
    public function keepLocalContent(ReportSetting $copy, ReportSettingContent $local): ReportSettingContent
    {
        $global = $this->pendingUpstreamUpdates($copy)[(string) $local->id] ?? null;

        if ($global === null) {
            throw new InvalidArgumentException('Sem atualização do modelo padrão para este conteúdo.');
        }

        $hash = $global->currentFingerprint();
        $local->forceFill(['dismissed_source_hash' => $hash])->saveQuietly();

        app(AuditService::class)->log(
            event: 'template_update_kept',
            model: $local,
            old: null,
            new: ['dismissed_source_hash' => $hash],
        );

        return $local->fresh();
    }

    /** Copia um conteúdo do padrão (com variáveis) para o modelo da clínica. */
    private function copyContent(ReportSettingContent $globalContent, string $settingId): ReportSettingContent
    {
        $contentCopy = $globalContent->replicate([
            'id', 'report_setting_id', 'created_at', 'updated_at',
            'deleted_at', 'created_by', 'updated_by', 'deleted_by',
            'source_content_hash', 'dismissed_source_hash',
        ]);
        $contentCopy->report_setting_id   = $settingId;
        $contentCopy->source_content_hash = $globalContent->currentFingerprint();
        $contentCopy->save();

        foreach ($globalContent->variables as $variable) {
            $varCopy = $variable->replicate([
                'id', 'report_setting_content_id', 'created_at', 'updated_at',
            ]);
            $varCopy->report_setting_content_id = $contentCopy->id;
            $varCopy->save();
        }

        return $contentCopy;
    }

    /** Texto + variáveis do padrão na cópia, preservando o ID do conteúdo. */
    private function applyGlobalContent(ReportSettingContent $globalContent, ReportSettingContent $localContent): void
    {
        $globalContent->loadMissing('variables');

        $localContent->forceFill([
            'content'               => $globalContent->content,
            'label'                 => $globalContent->label,
            'sort_order'            => $globalContent->sort_order,
            'source_content_hash'   => $globalContent->currentFingerprint(),
            'dismissed_source_hash' => null,
        ])->save();

        // Substitui variables (delete-then-insert) — ID do content é preservado.
        $localContent->variables()->delete();

        foreach ($globalContent->variables as $variable) {
            $varCopy = $variable->replicate([
                'id', 'report_setting_content_id', 'created_at', 'updated_at',
            ]);
            $varCopy->report_setting_content_id = $localContent->id;
            $varCopy->save();
        }
    }

    // ─── Sync Contents ──────────────────────────────────────────────────────

    /**
     * Sincroniza os conteúdos de um template (cria, atualiza, remove).
     */
    public function syncContents(ReportSetting $setting, array $contents): void
    {
        $keptIds = [];

        foreach ($contents as $row) {
            // Só conteúdo DESTE template: um id de outro template (de outra
            // clínica, inclusive) virava update no conteúdo alheio.
            $content = isset($row['id'])
                ? $setting->contents()->whereKey($row['id'])->first()
                : null;

            $data = [
                'type'  => $row['type'],
                'label' => $row['label'],
                // Sanitiza ANTES de persistir — nunca grava <script>/handler
                // de evento/javascript: cru. Modelo (não documento clínico
                // final) usa o mesmo profile 'medical' já usado em
                // MedicalRecordDocumentationsController/ExamManagerReport* —
                // placeholders {{VAR}} são texto puro, nunca afetados
                // (achado via auditoria de encerramento, EE-01). Linhas já
                // persistidas antes desta correção são protegidas de novo
                // na renderização (ver ReportSettingContent::sanitizedContent()).
                'content' => Purifier::clean($row['content'], 'medical'),
                'active'  => $row['active'] ?? true,
            ];

            if ($content) {
                // Edição manual do texto: o fingerprint atual passa a diferir
                // do `source_content_hash` (a versão do padrão de que veio),
                // e isUntouchedCopy()/pendingUpstreamUpdatesFor() já leem essa
                // diferença como "personalizada" — NÃO zerar o hash aqui
                // (2ª revisão Codex, P2-4): zerado, a próxima checagem perdia
                // a baseline e acusava "atualização disponível" na hora,
                // mesmo com o padrão global intocado. O "Manter o meu"
                // (dismissed_source_hash) FICA do mesmo jeito: é a versão do
                // padrão que a clínica recusou — o aviso só volta quando o
                // padrão mudar de novo, não a cada edição local.
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
