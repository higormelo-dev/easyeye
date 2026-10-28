import { computed } from 'vue';

/**
 * Apoio da aba "Cirurgia": chamadas JSON (fetch) e textos.
 *
 * As ações da aba usam fetch com `Accept: application/json` (e não o roteador
 * do Inertia) para ler o motivo da recusa do servidor — 409/422 com a mensagem
 * já traduzida — sem perder o que está digitado.
 */
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/** Junta as mensagens de erro de validação (422) em uma lista de textos. */
export function errorMessages(result) {
    const list = [];
    const errors = result?.data?.errors ?? {};
    Object.values(errors).forEach((messages) => list.push(...[messages].flat()));
    if (!list.length && result?.data?.message) list.push(result.data.message);
    return list;
}

export async function callApi(url, { method = 'POST', body = null } = {}) {
    let response;
    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: body === null ? undefined : JSON.stringify(body),
        });
    } catch {
        return { ok: false, status: 0, data: {} };
    }

    const data = await response.json().catch(() => ({}));

    return { ok: response.ok, status: response.status, data };
}

/** Remove chaves vazias ('' e null) do corpo, deixando o servidor aplicar o padrão. */
export function compact(payload) {
    return Object.fromEntries(
        Object.entries(payload).filter(([, value]) => value !== '' && value !== null && value !== undefined),
    );
}

/** `tr('perform.title', { n: 1 })` → texto de t.operation.ui.perform.title (com :n trocado). */
export function useOperationText(getT) {
    const ui = computed(() => getT()?.operation?.ui ?? {});

    function tr(path, params = {}) {
        const text = path
            .split('.')
            .reduce((node, key) => (node && typeof node === 'object' ? node[key] : undefined), ui.value);
        const base = typeof text === 'string' ? text : path;
        return Object.entries(params).reduce((out, [name, value]) => out.replace(`:${name}`, String(value)), base);
    }

    return { tr, ui };
}
