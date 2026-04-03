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
                    'message' => __('http-statuses.415'),
                ], 415);
            }
        }

        $response = $next($request);

        // Interceptar apenas redirecionamentos reais (3xx com Location)
        if ($response->isRedirection() && $response->headers->has('Location')) {
            $location = $response->headers->get('Location');

            if (str_contains($location, 'login')) {
                return response()->json([
                    'message' => __('http-statuses.401'),
                ], 401);
            }

            return response()->json([
                'message' => __('http-statuses.500'),
                'debug'   => app()->isLocal() ? "Unexpected redirect to: {$location}" : null,
            ], 500);
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
