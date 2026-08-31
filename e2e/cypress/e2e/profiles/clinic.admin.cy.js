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
  { url: '/panel/setting/iollenses',         label: 'Lentes de Catarata (IOL)',  heading: 'Lentes de catarata' },
  { url: '/panel/accesscontrol/users',       label: 'Usuários',                  heading: 'Controle de Acesso' },
  { url: '/panel/accesscontrol/roles',       label: 'Perfis e permissões',       heading: 'Perfis de acesso' },
  { url: '/panel/setting/report-settings',   label: 'Modelos de documento',      heading: 'Modelos de documentação' },
  { url: '/panel/setting/skintypes',         label: 'Parâmetros oftalmológicos', heading: 'Tipos de cútis' },
];

// Links que DEVEM existir no #sidebar-menu do admin (PanelNavigation::build,
// rule admin → todos os grupos). IA é link direto p/ /panel/ai/usage (sem
// submenu — submenu de IA é só doctor).
const MENU_HREFS = ALLOWED.map((i) => i.url);

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * O painel abre por padrão com sidebar MINI (body.mini-sidebar): os links
 * viram ícones (~39px) e o tema legado (preclinic-script jQuery) expande no
 * hover (mouseover no doc → body.expand-menu, com transição de largura).
 * Clicar durante a animação faz o clique "morrer" fora do <a> — expandir
 * ANTES de qualquer clique no menu.
 */
function expandSidebar() {
  cy.get('body').then(($body) => {
    if ($body.hasClass('mini-sidebar')) {
      cy.get('#sidebar').trigger('mouseover', { force: true });
      cy.get('body').should('have.class', 'expand-menu');
      cy.wait(400); // transição de largura do tema terminar
    }
  });
}

/**
 * Abre (se fechado) o submenu que contém o link `url`. O toggle tem DOIS
 * handlers concorrentes — Vue (@click.prevent → style display:block) e o
 * jQuery do tema (slideUp/slideDown 250/350ms) — que podem correr entre si;
 * se o <ul> não ficar visível após as animações, clica de novo.
 */
function ensureSubmenuOpenFor(url) {
  cy.get(`#sidebar-menu a[href$="${url}"]`).first().parents('li.submenu').first().as('grp');
  cy.get('@grp').children('ul').then(($ul) => {
    if ($ul.is(':visible')) return;
    cy.get('@grp').children('a').first().click();
    cy.wait(700); // animações do tema (slideUp 250ms / slideDown 350ms)
    cy.get('@grp').children('ul').then(($ul2) => {
      if (!$ul2.is(':visible')) {
        cy.get('@grp').children('a').first().click();
        cy.wait(700);
      }
    });
  });
}

/**
 * Navega até `url` clicando no link real do #sidebar-menu (expande o sidebar
 * mini e abre o submenu pai se preciso). Se o menu não tiver o link, cai
 * para cy.visit. Pressupõe que a página atual já tem o sidebar montado.
 */
