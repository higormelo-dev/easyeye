<?php

use App\Http\Controllers\Manager\{
    EntitiesController,
    EntityIntegratorEquipmentsController,
    EntityIntegratorsController
};
use Illuminate\Support\Facades\{Route};

Route::group(['prefix' => 'manager', 'as' => 'manager.'], function () {
    Route::resource('entities', EntitiesController::class);
    Route::resource('entities.integrators', EntityIntegratorsController::class);
    Route::resource('entities.integrators.equipments', EntityIntegratorEquipmentsController::class)->only('index');
});
