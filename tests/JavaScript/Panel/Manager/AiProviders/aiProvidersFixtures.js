import { vi } from 'vitest';

/** Fixtures compartilhadas dos testes de Manager → Provedores de IA. */
export const provider = (over = {}) => ({
    code: 'openai',
    label: 'OpenAI',
    enabled: false,
    order: null,
    role: null,
    configured: false,
    model: 'gpt-5-mini',
    price_ok: false,
    compatible: false,
    has_key: false,
    key_hint: null,
    key_env: 'OPENAI_API_KEY',
    model_env: 'AI_OPENAI_MODEL',
    model_source: 'env',
    base_url: 'https://api.openai.com/v1',
    base_url_env: 'AI_OPENAI_BASE_URL',
    base_url_secure: true,
    keys_url: 'https://platform.openai.com/api-keys',
    model_unlisted_at: null,
    ...over,
});

export const ready = (over = {}) =>
    provider({ configured: true, price_ok: true, has_key: true, key_hint: '••••abcd', ...over });

export const priceRow = (over = {}) => ({
    id: 'p1',
    provider: 'openai',
    provider_label: 'OpenAI',
    model: 'gpt-4o',
    input_usd_per_million: 2.5,
    output_usd_per_million: 10,
    reasoning_usd_per_million: null,
    tool_call_usd: null,
    active: true,
    source: 'seed',
    price_locked: false,
    effective_from: '01/01/2026',
    synced_at: null,
    unlisted_at: null,
    in_use: false,
    ...over,
});

export const paginator = (data, over = {}) => ({
    data,
    current_page: 1,
    last_page: 1,
    from: data.length ? 1 : null,
    to: data.length,
    total: data.length,
    links: [],
    prev_page_url: null,
    next_page_url: null,
    ...over,
});

/** fetch falso: devolve `json` com status ok/422. */
export function mockFetch(json = {}, ok = true) {
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok, status: ok ? 200 : 422, json: () => Promise.resolve(json) }));
    return globalThis.fetch;
}

/** Intl separa moeda e valor com espaço não separável. */
export const plain = (wrapper) => wrapper.text().replace(/ /g, ' ');

/** Proteção de dados (LGPD) de um card — padrão: EUA, exige registro, sem registro, fora de papel. */
export const lgpd = (over = {}) => ({
    location: 'us',
    transfer: 'contract',
    patients: true,
    blocked_reason: null,
    needs_record: true,
    can_record: true,
    record: null,
    in_role: false,
    pending: false,
    blocked_in_role: false,
    sources: ['https://developers.openai.com/api/docs/guides/your-data'],
    region_env: null,
    data_region: null,
    ...over,
});

export const blockedLgpd = (reason, over = {}) =>
    lgpd({
        location: 'any',
        patients: false,
        blocked_reason: reason,
        needs_record: false,
        can_record: false,
        ...over,
    });
