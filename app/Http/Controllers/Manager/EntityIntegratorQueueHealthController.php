<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator, IntegratorQueueHealth};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * "O que tá acontecendo agora" na fila local do integrador (Manager SaaS) —
 * dono do SaaS/suporte conferem pendentes/falhas/bloqueados/enviados de uma
 * clínica ANTES de precisar acessar a máquina remotamente.
 *
 * Read-only, e não é "ao vivo": é o último retrato que o próprio integrador
 * sincronizou (ver docs no repositório do integrator, módulo de sync de
 * queue-health) — a tela sempre mostra "sincronizado há Xmin" pra deixar
 * claro que não é uma consulta em tempo real à máquina da clínica.
 *
 * Rotas: /panel/manager/entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/queue-health
 */
class EntityIntegratorQueueHealthController extends Controller
{
    public function index(string $entityId, string $userIntegrator, string $integrator): InertiaResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);
        $integratorModel = EntityIntegrator::query()
            ->withTrashed()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integrator);

        $health = IntegratorQueueHealth::query()
            ->where('integrator_id', $integratorModel->id)
            ->first();

        return Inertia::render('Panel/Manager/EntityIntegratorQueueHealth/Index', [
            'entity' => [
                'id'   => $entity->id,
                'code' => $entity->code,
                'name' => $entity->name,
            ],
            'userIntegrator' => [
                'id'   => $userIntegratorModel->id,
                'code' => $userIntegratorModel->code,
                'name' => $userIntegratorModel->name,
            ],
            'integrator' => [
                'id'   => $integratorModel->id,
                'code' => $integratorModel->code,
                'name' => $integratorModel->name,
            ],
            'health' => $health === null ? null : [
                'pending_count'       => $health->pending_count,
                'failed_count'        => $health->failed_count,
                'blocked_count'       => $health->blocked_count,
                'sent_last_24h_count' => $health->sent_last_24h_count,
                'problems'            => $health->problems,
                'synced_at'           => $health->synced_at->toIso8601String(),
            ],
        ]);
    }
}
