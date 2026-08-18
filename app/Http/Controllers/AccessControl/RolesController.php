<?php

declare(strict_types=1);

namespace App\Http\Controllers\AccessControl;

use App\Enums\Permission as PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\{PermissionRecord, Role};
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
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
    public function __construct()
    {
        $this->titleController = 'Perfil de acesso';
    }

    public function index(): InertiaResponse
    {
        $entityId = session('selected_entity_id');

        $roles = Role::query()
            ->where('entity_id', $entityId)
            ->with('permissions')
            ->withCount('entityUsers')
            ->orderBy('name')
            ->get();

        return Inertia::render('Panel/AccessControl/Roles/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.access_control'), 'url' => route('panel.accesscontrol.users.index'), 'active' => false],
                ['label' => 'Perfis de acesso', 'url' => '#', 'active' => true],
            ],
            // BUG — página em branco: RoleResource::collection() envolve o
            // resultado em {"data": [...]} quando serializado pela resposta
            // completa do Inertia (diferente de json_encode() direto, que não
            // envolve). O Vue espera um Array puro (`roles.filter(...)` no
            // computed de busca) — sem ->resolve(), o objeto {data:[...]}
            // quebra o primeiro render sem erro capturado, tela fica em
            // branco. ->resolve() força o array desembrulhado.
            'roles'                => RoleResource::collection($roles)->resolve(),
            'availablePermissions' => $this->groupedAvailablePermissions(),
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

        $message = $this->getCreateMessage();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'data'    => new RoleResource($role),
            ]);
        }

        return redirect()->route('panel.accesscontrol.roles.index')->with('message', $message);
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

        $message = $this->getUpdateMessage($request);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'data'    => new RoleResource($role),
            ]);
        }

        return redirect()->route('panel.accesscontrol.roles.index')->with('message', $message);
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

        $message = $this->getDeleteMessage();

        if ($request->wantsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->route('panel.accesscontrol.roles.index')->with('message', $message);
    }

    /**
     * Permissions do enum App\Enums\Permission agrupadas por group(), com o
     * id do PermissionRecord correspondente — pra montar a matriz de
     * checkbox no frontend (grupo -> [{id, key, label}]).
     *
     * @return list<array{group: string, items: list<array{id: ?string, key: string, label: string}>}>
     */
    private function groupedAvailablePermissions(): array
    {
        $records = PermissionRecord::query()->get()->keyBy('key');

        return collect(PermissionEnum::cases())
            ->groupBy(fn (PermissionEnum $permission) => $permission->group())
            ->map(fn ($permissions, $group) => [
                'group' => $group,
                'items' => $permissions->map(fn (PermissionEnum $permission) => [
                    'id'    => $records->get($permission->value)?->id,
                    'key'   => $permission->value,
                    'label' => $permission->label(),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
