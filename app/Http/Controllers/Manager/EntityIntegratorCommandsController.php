<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator, IntegratorCommand};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

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
            'type' => ['required', 'string', Rule::in(self::VALID_TYPES)],
        ]);

        $command = IntegratorCommand::create([
            'integrator_id' => $integratorModel->id,
            'type'          => $validated['type'],
            'payload'       => [],
            'status'        => 'pending',
        ]);

        return response()->json(['id' => $command->id, 'type' => $command->type], 201);
    }
}
