<?php

namespace App\Http\Controllers\Manager;

use App\DTOs\ActionPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\EntityRequest;
use App\Models\Entity;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Inertia\{Inertia, Response};

class EntitiesController extends Controller
{
    protected string $titleController = 'Empresas';

    public function index(Request $request): Response
    {
        $search  = $request->string('search')->trim()->value();
        $sortBy  = $request->string('sort', 'created_at')->value();
        $sortDir = $request->string('direction', 'desc')->value();

        $allowedSorts = ['created_at', 'code', 'name', 'city', 'state', 'active'];
        $sortBy       = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';
        $sortDir      = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $query = Entity::withTrashed()
            ->select('entities.*')
            ->selectRaw(
                '(SELECT COUNT(*) FROM entity_users WHERE entity_users.entity_id = entities.id AND entity_users.deleted_at IS NULL) as entity_users_count',
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM entity_user_integrators WHERE entity_user_integrators.entity_id = entities.id AND entity_user_integrators.deleted_at IS NULL) as entity_user_integrators_count',
            )
            ->where('code', '!=', 'ENT-0000000001');

        if ($search !== '') {
            $lower = mb_strtolower($search, 'UTF-8');
            $query->where(
                fn ($q) => $q
                    ->whereRaw('LOWER(entities.name) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(entities.code) LIKE ?', ["%{$lower}%"])
                    ->orWhereRaw('LOWER(entities.city) LIKE ?', ["%{$lower}%"]),
            );
        }

        $query->orderBy("entities.{$sortBy}", $sortDir);
        $entities = $query->paginate(15)->withQueryString();

        return Inertia::render('Panel/Manager/Entities/Index', [
            'entities' => $entities->through(fn ($e) => $this->toTableRow($e)),
            'total'    => fn () => Entity::withTrashed()->where('code', '!=', 'ENT-0000000001')->count(),
            'filters'  => $request->only(['search', 'sort', 'direction']),
            't'        => trans('manager_entities'),
        ]);
    }

    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $records = Entity::withTrashed()
            ->select('entities.*')
            ->selectRaw(
                '(SELECT COUNT(*) FROM entity_users WHERE entity_users.entity_id = entities.id AND entity_users.deleted_at IS NULL) as entity_users_count',
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM entity_user_integrators WHERE entity_user_integrators.entity_id = entities.id AND entity_user_integrators.deleted_at IS NULL) as entity_user_integrators_count',
            )
            ->where('code', '!=', 'ENT-0000000001')
            ->when($search, fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search, 'UTF-8') . '%']))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->map(fn ($r) => [
                'id'                            => $r->id,
                'code'                          => $r->code,
                'name'                          => $r->name,
                'city'                          => $r->city,
                'state'                         => $r->state,
                'entity_users_count'            => $r->entity_users_count,
                'entity_user_integrators_count' => $r->entity_user_integrators_count,
                'users_url'                     => route('manager.entities.users', $r->id),
                'user_integrators_url'          => route('manager.entities.user-integrators.index', $r->id),
                ...ActionPolicy::forManager($r)->toArray(),
            ]),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $record = Entity::withTrashed()->findOrFail($id);

        return response()->json(['data' => [
            'id'                     => $record->id,
            'code'                   => $record->code,
            'name'                   => $record->name,
            'subdomain'              => $record->subdomain,
            'email'                  => $record->email,
            'telephone'              => $record->telephone,
            'cellphone'              => $record->cellphone,
            'website'                => $record->website,
            'national_registration'  => $record->national_registration,
            'state_registration'     => $record->state_registration,
            'municipal_registration' => $record->municipal_registration,
            'schedule_interval'      => $record->schedule_interval,
            'zipcode'                => $record->zipcode,
            'address'                => $record->address,
            'number'                 => $record->number,
            'complement'             => $record->complement,
            'district'               => $record->district,
            'city'                   => $record->city,
            'state'                  => $record->state,
            'country'                => $record->country,
            'active'                 => (bool) $record->active,
            'deleted_at'             => $record->deleted_at?->format('d/m/Y H:i'),
            'created_at'             => $record->created_at?->format('d/m/Y H:i'),
        ]]);
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

    public function store(EntityRequest $request): Application|JsonResponse|Redirector|RedirectResponse
    {
        $record = Entity::create($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $this->titleController . ' cadastrada com sucesso.',
                'data'    => $record->toArray(),
            ]);
        }

        return redirect(route('manager.entities.index'))
            ->with('success', $this->titleController . ' cadastrada com sucesso.');
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

        return redirect(route('manager.entities.index'))
            ->with('success', $this->titleController . ' atualizada com sucesso.');
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

        return redirect(route('manager.entities.index'))
            ->with('success', $this->titleController . ' removida com sucesso.');
    }

    private function toTableRow(Entity $e): array
    {
        $policy = ActionPolicy::forManager($e);

        return [
            'id'                            => $e->id,
            'code'                          => $e->code,
            'name'                          => $e->name,
            'city'                          => $e->city,
            'state'                         => $e->state,
            'email'                         => $e->email,
            'is_client'                     => $e->is_client,
            'active'                        => (bool) $e->active,
            'deleted_at'                    => $e->deleted_at,
            'created_at'                    => $e->created_at?->format('d/m/Y'),
            'entity_users_count'            => (int) $e->entity_users_count,
            'entity_user_integrators_count' => (int) $e->entity_user_integrators_count,
            'users_url'                     => route('manager.entities.users', $e->id),
            'user_integrators_url'          => route('manager.entities.user-integrators.index', $e->id),
            'mode'                          => $policy->mode,
            'deleted'                       => $policy->deleted,
        ];
    }
}
