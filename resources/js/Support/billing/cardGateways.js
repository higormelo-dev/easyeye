import { loadScript } from './loadScript.js';

/**
 * Tokenização do cartão no navegador com o SDK JS OFICIAL de cada gateway —
 * o número, a validade e o CVV nunca vão para o servidor do EasyEye, só o
 * token (card_token). Nada aqui registra dados do cartão (console/log).
 *
 * Cada adaptador expõe:
 *  - kind: 'brick' (o SDK desenha o formulário e o botão — Mercado Pago),
 *          'element' (o SDK desenha os campos num iframe — Stripe) ou
 *          'fields' (campos nossos, sem `name`, lidos só pelo SDK — Pagar.me e PagBank);
 *  - mount(target, options): carrega o SDK e monta;
 *  - tokenize(fields?): devolve { card_token, payment_method_id?, issuer_id? };
 *  - handleNextAction(nextAction): 3DS (Stripe) — { ok, error? };
 *  - destroy().
 *
 * Documentação oficial:
 *  - Mercado Pago Card Payment Brick: https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/card-payment-brick/introduction
 *  - Stripe Payment Element + ConfirmationToken: https://docs.stripe.com/payments/finalize-payments-on-the-server
 *  - Pagar.me tokenizecard.js: https://docs.pagar.me/reference/pagarme-js
 *  - PagBank criptografia do cartão: https://developer.pagbank.com.br/docs/criptografia-e-chave-publica
 */

export class CardSdkError extends Error {
    /** @param {'sdk_load_failed'|'sdk_unavailable'|'invalid'|'cancelled'|'next_action_failed'} code */
    constructor(code, detail = '') {
        super(code);
        this.code = code;
        // Mensagem já traduzida pelo próprio SDK (ex.: Stripe), nunca dados do cartão.
        this.detail = detail;
    }
}

function sdkGlobal(name) {
    return typeof window !== 'undefined' ? window[name] : undefined;
}

async function ensureSdk(config, globalName, attributes = {}) {
    if (!sdkGlobal(globalName)) {
        try {
            await loadScript(config.sdk_url, attributes);
        } catch {
            throw new CardSdkError('sdk_load_failed');
        }
    }
    if (!sdkGlobal(globalName)) throw new CardSdkError('sdk_unavailable');

    return sdkGlobal(globalName);
}

const toCents = (amount) => Math.round(Number(amount || 0) * 100);

// ── Mercado Pago: Card Payment Brick ─────────────────────────────────────────
function mercadoPagoAdapter(config) {
    let controller = null;

    return {
        kind: 'brick',
        async mount(containerId, { amount, maxInstallments = 1, locale = 'pt-BR', onSubmit, onReady, onError }) {
            const MercadoPago = await ensureSdk(config, 'MercadoPago');
            const mp = new MercadoPago(config.public_key, { locale });

            controller = await mp.bricks().create('cardPayment', containerId, {
                initialization: { amount: Number(amount) },
                customization: {
                    paymentMethods: { minInstallments: 1, maxInstallments: Math.max(1, maxInstallments) },
                    visual: { style: { theme: 'default' } },
                },
                callbacks: {
                    onReady: () => onReady?.(),
                    // cardFormData: { token, issuer_id, payment_method_id, installments, ... }
                    onSubmit: (cardFormData) =>
                        onSubmit({
                            card_token: cardFormData?.token,
                            installments: Number(cardFormData?.installments) || 1,
                            payment_method_id: cardFormData?.payment_method_id ?? null,
                            issuer_id: cardFormData?.issuer_id != null ? String(cardFormData.issuer_id) : null,
                        }),
                    onError: () => onError?.(),
                },
            });
        },
        async tokenize() {
            // O Brick envia pelo próprio botão (onSubmit).
            throw new CardSdkError('invalid');
        },
        async handleNextAction() {
            return { ok: true };
        },
        destroy() {
            try {
                controller?.unmount?.();
            } catch {
                /* já desmontado */
            }
            controller = null;
        },
    };
}

