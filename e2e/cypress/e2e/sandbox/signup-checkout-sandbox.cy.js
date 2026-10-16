// HOMOLOGAÇÃO REAL, OPT-IN — Asaas Sandbox. Não entra no gate padrão e NÃO usa
// cy.intercept para cobrança ou webhook.
//
// Como rodar (a partir de e2e/):
//   CYPRESS_SANDBOX=1 npx cypress run --browser chrome
//
// Pré-requisitos, ANTES de abrir o Cypress:
// - CYPRESS_BASE_URL obrigatoriamente em localhost/127.0.0.1 (normalmente
//   http://localhost:8085); host remoto não é permitido porque o comando
//   Artisan local não consegue comprovar a configuração daquele app;
// - ASAAS_BASE_URL=https://api-sandbox.asaas.com e uma chave Sandbox que começa
//   com $aact_hmlg_ (nunca grave a chave neste arquivo);
// - ASAAS_WEBHOOK_SECRET configurado no EasyEye e no webhook do Asaas Sandbox;
// - `ngrok http 8085` em execução e o webhook cadastrado no Sandbox apontando para
//   {URL_PUBLICA_NGROK}/api/billing/webhooks/asaas;
// - gateway padrão Asaas e pelo menos um plano público pago habilitados no ambiente.
//
// Pix: depois de a tela mostrar o QR, abra o painel Sandbox, simule o pagamento da
// cobrança criada e espere este spec encontrar o webhook. CYPRESS_SANDBOX_PAUSE=1
// deixa um cy.pause() opcional antes do polling (útil no Cypress open).
// Boleto: faça a mesma simulação manual de pagamento no painel Sandbox antes
// de retomar o polling; a assinatura só fica ativa depois do webhook.
//
// Cartões Sandbox Asaas (somente dados fictícios): aprovação 4444 4444 4444 4444,
// CVV 123, validade futura; recusa 5184019740373151 (Mastercard) ou
// 4916561358240741 (Visa). O sucesso só é aceito após webhook/summary, nunca pelo
// retorno successUrl isoladamente.
//
// Limpeza manual: o Sandbox acumula clientes, cobranças, assinaturas e checkouts.
// Ao terminar, no painel Sandbox procure o e-mail `cy-sandbox-<timestamp>-*` e remova
// as cobranças/assinaturas e o cliente correspondentes. Não há limpeza automática por API.

const PAYMENT_TIMEOUT_MS = 3 * 60 * 1000;

function signupCredentials(kind) {
    // Fica dentro do fluxo de cada it(): uma repetição do Cypress não reutiliza
    // uma conta existente (nem uma senha previsível).
    const nonce = `${Date.now()}-${Cypress._.uniqueId('sandbox')}`;
    const randomPassword = Array.from(crypto.getRandomValues(new Uint32Array(3)), (value) => value.toString(36)).join(
        '',
    );

    return {
        email: `cy-sandbox-${nonce}-${kind}@example.test`,
        password: `Sandbox!${randomPassword}aA`,
    };
}

function assertSandboxEnvironment() {
    if (String(Cypress.env('SANDBOX')) !== '1') {
        throw new Error('Sandbox bloqueado: execute este spec somente com CYPRESS_SANDBOX=1.');
    }

    const baseUrl = Cypress.config('baseUrl');
    const hostname = new URL(baseUrl).hostname;
    const localHost = hostname === 'localhost' || hostname === '127.0.0.1';

    if (!localHost) {
        throw new Error(
            `Sandbox bloqueado: CYPRESS_BASE_URL usa ${hostname}; somente localhost/127.0.0.1 é permitido.`,
        );
    }

    cy.task('asaasSandboxConfigured').then((configured) => {
        if (!configured) {
            throw new Error(
                'Sandbox bloqueado: configure ASAAS_BASE_URL=https://api-sandbox.asaas.com e ASAAS_SECRET com prefixo $aact_hmlg_ antes de cadastrar ou cobrar.',
            );
        }
    });
}

function maybePauseForManualPayment() {
    if (String(Cypress.env('SANDBOX_PAUSE')) === '1') {
        cy.log('Simule o pagamento no painel Asaas Sandbox; retome o Cypress para iniciar o polling.');
        cy.pause();
    }
}

function waitForWebhook(until, timeoutMessage, startedAt = Date.now()) {
    return cy.request('/signup-checkout/summary').then(({ body }) => {
        const summary = body?.data ?? body;
        const result = until(summary);

        if (result) return result;
        if (Date.now() - startedAt >= PAYMENT_TIMEOUT_MS) {
            throw new Error(timeoutMessage);
        }

        return cy.wait(5000).then(() => waitForWebhook(until, timeoutMessage, startedAt));
    });
}

