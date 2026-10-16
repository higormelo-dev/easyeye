// HOMOLOGAÇÃO REAL, OPT-IN — Asaas Sandbox. Não entra no gate padrão e NÃO usa
// cy.intercept para cobrança ou webhook.
//
// Como rodar (a partir de e2e/):
//   CYPRESS_SANDBOX=1 npx cypress run --browser chrome
//
// Pré-requisitos, ANTES de abrir o Cypress:
// - CYPRESS_BASE_URL em localhost/127.0.0.1 (normalmente http://localhost:8085) OU, só pelo
//   e2e/scripts/sandbox-run.sh, na URL pública do túnel (CYPRESS_SANDBOX_TUNNEL_HOST): aí a
//   origem do navegador é a mesma do APP_URL e dos retornos do checkout. Qualquer outro host
//   é barrado: o comando Artisan local não comprova a configuração de app remoto, e o túnel
//   só vale se o ngrok local o reportar apontando para a porta do serve do script (a cy.task
//   confere);
// - ASAAS_BASE_URL=https://api-sandbox.asaas.com e uma chave Sandbox que começa
//   com $aact_hmlg_ (nunca grave a chave neste arquivo);
// - ASAAS_WEBHOOK_SECRET configurado no EasyEye e no webhook do Asaas Sandbox;
// - `ngrok http 8085` em execução e o webhook cadastrado no Sandbox apontando para
//   {URL_PUBLICA_NGROK}/api/billing/webhooks/asaas;
// - gateway padrão Asaas e pelo menos um plano público pago habilitados no ambiente.
//
// Pix: depois de a tela mostrar o QR, abra o painel Sandbox, simule o pagamento da
// cobrança criada e espere este spec encontrar o webhook. CYPRESS_SANDBOX_PAUSE=1
// (pagamento à mão) NÃO pausa o teste: só registra a instrução no log e no terminal e
// alarga a espera do webhook de 3 para 5 minutos — o polling já está rodando enquanto
// você paga (um cy.pause() deixava o teste parado até o Resume, mesmo com o webhook
// já processado).
// Boleto: mesma simulação manual no painel Sandbox; a assinatura só fica ativa depois do webhook.
//
// Cartões Sandbox Asaas (somente dados fictícios): aprovação 4444 4444 4444 4444,
// CVV 123, validade futura; recusa 5184019740373151 (Mastercard) ou
// 4916561358240741 (Visa). O sucesso só é aceito após webhook/summary, nunca pelo
// retorno successUrl isoladamente.
//
// CARTÃO É PAGO FORA DO CYPRESS. O "Pagar" do Asaas Checkout é protegido por reCAPTCHA Enterprise, que pontua o navegador:
// a janela do Cypress (navigator.webdriver, flags de automação, User-Agent do modo túnel) é recusada — também com o clique
// de uma pessoa nela (POST /checkoutSession/pay → "Tente novamente e verifique o recaptcha.", e a página recarrega vazia).
// Os dois casos de cartão por isso:
//   - sem CYPRESS_SANDBOX_PAUSE=1: são PULADOS (nada é criado — o teste sai antes do cadastro), com o motivo no log;
//   - com CYPRESS_SANDBOX_PAUSE=1: o spec cadastra, escolhe cartão, abre a sessão (POST contract → checkout_url), AFIRMA que a
//     página do Asaas abre com o valor da fatura (só leitura, support/asaasCheckout.js), IMPRIME a URL em destaque no terminal
//     e espera o webhook por até 5 min SEM preencher nada no Asaas. Você abre a URL no SEU navegador (não no do Cypress) e
//     paga com o cartão de teste: aprovado 4444 4444 4444 4444 ou recusado 5184 0197 4037 3151 (validade futura, CVV 123;
//     identificação e endereço são digitados por você — a página nasce vazia).
//   - Depois do webhook o spec VISITA a página de retorno que o Asaas usaria na successUrl:
//     /signup-checkout/return?checkout_return=success|cancel&checkout_invoice=<uuid da fatura> (route signup-checkout.return;
//     SEM token assinado — vale pela sessão do cadastro e a fatura é da clínica dela) e afirma o status. O redirecionamento do
//     Asaas para essa URL, no navegador de quem pagou, é só UX e este spec NÃO o exercita.
// A Identificação do Asaas chega vazia porque o Checkout só aceita cliente (`customer`/`customerData`) com endereço completo e
// o cadastro da clínica não tem endereço. Pix e boleto não têm essa limitação (o pagamento é simulado no painel Sandbox).
//
// Limpeza: cada e-mail gerado aqui é registrado (cy.task sandboxRunRecord → e2e/cypress/.sandbox-run/<nonce>.json) ANTES
// do cadastro, e o after() roda e2e/scripts/clean-sandbox-signups.php com esse estado: apaga do Asaas Sandbox e do banco
// de DEV SÓ as contas/clínicas desta rodada — nunca uma conta anterior que apenas combine com o padrão, e só com a
// credencial efetiva em sandbox, APP_ENV local/testing e banco local da lista. Para investigar uma falha com a conta
// ainda de pé, rode com CYPRESS_SANDBOX_KEEP=1 (nada é apagado; o estado fica) e limpe depois à mão:
//   SANDBOX_CLEAN_STATE=e2e/cypress/.sandbox-run/<nonce>.json php artisan tinker --execute="require 'e2e/scripts/clean-sandbox-signups.php';"
// Restos de rodadas mortas, sem estado: SANDBOX_CLEAN_ALL=1 (varredura por padrão, opt-in). SANDBOX_CLEAN_DRY_RUN=1 só
// lista. Objetos que a limpeza não alcança (cobranças já pagas no painel) ficam para o painel Sandbox.

