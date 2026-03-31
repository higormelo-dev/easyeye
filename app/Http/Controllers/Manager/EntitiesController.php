<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\EntityRequest;
use App\Models\Entity;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Inertia\Inertia;

class EntitiesController extends Controller
{
    protected string $titleController = 'Empresas';

    public function index(): \Inertia\Response
    {
        return Inertia::render('Manager/Entities/Index');
    }

    public function list(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 15;

        $entities = Entity::withTrashed()
            ->when($search, function ($q) use ($search) {
                $lower = mb_strtolower($search, 'UTF-8');
                $q->where(function ($inner) use ($lower) {
                    $inner->whereRaw('LOWER(name) LIKE ?', ["%{$lower}%"])
                          ->orWhereRaw('LOWER(code) LIKE ?', ["%{$lower}%"])
                          ->orWhereRaw('LOWER(email) LIKE ?', ["%{$lower}%"])
                          ->orWhereRaw('LOWER(city) LIKE ?', ["%{$lower}%"]);
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $data = $entities->map(fn (Entity $e) => [
            'id'        => $e->id,
            'code'      => $e->code,
            'name'      => $e->name,
            'email'     => $e->email,
            'city'      => $e->city,
            'state'     => $e->state,
            'active'    => (bool) $e->active,
            'deleted'   => ! is_null($e->deleted_at),
            'is_client' => (bool) $e->is_client,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $entities->total(),
                'per_page'     => $entities->perPage(),
                'current_page' => $entities->currentPage(),
                'last_page'    => $entities->lastPage(),
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

    public function show(string $id): \Inertia\Response|JsonResponse
    {
        $record = Entity::withTrashed()->findOrFail($id);
        $record->loadCount(['entityUsers', 'patients', 'schedules']);

        if (request()->wantsJson()) {
            return response()->json(['data' => $record]);
        }

        return Inertia::render('Manager/Entities/Show', [
            'record' => $record,
        ]);
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

    public function restore(string $id): JsonResponse
    {
        $record = Entity::withTrashed()->findOrFail($id);
        $record->restore();

        return response()->json([
            'message'  => $this->titleController . ' restaurada com sucesso.',
            'restored' => $record->fresh()->toArray(),
        ]);
    }
}
