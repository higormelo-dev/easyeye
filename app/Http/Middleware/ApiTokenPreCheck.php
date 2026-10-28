<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenPreCheck
{
    /**
     * Handle an incoming request.
     *
     * Runs BEFORE auth:sanctum to:
     * 1. Capture the original last_used_at before Sanctum overwrites it (for inactivity check).
     * 2. Return a descriptive 401 when the token is expired by date (Sanctum would return a generic message).
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if ($bearerToken) {
            $accessToken = PersonalAccessToken::findToken($bearerToken);

            if ($accessToken) {
                // Store the original last_used_at before Sanctum can overwrite it
                $request->attributes->set(
                    'token_last_used_at',
                    $accessToken->last_used_at ?? $accessToken->created_at,
                );

                // Return descriptive error when token is expired by date
                if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                    $accessToken->delete();

                    return response()->json([
                        'message' => __('auth.token_expired'),
                    ], Response::HTTP_UNAUTHORIZED);
                }
            }
        }

        return $next($request);
    }
}
