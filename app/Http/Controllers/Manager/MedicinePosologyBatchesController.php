<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\MedicinePosologyBatchRequest;
use App\Jobs\ProcessMedicinePosologyBatchJob;
use App\Models\MedicinePosologyBatch;
use App\Services\Audit\AuditLogger;
use App\Services\Medicines\{MedicineCatalogFilters, MedicinePosologyAiException, MedicinePosologyBatchException, MedicinePosologyBatchService};
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Validation\ValidationException;

/**
 * "Gerar posologia com IA (lote)" — Manager → Medicamentos, admin only.
 * Prévia (contagens, grupos = chamadas, custo estimado, teto) → início em
 * fila → progresso por WebSocket (sem endpoint HTTP de status) → histórico.
 */
class MedicinePosologyBatchesController extends Controller
{
    public function __construct(
        private readonly MedicinePosologyBatchService $batches,
        private readonly MedicineCatalogFilters $filters,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Prévia do modal de confirmação (nada é gravado nem enviado à IA). */
    public function preview(MedicinePosologyBatchRequest $request): JsonResponse
    {
        $filters = $this->filters->normalize($request->validated());

        return response()->json([
            ...$this->batches->preview($filters),
            // Já há um lote rodando: a tela mostra o progresso em vez de abrir outro.
            'running' => $this->batches->running()?->progressPayload(),
        ]);
    }

    public function store(MedicinePosologyBatchRequest $request): RedirectResponse
    {
        $data    = $request->validated();
        $filters = $this->filters->normalize($data);

        try {
            $batch = $this->batches->start($filters, $data['provider'] ?? null, $request->user(), (string) session('selected_entity_id'));
        } catch (MedicinePosologyAiException $e) {
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        } catch (MedicinePosologyBatchException $e) {
            throw ValidationException::withMessages(['batch' => $e->getMessage()]);
        }

        // Trilha: quem disparou, com quais filtros/IA e o alcance confirmado.
        $this->audit->recordAdminAction(
            event: 'manager.medicine.posology_batch.start',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'medicine_posology_batch',
            auditableId: (string) $batch->id,
            reason: __('manager_medicines.batch_audit_reason'),
            newValues: [
                'filters'            => $filters,
                'provider'           => $batch->provider,
                'total_groups'       => $batch->total_groups,
                'total_medicines'    => $batch->total_medicines,
                'remaining_groups'   => $batch->remaining_groups,
                'estimated_cost_usd' => $batch->estimated_cost_usd,
            ],
            request: $request,
        );

        ProcessMedicinePosologyBatchJob::dispatch($batch);

        return back()->with('success', __('manager_medicines.batch_queued'));
    }

    public function cancel(Request $request, string $batch): RedirectResponse
    {
        $model = MedicinePosologyBatch::query()->findOrFail($batch);

        if (! $model->isRunning()) {
            throw ValidationException::withMessages(['batch' => __('manager_medicines.batch_not_running')]);
        }

        $this->batches->cancel($model);

        $this->audit->recordAdminAction(
            event: 'manager.medicine.posology_batch.cancel',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'medicine_posology_batch',
            auditableId: (string) $model->id,
            reason: __('manager_medicines.batch_cancelled_reason'),
            newValues: ['processed_groups' => $model->processed_groups, 'updated_count' => $model->updated_count],
            request: $request,
        );

        return back()->with('success', __('manager_medicines.batch_cancelled'));
    }
}
