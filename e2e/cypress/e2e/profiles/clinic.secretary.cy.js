// Perfil clinic.secretary — secretaria@clinicateste.com (rule secretary).
// Matriz: menu = base (Dashboard, Agendas, Pacientes) + Médicos + Imagens
// oftálmicas + Assistente de IA (LINK DIRETO /panel/ai/usage, sem submenu).
// Sem Financeiro, sem Relatórios, sem Configurações, sem Controle de acesso.
// Pode LER prontuários mas não escrever — nenhuma escrita clínica aqui.
// Fontes: app/Support/PanelNavigation.php ($canSeeDoctors inclui secretary;
// $isFinancial/$isAdmin excluem) + middleware permission/entity.role nas rotas.

// O painel carrega em mini-sidebar (html[data-layout="mini"] → body.mini-sidebar
// via preclinic-theme-script.js) e o hover expande via jQuery (body.expand-menu,
// preclinic-script.js). O mouseover sintético do próprio cy.click dispara essa
// expansão NO MEIO do click e desloca os itens (~1 item pra baixo) — clicando o
// vizinho errado. Fix: expandir ANTES, esperar a animação e só então clicar.
function clickMenuLink(hrefPart) {
  cy.get('#sidebar').trigger('mouseover');
  cy.get('body').should('have.class', 'expand-menu');
  cy.wait(500); // slideDown/width transition (~350ms) termina antes do click
  cy.get(`#sidebar-menu a[href*="${hrefPart}"]`).first().click();
}

