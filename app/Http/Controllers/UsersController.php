<?php

namespace App\Http\Controllers;

use App\DTOs\ActionPolicy;
use App\Http\Controllers\Concerns\RedirectsToListing;
use App\Http\Requests\EntityUserRequest;
use App\Http\Resources\EntityUserResource;
use App\Models\{DoctorInvitation, EntityUser, EntityUserInvitation, Role, SystemProfile};
use App\Services\{EntityUserService, UserInvitationService};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{DB, Storage, Vite};
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response};

/**
 * Usuários (vínculos EntityUser) da clínica selecionada — admin only (rota
 * `entity.role:admin`). Médicos têm tela própria (DoctorsController) e ficam
 * fora desta listagem.
 *
 * Multi-tenancy: toda query é escopada por entity_id da sessão; show/edit/
 * update/destroy/restore resolvem o registro via
 * EntityUserService::findByIdOrCode(), que também escopa pela sessão.
 */
class UsersController extends Controller
{
    use RedirectsToListing;

    /**
     * Parâmetros da listagem preservados ao voltar de criar/editar/ativar/
     * excluir/restaurar (RedirectsToListing) — busca, ordenação e página.
     */
    private const LISTING_PARAMS = ['search', 'sort', 'direction', 'page'];

    /** Colunas ordenáveis da listagem (whitelist) → coluna real no banco. */
    private const SORTABLE = [
        'created_at' => 'entity_users.created_at',
        'name'       => 'users.name',
        'email'      => 'users.email',
        'rule'       => 'entity_users.rule',
    ];

    /** Ordem padrão = a de sempre da tela (cadastro mais recente primeiro). */
    private const DEFAULT_SORT = 'created_at';

    private const DEFAULT_DIRECTION = 'desc';

    private const PER_PAGE = 12;

    /**
     * Instance of the standard model.
     */
    protected EntityUser $model;

    protected EntityUserService $service;

    public function __construct(EntityUser $entityUser, EntityUserService $entityUserService)
    {
        $this->model   = $entityUser;
        $this->service = $entityUserService;
    }

