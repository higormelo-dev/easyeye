<?php

namespace App\Http\Controllers;

use App\Http\Requests\IrisTypeRequest;
use App\Http\Resources\IrisTypeResource;
use App\Models\{IrisType};
use App\Services\{IrisTypeService};
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;

class IrisTypesController extends Controller
{
    /*
     * Name of the controller
     */
    protected string $titleController;

    /**
     * Instance of the standard model.
     */
    protected IrisType $model;

    protected IrisTypeService $service;

    public function __construct(IrisType $irisType, IrisTypeService $irisTypeService)
    {
        $this->titleController = __('actions.sidemenu.iristypes');
        $this->model           = $irisType;
        $this->service         = $irisTypeService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): Factory|Application|View
    {
        $meta = [
            'title'       => $this->titleController,
            'action'      => __('actions.records'),
            'breadcrumbs' => [
                [
                    'label'  => __('actions.sidemenu.dashboard'),
                    'url'    => route('panel.dashboard'),
                    'active' => false,
                ],
                [
                    'label'  => $this->titleController,
                    'url'    => route('panel.setting.iristypes.index'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.records'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        return view('system.iristypes.index', compact('meta'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Factory|Application|View|JsonResponse
    {
        return view('system.iristypes.form');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(IrisTypeRequest $request): Application|RedirectResponse|Redirector|JsonResponse|IrisTypeResource
    {
        $record = $this->service->create($request);

        $messageReturn = $this->titleController . ' cadastrado(a) com sucesso.';

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => (new IrisTypeResource($record))['data'],
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json([
                'data' => (new IrisTypeResource($record))['data'],
            ]);
        }

        return view(
            'system.iristypes.show',
            compact('record')
        );
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json([
                'data' => (new IrisTypeResource($record))['data'],
            ]);
        }

        return view('system.iristypes.form', compact('record'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @throws \Throwable
     */
    public function update(IrisTypeRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record        = $this->service->findByIdOrCode($id);
        $updatedRecord = $this->service->update($record, $request);
        $messageReturn = $this->getUpdateMessage($request);

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => (new IrisTypeResource($updatedRecord))['data'],
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($record) {
            $messageReturn = $this->titleController . ' deletado(a) com sucesso.';
            $recordData    = $record->toArray();
            $record->delete();

            // Retornar resposta
            if (request()->wantsJson()) {
                return response()->json([
                    'message' => $messageReturn,
                    'deleted' => $recordData,
                ]);
            }

            return redirect(action('\\' . static::class . '@index'))
                ->with('message', $messageReturn);
        });
    }

    /**
     * Restore the specified resource from storage.
     */
    public function restore(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($record) {
            $messageReturn = $this->titleController . ' restaurado(a) com sucesso.';
            $recordData    = $record->toArray();
            $record->restore();

            // Retornar resposta
            if (request()->wantsJson()) {
                return response()->json([
                    'message'  => $messageReturn,
                    'restored' => $recordData,
                ]);
            }

            return redirect(action('\\' . static::class . '@index'))
                ->with('message', $messageReturn);
        });
    }

    public function ajaxDatatable(Request $request): JsonResponse
    {
        $columns = [
            0 => 'created_at',
            1 => 'code',
            2 => 'name',
            3 => 'active',
            4 => 'action',
        ];

        $totalRecords = $this->model->query()->withTrashed()
            ->select('iris_types.*')
            ->where('iris_types.entity_id', session()->get('selected_entity_id'))
            ->orWhere(function ($query) {
                $query->whereNull('iris_types.entity_id')->whereNull('iris_types.deleted_at');
            })
            ->count();

        $limit = $request->get('length');
        $start = $request->get('start');
        $order = $columns[$request->get('order')[0]['column']];
        $dir   = $request->get('order')[0]['dir'];

        if (empty($request->get('search')['value'])) {
            $records = $this->model->query()->withTrashed()
                ->select('iris_types.*')
                ->where('iris_types.entity_id', session()->get('selected_entity_id'))
                ->orWhere(function ($query) {
                    $query->whereNull('iris_types.entity_id')->whereNull('iris_types.deleted_at');
                })
                ->skip($start)
                ->take($limit)
                ->orderBy($order, $dir)
                ->get();

            $totalFiltered = $totalRecords;
        } else {
            $search = $request->get('search')['value'];
            $query  = $this->model->query()->withTrashed()
                ->select('iris_types.*')
                ->where('iris_types.entity_id', session()->get('selected_entity_id'))
                ->orWhere(function ($query) {
                    $query->whereNull('iris_types.entity_id')->whereNull('iris_types.deleted_at');
                })
                ->whereRaw('LOWER(iris_types.name) LIKE LOWER(?)', ["%{$search}%"]);

            $records       = $query->skip($start)->take($limit)->orderBy($order, $dir)->get();
            $totalFiltered = $query->count();

        }
        $data = [];

        if (count($records)) {
            foreach ($records as $record) {
                $information['created_at'] = $record->created_at->format('d/m/Y H:i');
                $information['code']       = $record->code;
                $information['name']       = $record->name;
                $information['active']     = $record->deleted_at ? 'Deletado(a)' : ($record->active ?
                    '<span class="badge bg-success">SIM</span>' :
                    '<span class="badge bg-dark">NÃO</span>');
                $information['action'] = $this->buildActionButtons($record);
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
     * Get update message based on request type
     */
    private function getUpdateMessage(Request $request): string
    {
        if ($request->has('type_method')) {
            return $this->titleController .
                ($request->active ? ' desbloqueado(a) ' : ' bloqueado(a) ') . ' com sucesso.';
        }

        return $this->titleController . ' alterado(a) com sucesso.';
    }

    /**
     * Build action buttons for datatable
     */
    private function buildActionButtons($record): string
    {
        $btnActions = '';

        if (! $record->deleted_at && $record->entity_id === session()->get('selected_entity_id')) {
            $btnActions .= '<a href="javascript:void(0);"
	                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-edit"
	                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
	                    title="Editar"><i class="fa fa-edit"></i></a>';
            $btnActions .= '<a href="javascript:void(0);"
	                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-show"
	                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
	                    title="Visualizar"><i class="fa fa-eye"></i></a>';
            $btnActions .= '<a href="javascript:void(0);"
	                    class="btn waves-effect waves-light btn-secondary btn-xs m-1 btn-active"
	                    data-id="' . $record->id . '" data-situation="' . (($record->active) ? 0 : 1) . '"
	                    data-bs-toggle="tooltip" data-bs-placement="bottom"
	                    title="' . (($record->active) ? 'Inativar' : 'Ativar') . '">
	                    <i class="fas ' . (($record->active) ? 'fa-lock-open' : 'fa-unlock') . '"></i></a>';
            $btnActions .= '<a href="javascript:void(0);"
	                    class="btn waves-effect waves-light btn-danger btn-xs m-1 btn-trash"
	                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
	                    title="Deletar"><i class="fas fa-trash-alt"></i></a>';
        } elseif ($record->deleted_at && $record->entity_id === session()->get('selected_entity_id')) {
            $btnActions .= '<a href="javascript:void(0);"
	                    class="btn waves-effect waves-light btn-warning btn-xs m-1 btn-restore"
	                    data-id="' . $record->id . '" data-bs-toggle="tooltip" data-bs-placement="bottom"
	                    title="Restaurar"><i class="fas fa-recycle"></i></a>';
        }

        return $btnActions;
    }
}
