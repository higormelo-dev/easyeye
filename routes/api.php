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

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::group(['prefix' => 'integrators', 'as' => 'integrators.'], function () {
    Route::post('signin', [EntityIntegratorsController::class, 'store'])->name('auth');
    Route::post('check-token', [EntityIntegratorsController::class, 'checkToken'])->name('checktoken');
    Route::group(['middleware' => ['auth:integrator', 'auth_with_integrator', 'token.expiration']], static function () {
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

            Route::get('profile', static function (Request $request) {
                return response()->json($request->user());
            });

            // Adicione outras rotas protegidas aqui
        });
    });
});
