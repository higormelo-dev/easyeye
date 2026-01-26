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
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class IrisTypesController extends Controller
{
    protected string $titleController = 'Tipos de íris';

    /**
     * Instance of the standard model.
     */
    protected IrisType $model;

    protected IrisTypeService $service;

    public function __construct(IrisType $irisType, IrisTypeService $irisTypeService)
    {
        $this->model   = $irisType;
        $this->service = $irisTypeService;
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
        try {
            return view('system.iristypes.form');
        } catch (\Throwable $e) {
            return $this->serverErrorResponse($e);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(IrisTypeRequest $request): Application|RedirectResponse|Redirector|JsonResponse|IrisTypeResource
    {
        try {
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
        } catch (\Throwable $e) {
            return $this->serverErrorResponse($e);
        }

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): Application|View|JsonResponse
    {
        try {
            $record = $this->findRecord($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

            return view(
                'system.iristypes.show',
                compact('record')
            );
        } catch (\Throwable $e) {
            return $this->serverErrorResponse($e);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): Application|View|JsonResponse
    {
        try {
            $record = $this->findRecord($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

            return view('system.iristypes.form', compact('record'));
        } catch (\Throwable $e) {
            return $this->serverErrorResponse($e);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(IrisTypeRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        try {
            $record = $this->findRecord($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

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
        } catch (\Throwable $e) {
            return $this->serverErrorResponse($e);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): Application|View|JsonResponse
    {
        try {
            $record = $this->findRecord($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

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
        } catch (\Throwable $e) {
            return $this->serverErrorResponse($e);
        }
    }

    /**
     * Restore the specified resource from storage.
     */
    public function restore(string $id): Application|View|JsonResponse
    {
        try {
            $record = $this->findRecord($id);

            if (! $record) {
                return $this->notFoundResponse();
            }

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
        } catch (\Throwable $e) {
            return $this->serverErrorResponse($e);
        }
    }

    public function ajaxDatatable(Request $request): JsonResponse
    {
        $columns = [
            0 => 'created_at',
            1 => 'name',
            2 => 'active',
            3 => 'action',
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
     * Find skin type by ID
     */
    private function findRecord(string $id): ?IrisType
    {
        return $this->model->query()->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->where('id', $id)
            ->first();
    }

    /**
     * Return not found response
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json(['message' => 'Skin type not found.'], HttpResponse::HTTP_NOT_FOUND);
    }
    /**
     * Return server error response
     */
    private function serverErrorResponse($error): JsonResponse
    {
        $messages = [
            'message' => 'An error occurred.',
        ];

        if (app()->environment('local')) {
            $messages['debug'] = $error->getMessage();
            $messages['file']  = $error->getFile();
            $messages['line']  = $error->getLine();
            $messages['trace'] = $error->getTraceAsString();
            $messages['code']  = $error->getCode();
            $messages['type']  = get_class($error);
        }

        return response()->json($messages, HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
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
