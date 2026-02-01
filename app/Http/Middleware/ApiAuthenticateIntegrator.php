<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthenticateIntegrator
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $integrator = $request->user();

        if (! $integrator) {
            return response()->json(['message' => __('http-statuses.401')], 401);
        }

        // Disponibiliza o integrador para toda a request
        $request->attributes->set('integrator', $integrator);

        return $next($request);
    }
}