function goViaMenu(url) {
  cy.get('#sidebar-menu', { timeout: 15000 }).should('be.visible');
  expandSidebar();
  cy.get('#sidebar-menu').then(($menu) => {
    const $link = $menu.find(`a[href$="${url}"]`);
    if (!$link.length) {
      cy.visit(url);
      return;
    }
    if ($link.closest('li.submenu').length) {
      ensureSubmenuOpenFor(url);
    }
    // Clique nativo: em sidebar "mini" o <a> existe mas fica coberto pelo
    // próprio sidebar (position: fixed) — o click visível do Cypress trava.
    cy.get(`#sidebar-menu a[href$="${url}"]`).first().then(($a) => { $a[0].click(); });
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

// ═════════════════════════════════════════════════════════════════════════════
// PROCEDIMENTOS COMPLETOS — cobertura total do que o admin da clínica FAZ.
// Dados de teste prefixados CY-ADM; seed/clean via e2e/scripts/{seed,clean}-cyadm.php.
// ═════════════════════════════════════════════════════════════════════════════

const ADM_STAMP = String(Date.now()).slice(-7);
const SEED_ADM  = `cd .. && php artisan tinker --execute="require 'e2e/scripts/seed-cyadm.php';"`;
const CLEAN_ADM = `cd .. && php artisan tinker --execute="require 'e2e/scripts/clean-cyadm.php';"`;

/** CPF matematicamente válido (mesmo gerador do spec da secretária). */
function cpfValidoAdm(seed) {
  const n = [];
  let s = seed;
  for (let i = 0; i < 9; i++) { s = (s * 9301 + 49297) % 233280; n.push(s % 10); }
  const dv = (base, factor) => {
    let total = 0;
    base.forEach((d, i) => { total += d * (factor - i); });
    const r = (total * 10) % 11;
    return r === 10 ? 0 : r;
  };
  n.push(dv(n, 10));
  n.push(dv(n, 11));
  return n.join('');
}

/**
 * Request autenticado com CSRF do cookie XSRF-TOKEN (endpoints fetch/JSON do
 * painel). failOnStatusCode:false — o assert de status fica no teste.
 */
function csrfRequest(method, url, body = undefined) {
  return cy.getCookie('XSRF-TOKEN').then((c) => cy.request({
    method,
    url,
    body,
    headers: {
      'X-XSRF-TOKEN': decodeURIComponent(c.value),
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
    failOnStatusCode: false,
  }));
}

/** Campo do CatalogFormModal (inputs sem name/id — localiza pelo label). */
function catalogField(label) {
  return cy.get('.modal.d-block').contains('label', label)
    .parent().find('input:visible, textarea:visible').first();
}

/** Abre o dropdown ⋮ da linha (menu é TELEPORTADO pro body). */
function openRowMenu(rowText) {
  cy.contains('tr', rowText).find('button')
    .filter((_, el) => !!el.querySelector('i.ti-dots-vertical'))
    .first().click();
  return cy.get('.dropdown-menu.show', { timeout: 10000 });
}

/** Cria item num catálogo genérico já aberto (modal 'Novo registro'). */
function createCatalogItem(name, extraFn) {
  cy.get('.page-wrapper .border-bottom button.btn-primary').first().click();
  cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible')
    .and('contain.text', 'Novo registro');
  catalogField('Nome').type(name);
  if (extraFn) extraFn();
  cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
  cy.get('.modal.d-block').should('not.exist');
  cy.contains('td', name, { timeout: 10000 }).should('exist');
}

/** Exclui (soft) item de catálogo — confirm() nativo auto-aceito no teste. */
function deleteCatalogItem(name) {
  openRowMenu(name).contains('button', 'Excluir').click();
  // Listagem é withTrashed: a linha VIRA 'Removido', não some.
  cy.contains('tr', name, { timeout: 10000 })
    .should('have.class', 'table-secondary')
    .and('contain.text', 'Removido');
}

describe('Perfil clinic.admin — catálogos clínicos (CRUD real)', () => {
  before(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
  });
  beforeEach(() => {
    cy.loginAs('clinic.admin');
    cy.on('window:confirm', () => true);
  });
  after(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
  });

  it('visittypes: ciclo completo — criar, editar, desativar, ativar, excluir, restaurar', () => {
    cy.visit('/panel/setting/visittypes');
    cy.expectPanelPage();
    cy.intercept('POST', '**/setting/visittypes').as('storeVt');
    createCatalogItem('CY-ADM TIPO ATENDIMENTO');
    cy.wait('@storeVt').its('response.statusCode').should('eq', 200);

    // Editar (modal hidrata via GET show — aguardar sair o 'Carregando...').
    openRowMenu('CY-ADM TIPO ATENDIMENTO').contains('button', 'Editar').click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('contain.text', 'Editar registro');
    cy.get('.modal.d-block').should('not.contain.text', 'Carregando');
    catalogField('Nome').should('not.have.value', '').clear().type('CY-ADM TIPO EDITADO');
    cy.get('.modal.d-block .modal-footer').contains('button', 'Salvar').click();
    cy.get('.modal.d-block').should('not.exist');
    cy.contains('td', 'CY-ADM TIPO EDITADO', { timeout: 10000 }).should('exist');

    // Desativar / reativar pelo dropdown (sem confirm).
    openRowMenu('CY-ADM TIPO EDITADO').contains('button', 'Desativar').click();
    cy.contains('tr', 'CY-ADM TIPO EDITADO', { timeout: 10000 }).should('contain.text', 'Inativo');
    openRowMenu('CY-ADM TIPO EDITADO').contains('button', /^\s*Ativar/).click();
    cy.contains('tr', 'CY-ADM TIPO EDITADO', { timeout: 10000 }).should('contain.text', 'Ativo');

    // Excluir (soft) — linha vira 'Removido'; restaurar volta a 'Ativo'.
    deleteCatalogItem('CY-ADM TIPO EDITADO');
    cy.contains('tr', 'CY-ADM TIPO EDITADO').find('button[title="Restaurar"]').click();
    cy.contains('tr', 'CY-ADM TIPO EDITADO', { timeout: 10000 })
      .should('not.have.class', 'table-secondary')
      .and('contain.text', 'Ativo');
  });

  it('surgerytypes: criar com categoria obrigatória (SearchSelect) e excluir', () => {
    cy.visit('/panel/setting/surgerytypes');
    cy.expectPanelPage();
    createCatalogItem('CY-ADM TIPO CIRURGIA', () => {
      cy.get('.modal.d-block .multiselect').first().click();
      cy.get('.multiselect-option:visible').contains(/CATARATA/i).click({ force: true });
    });
    deleteCatalogItem('CY-ADM TIPO CIRURGIA');
  });

  it('covertesttypes: abreviação é obrigatória no backend (form não sinaliza) — 422 e depois sucesso', () => {
    cy.visit('/panel/setting/covertesttypes');
    cy.expectPanelPage();
    cy.intercept('POST', '**/setting/covertesttypes').as('storeCtt');

    // Sem abreviação: backend nega (CoverTestTypeRequest) apesar do form
    // Vue não marcar o campo como required — pegadinha real de produto.
    cy.get('.page-wrapper .border-bottom button.btn-primary').first().click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    catalogField('Nome').type('CY-ADM COVER TEST');
    cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
    cy.wait('@storeCtt').its('response.statusCode').should('eq', 422);
    cy.get('.modal.d-block .invalid-feedback').should('be.visible');

    catalogField('Abreviação').type('CYT');
    cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
    cy.wait('@storeCtt').its('response.statusCode').should('eq', 200);
    cy.get('.modal.d-block').should('not.exist');
    cy.contains('td', 'CY-ADM COVER TEST', { timeout: 10000 }).should('exist');
    deleteCatalogItem('CY-ADM COVER TEST');
  });

  it('visualacuitytypes: escala aceita só INTEIRO no backend — criar e excluir', () => {
    cy.visit('/panel/setting/visualacuitytypes');
    cy.expectPanelPage();
    createCatalogItem('CY-ADM ACUIDADE', () => {
      catalogField('Escala').type('20');
    });
    deleteCatalogItem('CY-ADM ACUIDADE');
  });

  it('lenses: exige ao menos um entre Longe/Perto (422) — criar com Longe e excluir', () => {
    cy.visit('/panel/setting/lenses');
    cy.expectPanelPage();
    cy.intercept('POST', '**/setting/lenses').as('storeLens');

    cy.get('.page-wrapper .border-bottom button.btn-primary').first().click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    catalogField('Nome').type('CY-ADM LENTE');
    // Ambos os switches desmarcados → validação at_least_one_required.
    cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
    cy.wait('@storeLens').its('response.statusCode').should('eq', 422);

    cy.get('.modal.d-block input#field_away').check({ force: true });
    cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
    cy.wait('@storeLens').its('response.statusCode').should('eq', 200);
    cy.get('.modal.d-block').should('not.exist');
    cy.contains('td', 'CY-ADM LENTE', { timeout: 10000 }).should('exist');
    deleteCatalogItem('CY-ADM LENTE');
  });

  // Catálogos "só nome": criar + excluir cada um (mesma página genérica).
  [
    ['skintypes',             'Tipos de cútis',                 'CY-ADM CUTIS'],
    ['iristypes',             'Tipos de iris',                  'CY-ADM IRIS'],
    ['additiontypes',         'Tipos de adição',                'CY-ADM ADICAO'],
    ['colorvisiontypes',      'Tipos de visão cromática',       'CY-ADM CROMATICA'],
    ['nearpointconvergences', 'Convergências de ponto próximo', 'CY-ADM PPC'],
  ].forEach(([slug, heading, name]) => {
    it(`${slug}: criar e excluir (heading "${heading}")`, () => {
      cy.visit(`/panel/setting/${slug}`);
      cy.expectPanelPage();
      cy.get('.page-wrapper').contains('h4', heading).should('be.visible');
      createCatalogItem(name);
      deleteCatalogItem(name);
    });
  });

  it('busca de catálogo: termo inexistente mostra estado vazio', () => {
    cy.visit('/panel/setting/visittypes');
    cy.expectPanelPage();
    cy.get('.input-group input.form-control').first()
      .type('zzz-cyadm-nao-existe');
    cy.contains('Nenhum registro cadastrado.', { timeout: 10000 }).should('be.visible');
  });
});

describe('Perfil clinic.admin — convênios, recursos, lentes IOL e painel de chamadas', () => {
  beforeEach(() => {
    cy.loginAs('clinic.admin');
    cy.on('window:confirm', () => true);
  });
  after(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
  });

  it('convênio: criar (nome vira MAIÚSCULAS), editar, excluir e restaurar', () => {
    cy.visit('/panel/setting/covenants');
    cy.expectPanelPage();
    cy.intercept('POST', '**/setting/covenants').as('storeCov');

    cy.get('.page-wrapper .border-bottom button.btn-primary').first().click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    catalogField('Nome').type('cy-adm convenio'); // minúsculas de propósito
    cy.get('.modal.d-block input[type=color]')
      .invoke('val', '#1f2e3d').trigger('input').trigger('change');
    cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
    cy.wait('@storeCov').its('response.statusCode').should('eq', 200);
    // prepareForValidation converte para caps.
    cy.contains('td', 'CY-ADM CONVENIO', { timeout: 10000 }).should('exist');

    openRowMenu('CY-ADM CONVENIO').contains('button', 'Editar').click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('contain.text', 'Editar registro');
    cy.get('.modal.d-block').should('not.contain.text', 'Carregando');
    catalogField('Nome').clear().type('CY-ADM CONV EDIT');
    cy.get('.modal.d-block .modal-footer').contains('button', 'Salvar').click();
    cy.get('.modal.d-block').should('not.exist');
    cy.contains('td', 'CY-ADM CONV EDIT', { timeout: 10000 }).should('exist');

    deleteCatalogItem('CY-ADM CONV EDIT');
    cy.contains('tr', 'CY-ADM CONV EDIT').find('button[title="Restaurar"]').click();
    cy.contains('tr', 'CY-ADM CONV EDIT', { timeout: 10000 }).should('contain.text', 'Ativo');
  });

  it('recurso/sala: "Outro" é armadilha (422 — backend só aceita Sala/Equipamento); criar Sala e excluir', () => {
    cy.visit('/panel/setting/resources');
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('h4', 'Recursos').should('be.visible');
    cy.intercept('POST', '**/setting/resources').as('storeRes');

    cy.get('.page-wrapper .border-bottom button.btn-primary').first().click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    catalogField('Nome').type('CY-ADM SALA TESTE');
    // 'Outro' existe no form mas o backend valida in:room,equipment → 422.
    cy.get('.modal.d-block .multiselect').first().click();
    cy.get('.multiselect-option:visible').contains('Outro').click({ force: true });
    cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
    cy.wait('@storeRes').its('response.statusCode').should('eq', 422);

    cy.get('.modal.d-block .multiselect').first().click();
    cy.get('.multiselect-option:visible').contains('Sala').click({ force: true });
    cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
    cy.wait('@storeRes').its('response.statusCode').should('eq', 200);
    cy.get('.modal.d-block').should('not.exist');
    cy.contains('td', 'CY-ADM SALA TESTE', { timeout: 10000 }).should('exist');
    deleteCatalogItem('CY-ADM SALA TESTE');
  });

  it('recurso: escala de funcionamento e bloqueios (endpoints sem UI — cobertos via API)', () => {
    // A UI antiga (Blade/DataTables) foi removida na migração Vue e os
    // endpoints ficaram órfãos de frontend — cobertura via cy.request.
    cy.visit('/panel/setting/resources');
    cy.expectPanelPage();
    cy.intercept('POST', '**/setting/resources').as('storeRes');
    cy.get('.page-wrapper .border-bottom button.btn-primary').first().click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    catalogField('Nome').type('CY-ADM SALA ESCALA');
    cy.get('.modal.d-block .multiselect').first().click();
    cy.get('.multiselect-option:visible').contains('Sala').click({ force: true });
    cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
    cy.wait('@storeRes').its('response.body.data.id').then((resourceId) => {
      // Escala: segunda-feira 08:00–12:00 (sync full-replace).
      csrfRequest('PUT', `/panel/resources/${resourceId}/work-schedule`, {
        days: [{ day: 1, active: true, ranges: [{ starts_at: '08:00', ends_at: '12:00' }] }],
      }).its('status').should('eq', 200);
      csrfRequest('GET', `/panel/resources/${resourceId}/work-schedule/data`).then((resp) => {
        expect(resp.status).to.eq(200);
        const seg = resp.body.days.find((d) => d.day === 1);
        expect(seg.active, 'segunda ativa').to.be.true;
        expect(seg.ranges[0].starts_at).to.eq('08:00');
      });
      // Bloqueio futuro: cria e remove.
      const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
      csrfRequest('POST', `/panel/resources/${resourceId}/blocks`, {
        starts_at: `${tomorrow} 09:00`, ends_at: `${tomorrow} 10:00`,
        type: 'other', reason: 'CY-ADM bloqueio de sala',
      }).then((resp) => {
        expect(resp.status, JSON.stringify(resp.body).slice(0, 200)).to.be.lessThan(300);
        const blockId = resp.body.data?.id ?? resp.body.id;
        csrfRequest('DELETE', `/panel/resources/${resourceId}/blocks/${blockId}`)
          .its('status').should('be.lessThan', 300);
      });
    });
  });

  it('lente IOL: criar, editar e excluir (OffcanvasPanel + redirect Inertia)', () => {
    cy.visit('/panel/setting/iollenses');
    cy.expectPanelPage();
    cy.contains('button', 'Nova lente').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible')
      .and('contain.text', 'Nova lente');
    cy.get('.ee-modal__dialog').contains('label', 'Fabricante')
      .parent().find('input:visible').type('CY-ADM FAB');
    cy.get('.ee-modal__dialog').contains('label', 'Modelo')
      .parent().find('input:visible').type(`CY-ADM MODELO ${ADM_STAMP}`);
    cy.get('.ee-modal__dialog').contains('button', 'Cadastrar lente').click();
    // Inertia com redirect — a prova do save é o card novo no grid.
    cy.contains('.iol-lens-card', 'CY-ADM MODELO', { timeout: 15000 }).should('be.visible');

    // Editar: clicar no card abre o mesmo panel já hidratado.
    cy.contains('.iol-lens-card', 'CY-ADM MODELO').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('contain.text', 'Editar lente');
    cy.get('.ee-modal__dialog').contains('label', 'Modelo')
      .parent().find('input:visible').clear().type(`CY-ADM MODELO EDIT ${ADM_STAMP}`);
    cy.get('.ee-modal__dialog').contains('button', 'Salvar alterações').click();
    cy.contains('.iol-lens-card', 'CY-ADM MODELO EDIT', { timeout: 15000 }).should('be.visible');

    // Excluir: lixeira no card, confirm() nativo com nome interpolado.
    cy.contains('.iol-lens-card', 'CY-ADM MODELO EDIT')
      .find('.iol-lens-card__delete').click({ force: true });
    cy.contains('.iol-lens-card', 'CY-ADM MODELO EDIT', { timeout: 15000 }).should('not.exist');
  });

  it('painel de chamadas: toggle salva via PATCH e link aparece quando ativo', () => {
    cy.visit('/panel/setting/call-panel');
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('h4', 'Painel de chamadas').should('be.visible');
    cy.intercept('PATCH', '**/setting/call-panel').as('saveCp');

    cy.get('#cpEnabled').then(($cb) => {
      const wasOn = $cb.prop('checked');
      // 1º toggle: inverte o estado atual.
      cy.get('#cpEnabled').click({ force: true });
      cy.wait('@saveCp').its('response.statusCode').should('eq', 200);
      if (!wasOn) {
        // Ativou agora: URL pública do painel exibida.
        cy.get('.bg-light code', { timeout: 10000 })
          .invoke('text').should('match', /\/call-panel\//);
      }
      // 2º toggle: volta ao estado original (não deixa resíduo).
      cy.get('#cpEnabled').click({ force: true });
      cy.wait('@saveCp').its('response.statusCode').should('eq', 200);
    });
  });
});

describe('Perfil clinic.admin — controle de acesso (usuários e perfis RBAC)', () => {
  // Fluxo encadeado deliberado (como no spec da secretária): o usuário criado
  // no 1º teste é reusado nos seguintes; o clean do describe zera tudo.
  const USER_EMAIL = 'cy-adm.user@easyeye.test';
  const USER_PASS  = 'CyAdm#2026!xPto9';

  before(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
  });
  beforeEach(() => {
    cy.loginAs('clinic.admin');
    cy.on('window:confirm', () => true);
  });
  after(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
  });

  it('usuário: criar com perfil Secretária (senha forte validada) e ver na tabela', () => {
    cy.visit('/panel/accesscontrol/users');
    cy.expectPanelPage();
    cy.contains('button', 'Novo usuário').click();
    cy.get('.ufm-panel', { timeout: 10000 }).should('be.visible');

    cy.get('.ufm-panel input[type=text]').first().type('CY-ADM Usuario Teste');
    cy.get('.ufm-panel input[type=email]').first().type(USER_EMAIL);
    cy.get('.ufm-panel .multiselect').first().click();
    cy.get('.multiselect-option:visible').contains(/Secret/i).click({ force: true });
    // should(have.value) força retry — type() pode perder chars no re-render
    // e o backend devolveria "confirmação não coincide".
    cy.get('.ufm-panel input[type=password]').eq(0).type(USER_PASS)
      .should('have.value', USER_PASS);
    cy.get('.ufm-panel input[type=password]').eq(1).type(USER_PASS)
      .should('have.value', USER_PASS);

    cy.intercept('POST', '**/accesscontrol/users').as('storeUser');
    cy.get('.ufm-footer button[type=submit]').click();
    cy.wait('@storeUser').then((i) => {
      const b = i.request.body || {};
      expect(
        b.password === b.password_confirmation,
        `senha=confirmação no request (pwd:${String(b.password).length} conf:${String(b.password_confirmation).length} chars)`
      ).to.be.true;
      expect(i.response.statusCode).to.be.oneOf([302, 303]);
    });
    cy.get('.ufm-panel').should('not.exist');
    // Nome vira MAIÚSCULAS no backend.
    cy.contains('tr', USER_EMAIL, { timeout: 10000 })
      .should('contain.text', 'CY-ADM USUARIO TESTE')
      .and('contain.text', 'Ativo');
  });

  it('usuário: desativar e reativar pelo dropdown (sem confirm)', () => {
    cy.visit('/panel/accesscontrol/users');
    cy.expectPanelPage();
    openRowMenu(USER_EMAIL).contains('button', 'Desativar').click();
    cy.contains('tr', USER_EMAIL, { timeout: 10000 }).should('contain.text', 'Inativo');
    openRowMenu(USER_EMAIL).contains('button', /^\s*Ativar/).click();
    cy.contains('tr', USER_EMAIL, { timeout: 10000 }).should('contain.text', 'Ativo');
  });

  it('perfil RBAC: criar com permissões, editar descrição', () => {
    cy.visit('/panel/accesscontrol/roles');
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('h4', 'Perfis de acesso').should('be.visible');
    // Perfis do sistema visíveis e read-only (badge 'Padrão').
    cy.contains('.card', 'Administrador').should('contain.text', 'Padrão');

    cy.contains('button', 'Novo perfil').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    cy.get('.ee-modal__dialog input[type=text]').first().type('CY-ADM PERFIL');
    cy.get('.ee-modal__dialog textarea').first().type('Perfil de teste E2E do admin');
    cy.get('.ee-modal__dialog').contains('label', 'Gerenciar usuários')
      .parent().find('input[type=checkbox]').check({ force: true });
    cy.get('.ee-modal__dialog').contains('label', 'Visualizar financeiro')
      .parent().find('input[type=checkbox]').check({ force: true });
    cy.get('.ee-modal__dialog').contains('button', 'Criar perfil').click();
    cy.get('.ee-modal__dialog').should('not.exist');
    cy.contains('.card', 'CY-ADM PERFIL', { timeout: 10000 })
      .should('contain.text', '2 permissões');

    // Editar: painel reabre pré-preenchido (sem fetch).
    cy.contains('.card', 'CY-ADM PERFIL').find('button[title="Editar"]').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('contain.text', 'Editar perfil');
    cy.get('.ee-modal__dialog textarea').first().clear().type('Descrição editada E2E');
    cy.get('.ee-modal__dialog').contains('button', 'Salvar alterações').click();
    cy.get('.ee-modal__dialog').should('not.exist');
    cy.contains('.card', 'CY-ADM PERFIL', { timeout: 10000 })
      .should('contain.text', 'Descrição editada E2E');
  });

  it('usuário: atribuir perfil adicional (RBAC aditivo) e excluir o perfil', () => {
    cy.visit('/panel/accesscontrol/users');
    cy.expectPanelPage();
    cy.contains('tr', USER_EMAIL).find('button[title="Editar"]').click();
    cy.get('.ufm-panel', { timeout: 10000 }).should('be.visible');
    cy.get('.ufm-panel .spinner-border').should('not.exist');
    // Seção 'Perfis adicionais' com o CY-ADM PERFIL criado no teste anterior.
    cy.get('.ufm-panel').contains('label', 'CY-ADM PERFIL')
      .parent().find('input[type=checkbox]').check({ force: true });
    cy.intercept('PATCH', '**/accesscontrol/users/*/roles').as('syncRoles');
    cy.get('.ufm-panel button[type=submit]').click();
    cy.wait('@syncRoles').its('response.statusCode').should('be.oneOf', [200, 302, 303]);
    cy.get('.ufm-panel').should('not.exist');

    // Excluir o perfil: confirm() nativo avisa que N usuário(s) perdem as
    // permissões adicionais — aceito pelo trap do teste.
    cy.visit('/panel/accesscontrol/roles');
    cy.contains('.card', 'CY-ADM PERFIL').find('button[title="Excluir"]').click();
    cy.contains('.card', 'CY-ADM PERFIL', { timeout: 10000 }).should('not.exist');
  });

  it('usuário: excluir (confirm) e restaurar (linha withTrashed)', () => {
    cy.visit('/panel/accesscontrol/users');
    cy.expectPanelPage();
    cy.intercept('DELETE', '**/accesscontrol/users/**').as('delUser');
    openRowMenu(USER_EMAIL).contains('button', 'Excluir').click();
    cy.wait('@delUser').its('response.statusCode').should('be.oneOf', [200, 302, 303]);
    cy.contains('tr', USER_EMAIL, { timeout: 10000 }).should('contain.text', 'Excluído');

    cy.contains('tr', USER_EMAIL).find('button[title="Restaurar"]').click();
    cy.contains('tr', USER_EMAIL, { timeout: 10000 })
      .should('not.contain.text', 'Excluído')
      .and('contain.text', 'Ativo');
  });

  it('guards de segurança: owner e a própria conta não expõem ações destrutivas', () => {
    cy.visit('/panel/accesscontrol/users');
    cy.expectPanelPage();
    // A linha do próprio admin logado não tem dropdown ⋮ (v-if !is_self).
    cy.contains('tr', 'admin@clinicateste.com').find('button')
      .filter((_, el) => !!el.querySelector('i.ti-dots-vertical'))
      .should('have.length', 0);
  });
});

