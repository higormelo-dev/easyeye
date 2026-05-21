<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\EntityUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response as InertiaResponse};

class EntityUsersController extends Controller
{
    /**
     * Lista usuários vinculados a uma empresa cliente para fins de impersonação
     * pelo time SaaS. Retorna Inertia + paginação server-side, substituindo a
     * antiga DataTable Yajra (Blade) por uma página Vue consistente com o resto
     * do painel manager.
     *
     * Rota: GET /panel/manager/entities/{entity}/users
     */
    public function index(Entity $entity, Request $request): InertiaResponse
    {
        $saasEntity = Entity::findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::SaasImpersonate->value, $saasEntity);

        $search = $request->string('search')->trim()->value();

        // Join com users para permitir busca por name/email e ordenação consistente.
        $query = EntityUser::query()
            ->withTrashed()
            ->join('users', 'users.id', '=', 'entity_users.user_id')
            ->select(['entity_users.*', 'users.name as user_name', 'users.email as user_email'])
            ->where('entity_users.entity_id', $entity->id);

        if ($search !== '') {
            $lower = mb_strtolower($search, 'UTF-8');
            $query->where(function ($q) use ($lower): void {
                $q->whereRaw('LOWER(users.name) LIKE ?', ["%{$lower}%"])
                  ->orWhereRaw('LOWER(users.email) LIKE ?', ["%{$lower}%"]);
            });
        }

        $query->orderByDesc('entity_users.created_at');

        $users = $query->paginate(15)->withQueryString();

        // Sessão atual já está em modo manager (saas.admin middleware garante isso),
        // mas a impersonação só faz sentido quando o admin SaaS não está impersonando
        // ninguém. Esse estado vira flag no payload para a UI decidir habilitar a ação.
        $isImpersonating = session()->has('impersonating');

        return Inertia::render('Panel/Manager/EntityUsers/Index', [
            'entity'          => [
                'id'   => $entity->id,
                'code' => $entity->code,
                'name' => $entity->name,
            ],
            'users'           => $users->through(fn (EntityUser $eu) => $this->toRow($eu, $entity->id, $isImpersonating)),
            'filters'         => ['search' => $search],
            'isImpersonating' => $isImpersonating,
            't'               => trans('manager_entity_users'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(EntityUser $eu, string $entityId, bool $isImpersonating): array
    {
        $canImpersonate = ! $eu->deleted_at
            && (bool) $eu->active
            && ! $isImpersonating;

        return [
            'id'              => (string) $eu->id,
            'code'            => (string) $eu->code,
            'name'            => (string) ($eu->user_name ?? '—'),
            'email'           => (string) ($eu->user_email ?? '—'),
            'rule'            => $eu->rule ? ucfirst((string) $eu->rule) : null,
            'active'          => (bool) $eu->active,
            'deleted'         => $eu->deleted_at !== null,
            'created_at'      => $eu->created_at?->format('d/m/Y H:i'),
            'can_impersonate' => $canImpersonate,
            'impersonate_url' => $canImpersonate
                ? route('manager.entities.impersonate', [$entityId, $eu->id])
                : null,
        ];
    }
}
