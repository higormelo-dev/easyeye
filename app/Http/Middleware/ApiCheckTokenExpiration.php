<?php

namespace App\Http\Middleware;

use App\Services\IntegratorTokenPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiCheckTokenExpiration
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token) {
            return $next($request);
        }
        $policy = app(IntegratorTokenPolicy::class);

        if ($reason = $policy->reason($token, $request->attributes->get('token_last_used_at'))) {
            $token->delete();

            return response()->json(['code' => 'token_invalid', 'message' => __($reason), 'valid' => false], 401);
        }
        $policy->renew($token);

        return $next($request);
    }
}
