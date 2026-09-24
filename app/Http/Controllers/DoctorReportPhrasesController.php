<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DoctorReportPhrase;
use App\Services\{ConsultationRecordResolver, DoctorReportPhraseService};
use DomainException;
use Illuminate\Http\{JsonResponse, Request};

/**
 * CRUD das frases rápidas do médico pro laudo do Gerenciador de Imagens
 * (benchmark 18/09/2026 — adaptação do "Wizard" do concorrente). Só JSON —
 * consumido inline de dentro de EyeImageReportModal.vue, sem tela própria
 * de configurações (v1).
 *
 * Sempre filtrado pelo médico autenticado: doctor_id NUNCA vem do payload,
 * só do usuário logado (mesmo padrão de AiDoctorPromptsController) — um
 * médico nunca lê/edita/apaga frase de outro médico, mesmo da mesma clínica.
 */
class DoctorReportPhrasesController extends Controller
{
    public function __construct(
        private readonly DoctorReportPhraseService $service,
        private readonly ConsultationRecordResolver $recordResolver,
    ) {
    }

    public function index(): JsonResponse
    {
        $entityId = $this->selectedEntityId();
        $doctorId = $this->recordResolver->resolveDoctorIdForCurrentUser($entityId);
        abort_if(! $doctorId, 403);

        $phrases = $this->service->listForDoctor($doctorId, $entityId);

        return response()->json([
            'data' => $phrases->map(fn (DoctorReportPhrase $p): array => [
                'id'      => (string) $p->id,
                'label'   => $p->label,
                'content' => $p->content,
            ])->values()->all(),
            'limit' => DoctorReportPhraseService::MAX_PHRASES_PER_DOCTOR,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entityId = $this->selectedEntityId();
        $doctorId = $this->recordResolver->resolveDoctorIdForCurrentUser($entityId);
        abort_if(! $doctorId, 403);

        $validated = $request->validate([
            'label'   => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $phrase = $this->service->create($doctorId, $entityId, $validated['label'], $validated['content']);
        } catch (DomainException) {
            return response()->json(['message' => __('eye_images.phrase_limit_reached')], 422);
        }

        return response()->json(['id' => (string) $phrase->id, 'label' => $phrase->label, 'content' => $phrase->content], 201);
    }

    public function update(Request $request, DoctorReportPhrase $phrase): JsonResponse
    {
        $this->assertOwnership($phrase);

        $validated = $request->validate([
            'label'   => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $phrase = $this->service->update($phrase, $validated['label'], $validated['content']);

        return response()->json(['id' => (string) $phrase->id, 'label' => $phrase->label, 'content' => $phrase->content]);
    }

    public function destroy(DoctorReportPhrase $phrase): JsonResponse
    {
        $this->assertOwnership($phrase);

        $this->service->destroy($phrase);

        return response()->json(['deleted' => true]);
    }

    private function selectedEntityId(): string
    {
        return (string) session('selected_entity_id');
    }

    // 403 pra tudo (nunca 404): não é IDOR de posse de tenant só — é
    // "outro médico da MESMA clínica também não pode", então 404 (que aqui
    // sugeriria "não existe") seria menos preciso que 403 (existe, mas não
    // é seu). Mesmo raciocínio de EyeImageExamActionsController.
    private function assertOwnership(DoctorReportPhrase $phrase): void
    {
        $entityId = $this->selectedEntityId();
        abort_if((string) $phrase->entity_id !== $entityId, 403);

        $doctorId = $this->recordResolver->resolveDoctorIdForCurrentUser($entityId);
        abort_if(! $doctorId || (string) $phrase->doctor_id !== $doctorId, 403);
    }
}
