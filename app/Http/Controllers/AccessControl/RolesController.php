<?php

declare(strict_types=1);

namespace App\Http\Controllers\AccessControl;

use App\Enums\Permission as PermissionEnum;
use App\Http\Controllers\Concerns\RedirectsToListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\{PermissionRecord, Role, SystemProfile};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * CRUD de Roles customizadas (RBAC granular ADITIVO por clínica).
 *
 * COMPLIANCE — REGRA INEGOCIÁVEL: autorização é feita EXCLUSIVAMENTE pelo
 * middleware de rota `entity.role:admin` (ver routes/web.php, bloco
 * "admin only: compliance, controle de acesso, segurança, gateways").
 * Gerenciar Roles/permissões é a "chave do cofre" do RBAC — se fosse
 * delegável via Permission customizada, um usuário não-admin poderia criar
 * uma Role com todas as permissions e se auto-atribuir (escalonamento de
 * privilégio). Por isso NENHUM método aqui usa hasPermissionInEntity() ou o
 * middleware `permission:` — só a checagem estrita de admin, no mesmo
 * padrão já usado por accesscontrol.users.
 *
 * Multi-tenancy: toda query é escopada por entity_id da sessão
 * (`selected_entity_id`), nunca por valor vindo do request. update()/
 * destroy() fazem checagem explícita de posse (abort 404) além do route
 * model binding — nunca confiar só no binding pra isolamento entre clínicas.
 */
class RolesController extends Controller
{
    use RedirectsToListing;

    /**
     * Parâmetros da listagem preservados ao voltar de criar/editar/excluir
     * (RedirectsToListing) — busca, ordenação e página.
     */
    private const LISTING_PARAMS = ['search', 'sort', 'direction', 'page'];

    /**
     * Colunas ordenáveis da listagem (whitelist) → coluna real. As contagens
     * são os aliases de withCount() no index() (nunca valor cru do request).
     */
    private const SORTABLE = [
        'name'              => 'roles.name',
        'permissions_count' => 'permissions_count',
        'users_count'       => 'entity_users_count',
        'created_at'        => 'roles.created_at',
    ];

    /** Ordem padrão = a de sempre da tela (nome A→Z). */
    private const DEFAULT_SORT = 'name';

    private const DEFAULT_DIRECTION = 'asc';

    private const PER_PAGE = 12;

    /**
     * Listagem no padrão de Panel/Patients: busca (nome/descrição, sem
     * acento), ordenação por whitelist e paginação server-side — antes a
     * tela carregava TODOS os perfis e filtrava no navegador.
     */
    public function index(Request $request): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');
        $search   = $this->queryText($request, 'search');
        $sortBy   = $this->queryText($request, 'sort', self::DEFAULT_SORT);
        $sortDir  = $this->queryText($request, 'direction', self::DEFAULT_DIRECTION);

        // Normalizados: valor fora da whitelist cai no padrão (a UI mostra o
        // que foi realmente aplicado).
        $sortBy  = array_key_exists($sortBy, self::SORTABLE) ? $sortBy : self::DEFAULT_SORT;
        $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : self::DEFAULT_DIRECTION;