function waitForWebhookActivation() {
    return waitForWebhook((summary) => {
        const invoicePaid = (summary.invoices ?? []).some((invoice) => invoice.status === 'paid');

        return invoicePaid && summary.subscription?.status === 'active' ? summary : null;
    }, 'O webhook não ativou a assinatura em até 3 minutos. Confira o ngrok, a URL/segredo do webhook e o evento no Asaas Sandbox.');
}

function waitForWebhookFailure() {
    return waitForWebhook((summary) => {
        const failedInvoice = (summary.invoices ?? []).find((invoice) => invoice.status === 'failed');

        return failedInvoice && summary.subscription?.status !== 'active' ? { summary, failedInvoice } : null;
    }, 'O webhook de recusa não marcou a fatura como failed em até 3 minutos. Confira o ngrok, a URL/segredo do webhook e o evento PAYMENT_CREDIT_CARD_CAPTURE_REFUSED no Asaas Sandbox.');
}

function createCheckoutSignup(kind) {
    const { email, password } = signupCredentials(kind);

    cy.clearCookies();
    cy.clearLocalStorage();
    cy.visit('/register');

    cy.get('[data-testid="register-name"]').type(`Cypress Sandbox ${kind}`);
    cy.get('[data-testid="register-email"]').type(email);
    cy.get('[data-testid="register-password"]').type(password, { log: false });
    cy.get('[data-testid="register-password-confirmation"]').type(password, { log: false });
    cy.get('[data-testid="register-next"]').click();

    cy.get('[data-testid="register-start-checkout"]').should('be.visible').click();
    cy.get('[data-testid="register-plan"]').first().should('be.visible').click();
    cy.get('[data-testid="register-company-name"]').type(`Clínica Sandbox ${kind}`);
    cy.get('[data-testid="register-company-phone"]').type('11987654321');
    cy.get('[data-testid="terms-accept-checkbox"]').should('be.visible').check();
    cy.get('[data-testid="register-submit"]').should('be.enabled').click();
    cy.get('[data-test="signup-checkout"]', { timeout: 20000 }).should('be.visible');
}

function choose(method) {
    cy.get(`[data-test="signup-checkout"] button[data-method="${method}"]`).should('be.visible').click();
}

function fillHostedCard(checkoutUrl, cardNumber) {
    const checkoutOrigin = new URL(checkoutUrl).origin;

    cy.get('[data-test="checkout-hosted-go"]').click();
    cy.origin(checkoutOrigin, { args: { cardNumber } }, ({ cardNumber: number }) => {
        cy.get('input[autocomplete="cc-number"], input[name="cardNumber"]').first().type(number);
        cy.get('input[autocomplete="cc-exp"], input[name="expirationDate"]').first().type('1229');
        cy.get('input[autocomplete="cc-csc"], input[name="cvv"]').first().type('123');
        cy.get('input[autocomplete="cc-name"], input[name="holderName"]').first().type('Cypress Sandbox');
        cy.contains('button', /pagar|finalizar|confirmar/i)
            .should('be.enabled')
            .click();
    });
}

function retryHostedCheckout(contract) {
    return cy
        .window()
        .then((win) =>
            win.axios.post(
                '/signup-checkout/contract',
                { ...contract, method: 'credit_card' },
                { headers: { Accept: 'application/json', 'Idempotency-Key': `cy-retry-${Date.now()}` } },
            ),
        )
        .then(({ data }) => {
            const checkoutUrl = data?.data?.checkout_url;

            expect(checkoutUrl, 'URL do novo Asaas Checkout').to.match(
                /^https:\/(?:\/([a-z0-9-]+\.)*asaas\.com)(?:\/|$)/i,
            );

            return checkoutUrl;
        });
}