    /**
     * Listagem no padrão de Panel/Patients: busca (nome/e-mail, sem acento),
     * ordenação por whitelist e paginação server-side — a MESMA página
     * alimenta a tabela e os cards (antes os cards buscavam um endpoint JSON
     * à parte, sem ordenação e sem tratar erro).
     */
    public function index(Request $request): Response
    {
        $entityId = (string) session('selected_entity_id');
        $isClient = (bool) session('selected_entity_is_client');
        $search   = $this->queryText($request, 'search');
        $sortBy   = $this->queryText($request, 'sort', self::DEFAULT_SORT);
        $sortDir  = $this->queryText($request, 'direction', self::DEFAULT_DIRECTION);

        // Normalizados: valor fora da whitelist cai no padrão (a UI mostra o
        // que foi realmente aplicado).
        $sortBy  = array_key_exists($sortBy, self::SORTABLE) ? $sortBy : self::DEFAULT_SORT;
        $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : self::DEFAULT_DIRECTION;

        $rolesMap = SystemProfile::labelMap($isClient ? SystemProfile::CONTEXT_CLIENT : SystemProfile::CONTEXT_SAAS);

        // select() ANTES de withCount(): select() depois substituiria as
        // colunas e descartaria a contagem. Removidos (soft delete) continuam
        // na lista para poder restaurar.
        $query = EntityUser::query()
            ->withTrashed()
            ->select('entity_users.*', 'users.name', 'users.email')
            ->join('users', 'entity_users.user_id', '=', 'users.id')
            ->where('entity_users.entity_id', $entityId)
            ->whereNot('entity_users.rule', 'doctor')
            // Perfis customizados (RBAC aditivo) de cada usuário — só a
            // contagem, para o selo "+N perfis adicionais".
            ->withCount('roles')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $q) use ($search): void {
                    $q->whereLikeUnaccent('users.name', $search)
                        ->orWhereLikeUnaccent('users.email', $search);
                });
            })
            // Coluna e direção vêm só da whitelist acima.
            ->orderBy(self::SORTABLE[$sortBy], $sortDir)
            // `id` desempata — sem ele a ordem entre páginas não é
            // determinística no PostgreSQL.
            ->orderBy('entity_users.id');

        $users = $this->paginateClamped($query, self::PER_PAGE)
            ->withQueryString()
            ->through(fn (EntityUser $eu) => $this->toTableRow($eu, $entityId, $rolesMap));

        return Inertia::render('Panel/Users/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.access_control'), 'url' => '#', 'active' => false],
                ['label' => __('access_control.page_title'), 'url' => '#', 'active' => true],
            ],
            'users'    => $users,
            'roles'    => $rolesMap,
            'isClient' => $isClient,
            'filters'  => [
                'search'    => $search,
                'sort'      => $sortBy,
                'direction' => $sortDir,
            ],
            't' => trans('access_control'),
            // Convite a quem já tem login no EasyEye (só clínicas-cliente).
            // Lista o e-mail DIGITADO exista conta ou não — nada revela quem
            // tem acesso (UserInvitationService).
            'invitableRoles'     => $isClient ? array_intersect_key($rolesMap, array_flip(UserInvitationService::invitableRules())) : [],
            'pendingInvitations' => fn () => $isClient ? EntityUserInvitation::query()
                ->where('entity_id', $entityId)
                // Recusado continua "pendente" até vencer: a recusa (só quem
                // tem conta recusa) não pode revelar que a conta existe.
                ->whereIn('status', [DoctorInvitation::STATUS_PENDING, DoctorInvitation::STATUS_DECLINED])
                ->where('expires_at', '>', now())
                ->latest('updated_at')
                ->get()
                ->map(fn (EntityUserInvitation $invitation): array => [
                    'id'         => $invitation->id,
                    'email'      => $invitation->email,
                    'rule'       => $rolesMap[$invitation->rule] ?? $invitation->rule,
                    'sent_at'    => $invitation->updated_at?->toIso8601String(),
                    'expires_at' => $invitation->expires_at?->toIso8601String(),
                ])
                ->values() : [],
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
            'id'          => $eu->id,
            'user_id'     => $eu->user_id,
            'name'        => $eu->name,
            'email'       => $eu->email,
            'rule'        => $eu->rule,
            'rule_label'  => $rolesMap[$eu->rule] ?? $eu->rule,
            'roles_count' => (int) ($eu->roles_count ?? 0),
            'active'      => (bool) $eu->active,
            'deleted_at'  => $eu->deleted_at,
            // ISO 8601: a tela formata no idioma do usuário (useLocaleFormat).
            'created_at' => $eu->created_at?->toIso8601String(),
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
    public function store(EntityUserRequest $request): RedirectResponse|JsonResponse
    {
        $record = $this->service->create($request);

        $messageReturn = __('access_control.flash_created');

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new EntityUserResource($record),
            ]);
        }

        return $this->backToListing($messageReturn);
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
        // Chamado pelo modal logo depois de salvar os dados do usuário: para
        // quem usa a tela, é a mesma ação "salvar usuário".
        $messageReturn = __('access_control.flash_updated');

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => [
                    'role_ids' => $record->roles->pluck('id')->values()->all(),
                ],
            ]);
        }

        return $this->backToListing($messageReturn);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(EntityUserRequest $request, string $id): RedirectResponse|JsonResponse
    {
        $record        = $this->service->findByIdOrCode($id);
        $updatedRecord = $this->service->update($record, $request);

        // Ativar/desativar pela listagem (type_method=toggle) x formulário.
        $messageReturn = match (true) {
            ! $request->has('type_method') => __('access_control.flash_updated'),
            $request->boolean('active')    => __('access_control.flash_activated'),
            default                        => __('access_control.flash_deactivated'),
        };

        if (request()->wantsJson()) {
            return response()->json([
                'message' => $messageReturn,
                'data'    => new EntityUserResource($updatedRecord),
            ]);
        }

        return $this->backToListing($messageReturn);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): RedirectResponse|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        if ($record->is_owner) {
            abort(403, trans('access_control.owner_protected'));
        }

        if ($record->user_id === auth()->id()) {
            abort(403, trans('access_control.self_protected'));
        }

        return DB::transaction(function () use ($record) {
            $messageReturn = __('access_control.flash_deleted');
            $recordData    = $record->toArray();
            $record->delete();

            // Retornar resposta
            if (request()->wantsJson()) {
                return response()->json([
                    'message' => $messageReturn,
                    'deleted' => $recordData,
                ]);
            }

            return $this->backToListing($messageReturn);
        });
    }

    /**
     * Restore the specified resource from storage.
     *
     * Rota PATCH (antes GET): restaurar devolve o acesso à clínica, e um GET
     * passa sem token CSRF — bastava o admin logado abrir um link preparado
     * por um usuário removido para ele recuperar o acesso.
     */
    public function restore(string $id): RedirectResponse|JsonResponse
    {
        $record = $this->service->findByIdOrCode($id);

        return DB::transaction(function () use ($record) {
            $messageReturn = __('access_control.flash_restored');
            $recordData    = $record->toArray();
            $record->restore();

            // Retornar resposta
            if (request()->wantsJson()) {
                return response()->json([
                    'message'  => $messageReturn,
                    'restored' => $recordData,
                ]);
            }

            return $this->backToListing($messageReturn);
        });
    }

    /** Volta para a listagem mantendo busca/ordenação/página, com a mensagem. */
    private function backToListing(string $message): RedirectResponse
    {
        return $this->redirectToListing('panel.accesscontrol.users.index', self::LISTING_PARAMS)
            ->with('message', $message);
    }

    /**
     * Pagina e, se a página pedida passou da última (ex.: excluiu o único
     * usuário da última página e o redirect manteve ?page=N), usa a última
     * válida — mesmo padrão de DoctorsController::paginateClamped().
     */
    private function paginateClamped(Builder $query, int $perPage): LengthAwarePaginator
    {
        $paginator = (clone $query)->paginate($perPage);

        if ($paginator->isEmpty() && $paginator->currentPage() > 1 && $paginator->lastPage() >= 1) {
            $paginator = $query->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        return $paginator;
    }

    /**
     * Parâmetro de query como texto (trim). Valor não textual (ex.:
     * `?sort[]=x`) vira o padrão em vez de estourar "Array to string
     * conversion" (500) em `$request->string()`.
     */
    private function queryText(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_string($value) ? trim($value) : $default;
    }
}