import { assertAsaasCheckoutOpen } from '../../support/asaasCheckout';

// Pagamento À MÃO (CYPRESS_SANDBOX_PAUSE=1): quem paga precisa de mais tempo que o webhook automático. Nunca pausa o teste.
const MANUAL_PAYMENT = String(Cypress.env('SANDBOX_PAUSE')) === '1';
const PAYMENT_TIMEOUT_MS = (MANUAL_PAYMENT ? 5 : 3) * 60 * 1000;
const PAYMENT_TIMEOUT_MIN = PAYMENT_TIMEOUT_MS / 60000;
// Cartões do sandbox (doc do Asaas: "Testando pagamento com cartão de crédito"): qualquer validade futura, CVV 123.
// Domínio do Asaas (sandbox ou produção): o checkout_url do contrato nunca aponta para outro lugar.
const HOSTED_CHECKOUT_URL = /^https:\/(?:\/([a-z0-9-]+\.)*asaas\.com)(?:\/|$)/i;
const CARD_APPROVED = '4444 4444 4444 4444 (APROVADO)';
const CARD_DECLINED = '5184 0197 4037 3151 (RECUSADO)';
// cy.exec herda o ambiente do processo do Cypress (o do sandbox-run.sh: APP_URL do túnel; o banco é o do .env, o mesmo
// do serve), então nada é repassado aqui.
const CLEAN_SIGNUPS = `cd .. && php artisan tinker --execute="require 'e2e/scripts/clean-sandbox-signups.php';"`;
// Identifica a rodada: os e-mails gerados vão para cypress/.sandbox-run/<RUN_NONCE>.json (o spec não é recarregado entre
// as retentativas do Cypress, então o nonce é o mesmo).
const RUN_NONCE = `${Date.now().toString(36)}-${Cypress._.random(1e9).toString(36)}`;
// Espera pela COBRANÇA depois de choose(): ela nasce no Asaas ao escolher o método. Pior caso do app: cada chamada ao
// gateway leva até 30 s (AbstractHttpGateway: Http::timeout(30)) e, com a cobrança ainda sendo gerada
// (charge_pending), o CheckoutFlow repete em 3, 5, 8, 13 e 21 s (RETRY_DELAYS = 50 s só de espera) — e CADA
// retentativa chama load() de novo e espera OUTRA resposta. São 6 tentativas sequenciais (a 1ª + 5 retentativas):
// 6 × 30 s + (3 + 5 + 8 + 13 + 21) s = 180 + 50 = 230 s, mais folga = 240 s. Menos que isso reprova o spec com a
// cobrança válida, antes de a UI concluir.
const CHARGE_CREATION_TIMEOUT = 240 * 1000;

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

