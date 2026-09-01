// GERADOR DE SCREENSHOTS do manual do financeiro (não é teste de regressão).
// Consumido por docs/manual-financeiro/README.md. Rodar sob demanda:
//   php artisan tinker --execute="require 'e2e/scripts/seed-docs-financial.php';"
//   cd e2e && npx cypress run --browser chrome --spec cypress/e2e/docs/financial-manual.cy.js
//   php artisan tinker --execute="require 'e2e/scripts/clean-docs-financial.php';"

const shot = (name) => cy.screenshot(name, { capture: 'viewport', overwrite: true });

describe('Manual do financeiro — capturas', () => {
  beforeEach(() => {
    cy.loginAs('clinic.financial');
    cy.on('window:confirm', () => true);
  });

  it('01 acesso: dashboard e menu', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    cy.get('.page-dashboard', { timeout: 15000 }).should('be.visible');
    shot('01-dashboard');

    cy.get('body').then(($b) => {
      if ($b.hasClass('mini-sidebar')) {
        cy.get('#sidebar').trigger('mouseover', { force: true });
        cy.get('body').should('have.class', 'expand-menu');
        cy.wait(500);
      }
    });
    shot('02-menu-lateral');
  });

  it('02 dashboard gerencial (BI)', () => {
    cy.visit('/panel/financial/bi');
    cy.expectPanelPage();
    cy.wait(1200);
    shot('03-dashboard-gerencial');
  });

  it('03 fluxo de caixa: lista e novo lançamento', () => {
    cy.visit('/panel/financial/cash-flow');
    cy.expectPanelPage();
    cy.wait(800);
    shot('04-fluxo-caixa');

    cy.contains('button', 'Novo lançamento').click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.get('.modal.d-block input[type=text][maxlength="255"]').type('Compra de colírios anestésicos');
    cy.get('.modal.d-block input[type=number]').invoke('val', '245.50')
      .trigger('input').trigger('change');
    cy.wait(300);
    shot('05-novo-lancamento');
    cy.get('.modal.d-block button.btn-close').click();
  });

  it('04 fechamento de caixa', () => {
    cy.visit('/panel/financial/cash-closing');
    cy.expectPanelPage();
    cy.wait(600);
    shot('06-fechamento-caixa');
  });

  it('05 tabela de preços', () => {
    cy.visit('/panel/financial/procedure-prices');
    cy.expectPanelPage();
    cy.wait(800);
    shot('07-tabela-precos');
  });

  it('06 faturamento TISS (abas)', () => {
    cy.visit('/panel/financial/billing');
    cy.expectPanelPage();
    cy.wait(800);
    shot('08-tiss-elegiveis');

    cy.get('ul.nav-tabs').contains('button', 'Guias').click();
    cy.wait(400);
    shot('09-tiss-guias');

    cy.get('ul.nav-tabs').contains('button', 'Lotes').click();
    cy.wait(400);
    shot('10-tiss-lotes');
  });

  it('07 conciliação de glosas', () => {
    cy.visit('/panel/financial/tiss/glosas');
    cy.expectPanelPage();
    cy.wait(800);
    shot('11-glosas');

    // Modal de recurso da glosa demo.
    cy.contains('tr', 'duplicidade', { timeout: 10000 })
      .contains('button', 'Recorrer').click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.wait(300);
    shot('12-glosa-recurso');
    cy.get('.modal.d-block button.btn-close, .modal.d-block .btn-outline-secondary')
      .first().click({ force: true });
  });

  it('08 relatórios financeiros', () => {
    cy.visit('/panel/financial/reports/cash-flow');
    cy.expectPanelPage();
    cy.wait(800);
    shot('13-rel-fluxo-caixa');

    cy.visit('/panel/financial/reports/covenants');
    cy.expectPanelPage();
    cy.wait(800);
    shot('14-rel-convenios');
  });

  it('09 relatórios operacionais (produção e absenteísmo)', () => {
    cy.visit('/panel/reports');
    cy.expectPanelPage();
    cy.wait(500);
    shot('15-relatorios-hub');

    const today = new Date().toISOString().slice(0, 10);
    cy.visit(`/panel/reports/schedules?date_from=${today}&date_until=${today}`);
    cy.expectPanelPage();
    cy.wait(800);
    shot('16-rel-agenda');
  });

  it('10 conta e áreas restritas', () => {
    cy.visit('/panel/profile');
    cy.expectPanelPage();
    cy.wait(400);
    shot('17-meu-perfil');

    // Financeiro não gerencia usuários nem configurações da clínica.
    cy.visit('/panel/accesscontrol/users', { failOnStatusCode: false });
    cy.wait(600);
    shot('18-acesso-negado');
  });
});
