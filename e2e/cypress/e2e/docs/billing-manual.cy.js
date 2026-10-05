// GERADOR DE SCREENSHOTS dos manuais — cobrança da clínica (não é teste de
// regressão; a regressão é profiles/billing.cy.js). Consumido pelas seções de
// assinatura/cobrança de docs/manual-{administrador,financeiro,secretaria,
// medico,portal-paciente}/README.md. Rodar sob demanda (o seed e a limpeza
// rodam sozinhos — clínicas "CY-BILL …" de e2e/scripts/seed-billing.php):
//   cd e2e && npx cypress run --browser chrome --config excludeSpecPattern=__none__ \
//     --spec cypress/e2e/docs/billing-manual.cy.js
// e copie cypress/screenshots/billing-manual.cy.js/<pasta>/*.png para
// docs/manual-<pasta>/img/ (adm → administrador, fin → financeiro,
// sec → secretaria, med → medico, portal → portal-paciente).
//
// Pix/boleto são respostas simuladas (nenhum gateway é chamado).

const { stubGatewayCalls, pixInstructions, seedBilling, cleanBilling } = require('../../support/billing');

// Sem o contorno de foco (o checkout foca o título de cada etapa — acessibilidade).
const shot = (name) => {
    cy.document().then((doc) => doc.activeElement?.blur?.());
    cy.screenshot(name, { capture: 'viewport', overwrite: true });
};

let S = null;

