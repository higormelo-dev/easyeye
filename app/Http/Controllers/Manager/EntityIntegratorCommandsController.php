<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator, IntegratorCommand};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response};

/**
 * Enfileira um comando pro desktop (Rust) buscar no próximo poll — infra
 * mínima (JSON, sem tela Inertia ainda) pro suporte/dono do SaaS disparar
 * "resync agora"/diagnóstico numa clínica sem precisar acessar a máquina
 * remotamente. Ver `App\Http\Controllers\Api\IntegratorCommandsController`
 * pro lado que o desktop consome (polling + ack).
 *
 * Rota: POST /panel/manager/entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/commands
 */
class EntityIntegratorCommandsController extends Controller
{
    /**
     * Tipos aceitos hoje — sem `CHECK` no banco (ver doc da migration),
     * então esta lista é só validação de entrada, não um limite estrutural.
     */
    public function index(Request $request, string $entityId, string $userIntegrator, string $integrator): Response
    {
        $user   = EntityUserIntegrator::where('entity_id', $entityId)->findOrFail($userIntegrator);
        $device = EntityIntegrator::where('entity_user_integrator_id', $user->id)->findOrFail($integrator);
        IntegratorCommand::where('integrator_id', $device->id)->where('status', 'pending')->whereRaw(IntegratorCommand::deadlineSql() . ' <= ?', [now()->toIso8601String()])->update(['status' => 'expired', 'acked_at' => DB::raw(IntegratorCommand::deadlineSql())]);

        return Inertia::render('Panel/Manager/IntegratorCommands/Index', ['entityId' => $entityId, 'userIntegrator' => $userIntegrator, 'integrator' => ['id' => $device->id, 'name' => $device->name], 'commands' => IntegratorCommand::where('integrator_id', $device->id)->latest()->limit(50)->get(['id', 'type', 'status', 'result', 'created_at', 'expires_at', 'acked_at']), 'allowedTypes' => self::VALID_TYPES]);
    }

    private const VALID_TYPES = ['resync_now', 'run_diagnostics', 'reload_config'];

    public function store(Request $request, string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);
        $integratorModel = EntityIntegrator::query()
            ->withTrashed()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integrator);

        $validated = $request->validate([
            'type'            => ['required', 'string', Rule::in(self::VALID_TYPES)],
            'timeout_minutes' => ['nullable', 'integer', 'between:1,60'],
        ]);

        abort_unless($integratorModel->active, 422, 'Integrador inativo.');
        $command = DB::transaction(function () use ($integratorModel, $validated, $request) {
            EntityIntegrator::whereKey($integratorModel->id)->lockForUpdate()->firstOrFail();
            abort_if(IntegratorCommand::where('integrator_id', $integratorModel->id)->where('status', 'pending')->where('created_at', '>', now()->subHour())->count() >= 20, 429, 'Limite de comandos pendentes.');
            $command = IntegratorCommand::create([
                'expires_at'    => now()->addMinutes($validated['timeout_minutes'] ?? 15), 'requested_by' => $request->user()->id,
                'integrator_id' => $integratorModel->id,
                'type'          => $validated['type'],
                'payload'       => [],
                'status'        => 'pending',
            ]);

            return $command;
        });

        return response()->json(['id' => $command->id, 'type' => $command->type], 201);
    }
}
