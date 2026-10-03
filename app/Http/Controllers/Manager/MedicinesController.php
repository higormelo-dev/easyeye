<?php

namespace App\Http\Controllers\Manager;

use App\Enums\{ImportStatus, MedicineSource};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\MedicineRequest;
use App\Models\{Medicine, MedicineImport, MedicinePresentation};
use App\Services\Medicines\MedicineCatalogSearch;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{RedirectResponse, Request};
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
    private const POSOLOGY_FIELDS = ['dosage', 'frequency', 'duration', 'instructions'];

    public function __construct(
        private readonly MedicineCatalogSearch $catalogSearch,
        private readonly TenantContext $tenant,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search'     => $request->string('search')->trim()->value(),
            'source'     => in_array($request->input('source'), ['manual', 'cmed'], true) ? $request->input('source') : '',
            'status'     => in_array($request->input('status'), ['active', 'inactive'], true) ? $request->input('status') : '',
            'ophthalmic' => $request->boolean('ophthalmic'),
        ];

        $query = $this->globalCatalog()->with('presentation:id,name');

        if ($filters['search'] !== '') {
            $this->catalogSearch->apply($query, $filters['search']);
        }

        $query->when($filters['source'], fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('active', false))
            ->when($filters['ophthalmic'], fn (Builder $q) => $q->where('is_ophthalmic', true))
            ->orderBy('name')
            ->orderBy('concentration');

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
            't' => trans('manager_medicines'),
        ]);
    }

    public function store(MedicineRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->tenant->withoutScope(function () use ($data) {
            $medicine            = new Medicine([...$data, 'source' => MedicineSource::Manual]);
            $medicine->entity_id = null;
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
            abort_if(array_diff(array_keys($data), self::POSOLOGY_FIELDS) !== [], 422, __('manager_medicines.cmed_only_posology'));
        }

        $this->tenant->withoutScope(fn () => $model->update($data));

        return back()->with('success', __('manager_medicines.saved'));
    }

    public function destroy(string $medicine): RedirectResponse
    {
        $model = $this->globalCatalog()->findOrFail($medicine);

        abort_if($model->source === MedicineSource::Cmed, 422, __('manager_medicines.cmed_cannot_delete'));

        // Soft delete: some da busca, mas posologias/favoritos dos médicos
        // (doctor_medication_presets, FK com cascade) continuam intactos.
        $this->tenant->withoutScope(fn () => $model->delete());

        return back()->with('success', __('manager_medicines.deleted'));
    }

    /** @return Builder<Medicine> */
    private function globalCatalog(): Builder
    {
        return Medicine::withoutGlobalScopes()->whereNull('medicines.entity_id')->whereNull('medicines.deleted_at');
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
            'source'                   => $m->source->value,
            'source_label'             => $m->source->label(),
            'is_ophthalmic'            => (bool) $m->is_ophthalmic,
            'is_marketed'              => (bool) $m->is_marketed,
            'active'                   => (bool) $m->active,
            'dosage'                   => $m->dosage,
            'frequency'                => $m->frequency,
            'duration'                 => $m->duration,
            'instructions'             => $m->instructions,
        ];
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        $active = $this->globalCatalog()->where('active', true);

        return [
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
}
