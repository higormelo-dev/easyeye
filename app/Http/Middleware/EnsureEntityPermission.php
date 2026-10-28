<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\Entity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ValueError;

/**
 * Garante que o usuário autenticado possui a permissão granular exigida
 * (RBAC customizado ADITIVO) na entity resolvida pelo request.
 *
 * Ordem de resolução da entity: idêntica à de EnsureEntityRole/
 * EnsureUserBelongsToEntity.
 *   1. Route model binding — parâmetro de rota tipado como Entity.
 *   2. Parâmetro de rota chamado "entity" (string/UUID) resolvido manualmente.
 *   3. Sessão — chave "selected_entity_id".
 *
 * A permission é passada como uma única key do enum App\Enums\Permission:
 *
 *   ->middleware('permission:settings.manage')
 *
 * Opcionalmente aceita uma lista de roles de fallback (ClientRule) como
 * argumentos extras, separados por vírgula — ADITIVO PURO sobre o
 * comportamento acima: passa quando hasPermissionInEntity(key) OR
 * hasAnyRoleInEntity([...roles]). Usado para migrar um bloco de rotas que
 * hoje usa `entity.role:role1,role2,...` para permission customizada sem
 * regredir o acesso que os roles já tinham:
 *
 *   ->middleware('permission:patients.manage,admin,financial,doctor,secretary')
 *
 * Sem argumentos extras o comportamento é IDÊNTICO ao de antes (só checa a
 * permission, sem fallback nenhum) — nenhuma rota existente muda de
 * comportamento por essa extensão.
 *
 * Comportamento quando negado:
 *   - JSON / expectsJson → 403 com payload { message }.
 *   - Web → abort(403).
 *
 * COMPLIANCE: este middleware só protege rotas administrativas/operacionais
 * não-clínicas. NUNCA usar `permission:` (com ou sem fallback roles) para
 * proteger ações clínicas/regulatórias travadas (assinar laudo, prescrever,
 * etc.) — essas continuam usando `entity.role:doctor` direto. Ver
 * App\Enums\Permission e App\Traits\HasEntityRoles::hasPermissionInEntity()
 * para a regra completa.
 *
 * Pré-requisito: middleware auth deve ter executado antes.
 * Recomendado: executar após entity.member para separar as responsabilidades.
 */
class EnsureEntityPermission
{
    public function handle(Request $request, Closure $next, ?string $permissionKey = null, string ...$fallbackRoles): Response
    {
        if (blank($permissionKey)) {
            // Nenhuma permission exigida → passa direto (misconfiguration silenciosa com log)
            logger()->warning('EnsureEntityPermission: nenhuma permission foi definida na rota — acesso liberado.');

            return $next($request);
        }

        try {
            $permission = Permission::from($permissionKey);
        } catch (ValueError) {
            // Key inexistente no enum: nega acesso e loga em vez de estourar 500.
            logger()->error('EnsureEntityPermission: permission key inválida na rota.', [
                'permission_key' => $permissionKey,
                'route'          => $request->path(),
            ]);

            return $this->deny($request, __('http-statuses.403'));
        }

        $entity = $this->resolveEntity($request);

        if (! $entity) {
            return $this->deny($request, __('http-statuses.404'));
        }

        $user = $request->user();

        if (! $user) {
            return $this->deny($request, __('http-statuses.403'));
        }

        $hasAccess = $user->hasPermissionInEntity($entity, $permission)
            || (! empty($fallbackRoles) && $user->hasAnyRoleInEntity($entity, $fallbackRoles));

        if (! $hasAccess) {
            return $this->deny($request, __('http-statuses.403'));
        }

        return $next($request);
    }

    private function resolveEntity(Request $request): ?Entity
    {
        // 1. Route model binding já resolveu para um modelo Entity
        foreach ($request->route()->parameters() as $value) {
            if ($value instanceof Entity) {
                return $value;
            }
        }

        // 2. Parâmetro de rota "entity" como string/UUID
        $routeEntityId = $request->route('entity');

        if ($routeEntityId && is_string($routeEntityId)) {
            return Entity::find($routeEntityId);
        }

        // 3. Fallback: entity selecionada na sessão do painel
        $sessionEntityId = session('selected_entity_id');

        if ($sessionEntityId) {
            return Entity::find($sessionEntityId);
        }

        return null;
    }

    private function deny(Request $request, string $message): Response
    {
        // API e fetch com Accept: application/json → JSON puro
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        // Inertia (X-Inertia: true) → redireciona de volta com flash de erro
        // Evita que o HTML da página de erro seja exibido como modal overlay.
        if ($request->hasHeader('X-Inertia')) {
            return redirect()->back()->with('error', $message);
        }

        abort(Response::HTTP_FORBIDDEN, $message);
    }
}
