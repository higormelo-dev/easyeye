<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\ExamKitRequest;
use App\Models\{Covenant, ExamKit};
use App\Services\Documents\ExamKitService;
use Illuminate\Http\JsonResponse;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Configurações › Kits de exames (Fase 2 / B2) — kits da clínica: criar,
 * editar, excluir, copiar do padrão do EasyEye e vincular a convênios
 * (nenhum = todos; só convênios ativos em vínculo novo).
 *
 * Rotas no grupo `permission:settings.manage` (admin; permissão customizada
 * também libera), como os Modelos de documento. Sem route model binding:
 * o kit é sempre buscado DESTA clínica (outra clínica ou global → 404).
 */
class ExamKitsController extends Controller
{
    public function __construct(private readonly ExamKitService $kits)
    {
    }

    public function index(): InertiaResponse
    {
        $entityId = $this->entityId();

        return Inertia::render('Panel/Settings/ExamKits/Index', [
            'clinicKits' => $this->kits->clinicKits($entityId),
            'globalKits' => $this->kits->globalKits($entityId),
            // Convênios da clínica + globais ativos (os inativos já vinculados
            // chegam junto do kit e continuam aparecendo).
            'covenants' => Covenant::query()
                ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Covenant $c) => ['id' => (string) $c->id, 'name' => $c->name])
                ->values()
                ->all(),
            'maxItems' => ExamKitService::MAX_ITEMS,
            't'        => trans('clinical_documents'),
        ]);
    }

    public function store(ExamKitRequest $request): JsonResponse
    {
        $kit = $this->kits->save(null, $this->entityId(), $request->validated());

        return response()->json([
            'message' => __('clinical_documents.kits.saved'),
            'data'    => $this->kits->serializeForSettings($kit),
        ], 201);
    }

    public function update(ExamKitRequest $request, string $kit): JsonResponse
    {
        $model = $this->kits->save($this->owned($kit), $this->entityId(), $request->validated());

        return response()->json([
            'message' => __('clinical_documents.kits.saved'),
            'data'    => $this->kits->serializeForSettings($model),
        ]);
    }

    public function destroy(string $kit): JsonResponse
    {
        $this->owned($kit)->delete();

        return response()->json(['message' => __('clinical_documents.kits.deleted')]);
    }

    /** Copia um kit padrão do EasyEye para a clínica (a cópia é dela). */
    public function copy(string $kit): JsonResponse
    {
        $global = ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->global()
            ->active()
            ->with('items')
            ->findOrFail($kit);

        $copy = $this->kits->copyToEntity($global, $this->entityId());

        return response()->json([
            'message' => __('clinical_documents.kits.copied'),
            'data'    => $this->kits->serializeForSettings($copy),
        ], 201);
    }

    private function owned(string $id): ExamKit
    {
        return ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->ownedBy($this->entityId())
            ->findOrFail($id);
    }

    private function entityId(): string
    {
        return (string) session('selected_entity_id');
    }
}
