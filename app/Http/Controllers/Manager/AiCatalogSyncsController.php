<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Models\AiCatalogSync;
use App\Enums\{EntityGate, ImportStatus};
use App\Http\Controllers\Controller;
use App\Jobs\SyncAiModelCatalogJob;
use App\Models\Entity;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;

/**
 * "Sincronizar agora" do catálogo de modelos/preços de IA (Manager →
 * Provedores de IA). Roda em fila (SyncAiModelCatalogJob); o progresso chega
 * à tela por WebSocket (ImportProgressUpdated) — sem endpoint de status.
 */
class AiCatalogSyncsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->authorizeSaas();

        // Uma por vez: duas sincronizações disputariam as mesmas linhas.
        $running = AiCatalogSync::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->exists();

        if ($running) {
            return response()->json(['message' => __('manager_ai.sync_in_progress')], 422);
        }

        $sync = AiCatalogSync::query()->create([
            'user_id' => $request->user()->id,
            'source'  => AiCatalogSync::SOURCE_MANUAL,
            'status'  => ImportStatus::Pending,
        ]);

        SyncAiModelCatalogJob::dispatch($sync);

        return response()->json([
            'message' => __('manager_ai.sync_queued'),
            'sync'    => $sync->fresh()->progressPayload(),
        ]);
    }

    /**
     * Cancela uma sincronização PARADA (na fila sem começar — worker fora do
     * ar — ou sem progresso além do timeout do job). Em andamento: recusa.
     */
    public function cancel(string $sync): JsonResponse
    {
        $this->authorizeSaas();

        $model = AiCatalogSync::query()->findOrFail($sync);

        if (! $model->isStalled()) {
            return response()->json(['message' => __('manager_ai.sync_not_stalled')], 422);
        }

        $model->update([
            'status'      => ImportStatus::Cancelled,
            'phase'       => null,
            'error'       => __('manager_ai.sync_cancelled_reason'),
            'finished_at' => now(),
        ]);

        return response()->json([
            'message' => __('manager_ai.sync_cancelled'),
            'sync'    => $model->progressPayload(),
        ]);
    }

    private function authorizeSaas(): void
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::SaasAdminPanel->value, $entity);
    }
}
