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
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user  = $request->user();
        $token = $user ? $user->currentAccessToken() : null;

        if (! $user) {
            return response()->json(['message' => __('http-statuses.401')], 401);
        }

        $integratorId = EntityIntegrator::idFromTokenAbilities($token ? $token->abilities : []);

        if (! $integratorId) {
            return response()->json(['message' => __('http-statuses.401')], 401);
        }

        $integrator = EntityIntegrator::query()
            ->with(['user', 'user.entity'])
            ->find($integratorId);

        $blockReason = $integrator ? $integrator->accessBlockReason() : 'auth.integrator_inactive';
        if (! $integrator || ! $integrator->active) {
            return response()->json(['message' => __('auth.integrator_inactive'), 'valid' => false], 401);
        }

        if ($blockReason !== null) {
            return response()->json(['message' => __($blockReason), 'valid' => false], 401);
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
