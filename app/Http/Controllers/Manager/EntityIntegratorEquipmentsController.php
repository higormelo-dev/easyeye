<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\{Entity, EntityIntegrator, EntityIntegratorEquipment, EntityUserIntegrator};
use Illuminate\Http\{JsonResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Visualização de Equipamentos vinculados a um Integrador (Manager SaaS).
 *
 * Read-only: equipamentos são criados via API pelo próprio integrador
 * (autenticado por Sanctum). O Manager apenas inspeciona.
 *
 * Rotas: /panel/manager/entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/equipments
 */
class EntityIntegratorEquipmentsController extends Controller
{
    public function __construct(
        protected EntityIntegratorEquipment $model,
    ) {
    }

    public function index(string $entityId, string $userIntegrator, string $integrator, Request $request): InertiaResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);
        $integratorModel = EntityIntegrator::query()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integrator);

        $search = $request->string('search')->trim()->value();

        $query = EntityIntegratorEquipment::query()
            ->withTrashed()
            ->where('integrator_id', $integratorModel->id);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->whereLikeUnaccent('name', $search)
                    ->orWhereLikeUnaccent('ip', $search)
                    ->orWhereLikeUnaccent('mac', $search)
                    ->orWhereLikeUnaccent('serial_number', $search)
                    ->orWhereLikeUnaccent('code', $search);
            });
        }

        $items = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        return Inertia::render('Panel/Manager/EntityIntegratorEquipments/Index', [
            'entity' => [
                'id'   => $entity->id,
                'code' => $entity->code,
                'name' => $entity->name,
            ],
            'userIntegrator' => [
                'id'   => $userIntegratorModel->id,
                'name' => $userIntegratorModel->name,
            ],
            'integrator' => [
                'id'   => $integratorModel->id,
                'code' => $integratorModel->code,
                'name' => $integratorModel->name,
            ],
            'items'   => $items->through(fn (EntityIntegratorEquipment $e) => $this->toRow($e, $entityId, $userIntegrator)),
            'filters' => ['search' => $search],
            't'       => trans('manager_entity_integrator_equipments'),
        ]);
    }

    public function show(string $entityId, string $userIntegrator, string $integratorId, string $equipment): JsonResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $integrator = EntityIntegrator::query()->withTrashed()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integratorId);

        $record = $this->model->query()->withTrashed()
            ->where('integrator_id', $integrator->id)
            ->findOrFail($equipment);

        return response()->json(['data' => $this->toRow($record, $entityId, $userIntegrator)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(EntityIntegratorEquipment $equipment, string $entityId, string $userIntegratorId): array
    {
        $isDeleted    = $equipment->deleted_at !== null;
        $integratorId = (string) $equipment->integrator_id;
        $equipmentId  = (string) $equipment->id;

        return [
            'id'            => $equipmentId,
            'code'          => (string) $equipment->code,
            'name'          => (string) $equipment->name,
            'ip'            => $equipment->ip,
            'mac'           => $equipment->mac,
            'serial_number' => $equipment->serial_number,
            'active'        => (bool) $equipment->active,
            'deleted'       => $isDeleted,
            'created_at'    => $equipment->created_at?->format('d/m/Y H:i'),
            'deleted_at'    => $equipment->deleted_at?->format('d/m/Y H:i'),
            'show_url'      => route('manager.entities.user-integrators.integrators.equipments.show', [$entityId, $userIntegratorId, $integratorId, $equipmentId]),
        ];
    }
}
