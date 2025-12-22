<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckJsonResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->expectsJson() && !$request->wantsJson()) {
            return response()->json([
                'message' => 'This endpoint only accepts JSON requests.',
            ], 406);
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            if (!$this->hasValidContentType($request)) {
                return response()->json([
                    'message' => 'Content-Type header must be application/json, multipart/form-data, or application/x-www-form-urlencoded.',
                ], 415);
            }
        }

        $response = $next($request);

        // Interceptar redirecionamentos
        if ($response->isRedirect()) {
            return response()->json([
                'message' => 'Acesso não autorizado.',
            ], 401);
        }

        return $response;
    }

    private function hasValidContentType(Request $request): bool
    {
        $contentType = $request->header('Content-Type', '');

        if (str_contains($contentType, '; boundary=')) {
            $contentType = explode('; boundary=', $contentType)[0];
        }

        $acceptedTypes = [
            'application/json',
            'multipart/form-data',
            'application/x-www-form-urlencoded',
        ];

        return in_array($contentType, $acceptedTypes, true);
    }
}
