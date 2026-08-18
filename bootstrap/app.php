<?php

use App\Http\Middleware\{ApiAuthenticateWithIntegrator,
    ApiCheckPlanAccess,
    ApiCheckTokenExpiration,
    ApiIdempotency,
    ApiTokenPreCheck,
    CheckFeature,
    CheckJsonResponse,
    CheckSubscription,
    EnsureApiDocsAccess,
    EnsureEntityPermission,
    EnsureEntityRole,
    EnsureEntitySelected,
    EnsureIsPartner,
    EnsureSaasAdmin,
    EnsureTokenScope,
    EnsureTwoFactor,
    EnsureUserBelongsToEntity,
    HandleImpersonation,
    HandleInertiaRequests,
    LogAdminAccess,
    ParseMultipartFormData,
    RequireTermsAcceptance,
    SetLocale};
use App\Http\Middleware\BindTenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\{Exceptions, Middleware};
use Illuminate\Http\Request;
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
        // ─────────────────────────────────────────────────────────────────
        // SaaS atrás de proxy reverso (SaveInCloud, Cloudflare, balancer
        // do hosting). Sem isso o Laravel ignora X-Forwarded-* e gera URLs
        // com `Host: _` (server_name catch-all do Nginx) ou esquema `http://`
        // mesmo o cliente acessando via HTTPS.
        //
        // - at: '*'  — confiar em qualquer IP que se apresente como proxy.
        //              É a opção pragmática em hosting compartilhado onde o
        //              IP do load balancer pode mudar. Mitigamos o risco de
        //              Host Header Injection com trustHosts() abaixo.
        // - headers  — habilita todos os X-Forwarded-* relevantes para que
        //              URL::generator use Host/Port/Proto do cliente real.
        // ─────────────────────────────────────────────────────────────────
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                   | Request::HEADER_X_FORWARDED_HOST
                   | Request::HEADER_X_FORWARDED_PORT
                   | Request::HEADER_X_FORWARDED_PROTO
                   | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // ─────────────────────────────────────────────────────────────────
        // Allowlist de hosts — defesa contra Host Header Injection.
        //
        // Sem isso, com `trustProxies('*')` qualquer cliente poderia enviar
        // `X-Forwarded-Host: site-malicioso.com` e o Laravel geraria links
        // (e-mails de pacientes, reset de senha, redirects) apontando para
        // o domínio do atacante. Em LGPD isso caracteriza incidente
        // reportável à ANPD.
        //
        // O middleware só age fora do ambiente `local`, então o dev local
        // (APP_URL=http://localhost:8085) não é afetado.
        // ─────────────────────────────────────────────────────────────────
        $middleware->trustHosts(at: [
            'easyeye.app',
            'teste.easyeye.app',
        ], subdomains: true);

        $middleware->alias([
            'tenant.bind'          => BindTenantContext::class,
            'entity.selected'      => EnsureEntitySelected::class,
            'entity.member'        => EnsureUserBelongsToEntity::class,
            'entity.role'          => EnsureEntityRole::class,
            'permission'           => EnsureEntityPermission::class,
            'check.subscription'   => CheckSubscription::class,
            'feature'              => CheckFeature::class,
            'auth_with_integrator' => ApiAuthenticateWithIntegrator::class,
            'token.precheck'       => ApiTokenPreCheck::class,
            'token.expiration'     => ApiCheckTokenExpiration::class,
            'token.scope'          => EnsureTokenScope::class,
            'idempotency'          => ApiIdempotency::class,
            'api.plan'             => ApiCheckPlanAccess::class,
            'terms.accepted'       => RequireTermsAcceptance::class,
            'partner'              => EnsureIsPartner::class,
            'saas.admin'           => EnsureSaasAdmin::class,
            'admin.audit'          => LogAdminAccess::class,
            '2fa'                  => EnsureTwoFactor::class,
            'docs.access'          => EnsureApiDocsAccess::class,
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
                // Preserva headers do erro (ex.: Retry-After e X-RateLimit-* do
                // ThrottleRequestsException no 429) — sem o 3º argumento o cliente
                // não saberia quando re-tentar.
                return response()->json([
                    'message' => $e->getMessage() ?: __('http-statuses.' . $e->getStatusCode(), [], null) ?? 'Error',
                ], $e->getStatusCode(), $e->getHeaders());
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
