<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSaasAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $request->expectsJson()
                ? response()->json(['message' => __('http-statuses.401')], 401)
                : redirect()->route('login');
        }

        // Rejeita usuários com entidade-cliente selecionada (clínicas).
        // Durante impersonação, redireciona suavemente para o painel próprio em vez
        // de abortar com 403 — a UI continua bloqueada, mas o UX fica limpo
        // (sem banner de erro preso após a navegação).
        if (session('selected_entity_is_client')) {
            $message = __('http-statuses.custom.403_saas_admin_only');

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 403);
            }

            return redirect()
                ->route('panel.dashboard')
                ->with('error', $message);
        }

        // Rejeita usuários sem entidade na sessão
        if (! session('selected_entity_id')) {
            return redirect()->route('selectentity.create');
        }

        return $next($request);
    }
}
