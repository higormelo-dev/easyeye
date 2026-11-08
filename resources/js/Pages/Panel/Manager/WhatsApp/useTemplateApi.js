import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// A Gupshup aceita 10 criações por minuto.
export const PAUSE_MS = 7000;

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Estado dos modelos na Gupshup e criação por API (Manager → WhatsApp → Modelos). `props` é o objeto
 * reativo do componente (templates, api, active, routes, t); `language` e `languageOff` vêm da prévia.
 * "Criar os que faltam" manda um modelo por chamada, com intervalo, e para no primeiro erro.
 */
export function useTemplateApi(props, language, languageOff) {
    const apiText = computed(() => props.t.templates_api ?? {});
    const statuses = ref(null); // { 'nome|idioma': { status, reason } }; null = ainda não lido
    const loading = ref(false);
    const sending = ref(''); // "chave|idioma" em envio
    const progress = ref(null); // { current, total } em "Criar os que faltam"
    const notice = ref(null); // { type: 'success' | 'danger', text }
    const busy = computed(() => loading.value || sending.value !== '' || progress.value !== null);
    let leaving = false;

    onBeforeUnmount(() => (leaving = true));

    function statusOf(tpl, lang = language.value) {
        return statuses.value ? (statuses.value[`${tpl.name}|${lang}`] ?? { status: 'MISSING', reason: '' }) : null;
    }

    const STATUS_CLASSES = {
        APPROVED: 'badge-soft-success',
        PENDING: 'badge-soft-warning',
        MISSING: 'badge-soft-secondary',
    };

    function statusClass(status) {
        return STATUS_CLASSES[status] ?? 'badge-soft-danger';
    }

    function statusLabel(status) {
        return apiText.value.status?.[status] ?? status;
    }

    const missing = computed(() => props.templates.filter((tpl) => statusOf(tpl)?.status === 'MISSING'));
    const canCreate = computed(() => props.api && !languageOff.value && !busy.value);

    async function loadStatus() {
        if (loading.value) return;
        loading.value = true;
        try {
            const { data } = await window.axios.get(props.routes.templates_status);
            statuses.value = data.templates ?? {};
        } catch (e) {
            notice.value = { type: 'danger', text: e.response?.data?.error ?? apiText.value.load_failed };
        } finally {
            loading.value = false;
        }
    }

    // Abriu a aba pela primeira vez (ou a página já abriu nela): lê o estado uma vez. onMounted e não
    // `immediate`: no SSR não há window nem axios.
    function readOnce() {
        if (props.active && props.api && statuses.value === null) loadStatus();
    }

    onMounted(readOnce);
    watch(() => props.active, readOnce);

    /** @returns {Promise<boolean>} true se o modelo foi criado */
    async function createOne(tpl, lang) {
        sending.value = `${tpl.key}|${lang}`;
        try {
            const { data } = await window.axios.post(
                props.routes.templates_create,
                { key: tpl.key, language: lang },
                { headers: { 'X-CSRF-TOKEN': csrf() } },
            );
            statuses.value = {
                ...statuses.value,
                [`${tpl.name}|${lang}`]: { status: data.status ?? 'PENDING', reason: '' },
            };
            notice.value = { type: 'success', text: data.message };
            return true;
        } catch (e) {
            notice.value = { type: 'danger', text: e.response?.data?.error ?? apiText.value.create_failed };
            return false;
        } finally {
            sending.value = '';
        }
    }

    async function createSingle(tpl) {
        const text = apiText.value.confirm_one.replace(':name', tpl.name).replace(':language', language.value);
        if (!window.confirm(text)) return;
        await createOne(tpl, language.value);
    }

    async function createMissing() {
        const queue = [...missing.value];
        const lang = language.value;
        if (!queue.length || !window.confirm(apiText.value.confirm_all.replace(':count', queue.length))) return;

        let sent = 0;
        let stopped = false;

        for (const [index, tpl] of queue.entries()) {
            progress.value = { current: index + 1, total: queue.length };
            if (index > 0) await new Promise((resolve) => setTimeout(resolve, PAUSE_MS));
            if (leaving) return;
            if (!(await createOne(tpl, lang))) {
                stopped = true;
                break;
            }
            sent += 1;
        }

        progress.value = null;
        notice.value = stopped
            ? { type: 'danger', text: `${notice.value.text} ${apiText.value.stopped}` }
            : { type: 'success', text: apiText.value.done.replace(':count', sent) };
        await loadStatus();
    }

    return {
        apiText,
        loading,
        sending,
        progress,
        notice,
        busy,
        missing,
        canCreate,
        statusOf,
        statusClass,
        statusLabel,
        loadStatus,
        createSingle,
        createMissing,
    };
}
