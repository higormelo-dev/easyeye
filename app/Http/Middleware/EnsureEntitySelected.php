<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureEntitySelected
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        if (! session('selected_entity_user_id')) {
            // Só vínculos ativos e não deletados: um vínculo soft-deletado ou
            // inativo aqui selecionaria uma entity onde o role check do
            // EnsureEntityRole (que filtra deleted_at) retorna null → 403 em
            // todas as rotas com entity.role, mesmo com o rule correto no
            // vínculo ativo.
            $entityUsers = Auth::user()->entityUsers()
                ->where('active', true)
                ->whereNull('deleted_at')
                ->get();

            if ($entityUsers->count() > 1) {
                return redirect()->route('selectentity.create');
            }

            $entityUser = $entityUsers->first();

            if ($entityUser) {
                session([
                    'selected_entity_user_id'   => $entityUser->id,
                    'selected_entity_user_rule' => $entityUser->rule,
                    'selected_entity_id'        => $entityUser->entity->id,
                    'user_rule'                 => $entityUser->rule,
                ]);
            }
        }

        return $next($request);
    }
}
