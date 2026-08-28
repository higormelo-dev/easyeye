// Smoke: fundação viva — login page renderiza, assets (ícones tabler) carregam,
// login real funciona, painel monta e logout devolve pro site público.

describe('Smoke — fundação EasyEye E2E', () => {
  it('/login renderiza o formulário e os ícones tabler', () => {
    cy.visit('/login');

    cy.get('.ee-auth-form').should('be.visible');
    cy.get('form input[type=email]').should('be.visible');
    cy.get('form input[type=password]').should('be.visible');
    cy.get('form button[type=submit]').should('be.visible');

    // Ícone tabler com a webfont realmente aplicada (asset build ok).
    cy.get('i[class^="ti ti-"]').first().should(($i) => {
      const fontFamily = getComputedStyle($i[0]).fontFamily.toLowerCase();
      expect(fontFamily, `font-family do ícone: ${fontFamily}`).to.include('tabler');
    });
  });

  it('clinic.admin loga e o painel monta (#sidebar-menu)', () => {
    cy.loginAs('clinic.admin');
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    // Entity selecionada aparece no topo do sidebar.
    cy.get('.sidebar-top h6').should('contain.text', 'CLÍNICA TESTE INTEGRADOR');
  });

  it('logout pelo dropdown do usuário volta para o site público', () => {
    cy.loginAs('clinic.admin');
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();

    cy.get('.profile-dropdown a.dropdown-toggle').click();
    cy.get('.profile-dropdown .dropdown-menu').should('be.visible');
    // Há outro button text-danger "Sair da impersonação" quando impersonando —
    // filtrar pelo texto exato "Sair".
    cy.get('.profile-dropdown button.dropdown-item.text-danger')
      .filter((_, el) => /Sair/.test(el.textContent) && !/impersona/i.test(el.textContent))
      .first()
      .click();

    // POST /logout -> Inertia::location('/') (full reload no site público).
    cy.url({ timeout: 20000 }).should('match', /\/(login)?$/);
    cy.get('#sidebar-menu').should('not.exist');
  });
});