describe('Manuais — cobrança da clínica (capturas)', () => {
    before(() => {
        seedBilling().then((out) => {
            S = out;
        });
    });

    after(() => {
        cleanBilling();
    });

    // ── Administrador ───────────────────────────────────────────────────────
    it('adm: Minha assinatura e pagar fatura (formas, Pix, boleto)', () => {
        cy.loginAs('billing.ok.admin');
        stubGatewayCalls();
        cy.visit('/panel/my-subscription');
        cy.expectPanelPage('Minha assinatura');
        cy.wait(600);
        shot('adm/34-minha-assinatura');

        cy.get('[data-test="open-invoice-pay"]').click();
        cy.get('[role="dialog"] [data-test="checkout-methods"]').should('be.visible');
        cy.wait(300);
        shot('adm/35-pagar-formas');

        cy.get('[role="dialog"] button[data-method="pix"]').click();
        cy.get('[data-test="pix-qr"]').should('be.visible');
        cy.wait(300);
        shot('adm/36-pagar-pix');

        cy.get('[data-test="checkout-back"]').click();
        cy.get('[role="dialog"] button[data-method="boleto"]').click();
        cy.get('[data-test="checkout-boleto"]').should('be.visible');
        cy.wait(300);
        shot('adm/37-pagar-boleto');
    });

    it('adm: trocar de plano (upgrade e downgrade)', () => {
        cy.loginAs('billing.ok.admin');
        cy.visit('/panel/my-subscription');
        cy.expectPanelPage('Minha assinatura');
        cy.get(`input[name="my-sub-plan"][value="${S.plans.premium}"]`).check({ force: true });
        cy.get('[data-test="my-subscription-contract"]').click();
        cy.get('[data-test="plan-change-summary"]').should('be.visible');
        cy.wait(300);
        shot('adm/38-trocar-plano-upgrade');
        cy.get('[role="dialog"] .btn-close').click();

        cy.get(`input[name="my-sub-plan"][value="${S.plans.basico}"]`).check({ force: true });
        cy.get('[data-test="my-subscription-contract"]').click();
        cy.get('[data-test="plan-change-confirm"]').should('be.visible');
        cy.wait(300);
        shot('adm/39-trocar-plano-downgrade');
    });

    it('adm: créditos de IA (pedido pendente e checkout do pacote)', () => {
        cy.loginAs('billing.ok.admin');
        stubGatewayCalls();
        cy.intercept('POST', '/panel/my-subscription/ai-credits', pixInstructions({ id: 'ai', reference: 'IA-DEMO' }));
        cy.visit('/panel/ai/usage');
        cy.expectPanelPage();
        cy.get('[data-test="ai-credit-pack-checkout"]').should('be.visible');
        cy.wait(400);
        // Atualiza a captura da seção 12 (a compra agora é pelo checkout).
        shot('adm/24-ia-consumo-creditos');
        cy.get('[data-test="ai-pack-pending"]').scrollIntoView();
        cy.wait(400);
        shot('adm/40-ia-creditos-pendente');

        cy.get('[data-test="ai-pack-buy"]').first().click();
        cy.get('[role="dialog"] [data-test="checkout-methods"]').should('be.visible');
        cy.wait(300);
        shot('adm/41-ia-comprar-checkout');
    });

    it('adm: avisos da régua e telas de acesso limitado / bloqueado', () => {
        cy.loginAs('billing.overdue.admin');
        cy.visit('/panel/dashboard');
        cy.expectPanelPage();
        cy.get('[data-test="subscription-banner"]').should('be.visible');
        cy.wait(400);
        shot('adm/42-aviso-atraso');

        cy.loginAs('billing.limited.admin');
        cy.visit('/panel/dashboard');
        cy.expectPanelPage();
        cy.get('[data-test="subscription-banner"]').should('be.visible');
        cy.wait(400);
        shot('adm/43-aviso-acesso-limitado');
        cy.visit('/panel/ai/usage');
        cy.get('[data-test="expired-limited"]').should('be.visible');
        cy.wait(400);
        shot('adm/44-tela-acesso-limitado');

        cy.loginAs('billing.blocked.admin');
        cy.visit('/panel/dashboard');
        cy.get('[data-test="expired-payment"]').should('be.visible');
        cy.wait(400);
        shot('adm/45-tela-bloqueio');
    });

    it('adm: teste grátis (aviso e encerrado) e IA sem franquia', () => {
        cy.loginAs('billing.trial_ending.admin');
        cy.visit('/panel/dashboard');
        cy.expectPanelPage();
        cy.get('[data-test="subscription-banner"]').should('be.visible');
        cy.wait(400);
        shot('adm/46-aviso-teste-gratis');

        cy.loginAs('billing.trial_over.admin');
        cy.visit('/panel/dashboard');
        cy.get('[data-test="expired-contract"]').should('have.length.at.least', 1);
        cy.wait(400);
        shot('adm/47-teste-gratis-encerrado');
    });

    it('adm: TV de chamada com a clínica bloqueada', () => {
        cy.visit(`/call-panel/${S.blocked.call_token}`);
        cy.get('[data-test="call-panel-unavailable"]', { timeout: 15000 }).should('be.visible');
        cy.wait(400);
        shot('adm/48-tv-servico-indisponivel');
    });

    // ── Financeiro ──────────────────────────────────────────────────────────
    it('fin: Minha assinatura, aviso e módulo financeiro no acesso limitado', () => {
        cy.loginAs('billing.ok.financial');
        cy.visit('/panel/my-subscription');
        cy.expectPanelPage('Minha assinatura');
        cy.wait(600);
        shot('fin/19-minha-assinatura');

        cy.loginAs('billing.overdue.financial');
        cy.visit('/panel/dashboard');
        cy.expectPanelPage();
        cy.get('[data-test="subscription-banner"]').should('be.visible');
        cy.wait(400);
        shot('fin/20-aviso-atraso');

        cy.loginAs('billing.limited.financial');
        cy.visit('/panel/financial/bi');
        cy.get('[data-test="expired-limited"]').should('be.visible');
        cy.wait(400);
        shot('fin/21-financeiro-acesso-limitado');
    });

    // ── Secretária ──────────────────────────────────────────────────────────
    it('sec: aviso do acesso limitado e tela de bloqueio', () => {
        cy.loginAs('billing.limited.secretary');
        cy.visit('/panel/dashboard');
        cy.expectPanelPage();
        cy.get('[data-test="subscription-banner-ask-admin"]').should('be.visible');
        cy.wait(400);
        shot('sec/33-aviso-acesso-limitado');

        cy.loginAs('billing.blocked.secretary');
        cy.visit('/panel/dashboard');
        cy.get('[data-test="expired-ask-admin"]').should('be.visible');
        cy.wait(400);
        shot('sec/34-tela-bloqueio');
    });

    // ── Médico ──────────────────────────────────────────────────────────────
    it('med: aviso do acesso limitado, IA bloqueada e paywall de créditos', () => {
        cy.loginAs('billing.limited.doctor');
        cy.visit('/panel/dashboard');
        cy.expectPanelPage();
        cy.get('[data-test="subscription-banner"]').should('be.visible');
        cy.wait(400);
        shot('med/35-aviso-acesso-limitado');
        cy.visit('/panel/ai/usage');
        cy.get('[data-test="expired-limited"]').should('be.visible');
        cy.wait(400);
        shot('med/36-ia-bloqueada-acesso-limitado');

        cy.loginAs('billing.trial_ending.doctor');
        cy.visit('/panel/ai/usage');
        cy.expectPanelPage();
        cy.get('[data-test="ai-paywall"]').should('be.visible');
        cy.wait(400);
        shot('med/37-ia-sem-creditos');
    });

    // ── Portal do paciente ──────────────────────────────────────────────────
    it('portal: clínica em modo somente consulta', () => {
        cy.loginAs('billing.patient');
        cy.visit('/meus-documentos');
        cy.get('[data-test="portal-read-only"]').should('be.visible');
        cy.wait(400);
        shot('portal/14-somente-consulta');
        cy.contains('a', 'CY-BILL BLOQUEADO').click();
        cy.get('[data-test="portal-read-only"]').should('be.visible');
        cy.wait(400);
        shot('portal/15-clinica-somente-consulta');
    });
});
