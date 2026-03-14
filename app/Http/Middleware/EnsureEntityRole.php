<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Entity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que o usuário autenticado possui pelo menos um dos roles exigidos
 * na entity resolvida pelo request.
 *
 * Ordem de resolução da entity: idêntica à de EnsureUserBelongsToEntity.
 *   1. Route model binding — parâmetro de rota tipado como Entity.
 *   2. Parâmetro de rota chamado "entity" (string/UUID) resolvido manualmente.
 *   3. Sessão — chave "selected_entity_id".
 *
 * Os roles aceitos são passados como argumentos variádicos separados por vírgula:
 *
 *   ->middleware('entity.role:admin')
 *   ->middleware('entity.role:admin,financial')
 *   ->middleware('entity.role:admin,financial,support')
 *
 * Comportamento quando negado:
 *   - JSON / expectsJson → 403 com payload { message }.
 *   - Web → abort(403).
 *
 * Pré-requisito: middleware auth deve ter executado antes.
 * Recomendado: executar após entity.member para separar as responsabilidades.
 */
class EnsureEntityRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (empty($roles)) {
            // Nenhum role exigido → passa direto (misconfiguration silenciosa com log)
            logger()->warning('EnsureEntityRole: nenhum role foi definido na rota — acesso liberado.');

            return $next($request);
        }

        $entity = $this->resolveEntity($request);

        if (! $entity) {
            return $this->deny($request, __('http-statuses.404'));
        }

        $user = $request->user();

        if (! $user || ! $user->hasAnyRoleInEntity($entity, $roles)) {
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
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, $message);
    }
}
