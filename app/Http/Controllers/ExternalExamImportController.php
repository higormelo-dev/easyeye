<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EntityGate;
use App\Http\Requests\ImportExternalExamRequest;
use App\Models\Entity;
use App\Services\ExternalExamImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * "Importar exame externo" (Gerenciador de Imagens): upload manual de exame
 * sem passar pelo integrador. Segue o mesmo par request/redirect de
 * PatientImportsController::store() — o form do front usa useForm().post()
 * com forceFormData: true, então a resposta precisa ser Inertia-friendly
 * (redirect), não JSON puro (quebraria o useForm).
 */
class ExternalExamImportController extends Controller
{
    public function __construct(
        private readonly ExternalExamImportService $importService,
    ) {
    }

    public function store(ImportExternalExamRequest $request): RedirectResponse
    {
        $entityId = (string) session('selected_entity_id');

        Gate::authorize(EntityGate::ImportExternalExam->value, Entity::findOrFail($entityId));

        $validated = $request->validated();

        $this->importService->import(
            entityId: $entityId,
            data: $validated,
            files: $request->file('files', []),
            userId: (string) auth()->id(),
        );

        // Middleware HandleInertiaRequests só compartilha os flashes
        // 'success'/'error' (não 'message') no shared prop `flash` consumido
        // por AppLayout.vue — mesmo padrão de alerta usado no resto do painel.
        return redirect()
            ->route('panel.eye-images.index')
            ->with('success', __('eye_images.import.success'));
    }
}
