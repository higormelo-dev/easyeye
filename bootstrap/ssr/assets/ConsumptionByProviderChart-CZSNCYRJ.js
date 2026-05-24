import { ref, computed, onMounted, onUnmounted, watch, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderStyle } from "vue/server-renderer";
import { Chart, DoughnutController, ArcElement, Legend, Tooltip } from "chart.js";
const _sfc_main = {
  __name: "ConsumptionByProviderChart",
  __ssrInlineRender: true,
  props: {
    consumption: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    Chart.register(DoughnutController, ArcElement, Legend, Tooltip);
    const props = __props;
    const PROVIDER_COLORS = {
      openai: "#10a37f",
      anthropic: "#cc785c",
      gemini: "#4285f4"
    };
    const canvas = ref(null);
    let chartInst = null;
    const hasData = computed(
      () => Object.values(props.consumption || {}).some((p) => ((p == null ? void 0 : p.credits) ?? 0) > 0)
    );
    function isDark() {
      return document.documentElement.getAttribute("data-bs-theme") === "dark";
    }
    function buildChart() {
      if (!canvas.value || !hasData.value) return;
      if (chartInst) {
        chartInst.destroy();
        chartInst = null;
      }
      const labels = [];
      const data = [];
      const colors = [];
      for (const [providerValue, info] of Object.entries(props.consumption || {})) {
        if (!info || (info.credits ?? 0) <= 0) continue;
        labels.push(info.label || providerValue);
        data.push(info.credits);
        colors.push(PROVIDER_COLORS[providerValue] ?? "#94a3b8");
      }
      chartInst = new Chart(canvas.value, {
        type: "doughnut",
        data: {
          labels,
          datasets: [{
            data,
            backgroundColor: colors,
            borderColor: isDark() ? "#0f1729" : "#ffffff",
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: "60%",
          plugins: {
            legend: {
              position: "bottom",
              labels: {
                color: isDark() ? "#9fb0cc" : "#64748b",
                font: { size: 11 },
                boxWidth: 10,
                padding: 10
              }
            },
            tooltip: {
              backgroundColor: isDark() ? "#111b30" : "#ffffff",
              titleColor: isDark() ? "#e4ebfa" : "#1e293b",
              bodyColor: isDark() ? "#9fb0cc" : "#64748b",
              borderColor: isDark() ? "#22334c" : "#e2e8f0",
              borderWidth: 1,
              callbacks: {
                label: (ctx) => ` ${ctx.label}: ${ctx.parsed.toLocaleString("pt-BR")} créditos`
              }
            }
          }
        }
      });
    }
    let themeObserver = null;
    onMounted(() => {
      buildChart();
      themeObserver = new MutationObserver(() => buildChart());
      themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ["data-bs-theme"] });
    });
    onUnmounted(() => {
      chartInst == null ? void 0 : chartInst.destroy();
      themeObserver == null ? void 0 : themeObserver.disconnect();
    });
    watch(() => props.consumption, buildChart, { deep: true });
    return (_ctx, _push, _parent, _attrs) => {
      var _a, _b, _c, _d;
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card h-100" }, _attrs))}><div class="card-header py-2 d-flex align-items-center"><i class="ti ti-chart-pie text-info me-2"></i><strong class="small">${ssrInterpolate(((_b = (_a = __props.t) == null ? void 0 : _a.kpi) == null ? void 0 : _b.consumption_by_provider) ?? "Consumo por provedor (30d)")}</strong></div><div class="card-body p-3">`);
      if (!hasData.value) {
        _push(`<div class="text-muted text-center small py-4"><i class="ti ti-mood-empty fs-3 d-block mb-2"></i> ${ssrInterpolate(((_d = (_c = __props.t) == null ? void 0 : _c.kpi) == null ? void 0 : _d.no_consumption) ?? "Sem consumo nos últimos 30 dias")}</div>`);
      } else {
        _push(`<div style="${ssrRenderStyle({ "height": "220px" })}"><canvas></canvas></div>`);
      }
      _push(`</div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/AiCreditPurchases/ConsumptionByProviderChart.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
