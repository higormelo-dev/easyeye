<?php

namespace App\Http\Controllers;

use App\DataTables\VisualAcuityTypesDataTable;
use App\Http\Requests\VisualAcuityTypeRequest;
use App\Http\Resources\VisualAcuityTypeResource;
use App\Models\VisualAcuityType;
use App\Services\VisualAcuityTypeService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;

class VisualAcuityTypesController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected VisualAcuityType $model;

    protected VisualAcuityTypeService $service;

    public function __construct(VisualAcuityType $visualAcuityType, VisualAcuityTypeService $visualAcuityTypeService)
    {
        $this->titleController = __('actions.sidemenu.visualacuitytypes');
        $this->model           = $visualAcuityType;
        $this->service         = $visualAcuityTypeService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(VisualAcuityTypesDataTable $dataTable): Factory|Application|View|JsonResponse
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
                    'url'    => route('panel.setting.visualacuitytypes.index'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.records'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        return $dataTable->render('system.settings.visualacuitytypes.index', compact('meta'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Factory|Application|View|JsonResponse
    {
        return view('system.settings.visualacuitytypes.form');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(VisualAcuityTypeRequest $request): Application|RedirectResponse|Redirector|JsonResponse|VisualAcuityTypeResource
    {
        $record = $this->service->create($request);

        $messageReturn = $this->getCreateMessage();

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new VisualAcuityTypeResource($record),
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): Application|View|JsonResponse|VisualAcuityTypeResource
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json([
                'data' => new VisualAcuityTypeResource($record),
            ]);
        }

        return view(
            'system.settings.visualacuitytypes.show',
            compact('record')
        );
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): Application|View|JsonResponse|VisualAcuityTypeResource
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json([
                'data' => new VisualAcuityTypeResource($record),
            ]);
        }

        return view('system.settings.visualacuitytypes.form', compact('record'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @throws \Throwable
     */
    public function update(VisualAcuityTypeRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record        = $this->service->findByIdOrCode($id);
        $updatedRecord = $this->service->update($record, $request);
        $messageReturn = $this->getUpdateMessage($request);

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new VisualAcuityTypeResource($updatedRecord),
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
            $messageReturn = $this->getDeleteMessage();
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
            $messageReturn = $this->getRestoreMessage();
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
}
