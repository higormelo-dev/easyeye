<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\EntityIntegratorRequest;
use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator};
use Illuminate\Http\{JsonResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Gestão de Integradores (Manager SaaS).
 *
 * Integrator = identidade do equipamento/PMS/RIS que se conecta à API EasyEye.
 * Vive sob um EntityUserIntegrator (que detém credenciais email/senha + code).
 *
 * Auth real: POST /api/integrators (email+password+code) gera Sanctum token
 * em personal_access_tokens. Esta tela Manager apenas gerencia identidade do
 * integrator — não emite tokens (segurança: tokens só nascem via login).
 *
 * Rotas: /panel/manager/entities/{entity}/user-integrators/{userIntegrator}/integrators/...
 */
class EntityIntegratorsController extends Controller
{
    public function __construct(
        protected EntityIntegrator $model,
    ) {
    }

    /**
     * Listagem Inertia paginada com busca por nome/IP/MAC/code.
     */
    public function index(string $entityId, string $userIntegrator, Request $request): InertiaResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $search = $request->string('search')->trim()->value();

        $query = EntityIntegrator::query()
            ->withTrashed()
            ->where('entity_user_integrator_id', $userIntegratorModel->id);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->whereLikeUnaccent('name', $search)
                    ->orWhereLikeUnaccent('ip', $search)
                    ->orWhereLikeUnaccent('mac', $search)
                    ->orWhereLikeUnaccent('code', $search);
            });
        }

        $items = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        return Inertia::render('Panel/Manager/EntityIntegrators/Index', [
            'entity' => [
                'id'   => $entity->id,
                'code' => $entity->code,
                'name' => $entity->name,
            ],
            'userIntegrator' => [
                'id'    => $userIntegratorModel->id,
                'code'  => $userIntegratorModel->code,
                'name'  => $userIntegratorModel->name,
                'email' => $userIntegratorModel->email,
            ],
            'items'   => $items->through(fn (EntityIntegrator $i) => $this->toRow($i)),
            'filters' => ['search' => $search],
            't'       => trans('manager_entity_integrators'),
        ]);
    }

    public function store(EntityIntegratorRequest $request, string $entityId, string $userIntegrator): JsonResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $attributes = array_merge($request->validated(), [
            'entity_user_integrator_id' => $userIntegratorModel->id,
            'active'                    => true,
        ]);

        $model = $this->model->create($attributes);

        return response()->json([
            'message' => trans('manager_entity_integrators.created'),
            'data'    => $this->toRow($model->fresh()),
        ]);
    }

    public function show(string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $entity              = Entity::query()->withTrashed()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()->withTrashed()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $record = $this->model->query()->withTrashed()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integrator);

        // Conta tokens Sanctum ativos do EntityUserIntegrator dono, filtrados por
        // ability do integrator_id atual — espelha a forma como o login emite tokens.
        $activeTokens = $record->user
            ->tokens()
            ->where(function ($q) use ($integrator) {
                $q->whereJsonContains('abilities', 'integrator_id:' . $integrator);
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        return response()->json([
            'data' => array_merge($this->toRow($record), [
                'equipments_count' => $record->equipments()->count(),
                'active_tokens'    => $activeTokens,
            ]),
        ]);
    }

    public function update(EntityIntegratorRequest $request, string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $record = $this->model->query()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integrator);

        $record->update($request->validated());

        return response()->json([
            'message' => trans('manager_entity_integrators.updated'),
            'data'    => $this->toRow($record->fresh()),
        ]);
    }

    /**
     * Dados planos para preenchimento do FormModal de edição.
     */
    public function editData(string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $record = $this->model->query()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integrator);

        return response()->json(['data' => [
            'name'   => $record->name,
            'ip'     => $record->ip,
            'mac'    => $record->mac,
            'active' => (bool) $record->active,
        ]]);
    }

    public function activate(Request $request, string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $record = $this->model->query()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integrator);

        $active = (bool) $request->input('active', ! $record->active);
        $record->update(['active' => $active]);

        return response()->json([
            'message' => trans($active
                ? 'manager_entity_integrators.activated'
                : 'manager_entity_integrators.deactivated'),
            'active' => $record->fresh()->active,
        ]);
    }

    public function restore(Request $request, string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $entity              = Entity::query()->withTrashed()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()->withTrashed()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $record = $this->model->query()->withTrashed()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integrator);

        $record->restore();

        return response()->json(['message' => trans('manager_entity_integrators.restored')]);
    }

    public function destroy(string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $record = $this->model->query()
            ->where('entity_user_integrator_id', $userIntegratorModel->id)
            ->findOrFail($integrator);

        $record->delete();

        return response()->json(['message' => trans('manager_entity_integrators.deleted')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(EntityIntegrator $integrator): array
    {
        $entityId     = (string) $integrator->user?->entity_id;
        $userId       = (string) $integrator->entity_user_integrator_id;
        $integratorId = (string) $integrator->id;
        $isDeleted    = $integrator->deleted_at !== null;
        $mode         = $isDeleted ? 'restore' : 'full';

        return [
            'id'             => $integratorId,
            'code'           => (string) $integrator->code,
            'name'           => (string) $integrator->name,
            'ip'             => $integrator->ip,
            'mac'            => $integrator->mac,
            'active'         => (bool) $integrator->active,
            'deleted'        => $isDeleted,
            'created_at'     => $integrator->created_at?->format('d/m/Y H:i'),
            'mode'           => $mode,
            'edit_data_url'  => route('manager.entities.user-integrators.integrators.edit-data', [$entityId, $userId, $integratorId]),
            'update_url'     => route('manager.entities.user-integrators.integrators.update', [$entityId, $userId, $integratorId]),
            'destroy_url'    => route('manager.entities.user-integrators.integrators.destroy', [$entityId, $userId, $integratorId]),
            'activate_url'   => route('manager.entities.user-integrators.integrators.activate', [$entityId, $userId, $integratorId]),
            'restore_url'    => route('manager.entities.user-integrators.integrators.restore', [$entityId, $userId, $integratorId]),
            'show_url'       => route('manager.entities.user-integrators.integrators.show', [$entityId, $userId, $integratorId]),
            'equipments_url' => route('manager.entities.user-integrators.integrators.equipments.index', [$entityId, $userId, $integratorId]),
        ];
    }
}
