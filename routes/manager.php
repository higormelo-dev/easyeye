<?php

use App\Http\Controllers\Manager\{
    AiCreditPurchasesController,
    EntitiesController,
    EntityIntegratorEquipmentsController,
    EntityIntegratorsController,
    EntityUserIntegratorsController,
    EntityUsersController,
    GatewaysController,
    ImpersonateController,
    ManagerDashboardController,
    PartnersController,
    PlansController,
    ReportSettingsController,
    SubscriptionsController
};
use Illuminate\Support\Facades\Route;

// ── Painel administrativo do SaaS ─────────────────────────────────────────────
// URL base : /panel/manager
// Middleware: auth, verified, entity.selected, saas.admin, admin.audit, throttle:30,1
// Nomes    : manager.*  (separados do panel.* das clínicas)
// ─────────────────────────────────────────────────────────────────────────────
Route::group([
    'prefix'     => 'panel/manager',
    'as'         => 'manager.',
    // 2fa entra DEPOIS de auth/verified (precisa de usuário autenticado)
    // e ANTES de saas.admin (que valida a entity SaaS — não devemos expor
    // contexto SaaS sem 2FA verificado na sessão).
    'middleware' => ['auth', 'verified', '2fa', 'entity.selected', 'saas.admin', 'admin.audit', 'throttle:30,1'],
], static function () {

    // ── Dashboard ──────────────────────────────────────────────────────────────
    Route::get('/', fn () => redirect()->route('manager.dashboard'));
    Route::get('/dashboard', ManagerDashboardController::class)->name('dashboard');

    // ── Empresas (Clínicas) ────────────────────────────────────────────────────
    Route::get('entities/cards', [EntitiesController::class, 'cards'])->name('entities.cards');
    Route::get('entities/{entity}/edit-data', [EntitiesController::class, 'editData'])->name('entities.edit-data');
    Route::get('entities/{entity}/users', [EntityUsersController::class, 'index'])->name('entities.users');
    Route::delete('entities/{entity}', [EntitiesController::class, 'destroy'])
        ->middleware('throttle:manager-destructive')
        ->name('entities.destroy');
    // Admin SaaS força 2FA em uma entity (ação de alto impacto — rate-limited)
    Route::patch('entities/{entity}/two-factor', [EntitiesController::class, 'toggleTwoFactor'])
        ->middleware('throttle:manager-destructive')
        ->name('entities.two-factor.toggle');
    Route::resource('entities', EntitiesController::class)->except('create', 'edit', 'destroy');

    // ── Usuários Integradores ──────────────────────────────────────────────────
    Route::get('entities/{entity}/user-integrators/{userIntegrator}/edit-data', [EntityUserIntegratorsController::class, 'editData'])->name('entities.user-integrators.edit-data');
    Route::patch('entities/{entity}/user-integrators/{userIntegrator}/activate', [EntityUserIntegratorsController::class, 'activate'])->name('entities.user-integrators.activate');
    Route::put('entities/{entity}/user-integrators/{userIntegrator}/restore', [EntityUserIntegratorsController::class, 'restore'])->name('entities.user-integrators.restore');
    Route::resource('entities.user-integrators', EntityUserIntegratorsController::class)->except('create', 'edit');

    // ── Integradores (sob Usuário Integrador) ──────────────────────────────────
    Route::get('entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/edit-data', [EntityIntegratorsController::class, 'editData'])->name('entities.user-integrators.integrators.edit-data');
    Route::patch('entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/activate', [EntityIntegratorsController::class, 'activate'])->name('entities.user-integrators.integrators.activate');
    Route::put('entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/restore', [EntityIntegratorsController::class, 'restore'])->name('entities.user-integrators.integrators.restore');
    Route::resource('entities.user-integrators.integrators', EntityIntegratorsController::class)->except('create', 'edit');

    // ── Equipamentos ───────────────────────────────────────────────────────────
    Route::resource('entities.user-integrators.integrators.equipments', EntityIntegratorEquipmentsController::class)->only('index', 'show');

    // ── Impersonação (apenas o INÍCIO — exige saas.admin) ──────────────────────
    // O encerramento (destroy) é registrado fora deste grupo, pois durante a
    // impersonação o `selected_entity_is_client` está true e o middleware
    // `saas.admin` bloquearia justamente a rota usada para SAIR da impersonação.
    Route::post('entities/{entity}/impersonate/{entityUser}', [ImpersonateController::class, 'store'])
        ->middleware('throttle:manager-destructive')
        ->name('entities.impersonate');

    // ── Planos ─────────────────────────────────────────────────────────────────
    Route::get('plans/cards', [PlansController::class, 'cards'])->name('plans.cards');
    Route::delete('plans/{plan}', [PlansController::class, 'destroy'])
        ->middleware('throttle:manager-destructive')
        ->name('plans.destroy');
    Route::resource('plans', PlansController::class)->except('create', 'edit', 'destroy');

    // ── Parceiros ──────────────────────────────────────────────────────────────
    // Rotas de export antes do resource para evitar conflito com /partners/{partner}.
    Route::get('partners/export/pdf', [PartnersController::class, 'exportPdf'])->name('partners.export.pdf');
    Route::get('partners/export/excel', [PartnersController::class, 'exportExcel'])->name('partners.export.excel');
    Route::get('partners/{partner}/edit-data', [PartnersController::class, 'editData'])->name('partners.edit-data');
    Route::get('partners/{partner}', [PartnersController::class, 'show'])->name('partners.show');
    Route::patch('partners/{partner}/leads/{lead}/advance', [PartnersController::class, 'advanceLead'])->name('partners.leads.advance');
    Route::patch('partners/commissions/{commission}/pay', [PartnersController::class, 'payCommission'])
        ->middleware('throttle:manager-destructive')
        ->name('partners.commission.pay');
    Route::delete('partners/{partner}', [PartnersController::class, 'destroy'])
        ->middleware('throttle:manager-destructive')
        ->name('partners.destroy');
    Route::resource('partners', PartnersController::class)->except('create', 'edit', 'show', 'destroy');

    // ── Compra de créditos IA ──────────────────────────────────────────────────
    // Gerencia pedidos das clínicas: aprovar pagamento (creditar), cancelar,
    // marcar falha de gateway, ou reembolsar (estornar wallet).
    //
    // Autorização granular via Gates SaaS dentro do controller — credit/fail
    // exigem `saas.financial`, cancel `saas.support`, refund `saas.admin-panel`.
    Route::get('ai-credit-purchases', [AiCreditPurchasesController::class, 'index'])
        ->name('ai-credit-purchases.index');
    Route::get('ai-credit-purchases/{purchase}', [AiCreditPurchasesController::class, 'show'])
        ->name('ai-credit-purchases.show');
    Route::patch('ai-credit-purchases/{purchase}/credit', [AiCreditPurchasesController::class, 'credit'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-credit-purchases.credit');
    Route::patch('ai-credit-purchases/{purchase}/cancel', [AiCreditPurchasesController::class, 'cancel'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-credit-purchases.cancel');
    Route::patch('ai-credit-purchases/{purchase}/fail', [AiCreditPurchasesController::class, 'markFailed'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-credit-purchases.fail');
    Route::patch('ai-credit-purchases/{purchase}/refund', [AiCreditPurchasesController::class, 'refund'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-credit-purchases.refund');
    Route::post('ai-credit-purchases/manual', [AiCreditPurchasesController::class, 'createManual'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-credit-purchases.manual');

    // ── Recargas (topups) do EasyEye nos provedores ──────────────────────
    Route::post('ai-provider-topups', [AiCreditPurchasesController::class, 'storeTopup'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-provider-topups.store');
    Route::delete('ai-provider-topups/{topup}', [AiCreditPurchasesController::class, 'deleteTopup'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-provider-topups.destroy');

    // ── Gateways de Pagamento ──────────────────────────────────────────────────
    Route::get('gateways', [GatewaysController::class, 'index'])->name('gateways.index');
    Route::patch('gateways/{gateway}/set-default', [GatewaysController::class, 'setDefault'])->name('gateways.set-default');
    Route::patch('gateways/{gateway}/toggle-active', [GatewaysController::class, 'toggleActive'])->name('gateways.toggle-active');
    Route::patch('gateways/{gateway}/priority', [GatewaysController::class, 'updatePriority'])->name('gateways.priority');
    Route::get('gateways/{gateway}/credentials', [GatewaysController::class, 'credentials'])->name('gateways.credentials');
    Route::post('gateways/{gateway}/credentials', [GatewaysController::class, 'storeCredential'])
        ->middleware('throttle:manager-destructive')
        ->name('gateways.credentials.store');
    Route::patch('gateways/{gateway}/credentials/{credential}/revoke', [GatewaysController::class, 'revokeCredential'])
        ->middleware('throttle:manager-destructive')
        ->name('gateways.credentials.revoke');
    Route::get('gateways/{gateway}/entity-access', [GatewaysController::class, 'entityAccess'])->name('gateways.entity-access');
    Route::patch('gateways/{gateway}/entity-access/{entity}', [GatewaysController::class, 'toggleEntityAccess'])->name('gateways.entity-access.toggle');

    // ── Assinaturas ────────────────────────────────────────────────────────────
    Route::get('subscriptions/cards', [SubscriptionsController::class, 'cards'])->name('subscriptions.cards');
    Route::post('subscriptions/activate', [SubscriptionsController::class, 'activate'])->name('subscriptions.activate');
    Route::post('subscriptions/trial', [SubscriptionsController::class, 'startTrial'])->name('subscriptions.trial');
    Route::post('subscriptions/cancel', [SubscriptionsController::class, 'cancel'])
        ->middleware('throttle:manager-destructive')
        ->name('subscriptions.cancel');
    Route::post('subscriptions/settings', [SubscriptionsController::class, 'updateSettings'])->name('subscriptions.settings');
    Route::patch('subscriptions/block-access', [SubscriptionsController::class, 'blockAccess'])
        ->middleware('throttle:manager-destructive')
        ->name('subscriptions.block-access');
    Route::get('subscriptions/{subscription}/invoices', [SubscriptionsController::class, 'invoices'])->name('subscriptions.invoices');
    Route::get('subscriptions/{subscription}/retries', [SubscriptionsController::class, 'retries'])->name('subscriptions.retries');
    Route::resource('subscriptions', SubscriptionsController::class)->only('index', 'show', 'update');

    // ── Modelos de Documento Globais ───────────────────────────────────────────
    Route::get('report-settings/cards', [ReportSettingsController::class, 'cards'])->name('report-settings.cards');
    Route::get('report-settings/{report_setting}/show-data', [ReportSettingsController::class, 'show'])->name('report-settings.show');
    Route::get('report-settings/{report_setting}/preview', [ReportSettingsController::class, 'preview'])->name('report-settings.preview');
    Route::post('report-settings/{report_setting}/publish', [ReportSettingsController::class, 'publish'])
        ->middleware('throttle:manager-destructive')
        ->name('report-settings.publish');
    Route::post('report-settings/{report_setting}/archive', [ReportSettingsController::class, 'archive'])
        ->middleware('throttle:manager-destructive')
        ->name('report-settings.archive');
    Route::delete('report-settings/{report_setting}', [ReportSettingsController::class, 'destroy'])
        ->middleware('throttle:manager-destructive')
        ->name('report-settings.destroy');
    Route::resource('report-settings', ReportSettingsController::class)->except('show', 'destroy');
});

// ── Encerramento da impersonação ─────────────────────────────────────────────
// FORA do grupo `saas.admin`: durante a impersonação o usuário efetivo é o
// admin da clínica, e `saas.admin` bloquearia esta própria rota — criando um
// catch-22. A segurança aqui é garantida pelo controller (verifica
// `session('impersonating')` antes de fazer qualquer coisa).
Route::middleware(['auth', 'verified'])
    ->delete('panel/manager/impersonate', [ImpersonateController::class, 'destroy'])
    ->name('manager.impersonate.destroy');
