<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\ExamKitRequest;
use App\Models\ExamKit;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\ExamKitService;
use App\Support\TenantContext;
use Illuminate\Http\{JsonResponse, Request};
use Inertia\{Inertia, Response};

/**
 * Manager → Kits de exames PADRÃO do EasyEye (Fase 2 / B2).
 *
 * Mesmo padrão dos catálogos globais do manager (CID-10, medicamentos):
 * tela simples, só admin SaaS, exclusão com justificativa e trilha. Os kits
 * nascem do ExamKitsSeeder; cada clínica copia e ajusta os dela — a cópia não
 * muda quando o padrão muda. Sem route model binding (EntityScope fica inerte
 * no manager; o kit é sempre buscado entre os GLOBAIS).
 */
class ExamKitsController extends Controller
{
    public function __construct(
        private readonly ExamKitService $kits,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Panel/Manager/ExamKits/Index', [
            'kits'     => $this->tenant->withoutScope(fn () => $this->kits->globalKits()),
            'maxItems' => ExamKitService::MAX_ITEMS,
            't'        => trans('clinical_documents'),
        ]);
    }

    public function store(ExamKitRequest $request): JsonResponse
    {
        $kit = $this->kits->save(null, null, $request->validated());

        return response()->json([
            'message' => __('clinical_documents.kits.saved'),
            'data'    => $this->kits->serializeForSettings($kit),
        ], 201);
    }

    public function update(ExamKitRequest $request, string $kit): JsonResponse
    {
        $model = $this->kits->save($this->global($kit), null, $request->validated());

        return response()->json([
            'message' => __('clinical_documents.kits.saved'),
            'data'    => $this->kits->serializeForSettings($model),
        ]);
    }

    public function destroy(Request $request, string $kit): JsonResponse
    {
        $model = $this->global($kit);

        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $this->tenant->withoutScope(fn () => $model->delete());

        $this->audit->recordAdminAction(
            event: 'manager.exam_kits.destroy',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'exam_kit',
            auditableId: (string) $model->id,
            reason: trim((string) $request->input('reason')),
            newValues: $model->only(['name', 'slug']),
            request: $request,
        );

        return response()->json(['message' => __('clinical_documents.kits.deleted')]);
    }

    private function global(string $id): ExamKit
    {
        return ExamKit::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->global()
            ->findOrFail($id);
    }
}
