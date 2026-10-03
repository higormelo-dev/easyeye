<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivationStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IntegratorQueueHealthRequest;
use App\Models\{IntegratorQueueHealth, IntegratorQueueHealthHistory};
use App\Services\{ActivationService, IntegratorHealthMinimizer};
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Sincronização periódica da fila local do integrador (pendentes/falhas/
 * bloqueados/enviados) — dono do SaaS ou suporte conferem "o que tá
 * acontecendo agora" (e a tendência recente) numa clínica antes de precisar
 * acessar a máquina remotamente.
 *
 * Escreve em DOIS lugares por sincronização, cada um com um raciocínio de
 * escala diferente (ver doc comment de cada migration):
 * - `integrator_queue_health`: upsert, uma linha por integrador para
 *   sempre — "o retrato de agora".
 * - `integrator_queue_health_history`: INSERT, uma linha por sincronização,
 *   expurgada após 7 dias por `queue-health:prune-history` — "o log/
 *   tendência dos últimos dias" que o retrato sozinho não guarda.
 */
class IntegratorQueueHealthController extends Controller
{
    public function store(IntegratorQueueHealthRequest $request): Response
    {
        $integrator = request()->attributes->get('integrator');
        $synced_at  = now();
        $counts     = $request->only([
            'pending_count',
            'failed_count',
            'blocked_count',
            'sent_last_24h_count',
        ]);

        DB::transaction(function () use ($request, $integrator, $synced_at, $counts): void {
            IntegratorQueueHealth::updateOrCreate(
                ['integrator_id' => $integrator->id],
                [
                    ...$counts,
                    'problems'    => app(IntegratorHealthMinimizer::class)->problems($request->validated('problems')),
                    'operational' => $request->validated('operational'),
                    'synced_at'   => $synced_at,
                ],
            );

            IntegratorQueueHealthHistory::create([
                'integrator_id' => $integrator->id,
                ...$counts,
                'synced_at' => $synced_at,
            ]);
        });

        if (collect($request->validated('operational.devices') ?? [])->contains(fn ($device) => ! empty($device['last_accepted_at']))) {
            app(ActivationService::class)->complete($integrator->user->entity_id, ActivationStep::IntegratorCaptureObserved);
        }

        return response()->noContent();
    }
}
