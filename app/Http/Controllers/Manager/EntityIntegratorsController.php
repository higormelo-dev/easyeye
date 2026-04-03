<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\EntityIntegratorsDataTable;
use App\Http\Controllers\Controller;
use App\Http\Requests\EntityIntegratorRequest;
use App\Models\{Entity, EntityIntegrator, EntityUserIntegrator};
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
    public function index(string $entityId, string $userIntegrator, EntityIntegratorsDataTable $dataTable): mixed
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $meta = [
            'title'            => $entity->name,
            'action'           => $this->titleController,
            'total'            => EntityIntegrator::where('entity_user_integrator_id', $userIntegratorModel->id)->count(),
            'breadcrumb_title' => false,
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Empresas', 'url' => route('panel.manager.entities.index'), 'active' => false],
                ['label' => __('actions.user_integrators'), 'url' => route('panel.manager.entities.user-integrators.index', $entityId), 'active' => false],
                ['label' => $userIntegratorModel->name, 'url' => 'javascript:void(0);', 'active' => false],
                ['label' => $this->titleController, 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];

        return $dataTable
            ->forUserIntegrator($entityId, $userIntegrator)
            ->render('system.manager.entity_integrators.index', [
                'meta'           => $meta,
                'entity'         => $entity,
                'userIntegrator' => $userIntegratorModel,
            ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @throws RandomException
     */
    public function store(EntityIntegratorRequest $request, string $entityId, string $userIntegrator): JsonResponse
    {
        $entity              = Entity::query()->findOrFail($entityId);
        $userIntegratorModel = EntityUserIntegrator::query()
            ->where('entity_id', $entity->id)
            ->findOrFail($userIntegrator);

        $parameterExtras = [
            'entity_user_integrator_id' => $userIntegratorModel->id,
            'token'                     => $this->generateUniqueToken(),
            'active'                    => true,
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
    public function show(string $entityId, string $userIntegrator, string $integrator): mixed
    {
        $entity = Entity::query()->withTrashed()->findOrFail($entityId);
        $record = $this->model->query()->withTrashed()
            ->where('entity_user_integrator_id', $userIntegrator)
            ->findOrFail($integrator);

        if (request()->wantsJson()) {
            return response()->json(['data' => $record->toArray()]);
        }

        return view('system.manager.entity_integrators.show', compact('entity', 'record'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EntityIntegratorRequest $request, string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $record = $this->model->query()
            ->where('entity_user_integrator_id', $userIntegrator)
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
    public function editData(string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $record = $this->model->query()
            ->where('entity_user_integrator_id', $userIntegrator)
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
    public function activate(Request $request, string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $record = $this->model->query()
            ->where('entity_user_integrator_id', $userIntegrator)
            ->findOrFail($integrator);
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
    public function restore(Request $request, string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $record = $this->model->query()->withTrashed()
            ->where('entity_user_integrator_id', $userIntegrator)
            ->findOrFail($integrator);
        $record->restore();

        return response()->json(['message' => $this->titleController . ' restaurado(a) com sucesso.']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $entityId, string $userIntegrator, string $integrator): JsonResponse
    {
        $record = $this->model->query()
            ->where('entity_user_integrator_id', $userIntegrator)
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
                microtime(true) . random_bytes(32) . uniqid('', true) . mt_rand(),
            );
        } while (EntityIntegrator::query()->where('token', $token)->exists());

        return $token;
    }
}
