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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        if (!session('selected_entity_user_id')) {
            if (count(Auth::user()->entityUsers) > 1) {
                return redirect()->route('selectentity.create');
            }

            $entityUser = Auth::user()->entityUsers->first();

            if ($entityUser) {
                session([
                    'selected_entity_user_id' => $entityUser->id,
                    'selected_entity_id'      => $entityUser->entity->id,
                    'user_rule'               => $entityUser->rule,
                ]);
            }
        }

        return $next($request);
    }
}
