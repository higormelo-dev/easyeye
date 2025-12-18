<?php

use App\Http\Controllers\Api\{
    EntityIntegratorEquipmentsController,
    EntityIntegratorsController
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::group(['prefix' => 'integrators', 'as' => 'integrators.'], function () {
    Route::post('auth', [EntityIntegratorsController::class, 'store'])->name('auth');
    Route::group(['middleware' => ['auth:sanctum']], function () {
        Route::delete('auth', [EntityIntegratorsController::class, 'destroy'])->name('logout');

        Route::group(['prefix' => 'v1', 'as' => 'v1.'], function () {
            Route::apiResource('equipments', EntityIntegratorEquipmentsController::class);

            Route::get('profile', function (Request $request) {
                return response()->json($request->user());
            });

            // Adicione outras rotas protegidas aqui
        });
    });
});
