<?php

use App\Http\Middleware\{ApiAuthenticateWithIntegrator,
    ApiCheckTokenExpiration,
    CheckFeature,
    CheckJsonResponse,
    CheckSubscription,
    EnsureEntityRole,
    EnsureEntitySelected,
    EnsureUserBelongsToEntity,
    HandleImpersonation,
    ParseMultipartFormData,
    SetLocale};
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\{Exceptions, Middleware};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\{MethodNotAllowedHttpException, NotFoundHttpException};

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
            'entity.member'   => EnsureUserBelongsToEntity::class,
            'entity.role'     => EnsureEntityRole::class,
            'check.subscription'   => CheckSubscription::class,
            'feature'              => CheckFeature::class,
            'auth_with_integrator' => ApiAuthenticateWithIntegrator::class,
            'token.expiration'     => ApiCheckTokenExpiration::class,
        ]);

        // Adiciona o SetLocale e HandleImpersonation ao grupo web
        $middleware->web(append: [
            SetLocale::class,
            HandleImpersonation::class,
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

        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => __('validation.custom.validation_invalid.default_message'),
                    'errors'  => $e->errors(),
                ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => __('http-statuses.405'),
                ], HttpResponse::HTTP_METHOD_NOT_ALLOWED);
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
