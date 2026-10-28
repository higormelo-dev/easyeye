<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Domains\AI\Models\AiDoctorPrompt;
use App\Domains\AI\Services\AiDoctorPromptService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * CRUD dos prompts favoritos do médico (Onda 3, P1). Acessível apenas a
 * usuários com perfil Doctor ativo na entity selecionada.
 */
class AiDoctorPromptsController extends Controller
{
    public function __construct(
        private readonly AiDoctorPromptService $service,
    ) {
    }

    public function index(): InertiaResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $doctor   = $this->service->resolveDoctor($entityId, (string) auth()->id());

        if (! $doctor) {
            return redirect()->route('panel.dashboard')->with('error', __('ai.prompts.doctor_required'));
        }

        $prompts = $this->service->listForDoctor((string) $doctor->id, $entityId);

        return Inertia::render('Panel/Setting/AiDoctorPrompts/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.settings'), 'url' => '#', 'active' => false],
                ['label' => __('ai.prompts.page_title'), 'url' => '#', 'active' => true],
            ],
            'prompts' => $prompts->map(fn (AiDoctorPrompt $p): array => [
                'id'       => (string) $p->id,
                'label'    => (string) $p->label,
                'prompt'   => (string) $p->prompt,
                'position' => (int) $p->position,
            ])->values()->all(),
            'limit'  => AiDoctorPromptService::MAX_PROMPTS_PER_DOCTOR,
            'labels' => [
                'page_title'     => __('ai.prompts.page_title'),
                'page_subtitle'  => __('ai.prompts.page_subtitle'),
                'create'         => __('ai.prompts.create'),
                'edit'           => __('ai.prompts.edit'),
                'delete'         => __('ai.prompts.delete'),
                'limit_reached'  => __('ai.prompts.limit_reached'),
                'label'          => __('ai.prompts.label'),
                'prompt'         => __('ai.prompts.prompt'),
                'save'           => __('actions.save'),
                'cancel'         => __('actions.cancel'),
                'confirm_delete' => __('ai.prompts.confirm_delete'),
                'move_up'        => __('ai.prompts.move_up'),
                'move_down'      => __('ai.prompts.move_down'),
                'empty'          => __('ai.prompts.empty'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $doctor   = $this->service->resolveDoctor($entityId, (string) auth()->id());
        abort_if(! $doctor, 403);

        $validated = $request->validate([
            'label'  => ['required', 'string', 'max:120'],
            'prompt' => ['required', 'string', 'min:12', 'max:30000'],
        ]);

        try {
            $prompt = $this->service->create((string) $doctor->id, $entityId, $validated['label'], $validated['prompt']);
        } catch (DomainException) {
            return $this->jsonOrBack($request, 422, ['message' => __('ai.prompts.limit_reached')]);
        }

        return $this->jsonOrBack($request, 201, ['id' => (string) $prompt->id]);
    }

    public function update(Request $request, AiDoctorPrompt $aiPrompt): JsonResponse|RedirectResponse
    {
        $this->assertOwnership($aiPrompt);

        $validated = $request->validate([
            'label'  => ['required', 'string', 'max:120'],
            'prompt' => ['required', 'string', 'min:12', 'max:30000'],
        ]);

        $this->service->update($aiPrompt, $validated['label'], $validated['prompt']);

        return $this->jsonOrBack($request, 200);
    }

    public function destroy(Request $request, AiDoctorPrompt $aiPrompt): JsonResponse|RedirectResponse
    {
        $this->assertOwnership($aiPrompt);

        $this->service->destroy($aiPrompt);

        return $this->jsonOrBack($request, 200);
    }

    public function reorder(Request $request): JsonResponse|RedirectResponse
    {
        $entityId = $this->selectedEntityId();
        $doctor   = $this->service->resolveDoctor($entityId, (string) auth()->id());
        abort_if(! $doctor, 403);

        $validated = $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['uuid'],
        ]);

        $this->service->reorder((string) $doctor->id, $validated['ids']);

        return $this->jsonOrBack($request, 200);
    }

    private function selectedEntityId(): string
    {
        return (string) session('selected_entity_id');
    }

    private function assertOwnership(AiDoctorPrompt $prompt): void
    {
        $entityId = $this->selectedEntityId();
        abort_if((string) $prompt->entity_id !== $entityId, 403);

        $doctor = $this->service->resolveDoctor($entityId, (string) auth()->id());
        abort_if(! $doctor || (string) $prompt->doctor_id !== (string) $doctor->id, 403);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonOrBack(Request $request, int $status, array $data = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($data, $status);
        }

        return back()->with($status >= 400 ? 'error' : 'success', $data['message'] ?? '');
    }
}
