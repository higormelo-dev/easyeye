import axios from 'axios';

/**
 * Cliente do checkout transparente (JSON). Mesmo contrato no painel
 * (panel.my-subscription.*) e no cadastro do site (signup-checkout.*):
 * respostas `{ data }`, erros `{ message, code }`.
 *
 * O cartão só passa por aqui como TOKEN do SDK do gateway; o POST do cartão
 * leva o header Idempotency-Key (o mesmo pedido repetido devolve o mesmo
 * resultado, sem cobrar duas vezes).
 */

const JSON_HEADERS = { Accept: 'application/json' };

/** Rotas do painel ("Minha assinatura", aviso, /subscription/expired). */
export function panelEndpoints() {
    return {
        summary: () => route('panel.my-subscription.summary'),
        options: (params) => route('panel.my-subscription.options', params),
        instructions: (invoice, method) => route('panel.my-subscription.instructions', { invoice, method }),
        charge: (invoice) => route('panel.my-subscription.charge', { invoice }),
        card: (invoice) => route('panel.my-subscription.card', { invoice }),
        replaceCard: () => route('panel.my-subscription.replace-card'),
        contract: () => route('panel.my-subscription.contract'),
        aiPackOptions: (params) => route('panel.my-subscription.ai-credits.options', params),
        aiPack: () => route('panel.my-subscription.ai-credits.purchase'),
        aiPackDiscard: (invoice) => route('panel.my-subscription.ai-credits.discard', { invoice }),
    };
}

/** Rotas do checkout do cadastro no site (antes de confirmar e-mail/WhatsApp). */
export function signupEndpoints() {
    return {
        summary: () => route('signup-checkout.summary'),
        options: (params) => route('signup-checkout.options', params),
        instructions: (invoice, method) => route('signup-checkout.instructions', { invoice, method }),
        charge: (invoice) => route('signup-checkout.charge', { invoice }),
        card: (invoice) => route('signup-checkout.card', { invoice }),
        replaceCard: null,
        contract: () => route('signup-checkout.contract'),
    };
}

/** Chave de idempotência nova (uma por token/pedido). */
export function newIdempotencyKey() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();

    return `ck-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

const unwrap = (response) => response?.data?.data ?? null;

export function createCheckoutApi(endpoints, http = axios) {
    const withKey = (key) => ({ headers: { ...JSON_HEADERS, ...(key ? { 'Idempotency-Key': key } : {}) } });

    return {
        endpoints,
        canReplaceCard: typeof endpoints.replaceCard === 'function',
        summary: async () => unwrap(await http.get(endpoints.summary(), { headers: JSON_HEADERS })),
        options: async (planId, cycle) =>
            unwrap(
                await http.get(endpoints.options({ plan_id: planId, billing_cycle: cycle }), { headers: JSON_HEADERS }),
            ),
        instructions: async (invoiceId, method) =>
            unwrap(await http.get(endpoints.instructions(invoiceId, method), { headers: JSON_HEADERS })),
        /** Emite (POST) a cobrança da fatura na forma pedida — a leitura (GET) nunca emite. */
        issueCharge: async (invoiceId, method, key) =>
            unwrap(await http.post(endpoints.charge(invoiceId), { method }, withKey(key))),
        payInvoice: async (invoiceId, payload, key) =>
            unwrap(await http.post(endpoints.card(invoiceId), payload, withKey(key))),
        replaceCard: async (payload, key) => unwrap(await http.put(endpoints.replaceCard(), payload, withKey(key))),
        contract: async (payload, key) => unwrap(await http.post(endpoints.contract(), payload, withKey(key))),
        /** Pacote de créditos de IA: opções (com o valor do pacote) e compra já pagando. */
        aiPackOptions: async (packageCode = null) =>
            unwrap(
                await http.get(endpoints.aiPackOptions(packageCode ? { package_code: packageCode } : {}), {
                    headers: JSON_HEADERS,
                }),
            ),
        aiPack: async (payload, key) => unwrap(await http.post(endpoints.aiPack(), payload, withKey(key))),
        /** Descarta o pedido de pacote ainda não pago (cancela a cobrança emitida). */
        aiPackDiscard: async (invoiceId) =>
            unwrap(await http.delete(endpoints.aiPackDiscard(invoiceId), { headers: JSON_HEADERS })),
    };
}

/** Códigos que valem tentar de novo (o mesmo pedido pode passar daqui a pouco). */
const RETRYABLE = ['charge_pending', 'busy', 'gateway_error', 'network', 'rate_limited'];

/** Códigos cuja mensagem do servidor é a melhor (motivo do emissor, limite de recusas, e-mail). */
const SERVER_MESSAGE = ['card_declined', 'card_attempts_exceeded', 'card_requires_verified_email'];

/**
 * Erro da API → { code, status, message, retryable }. Mensagem no idioma do
 * usuário: a recusa do cartão usa a do servidor (traz o motivo do emissor);
 * os demais códigos do contrato vêm de `t.errors`.
 */
export function checkoutError(error, t = {}) {
    const response = error?.response;
    const status = response?.status ?? 0;
    const data = response?.data ?? {};
    const errors = t.errors ?? {};

    let code = typeof data.code === 'string' ? data.code : null;
    if (!code) {
        if (!response) code = 'network';
        else if (status === 429) code = 'rate_limited';
        else if (status === 419 || status === 401) code = 'session_expired';
        else if (status === 422 && data.errors) code = 'validation';
        else code = 'unknown';
    }

    let message;
    if (SERVER_MESSAGE.includes(code) && data.message) message = data.message;
    else if (code === 'validation') message = Object.values(data.errors ?? {}).flat()[0] ?? errors.unknown;
    else message = errors[code] ?? data.message ?? errors.unknown ?? '';

    return { code, status, message, retryable: RETRYABLE.includes(code) };
}
