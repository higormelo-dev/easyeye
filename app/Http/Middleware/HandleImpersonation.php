<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\EntityUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica a sessão de impersonação ("usar como") a cada request.
 *
 * Quando ativa, substitui Auth::user() pelo usuário impersonado sem tocar
 * na sessão de autenticação do Laravel — o usuário original permanece
 * identificado pela chave de sessão 'impersonating.original_user_id'.
 *
 * Deve ser registrado no grupo 'web', APÓS o middleware 'auth'.
 *
 * Ciclo de vida:
 *   ImpersonateController::store()   → grava 'impersonating.*' na sessão
 *   HandleImpersonation (middleware) → troca Auth::user() a cada request
 *   ImpersonateController::destroy() → apaga 'impersonating.*', restaura contexto
 */
class HandleImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! session()->has('impersonating.entity_user_id')) {
            return $next($request);
        }

        $entityUser = EntityUser::with('user')
            ->find(session('impersonating.entity_user_id'));

        if (! $entityUser || ! $entityUser->user) {
            // Estado inválido: limpa e segue como usuário real
            session()->forget('impersonating');

            return $next($request);
        }

        // Troca Auth::user() para esta request sem alterar a sessão de auth
        auth()->setUser($entityUser->user);

        return $next($request);
    }
}
