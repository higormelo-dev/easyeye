<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\EntityUserIntegratorRequest;
use App\Models\{Entity, EntityUserIntegrator};
use Illuminate\Http\{JsonResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Gestão de Usuários Integradores por Empresa (Manager SaaS).
 *
 * Usuário integrador é o "técnico API" que detém credenciais de acesso para
 * que sistemas externos (PMS, RIS, equipamentos OEM) integrem com a clínica.
 * Cada um pode possuir N EntityIntegrator (próximo nível da hierarquia).
 *
 * Rotas: /panel/manager/entities/{entity}/user-integrators/...
 */
class EntityUserIntegratorsController extends Controller
{
    public function __construct(
        protected EntityUserIntegrator $model,
    ) {
    }

    /**
     * Lista paginada Inertia. Substitui a antiga DataTable Yajra mantendo
     * filtros active/deleted e busca por nome/email.
     */
    public function index(string $entity, Request $request): InertiaResponse
    {
        $entityModel = Entity::query()->findOrFail($entity);
        $search      = $request->string('search')->trim()->value();

        $query = EntityUserIntegrator::query()
            ->withTrashed()
            ->where('entity_id', $entityModel->id);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->whereLikeUnaccent('name', $search)
                    ->orWhereLikeUnaccent('email', $search)
                    ->orWhereLikeUnaccent('code', $search);
            });
        }

        $query->orderByDesc('created_at');

        $items = $query->paginate(15)->withQueryString();

        return Inertia::render('Panel/Manager/EntityUserIntegrators/Index', [
            'entity' => [
                'id'   => $entityModel->id,
                'code' => $entityModel->code,
                'name' => $entityModel->name,
            ],
            'items'   => $items->through(fn (EntityUserIntegrator $eui) => $this->toRow($eui)),
            'filters' => ['search' => $search],
            't'       => trans('manager_entity_user_integrators'),
        ]);
    }

    public function store(EntityUserIntegratorRequest $request, string $entity): JsonResponse
    {
        $entityModel = Entity::query()->findOrFail($entity);

        $attributes = array_merge($request->validated(), [
            'entity_id' => $entityModel->id,
            'active'    => true,
        ]);

        $model = $this->model->create($attributes);

        return response()->json([
            'message' => trans('manager_entity_user_integrators.created'),
            'data'    => $this->toRow($model->fresh()),
        ]);
    }

    public function show(string $entityId, string $userIntegrator, Request $request): JsonResponse
    {
        $entity = Entity::query()->withTrashed()->findOrFail($entityId);
        $record = $this->model->query()->withTrashed()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        return response()->json([
            'data' => array_merge(
                $this->toRow($record),
                [
                    'integrators_count' => $record->integrators()->count(),
                ],
            ),
        ]);
    }

    public function update(EntityUserIntegratorRequest $request, string $entityId, string $userIntegrator): JsonResponse
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $data = $request->validated();

        // Password vazio = mantém o atual (não sobrescreve).
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $record->update($data);

        return response()->json([
            'message' => trans('manager_entity_user_integrators.updated'),
            'data'    => $this->toRow($record->fresh()),
        ]);
    }

    /**
     * Retorna dados planos para preenchimento do FormModal de edição.
     * Nunca retorna password — só os campos editáveis.
     */
    public function editData(string $entityId, string $userIntegrator): JsonResponse
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        return response()->json(['data' => [
            'name'     => $record->name,
            'email'    => $record->email,
            'password' => '',
            'active'   => (bool) $record->active,
        ]]);
    }

    public function activate(Request $request, string $entityId, string $userIntegrator): JsonResponse
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $active = (bool) $request->input('active', ! $record->active);
        $record->update(['active' => $active]);

        return response()->json([
            'message' => trans($active
                ? 'manager_entity_user_integrators.activated'
                : 'manager_entity_user_integrators.deactivated'),
            'active' => $record->fresh()->active,
        ]);
    }

    public function restore(Request $request, string $entityId, string $userIntegrator): JsonResponse
    {
        $entity = Entity::query()->withTrashed()->findOrFail($entityId);
        $record = $this->model->query()->withTrashed()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $record->restore();

        return response()->json(['message' => trans('manager_entity_user_integrators.restored')]);
    }

    public function destroy(string $entityId, string $userIntegrator): JsonResponse
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $record->delete();

        return response()->json(['message' => trans('manager_entity_user_integrators.deleted')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(EntityUserIntegrator $eui): array
    {
        $entityId  = (string) $eui->entity_id;
        $isDeleted = $eui->deleted_at !== null;

        // mode controla quais ações a UI exibe:
        // - restore: registro soft-deleted, só botão de restaurar
        // - full:    registro normal, todas as ações
        $mode = $isDeleted ? 'restore' : 'full';

        return [
            'id'              => (string) $eui->id,
            'code'            => (string) $eui->code,
            'name'            => (string) $eui->name,
            'email'           => (string) $eui->email,
            'active'          => (bool) $eui->active,
            'deleted'         => $isDeleted,
            'created_at'      => $eui->created_at?->format('d/m/Y H:i'),
            'mode'            => $mode,
            'edit_data_url'   => route('manager.entities.user-integrators.edit-data', [$entityId, $eui->id]),
            'update_url'      => route('manager.entities.user-integrators.update', [$entityId, $eui->id]),
            'destroy_url'     => route('manager.entities.user-integrators.destroy', [$entityId, $eui->id]),
            'activate_url'    => route('manager.entities.user-integrators.activate', [$entityId, $eui->id]),
            'restore_url'     => route('manager.entities.user-integrators.restore', [$entityId, $eui->id]),
            'show_url'        => route('manager.entities.user-integrators.show', [$entityId, $eui->id]),
            'integrators_url' => route('manager.entities.user-integrators.integrators.index', [$entityId, $eui->id]),
        ];
    }
}
