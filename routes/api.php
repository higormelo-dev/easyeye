<?php

use App\Http\Controllers\Api\{EntityIntegratorEquipmentsController,
    EntityIntegratorsController,
    ExamTypesController,
    ExamsController,
    IntegratorQueueHealthController,
    IntegratorUpdatesController,
    PatientExamsController,
    PatientsController,
    SchedulesController};
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Controllers\Billing\WebhookController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'integrators', 'as' => 'integrators.'], function () {
    Route::post('signin', [EntityIntegratorsController::class, 'store'])->name('auth')->middleware('throttle:10,1');
    Route::post('check-token', [EntityIntegratorsController::class, 'checkToken'])->name('checktoken')->middleware('throttle:30,1');
    Route::group(['middleware' => ['token.precheck', 'auth:sanctum', 'auth_with_integrator', 'token.expiration', 'api.plan']], static function () {
        // throttle:integrators-api aqui só por consistência com o resto do
        // grupo autenticado (signout já roda depois de auth_with_integrator,
        // então o limiter tem 'integrator' pra chavear por device, igual v1) —
        // sem isso era o único endpoint autenticado sem nenhum rate limit.
        Route::delete('signout', [EntityIntegratorsController::class, 'destroy'])
            ->name('signout')
            ->withoutMiddleware('api.plan')
            ->middleware('throttle:integrators-api');
        Route::group([
            'prefix'     => 'v1',
            'as'         => 'v1.',
            'middleware' => ['throttle:integrators-api', 'token.scope', 'idempotency'],
        ], function () {
            Route::apiResource('equipments', EntityIntegratorEquipmentsController::class)
                ->except(['create', 'edit']);
            Route::apiResource('patients', PatientsController::class)->only('index', 'show');
            Route::apiResource('patients.exams', PatientExamsController::class)
                ->except(['create', 'edit']);
            Route::post('patients/{patient}/exams/{exam}', [PatientExamsController::class, 'update'])
                ->name('patients.exams.update_multipart');
            Route::apiResource('examtypes', ExamTypesController::class)->only(['index', 'show']);
            Route::apiResource('schedules', SchedulesController::class)->only('index', 'show');
            Route::apiResource('exams', ExamsController::class)->only('store');
            // Auto-atualização do desktop: manifesto do último build publicado
            Route::get('updates', [IntegratorUpdatesController::class, 'index'])->name('updates.index');
            // Retrato do estado atual da fila local (pendentes/falhas/
            // bloqueados/enviados) — upsert periódico, nunca histórico.
            Route::put('queue-health', [IntegratorQueueHealthController::class, 'store'])->name('queue-health.store');

            // Route::get('profile', static function (Request $request) {
            //     return response()->json($request->user());
            // });
        });
    });
});

Route::post('billing/webhooks/{gateway}', WebhookController::class)
    ->name('billing.webhooks')
    ->middleware('throttle:240,1');

// Webhook "ao receber" da Z-API (WhatsApp) — URL por clínica via token
// aleatório; tenant identificado pelo token + cross-check do instanceId no
// payload (ver WhatsAppWebhookController). Sem auth de sessão: rota pública
// idempotente, mesmo padrão do webhook de billing acima.
Route::post('whatsapp/webhooks/{token}', WhatsAppWebhookController::class)
    ->name('whatsapp.webhooks')
    ->middleware('throttle:240,1');
