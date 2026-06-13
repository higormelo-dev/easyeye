<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce least-privilege por verbo nas rotas da API de integradores.
 *
 * Tokens emitidos a partir do hardening de escopo carregam abilities
 * `api:read` e/ou `api:write` (ver EntityIntegratorsController::abilitiesFor).
 * Métodos seguros (GET/HEAD) exigem `api:read`; os demais exigem `api:write`.
 *
 * Compatibilidade: tokens LEGADOS (emitidos antes do escopo) só têm a ability
 * `integrator_id:<uuid>` — sem nenhuma `api:*`. Para não quebrar clientes já
 * implantados em campo, esses tokens recebem acesso total (a restrição só é
 * aplicada quando o token foi explicitamente emitido com escopo). Tokens são
 * sempre emitidos pelo servidor e hasheados, então a ausência de escopo é
 * confiável — não pode ser forjada pelo cliente.
 *
 * Pré-requisito: rodar após auth:sanctum (precisa do token autenticado).
 */
class EnsureTokenScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user  = $request->user();
        $token = $user?->currentAccessToken();

        // Sem token autenticado: deixa as camadas de auth anteriores decidirem.
        if (! $token) {
            return $next($request);
        }

        $abilities = (array) ($token->abilities ?? []);

        $hasScopes = false;

        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'api:')) {
                $hasScopes = true;

                break;
            }
        }

        // Token legado, sem escopo declarado → acesso total (compat).
        if (! $hasScopes) {
            return $next($request);
        }

        $required = $request->isMethodSafe() ? 'api:read' : 'api:write';

        if (! $user->tokenCan($required)) {
            return response()->json([
                'message' => __('auth.token_scope_insufficient'),
                'valid'   => false,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
