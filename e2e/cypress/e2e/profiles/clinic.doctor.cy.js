// Perfil clinic.doctor (dra.ana@clinicateste.com, rule doctor, CLÍNICA TESTE INTEGRADOR).
// Matriz: landing /panel/dashboard; allowed: dashboard, agendas, pacientes,
// imagens oftálmicas, IA (submenu ÚNICO deste perfil: Consumo & dashboard +
// Meus prompts), fila de espera (sem menu); forbidden: accesscontrol, setting
// resources, financeiro, relatórios, compliance, manager.
// Regra do projeto: menu ≠ autorização — doctor NÃO vê "Médicos" no menu,
// mas GET /panel/doctors responde 200 (permission:patients.manage inclui doctor).

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
  it('não vê "Médicos" no menu, mas GET /panel/doctors responde 200', () => {
    cy.visit('/panel/dashboard');
    cy.get('#sidebar-menu a[href$="/panel/doctors"]').should('not.exist');
    cy.get('#sidebar-menu').contains('a', 'Médicos').should('not.exist');

    cy.request({ url: '/panel/doctors', failOnStatusCode: false })
      .its('status')
      .should('eq', 200);
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