// CNPJ numérico VÁLIDO (dígitos verificadores corretos) e novo a cada chamada. O Asaas exige CPF/CNPJ do cliente para criar
// a assinatura/cobrança (400 invalid_object: "Para criar esta cobrança é necessário preencher o CPF ou CNPJ do cliente.")
// e o campo é opcional no /register; entities.national_registration é único, então não pode ser um valor fixo.
function generateCnpj() {
    const digits = [Cypress._.random(1, 9), ...Array.from({ length: 7 }, () => Cypress._.random(0, 9)), 0, 0, 0, 1];

    [
        [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
        [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
    ].forEach((weights) => {
        const rest = weights.reduce((sum, weight, index) => sum + digits[index] * weight, 0) % 11;

        digits.push(rest < 2 ? 0 : 11 - rest);
    });

    return digits.join('');
}

// A limpeza NUNCA reprova um caso verde: uma cobrança já paga no painel pode impedir apagar o cliente no Asaas. Restos
// são sinalizados (cy.log + console.warn) com o comando manual, e o script é idempotente. O próprio script tem as
// guardas (sandbox + local/testing) e só toca o que casa com os padrões do spec.
const CLEAN_MANUAL = `SANDBOX_CLEAN_STATE=e2e/cypress/.sandbox-run/${RUN_NONCE}.json php artisan tinker --execute="require 'e2e/scripts/clean-sandbox-signups.php';"`;

function cleanSandboxSignups() {
    if (String(Cypress.env('SANDBOX_KEEP')) === '1') {
        cy.log('CYPRESS_SANDBOX_KEEP=1: contas, clínicas e clientes do sandbox mantidos.');

        return;
    }

    cy.task('sandboxRunState', { nonce: RUN_NONCE }).then((statePath) => {
        if (!statePath) {
            cy.log('Nenhuma conta foi registrada nesta rodada: nada a limpar.');

            return;
        }

        cy.exec(CLEAN_SIGNUPS, {
            timeout: 180000,
            failOnNonZeroExit: false,
            env: { SANDBOX_CLEAN_STATE: statePath },
        }).then(({ code, stdout, stderr }) => {
            const summary = stdout.trim().split('\n').slice(-3).join(' | ');

            cy.log(summary);
            // cy.log não aparece no terminal do `cypress run`: o resultado da limpeza sai também pela task.
            cy.task('sandboxNotice', { message: `Limpeza do sandbox (exit ${code}): ${summary}` });

            if (code !== 0) {
                const warning = `Limpeza do sandbox incompleta (exit ${code}). Rode à mão: ${CLEAN_MANUAL}\n${stdout}\n${stderr}`;

                cy.log(warning);
                cy.task('sandboxNotice', { message: warning });
                console.warn(warning);
            }
        });
    });
}

function assertSandboxEnvironment() {
    if (String(Cypress.env('SANDBOX')) !== '1') {
        throw new Error('Sandbox bloqueado: execute este spec somente com CYPRESS_SANDBOX=1.');
    }

    const baseUrl = Cypress.config('baseUrl');
    const hostname = new URL(baseUrl).hostname;
    const localHost = hostname === 'localhost' || hostname === '127.0.0.1';
    const tunnelHost = String(Cypress.env('SANDBOX_TUNNEL_HOST') ?? '')
        .trim()
        .toLowerCase();

    if (!localHost && (tunnelHost === '' || hostname !== tunnelHost)) {
        throw new Error(
            `Sandbox bloqueado: CYPRESS_BASE_URL usa ${hostname}; só localhost/127.0.0.1 ou o túnel do sandbox-run.sh é permitido.`,
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

function installNgrokInterstitialBypass() {
    const hostname = new URL(Cypress.config('baseUrl')).hostname;
    const tunnelHost = String(Cypress.env('SANDBOX_TUNNEL_HOST') ?? '')
        .trim()
        .toLowerCase();

    if (tunnelHost === '' || hostname !== tunnelHost) return;

    cy.intercept({ hostname: tunnelHost, middleware: true }, (request) => {
        request.headers['ngrok-skip-browser-warning'] = '1';
    });
}

function assertReturnedToCurrentApp() {
    const appHost = new URL(Cypress.config('baseUrl')).host;

    cy.location('host', { timeout: 30000 }).should('eq', appHost);
    cy.location('pathname').should('eq', '/signup-checkout/return');
}

// Só instrui (log + terminal): NÃO pausa. O polling do webhook (waitForWebhook) já roda enquanto a pessoa paga.
function announceManualPayment(instruction) {
    if (!MANUAL_PAYMENT) return;

    const message = `${instruction} Aguardando o webhook por até ${PAYMENT_TIMEOUT_MIN} min.`;

    cy.log(message);
    cy.task('sandboxNotice', { message });
    cy.then(() => console.warn(message));
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
    }, `O webhook não ativou a assinatura em até ${PAYMENT_TIMEOUT_MIN} minutos. Confira o ngrok, a URL/segredo do webhook e o evento no Asaas Sandbox.`);
}

function waitForWebhookFailure() {
    return waitForWebhook((summary) => {
        const failedInvoice = (summary.invoices ?? []).find((invoice) => invoice.status === 'failed');

        return failedInvoice && summary.subscription?.status !== 'active' ? { summary, failedInvoice } : null;
    }, `O webhook de recusa não marcou a fatura como failed em até ${PAYMENT_TIMEOUT_MIN} minutos. Confira o ngrok, a URL/segredo do webhook e o evento PAYMENT_CREDIT_CARD_CAPTURE_REFUSED no Asaas Sandbox.`);
}

// O card do /register (e o checkout, que mora dentro dele) fica sob um ancestral `position: fixed` que recorta o que
// passa da janela: o Cypress só considera o elemento visível/clicável depois de rolá-lo para dentro dela ("not visible
// because its ancestor has position: fixed and it is overflowed"). Mesma sequência provada em
// profiles/billing.cy.js (createSignup): rola com folga para o cabeçalho, REconsulta (o nó pode ter sido recriado) e só
// então afirma a visibilidade. Nada de { force: true }: ele esconderia um card realmente quebrado.
function scrollVisible(selector, { first = false, timeout } = {}) {
    const query = () => {
        const chain = cy.get(selector, timeout ? { timeout } : {});

        return first ? chain.first() : chain;
    };

    query().scrollIntoView({ offset: { top: -100, left: 0 } });

    return query().should('be.visible');
}

function createCheckoutSignup(kind) {
    const { email, password } = signupCredentials(kind);
    const companyName = `Clínica Sandbox ${kind}`;

    // Registra o e-mail ANTES do cadastro: a conta pode nascer mesmo que o caso falhe depois — e é só ela que a limpeza apaga.
    cy.task('sandboxRunRecord', { nonce: RUN_NONCE, email });

    cy.clearCookies();
    cy.clearLocalStorage();
    cy.visit('/register');

    cy.get('[data-testid="register-name"]').type(`Cypress Sandbox ${kind}`);
    cy.get('[data-testid="register-email"]').type(email);
    cy.get('[data-testid="register-password"]').type(password, { log: false });
    cy.get('[data-testid="register-password-confirmation"]').type(password, { log: false });
    cy.get('[data-testid="register-next"]').click();

    // Passo 2: planos carregados e a transição (.reg-fade-enter-active) terminada antes de rolar e clicar.
    cy.get('[data-testid="register-plan"]', { timeout: 15000 }).should('have.length.at.least', 1);
    cy.get('.reg-card .reg-fade-enter-active', { timeout: 15000 }).should('not.exist');
    scrollVisible('[data-testid="register-plan"]', { first: true });
    scrollVisible('[data-testid="register-start-checkout"]', { timeout: 15000 })
        .click()
        .should('have.attr', 'aria-checked', 'true');
    scrollVisible('[data-testid="register-plan"]', { first: true }).click();
    scrollVisible('[data-testid="register-company-name"]').type(companyName);
    scrollVisible('[data-testid="register-company-phone"]').type('11987654321');

    // CNPJ do responsável pela cobrança: sem ele o Asaas recusa a assinatura (ver generateCnpj).
    const cnpj = generateCnpj();

    scrollVisible('[data-testid="register-company-cnpj"]')
        .type(cnpj)
        .should(($input) => {
            expect($input.val().replace(/\D/g, '')).to.eq(cnpj);
        });
    scrollVisible('[data-testid="terms-accept-checkbox"]').check();
    scrollVisible('[data-testid="register-submit"]').should('be.enabled').click();

    // Passo 3 (pagamento) entra com a mesma transição: espera o fim dela e rola até o checkout.
    cy.get('[data-test="signup-checkout"]', { timeout: 20000 }).should('exist');
    cy.get('.reg-card .reg-fade-enter-active', { timeout: 15000 }).should('not.exist');
    scrollVisible('[data-test="signup-checkout"]', { timeout: 20000 });
}

function choose(method) {
    scrollVisible(`[data-test="signup-checkout"] button[data-method="${method}"]`).click();
}

// Cartão é pago FORA do Cypress (reCAPTCHA Enterprise do Asaas — ver o cabeçalho). Sem CYPRESS_SANDBOX_PAUSE=1 o caso é
// pulado ANTES de criar conta/cobrança.
function requireManualCardPayment(test) {
    if (MANUAL_PAYMENT) return;

    const reason =
        'Pulado: o cartão é pago FORA do Cypress (o reCAPTCHA Enterprise do Asaas recusa o navegador do Cypress). ' +
        'Rode com CYPRESS_SANDBOX_PAUSE=1, abra no SEU navegador a URL que o spec imprime e pague com o cartão de teste.';

    Cypress.log({ name: 'pulado', message: reason });
    console.warn(reason);
    test.skip();
}

// A fatura em aberto do cadastro (id e valor). cy.request não depende de em que origem o navegador está.
function openSignupInvoice() {
    return cy.request('/signup-checkout/summary').then(({ body }) => {
        const invoice = (body?.data ?? body).open_invoices?.[0];

        expect(invoice, 'fatura em aberto do cadastro').to.exist;

        return invoice;
    });
}

// Entrega a URL do checkout a um HUMANO (task: o terminal do `cypress run` não mostra cy.log/console).
function announceCardPayment(checkoutUrl, card) {
    const message = [
        'PAGUE O CARTÃO FORA DO CYPRESS — o reCAPTCHA Enterprise do Asaas recusa o navegador do Cypress.',
        'Abra esta URL no SEU navegador (não na janela do Cypress):',
        `    ${checkoutUrl}`,
        `Cartão de teste: ${card} · validade futura (ex.: 12/30) · CVV 123`,
        'A página vem vazia: digite e-mail, nome, CPF/CNPJ, celular e endereço (ex.: CEP 01310-100, número 1000).',
        `O spec espera o webhook por até ${PAYMENT_TIMEOUT_MIN} min.`,
    ].join('\n');

    cy.log(message);
    cy.task('sandboxNotice', { message, banner: true });
    cy.then(() => console.warn(message));
}

// Abre a sessão do Asaas no navegador do Cypress só para LER (a página abre com o valor da fatura) e imprime a URL.
function openHostedCheckoutAndAnnounce(checkoutUrl, card, invoice) {
    scrollVisible('[data-test="checkout-hosted-go"]').click();
    assertAsaasCheckoutOpen(checkoutUrl, { amount: invoice.amount });
    announceCardPayment(checkoutUrl, card);
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

            expect(checkoutUrl, 'URL do novo Asaas Checkout').to.match(HOSTED_CHECKOUT_URL);

            return checkoutUrl;
        });
}

describe('Sandbox Asaas — cadastro com pagamento no ato', () => {
    let sandboxVerified = false;

    before(() => {
        // Guard obrigatório: falha antes de createCheckoutSignup() gerar conta/cobrança.
        assertSandboxEnvironment();
        cy.then(() => {
            sandboxVerified = true;
        });
    });

    after(() => {
        // Guard barrado = nada foi criado: não há o que limpar (e nada a executar fora do sandbox).
        if (sandboxVerified) cleanSandboxSignups();
    });

    beforeEach(() => {
        installNgrokInterstitialBypass();
    });

    it('Pix exibe QR, copia-e-cola e validade; o webhook ativa a assinatura', () => {
        createCheckoutSignup('pix');
        choose('pix');

        scrollVisible('[data-test="checkout-pix"]', { timeout: CHARGE_CREATION_TIMEOUT });
        scrollVisible('[data-test="pix-qr"]');
        // BR Code (Pix copia-e-cola) real do Asaas: começa por 000201 (payload format indicator do EMV).
        cy.get('[data-test="checkout-pix"] textarea, [data-test="checkout-pix"] input')
            .first()
            .invoke('val')
            .should('match', /^000201/);
        scrollVisible('[data-test="pix-expires"]');

        announceManualPayment('Simule o pagamento do Pix no painel Asaas Sandbox (cobrança desta conta de teste).');
        waitForWebhookActivation();
        scrollVisible('[data-test="checkout-check"]').click();
        scrollVisible('[data-test="checkout-paid"]', { timeout: 20000 });
    });

    it('boleto exibe linha digitável, PDF e vencimento; o webhook ativa a assinatura', () => {
        createCheckoutSignup('boleto');
        choose('boleto');

        scrollVisible('[data-test="checkout-boleto"]', { timeout: CHARGE_CREATION_TIMEOUT });
        // Linha digitável do boleto: 47 dígitos (o campo pode exibi-la sem máscara).
        cy.get('[data-test="checkout-boleto"] textarea, [data-test="checkout-boleto"] input')
            .first()
            .invoke('val')
            .should('match', /^\d{47}$/);
        cy.get('[data-test="boleto-pdf"]')
            .should('have.attr', 'href')
            .and('match', /^https:/);
        scrollVisible('[data-test="boleto-due"]');

        announceManualPayment('Simule o pagamento do boleto no painel Asaas Sandbox (cobrança desta conta de teste).');
        waitForWebhookActivation();
        scrollVisible('[data-test="checkout-check"]').click();
        scrollVisible('[data-test="checkout-paid"]', { timeout: 20000 });
    });

    it('cartão aprovado: a sessão do Asaas abre com o valor; pago fora do Cypress, o webhook ativa e o retorno mostra o pagamento', function () {
        requireManualCardPayment(this);

        createCheckoutSignup('card-approved');
        cy.intercept('POST', '**/signup-checkout/contract').as('signupContract');
        choose('credit_card');
        scrollVisible('[data-test="checkout-hosted"]', { timeout: CHARGE_CREATION_TIMEOUT });
        scrollVisible('[data-test="checkout-hosted-recurrent"]');
        cy.wait('@signupContract', { responseTimeout: CHARGE_CREATION_TIMEOUT }).then(({ response }) => {
            const checkoutUrl = response?.body?.data?.checkout_url;
            expect(checkoutUrl, 'URL do Asaas Checkout').to.match(HOSTED_CHECKOUT_URL);
            openSignupInvoice().then((invoice) => openHostedCheckoutAndAnnounce(checkoutUrl, CARD_APPROVED, invoice));
        });

        // A pessoa paga no navegador dela; só o webhook ativa a assinatura (nunca a volta pela successUrl).
        waitForWebhookActivation().then((summary) => {
            const paid = summary.invoices.find((invoice) => invoice.status === 'paid');

            expect(paid, 'fatura paga pelo webhook').to.exist;

            // A página para onde o Asaas devolve quem pagou (successUrl do HostedCheckoutService::callbackUrl): mesma rota e
            // query, sem token — vale pela sessão do cadastro. O redirecionamento em si não é exercitado aqui.
            cy.visit(`/signup-checkout/return?checkout_return=success&checkout_invoice=${paid.id}`);
            assertReturnedToCurrentApp();
            cy.get('[data-testid="checkout-return-status"]').should('contain.text', 'Pagamento confirmado');
            cy.get('[data-testid="checkout-return-next"]').should('contain.text', 'Verificar e-mail');
        });
    });

    it('cartão recusado: a fatura falha pelo webhook e a nova tentativa devolve uma sessão do Asaas que abre', function () {
        requireManualCardPayment(this);

        let retryContract;
        let firstCheckoutUrl;

        createCheckoutSignup('card-declined');
        cy.intercept('POST', '**/signup-checkout/contract').as('signupContract');
        choose('credit_card');
        cy.wait('@signupContract', { responseTimeout: CHARGE_CREATION_TIMEOUT }).then(({ request, response }) => {
            retryContract = request.body;
            firstCheckoutUrl = response?.body?.data?.checkout_url;
            expect(firstCheckoutUrl, 'URL do Asaas Checkout').to.match(HOSTED_CHECKOUT_URL);
            openSignupInvoice().then((invoice) =>
                openHostedCheckoutAndAnnounce(firstCheckoutUrl, CARD_DECLINED, invoice),
            );
        });

        // A prova da recusa é o webhook (a fatura vira `failed`), nunca a página do Asaas.
        waitForWebhookFailure().then(({ summary, failedInvoice }) => {
            expect(summary.subscription?.is_awaiting_first_payment).to.equal(true);
            expect(summary.subscription?.status).not.to.equal('active');
            expect(failedInvoice.can_pay).to.equal(true);

            // A página de retorno do cancelUrl (mesma rota/query do HostedCheckoutService::callbackUrl).
            cy.visit(`/signup-checkout/return?checkout_return=cancel&checkout_invoice=${failedInvoice.id}`);
            assertReturnedToCurrentApp();
            cy.get('[data-testid="checkout-return-status"]').should('contain.text', 'não foi concluído');

            // A nova tentativa (POST contract de novo) devolve uma sessão que ABRE com o valor da fatura. Pelo código a sessão
            // recusada vira `failed` e não é reaproveitada (HostedCheckoutService::usableFor só reusa `active`), então a URL
            // muda — mas isso só vale se o webhook de recusa a ligou à sessão; por isso só registramos, sem afirmar.
            retryHostedCheckout(retryContract).then((retryUrl) => {
                cy.log(
                    retryUrl === firstCheckoutUrl
                        ? 'A nova tentativa reabriu a MESMA sessão do Asaas.'
                        : 'A nova tentativa abriu OUTRA sessão do Asaas.',
                );
                cy.window().then((win) => win.location.assign(retryUrl));
                assertAsaasCheckoutOpen(retryUrl, { amount: failedInvoice.amount });
            });
        });
    });
});
