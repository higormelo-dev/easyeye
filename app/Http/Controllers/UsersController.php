<?php

namespace App\Http\Controllers;

use App\DTOs\ActionPolicy;
use App\Http\Requests\EntityUserRequest;
use App\Http\Resources\EntityUserResource;
use App\Models\{EntityUser, Role, SystemProfile, User};
use App\Services\EntityUserService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\{DB, Storage, Vite};
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response};

class UsersController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected EntityUser $model;

    protected EntityUserService $service;

    public function __construct(EntityUser $entityUser, EntityUserService $entityUserService)
    {
        $this->titleController = __('actions.users');
        $this->model           = $entityUser;
        $this->service         = $entityUserService;
    }

    /**
     * Return paginated JSON for the card view.
     */
    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $entityUsers = EntityUser::query()
            ->withTrashed()
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->where('entity_users.entity_id', session()->get('selected_entity_id'))
            ->whereNot('entity_users.rule', 'doctor')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->whereLikeUnaccent('users.name', $search)
                        ->orWhereLikeUnaccent('users.email', $search);
                });
            })
            ->select('entity_users.*', 'users.name', 'users.email')
            ->orderBy('entity_users.created_at', 'desc')
            ->paginate($perPage);

        $isClient = session()->get('selected_entity_is_client');
        $entityId = session()->get('selected_entity_id');
        $rolesMap = SystemProfile::labelMap($isClient ? SystemProfile::CONTEXT_CLIENT : SystemProfile::CONTEXT_SAAS);

        $data = $entityUsers->map(function (EntityUser $eu) use ($rolesMap, $entityId) {
            $userPhotoPath = 'users/' . $eu->user_id . '.jpg';

            return [
                'id'         => $eu->id,
                'full_name'  => $eu->name,
                'email'      => $eu->email,
                'rule_label' => $rolesMap[$eu->rule] ?? $eu->rule,
                'photo_url'  => Storage::disk('public')->exists($userPhotoPath)
                    ? Storage::disk('public')->url($userPhotoPath)
                    : Vite::asset('resources/img/system/team.png'),
                'is_owner' => (bool) $eu->is_owner,
                'is_self'  => $eu->user_id === auth()->id(),
                ...ActionPolicy::from($eu, $entityId)->toArray(),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $entityUsers->total(),
                'per_page'     => $entityUsers->perPage(),
                'current_page' => $entityUsers->currentPage(),
                'last_page'    => $entityUsers->lastPage(),
            ],
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        $entityId = session('selected_entity_id');
        $isClient = (bool) session('selected_entity_is_client');
        $search   = $request->string('search')->trim()->value();
        $sortBy   = $request->string('sort', 'created_at')->value();
        $sortDir  = $request->string('direction', 'desc')->value();

        $allowedSorts = ['created_at', 'name', 'email', 'rule'];
        $sortBy       = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';
        $sortDir      = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $rolesMap = SystemProfile::labelMap($isClient ? SystemProfile::CONTEXT_CLIENT : SystemProfile::CONTEXT_SAAS);

        $query = EntityUser::query()
            ->withTrashed()
            ->select('entity_users.*', 'users.name', 'users.email')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->where('entity_users.entity_id', $entityId)
            ->whereNot('entity_users.rule', 'doctor');

        if ($search !== '') {
            $query->where(
                fn ($q) => $q
                    ->whereLikeUnaccent('users.name', $search)
                    ->orWhereLikeUnaccent('users.email', $search),
            );
        }

        $dbCol = match ($sortBy) {
            'name'  => 'users.name',
            'email' => 'users.email',
            'rule'  => 'entity_users.rule',
            default => 'entity_users.created_at',
        };
        $query->orderBy($dbCol, $sortDir);

        $users = $query->paginate(15)->withQueryString();

        return Inertia::render('Panel/Users/Index', [
            'users'    => $users->through(fn ($eu) => $this->toTableRow($eu, $entityId, $rolesMap)),
            'total'    => fn () => EntityUser::where('entity_id', $entityId)->whereNot('rule', 'doctor')->count(),
            'roles'    => $rolesMap,
            'isClient' => $isClient,
            'filters'  => $request->only(['search', 'sort', 'direction']),
            't'        => trans('access_control'),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     * Redirect to index — create is now handled inline via offcanvas modal.
     */
    public function create(): RedirectResponse
    {
        return redirect()->route('panel.accesscontrol.users.index');
    }

    private function toTableRow(EntityUser $eu, string $entityId, array $rolesMap): array
    {
        $policy        = ActionPolicy::from($eu, $entityId);
        $userPhotoPath = 'users/' . $eu->user_id . '.jpg';

        return [
            'id'         => $eu->id,
            'user_id'    => $eu->user_id,
            'name'       => $eu->name,
            'email'      => $eu->email,
            'rule'       => $eu->rule,
            'rule_label' => $rolesMap[$eu->rule] ?? $eu->rule,
            'active'     => (bool) $eu->active,
            'deleted_at' => $eu->deleted_at,
            'created_at' => $eu->created_at?->format('d/m/Y'),
            'photo_url'  => Storage::disk('public')->exists($userPhotoPath)
                ? Storage::disk('public')->url($userPhotoPath)
                : Vite::asset('resources/img/system/team.png'),
            'mode'     => $policy->mode,
            'deleted'  => $policy->deleted,
            'is_owner' => (bool) $eu->is_owner,
            'is_self'  => $eu->user_id === auth()->id(),
        ];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(EntityUserRequest $request): Application|RedirectResponse|Redirector|JsonResponse|EntityUserResource
    {
        $record = $this->service->create($request);

        $messageReturn = $this->getCreateMessage();

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new EntityUserResource($record),
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Display the specified resource (JSON only — UI via Vue/Inertia).
     */
    public function show(string $id): JsonResponse|RedirectResponse
    {
        if (! request()->wantsJson()) {
            return redirect()->route('panel.accesscontrol.users.index');
        }

        $record = $this->service->findByIdOrCode($id);
        $record->loadMissing('user');

        return response()->json([
            'data' => [
                'id'     => $record->id,
                'name'   => $record->user?->name ?? '',
                'email'  => $record->user?->email ?? '',
                'rule'   => $record->rule,
                'active' => (bool) $record->active,
            ],
        ]);
    }

    /**
     * Return user data for the edit modal (JSON only — UI via Vue/Inertia).
     */
    public function edit(string $id): JsonResponse|RedirectResponse
    {
        if (! request()->wantsJson()) {
            return redirect()->route('panel.accesscontrol.users.index');
        }

        $record = $this->service->findByIdOrCode($id);
        $record->loadMissing('roles');

        return response()->json([
            'data' => new EntityUserResource($record),
            // Perfis customizados (RBAC granular ADITIVO) — alimenta o
            // multi-select "Perfis adicionais" no form de edição de usuário.
            'role_ids' => $record->roles->pluck('id')->values()->all(),
            'roles'    => Role::query()
                ->where('entity_id', session('selected_entity_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Sincroniza os perfis customizados (Role) atribuídos a este EntityUser.
     *
     * Endpoint dedicado (em vez de acoplar em update()) porque update() já
     * serve o formulário base de edição (rule/active) via EntityUserRequest
     * + EntityUserService, compartilhado com o fluxo de criação — misturar
     * role_ids ali obrigaria alterar uma Request/Service usados por outro
     * fluxo, risco desnecessário pra uma feature nova e isolada. Um PATCH
     * dedicado mantém o blast radius restrito a este método.
     *
     * Multi-tenant: role_ids validados via Rule::exists escopado à entity da
     * sessão (nunca confia em id de request) + findByIdOrCode() já escopa o
     * EntityUser-alvo à mesma entity.
     */
    public function updateRoles(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $entityId = session('selected_entity_id');

        $request->validate([
            'role_ids'   => ['array'],
            'role_ids.*' => [
                'string',
                Rule::exists('roles', 'id')->where(
                    fn ($query) => $query->where('entity_id', $entityId)->whereNull('deleted_at'),
                ),
            ],
        ]);

        $record = $this->service->findByIdOrCode($id);

        DB::transaction(function () use ($record, $request): void {
            $record->roles()->sync($request->input('role_ids', []));
        });

        $record->load('roles');
        $messageReturn = 'Perfis do usuário atualizados com sucesso.';

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => [
                    'role_ids' => $record->roles->pluck('id')->values()->all(),
                ],
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EntityUserRequest $request, string $id): Application|JsonResponse|Redirector|RedirectResponse|EntityUserResource
    {
        $record        = $this->service->findByIdOrCode($id);
        $updatedRecord = $this->service->update($record, $request);
        $messageReturn = $this->getUpdateMessage($request);

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new EntityUserResource($updatedRecord),
            ]);
        }

        return redirect(action('\\' . static::class . '@index'))
            ->with('message', $messageReturn);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): Application|View|JsonResponse|RedirectResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if ($record->is_owner) {
            abort(403, trans('access_control.owner_protected'));
        }

        if ($record->user_id === auth()->id()) {
            abort(403, trans('access_control.self_protected'));
        }

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
    public function restore(string $id): Application|View|JsonResponse|RedirectResponse
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
