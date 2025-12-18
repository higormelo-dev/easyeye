<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\EntityIntegratorRequest;
use App\Models\{Entity, EntityIntegrator};
use Illuminate\Contracts\View\{View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Random\RandomException;

class EntityIntegratorsController extends Controller
{
    protected string $titleController = 'Integradores';

    /**
     * Instance of the standard model.
     */
    protected EntityIntegrator $model;

    public function __construct(EntityIntegrator $entityIntegrator)
    {
        $this->model = $entityIntegrator;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(string $entityId): Application|View
    {
        $entity = Entity::query()->findOrFail($entityId);
        $meta   = [
            'title'       => $this->titleController,
            'action'      => __('actions.records'),
            'breadcrumbs' => [
                [
                    'label'  => __('actions.sidemenu.dashboard'),
                    'url'    => route('panel.dashboard'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.sidemenu.entities'),
                    'url'    => route('panel.manager.entities.index'),
                    'active' => false,
                ],
                [
                    'label'  => $this->titleController,
                    'url'    => route('panel.manager.entities.integrators.index', $entity->id),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.records'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        return view(
            'system.manager.entity_integrators.index',
            compact('meta', 'entity')
        );
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(string $entityId): Application|View
    {
        return view('system.manager.entity_integrators.form');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @throws RandomException
     */
    public function store(EntityIntegratorRequest $request, string $entityId): Application|JsonResponse|Redirector|RedirectResponse
    {
        $entity          = Entity::query()->findOrFail($entityId);
        $parameterExtras = [
            'entity_id' => $entity->id,
            'token'     => $this->generateUniqueToken(),
            'active'    => true,
        ];
        $attributes        = array_merge($request->except(['_token', 'mac']), $parameterExtras);
        $attributes['mac'] = strtoupper($attributes['mac']);
        $model             = $this->model->create($attributes);

        if (request()->wantsJson()) {
            return response()->json(
                [
                    'message' => $this->titleController . ' cadastrado(a) com sucesso.',
                    'data'    => $model->toArray(),
                ]
            );
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $this->titleController . ' cadastrado(a) com sucesso.');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $entityId, string $id): View|Application|JsonResponse
    {
        $entity = Entity::query()->withTrashed()->findOrFail($entityId);
        $record = $this->model->query()->withTrashed()->where('entity_id', $entity->id)->findOrFail($id);

        if (request()->wantsJson()) {
            return response()->json(
                [
                    'data' => $record->toArray(),
                ]
            );
        }

        return view('system.manager.entity_integrators.show', compact('entity', 'record'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $entityId, string $id)
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()->where('entity_id', $entity->id)->findOrFail($id);

        return view('system.manager.entity_integrators.form', compact('entity', 'record'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EntityIntegratorRequest $request, string $entityId, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $entity          = Entity::query()->findOrFail($entityId);
        $parameterExtras = [
            'entity_id' => $entity->id,
        ];
        $attributes        = array_merge($request->except(['_token', 'mac']), $parameterExtras);
        $attributes['mac'] = strtoupper($attributes['mac']);
        $model             = $this->model->update($attributes);

        if (request()->wantsJson()) {
            return response()->json(
                [
                    'message' => $this->titleController . ' cadastrado(a) com sucesso.',
                    'data'    => $model->toArray(),
                ]
            );
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $this->titleController . ' cadastrado(a) com sucesso.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $entityId, string $id)
    {
        $entity = Entity::query()->findOrFail($entityId);
        $record = $this->model->query()->where('entity_id', $entity->id)->findOrFail($id);
        $record->delete();

        if (request()->wantsJson()) {
            return response()->json(
                [
                    'message' => $this->titleController . ' deletada(a) com sucesso.',
                    'deleted' => $record->toArray(),
                ]
            );
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message-error', $this->titleController . ' deletada(a) com sucesso.');

    }

    /**
     * AJAX Datatable
     */
    public function ajaxDatatable(Request $request): JsonResponse
    {
        $columns = [
            0 => 'created_at',
            1 => 'name',
            2 => 'active',
            3 => 'action',
        ];

        $entity       = Entity::query()->findOrFail(session()->get('selected_entity_id'));
        $totalRecords = $this->model->query()->withTrashed()
            ->where('entity_id', $entity->id)->count();
        $limit = $request->get('length');
        $start = $request->get('start');
        $order = $columns[$request->get('order')[0]['column']];
        $dir   = $request->get('order')[0]['dir'];

        if (empty($request->get('search')['value'])) {
            $records = $this->model->query()->withTrashed()
                ->where('entity_id', $entity->id)
                ->skip($start)->take($limit)
                ->orderBy($order, $dir)->get();
            $totalFiltered = $this->model->count();
        } else {
            $search  = $request->get('search')['value'];
            $records = $this->model->query()->withTrashed()
                ->where('entity_id', $entity->id)
                ->where('name', 'like', "%{$search}%")
                ->skip($start)
                ->take($limit)
                ->orderBy($order, $dir)
                ->get();
            $totalFiltered = $this->model->query()->withTrashed()
                ->where('entity_id', $entity->id)
                ->where('name', 'like', "%{$search}%")
                ->skip($start)
                ->take($limit)
                ->orderBy($order, $dir)
                ->count();
        }
        $data = [];

        if (count($records)) {
            foreach ($records as $record) {
                $btnActions                = '';
                $information['created_at'] = $record->created_at->format('d/m/Y H:i');
                $information['name']       = $record->name;
                $information['active']     = !$record->deleted_at ?
                    (
                        $record->active ?
                            '<span class="badge bg-success">SIM</span>' :
                            '<span class="badge bg-dark">NÃO</span>'
                    ) :
                    '<span class="badge bg-light text-dark">Inativo</span>';

                if (!$record->deleted_at) {
                    $btnActions .= '<a href="javascript:void(0);"
	                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-edit"
	                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
	                    title="Editar"><i class="fa fa-edit"></i></a>';

                }
                $btnActions .= '<a href="javascript:void(0);"
                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-show"
                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
                    title="Visualizar"><i class="fa fa-eye"></i></a>';

                if (!$record->deleted_at) {
                    $btnActions .= '<a
	                    href="' . route('panel.manager.entities.integrators.equipments.index', [$entity->id, $record->id])
                        . '"
	                    class="btn waves-effect waves-light btn-secondary btn-xs m-1"
	                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
	                    title="Equipamentos"><i class="fas fa-satellite-dish"></i></a>';
                    $btnActions .= '<a href="javascript:void(0);"
	                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-active"
	                    data-id="' . $record->id . '" data-situation="' . (($record->active) ? 0 : 1) . '"
	                    data-bs-toggle="tooltip" data-bs-placement="bottom"
	                    title="' . (($record->active) ? 'Inativar' : 'Ativar') . '">
	                    <i class="fas ' . (($record->active) ? 'fa-lock-open' : 'fa-unlock') . '"></i></a>';
                    $btnActions .= '<a href="javascript:void(0);"
	                    class="btn waves-effect waves-danger btn-danger btn-xs m-1 btn-trash"
	                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
	                    title="Deletar"><i class="fas fa-trash-alt"></i></a>';

                }
                $information['action'] = $btnActions;
                $data[]                = $information;
            }

        }

        return response()->json(
            [
                'draw'            => (int) $request->get('draw'),
                'recordsTotal'    => (int) $totalRecords,
                'recordsFiltered' => (int) $totalFiltered,
                'data'            => $data,
            ]
        );
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
