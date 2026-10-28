import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Autosave do prontuário — rascunho NO SERVIDOR (MedicalRecordDraftsController),
 * nada no armazenamento do navegador.
 *
 *  - A cada `intervalMs` (30 s) grava, se o conteúdo mudou desde a última
 *    gravação e é diferente do prontuário salvo.
 *  - Ao ocultar a aba (troca de aba, minimizar, bloquear a tela) grava na
 *    hora, com `keepalive` (sobrevive ao fechamento da aba); payload maior
 *    que o limite do keepalive vai por fetch comum.
 *  - Desligado quando `enabled()` é falso (prontuário assinado, quem não pode
 *    salvar). Prontuário assinado no meio do caminho (423): para de tentar.
 *  - `recover()` / `discard()`: oferta de recuperar o rascunho ao abrir.
 *
 * @param {{
 *   url: () => (string|null),
 *   enabled: () => boolean,
 *   payload: () => object,
 *   saved: () => object,
 *   context: () => object,
 *   intervalMs?: number,
 * }} options
 */
export function useRecordAutosave({ url, enabled, payload, saved, context, intervalMs = 30_000 }) {
    // idle | saving | saved | error | locked
    const status = ref('idle');
    const savedAtLabel = ref('');
    let lastSent = null;
    let inFlight = false;
    let timer = null;
    let stopped = false;

    // Limite do corpo de fetch com keepalive nos navegadores (~64 KB).
    const KEEPALIVE_LIMIT = 60 * 1024;

    function headers(extra = {}) {
        return {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            ...extra,
        };
    }

    function query() {
        const params = new URLSearchParams();
        Object.entries(context() ?? {}).forEach(([key, value]) => {
            if (value) params.set(key, String(value));
        });
        const qs = params.toString();
        return qs ? `${url()}?${qs}` : url();
    }

    function pendingJson() {
        const json = JSON.stringify(payload());
        if (json === lastSent || json === JSON.stringify(saved())) return null;
        return json;
    }

    async function flush({ keepalive = false } = {}) {
        if (stopped || inFlight || !url() || !enabled()) return false;
        const json = pendingJson();
        if (json === null) return false;

        const body = JSON.stringify({ payload: JSON.parse(json), ...(context() ?? {}) });
        inFlight = true;
        status.value = 'saving';
        try {
            const res = await fetch(url(), {
                method: 'PUT',
                keepalive: keepalive && body.length < KEEPALIVE_LIMIT,
                headers: headers({ 'Content-Type': 'application/json' }),
                body,
            });
            if (res.ok) {
                const data = await res.json().catch(() => ({}));
                lastSent = json;
                savedAtLabel.value = data.saved_at_label ?? '';
                status.value = 'saved';
                return true;
            }
            if (res.status === 423) {
                // Prontuário assinado: rascunho não vale mais nada aqui.
                stopped = true;
                status.value = 'locked';
                return false;
            }
            status.value = 'error';
            return false;
        } catch {
            status.value = 'error';
            return false;
        } finally {
            inFlight = false;
        }
    }

    function onVisibility() {
        if (document.visibilityState === 'hidden') flush({ keepalive: true });
    }

    /** Prontuário salvo: o servidor já apagou o rascunho; recomeça do zero. */
    function markSaved() {
        lastSent = null;
        savedAtLabel.value = '';
        if (status.value !== 'locked') status.value = 'idle';
    }

    /** Conteúdo do rascunho oferecido ao abrir (null se não há/sumiu). */
    async function recover() {
        if (!url()) return null;
        try {
            const res = await fetch(query(), { headers: headers() });
            if (!res.ok) return null;
            const data = await res.json();
            return data?.payload ?? null;
        } catch {
            return null;
        }
    }

    async function discard() {
        if (!url()) return false;
        try {
            const res = await fetch(query(), { method: 'DELETE', headers: headers() });
            return res.ok;
        } catch {
            return false;
        }
    }

    onMounted(() => {
        timer = setInterval(() => flush(), intervalMs);
        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        clearInterval(timer);
        timer = null;
        document.removeEventListener('visibilitychange', onVisibility);
    });

    return { status, savedAtLabel, flush, markSaved, recover, discard };
}
