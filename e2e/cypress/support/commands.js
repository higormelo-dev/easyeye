// Comandos customizados EasyEye E2E.
// Perfis/credenciais vivem em cypress/fixtures/profiles.json.
const profiles = require('../fixtures/profiles.json');

/**
 * cy.loginAs('clinic.admin')
 * Login pela UI real (/login) com cache de sessão por perfil (cy.session,
 * chaveado pelo e-mail) — evita throttle do POST /login e lockout.
 * Após loginAs é preciso cy.visit() na página desejada (cy.session não navega).
 */
Cypress.Commands.add('loginAs', (key) => {
  const profile = profiles[key];
  if (!profile) {
    throw new Error(`[loginAs] Perfil desconhecido: "${key}". Chaves válidas: ${Object.keys(profiles).join(', ')}`);
  }
  if (!profile.email) {
    throw new Error(
      `[loginAs] Perfil "${key}" SEM CREDENCIAL SEEDADA — criar o usuário e preencher cypress/fixtures/profiles.json (campo "todo").`
    );
  }

  // Guarda o último perfil logado para expectForbidden() saber a landing.
  Cypress.env('lastProfileKey', key);

  cy.session(
    ['easyeye', profile.email],
    () => {
      cy.visit('/login');
      // Sem ids/names no form (v-model): usar seletores estruturais.
      cy.get('form input[type=email]').should('be.visible').clear().type(profile.email);
      // NÃO clicar no toggle "olho" antes de digitar (viraria type=text).
      cy.get('form input[type=password]').should('be.visible').clear().type(profile.password, { log: false });
      cy.get('form button[type=submit]').should('not.be.disabled').click();
      // Landing por URL: clínica -> /panel/dashboard, SaaS -> /panel/manager/dashboard.
      cy.url({ timeout: 20000 }).should('include', profile.landing);
    },
    {
      cacheAcrossSpecs: true,
      validate() {
        // Sessão viva = landing responde 200 direto (302 = mandou pro /login).
        cy.request({ url: profile.landing, followRedirect: false, failOnStatusCode: false })
          .its('status')
          .should('eq', 200);
      },
    }
  );
});

/**
 * cy.expectPanelPage() / cy.expectPanelPage('Pacientes')
 * Garante que a página montou de verdade:
 *  - #sidebar-menu visível (painel) OU .ee-auth-form (telas guest);
 *  - sem "Internal Server Error" / "Page Expired";
 *  - body não-vazio;
 *  - marker opcional: seletor CSS (se começar com #, . ou [) ou texto (cy.contains).
 * Tela em branco (Ziggy/Vue não montou) estoura o timeout do cy.get.
 */
Cypress.Commands.add('expectPanelPage', (marker) => {
  cy.get('#sidebar-menu, .ee-auth-form', { timeout: 15000 }).should('be.visible');
  cy.get('body').should(($body) => {
    const text = $body.text();
    expect($body.children().length, 'body não-vazio').to.be.greaterThan(0);
    expect(text, 'sem Internal Server Error').to.not.include('Internal Server Error');
    expect(text, 'sem Page Expired').to.not.include('Page Expired');
  });
  if (marker) {
    if (/^[#.\[]/.test(marker)) {
      cy.get(marker).should('be.visible');
    } else {
      cy.contains(marker).should('be.visible');
    }
  }
});

/**
 * cy.expectForbidden('/panel/manager/dashboard')
 * Negação tem DOIS modos no EasyEye:
 *  - rotas clínicas negadas: 403 full-page (abort);
 *  - /panel/manager/* p/ usuário de clínica: 302 -> /panel/dashboard + flash.
 * Usa cy.request full-page (nunca XHR Inertia, que viraria redirect back).
 * Aceita 403 OU (200/302 tendo redirecionado para FORA da url pedida).
 * Depois visita a landing do último perfil logado p/ garantir sessão viva.
 */
Cypress.Commands.add('expectForbidden', (url, landingOverride) => {
  cy.request({ url, failOnStatusCode: false }).then((resp) => {
    const redirects = resp.redirects || [];
    const lastHop = redirects.length ? redirects[redirects.length - 1] : '';
    const redirectedAway = redirects.length > 0 && !lastHop.includes(url);
    const denied = resp.status === 403 || ((resp.status === 200 || resp.status === 302) && redirectedAway);
    expect(
      denied,
      `esperava negação em ${url} — status ${resp.status}, redirects: [${redirects.join(' | ')}]`
    ).to.be.true;
  });

  const key = Cypress.env('lastProfileKey');
  const landing = landingOverride || (key && profiles[key] && profiles[key].landing) || '/panel/dashboard';
  cy.visit(landing);
  cy.get('#sidebar-menu', { timeout: 15000 }).should('be.visible');
});
