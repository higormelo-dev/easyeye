<?php

use App\Http\Controllers\Api\{EntityIntegratorEquipmentsController,
    EntityIntegratorsController,
    ExamTypesController,
    ExamsController,
    PatientExamsController,
    PatientsController,
    SchedulesController};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

// Debug route - REMOVER DEPOIS
Route::get('/debug-token', function (Request $request) {
    $bearerToken = $request->bearerToken();

    if (! $bearerToken) {
        return response()->json(['error' => 'No bearer token']);
    }

    $accessToken = PersonalAccessToken::findToken($bearerToken);

    if (! $accessToken) {
        return response()->json(['error' => 'Token not found', 'bearer' => $bearerToken]);
    }

    return response()->json([
        'token_found'    => true,
        'tokenable_type' => $accessToken->tokenable_type,
        'tokenable_id'   => $accessToken->tokenable_id,
        'tokenable'      => $accessToken->tokenable ? 'exists' : 'null',
        'expires_at'     => $accessToken->expires_at,
        'is_expired'     => $accessToken->expires_at?->isPast(),
    ]);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::group(['prefix' => 'integrators', 'as' => 'integrators.'], function () {
    Route::post('signin', [EntityIntegratorsController::class, 'store'])->name('auth');
    Route::post('check-token', [EntityIntegratorsController::class, 'checkToken'])->name('checktoken');
    Route::group(['middleware' => ['auth:sanctum', 'auth_with_integrator', 'token.expiration']], static function () {
        Route::delete('signout', [EntityIntegratorsController::class, 'destroy'])->name('signout');
        Route::group(['prefix' => 'v1', 'as' => 'v1.'], function () {
            Route::apiResource('equipments', EntityIntegratorEquipmentsController::class)
                ->except(['create', 'edit']);
            Route::apiResource('patients', PatientsController::class)->only('index', 'show');
            Route::apiResource('patients.exams', PatientExamsController::class)
                ->except(['create', 'edit']);
            Route::post('patients/{patient}/exams/{exam}', [PatientExamsController::class, 'update'])
                ->name('patients.exams.update');
            Route::apiResource('examtypes', ExamTypesController::class)->only('index', 'show');
            Route::apiResource('schedules', SchedulesController::class)->only('index', 'show');
            Route::apiResource('exams', ExamsController::class)->only('store');

            // Route::get('profile', static function (Request $request) {
            //     return response()->json($request->user());
            // });
        });
    });
});
