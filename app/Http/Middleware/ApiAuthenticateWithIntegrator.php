<?php

namespace App\Http\Middleware;

use App\Models\EntityIntegrator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthenticateWithIntegrator
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user  = $request->user();
        $token = $user ? $user->currentAccessToken() : null;

        if (! $user) {
            return response()->json(['message' => __('http-statuses.401')], 401);
        }

        // Extrai o integrator_id das abilities do token
        $integratorId = null;
        $abilities    = $token ? $token->abilities : [];

        foreach ($abilities as $ability) {
            if (str_starts_with($ability, 'integrator_id:')) {
                $integratorId = str_replace('integrator_id:', '', $ability);

                break;
            }
        }

        if (! $integratorId) {
            return response()->json(['message' => __('http-statuses.401')], 401);
        }

        // Busca o EntityIntegrator
        $integrator = EntityIntegrator::query()
            ->with(['user', 'user.entity'])
            ->find($integratorId);

        if (! $integrator || ! $integrator->active) {
            return response()->json(['message' => __('auth.integrator_inactive'), 'valid' => false], 401);
        }

        if (! $integrator->user->active) {
            return response()->json(['message' => __('auth.user_integrator_inactive'), 'valid' => false], 401);
        }

        if (! ($integrator->user->entity && $integrator->user->entity->active)) {
            return response()->json(['message' => __('auth.entity_inactive'), 'valid' => false], 401);
        }

        // Disponibiliza o usuário e integrador para toda a request
        $request->attributes->set('user', $user);
        $request->attributes->set('integrator', $integrator);

        return $next($request);
    }
}
