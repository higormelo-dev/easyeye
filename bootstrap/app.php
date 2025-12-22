<?php

use App\Http\Middleware\{CheckJsonResponse, EnsureEntitySelected, ParseMultipartFormData};
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\{Exceptions, Middleware};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'entity.selected' => EnsureEntitySelected::class,
        ]);

        $middleware->api([
            CheckJsonResponse::class,
            ParseMultipartFormData::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/integrators/v1/*')) {
                return response()->json([
                    'message' => 'Not authenticated.',
                ], 401);
            }
        });
    })->create();
