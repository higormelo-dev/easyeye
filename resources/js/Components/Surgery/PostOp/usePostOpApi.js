/**
 * Chamadas JSON do pós-operatório (avaliação, adendo, orientações, alta, retornos).
 * Devolve { ok, status, data }; erro de validação (422) traz `data.errors`, regra de
 * negócio traz `data.message` — a tela mostra os dois sem decidir nada.
 */
export async function postOpRequest(method, url, body = null) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    try {
        const response = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body === null ? undefined : JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));

        return { ok: response.ok, status: response.status, data };
    } catch {
        return { ok: false, status: 0, data: {} };
    }
}

/** Primeira mensagem de erro legível de uma resposta. */
export function firstError(result, fallback = '') {
    const errors = result?.data?.errors;

    if (errors && typeof errors === 'object') {
        const first = Object.values(errors).flat()[0];
        if (first) return String(first);
    }

    return result?.data?.message || fallback;
}
