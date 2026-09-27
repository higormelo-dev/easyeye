/**
 * Utilitários da tela de Faturamento (Pages/Panel/Financial/Billing/*).
 */

/** Primeira mensagem de um objeto de erros do Inertia ({ campo: 'msg' | ['msg'] }). */
export function firstError(errors) {
    for (const value of Object.values(errors ?? {})) {
        const message = Array.isArray(value) ? value[0] : value;
        if (message) return String(message);
    }

    return '';
}

/**
 * Erros que não pertencem a nenhum campo do formulário (ex.: `status` de uma
 * transição recusada, `batch`, `schedule_ids`) — vão num alerta no topo do modal.
 */
export function generalErrors(errors, fieldKeys = []) {
    return Object.entries(errors ?? {})
        .filter(([key, message]) => message && !fieldKeys.includes(key))
        .map(([, message]) => (Array.isArray(message) ? message[0] : message));
}

/**
 * Erros de validação (422) de uma chamada axios: { chave: 'primeira mensagem' }
 * — ex.: `paid_at`, `items.0.paid_amount`, `claims.<id>`, `batch`. null se
 * não foi 422 (rede, 403, 500...).
 */
export function validationErrors(error) {
    if (error?.response?.status !== 422) return null;

    return Object.fromEntries(
        Object.entries(error.response.data?.errors ?? {})
            .map(([key, value]) => [key, String((Array.isArray(value) ? value[0] : value) ?? '')])
            .filter(([, message]) => message !== ''),
    );
}

/** A ação vem do servidor (allowed_actions) — a tela não decide transição sozinha. */
export function hasAction(row, action) {
    return Array.isArray(row?.allowed_actions) && row.allowed_actions.includes(action);
}

/** Linhas de uma aba: paginator do Laravel ({ data, links, total... }); tolera array simples. */
export function pageRows(page) {
    if (Array.isArray(page)) return page;

    return Array.isArray(page?.data) ? page.data : [];
}

/** Paginator com links (TablePagination só entra quando o servidor mandou um). */
export function isPaginator(page) {
    return Boolean(page) && !Array.isArray(page) && Array.isArray(page.links);
}

/** Parâmetros de URL sem vazios (a querystring só leva filtro aplicado). */
export function cleanParams(params) {
    return Object.fromEntries(
        Object.entries(params ?? {}).filter(([, value]) => value !== undefined && value !== null && value !== ''),
    );
}

/** Acrescenta parâmetros a uma URL (absoluta ou relativa), preservando os existentes. */
export function withQuery(url, params) {
    const query = new URLSearchParams(cleanParams(params)).toString();
    if (!url || !query) return url;

    return `${url}${url.includes('?') ? '&' : '?'}${query}`;
}

/**
 * Preço da tabela (procedimento × convênio) de um conjunto de atendimentos:
 * null se nenhum tem preço; `single` quando todos os com preço concordam.
 */
export function tablePriceInfo(schedules) {
    const prices = (schedules ?? [])
        .map((s) => s?.suggested_price)
        .filter((price) => price !== null && price !== undefined && price !== '')
        .map(Number)
        .filter((price) => Number.isFinite(price));

    if (prices.length === 0) return null;

    const min = Math.min(...prices);
    const max = Math.max(...prices);

    return { min, max, single: min === max ? min : null, priced: prices.length };
}

/** Símbolo da moeda no idioma do usuário (ex.: 'R$'), para o prefixo dos campos de valor. */
export function currencySymbol(locale, currency = 'BRL') {
    try {
        return new Intl.NumberFormat(locale, { style: 'currency', currency })
            .formatToParts(0)
            .find((part) => part.type === 'currency')?.value ?? currency;
    } catch {
        return currency;
    }
}
