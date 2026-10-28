/**
 * Visual de cada provedor de IA (rótulo, ícone, cor, painel de cobrança) nos
 * cards do Manager → Créditos IA. Provedor sem visual próprio cai no genérico
 * — nunca some da tela (lista pronta: App\Enums\AI\AiProvider).
 */
const KNOWN = {
    openai: {
        label: 'ChatGPT',
        icon: 'ti ti-brand-openai',
        color: '#10a37f',
        billingUrl: 'https://platform.openai.com/settings/organization/limits',
    },
    anthropic: {
        label: 'Claude',
        icon: 'ti ti-message-chatbot',
        color: '#cc785c',
        billingUrl: 'https://console.anthropic.com/settings/limits',
    },
    gemini: {
        label: 'Gemini',
        icon: 'ti ti-brand-google',
        color: '#4285f4',
        billingUrl: 'https://console.cloud.google.com/billing/budgets',
    },
    mistral: {
        label: 'Mistral',
        icon: 'ti ti-wind',
        color: '#fa520f',
        billingUrl: 'https://console.mistral.ai/billing',
    },
    groq: {
        label: 'Groq',
        icon: 'ti ti-bolt',
        color: '#f55036',
        billingUrl: 'https://console.groq.com/settings/billing',
    },
    xai: { label: 'Grok', icon: 'ti ti-letter-x', color: '#5f6368', billingUrl: 'https://console.x.ai' },
    azure_openai: {
        label: 'Azure OpenAI',
        icon: 'ti ti-brand-azure',
        color: '#0078d4',
        billingUrl: 'https://portal.azure.com/#view/Microsoft_Azure_CostManagement/Menu/~/overview',
    },
    maritaca: {
        label: 'Sabiá',
        icon: 'ti ti-feather',
        color: '#2e7d32',
        billingUrl: 'https://plataforma.maritaca.ai',
    },
};

/** Sempre aparecem (mesmo zerados); os demais só com movimento. */
export const BASE_PROVIDERS = ['openai', 'anthropic', 'gemini'];

/** Fundo suave (10%) a partir da cor #rrggbb. */
function softBg(hex) {
    const n = parseInt(hex.slice(1), 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},0.10)`;
}

export function providerMeta(code, label = null) {
    const meta = KNOWN[code] ?? { label: code, icon: 'ti ti-sparkles', color: '#6c757d', billingUrl: null };

    return { ...meta, label: label ?? meta.label, bg: softBg(meta.color) };
}

/**
 * Ordem dos cards: os três de sempre + quem tiver movimento (na ordem em que
 * o servidor mandou).
 *
 * @param {string[]} codes códigos presentes nos dados
 * @param {(code: string) => boolean} hasActivity
 */
export function providerOrder(codes, hasActivity = () => false) {
    const extra = codes.filter((c) => !BASE_PROVIDERS.includes(c) && hasActivity(c));

    return [...BASE_PROVIDERS, ...extra];
}
