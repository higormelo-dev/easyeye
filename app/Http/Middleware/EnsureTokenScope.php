<?php

namespace App\Http\Middleware;

use App\Services\IntegratorTokenPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token) {
            return $next($request);
        }
        $abilities = (array) $token->abilities;
        $required  = $request->isMethodSafe() ? 'api:read' : 'api:write';
        $declared  = in_array('api:read', $abilities, true) || in_array('api:write', $abilities, true);
        $path      = $request->path();
        $purpose   = match(true) {
            str_contains($path, 'queue-health')                                                                                    => 'telemetry:write',str_contains($path, 'updates') => 'updates:read',
            str_contains($path, 'commands/') && str_ends_with($path, '/ack')                                                       => 'commands:ack',str_ends_with($path, '/commands') => 'commands:read',
            str_contains($path, 'equipments') || str_contains($path, 'equipment-operations')                                       => 'equipment:write',
            str_contains($path, 'schedules') || str_contains($path, 'clinic-resources') || str_contains($path, 'offline-snapshot') => 'worklist:read',
            str_contains($path, 'exams') && ! $request->isMethodSafe()                                                             => 'exams:write',default => 'patients:resolve',
        };
        $model   = $request->attributes->get('integrator');
        $allowed = IntegratorTokenPolicy::PROFILES[$model?->token_profile] ?? [];

        // Legacy tokens retain verb compatibility until renewal, always intersect the server-authorized profile.
        if (($declared && ! in_array($required, $abilities, true)) || ! in_array($purpose, $allowed, true) || (in_array('purpose:v1', $abilities, true) && ! in_array($purpose, $abilities, true))) {
            return response()->json(['code' => 'token_purpose_insufficient', 'message' => __('auth.token_scope_insufficient'), 'valid' => false], 403);
        }

        return $next($request);
    }
}
