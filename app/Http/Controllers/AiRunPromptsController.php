<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\AI\Models\AiDoctorPrompt;
use App\Domains\AI\Services\AiDoctorPromptService;
use App\Enums\FeatureKey;
use App\Services\FeatureGateService;
use DomainException;
use Illuminate\Http\{JsonResponse, Request};

/**
 * Prompts favoritos do médico para o painel do Assistente de IA (Onda 3, P1).
 * Extraído de AiRunsController — mesmas rotas (panel.ai-runs.my-prompts.*).
 */
class AiRunPromptsController extends Controller
{
    public function __construct(
        private readonly AiDoctorPromptService $promptService,
        private readonly FeatureGateService $featureGate,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertAiFeatureEnabled($entityId);
        $doctor = $this->promptService->resolveDoctor($entityId, (string) auth()->id());

        if (! $doctor) {
            return response()->json(['data' => []]);
        }

        $prompts = $this->promptService->listForDoctor((string) $doctor->id, $entityId);

        return response()->json([
            'data' => $prompts->map(fn (AiDoctorPrompt $p): array => [
                'id'     => (string) $p->id,
                'label'  => (string) $p->label,
                'prompt' => (string) $p->prompt,
            ])->values()->all(),
            'limit' => AiDoctorPromptService::MAX_PROMPTS_PER_DOCTOR,
        ]);
    }

    /**
     * Cria um prompt inline a partir do painel. Aceita label opcional — quando
     * ausente, gera "Meu prompt N" automaticamente.
     */
    public function store(Request $request): JsonResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertAiFeatureEnabled($entityId);
        $doctor = $this->promptService->resolveDoctor($entityId, (string) auth()->id());
        abort_if(! $doctor, 403);

        $validated = $request->validate([
            'label'  => ['nullable', 'string', 'max:120'],
            'prompt' => ['required', 'string', 'min:12', 'max:30000'],
        ]);

        $label = trim((string) ($validated['label'] ?? ''));

        if ($label === '') {
            $next  = $this->promptService->listForDoctor((string) $doctor->id, $entityId)->count() + 1;
            $label = __('ai.prompts.default_label', ['n' => $next]);
        }

        try {
            $prompt = $this->promptService->create((string) $doctor->id, $entityId, $label, $validated['prompt']);
        } catch (DomainException) {
            return response()->json(['message' => __('ai.prompts.limit_reached')], 422);
        }

        return response()->json([
            'id'     => (string) $prompt->id,
            'label'  => (string) $prompt->label,
            'prompt' => (string) $prompt->prompt,
        ], 201);
    }

    public function destroy(Request $request, AiDoctorPrompt $aiPrompt): JsonResponse
    {
        $entityId = $this->selectedEntityId();
        $this->assertAiFeatureEnabled($entityId);
        $doctor = $this->promptService->resolveDoctor($entityId, (string) auth()->id());
        abort_if(! $doctor, 403);
        abort_if((string) $aiPrompt->entity_id !== $entityId, 403);
        abort_if((string) $aiPrompt->doctor_id !== (string) $doctor->id, 403);

        $this->promptService->destroy($aiPrompt);

        return response()->json(['status' => 'deleted']);
    }

    private function selectedEntityId(): string
    {
        return (string) session('selected_entity_id');
    }

    private function assertAiFeatureEnabled(string $entityId): void
    {
        $hasExamAssistant  = $this->featureGate->can($entityId, FeatureKey::HasAiExamAssistant);
        $hasReportDrafting = $this->featureGate->can($entityId, FeatureKey::HasAiReportDrafting);
        $hasEyeImage       = $this->featureGate->can($entityId, FeatureKey::HasAiEyeImageAnalysis);

        if (! $hasExamAssistant && ! $hasReportDrafting && ! $hasEyeImage) {
            abort(403, __('ai.feature_unavailable'));
        }
    }
}