describe('Perfil clinic.admin — financeiro (procedimentos)', () => {
  before(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
    cy.exec(SEED_ADM, { timeout: 60000 }).its('stdout').should('include', 'cyadm:');
  });
  beforeEach(() => {
    cy.loginAs('clinic.admin');
    cy.on('window:confirm', () => true);
  });
  after(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
  });

  it('fluxo de caixa: criar receita e despesa, excluir a despesa', () => {
    cy.visit('/panel/financial/cash-flow');
    cy.expectPanelPage();
    cy.intercept('POST', '**/financial/cash-flow').as('storeEntry');

    // Receita (defaults: tipo Receita, status Pago, data hoje).
    cy.contains('button', 'Novo lançamento').click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.get('.modal.d-block input[type=text][maxlength="255"]').type('CY-ADM RECEITA TESTE');
    cy.get('.modal.d-block input[type=number]').invoke('val', '150.50')
      .trigger('input').trigger('change');
    cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
    cy.wait('@storeEntry').its('response.statusCode').should('be.lessThan', 300);
    cy.get('.modal.d-block').should('not.exist');
    cy.contains('td', 'CY-ADM RECEITA TESTE', { timeout: 10000 }).should('exist');

    // Despesa: troca o Tipo no primeiro SearchSelect do modal.
    cy.contains('button', 'Novo lançamento').click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.get('.modal.d-block .multiselect').first().click();
    cy.get('.multiselect-option:visible').contains('Despesa').click({ force: true });
    cy.get('.modal.d-block input[type=text][maxlength="255"]').type('CY-ADM DESPESA TESTE');
    cy.get('.modal.d-block input[type=number]').invoke('val', '45.90')
      .trigger('input').trigger('change');
    cy.get('.modal.d-block .modal-footer').contains('button', 'Cadastrar').click();
    cy.wait('@storeEntry').its('response.statusCode').should('be.lessThan', 300);
    cy.contains('td', 'CY-ADM DESPESA TESTE', { timeout: 10000 }).should('exist');

    // Excluir a despesa (confirm nativo).
    cy.intercept('DELETE', '**/financial/cash-flow/**').as('delEntry');
    cy.contains('tr', 'CY-ADM DESPESA TESTE').find('button[title="Excluir"]').click();
    cy.wait('@delEntry').its('response.statusCode').should('be.lessThan', 300);
    cy.contains('td', 'CY-ADM DESPESA TESTE', { timeout: 10000 }).should('not.exist');
  });

  it('fechamento de caixa: fechar período antigo e reabrir (confirm)', () => {
    cy.visit('/panel/financial/cash-closing');
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('h4', 'Fechamento de Caixa').should('be.visible');

    // Período de 2020: não colide com lançamentos correntes dos outros specs.
    cy.get('input[type=date]').eq(0).invoke('val', '2020-01-01')
      .trigger('input').trigger('change');
    cy.get('input[type=date]').eq(1).invoke('val', '2020-01-02')
      .trigger('input').trigger('change');
    cy.get('textarea').first().type('CY-ADM FECHAMENTO DE TESTE');
    cy.contains('button', 'Fechar período').click();
    cy.contains('.card', 'Períodos fechados', { timeout: 15000 })
      .should('contain.text', '2020');

    // Reabrir (router.delete + confirm) — não deixa período travado.
    cy.contains('.card', 'Períodos fechados').contains('tr', '2020')
      .contains('button', 'Reabrir').click();
    cy.contains('.card', 'Períodos fechados', { timeout: 15000 })
      .should('not.contain.text', '2020');
  });

  it('tabela de preços: definir preço de procedimento num convênio de teste', () => {
    // Convênio dedicado via endpoint JSON (grade é sync em lote por convênio —
    // não sobrescrever preços reais da clínica).
    cy.visit('/panel/dashboard');
    csrfRequest('POST', '/panel/setting/covenants', { name: 'CY-ADM PRECOS', color: '#0d1e2f', table: false })
      .then((resp) => {
        // Retry-safe: num retry o convênio do attempt anterior já existe (422
        // de nome duplicado) — o que importa é ele EXISTIR para a grade.
        const dupe = resp.status === 422 && JSON.stringify(resp.body).includes('registrado');
        expect(resp.status === 200 || dupe, JSON.stringify(resp.body).slice(0, 300)).to.be.true;
      });

    cy.visit('/panel/financial/procedure-prices');
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('h4', 'Tabela de Preços').should('be.visible');
    cy.get('.search-select.multiselect').first().click();
    cy.get('.multiselect-search').type('CY-ADM PRECOS');
    cy.get('.multiselect-option:visible').contains('CY-ADM PRECOS').click({ force: true });

    cy.get('table tbody tr').first().find('input[type=number]')
      .invoke('val', '123.45').trigger('input').trigger('change');
    cy.intercept('POST', '**/financial/procedure-prices').as('savePrices');
    cy.contains('.border-bottom button', 'Salvar preços').click();
    cy.wait('@savePrices').its('response.statusCode').should('be.oneOf', [200, 302, 303]);
    // Prova de persistência: recarregar a grade e conferir o preço gravado
    // (o toast de sucesso some rápido demais para assert confiável).
    cy.reload();
    cy.get('table tbody tr', { timeout: 15000 }).first().find('input[type=number]')
      .should('have.value', '123.45');
  });

  it('faturamento TISS: três abas alternam com contadores e estados', () => {
    cy.visit('/panel/financial/billing');
    cy.expectPanelPage();
    cy.contains('.page-wrapper', 'Faturamento TISS').should('be.visible');
    ['Elegíveis', 'Guias', 'Lotes'].forEach((tab) => {
      cy.get('ul.nav-tabs').contains('button', tab).click()
        .should('have.class', 'active');
    });
    // Conteúdo de cada aba montado (tabela ou estado vazio).
    cy.get('.page-wrapper table, .page-wrapper .text-muted').should('exist');
  });

  it('glosas TISS: recorrer exige justificativa mínima e abre o recurso', () => {
    cy.visit('/panel/financial/tiss/glosas');
    cy.expectPanelPage();
    cy.contains('.page-wrapper', 'Glosas').should('be.visible');
    // Glosa CY-ADM seedada com status open e identified_at hoje.
    cy.contains('tr', 'CY-ADM GLOSA', { timeout: 10000 })
      .contains('button', 'Recorrer').click();
    cy.get('.modal.d-block', { timeout: 10000 })
      .should('contain.text', 'Abrir recurso de glosa');

    cy.intercept('POST', '**/tiss/glosas/*/appeal').as('appeal');
    // < 10 caracteres: bloqueio client-side, nenhum request sai.
    cy.get('.modal.d-block textarea').type('curto');
    cy.get('.modal.d-block').contains('button', 'Enviar recurso').click();
    cy.get('.modal.d-block').should('be.visible'); // modal permanece

    cy.get('.modal.d-block textarea').clear()
      .type('CY-ADM justificativa completa: cobrança em conformidade com o contrato.');
    cy.get('.modal.d-block').contains('button', 'Enviar recurso').click();
    cy.wait('@appeal').its('response.statusCode').should('be.lessThan', 400);
    cy.get('.modal.d-block').should('not.exist');
    // Status vira 'appealed' → botão Recorrer some da linha.
    cy.contains('tr', 'CY-ADM GLOSA', { timeout: 10000 })
      .contains('button', 'Recorrer').should('not.exist');
  });

  it('relatórios financeiros: páginas montam e exports CSV respondem', () => {
    cy.visit('/panel/financial/reports/cash-flow');
    cy.expectPanelPage();
    cy.contains('.page-wrapper', 'Relatório de Fluxo de Caixa').should('be.visible');
    cy.contains('a', 'Exportar CSV').should('have.attr', 'href')
      .and('include', '/reports/cash-flow/export');

    cy.visit('/panel/financial/reports/covenants');
    cy.expectPanelPage();
    cy.contains('.page-wrapper', 'Relatório de Faturamento por Convênio').should('be.visible');

    // Exports via request (clicar dispararia download real no runner).
    const from = '2026-01-01';
    const to   = '2026-12-31';
    cy.request(`/panel/financial/reports/cash-flow/export?from=${from}&to=${to}`)
      .then((resp) => {
        expect(resp.status).to.eq(200);
        expect(resp.headers['content-type']).to.include('text/csv');
      });
    cy.request(`/panel/financial/reports/covenants/export?from=${from}&to=${to}`)
      .then((resp) => {
        expect(resp.status).to.eq(200);
        expect(resp.headers['content-type']).to.include('text/csv');
      });
  });
});

