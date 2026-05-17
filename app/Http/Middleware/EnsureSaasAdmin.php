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

        // Rejeita usuários com entidade-cliente selecionada (clínicas)
        if (session('selected_entity_is_client')) {
            abort(403, 'Área restrita ao administrador do SaaS.');
        }

        // Rejeita usuários sem entidade na sessão
        if (! session('selected_entity_id')) {
            return redirect()->route('selectentity.create');
        }

        return $next($request);
    }
}
