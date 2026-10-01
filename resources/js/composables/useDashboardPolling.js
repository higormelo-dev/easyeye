import { onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Polls Inertia props on an interval using partial reloads.
 * Pauses automatically when the browser tab is hidden and
 * resumes (with an immediate refresh) when the tab regains focus.
 *
 * @param {string[]} only       - Inertia prop keys to reload
 * @param {number}   intervalMs - Polling interval in ms (default 30s)
 */
export function useDashboardPolling(only, intervalMs = 30_000) {
    const isRefreshing = ref(false);
    const lastUpdated = ref(new Date());
    let timer = null;

    function refresh() {
        if (isRefreshing.value) return;
        isRefreshing.value = true;

        router.reload({
            only,
            preserveScroll: true,
            // "Atualizado há X" só avança quando os dados chegaram de fato;
            // em falha (rede, 500) continua mostrando a última atualização boa.
            onSuccess: () => {
                lastUpdated.value = new Date();
            },
            onFinish: () => {
                isRefreshing.value = false;
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

    return { isRefreshing, lastUpdated, refresh };
}
