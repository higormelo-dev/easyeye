// Perfil clinic.doctor (dra.ana@clinicateste.com, rule doctor, CLÍNICA TESTE INTEGRADOR).
// Matriz: landing /panel/dashboard; allowed: dashboard, agendas, pacientes,
// imagens oftálmicas, IA (submenu ÚNICO deste perfil: Consumo & dashboard +
// Meus prompts), fila de espera (sem menu); forbidden: accesscontrol, setting
// resources, financeiro, relatórios, compliance, manager.
// Regra do projeto: menu ≠ autorização — doctor NÃO vê "Médicos" no menu,
// e GET /panel/doctors NEGA (29/08: bloco de médicos exclui o rule doctor).

describe('Perfil clinic.doctor — matriz de acesso', () => {
  beforeEach(() => {
    cy.loginAs('clinic.doctor');
  });

  // Navegação de menu = <a href> full-page. Sob carga (php artisan serve é
  // single-thread e outras suítes rodam em paralelo) o load pode demorar:
  // timeouts folgados nos asserts pós-navegação.
  const NAV_TIMEOUT = 30000;

  // Garante que estamos de fato numa página antes de clicar em link do menu.
  function atPage(pathname) {
    cy.location('pathname', { timeout: NAV_TIMEOUT }).should('eq', pathname);
    cy.get('#sidebar-menu', { timeout: 15000 }).should('be.visible');
  }

  // O painel abre por padrão com sidebar MINI (html data-layout="mini" →
  // body.mini-sidebar): links viram ícones de ~39px e o tema expande no hover
  // (doc mouseover → body.expand-menu, com transição animada). Clicar direto
  // durante a animação faz o clique "morrer" fora do <a>. Expandir ANTES.
  function expandSidebar() {
    cy.get('body').then(($body) => {
      if ($body.hasClass('mini-sidebar')) {
        cy.get('#sidebar').trigger('mouseover', { force: true });
        cy.get('body').should('have.class', 'expand-menu');
        cy.wait(400); // transição de largura do tema terminar
      }
    });
  }

  // Abre o submenu IA de forma resiliente: além do handler Vue (@click.prevent),
  // o tema legado (preclinic-script jQuery) pode ligar um slideToggle no mesmo
  // <a>, causando corrida abrir/fechar. Clica e, se o <ul> não ficar visível
  // após as animações (250/350ms), clica de novo.
  function ensureAiSubmenuOpen() {
    expandSidebar();
    cy.get('#sidebar-menu li.submenu')
      .contains('a', 'Assistente de IA')
      .parents('li.submenu')
      .first()
      .as('aiGroup');

    cy.get('@aiGroup').children('ul').then(($ul) => {
      if (!$ul.is(':visible')) {
        cy.get('@aiGroup').children('a').first().click();
        cy.wait(700); // animações do tema (slideUp 250ms / slideDown 350ms)
        cy.get('@aiGroup').children('ul').then(($ul2) => {
          if (!$ul2.is(':visible')) {
            cy.get('@aiGroup').children('a').first().click();
          }
        });
      }
    });
    cy.get('@aiGroup').children('ul').should('be.visible');
  }

  // ── Landing ──────────────────────────────────────────────────────────────
  it('landing pós-login: /panel/dashboard monta (.page-dashboard + "Personalizar")', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage('.page-dashboard');
    cy.contains('Personalizar').should('be.visible');
  });

  // ── Allowed: navegação REAL pelo menu (links <a href> full-page) ────────
  it('menu "Agendas" → /panel/schedules (h4 "Agenda")', () => {
    cy.visit('/panel/dashboard');
    atPage('/panel/dashboard');
    expandSidebar();
    cy.get('#sidebar-menu a[href*="/panel/schedules"]').should('be.visible').click();
    cy.location('pathname', { timeout: NAV_TIMEOUT }).should('eq', '/panel/schedules');
    cy.expectPanelPage();
    cy.get('h4').contains('Agenda').should('be.visible');
  });

  it('menu "Pacientes" → /panel/patients (h4 "Pacientes")', () => {
    cy.visit('/panel/dashboard');
    atPage('/panel/dashboard');
    expandSidebar();
    cy.get('#sidebar-menu a[href*="/panel/patients"]').should('be.visible').click();
    cy.location('pathname', { timeout: NAV_TIMEOUT }).should('eq', '/panel/patients');
    cy.expectPanelPage();
    cy.get('h4').contains('Pacientes').should('be.visible');
  });

  it('menu "Imagens oftálmicas" → /panel/eye-images (h4)', () => {
    cy.visit('/panel/dashboard');
    atPage('/panel/dashboard');
    expandSidebar();
    cy.get('#sidebar-menu a[href*="/panel/eye-images"]').should('be.visible').click();
    cy.location('pathname', { timeout: NAV_TIMEOUT }).should('eq', '/panel/eye-images');
    cy.expectPanelPage();
    cy.get('h4').contains('Imagens oftálmicas').should('be.visible');
  });

  it('submenu IA (exclusivo do doctor): 2 filhos, ambos navegáveis', () => {
    cy.visit('/panel/dashboard');
    atPage('/panel/dashboard');

    // li.submenu com label "Assistente de IA" — só o doctor tem children.
    ensureAiSubmenuOpen();

    // Submenu aberto com exatamente 2 filhos: Consumo & dashboard + Meus prompts.
    cy.get('@aiGroup').children('ul').find('li').should('have.length', 2);
    cy.get('@aiGroup').children('ul').within(() => {
      cy.contains('a', 'Consumo & dashboard').should('be.visible');
      cy.contains('a', 'Meus prompts').should('be.visible');
    });

    // Filho 1: Consumo & dashboard → /panel/ai/usage.
    cy.get('@aiGroup').children('ul').contains('a', 'Consumo & dashboard').click();
    cy.location('pathname', { timeout: NAV_TIMEOUT }).should('eq', '/panel/ai/usage');
    cy.expectPanelPage();
    cy.get('h4').contains('Assistente de IA').should('be.visible');

    // Filho 2: Meus prompts → /panel/setting/ai-prompts. Grupo deveria vir
    // aberto (match panel.ai-runs.* ativo), mas garantimos de novo.
    ensureAiSubmenuOpen();
    cy.get('@aiGroup').children('ul').contains('a', 'Meus prompts').click();
    cy.location('pathname', { timeout: NAV_TIMEOUT }).should('eq', '/panel/setting/ai-prompts');
    cy.expectPanelPage('Meus prompts de IA');
  });

  it('fila de espera: sem item de menu, mas /panel/waiting-list acessível (200 JSON)', () => {
    cy.visit('/panel/dashboard');
    atPage('/panel/dashboard');
    expandSidebar();
    cy.get('#sidebar-menu a[href*="/panel/waiting-list"]').should('not.exist');

    // A rota é endpoint JSON (WaitingListController@index: JsonResponse) usado
    // pelo WaitingListPanel dentro da Agenda — não é página Inertia, então
    // valida-se por cy.request (200 + shape {data, count}), sem cy.visit.
    cy.request({ url: '/panel/waiting-list', failOnStatusCode: false }).then((resp) => {
      expect(resp.status, 'GET /panel/waiting-list').to.eq(200);
      expect(resp.body, 'payload JSON da fila de espera').to.have.property('data');
      expect(resp.body).to.have.property('count');
    });
  });

  // ── Menu ≠ autorização ──────────────────────────────────────────────────
  it('não vê "Médicos" no menu E a rota nega (decisão de produto 29/08: gestão de médicos é administrativa)', () => {
    cy.visit('/panel/dashboard');
    cy.get('#sidebar-menu a[href$="/panel/doctors"]').should('not.exist');
    cy.get('#sidebar-menu').contains('a', 'Médicos').should('not.exist');

    // Antes a rota respondia 200 (menu só escondia); agora o bloco de rotas
    // de médicos exclui o rule doctor — defesa real, não só UI.
    cy.expectForbidden('/panel/doctors');
    cy.expectForbidden('/panel/doctors/cards');
  });

  // ── Forbidden ───────────────────────────────────────────────────────────
  it('403 em /panel/accesscontrol/users (entity.role:admin)', () => {
    cy.expectForbidden('/panel/accesscontrol/users');
  });

  it('403 em /panel/setting/resources (permission:settings.manage, sem bypass p/ doctor)', () => {
    cy.expectForbidden('/panel/setting/resources');
  });

  it('403 em /panel/financial/cash-flow (permission:financial.manage,admin,financial)', () => {
    cy.expectForbidden('/panel/financial/cash-flow');
  });

  it('403 em /panel/reports (permission:financial.manage,admin,financial)', () => {
    cy.expectForbidden('/panel/reports');
  });

  it('403 em /panel/reports/compliance (entity.role:admin)', () => {
    cy.expectForbidden('/panel/reports/compliance');
  });

  it('/panel/manager/dashboard: 302 → /panel/dashboard + flash alert-danger', () => {
    // Navegação real (browser segue o 302 e o flash monta no AppLayout).
    cy.visit('/panel/manager/dashboard');
    cy.url({ timeout: NAV_TIMEOUT }).should('include', '/panel/dashboard');
    cy.get('#sidebar-menu', { timeout: 15000 }).should('be.visible');
    // Flash auto-dismiss em 6s: assertar IMEDIATAMENTE, timeout curto.
    cy.get('div.alert.alert-danger', { timeout: 4000 })
      .should('be.visible')
      .and('contain.text', 'Esta área é exclusiva do administrador do SaaS.');
  });

  // ── Interações não-destrutivas ──────────────────────────────────────────
  it('Pacientes: busca reage (Total: 0 p/ termo inexistente) e modal "Novo paciente" abre/fecha sem salvar', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage();
    cy.get('h4').contains('Pacientes').should('be.visible');

    // Busca com debounce 400ms → router.get com ?search= (preserveState+replace).
    cy.get('input[placeholder^="Buscar por nome"]').should('be.visible')
      .type('zzz-cy-sem-resultado');
    cy.url({ timeout: 15000 }).should('include', 'search=');
    // Badge "Total: N" é independente da view (tabela/cards).
    cy.contains('Total: 0', { timeout: 15000 }).should('be.visible');

    // Limpar busca pelo botão "x" e a lista volta.
    cy.get('.table-search button').click();
    cy.contains('Total: 0').should('not.exist');

    // Modal de criação (OffcanvasPanel) abre e FECHA sem salvar nada.
    cy.contains('button', 'Novo paciente').click();
    cy.get('.ee-modal__dialog').should('be.visible');
    cy.contains('.ee-modal__dialog button', 'Cancelar').click();
    cy.get('.ee-modal__dialog').should('not.exist');
  });

  it('Meus prompts de IA: modal "Novo prompt" abre e fecha sem salvar', () => {
    cy.visit('/panel/setting/ai-prompts');
    cy.expectPanelPage('Meus prompts de IA');

    cy.contains('button', 'Novo prompt').should('be.visible').click();
    cy.get('.modal.show').should('be.visible')
      .find('.modal-title').should('contain.text', 'Novo prompt');
    cy.get('.modal.show .modal-footer').contains('button', 'Cancelar').click();
    cy.get('.modal.show').should('not.exist');
  });

  // ── Logout ──────────────────────────────────────────────────────────────
  it('logout devolve ao site público', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();

    cy.get('.profile-dropdown a.dropdown-toggle').click();
    cy.get('.profile-dropdown .dropdown-menu').should('be.visible');
    cy.get('.profile-dropdown button.dropdown-item.text-danger')
      .filter((_, el) => /Sair/.test(el.textContent) && !/impersona/i.test(el.textContent))
      .first()
      .click();

    cy.url({ timeout: 20000 }).should('match', /\/(login)?$/);
    cy.get('#sidebar-menu').should('not.exist');
  });
});

