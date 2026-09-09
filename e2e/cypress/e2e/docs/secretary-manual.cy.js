// GERADOR DE SCREENSHOTS do manual da secretária (não é teste de regressão).
// Percorre todas as áreas do perfil tirando capturas nomeadas e ordenadas,
// consumidas por docs/manual-secretaria/README.md. Rodar sob demanda:
//   npx cypress run --browser chrome --spec cypress/e2e/docs/secretary-manual.cy.js
// Screenshots em cypress/screenshots/secretary-manual.cy.js/.

const shot = (name) => cy.screenshot(name, { capture: 'viewport', overwrite: true });

describe('Manual da secretária — capturas', () => {
  beforeEach(() => {
    cy.loginAs('clinic.secretary');
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

  it('02 agenda: lista, calendário e busca', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.wait(800);
    shot('03-agenda-lista');

    cy.get('button:has(i.ti-calendar)').first().click();
    cy.wait(1200);
    shot('04-agenda-calendario');
    cy.get('button:has(i.ti-list)').first().click();
    cy.wait(500);

    cy.get('input[placeholder]').filter((_, el) => /buscar|paciente/i.test(el.placeholder))
      .first().type('Silva');
    cy.wait(900);
    shot('05-agenda-busca');
  });

  it('03 agenda: novo agendamento (modal, médico e horários)', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.contains('button', /^\s*Novo\s*$/).click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('06-novo-agendamento-modal');

    cy.get('.ee-modal__dialog .multiselect:visible').first().click();
    cy.get('.multiselect-option:visible').contains(/ana/i).first().click({ force: true });
    cy.wait(1500); // SlotPicker carrega horários
    shot('07-novo-agendamento-horarios');

    cy.get('.ee-modal__header .btn-close').click({ force: true });
  });

  it('04 agenda: situações e cancelamento', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.wait(800);
    cy.get('.schedule-card').first().find('button')
      .filter((_, el) => /situa|alterar/i.test(el.title || '') || el.querySelector('i.fa-list-ul'))
      .first().click({ force: true });
    cy.wait(400);
    shot('08-agenda-situacoes');
    cy.get('body').type('{esc}');
  });

  it('04b agenda: drawer de detalhe — copiar código e cadastro do paciente', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.wait(800);

    // Chrome headless nega permissão de escrita na área de transferência —
    // stub evita rejeição não tratada (console.error falharia o spec).
    cy.window().then((win) => {
      cy.stub(win.navigator.clipboard, 'writeText').resolves();
    });

    cy.get('.schedule-card', { timeout: 15000 }).first()
      .find('[title="Visualizar"]').first().click({ force: true });
    cy.get('.offcanvas.show, .ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('26-agenda-drawer-detalhe');

    cy.get('[title="Copiar código"]').first().click({ force: true });
    cy.wait(300);
    shot('27-agenda-codigo-copiado');
    cy.get('body').type('{esc}');
  });

  it('05 mural de recados', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.contains('button', 'Mural de Recados').click();
    cy.get('div.border.border-primary', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('09-mural-recados');

    cy.get('div.border.border-primary').find('button:has(i.fa-plus)').first().click();
    cy.get('textarea[placeholder="Digite o recado…"]').type('Reunião de equipe amanhã às 8h.');
    cy.wait(200);
    shot('10-mural-novo-recado');
  });

  it('06 fila de espera', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.contains('button', 'Adicionar à Fila').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('11-fila-adicionar');
    cy.get('.ee-modal__header .btn-close').click({ force: true });

    cy.contains('button', 'Lista de Espera').click();
    cy.get('div.border.border-warning', { timeout: 10000 }).should('be.visible');
    cy.wait(300);
    shot('12-fila-painel');
  });

  it('07 pacientes: lista, busca e novo', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage();
    cy.wait(600);
    shot('13-pacientes-lista');

    cy.get('.page-wrapper').contains('button', 'Novo paciente').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('14-paciente-novo-pessoal');

    cy.get('.ee-modal__dialog .nav-tabs button').contains(/Cl[ií]nico|Conv/i).click({ force: true });
    cy.wait(300);
    shot('15-paciente-novo-clinico');
    cy.get('.ee-modal__header .btn-close').click({ force: true });
  });

  it('08 pacientes: detalhe e prontuário (leitura)', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage();
    // Drawer de detalhe — primeiro paciente COM botão "Visualizar" (linhas
    // de excluído só têm "Restaurar").
    cy.get('tbody tr [title="Visualizar"]', { timeout: 15000 })
      .first().click({ force: true });
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('16-paciente-detalhe');
    cy.get('.ee-modal__header .btn-close').click({ force: true });

    // Prontuários do paciente (acesso de leitura) — link "Prontuário" da linha.
    cy.get('tbody tr [title="Prontuário"]', { timeout: 15000 }).first()
      .then(($a) => { $a[0].click(); });
    cy.url({ timeout: 15000 }).should('include', 'medicalrecords');
    cy.wait(800);
    shot('17-paciente-prontuarios');
  });

  it('09 pacientes: importação por planilha', () => {
    cy.visit('/panel/patients/import');
    cy.expectPanelPage();
    cy.wait(500);
    shot('18-pacientes-importacao');
  });

  it('10 médicos: lista, detalhe e escala', () => {
    cy.visit('/panel/doctors');
    cy.expectPanelPage();
    cy.wait(500);
    shot('19-medicos-lista');

    cy.get('a[title="Horários de atendimento"]').first()
      .invoke('attr', 'href').then((href) => cy.visit(href));
    cy.expectPanelPage();
    cy.contains('Escala de Atendimento', { timeout: 15000 }).should('be.visible');
    cy.wait(500);
    shot('20-medico-escala');
  });

  it('11 imagens oftálmicas e assistente de IA', () => {
    cy.visit('/panel/eye-images');
    cy.expectPanelPage();
    cy.wait(800);
    shot('21-imagens-oftalmicas');

    cy.visit('/panel/ai/usage');
    cy.expectPanelPage();
    cy.wait(600);
    shot('22-assistente-ia');
  });

  it('11b imagens oftálmicas: comparar exames', () => {
    // Fixture compartilhada com o manual do médico (mesma entidade demo).
    cy.exec(`cd .. && php artisan tinker --execute="require 'e2e/scripts/seed-docs-doctor.php';"`, { timeout: 40000 })
      .its('stdout').should('include', 'docsdoc:');

    cy.visit('/panel/eye-images');
    cy.expectPanelPage();
    cy.get('input[placeholder="Buscar paciente..."]').type('MARIANA');
    cy.contains('.patient-item', 'MARIANA', { timeout: 15000 }).click();
    cy.wait(400);
    shot('28-imagens-paciente-selecionado');

    cy.get('.bg-dark.flex-wrap > .position-relative', { timeout: 15000 })
      .should('have.length.at.least', 2)
      .then(($exams) => {
        cy.wrap($exams[0]).click();
        cy.wrap($exams[1]).click();
      });
    cy.contains('button', 'Comparar').should('not.be.disabled').click();
    cy.get('.modal.show, .modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('29-comparar-exames');
    cy.get('body').type('{esc}');
  });

  it('11c portal do paciente: convite e compartilhamento de exame', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage();
    cy.get('input[placeholder]').filter((_, el) => /buscar|nome/i.test(el.placeholder))
      .first().type('MARIANA');
    cy.contains('tr', 'MARIANA', { timeout: 15000 }).find('[title="Visualizar"]').first()
      .click({ force: true });
    cy.get('.ee-modal__dialog, .modal.show, .modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('30-ficha-paciente-convite');

    cy.intercept('POST', '**/portal-invitation').as('invite');
    cy.contains('button', 'Convidar para o portal', { timeout: 10000 })
      .should('not.be.disabled').click();
    cy.wait('@invite').its('response.statusCode').should('be.lessThan', 400);
    cy.contains(/Convite enviado para/i, { timeout: 10000 }).should('be.visible');
    cy.wait(300);
    shot('31-convite-enviado');
    cy.get('body').type('{esc}');

    cy.visit('/panel/eye-images');
    cy.expectPanelPage();
    cy.get('input[placeholder="Buscar paciente..."]').type('MARIANA');
    cy.contains('.patient-item', 'MARIANA', { timeout: 15000 }).click();
    cy.intercept('POST', '**/document-shares').as('shareExam');
    cy.get('[title="Compartilhar exame com o paciente"]', { timeout: 15000 }).first()
      .click({ force: true });
    cy.wait('@shareExam').its('response.statusCode').should('be.lessThan', 300);
    cy.get('[title*="Compartilhado com o paciente"]', { timeout: 10000 }).should('exist');
    cy.wait(300);
    shot('32-compartilhar-exame-ativo');

    // Limpeza total do fixture compartilhada com o manual do médico.
    cy.exec(`cd .. && php artisan tinker --execute="require 'e2e/scripts/clean-docs-doctor.php';"`, { failOnNonZeroExit: false, timeout: 40000 });
  });

  it('12 conta: perfil e logout', () => {
    cy.visit('/panel/profile');
    cy.expectPanelPage();
    cy.wait(400);
    shot('23-meu-perfil');

    cy.visit('/panel/dashboard');
    cy.get('.profile-dropdown a.dropdown-toggle').click();
    cy.get('.profile-dropdown .dropdown-menu').should('be.visible');
    cy.wait(200);
    shot('24-menu-usuario-logout');
  });

  it('13 áreas restritas (acesso negado)', () => {
    cy.visit('/panel/financial/cash-flow', { failOnStatusCode: false });
    cy.wait(600);
    shot('25-acesso-negado');
  });
});
