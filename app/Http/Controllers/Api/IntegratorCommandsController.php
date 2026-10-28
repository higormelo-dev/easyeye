<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IntegratorCommandAckRequest;
use App\Http\Resources\Api\IntegratorCommandResource;
use App\Models\IntegratorCommand;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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

        IntegratorCommand::where('integrator_id', $integrator->id)->where('status', 'pending')->whereRaw(IntegratorCommand::deadlineSql() . ' <= ?', [now()->toIso8601String()])->update(['status' => 'expired', 'acked_at' => DB::raw(IntegratorCommand::deadlineSql())]);
        $commands = IntegratorCommand::query()
            ->where('integrator_id', $integrator->id)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(20)->get();

        return IntegratorCommandResource::collection($commands);
    }

    public function ack(IntegratorCommandAckRequest $request, string $command): Response
    {
        $integrator = request()->attributes->get('integrator');

        $model = IntegratorCommand::query()
            ->where('integrator_id', $integrator->id)
            ->findOrFail($command);

        $result = $request->validated('result');

        // Legacy free-text failures are accepted for compatibility but never persisted.
        if (is_array($result) && array_key_exists('error', $result)) {
            $result = ['error_code' => 'command_failed'];
        }
        $expired = IntegratorCommand::whereKey($model->id)->where('integrator_id', $integrator->id)->where('status', 'pending')->whereRaw(IntegratorCommand::deadlineSql() . ' <= ?', [now()->toIso8601String()])->update(['status' => 'expired', 'acked_at' => DB::raw(IntegratorCommand::deadlineSql())]);
        IntegratorCommand::whereKey($model->id)->where('integrator_id', $integrator->id)->where('status', 'pending')->update(['status' => $request->validated('status'), 'result' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR), 'acked_at' => now()->toIso8601String()]);

        return response()->noContent();
    }
}
