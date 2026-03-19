<?php

use App\Http\Controllers\Manager\{
    EntitiesController,
    EntityIntegratorEquipmentsController,
    EntityIntegratorsController,
    EntityUserIntegratorsController,
    EntityUsersController,
    ImpersonateController,
    PlansController,
    SubscriptionsController
};
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'manager', 'as' => 'manager.'], static function () {
    // ── Empresas ───────────────────────────────────────────────────────────
    Route::get('entities/{entity}/edit-data', [EntitiesController::class, 'editData'])->name('entities.edit-data');
    Route::get('entities/{entity}/users', [EntityUsersController::class, 'index'])->name('entities.users');
    Route::resource('entities', EntitiesController::class)->except('create', 'edit');

    // ── Usuários Integradores ──────────────────────────────────────────────
    Route::get('entities/{entity}/user-integrators/{userIntegrator}/edit-data', [EntityUserIntegratorsController::class, 'editData'])->name('entities.user-integrators.edit-data');
    Route::patch('entities/{entity}/user-integrators/{userIntegrator}/activate', [EntityUserIntegratorsController::class, 'activate'])->name('entities.user-integrators.activate');
    Route::put('entities/{entity}/user-integrators/{userIntegrator}/restore', [EntityUserIntegratorsController::class, 'restore'])->name('entities.user-integrators.restore');
    Route::resource('entities.user-integrators', EntityUserIntegratorsController::class)->except('create', 'edit');

    // ── Integradores (sob Usuário Integrador) ─────────────────────────────
    Route::get('entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/edit-data', [EntityIntegratorsController::class, 'editData'])->name('entities.user-integrators.integrators.edit-data');
    Route::patch('entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/activate', [EntityIntegratorsController::class, 'activate'])->name('entities.user-integrators.integrators.activate');
    Route::put('entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/restore', [EntityIntegratorsController::class, 'restore'])->name('entities.user-integrators.integrators.restore');
    Route::resource('entities.user-integrators.integrators', EntityIntegratorsController::class)->except('create', 'edit');

    // ── Equipamentos ───────────────────────────────────────────────────────
    Route::resource('entities.user-integrators.integrators.equipments', EntityIntegratorEquipmentsController::class)->only('index', 'show');

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
