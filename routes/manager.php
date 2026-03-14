<?php

use App\Http\Controllers\Manager\{
    EntitiesController,
    EntityIntegratorEquipmentsController,
    EntityIntegratorsController,
    ImpersonateController,
    PlansController,
    SubscriptionsController
};
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'manager', 'as' => 'manager.'], static function () {
    // ── Empresas ───────────────────────────────────────────────────────────
    Route::get('entities/{entity}/edit-data', [EntitiesController::class, 'editData'])->name('entities.edit-data');
    // Route::put('entities/{entity}/restore', [EntitiesController::class, 'restore'])->name('entities.restore');
    Route::resource('entities', EntitiesController::class)->except('create', 'edit');

    // ── Integradores ───────────────────────────────────────────────────────
    Route::get('entities/{entity}/integrators/{integrator}/edit-data', [EntityIntegratorsController::class, 'editData'])->name('entities.integrators.edit-data');
    Route::patch('entities/{entity}/integrators/{integrator}/activate', [EntityIntegratorsController::class, 'activate'])->name('entities.integrators.activate');
    Route::put('entities/{entity}/integrators/{integrator}/restore', [EntityIntegratorsController::class, 'restore'])->name('entities.integrators.restore');
    Route::resource('entities.integrators', EntityIntegratorsController::class)->except('create', 'edit');

    // ── Equipamentos ───────────────────────────────────────────────────────
    Route::resource('entities.integrators.equipments', EntityIntegratorEquipmentsController::class)->only('index', 'show');

    // ── Impersonação ("usar como este") ────────────────────────────────────
    Route::post('entities/{entity}/impersonate/{entityUser}', [ImpersonateController::class, 'store'])
        ->name('entities.impersonate');
    Route::delete('impersonate', [ImpersonateController::class, 'destroy'])
        ->name('impersonate.destroy');

    // ── Planos ─────────────────────────────────────────────────────────────
    Route::resource('plans', PlansController::class)->except('create', 'edit');

    // ── Assinaturas ────────────────────────────────────────────────────────
    Route::post('subscriptions/activate', [SubscriptionsController::class, 'activate'])->name('subscriptions.activate');
    Route::post('subscriptions/trial', [SubscriptionsController::class, 'startTrial'])->name('subscriptions.trial');
    Route::post('subscriptions/cancel', [SubscriptionsController::class, 'cancel'])->name('subscriptions.cancel');
    Route::post('subscriptions/settings', [SubscriptionsController::class, 'updateSettings'])->name('subscriptions.settings');
    Route::patch('subscriptions/block-access', [SubscriptionsController::class, 'blockAccess'])->name('subscriptions.block-access');
    Route::resource('subscriptions', SubscriptionsController::class)->only('index', 'show', 'update');
});
