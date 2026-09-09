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
    // O clique em "Iniciar atendimento" do teste 02 só RENDERIZA o formulário
    // de criação (GET, nada persiste ainda) — então reabre pela mesma URL de
    // criação, resolvida direto pelo agendamento (schedule_id), em vez de
    // buscar de novo no cartão da agenda (evita flakiness da UI de busca) ou
    // de tentar achar "o prontuário mais recente" (ainda não existe nenhum;
    // o único prontuário do paciente nesse ponto é o assinado do fixture de
    // compartilhamento, sementeado à parte).
    cy.exec(`cd .. && php artisan tinker --execute="\\$p = \\App\\Models\\Patient::whereHas('person', fn (\\$q) => \\$q->where('full_name', 'MARIANA COSTA E SILVA'))->firstOrFail(); \\$s = \\App\\Models\\Schedule::where('patient_id', \\$p->id)->latest('date_time')->firstOrFail(); echo 'mr:/panel/patients/' . \\$p->id . '/medicalrecords/create?schedule_id=' . \\$s->id;"`, { timeout: 40000 })
      .its('stdout').then((out) => {
        const m = out.match(/mr:(\S+)/);
        expect(m).to.not.be.null;
        cy.visit(m[1]);
      });
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
    // Salvar mantém a consulta aberta: volta pro edit com a barra liberada.
    cy.url({ timeout: 20000 }).should('match', /medicalrecords\/[0-9a-f-]+\/edit/);
    cy.get('.pmr-form', { timeout: 15000 }).should('exist');
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

  it('09 imagens oftálmicas: laudo manual (Novo laudo)', () => {
    // Reseed do paciente de demonstração (prontuário assinado + 2 exames de
    // imagem de hoje) — mesmo fixture usado pelos testes de regressão.
    cy.exec(`cd .. && php artisan tinker --execute="require 'e2e/scripts/seed-docs-doctor.php';"`, { timeout: 40000 })
      .its('stdout').should('include', 'docsdoc:');

    cy.visit('/panel/eye-images');
    cy.expectPanelPage();
    cy.get('input[placeholder="Buscar paciente..."]').type('MARIANA');
    cy.contains('.patient-item', 'MARIANA', { timeout: 15000 }).click();
    cy.wait(500);
    shot('23-imagens-paciente-selecionado');

    // Botão "Novo laudo" só existe no DOM para o médico (v-if="isDoctor").
    cy.contains('button', 'Novo laudo', { timeout: 15000 }).should('be.visible').click();
    cy.get('.modal.show, .modal.d-block', { timeout: 10000 }).should('be.visible')
      .and('contain.text', 'Novo laudo');
    cy.wait(400);
    shot('24-novo-laudo-modal');

    // Sem modelo cadastrado nesta entidade demo p/ "laudos"/"exames
    // especializados" — digita direto no editor livre (TinyMCE).
    cy.get('iframe.tox-edit-area__iframe', { timeout: 10000 }).should('be.visible');
    cy.wait(700); // TinyMCE termina de montar o iframe/body (init assíncrono)
    cy.get('iframe.tox-edit-area__iframe')
      .its('0.contentDocument.body').should('not.be.undefined')
      .then((body) => {
        cy.wrap(body).click({ force: true })
          .type('Retinografia sem alterações significativas. Escavação papilar dentro da normalidade bilateralmente.', { force: true });
      });
    cy.wait(300);
    shot('25-novo-laudo-preenchido');

    cy.intercept('POST', '**/eye-images/reports').as('storeReport');
    cy.contains('button', 'Salvar laudo').should('not.be.disabled').click();
    cy.wait('@storeReport', { timeout: 20000 }).its('response.statusCode')
      .should('be.oneOf', [200, 201, 422]); // 422 só se pedir confirmação de prontuário do dia

    cy.get('body').then(($b) => {
      if ($b.text().includes('prontuário') && $b.find('.swal2-confirm:visible').length) {
        cy.get('.swal2-confirm:visible').click();
        cy.wait('@storeReport', { timeout: 20000 });
      }
    });

    cy.contains('Laudo salvo com sucesso.', { timeout: 15000 }).should('be.visible');
    cy.wait(300);
    shot('26-novo-laudo-sucesso-pdf');
  });

  it('10 imagens oftálmicas: comparar dois exames', () => {
    cy.visit('/panel/eye-images');
    cy.expectPanelPage();
    cy.get('input[placeholder="Buscar paciente..."]').type('MARIANA');
    cy.contains('.patient-item', 'MARIANA', { timeout: 15000 }).click();

    // Seleciona os 2 exames sementeados (retinografia OD/OE) — "Comparar"
    // só habilita com exatamente 2 selecionados.
    cy.get('.bg-dark.flex-wrap > .position-relative', { timeout: 15000 })
      .should('have.length.at.least', 2)
      .then(($exams) => {
        cy.wrap($exams[0]).click();
        cy.wrap($exams[1]).click();
      });
    cy.wait(300);
    shot('27-comparar-exames-selecionados');

    cy.contains('button', 'Comparar').should('not.be.disabled').click();
    cy.get('.modal.show, .modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    // Abre em "Sobrepor" por padrão.
    shot('28-comparar-sobrepor');

    cy.contains('button', /Lado a lado/i).click();
    cy.wait(400);
    shot('29-comparar-lado-a-lado');
    cy.get('body').type('{esc}');
  });

  it('11 portal do paciente: compartilhar laudo e exame', () => {
    // Laudo — a partir do drawer de detalhe do prontuário assinado.
    cy.visit('/panel/patients');
    cy.expectPanelPage();
    cy.get('input[placeholder]').filter((_, el) => /buscar|nome/i.test(el.placeholder))
      .first().type('MARIANA');
    cy.contains('tr', 'MARIANA', { timeout: 15000 }).find('[title="Prontuário"]').first()
      .then(($a) => { $a[0].click(); });
    cy.url({ timeout: 15000 }).should('include', 'medicalrecords');

    cy.get('[title="Visualizar"], [title="Ver detalhes"]').first().click({ force: true });
    cy.get('.ee-modal__dialog, .modal.show, .modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('30-compartilhar-laudo-drawer');

    cy.intercept('POST', '**/document-shares').as('shareDoc');
    cy.get('[title="Compartilhar este documento com o paciente"]', { timeout: 10000 })
      .first().click({ force: true });
    cy.wait('@shareDoc').its('response.statusCode').should('be.oneOf', [200, 302, 303]);
    cy.get('[title="Revogar acesso do paciente a este documento"]', { timeout: 10000 }).should('exist');
    cy.wait(300);
    shot('31-compartilhar-laudo-ativo');
    cy.get('body').type('{esc}');

    // Exame — a partir do Gerenciador de Imagens (badge circular na thumbnail).
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
  });

  it('12 portal do paciente: enviar convite de acesso', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage();
    cy.get('input[placeholder]').filter((_, el) => /buscar|nome/i.test(el.placeholder))
      .first().type('MARIANA');
    cy.contains('tr', 'MARIANA', { timeout: 15000 }).find('[title="Visualizar"]').first()
      .click({ force: true });
    cy.get('.ee-modal__dialog, .modal.show, .modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.wait(400);
    shot('33-ficha-paciente-convite');

    cy.intercept('POST', '**/portal-invitation').as('invite');
    cy.contains('button', 'Convidar para o portal', { timeout: 10000 })
      .should('not.be.disabled').click();
    cy.wait('@invite').its('response.statusCode').should('be.lessThan', 400);
    cy.contains(/Convite enviado para/i, { timeout: 10000 }).should('be.visible');
    cy.wait(300);
    shot('34-convite-enviado');

    // Limpeza total do fixture (paciente + prontuário assinado + exames).
    cy.exec(`cd .. && php artisan tinker --execute="require 'e2e/scripts/clean-docs-doctor.php';"`, { failOnNonZeroExit: false, timeout: 40000 });
  });

  it('13 conta e áreas restritas', () => {
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
