<?php

use App\Http\Controllers\Api\{EntityIntegratorEquipmentsController,
    EntityIntegratorsController,
    PatientExamsController,
    PatientsController};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::group(['prefix' => 'integrators', 'as' => 'integrators.'], function () {
    Route::post('auth', [EntityIntegratorsController::class, 'store'])->name('auth');
    Route::group(['middleware' => ['api', 'auth:sanctum']], static function () {
        Route::delete('auth', [EntityIntegratorsController::class, 'destroy'])->name('logout');

        Route::group(['prefix' => 'v1', 'as' => 'v1.'], function () {
            Route::apiResource('equipments', EntityIntegratorEquipmentsController::class)->except(['create', 'edit']);
            Route::apiResource('patients', PatientsController::class)->only('index', 'show');
            Route::resource('patients.exams', PatientExamsController::class)->except(['create', 'edit']);
            // Route::post('patients/{patient}/exams/{exam}', [PatientExamsController::class, 'update'])->name('patients.exams.update');

            Route::get('profile', static function (Request $request) {
                return response()->json($request->user());
            });

            // Adicione outras rotas protegidas aqui
        });
    });
});
