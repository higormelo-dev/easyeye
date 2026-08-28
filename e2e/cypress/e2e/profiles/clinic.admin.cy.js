// Perfil clinic.admin (admin@clinicateste.com, rule admin, CLÍNICA TESTE INTEGRADOR).
// Admin de clínica tem bypass total de permissões (hasPermissionInEntity) —
// vê todos os grupos do menu e acessa todos os catálogos permission:settings.manage.
// Negações esperadas: /panel/manager/* (302 + flash, EnsureSaasAdmin),
// rotas doctor-only (403) e portal de parceiros (403).

// ── Matriz de páginas permitidas ─────────────────────────────────────────────
// marker: selector = CSS visível; heading = texto dentro de h4 no conteúdo;
// text = texto no conteúdo (.page-wrapper, para não casar com links do sidebar).
const ALLOWED = [
  { url: '/panel/dashboard',                 label: 'Painel de controle',        selector: '.page-dashboard', text: 'Personalizar' },
  { url: '/panel/schedules',                 label: 'Agendas',                   heading: 'Agenda' },
  { url: '/panel/patients',                  label: 'Pacientes',                 heading: 'Pacientes' },
  { url: '/panel/doctors',                   label: 'Médicos',                   heading: 'Médicos' },
  { url: '/panel/eye-images',                label: 'Imagens oftálmicas',        heading: 'Imagens oftálmicas' },
  { url: '/panel/ai/usage',                  label: 'Assistente de IA (consumo)', heading: 'Assistente de IA' },
  { url: '/panel/financial/bi',              label: 'Dashboard Gerencial',       text: 'Dashboard Gerencial' },
  { url: '/panel/financial/cash-flow',       label: 'Fluxo de Caixa',            heading: 'Fluxo de Caixa' },
  { url: '/panel/financial/billing',         label: 'Faturamento TISS',          text: 'Faturamento TISS' },
  { url: '/panel/financial/tiss/glosas',     label: 'Conciliação de Glosas',     text: 'Conciliação de Glosas' },
  { url: '/panel/financial/reports/cash-flow', label: 'Rel. Fluxo de Caixa',     text: 'Relatório de Fluxo de Caixa' },
  { url: '/panel/financial/reports/covenants', label: 'Rel. Faturamento',        text: 'Relatório de Faturamento por Convênio' },
  { url: '/panel/reports',                   label: 'Relatórios',                heading: 'Relatórios' },
  { url: '/panel/setting/resources',         label: 'Unidades / salas',          heading: 'Recursos' },
  { url: '/panel/setting/security',          label: 'Segurança (2FA)',           heading: 'Autenticação em dois fatores' },
  { url: '/panel/setting/call-panel',        label: 'Painel de chamadas',        text: 'Painel de chamadas' },
  { url: '/panel/setting/covenants',         label: 'Convênios',                 heading: 'Convênios' },
  { url: '/panel/setting/visittypes',        label: 'Tipos de atendimento',      heading: 'Tipos de atendimento' },
  { url: '/panel/setting/surgerytypes',      label: 'Tipos de cirurgia',         heading: 'Tipos de cirurgia' },
  { url: '/panel/setting/iollenses',         label: 'Lentes de Catarata (IOL)',  heading: 'Lentes de Catarata' },
  { url: '/panel/accesscontrol/users',       label: 'Usuários',                  heading: 'Usuários' },
  { url: '/panel/accesscontrol/roles',       label: 'Perfis e permissões',       heading: 'Perfis de acesso' },
  { url: '/panel/setting/report-settings',   label: 'Modelos de documento',      heading: 'Modelos de Documento' },
  { url: '/panel/setting/skintypes',         label: 'Parâmetros oftalmológicos', heading: 'Tipos de cútis' },
];

// Links que DEVEM existir no #sidebar-menu do admin (PanelNavigation::build,
// rule admin → todos os grupos). IA é link direto p/ /panel/ai/usage (sem
// submenu — submenu de IA é só doctor).
const MENU_HREFS = ALLOWED.map((i) => i.url);

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Navega até `url` clicando no link real do #sidebar-menu (abre o submenu
 * pai se estiver fechado). Se o menu não tiver o link, cai para cy.visit.
 * Pressupõe que a página atual já tem o sidebar montado.
 */
function goViaMenu(url) {
  cy.get('#sidebar-menu', { timeout: 15000 }).should('be.visible');
  cy.get('#sidebar-menu').then(($menu) => {
    const $link = $menu.find(`a[href$="${url}"]`);
    if (!$link.length) {
      cy.visit(url);
      return;
    }
    const $group = $link.closest('li.submenu');
    if ($group.length && !$link.is(':visible')) {
      // Abre o submenu pai (toggle é o <a href="#"> direto do li.submenu).
      cy.wrap($group.children('a').first()).click();
    }
    cy.get(`#sidebar-menu a[href$="${url}"]`).first().should('be.visible').click();
  });
  cy.url({ timeout: 20000 }).should('include', url);
}

/** Asserta os markers da página SEMPRE dentro de .page-wrapper (nunca sidebar). */
function assertPageMarkers(item) {
  cy.expectPanelPage();
  if (item.selector) {
    cy.get(item.selector, { timeout: 15000 }).should('be.visible');
  }
  if (item.heading) {
    cy.get('.page-wrapper', { timeout: 15000 })
      .contains('h4', item.heading)
      .should('be.visible');
  }
  if (item.text) {
    cy.get('.page-wrapper', { timeout: 15000 })
      .contains(item.text)
      .should('be.visible');
  }
}

// ── Specs ────────────────────────────────────────────────────────────────────

