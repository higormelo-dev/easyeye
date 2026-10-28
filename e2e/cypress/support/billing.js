// Ajudantes dos specs de cobrança (profiles/billing.cy.js e o gerador de
// capturas docs/billing-manual.cy.js): seed/clean das clínicas de cenário e
// as respostas simuladas do checkout (mesmo contrato das rotas JSON do
// sistema). O gateway de pagamento nunca é chamado.

const SEED = `cd .. && php artisan tinker --execute="require 'e2e/scripts/seed-billing.php';"`;
const CLEAN = `cd .. && php artisan tinker --execute="require 'e2e/scripts/clean-billing.php';"`;

const PIX_CODE =
    '00020126580014br.gov.bcb.pix0136cy-billing-e2e-0000-0000-000000000000520400005303986540589990' +
    '5802BR5914CY BILL EM DIA6009SAO PAULO62070503***6304CY01';
const BOLETO_LINE = '34191.79001 01043.510047 91020.150008 1 96610000089990';

const inMinutes = (m) => new Date(Date.now() + m * 60 * 1000).toISOString();

// `invoice` só quando a resposta abre uma fatura nova (contratar, comprar
// créditos); pagar uma fatura existente mantém a dela na tela.
function pixInstructions(invoice = null) {
    return {
        data: {
            ...(invoice
                ? { invoice: { id: invoice.id, reference: invoice.reference, amount: 899.9, currency: 'BRL' } }
                : {}),
            mode: 'transparent',
            method: 'pix',
            instructions: {
                method: 'pix',
                pix: { copy_paste: PIX_CODE, qr_code_base64: null, qr_image_url: null, expires_at: inMinutes(30) },
                boleto: null,
                payment_url: null,
            },
        },
    };
}

// `invoice` só quando a resposta abre uma fatura nova (contratar, comprar
// créditos); pagar uma fatura existente mantém a dela na tela.
function boletoInstructions(invoice = null) {
    return {
        data: {
            ...(invoice
                ? { invoice: { id: invoice.id, reference: invoice.reference, amount: 899.9, currency: 'BRL' } }
                : {}),
            mode: 'transparent',
            method: 'boleto',
            instructions: {
                method: 'boleto',
                pix: null,
                boleto: {
                    digitable_line: BOLETO_LINE,
                    barcode: '34196966100000899901790010435100479102015000',
                    pdf_url: 'https://sandbox.asaas.com/b/pdf/cy-billing',
                    due_date: new Date(Date.now() + 5 * 864e5).toISOString().slice(0, 10),
                },
                payment_url: null,
            },
        },
    };
}

/**
 * Instruções de pagamento das faturas (GET .../instructions): Pix e boleto
 * simulados (emitir no gateway é o que não pode acontecer aqui); cartão vai
 * ao backend real (Asaas = link da cobrança já gravada, sem chamar o gateway).
 * Qualquer POST que emitiria/pagaria no gateway é barrado e contado.
 */
function stubGatewayCalls() {
    cy.intercept(
        { method: 'GET', pathname: /\/(panel\/my-subscription|signup-checkout)\/invoices\/[^/]+\/instructions$/ },
        (req) => {
            if (req.query.method === 'pix') return req.reply(pixInstructions());
            if (req.query.method === 'boleto') return req.reply(boletoInstructions());
            return req.continue();
        },
    ).as('instructions');

    cy.intercept(
        { method: 'POST', pathname: /\/(panel\/my-subscription|signup-checkout)\/invoices\/[^/]+\/(charge|card)$/ },
        {
            statusCode: 500,
            body: { message: 'e2e: emissão no gateway não permitida', code: 'gateway_error' },
        },
    ).as('gatewayPost');
}

/** navigator.clipboard: o Chrome headless sem foco recusa — stub e confere o texto. */
function stubClipboard() {
    cy.window().then((win) => {
        if (win.navigator.clipboard) {
            cy.stub(win.navigator.clipboard, 'writeText').as('clipboard').resolves();
        }
    });
}

/** Roda o seed e devolve (via cy.wrap) o JSON com ids/tokens do cenário. */
function seedBilling() {
    return cy.exec(SEED, { timeout: 90000 }).then((r) => {
        const m = r.stdout.match(/billing-seed:(\{.*\});/);
        expect(m, `saída do seed: ${r.stdout} ${r.stderr}`).to.not.be.null;

        return JSON.parse(m[1]);
    });
}

function cleanBilling() {
    return cy.exec(CLEAN, { timeout: 60000, failOnNonZeroExit: false });
}

module.exports = {
    PIX_CODE,
    BOLETO_LINE,
    inMinutes,
    pixInstructions,
    boletoInstructions,
    stubGatewayCalls,
    stubClipboard,
    seedBilling,
    cleanBilling,
};
