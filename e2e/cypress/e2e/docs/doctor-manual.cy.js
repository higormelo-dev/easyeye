// GERADOR DE SCREENSHOTS do manual do médico (não é teste de regressão).
// Consumido por docs/manual-medico/README.md. Rodar sob demanda:
//   npx cypress run --browser chrome --spec cypress/e2e/docs/doctor-manual.cy.js
// Requer o seed (paciente de demonstração + agendamento de hoje):
//   php artisan tinker --execute="require 'e2e/scripts/seed-docs-doctor.php';"

const shot = (name) => cy.screenshot(name, { capture: 'viewport', overwrite: true });

describe('Manual do médico — capturas', () => {
  beforeEach(() => {
    cy.loginAs('clinic.doctor');
    cy.on('window:confirm', () => true);
  });

  it('01 acesso: dashboard e menu do médico', () => {
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

  it('02 agenda: iniciar atendimento', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.wait(800);
    shot('03-agenda-medico');

    // Card da paciente de demonstração com o botão verde (player) em destaque.
    cy.get('input[placeholder]').filter((_, el) => /buscar|paciente/i.test(el.placeholder))
      .first().type('MARIANA');
    cy.contains('.schedule-card', 'MARIANA COSTA E SILVA', { timeout: 15000 }).should('be.visible');
    cy.wait(400);
    shot('04-iniciar-atendimento');

    // Entra no atendimento → prontuário abre.
    cy.contains('.schedule-card', 'MARIANA COSTA E SILVA')
      .find('a.btn-success').first().then(($a) => { $a[0].click(); });
    cy.url({ timeout: 20000 }).should('include', '/medicalrecords');
    cy.get('.pmr-form', { timeout: 15000 }).should('exist');
    cy.wait(800);
    shot('05-prontuario-novo');
  });

  it('03 prontuário: preencher e salvar', () => {
    cy.visit('/panel/schedules');
    cy.get('input[placeholder]').filter((_, el) => /buscar|paciente/i.test(el.placeholder))
      .first().type('MARIANA');
    cy.contains('.schedule-card', 'MARIANA COSTA E SILVA', { timeout: 15000 })
      .find('a.btn-success').first().then(($a) => { $a[0].click(); });
    cy.get('.pmr-form', { timeout: 15000 }).should('exist');

    // Queixa principal + tonometria — o essencial de um registro válido.
    cy.get('.pmr-form textarea[placeholder^="Descreva a queixa"], .pmr-form textarea[placeholder^="Descreva livremente"]')
      .filter(':visible:not(:disabled)')
      .first().type('Baixa acuidade visual progressiva no olho direito há 3 meses.');
    cy.get('.pmr-form input[placeholder="00"]').eq(0).clear().type('14');
    cy.get('.pmr-form input[placeholder="00"]').eq(1).clear().type('15');
    cy.wait(300);
    shot('06-prontuario-preenchido');

    cy.get('button.pmr-save-btn').first().click();
    // Save com agendamento redireciona para a agenda.
    cy.url({ timeout: 20000 }).should('include', '/panel/schedules');
  });

  it('04 prontuário: documentações (atestado, evolução, anexo)', () => {
    // Reabre a edição do prontuário salvo (URL real via tinker).
    cy.exec(`cd .. && php artisan tinker --execute="\\$p = \\App\\Models\\Patient::whereHas('person', fn (\\$q) => \\$q->where('full_name', 'MARIANA COSTA E SILVA'))->firstOrFail(); \\$m = \\App\\Models\\MedicalRecord::where('patient_id', \\$p->id)->latest('created_at')->firstOrFail(); echo 'mr:/panel/patients/' . \\$p->id . '/medicalrecords/' . \\$m->id . '/edit';"`, { timeout: 40000 })
      .its('stdout').then((out) => {
        const m = out.match(/mr:(\S+)/);
        expect(m).to.not.be.null;
        cy.visit(m[1]);
      });
    cy.get('.pmr-form', { timeout: 15000 }).should('exist');
    cy.wait(600);
    shot('07-prontuario-edicao');

    // Barra de documentações rápidas.
    cy.get('.pmr-doc-img-btn-label', { timeout: 10000 }).should('exist');
    shot('08-barra-documentacoes');

    // Modal do atestado médico (dias de afastamento).
    cy.get('.pmr-doc-img-btn-label')
      .filter((_, el) => /M[ée]dico/.test(el.textContent))
      .first().closest('button').click({ force: true });
    cy.get('.ee-modal__dialog:visible, .modal.show, .modal.d-block', { timeout: 10000 })
      .should('exist');
    cy.wait(600);
    shot('09-atestado-medico');
    cy.get('body').type('{esc}');
    cy.wait(300);

    // Modal de evolução clínica.
    cy.contains('.pmr-doc-img-btn-label', /Evolução/).first().closest('button').click({ force: true });
    cy.get('.ee-modal__dialog:visible, .modal.show, .modal.d-block', { timeout: 10000 })
      .should('exist');
    cy.wait(400);
    shot('10-evolucao');
    cy.get('body').type('{esc}');
    cy.wait(300);

    // Modal de anexos.
    cy.contains('.pmr-doc-img-btn-label', /Anexo/).first().closest('button').click({ force: true });
    cy.get('.ee-modal__dialog:visible, .modal.show, .modal.d-block', { timeout: 10000 })
      .should('exist');
    cy.wait(400);
    shot('11-anexos');
    cy.get('body').type('{esc}');
    cy.wait(300);

    // Modal de documentações por modelo (receitas, laudos, solicitações).
    cy.contains('.pmr-doc-img-btn-label', /Documenta/).first().closest('button').click({ force: true });
    cy.get('.ee-modal__dialog:visible, .modal.show, .modal.d-block', { timeout: 10000 })
      .should('exist');
    cy.wait(500);
    shot('19-documentacoes-modelos');
    cy.get('body').type('{esc}');
  });

  it('05 prontuário: finalizar consulta (desfecho)', () => {
    cy.exec(`cd .. && php artisan tinker --execute="\\$p = \\App\\Models\\Patient::whereHas('person', fn (\\$q) => \\$q->where('full_name', 'MARIANA COSTA E SILVA'))->firstOrFail(); \\$m = \\App\\Models\\MedicalRecord::where('patient_id', \\$p->id)->latest('created_at')->firstOrFail(); echo 'mr:/panel/patients/' . \\$p->id . '/medicalrecords/' . \\$m->id . '/edit';"`, { timeout: 40000 })
      .its('stdout').then((out) => {
        cy.visit(out.match(/mr:(\S+)/)[1]);
      });
    cy.get('.pmr-form', { timeout: 15000 }).should('exist');

    // Botão voltar → ScheduleFlowGuard pergunta o desfecho do atendimento.
    cy.get('button.btn-outline-white:has(i.fa-arrow-left)').first().click();
    cy.get('.modal.show, .modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('12-finalizar-consulta');
    cy.get('body').type('{esc}');
  });

  it('06 assistente de IA flutuante', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    cy.get('.ai-fab', { timeout: 10000 }).should('be.visible').click();
    cy.get('.ai-floating-assistant', { timeout: 10000 }).should('be.visible');
    cy.wait(500);
    shot('13-assistente-ia-widget');
  });

  it('07 IA: meus prompts e consumo', () => {
    cy.visit('/panel/setting/ai-prompts');
    cy.expectPanelPage();
    cy.wait(500);
    shot('14-meus-prompts');

    // Modal de novo prompt.
    cy.contains('button', /Novo/i).first().click();
    cy.get('.ee-modal__dialog, .modal.show, .modal.d-block', { timeout: 10000 }).should('exist');
    cy.wait(300);
    shot('15-novo-prompt');
    cy.get('body').type('{esc}');

    cy.visit('/panel/ai/usage');
    cy.expectPanelPage();
    cy.wait(600);
    shot('16-ia-consumo');
  });

  it('08 pacientes e imagens oftálmicas', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage();
    cy.wait(500);
    shot('17-pacientes');

    cy.visit('/panel/eye-images');
    cy.expectPanelPage();
    cy.wait(800);
    shot('18-imagens-oftalmicas');
  });

  it('10 conta e áreas restritas', () => {
    cy.visit('/panel/profile');
    cy.expectPanelPage();
    cy.wait(400);
    shot('20-meu-perfil');

    // Médico não lista médicos (decisão de produto) nem acessa financeiro.
    cy.visit('/panel/doctors', { failOnStatusCode: false });
    cy.wait(600);
    shot('21-acesso-negado');
  });
});