describe('Perfil clinic.admin — landing, menu, acessos e negações', () => {
  beforeEach(() => {
    cy.loginAs('clinic.admin');
  });

  // (a) Landing correta. A criação da cy.session já asserta que o POST /login
  // termina em /panel/dashboard; aqui validamos que o painel monta de verdade
  // com a entity certa no sidebar.
  it('landing pós-login: /panel/dashboard monta com a entity selecionada', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    cy.get('.sidebar-top h6').should('contain.text', 'CLÍNICA TESTE INTEGRADOR');
    cy.get('.page-dashboard').should('be.visible');
  });

  // Estrutura do menu: admin vê TODOS os grupos (base + Médicos + Imagens +
  // IA direto + Financeiro c/ 6 filhos + Relatórios + 5 grupos de Configurações).
  it('menu lateral contém link para todas as áreas do admin', () => {
    cy.visit('/panel/dashboard');
    cy.get('#sidebar-menu', { timeout: 15000 }).should('be.visible');

    MENU_HREFS.forEach((href) => {
      cy.get(`#sidebar-menu a[href$="${href}"]`).should('have.length', 1);
    });

    // IA para admin é link direto (sem submenu — submenu de IA é doctor-only).
    cy.get('#sidebar-menu a[href$="/panel/ai/usage"]')
      .parents('li.submenu')
      .should('not.exist');

    // Grupo Financeiro tem exatamente 6 filhos.
    cy.get('#sidebar-menu a[href$="/panel/financial/bi"]')
      .closest('li.submenu')
      .find('ul li')
      .should('have.length', 6);
  });

  // (b) Cada página permitida: navegação REAL pelo menu (clique no href;
  // submenu pai é aberto antes) + marker de conteúdo montado.
  ALLOWED.forEach((item) => {
    it(`acessa ${item.label} (${item.url})`, () => {
      cy.visit('/panel/dashboard');
      goViaMenu(item.url);
      assertPageMarkers(item);
    });
  });

  // ── (c) Negações ──────────────────────────────────────────────────────────

  it('nega /panel/manager/dashboard: 302 → /panel/dashboard + flash de erro', () => {
    // EnsureSaasAdmin redireciona (NÃO é 403). Flash auto-dismiss em 6s —
    // assertar imediatamente após o load.
    cy.visit('/panel/manager/dashboard');
    cy.url({ timeout: 20000 }).should('match', /\/panel\/dashboard(\?|$)/);
    cy.get('div.alert.alert-danger', { timeout: 6000 })
      .should('be.visible')
      .and('contain.text', 'Esta área é exclusiva do administrador do SaaS.');
    cy.expectPanelPage();
  });

  it('nega /panel/manager/entities: redirect para fora (EnsureSaasAdmin)', () => {
    cy.expectForbidden('/panel/manager/entities');
  });

  it('nega /panel/setting/ai-prompts: 403 direto (doctor-only)', () => {
    cy.request({ url: '/panel/setting/ai-prompts', failOnStatusCode: false })
      .its('status')
      .should('eq', 403);
    // Full-page: página de erro Laravel, sem painel montado.
    cy.visit('/panel/setting/ai-prompts', { failOnStatusCode: false });
    cy.get('#sidebar-menu').should('not.exist');
  });

  it('nega /panel/medication-presets: 403 direto (doctor-only)', () => {
    cy.request({ url: '/panel/medication-presets', failOnStatusCode: false })
      .its('status')
      .should('eq', 403);
  });

  it('nega /portal/dashboard: 403 direto (EnsureIsPartner)', () => {
    cy.request({ url: '/portal/dashboard', failOnStatusCode: false })
      .its('status')
      .should('eq', 403);
    // Sessão do painel continua viva após as negações.
    cy.visit('/panel/dashboard');
    cy.get('#sidebar-menu', { timeout: 15000 }).should('be.visible');
  });

  // ── (d) Interações não-destrutivas na área central (Pacientes) ────────────

  it('Pacientes: abre o offcanvas "Novo paciente" e fecha sem salvar', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('button', 'Novo paciente').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 })
      .should('be.visible')
      .and('contain.text', 'Novo Paciente');
    // Fecha pelo X do header — nada é salvo.
    cy.get('.ee-modal__header button.btn-close').click();
    cy.get('.ee-modal__dialog').should('not.exist');
  });

  it('Pacientes: busca reage e mostra estado vazio para termo inexistente', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage();
    cy.get('.table-search input.form-control')
      .should('be.visible')
      .type('zzz-cy-nao-existe-20260828');
    // Debounce 400ms → Inertia GET com ?search=...
    cy.url({ timeout: 10000 }).should('include', 'search=');
    cy.get('.page-wrapper').contains('Nenhum paciente encontrado.').should('be.visible');
    // Limpa a busca pelo botão X do input (só existe com termo digitado).
    cy.get('.table-search button.btn-outline-secondary').click();
    cy.get('.table-search input.form-control').should('have.value', '');
  });

  // ── (e) Logout ────────────────────────────────────────────────────────────

  it('logout pelo dropdown do usuário encerra a sessão', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();

    cy.get('.profile-dropdown a.dropdown-toggle').click();
    cy.get('.profile-dropdown .dropdown-menu').should('be.visible');
    cy.get('.profile-dropdown button.dropdown-item.text-danger')
      .filter((_, el) => /Sair/.test(el.textContent) && !/impersona/i.test(el.textContent))
      .first()
      .click();

    // POST /logout → Inertia::location('/') (full reload no site público).
    cy.url({ timeout: 20000 }).should('match', /\/(login)?$/);
    cy.get('#sidebar-menu').should('not.exist');
  });
});