// ── Stripe: Payment Element + ConfirmationToken (3DS: handleNextAction) ──────
function stripeAdapter(config) {
    let stripe = null;
    let elements = null;
    let element = null;

    return {
        kind: 'element',
        async mount(target, { amount, mode = 'payment', locale = 'pt-BR', onReady, onChange }) {
            const Stripe = await ensureSdk(config, 'Stripe');
            stripe = Stripe(config.public_key, { locale: locale.startsWith('pt') ? 'pt-BR' : 'en' });

            // elements_options do servidor (mode payment, brl, off_session, só cartão);
            // a troca de cartão usa o modo setup (sem valor — nada é cobrado).
            const options = { currency: 'brl', paymentMethodTypes: ['card'], ...(config.elements_options ?? {}) };
            if (mode === 'setup') {
                options.mode = 'setup';
                options.setupFutureUsage = 'off_session';
                delete options.amount;
            } else {
                options.mode = 'payment';
                options.amount = toCents(amount);
            }

            elements = stripe.elements(options);
            element = elements.create('payment', { layout: 'tabs' });
            element.on?.('ready', () => onReady?.());
            element.on?.('change', (event) => onChange?.(event));
            element.mount(target);
        },
        async tokenize() {
            const submitted = await elements.submit();
            if (submitted?.error) throw new CardSdkError('invalid', submitted.error.message ?? '');

            const { error, confirmationToken } = await stripe.createConfirmationToken({ elements });
            if (error || !confirmationToken?.id) throw new CardSdkError('invalid', error?.message ?? '');

            return { card_token: confirmationToken.id };
        },
        async handleNextAction(nextAction) {
            const clientSecret = nextAction?.client_secret;
            if (!clientSecret) return { ok: false };

            const result = await stripe.handleNextAction({ clientSecret });
            if (result?.error) return { ok: false, error: result.error.message ?? '' };

            const intent = result?.paymentIntent ?? result?.setupIntent ?? null;

            return { ok: true, status: intent?.status ?? null };
        },
        destroy() {
            try {
                element?.destroy?.();
            } catch {
                /* já desmontado */
            }
            element = null;
            elements = null;
        },
    };
}

// ── Pagar.me: tokenizecard.js (campos com data-pagarmecheckout-element) ──────
function pagarmeAdapter(config) {
    let pending = null;

    function settle(fn, value) {
        const current = pending;
        pending = null;
        current?.[fn](value);
    }

    return {
        kind: 'fields',
        // O formulário precisa existir antes do init (o script liga o submit dele).
        async mount() {
            const PagarmeCheckout = await ensureSdk(config, 'PagarmeCheckout', {
                'data-pagarmecheckout-app-id': config.public_key,
            });

            PagarmeCheckout.init(
                (data) => {
                    const token =
                        data?.pagarmetoken ??
                        data?.['pagarmetoken-0'] ??
                        document.querySelector('[data-pagarmecheckout-form] input[name^="pagarmetoken"]')?.value;
                    if (token) settle('resolve', { card_token: String(token) });
                    else settle('reject', new CardSdkError('invalid'));

                    // false: o formulário não é enviado (o token segue pela API do checkout).
                    return false;
                },
                () => {
                    settle('reject', new CardSdkError('invalid'));

                    return false;
                },
            );
        },
        /** Chamado no submit do formulário: o script do Pagar.me gera o token e chama o success. */
        tokenize() {
            return new Promise((resolve, reject) => {
                pending = { resolve, reject };
                setTimeout(() => settle('reject', new CardSdkError('invalid')), 30000);
            });
        },
        async handleNextAction() {
            return { ok: true };
        },
        destroy() {
            settle('reject', new CardSdkError('cancelled'));
            // O token gerado fica num input oculto: não deixa sobrar no DOM.
            document
                .querySelectorAll('[data-pagarmecheckout-form] input[name^="pagarmetoken"]')
                .forEach((input) => input.remove());
        },
    };
}

// ── PagBank: PagSeguro.encryptCard (cartão criptografado com a chave pública) ─
function pagbankAdapter(config) {
    let sdk = null;

    return {
        kind: 'fields',
        async mount() {
            sdk = await ensureSdk(config, 'PagSeguro');
        },
        async tokenize(fields) {
            const result = sdk.encryptCard({
                publicKey: config.public_key,
                holder: String(fields.holder ?? '').trim(),
                number: String(fields.number ?? '').replace(/\D/g, ''),
                expMonth: String(fields.exp_month ?? '').padStart(2, '0'),
                expYear: String(fields.exp_year ?? ''),
                securityCode: String(fields.cvv ?? ''),
            });

            if (!result || result.hasErrors || !result.encryptedCard) {
                // Só os códigos de erro do SDK (ex.: INVALID_NUMBER), nunca os dados.
                const codes = (result?.errors ?? []).map((e) => e?.code).filter(Boolean);
                throw new CardSdkError('invalid', codes.join(','));
            }

            return { card_token: result.encryptedCard };
        },
        async handleNextAction() {
            return { ok: true };
        },
        destroy() {
            sdk = null;
        },
    };
}

const FACTORIES = {
    mercadopago: mercadoPagoAdapter,
    stripe_br: stripeAdapter,
    pagarme: pagarmeAdapter,
    pagbank: pagbankAdapter,
};

/** Gateways com formulário de cartão no navegador. */
export const SUPPORTED_CARD_GATEWAYS = Object.keys(FACTORIES);

/**
 * @param {{gateway: string, public_key: ?string, sdk_url: string}} config objeto `card` do contrato
 * @returns {object|null} adaptador, ou null quando o gateway não tem tokenização no navegador
 */
export function createCardAdapter(config) {
    const factory = FACTORIES[config?.gateway];

    return factory && config?.public_key && config?.sdk_url ? factory(config) : null;
}
