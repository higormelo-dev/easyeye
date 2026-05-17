<?php

use App\Http\Middleware\{ApiAuthenticateWithIntegrator,
    ApiCheckPlanAccess,
    ApiCheckTokenExpiration,
    ApiTokenPreCheck,
    CheckFeature,
    CheckJsonResponse,
    CheckSubscription,
    EnsureEntityRole,
    EnsureEntitySelected,
    EnsureIsPartner,
    EnsureSaasAdmin,
    EnsureUserBelongsToEntity,
    HandleImpersonation,
    HandleInertiaRequests,
    LogAdminAccess,
    ParseMultipartFormData,
    RequireTermsAcceptance,
    SetLocale};
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\{Exceptions, Middleware};
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\{HttpException, MethodNotAllowedHttpException, NotFoundHttpException};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'entity.selected'      => EnsureEntitySelected::class,
            'entity.member'        => EnsureUserBelongsToEntity::class,
            'entity.role'          => EnsureEntityRole::class,
            'check.subscription'   => CheckSubscription::class,
            'feature'              => CheckFeature::class,
            'auth_with_integrator' => ApiAuthenticateWithIntegrator::class,
            'token.precheck'       => ApiTokenPreCheck::class,
            'token.expiration'     => ApiCheckTokenExpiration::class,
            'api.plan'             => ApiCheckPlanAccess::class,
            'terms.accepted'       => RequireTermsAcceptance::class,
            'partner'              => EnsureIsPartner::class,
            'saas.admin'           => EnsureSaasAdmin::class,
            'admin.audit'          => LogAdminAccess::class,
        ]);

        // Adiciona o SetLocale, HandleImpersonation e HandleInertiaRequests ao grupo web
        $middleware->web(append: [
            SetLocale::class,
            HandleImpersonation::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->api([
            CheckJsonResponse::class,
            ParseMultipartFormData::class,
        ]);

        // Garante que token.precheck rode ANTES de auth:sanctum,
        // que tem prioridade via AuthenticatesRequests.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ApiTokenPreCheck::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        if (app()->environment('testing') || app()->environment('production')) {
            Integration::handles($exceptions);
        }

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

        $exceptions->render(function (HttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage() ?: __('http-statuses.' . $e->getStatusCode(), [], null) ?? 'Error',
                ], $e->getStatusCode());
            }

            // Inertia requests: redireciona de volta com flash de erro em vez de
            // retornar HTML puro, que seria exibido como modal overlay no frontend.
            if ($request->hasHeader('X-Inertia')) {
                $message = $e->getMessage() ?: __('http-statuses.' . $e->getStatusCode(), [], null) ?? 'Error';

                return redirect()->back()->with('error', $message);
            }
        });

        $exceptions->render(function (Throwable $e, $request) {
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