describe('Sandbox Asaas — cadastro com pagamento no ato', () => {
    before(() => {
        // Guard obrigatório: falha antes de createCheckoutSignup() gerar conta/cobrança.
        assertSandboxEnvironment();
    });

    it('Pix exibe QR, copia-e-cola e validade; o webhook ativa a assinatura', () => {
        createCheckoutSignup('pix');
        choose('pix');

        cy.get('[data-test="checkout-pix"]').should('be.visible');
        cy.get('[data-test="pix-qr"]').should('be.visible');
        cy.get('[data-test="checkout-pix"] textarea, [data-test="checkout-pix"] input')
            .should('have.value')
            .and('not.be.empty');
        cy.get('[data-test="pix-expires"]').should('be.visible');

        maybePauseForManualPayment();
        waitForWebhookActivation();
        cy.get('[data-test="checkout-check"]').click();
        cy.get('[data-test="checkout-paid"]', { timeout: 20000 }).should('be.visible');
    });

    it('boleto exibe linha digitável, PDF e vencimento; o webhook ativa a assinatura', () => {
        createCheckoutSignup('boleto');
        choose('boleto');

        cy.get('[data-test="checkout-boleto"]').should('be.visible');
        cy.get('[data-test="checkout-boleto"] textarea, [data-test="checkout-boleto"] input')
            .should('have.value')
            .and('not.be.empty');
        cy.get('[data-test="boleto-pdf"]')
            .should('have.attr', 'href')
            .and('match', /^https:/);
        cy.get('[data-test="boleto-due"]').should('be.visible');

        maybePauseForManualPayment();
        waitForWebhookActivation();
        cy.get('[data-test="checkout-check"]').click();
        cy.get('[data-test="checkout-paid"]', { timeout: 20000 }).should('be.visible');
    });

    it('cartão aprovado passa pelo Asaas Checkout, volta pela successUrl e só conclui após webhook', () => {
        createCheckoutSignup('card-approved');
        cy.intercept('POST', '**/signup-checkout/contract').as('signupContract');
        choose('credit_card');
        cy.get('[data-test="checkout-hosted"]').should('be.visible');
        cy.get('[data-test="checkout-hosted-recurrent"]').should('be.visible');
        cy.wait('@signupContract').then(({ response }) => {
            const checkoutUrl = response?.body?.data?.checkout_url;
            expect(checkoutUrl, 'URL do Asaas Checkout').to.match(/^https:\/(?:\/([a-z0-9-]+\.)*asaas\.com)(?:\/|$)/i);
            fillHostedCard(checkoutUrl, '4444444444444444');
        });

        cy.location('pathname', { timeout: 30000 }).should('eq', '/signup-checkout/return');
        cy.location('search').should('include', 'checkout_return=success');
        cy.get('[data-testid="checkout-return-status"]').should('contain.text', /Aguardando|Pagamento confirmado/);

        maybePauseForManualPayment();
        waitForWebhookActivation();
        cy.reload();
        cy.get('[data-testid="checkout-return-status"]').should('contain.text', 'Pagamento confirmado');
        cy.get('[data-testid="checkout-return-next"]').should('contain.text', 'Verificar e-mail');
    });

    it('cartão recusado mostra erro no Checkout hospedado e permite nova tentativa', () => {
        let retryContract;

        createCheckoutSignup('card-declined');
        cy.intercept('POST', '**/signup-checkout/contract').as('signupContract');
        choose('credit_card');
        cy.wait('@signupContract').then(({ request, response }) => {
            retryContract = request.body;
            const checkoutUrl = response?.body?.data?.checkout_url;
            expect(checkoutUrl, 'URL do Asaas Checkout').to.match(/^https:\/(?:\/([a-z0-9-]+\.)*asaas\.com)(?:\/|$)/i);
            const checkoutOrigin = new URL(checkoutUrl).origin;

            cy.get('[data-test="checkout-hosted-go"]').click();
            cy.origin(checkoutOrigin, () => {
                cy.get('input[autocomplete="cc-number"], input[name="cardNumber"]').first().type('5184019740373151');
                cy.get('input[autocomplete="cc-exp"], input[name="expirationDate"]').first().type('1229');
                cy.get('input[autocomplete="cc-csc"], input[name="cvv"]').first().type('123');
                cy.get('input[autocomplete="cc-name"], input[name="holderName"]').first().type('Cypress Sandbox');
                cy.contains('button', /pagar|finalizar|confirmar/i)
                    .should('be.enabled')
                    .click();
                cy.get('[role="alert"], .alert-danger, .error-message', { timeout: 20000 }).should('be.visible');
                cy.get('input[autocomplete="cc-number"], input[name="cardNumber"]').first().should('be.enabled');
            });
        });

        waitForWebhookFailure().then(({ summary, failedInvoice }) => {
            cy.visit(`/signup-checkout/return?checkout_return=cancel&checkout_invoice=${failedInvoice.id}`);
            cy.get('[data-testid="checkout-return-status"]').should('contain.text', 'não foi concluído');
            expect(summary.subscription?.is_awaiting_first_payment).to.equal(true);
            expect(summary.subscription?.status).not.to.equal('active');
            expect(failedInvoice.can_pay).to.equal(true);

            retryHostedCheckout(retryContract).then((checkoutUrl) => {
                const checkoutOrigin = new URL(checkoutUrl).origin;

                cy.window().then((win) => win.location.assign(checkoutUrl));
                cy.origin(checkoutOrigin, () => {
                    cy.get('input[autocomplete="cc-number"], input[name="cardNumber"]').first().should('be.visible');
                });
            });
        });
    });
});
