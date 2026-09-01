// GERADOR DE SCREENSHOTS do manual do administrador da clínica.
// Consumido por docs/manual-administrador/README.md. Rodar sob demanda:
//   cd e2e && npx cypress run --browser chrome --spec cypress/e2e/docs/admin-manual.cy.js
// Nenhum dado é persistido: modais são preenchidos e fechados sem salvar.

const shot = (name) => cy.screenshot(name, { capture: 'viewport', overwrite: true });

describe('Manual do administrador — capturas', () => {
  beforeEach(() => {
    cy.loginAs('clinic.admin');
    cy.on('window:confirm', () => false); // nunca confirmar ação destrutiva
  });

  it('01 acesso: dashboard e menu completo', () => {
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

  it('02 médicos: lista e cadastro (4 abas)', () => {
    cy.visit('/panel/doctors');
    cy.expectPanelPage();
    cy.wait(500);
    shot('03-medicos-lista');

    cy.contains('button', 'Novo médico').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    cy.get('.ee-modal__dialog').contains('label', 'Nome completo')
      .parent().find('input:visible').type('DR. RICARDO ALMEIDA PRADO');
    cy.get('.ee-modal__dialog').contains('label', 'Apelido')
      .parent().find('input:visible').type('DR. RICARDO');
    cy.wait(300);
    shot('04-medico-novo-pessoal');

    cy.get('.ee-modal__dialog .nav-tabs button').eq(1).click(); // Médico
    cy.get('.ee-modal__dialog').contains('label', 'CRM')
      .parent().find('input:visible').type('123456');
    cy.get('.ee-modal__dialog').contains('label', 'Especialidade')
      .parent().find('input:visible').type('Retina e Vítreo');
    cy.wait(300);
    shot('05-medico-novo-profissional');

    cy.get('.ee-modal__dialog .nav-tabs button').eq(3).click(); // Acesso
    cy.wait(300);
    shot('06-medico-novo-acesso');
    cy.get('.ee-modal__header .btn-close').click({ force: true });
  });

  it('03 médicos: escala e bloqueios', () => {
    cy.visit('/panel/doctors');
    cy.expectPanelPage();
    cy.get('a[title="Horários de atendimento"]').first()
      .invoke('attr', 'href').then((href) => cy.visit(href));
    cy.expectPanelPage();
    cy.contains('Escala de Atendimento', { timeout: 15000 }).should('be.visible');
    cy.wait(600);
    shot('07-medico-escala');
  });

  it('04 usuários: lista e novo usuário', () => {
    cy.visit('/panel/accesscontrol/users');
    cy.expectPanelPage();
    cy.wait(500);
    shot('08-usuarios-lista');

    cy.contains('button', 'Novo usuário').click();
    cy.get('.ufm-panel', { timeout: 10000 }).should('be.visible');
    cy.get('.ufm-panel input[type=text]').first().type('Paula Regina Souza');
    cy.get('.ufm-panel input[type=email]').first().type('paula.souza@clinica.com.br');
    cy.get('.ufm-panel .multiselect').first().click();
    cy.get('.multiselect-option:visible').contains(/Secret/i).click({ force: true });
    cy.wait(300);
    shot('09-usuario-novo');
    cy.get('.ufm-panel .btn-close').click({ force: true });
  });

  it('05 perfis de acesso (RBAC)', () => {
    cy.visit('/panel/accesscontrol/roles');
    cy.expectPanelPage();
    cy.wait(500);
    shot('10-perfis-lista');

    cy.contains('button', 'Novo perfil').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    cy.get('.ee-modal__dialog input[type=text]').first().type('Recepção ampliada');
    cy.get('.ee-modal__dialog').contains('label', 'Visualizar financeiro')
      .parent().find('input[type=checkbox]').check({ force: true });
    cy.wait(300);
    shot('11-perfil-novo');
    cy.get('.ee-modal__header .btn-close, .ee-modal__dialog .btn-close')
      .first().click({ force: true });
  });

  it('06 configurações: catálogos clínicos', () => {
    cy.visit('/panel/setting/visittypes');
    cy.expectPanelPage();
    cy.wait(500);
    shot('12-catalogo-tipos-atendimento');

    // Modal de criação do catálogo (padrão de todos os 10).
    cy.get('.page-wrapper .border-bottom button.btn-primary').first().click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.get('.modal.d-block').contains('label', 'Nome').parent()
      .find('input:visible').first().type('Consulta de retorno');
    cy.wait(200);
    shot('13-catalogo-novo-registro');
    cy.get('.modal.d-block .btn-close').click();

    cy.visit('/panel/setting/skintypes');
    cy.expectPanelPage();
    cy.wait(400);
    shot('14-catalogo-parametros');
  });

  it('07 configurações: convênios e salas', () => {
    cy.visit('/panel/setting/covenants');
    cy.expectPanelPage();
    cy.wait(500);
    shot('15-convenios');

    cy.visit('/panel/setting/resources');
    cy.expectPanelPage();
    cy.wait(500);
    shot('16-recursos-salas');
  });

  it('08 configurações: lentes IOL e modelos de documento', () => {
    cy.visit('/panel/setting/iollenses');
    cy.expectPanelPage();
    cy.wait(500);
    shot('17-lentes-iol');

    cy.visit('/panel/setting/report-settings');
    cy.expectPanelPage();
    cy.wait(500);
    shot('18-modelos-documento');
  });

  it('09 configurações: painel de chamadas e 2FA da clínica', () => {
    cy.visit('/panel/setting/call-panel');
    cy.expectPanelPage();
    cy.wait(500);
    shot('19-painel-chamadas');

    cy.visit('/panel/setting/security');
    cy.expectPanelPage();
    cy.wait(500);
    shot('20-seguranca-2fa');
  });

  it('10 relatórios e compliance', () => {
    cy.visit('/panel/reports');
    cy.expectPanelPage();
    cy.wait(400);
    shot('21-relatorios-hub');

    cy.visit('/panel/reports/compliance');
    cy.expectPanelPage();
    cy.wait(400);
    // Datas preenchidas mostram os exports habilitados.
    const today = new Date().toISOString().slice(0, 10);
    cy.get('input[type=date]').each(($i) => {
      cy.wrap($i).invoke('val', today).trigger('input').trigger('change');
    });
    cy.wait(300);
    shot('22-compliance');
  });

  it('11 financeiro (visão do admin)', () => {
    cy.visit('/panel/financial/bi');
    cy.expectPanelPage();
    cy.wait(1000);
    shot('23-financeiro-bi');
  });

  it('12 IA: consumo e compra de créditos', () => {
    cy.visit('/panel/ai/usage');
    cy.expectPanelPage();
    cy.wait(800);
    shot('24-ia-consumo-creditos');
  });

  it('13 agenda e pacientes (operação)', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.wait(800);
    shot('25-agenda');

    cy.visit('/panel/patients');
    cy.expectPanelPage();
    cy.wait(500);
    shot('26-pacientes');
  });

  it('14 conta e área do SaaS (negada)', () => {
    cy.visit('/panel/profile');
    cy.expectPanelPage();
    cy.wait(400);
    shot('27-meu-perfil');

    // Painel do SaaS: redireciona com aviso de área exclusiva.
    cy.visit('/panel/manager/dashboard');
    cy.url({ timeout: 15000 }).should('match', /\/panel\/dashboard/);
    cy.wait(300);
    shot('28-area-saas-negada');
  });
});
