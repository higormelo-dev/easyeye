import { ref, onMounted, onUnmounted } from "vue";
import { router } from "@inertiajs/vue3";
function useDashboardPolling(only, intervalMs = 3e4) {
  const isRefreshing = ref(false);
  const lastUpdated = ref(/* @__PURE__ */ new Date());
  let timer = null;
  function refresh() {
    if (isRefreshing.value) return;
    isRefreshing.value = true;
    router.reload({
      only,
      preserveScroll: true,
      onFinish: () => {
        isRefreshing.value = false;
        lastUpdated.value = /* @__PURE__ */ new Date();
      }
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
    lastUpdated.value = /* @__PURE__ */ new Date();
    startTimer();
    document.addEventListener("visibilitychange", onVisibility);
  });
  onUnmounted(() => {
    stopTimer();
    document.removeEventListener("visibilitychange", onVisibility);
  });
  return { isRefreshing, lastUpdated, refresh };
}
export {
  useDashboardPolling as u
};