describe('Perfil clinic.admin — relatórios e compliance', () => {
  before(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
    cy.exec(SEED_ADM, { timeout: 60000 }).its('stdout').should('include', 'cyadm:');
  });
  beforeEach(() => {
    cy.loginAs('clinic.admin');
  });
  after(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
  });

  it('hub de relatórios: cards Produção e Absenteísmo navegam', () => {
    cy.visit('/panel/reports');
    cy.expectPanelPage();
    cy.contains('a.card', 'Absenteísmo').should('be.visible');
    cy.contains('a.card', 'Produção').click();
    cy.url({ timeout: 15000 }).should('include', '/panel/reports/schedules');
    cy.get('.page-wrapper').contains('h4', 'Relatório de Agenda').should('be.visible');
  });

  it('relatório de agenda: filtro por querystring mostra sumário e o CY-ADM', () => {
    const today = new Date().toISOString().slice(0, 10);
    cy.visit(`/panel/reports/schedules?date_from=${today}&date_until=${today}`);
    cy.expectPanelPage();
    // Sumário montado + agendamento seedado (Faltou hoje) na tabela.
    cy.contains('.page-wrapper', 'Taxa de presença').should('be.visible');
    cy.contains('.page-wrapper', 'CY-ADM PACIENTE', { timeout: 10000 }).should('be.visible');
  });

  it('relatório de absenteísmo: agendamento Faltou aparece com badge', () => {
    const today = new Date().toISOString().slice(0, 10);
    cy.visit(`/panel/reports/absenteeism?date_from=${today}&date_until=${today}`);
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('h4', 'Relatório de Absenteísmo').should('be.visible');
    cy.contains('tr', 'CY-ADM PACIENTE', { timeout: 10000 })
      .should('contain.text', 'Faltou');
  });

  it('compliance: botões de export habilitam com datas e CSVs respondem (LGPD/CFM)', () => {
    cy.visit('/panel/reports/compliance');
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('h4', 'Compliance & Auditoria').should('be.visible');

    // Sem datas: âncoras de export desabilitadas.
    cy.get('a.btn-primary').contains('Exportar CSV').should('have.class', 'disabled');
    // Preenche as datas do card de audit log → habilita e monta o href.
    const today = new Date().toISOString().slice(0, 10);
    cy.contains('.card', 'Audit log').find('input[type=date]').eq(0)
      .invoke('val', today).trigger('input').trigger('change');
    cy.contains('.card', 'Audit log').find('input[type=date]').eq(1)
      .invoke('val', today).trigger('input').trigger('change');
    cy.contains('.card', 'Audit log').find('a').contains('Exportar CSV')
      .should('not.have.class', 'disabled')
      .and('have.attr', 'href').and('include', `date_from=${today}`);

    // Exports via request: CSV com separador ';' e cabeçalho pt-BR.
    cy.request(`/panel/reports/compliance/audit?date_from=${today}&date_until=${today}`)
      .then((resp) => {
        expect(resp.status).to.eq(200);
        expect(resp.headers['content-type']).to.include('text/csv');
        expect(resp.body).to.include('Data/Hora;Usu');
      });
    cy.request(`/panel/reports/compliance/access?date_from=${today}&date_until=${today}`)
      .then((resp) => {
        expect(resp.status).to.eq(200);
        expect(resp.headers['content-type']).to.include('text/csv');
      });
    // Sem parâmetros: validação devolve redirect (302), não 500.
    cy.request({ url: '/panel/reports/compliance/audit', followRedirect: false, failOnStatusCode: false })
      .its('status').should('be.oneOf', [302, 303]);
  });
});

