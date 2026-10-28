<?php

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Services\AiUsdBrlRate;
use App\Enums\{ImportStatus, MedicinePosologySource, MedicineSource};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\{MedicinePosologyAiRequest, MedicineRequest};
use App\Models\{Medicine, MedicineImport, MedicinePosologyBatch, MedicinePosologyBatchGroup, MedicinePresentation};
use App\Services\Audit\AuditLogger;
use App\Services\Medicines\{MedicineCatalogFilters, MedicinePosologyAiException, MedicinePosologyAiService, MedicinePosologyBatchService};
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response};

/**
 * Catálogo GLOBAL de medicamentos (entity_id nulo) usado na busca do
 * receituário de todas as clínicas — manager do SaaS, admin only.
 *
 * Itens manuais: CRUD completo (nome, genérico, apresentação, posologia
 * sugerida). Itens da CMED/Anvisa: só a posologia sugerida é editável — os
 * dados cadastrais e o ativo/inativo vêm da importação (MedicineImportsController).
 *
 * Nunca usa route model binding: o EntityScope fica inerte no manager (sem
 * tenant.bind), então o binding acharia medicamento de CLÍNICA também.
 */
class MedicinesController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly MedicinePosologyAiService $posologyAi,
        private readonly MedicineCatalogFilters $catalogFilters,
        private readonly MedicinePosologyBatchService $posologyBatches,
        private readonly AiUsdBrlRate $usdBrl,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->catalogFilters->normalize($request->query());
        $query   = $this->catalogFilters->query($filters)->with('presentation:id,name');

        $runningImport = MedicineImport::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->latest()
            ->first();

        return Inertia::render('Panel/Manager/Medicines/Index', [
            'medicines' => $query->paginate(20)->withQueryString()->through(fn (Medicine $m) => $this->toRow($m)),
            'filters'   => $filters,
            'stats'     => fn () => $this->stats(),
            'imports'   => fn () => $this->recentImports(),
            // Barra de progresso (atualizada por WebSocket no canal do import).
            'runningImport' => $runningImport?->progressPayload(),
            'presentations' => fn () => MedicinePresentation::withoutGlobalScopes()
                ->whereNull('entity_id')
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                // MedicinePresentationsSeeder e OphthalmicMedicinesSeeder
                // gravaram a mesma apresentação com caixa diferente.
                ->unique(fn ($p) => mb_strtolower($p->name, 'UTF-8'))
                ->values(),
            // Aviso da verificação semanal (routes/console.php).
            'autoSync' => (bool) config('medicines.cmed.sync_enabled'),
            // Botão "Gerar com IA": IAs "Configuradas" no painel de provedores
            // (chave + modelo), Principal primeiro — com mais de uma, o admin escolhe.
            'aiProviders' => fn () => $this->posologyAi->providers(),
            // Lote "Gerar posologia com IA": em andamento (barra por WebSocket),
            // histórico e teto de chamadas por lote.
            'runningPosologyBatch' => fn () => $this->posologyBatches->running()?->progressPayload(),
            'posologyBatches'      => fn () => $this->recentPosologyBatches(),
            'posologyBatchCap'     => $this->posologyBatches->maxGroups(),
            't'                    => trans('manager_medicines'),
        ]);
    }

    public function store(MedicineRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->tenant->withoutScope(function () use ($data) {
            $medicine            = new Medicine([...$data, 'source' => MedicineSource::Manual]);
            $medicine->entity_id = null;
            $this->markPosologyReviewed($medicine);
            $medicine->save();
        });

        return back()->with('success', __('manager_medicines.saved'));
    }

    public function update(MedicineRequest $request, string $medicine): RedirectResponse
    {
        $model = $this->globalCatalog()->findOrFail($medicine);
        $data  = $request->validated();

        if ($model->source === MedicineSource::Cmed) {
            // Cadastro e status da CMED são controlados pela importação.
            abort_if(array_diff(array_keys($data), Medicine::POSOLOGY_FIELDS) !== [], 422, __('manager_medicines.cmed_only_posology'));
        }

        $this->tenant->withoutScope(function () use ($model, $data) {
            $model->fill($data);

            // Salvo pelo modal de edição (que sempre manda a posologia): o
            // admin revisou — a posologia gerada por IA vira manual.
            if (array_intersect(array_keys($data), Medicine::POSOLOGY_FIELDS) !== []) {
                $this->markPosologyReviewed($model);
            }

            $model->save();
        });

        return back()->with('success', __('manager_medicines.saved'));
    }

    /**
     * Posologia vista e salva pelo admin = manual e revisada (quem/quando).
     * Sem posologia: volta a "sem posologia". A data em que a IA gerou
     * (posology_ai_generated_at) fica como histórico.
     */
    private function markPosologyReviewed(Medicine $model): void
    {
        $has = $model->hasPosology();

        $model->forceFill([
            'posology_source'      => $has ? MedicinePosologySource::Manual : null,
            'posology_reviewed_at' => $has ? now() : null,
            'posology_reviewed_by' => $has ? auth()->id() : null,
        ]);
    }

    /**
     * Aprova a posologia gerada por IA como está (revisão em um clique):
     * mesmo efeito de salvar pelo modal — passa a "revisada" (origem manual,
     * quem/quando), sem tocar no texto. A data em que a IA gerou fica como
     * histórico. Só vale para posologia ainda pendente (outra pessoa pode ter
     * aprovado/editado nesse meio-tempo).
     */
    public function approvePosology(string $medicine): RedirectResponse
    {
        $model = $this->globalCatalog()->findOrFail($medicine);

        if (! $model->posologyPendingReview() || ! $model->hasPosology()) {
            throw ValidationException::withMessages(['posology' => __('manager_medicines.posology_approve_not_pending')]);
        }

        $model->update([
            'posology_source'      => MedicinePosologySource::Manual,
            'posology_reviewed_at' => now(),
            'posology_reviewed_by' => auth()->id(),
        ]);

        return back()->with('success', __('manager_medicines.posology_approved', ['name' => $model->name]));
    }

    public function destroy(Request $request, string $medicine): RedirectResponse
    {
        $model = $this->globalCatalog()->findOrFail($medicine);

        abort_if($model->source === MedicineSource::Cmed, 422, __('manager_medicines.cmed_cannot_delete'));

        // Mesma regra das demais exclusões do manager: justificativa + trilha.
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        // Soft delete: some da busca, mas posologias/favoritos dos médicos
        // (doctor_medication_presets, FK com cascade) continuam intactos.
        $this->tenant->withoutScope(fn () => $model->delete());

        $this->audit->recordAdminAction(
            event: 'manager.medicine.destroy',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'medicine',
            auditableId: (string) $model->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['name' => $model->name, 'concentration' => $model->concentration],
            request: $request,
        );

        return back()->with('success', __('manager_medicines.deleted'));
    }

    /**
     * Sugestão de posologia por IA para preencher o formulário — nada é
     * gravado no medicamento aqui (o admin revisa e salva).
     */
    public function aiPosology(MedicinePosologyAiRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! empty($data['medicine_id'])) {
            // Item salvo: dados do banco (inclui apresentação/classe da CMED), nunca os do cliente.
            $model    = $this->globalCatalog()->with('presentation:id,name')->findOrFail($data['medicine_id']);
            $medicine = $this->posologyAi->catalogContext($model);
        } else {
            $presentation = empty($data['medicine_presentation_id']) ? null : MedicinePresentation::withoutGlobalScopes()
                ->whereNull('entity_id')
                ->whereKey($data['medicine_presentation_id'])
                ->value('name');

            $medicine = [
                'nome'               => $data['name'],
                'principio_ativo'    => $data['active_ingredient'] ?? null,
                'concentracao'       => $data['concentration'] ?? null,
                'forma_farmaceutica' => $presentation,
                'uso_oftalmico'      => (bool) ($data['is_ophthalmic'] ?? false),
            ];
        }

        try {
            $suggestion = $this->posologyAi->suggest(
                $medicine,
                (string) session('selected_entity_id'),
                (string) auth()->id(),
                $data['provider'] ?? null,
            );
        } catch (MedicinePosologyAiException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['suggestion' => $suggestion]);
    }

    /**
     * Situação na lista de preços da CMED. Item da CMED inativo = fora da
     * lista atual (a importação desativa o que saiu da lista ou teve o
     * registro cancelado — o admin não desativa item da CMED). Curado: null.
     */
    private function cmedSituation(Medicine $m): ?string
    {
        if ($m->source !== MedicineSource::Cmed) {
            return null;
        }

        if (! $m->active) {
            return 'left_list';
        }

        return $m->is_marketed ? 'marketed' : 'not_marketed';
    }

    /** @return Builder<Medicine> */
    private function globalCatalog(): Builder
    {
        return $this->catalogFilters->globalCatalog();
    }

    /** @return array<string, mixed> */
    private function toRow(Medicine $m): array
    {
        return [
            'id'                       => $m->id,
            'name'                     => $m->name,
            'active_ingredient'        => $m->active_ingredient,
            'concentration'            => $m->concentration,
            'form'                     => $m->presentation?->name ?? $m->formLabel(),
            'medicine_presentation_id' => $m->medicine_presentation_id,
            'presentation_detail'      => $m->presentation_detail,
            'laboratory'               => $m->laboratory,
            'category'                 => $m->regulatory_category,
            'anvisa_registration'      => $m->anvisa_registration,
            'ean'                      => $m->ean,
            'therapeutic_class'        => $m->therapeutic_class,
            'synced_at'                => $m->source_synced_at?->isoFormat('L LT'),
            'source'                   => $m->source->value,
            'source_label'             => $m->source->label(),
            'is_ophthalmic'            => (bool) $m->is_ophthalmic,
            'is_marketed'              => (bool) $m->is_marketed,
            'cmed_situation'           => $this->cmedSituation($m),
            'active'                   => (bool) $m->active,
            'dosage'                   => $m->dosage,
            'frequency'                => $m->frequency,
            'duration'                 => $m->duration,
            'instructions'             => $m->instructions,
            // Selo "IA – revisar": gerada em lote, ainda não revisada no modal.
            'posology_source'          => $m->posology_source?->value,
            'posology_pending_review'  => $m->posologyPendingReview(),
            'posology_ai_generated_at' => $m->posology_ai_generated_at?->isoFormat('L LT'),
            'posology_reviewed_at'     => $m->posology_reviewed_at?->isoFormat('L LT'),
        ];
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        $active = $this->globalCatalog()->where('active', true);

        return [
            // Posologias geradas por IA aguardando revisão (atalho "Revisar agora").
            'ai_pending' => $this->globalCatalog()->where('posology_source', MedicinePosologySource::Ai->value)->count(),
            'active'     => (clone $active)->count(),
            'cmed'       => (clone $active)->where('source', MedicineSource::Cmed->value)->count(),
            'manual'     => (clone $active)->where('source', MedicineSource::Manual->value)->count(),
            'ophthalmic' => (clone $active)->where('is_ophthalmic', true)->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentImports(): array
    {
        return MedicineImport::query()
            ->with('user:id,name')
            ->latest()
            ->limit(10)
            ->get()
            // Mesmo formato da barra de progresso (progressPayload) + metadados
            // do histórico — a tela acha aqui o resultado final de uma
            // importação que terminou antes do WebSocket conectar.
            ->map(fn (MedicineImport $i) => [
                ...$i->progressPayload(),
                'user'                    => $i->user?->name,
                'open_data_original_name' => $i->open_data_original_name,
                'created_at'              => $i->created_at?->isoFormat('L LT'),
                'finished_at'             => $i->finished_at?->isoFormat('L LT'),
            ])
            ->all();
    }

    /**
     * Histórico dos lotes de posologia por IA: quem, quando, filtros, IA,
     * grupos, itens atualizados, falhas e custo real (US$ e R$).
     *
     * @return list<array<string, mixed>>
     */
    private function recentPosologyBatches(): array
    {
        $rate = $this->usdBrl->current();

        return MedicinePosologyBatch::query()
            ->with([
                'user:id,name',
                'groups' => fn ($q) => $q->where('status', MedicinePosologyBatchGroup::STATUS_FAILED)->orderBy('position'),
            ])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (MedicinePosologyBatch $b) => [
                ...$b->progressPayload(),
                'user'               => $b->user?->name,
                'filters_summary'    => $this->filtersSummary((array) $b->filters),
                'estimated_cost_usd' => $b->estimated_cost_usd,
                'cost_brl'           => round((float) $b->cost_usd * $rate['rate'], 4),
                'failures'           => $b->groups->take(20)->map(fn (MedicinePosologyBatchGroup $g) => [
                    'label' => $g->label,
                    'error' => $g->error,
                ])->values()->all(),
                'created_at'  => $b->created_at?->isoFormat('L LT'),
                'finished_at' => $b->finished_at?->isoFormat('L LT'),
            ])
            ->all();
    }

    /**
     * Filtros do lote em texto (histórico).
     *
     * @param array<string, mixed> $filters
     *
     * @return list<string>
     */
    private function filtersSummary(array $filters): array
    {
        $parts = [];

        if (($filters['search'] ?? '') !== '') {
            $parts[] = __('manager_medicines.batch_filter_search', ['term' => $filters['search']]);
        }

        if (($filters['source'] ?? '') !== '') {
            $parts[] = __('manager_medicines.source_' . $filters['source']);
        }

        if (($filters['cmed_situation'] ?? '') !== '') {
            $parts[] = __('manager_medicines.' . match ($filters['cmed_situation']) {
                'marketed'     => 'cmed_marketed',
                'not_marketed' => 'not_marketed',
                default        => 'cmed_left_list',
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $parts[] = __('manager_medicines.status_' . $filters['status']);
        }

        if (($filters['posology'] ?? '') !== '') {
            $parts[] = __('manager_medicines.posology_filter_' . $filters['posology']);
        }

        if (! empty($filters['ophthalmic'])) {
            $parts[] = __('manager_medicines.filter_ophthalmic');
        }

        return $parts;
    }
}
