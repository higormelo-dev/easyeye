<?php

use App\Http\Controllers\Api\{EntityIntegratorEquipmentsController,
    EntityIntegratorsController,
    ExamTypesController,
    ExamsController,
    PatientExamsController,
    PatientsController,
    SchedulesController};
use App\Http\Controllers\Billing\WebhookController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'integrators', 'as' => 'integrators.'], function () {
    Route::post('signin', [EntityIntegratorsController::class, 'store'])->name('auth')->middleware('throttle:10,1');
    Route::post('check-token', [EntityIntegratorsController::class, 'checkToken'])->name('checktoken');
    Route::group(['middleware' => ['token.precheck', 'auth:sanctum', 'auth_with_integrator', 'token.expiration', 'api.plan']], static function () {
        Route::delete('signout', [EntityIntegratorsController::class, 'destroy'])->name('signout')->withoutMiddleware('api.plan');
        Route::group(['prefix' => 'v1', 'as' => 'v1.'], function () {
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

            // Route::get('profile', static function (Request $request) {
            //     return response()->json($request->user());
            // });
        });
    });
});

Route::post('billing/webhooks/{gateway}', WebhookController::class)
    ->name('billing.webhooks')
    ->middleware('throttle:240,1');
