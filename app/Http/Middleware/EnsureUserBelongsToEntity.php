<?php

declare(strict_types = 1);

namespace App\Http\Middleware;

use App\Models\Entity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que o usuário autenticado possui membership ativo na entity
 * resolvida pelo request.
 *
 * Ordem de resolução da entity:
 *   1. Route model binding — parâmetro de rota tipado como Entity.
 *   2. Parâmetro de rota chamado "entity" (string/UUID) resolvido manualmente.
 *   3. Sessão — chave "selected_entity_id" (entity ativa da sessão do painel).
 *
 * Comportamento quando negado:
 *   - JSON / expectsJson → 403 com payload { message }.
 *   - Web → abort(403).
 *
 * Uso:
 *   ->middleware('entity.member')
 *
 * Pré-requisito: middleware auth deve ter executado antes.
 */
class EnsureUserBelongsToEntity
{
    public function handle(Request $request, Closure $next): Response
    {
        $entity = $this->resolveEntity($request);

        if (!$entity) {
            return $this->deny($request, __('http-statuses.404'));
        }

        $user = $request->user();

        if (!$user || !$user->canAccessEntity($entity)) {
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