// ═══════════════════════════════════════════════════════════════════════════
// PROCEDIMENTOS COMPLETOS — o coração do trabalho do médico, ponta a ponta:
// atendimento (agenda → prontuário → salvar → finalizar), receituário
// (APIs exclusivas de médico), prompts de IA (CRUD) e a própria escala.
// Dados "CY-DOC" nascem por seed (e2e/scripts/seed-cydoc.php) e são
// totalmente removidos ao final. NÃO assinamos prontuário: assinatura trava
// o registro por compliance (Signable/CFM) e criaria resíduo indelével.
// ═══════════════════════════════════════════════════════════════════════════

describe('Perfil clinic.doctor — procedimentos completos', () => {
  beforeEach(() => {
    cy.loginAs('clinic.doctor');
  });

  it('atendimento completo: Iniciar atendimento → prontuário → salvar → Finalizar consulta → Atendido', () => {
    // Agendamento de hoje pronto para atender (paciente CY-DOC + Dra. Ana).
    // Verifica o stdout do seed: falha de ambiente aparece aqui com clareza,
    // não como "card não encontrado" 15s depois.
    cy.exec(`cd .. && php artisan tinker --execute="require 'e2e/scripts/seed-cydoc.php';"`, { timeout: 40000 })
      .its('stdout').should('include', 'cydoc:');

    cy.visit('/panel/schedules');
    cy.expectPanelPage();

    // Sessão interativa pode carregar filtros/busca/data residuais da agenda
    // (persistem por sessão) — a BUSCA explícita isola o card do CY-DOC de
    // qualquer estado herdado; um reload cobre corrida de render.
    const findCyDocCard = () => {
      cy.get('input[placeholder]').filter((_, el) => /buscar|paciente/i.test(el.placeholder))
        .first().clear().type('CY-DOC');
      cy.wait(700); // debounce da busca
    };
    findCyDocCard();
    cy.get('body').then(($b) => {
      if ($b.find('.schedule-card:contains("CY-DOC PACIENTE")').length === 0) {
        cy.reload();
        cy.expectPanelPage();
        findCyDocCard();
      }
    });
    cy.contains('.schedule-card', 'CY-DOC PACIENTE', { timeout: 15000 }).should('be.visible');

    // Botão verde "Iniciar atendimento" (só médicos veem) abre o prontuário
    // DESTE agendamento; a abertura muda a situação para "Em consulta".
    cy.contains('.schedule-card', 'CY-DOC PACIENTE')
      .find('a.btn-success').first()
      .then(($a) => { $a[0].click(); });

    cy.url({ timeout: 20000 }).should('include', '/medicalrecords');
    cy.expectPanelPage();
    cy.contains('CY-DOC PACIENTE', { timeout: 15000 }).should('be.visible');

    // Salvar o prontuário (rascunho, sem assinar). Queixa principal satisfaz
    // a validação (main_complaint required_without observação) em QUALQUER
    // modo de prontuário — é o fluxo clínico real; "Finalizar" também passa
    // pelo submit do form, então o registro precisa ser válido.
    cy.get('.pmr-form', { timeout: 15000 }).should('exist');
    cy.get('.pmr-form textarea[placeholder^="Descreva a queixa"], .pmr-form textarea[placeholder^="Descreva livremente"]')
      .filter(':visible:not(:disabled)')
      .first().type('CY-DOC: atendimento de teste E2E — sem valor clínico.');
    cy.intercept('POST', '**/medicalrecords').as('storeRecord');
    cy.get('button.pmr-save-btn').first().click();
    cy.wait('@storeRecord', { timeout: 20000 }).then((i) => {
      expect(i.response.statusCode, `salvar prontuário: ${JSON.stringify(i.response.body).slice(0, 300)}`)
        .to.be.lessThan(400);
    });
    // Salvar MANTÉM a consulta aberta: redireciona pra EDIÇÃO do prontuário
    // criado (regex estrita — "create" não pode casar).
    cy.url({ timeout: 20000 }).should('match', /medicalrecords\/[0-9a-f]{8}-[0-9a-f-]{27}\/edit/);

    // Salvar ≠ Finalizar: com o prontuário aberto, a barra inferior oferece
    // "Finalizar consulta" direto (sem passar pelo "←"). Finalizar salva,
    // marca Atendido e volta pra AGENDA — o médico segue pro próximo.
    cy.get('button.pmr-flow-finish', { timeout: 10000 }).should('be.visible').click();
    cy.url({ timeout: 20000 }).should('include', '/panel/schedules');
    cy.expectPanelPage();

    // Na agenda, o card do CY-DOC está "Atendido" (busca explícita de novo —
    // imune a filtros residuais da sessão interativa).
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.get('input[placeholder]').filter((_, el) => /buscar|paciente/i.test(el.placeholder))
      .first().clear().type('CY-DOC');
    cy.contains('.schedule-card', 'CY-DOC PACIENTE', { timeout: 15000 })
      .should('contain.text', 'Atendido');

    // Limpeza total (prontuário não assinado, agendamento, paciente).
    cy.exec(`cd .. && php artisan tinker --execute="require 'e2e/scripts/clean-cydoc.php';"`, { failOnNonZeroExit: false, timeout: 40000 });
  });

  it('prontuário completo: atestado, evolução, anexo, tonometria e Assistente de IA', () => {
    cy.exec(`cd .. && php artisan tinker --execute="require 'e2e/scripts/seed-cydoc.php';"`, { timeout: 40000 })
      .its('stdout').should('include', 'cydoc:');

    // Runtime de IA: geração de verdade SÓ com provider fake (não gastar
    // créditos reais do usuário sem consentimento).
    cy.exec(`cd .. && php artisan tinker --execute="echo config('ai.provider_runtime');"`, { timeout: 30000 })
      .its('stdout').then((runtime) => {
        cy.wrap(runtime.trim().split('\n').pop()).as('aiRuntime');
      });

    // Entra no atendimento e salva o prontuário (destrava a barra de docs).
    cy.visit('/panel/schedules');
    cy.get('input[placeholder]').filter((_, el) => /buscar|paciente/i.test(el.placeholder))
      .first().clear().type('CY-DOC');
    cy.contains('.schedule-card', 'CY-DOC PACIENTE', { timeout: 15000 })
      .find('a.btn-success').first().then(($a) => { $a[0].click(); });
    cy.url({ timeout: 20000 }).should('include', '/medicalrecords');
    cy.get('.pmr-form', { timeout: 15000 }).should('exist');

    // Queixa principal: satisfaz a validação (required_without observação)
    // em QUALQUER modo de prontuário — é o fluxo clínico real.
    cy.get('.pmr-form textarea[placeholder^="Descreva a queixa"], .pmr-form textarea[placeholder^="Descreva livremente"]')
      .filter(':visible:not(:disabled)')
      .first().type('CY-DOC: queixa de teste E2E, baixa acuidade visual.');

    // Tonometria: OD/OE preenchidos ANTES do salvar (ficam no registro).
    cy.get('.pmr-form input[placeholder="00"]').eq(0).clear().type('12');
    cy.get('.pmr-form input[placeholder="00"]').eq(1).clear().type('14');

    cy.get('button.pmr-save-btn').first().click();
    // Salvar MANTÉM a consulta aberta: o 1º save (com schedule_id) volta pro
    // EDIT do prontuário com a barra de documentos liberada — sem passar pela
    // Agenda. A URL de edição fica registrada pra reuso no fim do cenário.
    cy.url({ timeout: 20000 }).should('match', /medicalrecords\/[0-9a-f-]+\/edit/);
    cy.url().then((u) => cy.wrap(u).as('mrUrl'));
    cy.get('.pmr-form', { timeout: 15000 }).should('exist');

    // ── Atestado médico (quick action com payload dias) ────────────────────
    // issueQuickAction tem guards com alert() (médico não selecionado etc.) —
    // Cypress aceita alerts em silêncio; aqui viram erro com a mensagem.
    cy.on('window:alert', (msg) => { throw new Error(`ALERT da aplicação: ${msg}`); });
    cy.intercept('POST', '**/quick-actions/medical-certificate').as('cert');
    // Dois botões contêm "Atestado" — mirar no "Atestado Médico" (tem o
    // campo de dias); o de Comparecimento é outro quick-action.
    cy.get('.pmr-doc-img-btn-label')
      .filter((_, el) => /M[ée]dico/.test(el.textContent))
      .first().closest('button').click({ force: true });
    cy.get('.ee-modal__dialog:visible, .modal.show, .modal.d-block', { timeout: 10000 })
      .last().within(() => {
        // O preview de dias re-renderiza o modal no input — type() perderia o
        // nó; invoke('val')+trigger atualiza o v-model deterministicamente.
        cy.get('input[type=number]:visible', { timeout: 15000 })
          .first().should('not.be.disabled')
          .invoke('val', '2').trigger('input');
        // "Emitir" fica desabilitado enquanto a pré-visualização carrega —
        // aguardar habilitar (force:true em botão disabled não dispara JS).
        cy.contains('button', /Emitir/, { timeout: 15000 })
          .should('not.be.disabled')
          .click();
      });
    // Emissão via fetch: comprovar o POST e o documento na tela de edição.
    cy.wait('@cert', { timeout: 20000 }).then(({ response }) => {
      expect(response.statusCode, `atestado: ${JSON.stringify(response.body).slice(0, 300)}`)
        .to.be.lessThan(400);
    });
    // Preview de PDF (PdfPreviewModal) não fecha de forma confiável com Esc
    // no Cypress — o iframe do PDF rouba o foco do keydown. Fechar pelo
    // botão explícito, mesmo padrão já usado pro modal de anexo abaixo.
    cy.get('.modal.d-block:visible .btn-close', { timeout: 10000 }).last().click({ force: true });
    cy.get('.pmr-form', { timeout: 15000 }).should('exist');
    cy.contains(/Atestado/, { timeout: 15000 }).should('exist');

    // ── Evolução clínica ───────────────────────────────────────────────────
    cy.intercept('POST', '**/evolutions').as('evo');
    cy.contains('.pmr-doc-img-btn-label', /Evolução/).first().closest('button').click({ force: true });
    cy.get('.ee-modal__dialog:visible, .modal.show, .modal.d-block', { timeout: 10000 })
      .last().within(() => {
        cy.get('textarea:visible').first().type('CY-DOC evolução de teste E2E.');
        cy.contains('button', /Registrar|Salvar/).click({ force: true });
      });
    cy.wait('@evo', { timeout: 20000 }).its('response.statusCode').should('be.lessThan', 400);
    cy.get('body').type('{esc}');

    // ── Anexo (upload real — valida também o disco private) ────────────────
    cy.intercept('POST', '**/files').as('upFile');
    cy.contains('.pmr-doc-img-btn-label', /Anexo/).first().closest('button').click({ force: true });
    cy.get('.ee-modal__dialog:visible, .modal.show, .modal.d-block', { timeout: 10000 })
      .last().within(() => {
        // PDF mínimo 100% ASCII — modal valida por extensão (quota.accept_mimes)
        // e servidor por conteúdo (mimes:). PDF em texto puro sobrevive ao proxy
        // do cy.intercept, que corrompe corpos multipart binários (PNG falha 422).
        const miniPdf = [
          '%PDF-1.4',
          '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
          '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
          '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj',
          'xref', '0 4', 'trailer<</Size 4/Root 1 0 R>>', '%%EOF', '',
        ].join('\n');
        cy.get('input[type=file]').first().selectFile({
          contents: Cypress.Buffer.from(miniPdf),
          fileName: 'cy-doc-anexo.pdf',
          mimeType: 'application/pdf',
        }, { force: true });
        cy.contains('button', /Enviar/, { timeout: 15000 })
          .should('not.be.disabled').click();
      });
    cy.wait('@upFile', { timeout: 20000 }).then(({ response }) => {
      expect(response.statusCode, `upload anexo: ${JSON.stringify(response.body).slice(0, 500)}`)
        .to.be.lessThan(400);
    });
    cy.get('body').type('{esc}');

    // ── Exames de imagem (módulo Eye Images dentro do prontuário) ──────────
    // O modal de anexo não fecha com Esc — fechar pelo botão "Fechar".
    cy.get('.modal.d-block:visible').last().within(() => {
      cy.contains('button', 'Fechar').click();
    });
    cy.get('.upload-dropzone').should('not.exist');
    cy.intercept('GET', '**/eye-images/patient-exams/**').as('eyeExams');
    cy.contains('.pmr-doc-img-btn-label', /Exames de/).first().closest('button')
      .click({ force: true });
    cy.wait('@eyeExams').its('response.statusCode').should('eq', 200);
    // CY-DOC não tem exames — estado vazio do painel.
    cy.contains(/Nenhum exame de imagem/, { timeout: 10000 }).should('be.visible');
    cy.get('body').type('{esc}');
    cy.get('.modal.d-block .btn-close').first().click({ force: true });
    cy.wait(300);

    // ── Assistente de IA ───────────────────────────────────────────────────
    cy.get('.ai-fab', { timeout: 10000 }).should('be.visible').click();
    cy.get('.ai-floating-assistant', { timeout: 10000 })
      .find('textarea, input[type=text]').filter(':visible').should('exist');
    cy.get('@aiRuntime').then((runtime) => {
      if (runtime === 'fake') {
        cy.get('.ai-floating-assistant').find('textarea:visible, input[type=text]:visible')
          .first().type('Resuma o paciente');
        cy.get('.ai-floating-assistant').find('button[type=submit], button:has(i.ti-send), button')
          .filter((_, el) => /enviar|send/i.test((el.title || '') + el.innerHTML))
          .first().click({ force: true });
        cy.get('.ai-floating-assistant', { timeout: 30000 })
          .should('contain.text', 'CY-DOC PACIENTE');
      } else {
        cy.log(`AI runtime="${runtime}" — geração pulada (não gastar créditos reais)`);
      }
    });

    // Limpeza total.
    cy.exec(`cd .. && php artisan tinker --execute="require 'e2e/scripts/clean-cydoc.php';"`, { failOnNonZeroExit: false, timeout: 40000 });
  });

  it('receituário: APIs exclusivas do médico respondem (presets, medicamentos, CID-10)', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();

    // medication-presets é DOCTOR-ONLY (entity.role:doctor) — prova o acesso.
    cy.request('/panel/medication-presets').then((r) => {
      expect(r.status).to.eq(200);
    });
    cy.request('/panel/medicines/search?q=olho').its('status').should('eq', 200);
    cy.request('/panel/cid10/search?q=glaucoma').its('status').should('eq', 200);
  });

  it('prompts de IA: criar, ver na lista e excluir (CRUD exclusivo do médico)', () => {
    const NAME = `CY prompt ${Date.now().toString().slice(-6)}`;
    cy.visit('/panel/setting/ai-prompts');
    cy.expectPanelPage('Meus prompts de IA');

    cy.contains('button', 'Novo prompt').click();
    cy.get('.modal.show, .modal.d-block').should('be.visible').within(() => {
      cy.get('input[type=text]:visible').first().type(NAME);
      cy.get('textarea:visible').first().type('Resuma o quadro clínico do paciente em 3 linhas.');
      cy.contains('button', /Salvar|Criar/).click();
    });
    cy.get('.modal.show, .modal.d-block', { timeout: 10000 }).should('not.exist');
    cy.contains(NAME, { timeout: 10000 }).should('be.visible');

    cy.on('window:confirm', () => true);
    cy.contains(NAME).closest('div:has(button)')
      .find('button').filter((_, el) => /excluir|trash/i.test((el.title || '') + el.textContent + el.innerHTML))
      .first().click({ force: true });
    cy.contains(NAME, { timeout: 10000 }).should('not.exist');
  });

  it('página de escala de médicos também nega (vive sob /panel/doctors)', () => {
    // A escala é gerida por admin/secretária (coberta na spec da secretária).
    // O médico segue vendo colegas/slots pelo contexto da AGENDA.
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    cy.request({ url: '/panel/doctors/cards', failOnStatusCode: false })
      .its('status').should('eq', 403);
  });
});
