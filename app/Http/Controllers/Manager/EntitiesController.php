<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\EntitiesDataTable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\EntityRequest;
use App\Models\Entity;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;

class EntitiesController extends Controller
{
    protected string $titleController = 'Empresas';

    public function index(EntitiesDataTable $dataTable): Factory|Application|View|JsonResponse
    {
        $total = Entity::withTrashed()->where('code', '!=', 'ENT-0000000001')->count();

        $meta = [
            'title'            => $this->titleController,
            'action'           => __('actions.records'),
            'total'            => $total,
            'cardsUrl'         => route('panel.manager.entities.cards'),
            'breadcrumb_title' => false,
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => $this->titleController, 'url' => route('panel.manager.entities.index'), 'active' => true],
            ],
        ];

        $storeUrl = route('panel.manager.entities.store');
        $baseUrl  = url('panel/manager/entities');

        return $dataTable->render('system.manager.entities.index', compact('meta', 'storeUrl', 'baseUrl'));
    }

    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $records = Entity::withTrashed()
            ->where('code', '!=', 'ENT-0000000001')
            ->when($search, fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search, 'UTF-8') . '%']))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->map(fn ($r) => [
                'id'      => $r->id,
                'code'    => $r->code,
                'name'    => $r->name,
                'city'    => $r->city,
                'state'   => $r->state,
                'active'  => (bool) $r->active,
                'deleted' => (bool) $r->deleted_at,
            ]),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    public function store(EntityRequest $request): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record = Entity::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $this->titleController . ' cadastrada com sucesso.',
                'data'    => $record->toArray(),
            ]);
        }

        return redirect(route('panel.manager.entities.index'))
            ->with('message', $this->titleController . ' cadastrada com sucesso.');
    }

    public function show(string $id): View|JsonResponse
    {
        $record = Entity::withTrashed()->findOrFail($id);

        return view('system.manager.entities.show', compact('record'));
    }

    public function editData(string $id): JsonResponse
    {
        $record = Entity::withTrashed()->findOrFail($id);

        return response()->json(['data' => [
            'name'                   => $record->name,
            'subdomain'              => $record->subdomain,
            'email'                  => $record->email,
            'telephone'              => $record->telephone,
            'cellphone'              => $record->cellphone,
            'national_registration'  => $record->national_registration,
            'state_registration'     => $record->state_registration,
            'municipal_registration' => $record->municipal_registration,
            'website'                => $record->website,
            'zipcode'                => $record->zipcode,
            'address'                => $record->address,
            'number'                 => $record->number,
            'complement'             => $record->complement,
            'district'               => $record->district,
            'city'                   => $record->city,
            'state'                  => $record->state,
            'country'                => $record->country,
            'schedule_interval'      => $record->schedule_interval,
            'active'                 => (bool) $record->active,
        ]]);
    }

    public function update(EntityRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record = Entity::findOrFail($id);
        $record->update($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $this->titleController . ' atualizada com sucesso.',
                'data'    => $record->fresh()->toArray(),
            ]);
        }

        return redirect(route('panel.manager.entities.index'))
            ->with('message', $this->titleController . ' atualizada com sucesso.');
    }

    public function destroy(string $id): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record = Entity::findOrFail($id);
        $record->delete();

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $this->titleController . ' removida com sucesso.',
                'deleted' => $record->toArray(),
            ]);
        }

        return redirect(route('panel.manager.entities.index'))
            ->with('message', $this->titleController . ' removida com sucesso.');
    }
}
