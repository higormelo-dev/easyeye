<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\EntityIntegratorsDataTable;
use App\Http\Controllers\Controller;
use App\Http\Requests\EntityIntegratorRequest;
use App\Models\{Entity, EntityIntegrator};
use Illuminate\Http\{JsonResponse, Request};
use Random\RandomException;

class EntityIntegratorsController extends Controller
{
    protected string $titleController = 'Integradores';

    protected EntityIntegrator $model;

    public function __construct(EntityIntegrator $entityIntegrator)
    {
        $this->model = $entityIntegrator;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(string $entity, EntityIntegratorsDataTable $dataTable): mixed
    {
        $entityModel = Entity::query()->findOrFail($entity);

        $meta = [
            'title'       => $this->titleController,
            'action'      => __('actions.records'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.entities'), 'url' => route('panel.manager.entities.index'), 'active' => false],
                ['label' => $this->titleController, 'url' => route('panel.manager.entities.integrators.index', $entity), 'active' => false],
                ['label' => __('actions.records'), 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];

        return $dataTable
            ->forEntity($entity)
            ->render('system.manager.entity_integrators.index', ['meta' => $meta, 'entity' => $entityModel]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @throws RandomException
     */
    public function store(EntityIntegratorRequest $request, string $entity): JsonResponse
    {
        $entityModel     = Entity::query()->findOrFail($entity);
        $parameterExtras = [
            'token'  => $this->generateUniqueToken(),
            'active' => true,
        ];
        $attributes        = array_merge($request->except(['_token', 'mac']), $parameterExtras);
        $attributes['mac'] = strtoupper($request->input('mac', ''));
        $model             = $this->model->create($attributes);

        return response()->json([
            'message' => $this->titleController . ' cadastrado(a) com sucesso.',
            'data'    => $model->toArray(),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $entityId, string $integrator): mixed
    {
        $entity = Entity::query()->withTrashed()->findOrFail($entityId);
        $record = $this->model->query()->withTrashed()
            ->whereHas('user', fn ($q) => $q->where('entity_id', $entity->id))
            ->findOrFail($integrator);

        if (request()->wantsJson()) {
            return response()->json(['data' => $record->toArray()]);
        }

        return view('system.manager.entity_integrators.show', compact('entity', 'record'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EntityIntegratorRequest $request, string $entityId, string $integrator): JsonResponse
    {
        $entity            = Entity::query()->findOrFail($entityId);
        $record            = $this->model->query()
            ->whereHas('user', fn ($q) => $q->where('entity_id', $entity->id))
            ->findOrFail($integrator);
        $attributes        = $request->except(['_token', 'mac']);
        $attributes['mac'] = strtoupper($request->input('mac', ''));
        $record->update($attributes);

        return response()->json([
            'message' => $this->titleController . ' atualizado(a) com sucesso.',
            'data'    => $record->fresh()->toArray(),
        ]);
    }

    /**
     * Return flat JSON for the crudForm edit modal.
     */
    public function editData(string $entityId, string $integrator): JsonResponse
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()
            ->whereHas('user', fn ($q) => $q->where('entity_id', $entity->id))
            ->findOrFail($integrator);

        return response()->json(['data' => [
            'name'   => $record->name,
            'ip'     => $record->ip,
            'mac'    => $record->mac,
            'active' => (bool) $record->active,
        ]]);
    }

    /**
     * Toggle active status.
     */
    public function activate(Request $request, string $entityId, string $integrator): JsonResponse
    {
        $entity  = Entity::query()->findOrFail($entityId);
        $record  = $this->model->query()
            ->whereHas('user', fn ($q) => $q->where('entity_id', $entity->id))
            ->findOrFail($integrator);
        $active  = (bool) $request->input('active', ! $record->active);
        $record->update(['active' => $active]);

        return response()->json([
            'message' => $this->titleController . ' ' . ($active ? 'ativado(a)' : 'desativado(a)') . ' com sucesso.',
            'active'  => $record->fresh()->active,
        ]);
    }

    /**
     * Restore a soft-deleted record.
     */
    public function restore(Request $request, string $entityId, string $integrator): JsonResponse
    {
        $entity = Entity::query()->withTrashed()->findOrFail($entityId);
        $record = $this->model->query()->withTrashed()
            ->whereHas('user', fn ($q) => $q->where('entity_id', $entity->id))
            ->findOrFail($integrator);
        $record->restore();

        return response()->json(['message' => $this->titleController . ' restaurado(a) com sucesso.']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $entityId, string $integrator): JsonResponse
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()
            ->whereHas('user', fn ($q) => $q->where('entity_id', $entity->id))
            ->findOrFail($integrator);
        $record->delete();

        return response()->json(['message' => $this->titleController . ' deletado(a) com sucesso.']);
    }

    /**
     * @throws RandomException
     */
    private function generateUniqueToken(): string
    {
        do {
            $token = hash(
                'sha256',
                microtime(true) . random_bytes(32) . uniqid('', true) . mt_rand()
            );
        } while (EntityIntegrator::query()->where('token', $token)->exists());

        return $token;
    }
}