        $query = Role::query()
            ->where('roles.entity_id', $entityId)
            ->with('permissions')
            ->withCount(['permissions', 'entityUsers'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $q) use ($search): void {
                    $q->whereLikeUnaccent('roles.name', $search)
                        ->orWhereLikeUnaccent('roles.description', $search);
                });
            })
            // Coluna e direção vêm só da whitelist acima.
            ->orderBy(self::SORTABLE[$sortBy], $sortDir)
            // Contagens iguais: nome A→Z, como na ordem padrão.
            ->when($sortBy !== 'name', fn (Builder $query) => $query->orderBy('roles.name'))
            // `id` desempata — sem ele a ordem entre páginas não é
            // determinística no PostgreSQL.
            ->orderBy('roles.id');

        $roles = $this->paginateClamped($query, self::PER_PAGE)
            ->withQueryString()
            // `->through()` preserva o paginator (current_page/last_page/
            // total/links) e só troca os itens — RoleResource::collection()
            // como prop perderia a paginação (ver Stock\IolLensesController).
            ->through(fn (Role $role) => (new RoleResource($role))->resolve());

        $pageTitle = __('access_control_roles.page_title');

        return Inertia::render('Panel/AccessControl/Roles/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.access_control'), 'url' => route('panel.accesscontrol.users.index'), 'active' => false],
                ['label' => $pageTitle, 'url' => '#', 'active' => true],
            ],
            'roles'   => $roles,
            'filters' => [
                'search'    => $search,
                'sort'      => $sortBy,
                'direction' => $sortDir,
            ],
            // Perfis FIXOS da plataforma — pré-definidos pelo dono do SaaS
            // (tabela system_profiles, fallback hardcoded), somente leitura
            // na tela. O perfil de cada usuário é escolhido no cadastro de
            // usuários; as Roles customizadas acima são apenas a camada
            // ADITIVA administrativa.
            'systemProfiles'       => SystemProfile::catalogFor(SystemProfile::CONTEXT_CLIENT),
            'availablePermissions' => $this->groupedAvailablePermissions(),
            't'                    => trans('access_control_roles'),
            'routes'               => [
                'index' => route('panel.accesscontrol.roles.index'),
                'store' => route('panel.accesscontrol.roles.store'),
                // Vue substitui {id} no client — mesma convenção usada nos
                // catálogos de Setting (ver BaseSettingController::index()).
                'update'  => route('panel.accesscontrol.roles.update', ['__ID__']),
                'destroy' => route('panel.accesscontrol.roles.destroy', ['__ID__']),
            ],
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse|JsonResponse
    {
        $entityId = session('selected_entity_id');

        $role = DB::transaction(function () use ($request, $entityId) {
            $role = Role::query()->create([
                'entity_id'   => $entityId,
                'name'        => $request->validated('name'),
                'description' => $request->validated('description'),
            ]);

            $role->permissions()->sync($request->input('permission_ids', []));

            return $role;
        });

        $role->load('permissions')->loadCount('entityUsers');

        $message = __('access_control_roles.flash_created');

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'data'    => new RoleResource($role),
            ]);
        }

        return $this->redirectToListing('panel.accesscontrol.roles.index', self::LISTING_PARAMS)
            ->with('message', $message);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse|JsonResponse
    {
        $entityId = session('selected_entity_id');

        // Isolamento multi-tenant: nunca confiar só no route model binding —
        // uma Role de outra clínica não pode ser editada mesmo que o id seja
        // adivinhado/enumerado na URL.
        abort_unless((string) $role->entity_id === (string) $entityId, 404);

        DB::transaction(function () use ($request, $role) {
            $role->update([
                'name'        => $request->validated('name'),
                'description' => $request->validated('description'),
            ]);

            $role->permissions()->sync($request->input('permission_ids', []));
        });

        $role->load('permissions')->loadCount('entityUsers');

        $message = __('access_control_roles.flash_updated');

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'data'    => new RoleResource($role),
            ]);
        }

        return $this->redirectToListing('panel.accesscontrol.roles.index', self::LISTING_PARAMS)
            ->with('message', $message);
    }

    public function destroy(Request $request, Role $role): RedirectResponse|JsonResponse
    {
        $entityId = session('selected_entity_id');

        abort_unless((string) $role->entity_id === (string) $entityId, 404);

        DB::transaction(function () use ($role): void {
            // Role usa SoftDeletes: o ON DELETE CASCADE definido na FK
            // entity_user_role.role_id só dispara em hard delete (delete()
            // aqui é um UPDATE de deleted_at). Removemos os vínculos
            // explicitamente para não deixar entity_user_role "órfão"
            // apontando pra uma Role soft-deleted — mesmo que
            // hasPermissionInEntity() já ignore roles soft-deleted
            // automaticamente (global scope do Eloquent em entityUser->roles()).
            $role->entityUsers()->detach();

            $role->delete();
        });

        $message = __('access_control_roles.flash_deleted');

        if ($request->wantsJson()) {
            return response()->json(['message' => $message]);
        }

        return $this->redirectToListing('panel.accesscontrol.roles.index', self::LISTING_PARAMS)
            ->with('message', $message);
    }

    /**
     * Permissions do enum App\Enums\Permission agrupadas por grupo (rótulos
     * no idioma do usuário — localizedGroup()/localizedLabel()), com o
     * id do PermissionRecord correspondente — pra montar a matriz de
     * checkbox no frontend (grupo -> [{id, key, label}]).
     *
     * @return list<array{group: string, items: list<array{id: ?string, key: string, label: string}>}>
     */
    private function groupedAvailablePermissions(): array
    {
        $records = PermissionRecord::query()->get()->keyBy('key');

        return collect(PermissionEnum::cases())
            ->groupBy(fn (PermissionEnum $permission) => $permission->localizedGroup())
            ->map(fn ($permissions, $group) => [
                'group' => $group,
                'items' => $permissions->map(fn (PermissionEnum $permission) => [
                    'id'    => $records->get($permission->value)?->id,
                    'key'   => $permission->value,
                    'label' => $permission->localizedLabel(),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Pagina e, se a página pedida passou da última (ex.: excluiu o único
     * perfil da última página e o redirect manteve ?page=N), usa a última
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
     * conversion" (500).
     */
    private function queryText(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_string($value) ? trim($value) : $default;
    }
}