describe('Perfil clinic.admin — clínico (médicos, mural, fila, imagens e IA)', () => {
  before(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
    cy.exec(SEED_ADM, { timeout: 60000 }).its('stdout').should('include', 'cyadm:');
  });
  beforeEach(() => {
    cy.loginAs('clinic.admin');
    cy.on('window:confirm', () => true);
  });
  after(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
  });

  const DR_EMAIL = `cy-adm.dr.${ADM_STAMP}@easyeye.test`;

  it('médico: criar completo pelas 4 abas (cria credencial de login)', () => {
    const cpf = cpfValidoAdm((Date.now() % 900000) + 41);
    cy.visit('/panel/doctors');
    cy.expectPanelPage();
    cy.contains('button', 'Novo médico').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');

    // Aba Pessoal.
    cy.get('.ee-modal__dialog').contains('label', 'Nome completo')
      .parent().find('input:visible').type('CY-ADM DR TESTE');
    cy.get('.ee-modal__dialog').contains('label', 'Apelido')
      .parent().find('input:visible').type('CYADMDR');
    cy.get('.ee-modal__dialog').contains('label', 'CPF')
      .parent().find('input:visible').type(cpf);
    cy.get('.ee-modal__dialog').contains('label', 'Data de nascimento')
      .parent().find('input:visible').type('1985-03-10');
    cy.get('.ee-modal__dialog').contains('label', 'Gênero')
      .parent().find('.multiselect:visible').click();
    cy.get('.multiselect-option:visible').first().click({ force: true });
    cy.get('.ee-modal__dialog').contains('label', 'Estado civil')
      .parent().find('.multiselect:visible').click();
    cy.get('.multiselect-option:visible').first().click({ force: true });
    cy.get('.ee-modal__dialog').contains('label', 'E-mail')
      .parent().find('input:visible').type(DR_EMAIL);

    // Aba Contato.
    cy.get('.ee-modal__dialog .nav-tabs button').eq(2).click();
    cy.get('.ee-modal__dialog').contains('label', 'Celular')
      .parent().find('input:visible').type('11977776666');

    // Aba Médico (CRM/especialidade/cor).
    cy.get('.ee-modal__dialog .nav-tabs button').eq(1).click();
    cy.get('.ee-modal__dialog').contains('label', 'CRM')
      .parent().find('input:visible').type(`9${ADM_STAMP.slice(-5)}`);
    cy.get('.ee-modal__dialog').contains('label', 'Especialidade')
      .parent().find('input:visible').type('Oftalmologia Geral');
    cy.get('.ee-modal__dialog input[type=color]')
      .invoke('val', '#2b6cb0').trigger('input').trigger('change');

    // Aba Acesso (senha da credencial).
    cy.get('.ee-modal__dialog .nav-tabs button').eq(3).click();
    cy.get('.ee-modal__dialog').contains('label', /^Senha/)
      .parent().find('input:visible').type(`CyAdmDr@${ADM_STAMP}!`)
      .should('have.value', `CyAdmDr@${ADM_STAMP}!`);
    cy.get('.ee-modal__dialog').contains('label', 'Confirmar senha')
      .parent().find('input:visible').type(`CyAdmDr@${ADM_STAMP}!`)
      .should('have.value', `CyAdmDr@${ADM_STAMP}!`);

    cy.intercept('POST', '**/panel/doctors').as('storeDoctor');
    cy.get('.ee-modal__dialog button.btn-primary').last().click();
    cy.wait('@storeDoctor').its('response.statusCode').should('be.oneOf', [302, 303]);
    cy.get('.ee-modal__dialog').should('not.exist');
    // A coluna Nome exibe o APELIDO; médico recém-criado nasce INATIVO.
    cy.visit('/panel/doctors?search=CY-ADM');
    cy.contains('tr', DR_EMAIL, { timeout: 10000 })
      .should('contain.text', 'CYADMDR')
      .and('contain.text', 'Inativo');
  });

  it('médico: ativar e desativar pelo dropdown (toggle sem confirm)', () => {
    cy.visit('/panel/doctors?search=CY-ADM');
    cy.expectPanelPage();
    // Nasce inativo → Ativar primeiro; termina ATIVO para os testes seguintes.
    openRowMenu(DR_EMAIL).contains('button', /^\s*Ativar/).click();
    cy.contains('tr', DR_EMAIL, { timeout: 10000 }).should('contain.text', 'Ativo');
    openRowMenu(DR_EMAIL).contains('button', 'Desativar').click();
    cy.contains('tr', DR_EMAIL, { timeout: 10000 }).should('contain.text', 'Inativo');
    openRowMenu(DR_EMAIL).contains('button', /^\s*Ativar/).click();
    cy.contains('tr', DR_EMAIL, { timeout: 10000 }).should('contain.text', 'Ativo');
  });

  it('médico: escala de atendimento salva e persiste; bloqueio criado e removido', () => {
    cy.visit('/panel/doctors?search=CY-ADM');
    cy.expectPanelPage();
    cy.contains('tr', DR_EMAIL).find('a[title="Horários de atendimento"]')
      .invoke('attr', 'href').then((href) => cy.visit(href));
    cy.expectPanelPage();
    cy.contains('Escala de Atendimento').should('be.visible');

    // Habilita Terça e salva (PUT fetch JSON).
    cy.contains('label', /^Ter/).parent().find('input[type=checkbox]')
      .check({ force: true });
    cy.intercept('PUT', '**/work-schedule').as('saveWs');
    cy.contains('button', 'Salvar Escala').click();
    cy.wait('@saveWs').its('response.statusCode').should('eq', 200);
    cy.reload();
    cy.contains('label', /^Ter/).parent().find('input[type=checkbox]')
      .should('be.checked');

    // Bloqueio futuro: cria e exclui (confirm nativo).
    const t = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
    cy.contains(/Bloqueios/).parent().find('button').first().click();
    cy.get('input[type=datetime-local]').eq(0)
      .invoke('val', `${t}T09:00`).trigger('input').trigger('change');
    cy.get('input[type=datetime-local]').eq(1)
      .invoke('val', `${t}T10:00`).trigger('input').trigger('change');
    cy.get('input[placeholder="Motivo (opcional)"]').type('CY-BLOQ ADMIN');
    cy.intercept('POST', '**/blocks').as('storeBlock');
    cy.contains('button', 'Adicionar Bloqueio').click();
    cy.wait('@storeBlock').its('response.statusCode').should('be.lessThan', 300);
    cy.contains('CY-BLOQ ADMIN', { timeout: 10000 }).should('be.visible');

    cy.intercept('DELETE', '**/blocks/**').as('delBlock');
    cy.contains('div.d-flex.align-items-start', 'CY-BLOQ ADMIN')
      .find('button.btn-outline-danger').first().click();
    cy.wait('@delBlock').its('response.statusCode').should('be.lessThan', 300);
    cy.contains('CY-BLOQ ADMIN').should('not.exist');
  });

  it('médico: excluir (confirm) — some da listagem, sem opção de restaurar', () => {
    cy.visit('/panel/doctors?search=CY-ADM');
    cy.expectPanelPage();
    cy.intercept('DELETE', '**/panel/doctors/**').as('delDoctor');
    openRowMenu(DR_EMAIL).contains('button', 'Excluir').click();
    cy.wait('@delDoctor').its('response.statusCode').should('be.oneOf', [200, 302, 303]);
    // Index de médicos NÃO lista trashed (diferente de pacientes/usuários).
    cy.contains('td', DR_EMAIL, { timeout: 10000 }).should('not.exist');
  });

  it('mural de recados: publicar, marcar como lido e excluir', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.contains('button', 'Mural de Recados').click();
    cy.get('div.border.border-primary', { timeout: 10000 }).should('be.visible');
    cy.get('div.border.border-primary').find('button:has(i.fa-plus)').first().click();
    cy.get('textarea[placeholder="Digite o recado…"]').type('CY-ADM RECADO DE TESTE');
    cy.intercept('POST', '**/panel/notices').as('storeNotice');
    cy.contains('button', 'Publicar').click();
    cy.wait('@storeNotice').its('response.statusCode').should('eq', 201);
    cy.contains('.list-group-item', 'CY-ADM RECADO DE TESTE', { timeout: 10000 })
      .should('be.visible');

    // Marcar lido.
    cy.intercept('POST', '**/notices/*/read').as('readNotice');
    cy.contains('.list-group-item', 'CY-ADM RECADO DE TESTE')
      .contains('button', 'Li').click();
    cy.wait('@readNotice').its('response.statusCode').should('be.lessThan', 300);

    // Excluir (remoção otimista, sem confirm).
    cy.intercept('DELETE', '**/panel/notices/**').as('delNotice');
    cy.contains('.list-group-item', 'CY-ADM RECADO DE TESTE')
      .find('button.btn-outline-danger').first().click();
    cy.wait('@delNotice').its('response.statusCode').should('be.lessThan', 300);
    cy.contains('.list-group-item', 'CY-ADM RECADO DE TESTE').should('not.exist');
  });

  it('fila de espera: adicionar paciente e remover', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.contains('button', 'Adicionar à Fila').click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    // Médico: primeiro SearchSelect do modal → Dra. Ana (seed fixa da clínica).
    cy.get('.ee-modal__dialog .multiselect:visible').first().click();
    cy.get('.multiselect-option:visible').contains(/ANA/i).click({ force: true });
    cy.get('.ee-modal__dialog').contains('label', 'Nome completo')
      .parent().find('input:visible').type('CY-ADM FILA TESTE');
    cy.intercept('POST', '**/panel/waiting-list').as('storeWl');
    cy.get('.ee-modal__dialog button.btn-warning').click();
    cy.wait('@storeWl').its('response.statusCode').should('eq', 201);
    cy.get('.ee-modal__dialog').should('not.exist');

    // Painel da fila: item listado → remover (otimista, sem confirm).
    cy.contains('button', 'Lista de Espera').click();
    cy.get('div.border.border-warning', { timeout: 10000 }).should('be.visible');
    cy.contains('div.border.border-warning', 'CY-ADM FILA TESTE').should('be.visible');
    cy.intercept('DELETE', '**/panel/waiting-list/**').as('delWl');
    cy.get('div.border.border-warning')
      .contains(/CY-ADM FILA TESTE/).closest('.list-group-item, li, .d-flex')
      .find('button[title="Remover"]').first().click();
    cy.wait('@delWl').its('response.statusCode').should('be.lessThan', 300);
    cy.get('div.border.border-warning').should('not.contain.text', 'CY-ADM FILA TESTE');
  });

  it('imagens oftálmicas: filtros expandem e import externo real (PDF ASCII)', () => {
    cy.visit('/panel/eye-images');
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('h4', 'Imagens oftálmicas').should('be.visible');
    // Barra de filtros expande (radios de lateralidade).
    cy.contains('button', 'Filtros').click();
    cy.get('#f-lat-od').should('exist');

    // Import externo: modal completo com upload real.
    cy.contains('button', /^\s*Novo\s*$/).click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    cy.get('.ee-modal__dialog input[placeholder^="Buscar paciente por nome"]')
      .type('CY-ADM');
    cy.get('.ee-modal__dialog ul.list-group li.list-group-item-action', { timeout: 10000 })
      .contains('CY-ADM PACIENTE').click();
    cy.get('.ee-modal__dialog select.form-select').first()
      .find('option').eq(1).then(($o) => {
        cy.get('.ee-modal__dialog select.form-select').first().select($o.val());
      });
    cy.get('.ee-modal__dialog input[type=date]')
      .invoke('val', new Date().toISOString().slice(0, 10))
      .trigger('input').trigger('change');
    // PDF 100% ASCII — cy.intercept corrompe multipart binário.
    const miniPdf = [
      '%PDF-1.4',
      '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj',
      '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj',
      '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj',
      'xref', '0 4', 'trailer<</Size 4/Root 1 0 R>>', '%%EOF', '',
    ].join('\n');
    cy.get('.ee-modal__dialog input[type=file]').selectFile({
      contents: Cypress.Buffer.from(miniPdf),
      fileName: 'cy-adm-exame.pdf',
      mimeType: 'application/pdf',
    }, { force: true });
    cy.intercept('POST', '**/eye-images/import').as('importExam');
    cy.get('.ee-modal__dialog').contains('button', 'Importar').click();
    cy.wait('@importExam').its('response.statusCode').should('be.oneOf', [302, 303]);
    cy.get('.ee-modal__dialog', { timeout: 15000 }).should('not.exist');
  });

  it('imagens oftálmicas: escrever diagnóstico é doctor-only (403 para admin)', () => {
    cy.visit('/panel/dashboard');
    csrfRequest('POST', '/panel/eye-images/diagnoses', { name: 'CY-ADM DX' })
      .its('status').should('eq', 403);
  });

  it('consumo de IA: admin vê pacotes de crédito e filtra execuções', () => {
    cy.visit('/panel/ai/usage');
    cy.expectPanelPage();
    // Seção admin-only (v-if canPurchaseCredits) — NUNCA clicar em comprar.
    cy.contains('h6', 'Pacotes de créditos IA').should('be.visible');
    cy.contains('strong', 'Créditos disponíveis').should('be.visible');
    // Filtro de status ecoa na querystring (preserveState + replace).
    cy.get('.ai-status-filter .multiselect, .ai-status-filter').first().click();
    cy.get('.multiselect-option:visible').eq(1).click({ force: true });
    cy.url({ timeout: 10000 }).should('include', 'status=');
  });
});

