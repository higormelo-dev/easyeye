<?php

use App\Enums\AI\AiProvider;
use App\Http\Controllers\Manager\{
    AiCatalogSyncsController,
    AiCreditPurchasesController,
    AiModelPricesController,
    AiProvidersController,
    AiUsageController,
    Cid10CodesController,
    Cid10ImportsController,
    CovenantImportsController,
    CovenantPlansController,
    CovenantsController,
    EntitiesController,
    EntityIntegratorCommandsController,
    EntityIntegratorEquipmentsController,
    EntityIntegratorQueueHealthController,
    EntityIntegratorsController,
    EntityUserIntegratorsController,
    EntityUsersController,
    FinanceController,
    GatewaysController,
    ImpersonateController,
    IntegratorUpdatesController,
    ManagerDashboardController,
    MedicineImportsController,
    MedicinePosologyBatchesController,
    MedicinesController,
    PartnersController,
    PlansController,
    ReportSettingsController,
    SubscriptionsController,
    WhatsAppController
};
use Illuminate\Support\Facades\Route;

// ── Painel administrativo do SaaS ─────────────────────────────────────────────
// URL base : /panel/manager
// Middleware: auth, verified, entity.selected, saas.admin, admin.audit, throttle:manager-read
// Nomes    : manager.*  (separados do panel.* das clínicas)
// ─────────────────────────────────────────────────────────────────────────────
Route::group([
    'prefix' => 'panel/manager',
    'as'     => 'manager.',
    // 2fa entra DEPOIS de auth/verified (precisa de usuário autenticado)
    // e ANTES de saas.admin (que valida a entity SaaS — não devemos expor
    // contexto SaaS sem 2FA verificado na sessão).
    //
    // BUGFIX: era 'throttle:30,1' (30 req/min por IP, compartilhado entre
    // TODAS as rotas de leitura do manager — dashboard, listagem/paginação
    // de entities, listagem/paginação de usuários por clínica, cards, etc).
    // Um admin navegando por poucas clínicas de teste já estourava a cota:
    // o próximo clique (ex.: página 2) recebia 429, e o handler global de
    // exceptions (bootstrap/app.php) faz redirect()->back() em requests
    // Inertia — visualmente indistinguível de "o botão não faz nada".
    // 'manager-read' (AppServiceProvider) já existia definido para este
    // exato propósito (60/min, por usuário) mas nunca tinha sido aplicado
    // aqui — o grupo ainda usava o limite literal antigo.
    'middleware' => ['auth', 'verified', '2fa', 'entity.selected', 'saas.admin', 'admin.audit', 'throttle:manager-read'],
], static function () {
    // ═══════════════════════════════════════════════════════════════════════════
    // ACL POR PAPEL SAAS (saas.role — EnsureSaasRole)
    // `saas.admin` valida apenas que a entity da sessão é a do SaaS, NUNCA o
    // papel do usuário nela. Sem os grupos abaixo, qualquer membro ativo da
    // entity SaaS (inclusive rule 'user') executava ações administrativas por
    // URL direta — o menu esconder o manager é UI, não autorização.
    // Módulos que já fazem Gate fino por action no controller (Créditos IA,
    // Provedores IA, Finanças internas, WhatsApp) ficam FORA dos grupos para
    // não regredir os papéis que os Gates deliberadamente aceitam.
    // ═══════════════════════════════════════════════════════════════════════════

    // ── Dashboard ──────────────────────────────────────────────────────────────
    // Landing page do manager pós-login: todos os papéis SaaS operacionais.
    // Rule 'user' (Usuário Comum SaaS) fica de fora — não tem função no manager.
    Route::middleware('saas.role:admin,financial,support')->group(function () {
        Route::get('/', fn () => redirect()->route('manager.dashboard'));
        Route::get('/dashboard', ManagerDashboardController::class)->name('dashboard');
    });

    // ── Empresas (Clínicas) — admin only ───────────────────────────────────────
    Route::middleware('saas.role:admin')->group(function () {
        Route::get('entities/cards', [EntitiesController::class, 'cards'])->name('entities.cards');
        Route::get('entities/{entity}/edit-data', [EntitiesController::class, 'editData'])->name('entities.edit-data');
        Route::delete('entities/{entity}', [EntitiesController::class, 'destroy'])
            ->middleware('throttle:manager-destructive')
            ->name('entities.destroy');
        // Admin SaaS força 2FA em uma entity (ação de alto impacto — rate-limited)
        Route::patch('entities/{entity}/two-factor', [EntitiesController::class, 'toggleTwoFactor'])
            ->middleware('throttle:manager-destructive')
            ->name('entities.two-factor.toggle');
        Route::resource('entities', EntitiesController::class)->except('create', 'edit', 'destroy');

        // ── Usuários Integradores ──────────────────────────────────────────────
        Route::get('entities/{entity}/user-integrators/{userIntegrator}/edit-data', [EntityUserIntegratorsController::class, 'editData'])->name('entities.user-integrators.edit-data');
        Route::patch('entities/{entity}/user-integrators/{userIntegrator}/activate', [EntityUserIntegratorsController::class, 'activate'])->name('entities.user-integrators.activate');
        Route::put('entities/{entity}/user-integrators/{userIntegrator}/restore', [EntityUserIntegratorsController::class, 'restore'])->name('entities.user-integrators.restore');
        Route::resource('entities.user-integrators', EntityUserIntegratorsController::class)->except('create', 'edit');

        // ── Integradores (sob Usuário Integrador) ──────────────────────────────
        Route::get('entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/edit-data', [EntityIntegratorsController::class, 'editData'])->name('entities.user-integrators.integrators.edit-data');
        Route::patch('entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/activate', [EntityIntegratorsController::class, 'activate'])->name('entities.user-integrators.integrators.activate');
        Route::put('entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/restore', [EntityIntegratorsController::class, 'restore'])->name('entities.user-integrators.integrators.restore');
        Route::resource('entities.user-integrators.integrators', EntityIntegratorsController::class)->except('create', 'edit');

        // ── Equipamentos ───────────────────────────────────────────────────────
        Route::resource('entities.user-integrators.integrators.equipments', EntityIntegratorEquipmentsController::class)->only('index', 'show');

        // ── Fila local do integrador ("o que tá acontecendo agora") ───────────
        Route::get(
            'entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/queue-health',
            [EntityIntegratorQueueHealthController::class, 'index'],
        )->name('entities.user-integrators.integrators.queue-health');

        // ── Comando remoto pro desktop (resync/diagnóstico) ────────────────────
        // Infra mínima (JSON, sem tela própria ainda) — enfileira, o
        // integrador busca no próximo poll (ver Api\IntegratorCommandsController).
        Route::get('entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/commands', [EntityIntegratorCommandsController::class, 'index'])->name('entities.user-integrators.integrators.commands.index');
        Route::post(
            'entities/{entity}/user-integrators/{userIntegrator}/integrators/{integrator}/commands',
            [EntityIntegratorCommandsController::class, 'store'],
        )->name('entities.user-integrators.integrators.commands.store');

        // ── Atualizações do Integrador (auto-update dos desktops) ─────────────
        // Publicar um binário distribui para TODAS as clínicas — admin only e
        // rate-limited como as demais ações de alto impacto.
        Route::get('integrator-updates', [IntegratorUpdatesController::class, 'index'])->name('integrator-updates.index');
        Route::post('integrator-updates', [IntegratorUpdatesController::class, 'store'])
            ->middleware('throttle:manager-destructive')
            ->name('integrator-updates.store');
        Route::patch('integrator-updates/{integratorUpdate}', [IntegratorUpdatesController::class, 'update'])
            ->middleware('throttle:manager-destructive')
            ->name('integrator-updates.update');
    });

    // ── Usuários das empresas + Impersonação — admin ou support ───────────────
    // Os controllers já autorizam via Gate SaasImpersonate (admin|support);
    // o middleware repete a regra como defesa em profundidade na borda HTTP.
    Route::middleware('saas.role:admin,support')->group(function () {
        Route::get('entities/{entity}/users', [EntityUsersController::class, 'index'])->name('entities.users');

        // Apenas o INÍCIO da impersonação. O encerramento (destroy) é
        // registrado fora deste grupo, pois durante a impersonação o
        // `selected_entity_is_client` está true e o middleware `saas.admin`
        // bloquearia justamente a rota usada para SAIR da impersonação.
        Route::post('entities/{entity}/impersonate/{entityUser}', [ImpersonateController::class, 'store'])
            ->middleware('throttle:manager-destructive')
            ->name('entities.impersonate');
    });

    // ── Planos — admin only ────────────────────────────────────────────────────
    Route::middleware('saas.role:admin')->group(function () {
        Route::get('plans/cards', [PlansController::class, 'cards'])->name('plans.cards');
        // Dias de trial (antes do resource: não pode cair em plans/{plan}).
        Route::put('plans/trial-settings', [PlansController::class, 'updateTrialSettings'])->name('plans.trial-settings');
        // Máximo de parcelas sem juros do checkout (cartão, ciclo anual).
        Route::put('plans/checkout-settings', [PlansController::class, 'updateCheckoutSettings'])->name('plans.checkout-settings');
        Route::delete('plans/{plan}', [PlansController::class, 'destroy'])
            ->middleware('throttle:manager-destructive')
            ->name('plans.destroy');
        Route::resource('plans', PlansController::class)->except('create', 'edit', 'destroy');
    });

    // ── Parceiros — admin ou financial (comissões = domínio financeiro) ────────
    Route::middleware('saas.role:admin,financial')->group(function () {
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
    });

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

    // ── Provedores de IA habilitados (quais/quantos o sistema usa) ───────
    Route::get('ai-providers', [AiProvidersController::class, 'index'])
        ->name('ai-providers.index');
    Route::patch('ai-providers', [AiProvidersController::class, 'update'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-providers.update');
    // Modelo de um provedor (drawer do provedor).
    Route::patch('ai-providers/{provider}/model', [AiProvidersController::class, 'updateModel'])
        ->whereIn('provider', array_map(static fn (AiProvider $p) => $p->value, AiProvider::cases()))
        ->middleware('throttle:manager-destructive')
        ->name('ai-providers.model');
    // LGPD: mecanismo de transferência internacional registrado por provedor.
    Route::patch('ai-providers/{provider}/transfer', [AiProvidersController::class, 'updateTransfer'])
        ->whereIn('provider', array_map(static fn (AiProvider $p) => $p->value, AiProvider::cases()))
        ->middleware('throttle:manager-destructive')
        ->name('ai-providers.transfer.update');
    Route::delete('ai-providers/{provider}/transfer', [AiProvidersController::class, 'destroyTransfer'])
        ->whereIn('provider', array_map(static fn (AiProvider $p) => $p->value, AiProvider::cases()))
        ->middleware('throttle:manager-destructive')
        ->name('ai-providers.transfer.destroy');
    // Teste de conexão real (chamada mínima ao provedor) — throttle apertado:
    // cada clique custa alguns centavos de API.
    Route::post('ai-providers/test', [AiProvidersController::class, 'test'])
        ->middleware('throttle:6,1')
        ->name('ai-providers.test');
    // Sincronização do catálogo de modelos/preços (fila + progresso por WebSocket).
    Route::post('ai-providers/catalog-syncs', [AiCatalogSyncsController::class, 'store'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-catalog-syncs.store');
    Route::post('ai-providers/catalog-syncs/{sync}/cancel', [AiCatalogSyncsController::class, 'cancel'])
        ->whereUuid('sync')
        ->middleware('throttle:manager-destructive')
        ->name('ai-catalog-syncs.cancel');

    // ── Catálogo de modelos e preços (habilita modelos no select acima) ──
    Route::get('ai-model-prices', [AiModelPricesController::class, 'index'])
        ->name('ai-model-prices.index');
    Route::post('ai-model-prices', [AiModelPricesController::class, 'store'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-model-prices.store');
    Route::patch('ai-model-prices/{aiModelPrice}', [AiModelPricesController::class, 'update'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-model-prices.update');

    // ── Gateways de Pagamento do SaaS — admin only (credenciais = máxima criticidade) ──
    Route::middleware('saas.role:admin')->group(function () {
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
        // Sem "acesso por clínica": os gateways são só do dono do SaaS, para
        // cobrar as clínicas (assinatura e pacotes de IA). Clínica não tem
        // gateway próprio nem recebe pagamento por eles.
    });

    // ── Assinaturas — admin ou financial (operação de cobrança) ────────────────
    Route::middleware('saas.role:admin,financial')->group(function () {
        Route::get('subscriptions/cards', [SubscriptionsController::class, 'cards'])->name('subscriptions.cards');
        // Busca de empresas para "Nova assinatura" (com a situação atual de cada uma).
        Route::get('subscriptions/entities', [SubscriptionsController::class, 'entities'])->name('subscriptions.entities');
        // Prévia da troca de plano (upgrade proporcional / downgrade agendado) antes de confirmar.
        Route::get('subscriptions/change-preview', [SubscriptionsController::class, 'changePreview'])->name('subscriptions.change-preview');
        // Nova assinatura (trial, cobrança automática ou cortesia) — substitui
        // a vigente da empresa.
        Route::post('subscriptions', [SubscriptionsController::class, 'store'])
            ->middleware('throttle:manager-destructive')
            ->name('subscriptions.store');
        Route::post('subscriptions/cancel', [SubscriptionsController::class, 'cancel'])
            ->middleware('throttle:manager-destructive')
            ->name('subscriptions.cancel');
        Route::patch('subscriptions/block-access', [SubscriptionsController::class, 'blockAccess'])
            ->middleware('throttle:manager-destructive')
            ->name('subscriptions.block-access');
        Route::post('subscriptions/{subscription}/extend', [SubscriptionsController::class, 'extend'])->name('subscriptions.extend');
        // Desfazer a mudança de plano agendada (downgrade) antes da data.
        Route::post('subscriptions/{subscription}/scheduled-change/cancel', [SubscriptionsController::class, 'cancelScheduledChange'])
            ->middleware('throttle:manager-destructive')
            ->name('subscriptions.scheduled-change.cancel');
        Route::get('subscriptions/{subscription}/history', [SubscriptionsController::class, 'history'])->name('subscriptions.history');
        Route::get('subscriptions/{subscription}/invoices', [SubscriptionsController::class, 'invoices'])->name('subscriptions.invoices');
        // "Enviar cobrança à clínica": e-mail + WhatsApp com o link para pagar
        // a fatura em Minha assinatura (um envio por fatura a cada 10 min).
        Route::post('subscriptions/{subscription}/invoices/{invoice}/send-charge', [SubscriptionsController::class, 'sendCharge'])
            ->middleware('throttle:manager-destructive')
            ->name('subscriptions.invoices.send-charge');
        // Estornar pagamento (total ou parcial), com justificativa — só no
        // gateway com estorno pela API; "solicitado" até o gateway confirmar.
        Route::post('subscriptions/{subscription}/invoices/{invoice}/payments/{payment}/refund', [SubscriptionsController::class, 'refund'])
            ->middleware('throttle:manager-destructive')
            ->name('subscriptions.payments.refund');
        // Conferir no gateway o estorno parado em "solicitado": concluído,
        // em andamento ou liberado (não existe/negado lá).
        Route::post('subscriptions/{subscription}/invoices/{invoice}/payments/{payment}/refunds/{refund}/check', [SubscriptionsController::class, 'checkRefund'])
            ->middleware('throttle:manager-destructive')
            ->name('subscriptions.payments.refunds.check');
        // Aviso "o gateway desativou a recorrência" visto pelo time.
        Route::post('subscriptions/{subscription}/recurrence-alert/acknowledge', [SubscriptionsController::class, 'acknowledgeRecurrenceAlert'])
            ->name('subscriptions.recurrence-alert.acknowledge');
        Route::get('subscriptions/{subscription}/retries', [SubscriptionsController::class, 'retries'])->name('subscriptions.retries');
        // Alterar plano, modalidade e período (com justificativa).
        Route::put('subscriptions/{subscription}', [SubscriptionsController::class, 'update'])
            ->middleware('throttle:manager-destructive')
            ->name('subscriptions.update');
        Route::resource('subscriptions', SubscriptionsController::class)->only('index', 'show');
    });

    // ── Finanças internas do EasyEye (P&L do próprio SaaS + IA) ────────────────
    // Gate SaasOwnerFinancial (mais restrito que SaasFinancial) verificado
    // dentro de CADA ação do controller — ver FinanceController::authorizeSaasEntity().
    // Nunca isento de admin.audit (leitura do P&L fica registrada em audit_logs
    // como qualquer outra rota deste grupo — ver LogAdminAccess::SKIP_ROUTES,
    // que deliberadamente NÃO inclui nada daqui).
    Route::get('finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('finance/expenses', [FinanceController::class, 'storeExpense'])
        ->middleware('throttle:manager-destructive')
        ->name('finance.expenses.store');
    Route::patch('finance/expenses/{expense}', [FinanceController::class, 'updateExpense'])
        ->middleware('throttle:manager-destructive')
        ->name('finance.expenses.update');
    Route::delete('finance/expenses/{expense}', [FinanceController::class, 'destroyExpense'])
        ->middleware('throttle:manager-destructive')
        ->name('finance.expenses.destroy');
    // Digest/chat disparam execução de IA (custo real) — mesmo throttle
    // destrutivo dos demais endpoints custosos do manager.
    Route::post('finance/ai/digest', [FinanceController::class, 'digest'])
        ->middleware('throttle:manager-destructive')
        ->name('finance.digest');
    Route::post('finance/ai/chat', [FinanceController::class, 'chat'])
        ->middleware('throttle:manager-destructive')
        ->name('finance.chat');
    Route::get('finance/ai/runs/{aiRun}', [FinanceController::class, 'showAiRun'])
        ->name('finance.ai-runs.show');

    // ── Uso de IA (custo e uso por ação, clínica, usuário e provedor) ──────────
    // Mesmo Gate SaasOwnerFinancial do P&L (AiUsageRequest/controller); leitura
    // e exportação registradas pelo admin.audit do grupo. Só metadados de uso.
    Route::get('ai-usage', [AiUsageController::class, 'index'])->name('ai-usage.index');
    Route::get('ai-usage/runs/{run}', [AiUsageController::class, 'showRun'])
        ->whereUuid('run')
        ->name('ai-usage.runs.show');
    Route::get('ai-usage/export', [AiUsageController::class, 'export'])
        ->middleware('throttle:manager-destructive')
        ->name('ai-usage.export');

    // ── WhatsApp oficial (Gupshup): app global + por clínica ───────────────────
    // Configuração EXCLUSIVA do dono/admin do SaaS (Gate SaasAdminPanel dentro
    // do controller): a conta de parceiro Gupshup pertence à empresa dona — a
    // clínica usa o número do EasyEye ou o próprio, sem ver credencial.
    Route::get('whatsapp', [WhatsAppController::class, 'index'])->name('whatsapp.index');
    // App GLOBAL do EasyEye (padrão pra clínica sem número próprio) —
    // rotas fixas ANTES de whatsapp/{entity} pra não colidir com o binding.
    Route::patch('whatsapp/global', [WhatsAppController::class, 'updateGlobal'])
        ->middleware('throttle:manager-destructive')
        ->name('whatsapp.global.update');
    // Teste = saúde do app na Gupshup ou mensagem de teste (template pago).
    Route::post('whatsapp/global/test', [WhatsAppController::class, 'testGlobal'])
        ->middleware('throttle:manager-destructive')
        ->name('whatsapp.global.test');
    Route::patch('whatsapp/{entity}', [WhatsAppController::class, 'update'])
        ->middleware('throttle:manager-destructive')
        ->name('whatsapp.update');
    Route::post('whatsapp/{entity}/test', [WhatsAppController::class, 'test'])
        ->middleware('throttle:manager-destructive')
        ->name('whatsapp.test');

    // ── Modelos de Documento Globais — admin only ──────────────────────────────
    Route::middleware('saas.role:admin')->group(function () {
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

    // ── Catálogo global de medicamentos (receituário das clínicas) — admin only
    // Manuais (com posologia sugerida) + importação da lista CMED/Anvisa.
    Route::middleware('saas.role:admin')->group(function () {
        Route::get('medicines', [MedicinesController::class, 'index'])->name('medicines.index');
        Route::post('medicines', [MedicinesController::class, 'store'])->name('medicines.store');
        Route::put('medicines/{medicine}', [MedicinesController::class, 'update'])->name('medicines.update');
        // Revisão da posologia gerada por IA com um clique (sem abrir o formulário).
        Route::post('medicines/{medicine}/posology/approve', [MedicinesController::class, 'approvePosology'])
            ->middleware('throttle:120,1')
            ->name('medicines.posology.approve');
        Route::delete('medicines/{medicine}', [MedicinesController::class, 'destroy'])
            ->middleware('throttle:manager-destructive')
            ->name('medicines.destroy');
        Route::post('medicines/imports', [MedicineImportsController::class, 'store'])
            ->middleware('throttle:manager-destructive')
            ->name('medicines.imports.store');
        Route::post('medicines/imports/{import}/cancel', [MedicineImportsController::class, 'cancel'])
            ->whereUuid('import')
            ->middleware('throttle:manager-destructive')
            ->name('medicines.imports.cancel');
        // Sugestão de posologia por IA (chamada paga ao provedor): limite próprio.
        Route::post('medicines/ai-posology', [MedicinesController::class, 'aiPosology'])
            ->middleware('throttle:10,1')
            ->name('medicines.ai-posology');
        // Posologia por IA em LOTE (filtros aplicados): prévia, início (fila) e
        // cancelamento — progresso só por WebSocket (sem endpoint de status).
        Route::get('medicines/posology-batches/preview', [MedicinePosologyBatchesController::class, 'preview'])
            ->middleware('throttle:30,1')
            ->name('medicines.posology-batches.preview');
        Route::post('medicines/posology-batches', [MedicinePosologyBatchesController::class, 'store'])
            ->middleware('throttle:manager-destructive')
            ->name('medicines.posology-batches.store');
        Route::post('medicines/posology-batches/{batch}/cancel', [MedicinePosologyBatchesController::class, 'cancel'])
            ->whereUuid('batch')
            ->middleware('throttle:manager-destructive')
            ->name('medicines.posology-batches.cancel');
    });

    // ── Catálogo global da CID-10 (diagnóstico de todas as clínicas) — admin only
    // Lista oficial do DATASUS + códigos personalizados; importação do
    // CID10CSV.zip com progresso por WebSocket (sem endpoint de status).
    Route::middleware('saas.role:admin')->group(function () {
        Route::get('cid10', [Cid10CodesController::class, 'index'])->name('cid10.index');
        Route::post('cid10', [Cid10CodesController::class, 'store'])->name('cid10.store');
        Route::put('cid10/{cid10}', [Cid10CodesController::class, 'update'])
            ->whereUuid('cid10')
            ->name('cid10.update');
        Route::delete('cid10/{cid10}', [Cid10CodesController::class, 'destroy'])
            ->whereUuid('cid10')
            ->middleware('throttle:manager-destructive')
            ->name('cid10.destroy');
        Route::post('cid10/imports', [Cid10ImportsController::class, 'store'])
            ->middleware('throttle:manager-destructive')
            ->name('cid10.imports.store');
        Route::post('cid10/imports/{import}/cancel', [Cid10ImportsController::class, 'cancel'])
            ->whereUuid('import')
            ->middleware('throttle:manager-destructive')
            ->name('cid10.imports.cancel');
    });

    // ── Catálogo global de convênios (operadoras da ANS + manuais) — admin only
    // Sincronização com o Cadastro de Operadoras da ANS (dados abertos).
    Route::middleware('saas.role:admin')->group(function () {
        Route::get('covenants', [CovenantsController::class, 'index'])->name('covenants.index');
        Route::post('covenants', [CovenantsController::class, 'store'])->name('covenants.store');
        Route::post('covenants/imports', [CovenantImportsController::class, 'store'])
            ->middleware('throttle:manager-destructive')
            ->name('covenants.imports.store');
        Route::post('covenants/imports/{import}/cancel', [CovenantImportsController::class, 'cancel'])
            ->whereUuid('import')
            ->middleware('throttle:manager-destructive')
            ->name('covenants.imports.cancel');
        Route::put('covenants/{covenant}', [CovenantsController::class, 'update'])
            ->whereUuid('covenant')
            ->name('covenants.update');
        Route::get('covenants/{covenant}/usage', [CovenantsController::class, 'usage'])
            ->whereUuid('covenant')
            ->name('covenants.usage');
        Route::delete('covenants/{covenant}', [CovenantsController::class, 'destroy'])
            ->whereUuid('covenant')
            ->middleware('throttle:manager-destructive')
            ->name('covenants.destroy');

        // Planos do convênio (ANS só leitura; manuais com cadastro).
        Route::get('covenants/{covenant}/plans', [CovenantPlansController::class, 'index'])
            ->whereUuid('covenant')
            ->name('covenants.plans.index');
        Route::post('covenants/{covenant}/plans', [CovenantPlansController::class, 'store'])
            ->whereUuid('covenant')
            ->name('covenants.plans.store');
        Route::put('covenant-plans/{plan}', [CovenantPlansController::class, 'update'])
            ->whereUuid('plan')
            ->name('covenants.plans.update');
        Route::delete('covenant-plans/{plan}', [CovenantPlansController::class, 'destroy'])
            ->whereUuid('plan')
            ->middleware('throttle:manager-destructive')
            ->name('covenants.plans.destroy');
    });
});

// ── Encerramento da impersonação ─────────────────────────────────────────────
// FORA do grupo `saas.admin`: durante a impersonação o usuário efetivo é o
// admin da clínica, e `saas.admin` bloquearia esta própria rota — criando um
// catch-22. A segurança aqui é garantida pelo controller (verifica
// `session('impersonating')` antes de fazer qualquer coisa).
Route::middleware(['auth', 'verified'])
    ->delete('panel/manager/impersonate', [ImpersonateController::class, 'destroy'])
    ->name('manager.impersonate.destroy');
