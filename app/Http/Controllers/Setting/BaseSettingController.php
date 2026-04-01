<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\BaseDataTable;
use App\Http\Controllers\Controller;
use App\Services\BaseSettingService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;

abstract class BaseSettingController extends Controller
{
    protected BaseSettingService $service;

    protected BaseDataTable $dataTable;

    protected string $resourceClass;

    protected string $routePrefix;

    protected string $jsFile;

    protected string $viewSlot = '';

    protected array $crudFields = ['name' => '', 'active' => true];

    protected string $baseUrl = '';

    public function index(): Factory|Application|View|JsonResponse
    {
        $meta = $this->buildMeta();

        return $this->dataTable->render('system.settings.index', array_merge(
            compact('meta'),
            $this->viewData(),
            ['jsFile' => $this->jsFile]
        ));
    }

    public function cards(Request $request): JsonResponse
    {
        $class   = $this->service->getModelClass();
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $records = $class::query()
            ->where(fn ($q) => $q
                ->where('entity_id', session('selected_entity_id'))
                ->orWhereNull('entity_id')
            )
            ->when($search, fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search, 'UTF-8') . '%']))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'active' => (bool) $r->active]),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    public function create(): Factory|Application|View
    {
        return view('system.settings.form', $this->viewData());
    }

    public function show(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json(['data' => $record->only(array_keys($this->crudFields))]);
        }

        return view('system.settings.show', array_merge($this->viewData(), compact('record')));
    }

    public function edit(string $id): Application|View|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if (request()->wantsJson()) {
            return response()->json(['data' => new $this->resourceClass($record)]);
        }

        return view('system.settings.form', array_merge($this->viewData(), compact('record')));
    }

    public function destroy(string $id): Application|View|JsonResponse|RedirectResponse|Redirector
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($record) {
            $message    = $this->getDeleteMessage();
            $recordData = $record->toArray();
            $record->delete();

            if (request()->wantsJson()) {
                return response()->json(['message' => $message, 'deleted' => $recordData]);
            }

            return redirect(action('\\' . static::class . '@index'))->with('message', $message);
        });
    }

    public function restore(string $id): Application|View|JsonResponse|RedirectResponse|Redirector
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($record) {
            $message    = $this->getRestoreMessage();
            $recordData = $record->toArray();
            $record->restore();

            if (request()->wantsJson()) {
                return response()->json(['message' => $message, 'restored' => $recordData]);
            }

            return redirect(action('\\' . static::class . '@index'))->with('message', $message);
        });
    }

    protected function genericStore(FormRequest $request): Application|RedirectResponse|Redirector|JsonResponse
    {
        $record        = $this->service->create($request);
        $messageReturn = $this->getCreateMessage();

        if ($request->wantsJson()) {
            return response()->json(['message' => $messageReturn, 'data' => new $this->resourceClass($record)]);
        }

        return redirect(action('\\' . static::class . '@index'))->with('message', $messageReturn);
    }

    protected function genericUpdate(FormRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record        = $this->service->findByIdOrCode($id);
        $updated       = $this->service->update($record, $request);
        $messageReturn = $this->getUpdateMessage($request);

        if ($request->wantsJson()) {
            return response()->json(['message' => $messageReturn, 'data' => new $this->resourceClass($updated)]);
        }

        return redirect(action('\\' . static::class . '@index'))->with('message', $messageReturn);
    }

    protected function viewData(): array
    {
        return [
            'viewSlot'        => $this->viewSlot,
            'titleController' => $this->titleController,
            'storeUrl'        => route($this->routePrefix . '.store'),
            'baseUrl'         => $this->baseUrl,
            'crudFields'      => $this->crudFields,
            'storageKey'      => $this->viewSlot . '_view',
        ];
    }

    protected function buildMeta(): array
    {
        return [
            'title'             => $this->titleController,
            'total'             => $this->service->count(),
            'cardsUrl'          => route($this->routePrefix . '.cards'),
            'breadcrumb_title'  => false,
            'action'            => __('actions.records'),
            'breadcrumbs'       => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'),                         'active' => false],
                ['label' => $this->titleController,           'url' => route($this->routePrefix . '.index'), 'active' => false],
                ['label' => __('actions.records'),            'url' => 'javascript:void(0);',                'active' => true],
            ],
        ];
    }
}
