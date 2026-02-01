<?php

use App\Http\Middleware\{ApiAuthenticateIntegrator, CheckJsonResponse, EnsureEntitySelected, ParseMultipartFormData};
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\{Exceptions, Middleware};
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            'auth.integrator' => ApiAuthenticateIntegrator::class,
        ]);

        $middleware->api([
            CheckJsonResponse::class,
            ParseMultipartFormData::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => __('http-statuses.401'),
                ], HttpResponse::HTTP_UNAUTHORIZED);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // Verifica se a origem foi um ModelNotFoundException
                $message = $e->getPrevious() instanceof ModelNotFoundException
                    ? __('http-statuses.custom.404_model')
                    : __('http-statuses.404');

                return response()->json([
                    'message' => $message,
                ], HttpResponse::HTTP_NOT_FOUND);
            }
        });

        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $messages = [
                    'message' => __('http-statuses.500'),
                ];

                if (app()->environment(['local', 'testing'])) {
                    $messages['debug'] = $e->getMessage();
                    $messages['file']  = $e->getFile();
                    $messages['line']  = $e->getLine();
                    $messages['trace'] = $e->getTraceAsString();
                    $messages['code']  = $e->getCode();
                    $messages['type']  = get_class($e);
                }

                return response()->json($messages, HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
            }
        });
    })->create();
