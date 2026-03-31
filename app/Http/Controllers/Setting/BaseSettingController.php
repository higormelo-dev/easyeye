<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\BaseDataTable;
use App\Http\Controllers\Controller;
use App\Services\BaseSettingService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\{JsonResponse, RedirectResponse};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

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

    protected string $inertiaPage = '';

    public function index(): Factory|Application|View|JsonResponse|\Inertia\Response
    {
        if (request()->wantsJson() && !request()->hasHeader('X-Inertia')) {
            $meta = $this->buildMeta();

            return $this->dataTable->render('system.settings.index', array_merge(
                compact('meta'),
                $this->viewData(),
                ['jsFile' => $this->jsFile]
            ));
        }

        return Inertia::render('Settings/' . $this->inertiaPage, [
            'records' => $this->resourceClass::collection($this->service->all()),
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
        ];
    }

    private function buildMeta(): array
    {
        return [
            'title'       => $this->titleController,
            'action'      => __('actions.records'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'),                         'active' => false],
                ['label' => $this->titleController,           'url' => route($this->routePrefix . '.index'), 'active' => false],
                ['label' => __('actions.records'),            'url' => 'javascript:void(0);',                'active' => true],
            ],
        ];
    }
}
