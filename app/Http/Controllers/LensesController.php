<?php

namespace App\Http\Controllers;

use App\DataTables\LensesDataTable;
use App\Http\Requests\LenseRequest;
use App\Http\Resources\LenseResource;
use App\Models\Lense;
use App\Services\LenseService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;

class LensesController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected Lense $model;

    protected LenseService $service;

    public function __construct(Lense $lense, LenseService $lenseService)
    {
        $this->titleController = __('actions.sidemenu.lenses');
        $this->model           = $lense;
        $this->service         = $lenseService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(LensesDataTable $dataTable): Factory|Application|View|JsonResponse
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
                    'url'    => route('panel.setting.lenses.index'),
                    'active' => false,
                ],
                [
                    'label'  => __('actions.records'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        return $dataTable->render('system.settings.lenses.index', compact('meta'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Factory|Application|View|JsonResponse
    {
        return view('system.settings.lenses.form');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(LenseRequest $request): Application|RedirectResponse|Redirector|JsonResponse|LenseResource
    {
        $record = $this->service->create($request);

        $messageReturn = $this->getCreateMessage();

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new LenseResource($record),
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): Application|View|JsonResponse|LenseResource
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json([
                'data' => new LenseResource($record),
            ]);
        }

        return view(
            'system.settings.lenses.show',
            compact('record')
        );
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id): Application|View|JsonResponse|LenseResource
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json([
                'data' => new LenseResource($record),
            ]);
        }

        return view('system.settings.lenses.form', compact('record'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @throws \Throwable
     */
    public function update(LenseRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record        = $this->service->findByIdOrCode($id);
        $updatedRecord = $this->service->update($record, $request);
        $messageReturn = $this->getUpdateMessage($request);

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new LenseResource($updatedRecord),
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
