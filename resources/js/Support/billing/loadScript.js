/**
 * Carrega o SDK JS oficial do gateway SÓ na tela de checkout (nunca no
 * bundle). Aceita apenas os hosts oficiais (o `sdk_url` vem do servidor, mas
 * um script de origem desconhecida não entra na página de pagamento) e só
 * HTTPS. Cada script é inserido uma vez; uma falha libera nova tentativa.
 */
export const ALLOWED_SDK_HOSTS = [
    'sdk.mercadopago.com', // Mercado Pago — MercadoPago.js v2 (Card Payment Brick)
    'checkout.pagar.me', // Pagar.me — tokenizecard.js
    'js.stripe.com', // Stripe — Stripe.js (Payment Element)
    'assets.pagseguro.com.br', // PagBank — SDK de criptografia do cartão
];

const loaded = new Map();

export function isAllowedSdkUrl(url) {
    try {
        const parsed = new URL(String(url));

        return parsed.protocol === 'https:' && ALLOWED_SDK_HOSTS.includes(parsed.hostname);
    } catch {
        return false;
    }
}

/**
 * @param {string} src URL do SDK (host oficial, https)
 * @param {Record<string, string>} [attributes] atributos extras da tag (ex.: data-pagarmecheckout-app-id)
 * @returns {Promise<void>}
 */
export function loadScript(src, attributes = {}) {
    if (!isAllowedSdkUrl(src)) return Promise.reject(new Error('sdk_not_allowed'));

    const key = `${src}|${JSON.stringify(attributes)}`;
    if (loaded.has(key)) return loaded.get(key);

    const promise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        Object.entries(attributes).forEach(([name, value]) => script.setAttribute(name, value));
        script.onload = () => resolve();
        script.onerror = () => {
            loaded.delete(key);
            script.remove();
            reject(new Error('sdk_load_failed'));
        };
        document.head.appendChild(script);
    });

    loaded.set(key, promise);

    return promise;
}

/** Globais que os SDKs de cartão deixam na janela. */
const SDK_GLOBALS = ['MercadoPago', 'Stripe', 'PagarmeCheckout', 'PagSeguro'];

/**
 * Descarrega os SDKs de cartão ao sair do checkout: tira as tags <script>
 * dos hosts dos gateways, os iframes que eles deixam no <body> (Stripe.js)
 * e as globais — fora da tela de pagamento não sobra código de terceiro
 * rodando na página. Voltar ao checkout carrega de novo.
 */
export function unloadCheckoutSdks() {
    if (typeof document === 'undefined') return;

    document.querySelectorAll('script[src]').forEach((script) => {
        if (isAllowedSdkUrl(script.src)) script.remove();
    });

    document.querySelectorAll('body > iframe[src], body > div > iframe[name^="__privateStripe"]').forEach((frame) => {
        if (isAllowedSdkUrl(frame.src) || String(frame.name ?? '').startsWith('__privateStripe')) frame.remove();
    });

    if (typeof window !== 'undefined') {
        SDK_GLOBALS.forEach((name) => {
            try {
                delete window[name];
            } catch {
                window[name] = undefined;
            }
        });
    }

    loaded.clear();
}

/** Só para testes: esquece os scripts já carregados. */
export function resetLoadedScripts() {
    loaded.clear();
}