describe('Perfil clinic.admin — conta e segurança', () => {
  beforeEach(() => {
    cy.loginAs('clinic.admin');
  });
  after(() => {
    cy.exec(CLEAN_ADM, { failOnNonZeroExit: false, timeout: 60000 });
  });

  it('meu perfil: renomear, salvar e restaurar o nome original', () => {
    cy.visit('/panel/profile');
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('h4', 'Meu perfil').should('be.visible');
    cy.get('#profile_name').invoke('val').then((original) => {
      cy.get('#profile_name').clear().type('CY-ADM RENOMEADO');
      cy.contains('button', 'Salvar perfil').click();
      cy.contains('.alert.alert-success', 'Perfil salvo com sucesso.', { timeout: 15000 })
        .should('be.visible');
      // Restaura o nome (o clean-cyadm cobre o caso de falha no meio).
      cy.get('#profile_name').clear().type(original);
      cy.contains('button', 'Salvar perfil').click();
      cy.contains('.alert.alert-success', 'Perfil salvo com sucesso.', { timeout: 15000 })
        .should('be.visible');
    });
  });

  it('alterar senha: senha atual errada NÃO troca a senha', () => {
    cy.visit('/panel/profile');
    cy.expectPanelPage();
    cy.get('input[autocomplete=current-password]').type('SenhaErrada#123');
    cy.get('input[autocomplete=new-password]').eq(0).type('NuncaSeraUsada#2026!');
    cy.get('input[autocomplete=new-password]').eq(1).type('NuncaSeraUsada#2026!');
    cy.contains('button', 'Atualizar senha').click();
    // Erro chega no bag updatePassword — o assert robusto é a AUSÊNCIA do
    // alert de sucesso (a mensagem inline pode não renderizar; gotcha real).
    cy.wait(1500);
    cy.contains('.alert.alert-success', 'Senha alterada').should('not.exist');
  });

  it('preferências: PATCH parcial preserva as outras chaves (merge)', () => {
    cy.visit('/panel/dashboard');
    csrfRequest('PATCH', '/panel/preferences', {
      favorite_shortcuts: [{ key: 'patients', hidden: false }],
    }).its('status').should('eq', 200);
    csrfRequest('PATCH', '/panel/preferences', {
      dashboard_widget_order: ['agenda', 'financeiro'],
    }).then((resp) => {
      expect(resp.status).to.eq(200);
      // Merge parcial: a chave anterior sobrevive ao segundo PATCH.
      const bag = resp.body.data ?? resp.body.preferences ?? resp.body;
      expect(JSON.stringify(bag)).to.include('patients');
    });
  });

  it('2FA obrigatório da empresa: modal exige justificativa LGPD (sem ativar de verdade)', () => {
    cy.visit('/panel/setting/security');
    cy.expectPanelPage();
    cy.get('.page-wrapper').contains('h4', 'Autenticação em dois fatores').should('be.visible');
    cy.contains('.badge', /Inativo|Ativo/).should('be.visible');

    // Abre o modal de confirmação com justificativa (NUNCA confirmar ativação:
    // travaria o login de TODOS os specs no middleware 2fa).
    cy.contains('button', /2FA obrigatório/).click();
    cy.get('.modal.d-block', { timeout: 10000 }).should('be.visible');
    cy.get('.modal.d-block .modal-footer button.btn-primary, .modal.d-block .modal-footer button.btn-danger')
      .first().should('be.disabled');
    cy.get('.modal.d-block textarea').type('CY-ADM justificativa de teste E2E');
    cy.get('.modal.d-block .modal-footer button.btn-primary, .modal.d-block .modal-footer button.btn-danger')
      .first().should('not.be.disabled');
    cy.get('.modal.d-block').contains('button', 'Cancelar').click();
    cy.get('.modal.d-block').should('not.exist');

    // API: justificativa curta → 422; desativar já-inativo → 200 idempotente.
    csrfRequest('PATCH', '/panel/setting/security/two-factor', { enabled: false, reason: 'curta' })
      .its('status').should('eq', 422);
    csrfRequest('PATCH', '/panel/setting/security/two-factor', {
      enabled: false, reason: 'CY-ADM desativação idempotente para teste E2E',
    }).its('status').should('eq', 200);
  });

  it('2FA pessoal: setup mostra QR e rejeita código inválido (sem ativar)', () => {
    cy.visit('/security/two-factor/setup');
    // Página guest-app (fora do painel) — QR SVG renderizado.
    cy.get('div.bg-white svg', { timeout: 15000 }).should('be.visible');
    // Botão só habilita com 6 dígitos.
    cy.get('input[maxlength="6"]').type('123');
    cy.contains('button', 'Confirmar e ativar').should('be.disabled');
    cy.get('input[maxlength="6"]').type('456');
    cy.contains('button', 'Confirmar e ativar').should('not.be.disabled');
    // Código errado → erro, 2FA continua desativado.
    cy.get('input[maxlength="6"]').clear().type('000000');
    cy.contains('button', 'Confirmar e ativar').click();
    cy.get('.alert.alert-danger', { timeout: 15000 }).should('be.visible');
  });

  it('eventos de agenda (API órfã de UI): criar, ver na agenda, editar e excluir', () => {
    cy.visit('/panel/dashboard');
    const today = new Date().toISOString().slice(0, 10);
    csrfRequest('POST', '/panel/schedule-events', {
      title: 'CY-ADM EVENTO', type: 'meeting',
      starts_at: `${today} 15:00`, ends_at: `${today} 16:00`,
      color: '#7c3aed', notes: 'CY-ADM compromisso de teste',
    }).then((resp) => {
      expect(resp.status, JSON.stringify(resp.body).slice(0, 200)).to.eq(201);
      const id = resp.body.data.id;
      // Exibição na agenda (EventCard).
      cy.visit('/panel/schedules');
      cy.expectPanelPage();
      cy.contains('CY-ADM EVENTO', { timeout: 15000 }).should('be.visible');
      // Editar e excluir via API (não existe UI de escrita).
      csrfRequest('PUT', `/panel/schedule-events/${id}`, {
        title: 'CY-ADM EVENTO EDIT', type: 'meeting',
        starts_at: `${today} 15:00`, ends_at: `${today} 16:00`,
      }).its('status').should('eq', 200);
      csrfRequest('DELETE', `/panel/schedule-events/${id}`)
        .its('status').should('eq', 200);
      cy.reload();
      cy.contains('CY-ADM EVENTO').should('not.exist');
    });
  });
});