describe('Perfil clinic.secretary — acesso e navegação', () => {
  beforeEach(() => {
    cy.loginAs('clinic.secretary');
  });

  // ── (a) Landing ────────────────────────────────────────────────────────────
  it('landing pós-login: /panel/dashboard monta com a entity no sidebar', () => {
    // O cy.session de loginAs já assertou a URL /panel/dashboard no login real.
    cy.visit('/panel/dashboard');
    cy.expectPanelPage('.page-dashboard');
    cy.contains('Personalizar').should('be.visible');
    cy.get('.sidebar-top h6').should('contain.text', 'CLÍNICA TESTE INTEGRADOR');
  });

  // ── Menu: composição exata do perfil ───────────────────────────────────────
  it('menu tem exatamente os itens do perfil (com IA link direto, sem Financeiro/Relatórios/Configurações)', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();

    // Presentes (por href — mais estável que texto).
    cy.get('#sidebar-menu a[href*="/panel/dashboard"]').should('exist');
    cy.get('#sidebar-menu a[href*="/panel/schedules"]').should('exist');
    cy.get('#sidebar-menu a[href*="/panel/patients"]').should('exist');
    cy.get('#sidebar-menu a[href*="/panel/doctors"]').should('exist');
    cy.get('#sidebar-menu a[href*="/panel/eye-images"]').should('exist');
    // IA para secretary = link DIRETO para o dashboard de uso (sem submenu de prompts).
    cy.get('#sidebar-menu a[href*="/panel/ai/usage"]').should('exist');
    cy.get('#sidebar-menu a[href*="/panel/setting/ai-prompts"]').should('not.exist');

    // Ausentes (menu ≠ autorização, mas para secretary a matriz nega ambos).
    cy.get('#sidebar-menu a[href*="/panel/financial"]').should('not.exist');
    cy.get('#sidebar-menu a[href*="/panel/reports"]').should('not.exist');
    cy.get('#sidebar-menu a[href*="/panel/accesscontrol"]').should('not.exist');
    cy.get('#sidebar-menu a[href*="/panel/setting"]').should('not.exist');
    cy.get('#sidebar-menu a[href*="/panel/manager"]').should('not.exist');
  });

  // ── (b) Allowed — navegando pelos links reais do menu ──────────────────────
  it('Agendas: link do menu abre /panel/schedules', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    clickMenuLink('/panel/schedules');
    cy.url().should('include', '/panel/schedules');
    cy.expectPanelPage();
    cy.contains('h4', 'Agenda').should('be.visible');
  });

  it('Pacientes: link do menu abre /panel/patients', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    clickMenuLink('/panel/patients');
    cy.url().should('include', '/panel/patients');
    cy.expectPanelPage();
    cy.contains('h4', 'Pacientes').should('be.visible');
  });

  it('Médicos: link do menu abre /panel/doctors', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    clickMenuLink('/panel/doctors');
    cy.url().should('include', '/panel/doctors');
    cy.expectPanelPage();
    cy.contains('h4', 'Médicos').should('be.visible');
  });

  it('Imagens oftálmicas: link do menu abre /panel/eye-images', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    clickMenuLink('/panel/eye-images');
    cy.url().should('include', '/panel/eye-images');
    cy.expectPanelPage();
    cy.contains('h4', 'Imagens oftálmicas').should('be.visible');
  });

  it('Assistente de IA: link direto do menu abre /panel/ai/usage', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    clickMenuLink('/panel/ai/usage');
    cy.url().should('include', '/panel/ai/usage');
    cy.expectPanelPage();
    cy.contains('h4', 'Assistente de IA').should('be.visible');
  });

  // ── (c) Forbidden ──────────────────────────────────────────────────────────
  it('financeiro: /panel/financial/cash-flow nega (permission financial.manage/admin/financial)', () => {
    cy.expectForbidden('/panel/financial/cash-flow');
  });

  it('relatórios: /panel/reports nega (mesmo middleware do financeiro)', () => {
    cy.expectForbidden('/panel/reports');
  });

  it('controle de acesso: /panel/accesscontrol/users nega (entity.role admin)', () => {
    cy.expectForbidden('/panel/accesscontrol/users');
  });

  it('prompts de IA: /panel/setting/ai-prompts nega (entity.role doctor — secretary vê IA mas não prompts)', () => {
    cy.expectForbidden('/panel/setting/ai-prompts');
  });

  it('convênios: /panel/setting/covenants nega (permission settings.manage)', () => {
    cy.expectForbidden('/panel/setting/covenants');
  });

  it('manager: /panel/manager/entities redireciona pro dashboard com flash de área exclusiva do SaaS', () => {
    // Modo 302 + flash (não 403): validar a UX real via cy.visit.
    cy.visit('/panel/manager/entities');
    cy.url().should('include', '/panel/dashboard');
    // Flash auto-dismiss em 6s: assertar imediatamente.
    cy.get('.alert.alert-danger', { timeout: 4000 })
      .should('contain.text', 'Esta área é exclusiva do administrador do SaaS.');
    cy.expectPanelPage();
  });

  // ── (d) Interações não-destrutivas nas áreas centrais ──────────────────────
  it('Pacientes: busca reage e modal "Novo paciente" abre e fecha sem salvar', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage('Pacientes');

    // Busca (debounce 400ms → router.get com ?search=): termo impossível
    // não altera nada no banco e prova a reação da lista.
    cy.get('input[placeholder^="Buscar por nome"]').type('zzz-cy-inexistente');
    cy.url({ timeout: 10000 }).should('include', 'search=zzz-cy-inexistente');
    cy.contains('Nenhum paciente encontrado.').should('be.visible');
    cy.get('input[placeholder^="Buscar por nome"]').clear();
    cy.url({ timeout: 10000 }).should('not.include', 'zzz-cy-inexistente');

    // Modal de criação (OffcanvasPanel .ee-modal__dialog): abrir e FECHAR sem salvar.
    cy.contains('button', 'Novo paciente').click();
    cy.get('.ee-modal__dialog').should('be.visible');
    cy.get('.ee-modal__header .btn-close').click();
    cy.get('.ee-modal__dialog').should('not.exist');
  });

  it('Agendas: modal "Novo" agendamento abre e fecha sem salvar', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.contains('h4', 'Agenda').should('be.visible');

    // Botão "Novo" (texto exato — ícone não tem texto) abre CenteredModal.
    cy.contains('button', /^\s*Novo\s*$/).click();
    cy.get('.ee-modal__dialog').should('be.visible');
    cy.get('.ee-modal__header .btn-close').click();
    cy.get('.ee-modal__dialog').should('not.exist');
  });

  it('Médicos: busca reage sem alterar dados', () => {
    cy.visit('/panel/doctors');
    cy.expectPanelPage('Médicos');

    cy.get('input[placeholder^="Buscar por nome"]').type('zzz-cy-inexistente');
    cy.url({ timeout: 10000 }).should('include', 'search=zzz-cy-inexistente');
    cy.get('input[placeholder^="Buscar por nome"]').clear();
    cy.url({ timeout: 10000 }).should('not.include', 'zzz-cy-inexistente');
  });

  // ── (e) Logout ─────────────────────────────────────────────────────────────
  it('logout pelo dropdown volta ao site público', () => {
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
