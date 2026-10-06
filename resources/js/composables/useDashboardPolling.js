import { onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Polls Inertia props on an interval using partial reloads.
 * Pauses automatically when the browser tab is hidden and
 * resumes (with an immediate refresh) when the tab regains focus.
 *
 * `manual` (opcional): props que NÃO entram no polling — só no clique em
 * "Atualizar" (`refresh({ full: true })`), que também manda `headers` (ex.: o
 * Dashboard pede para o servidor descartar o cache dos números de gestão).
 *
 * @param {string[]} only       - Inertia prop keys to reload
 * @param {number}   intervalMs - Polling interval in ms (default 30s)
 * @param {{ manual?: string[], headers?: Record<string, string> }} [options]
 */
export function useDashboardPolling(only, intervalMs = 30_000, { manual = [], headers = {} } = {}) {
    const isRefreshing = ref(false);
    const isFullRefresh = ref(false);
    const lastUpdated = ref(new Date());
    let timer = null;

    function refresh({ full = false } = {}) {
        if (isRefreshing.value) return;
        isRefreshing.value = true;
        isFullRefresh.value = full && manual.length > 0;

        router.reload({
            only: isFullRefresh.value ? [...only, ...manual] : only,
            ...(isFullRefresh.value ? { headers } : {}),
            preserveScroll: true,
            // "Atualizado há X" só avança quando os dados chegaram de fato;
            // em falha (rede, 500) continua mostrando a última atualização boa.
            onSuccess: () => {
                lastUpdated.value = new Date();
            },
            onFinish: () => {
                isRefreshing.value = false;
                isFullRefresh.value = false;
            },
        });
    }

    function startTimer() {
        if (timer !== null) return;
        timer = setInterval(refresh, intervalMs);
    }

    function stopTimer() {
        clearInterval(timer);
        timer = null;
    }

    function onVisibility() {
        if (document.hidden) {
            stopTimer();
        } else {
            refresh();
            startTimer();
        }
    }

    onMounted(() => {
        lastUpdated.value = new Date();
        startTimer();
        document.addEventListener('visibilitychange', onVisibility);
    });

    onUnmounted(() => {
        stopTimer();
        document.removeEventListener('visibilitychange', onVisibility);
    });

    return { isRefreshing, isFullRefresh, lastUpdated, refresh };
}
