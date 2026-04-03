<?php

use App\Http\Controllers\Manager\{
    EntitiesController,
    EntityIntegratorEquipmentsController,
    EntityIntegratorsController,
    EntityUserIntegratorsController,
    EntityUsersController,
    ImpersonateController,
    PartnersController,
    PlansController,
    ReportSettingsController,
    SubscriptionsController
};
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'manager', 'as' => 'manager.'], static function () {
    // ── Empresas ───────────────────────────────────────────────────────────
    Route::get('entities/cards', [EntitiesController::class, 'cards'])->name('entities.cards');
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
    Route::get('plans/cards', [PlansController::class, 'cards'])->name('plans.cards');
    Route::resource('plans', PlansController::class)->except('create', 'edit');

    // ── Parceiros ──────────────────────────────────────────────────────────
    Route::get('partners/{partner}/edit-data', [PartnersController::class, 'editData'])->name('partners.edit-data');
    Route::get('partners/{partner}', [PartnersController::class, 'show'])->name('partners.show');
    Route::patch('partners/{partner}/leads/{lead}/advance', [PartnersController::class, 'advanceLead'])->name('partners.leads.advance');
    Route::patch('partners/commissions/{commission}/pay', [PartnersController::class, 'payCommission'])->name('partners.commission.pay');
    Route::resource('partners', PartnersController::class)->except('create', 'edit', 'show');

    // ── Assinaturas ────────────────────────────────────────────────────────
    Route::get('subscriptions/cards', [SubscriptionsController::class, 'cards'])->name('subscriptions.cards');
    Route::post('subscriptions/activate', [SubscriptionsController::class, 'activate'])->name('subscriptions.activate');
    Route::post('subscriptions/trial', [SubscriptionsController::class, 'startTrial'])->name('subscriptions.trial');
    Route::post('subscriptions/cancel', [SubscriptionsController::class, 'cancel'])->name('subscriptions.cancel');
    Route::post('subscriptions/settings', [SubscriptionsController::class, 'updateSettings'])->name('subscriptions.settings');
    Route::patch('subscriptions/block-access', [SubscriptionsController::class, 'blockAccess'])->name('subscriptions.block-access');
    Route::resource('subscriptions', SubscriptionsController::class)->only('index', 'show', 'update');

    // ── Modelos de Documento Globais ────────────────────────────────────────
    Route::get('report-settings/cards', [ReportSettingsController::class, 'cards'])->name('report-settings.cards');
    Route::post('report-settings/{report_setting}/publish', [ReportSettingsController::class, 'publish'])->name('report-settings.publish');
    Route::post('report-settings/{report_setting}/archive', [ReportSettingsController::class, 'archive'])->name('report-settings.archive');
    Route::resource('report-settings', ReportSettingsController::class)->except('show');
});
