<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IntegratorCommandAckRequest;
use App\Http\Resources\Api\IntegratorCommandResource;
use App\Models\IntegratorCommand;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Canal de comando do backend pro desktop (Rust) — até aqui toda a API de
 * integradores era iniciada pelo cliente (signin, uploads, queue-health);
 * este é o único fluxo em que o SaaS pede pro desktop fazer algo
 * (`resync_now`, `run_diagnostics`, `reload_config` por ora — ver
 * `Manager\EntityIntegratorCommandsController` pro lado que enfileira),
 * via polling periódico (`service::maybe_poll_commands` no integrator).
 *
 * Modelo de entrega: at-least-once, execute-então-ack. Este banco É o
 * estado da fila — o cliente não mantém uma fila própria disso. Todo
 * handler de comando do lado Rust precisa ser idempotente por construção:
 * um crash entre "executou" e "confirmou o ack" faz o mesmo comando
 * reaparecer no próximo poll (ver docs/COMMANDS.md no integrator).
 */
class IntegratorCommandsController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $integrator = request()->attributes->get('integrator');

        $commands = IntegratorCommand::query()
            ->where('integrator_id', $integrator->id)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get();

        return IntegratorCommandResource::collection($commands);
    }

    public function ack(IntegratorCommandAckRequest $request, string $command): Response
    {
        $integrator = request()->attributes->get('integrator');

        $model = IntegratorCommand::query()
            ->where('integrator_id', $integrator->id)
            ->findOrFail($command);

        // Idempotente por si mesmo, independente do middleware `idempotency`
        // (TTL 24h, opt-in via header `Idempotency-Key`) — um integrador
        // offline por dias faz retry fora dessa janela e ainda assim não
        // pode reprocessar/sobrescrever um ack já gravado.
        if ($model->status !== 'pending') {
            return response()->noContent();
        }

        $model->update([
            'status'   => $request->validated('status'),
            'result'   => $request->validated('result'),
            'acked_at' => now(),
        ]);

        return response()->noContent();
    }
}
