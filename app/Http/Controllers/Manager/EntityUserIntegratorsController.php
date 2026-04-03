<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\EntityUserIntegratorsDataTable;
use App\Http\Controllers\Controller;
use App\Http\Requests\EntityUserIntegratorRequest;
use App\Models\{Entity, EntityUserIntegrator};
use Illuminate\Http\{JsonResponse, Request};

class EntityUserIntegratorsController extends Controller
{
    protected EntityUserIntegrator $model;

    public function __construct(EntityUserIntegrator $entityUserIntegrator)
    {
        $this->titleController = __('actions.user_integrators');
        $this->model           = $entityUserIntegrator;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(string $entity, EntityUserIntegratorsDataTable $dataTable): mixed
    {
        $entityModel = Entity::query()->findOrFail($entity);

        $meta = [
            'title'            => $entityModel->name,
            'action'           => $this->titleController,
            'total'            => EntityUserIntegrator::where('entity_id', $entity)->count(),
            'breadcrumb_title' => false,
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Empresas', 'url' => route('panel.manager.entities.index'), 'active' => false],
                ['label' => $entityModel->name, 'url' => 'javascript:void(0);', 'active' => false],
                ['label' => $this->titleController, 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];

        return $dataTable
            ->forEntity($entity)
            ->render('system.manager.entity_user_integrators.index', ['meta' => $meta, 'entity' => $entityModel]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(EntityUserIntegratorRequest $request, string $entity): JsonResponse
    {
        $entityModel = Entity::query()->findOrFail($entity);
        $attributes  = array_merge($request->validated(), [
            'entity_id' => $entityModel->id,
            'active'    => true,
        ]);
        $model = $this->model->create($attributes);

        return response()->json([
            'message' => $this->titleController . ' cadastrado(a) com sucesso.',
            'data'    => $model->toArray(),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $entityId, string $userIntegrator): mixed
    {
        $entity = Entity::query()->withTrashed()->findOrFail($entityId);
        $record = $this->model->query()->withTrashed()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        if (request()->wantsJson()) {
            return response()->json(['data' => $record->toArray()]);
        }

        return view('system.manager.entity_user_integrators.show', compact('entity', 'record'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EntityUserIntegratorRequest $request, string $entityId, string $userIntegrator): JsonResponse
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);
        $data = $request->validated();

        if (empty($data['password'])) {
            unset($data['password']);
        }
        $record->update($data);

        return response()->json([
            'message' => $this->titleController . ' atualizado(a) com sucesso.',
            'data'    => $record->fresh()->toArray(),
        ]);
    }

    /**
     * Return flat JSON for the crudForm edit modal.
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

    /**
     * Toggle active status.
     */
    public function activate(Request $request, string $entityId, string $userIntegrator): JsonResponse
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);
        $active = (bool) $request->input('active', ! $record->active);
        $record->update(['active' => $active]);

        return response()->json([
            'message' => $this->titleController . ' ' . ($active ? 'ativado(a)' : 'desativado(a)') . ' com sucesso.',
            'active'  => $record->fresh()->active,
        ]);
    }

    /**
     * Restore a soft-deleted record.
     */
    public function restore(Request $request, string $entityId, string $userIntegrator): JsonResponse
    {
        $entity = Entity::query()->withTrashed()->findOrFail($entityId);
        $record = $this->model->query()->withTrashed()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);
        $record->restore();

        return response()->json(['message' => $this->titleController . ' restaurado(a) com sucesso.']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $entityId, string $userIntegrator): JsonResponse
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);
        $record->delete();

        return response()->json(['message' => $this->titleController . ' deletado(a) com sucesso.']);
    }
}
