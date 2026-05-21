import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderStyle, ssrRenderClass } from "vue/server-renderer";
const _sfc_main = {
  __name: "ConversionFunnel",
  __ssrInlineRender: true,
  props: {
    conversionFunnel: { type: Object, required: true },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const cf = computed(() => props.conversionFunnel);
    function rateClass(rate, thresholds) {
      if (rate >= thresholds[0]) return "conv-rate-badge--good";
      if (rate >= thresholds[1]) return "conv-rate-badge--mid";
      return "conv-rate-badge--low";
    }
    function trialRateClass(rate) {
      if (rate >= 30) return "text-success";
      if (rate >= 15) return "text-warning";
      return "text-danger";
    }
    const trialBarWidth = computed(() => {
      if (!cf.value.totalLeads) return 4;
      return Math.max(4, Math.min(100, Math.round(cf.value.totalTrials / cf.value.totalLeads * 100)));
    });
    const activeBarWidth = computed(() => {
      if (!cf.value.totalTrials) return 4;
      return Math.max(4, Math.min(100, Math.round(cf.value.totalActive / cf.value.totalTrials * 100)));
    });
    return (_ctx, _push, _parent, _attrs) => {
      var _a, _b, _c;
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card mgr-chart-card h-100" }, _attrs))}><div class="card-header d-flex align-items-center justify-content-between"><span><i class="ti ti-filter me-2"></i>${ssrInterpolate(__props.t.conversion_funnel_title)}</span><span class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13">${ssrInterpolate(__props.t.last_90d)}</span></div><div class="card-body"><div class="conv-funnel mb-4"><div class="conv-step"><div class="conv-step-header"><div class="conv-step-icon conv-step-icon--leads"><i class="ti ti-target-arrow"></i></div><div><div class="conv-step-value">${ssrInterpolate((_a = cf.value.totalLeads) == null ? void 0 : _a.toLocaleString("pt-BR"))}</div><div class="conv-step-label">${ssrInterpolate(__props.t.funnel_leads)}</div></div><div class="ms-auto text-end"><div class="conv-rate-badge conv-rate-badge--neutral">100%</div><div class="conv-rate-label">${ssrInterpolate(__props.t.funnel_base)}</div></div></div><div class="conv-bar-wrapper"><div class="conv-bar conv-bar--leads" style="${ssrRenderStyle({ "width": "100%" })}"></div></div></div><div class="conv-arrow"><i class="ti ti-arrow-down"></i><span class="conv-arrow-rate">${ssrInterpolate(cf.value.leadToTrialRate)}% ${ssrInterpolate(__props.t.funnel_converted)}</span></div><div class="conv-step"><div class="conv-step-header"><div class="conv-step-icon conv-step-icon--trials"><i class="ti ti-clock-hour-4"></i></div><div><div class="conv-step-value">${ssrInterpolate((_b = cf.value.totalTrials) == null ? void 0 : _b.toLocaleString("pt-BR"))}</div><div class="conv-step-label">${ssrInterpolate(__props.t.funnel_trials)}</div></div><div class="ms-auto text-end"><div class="${ssrRenderClass(["conv-rate-badge", rateClass(cf.value.leadToTrialRate, [30, 10])])}">${ssrInterpolate(cf.value.leadToTrialRate)}% </div><div class="conv-rate-label">${ssrInterpolate(__props.t.funnel_from_leads)}</div></div></div><div class="conv-bar-wrapper"><div class="conv-bar conv-bar--trials" style="${ssrRenderStyle(`width:${trialBarWidth.value}%;`)}"></div></div></div><div class="conv-arrow"><i class="ti ti-arrow-down"></i><span class="conv-arrow-rate">${ssrInterpolate(cf.value.trialToActiveRate)}% ${ssrInterpolate(__props.t.funnel_converted)}</span></div><div class="conv-step"><div class="conv-step-header"><div class="conv-step-icon conv-step-icon--active"><i class="ti ti-circle-check"></i></div><div><div class="conv-step-value">${ssrInterpolate((_c = cf.value.totalActive) == null ? void 0 : _c.toLocaleString("pt-BR"))}</div><div class="conv-step-label">${ssrInterpolate(__props.t.funnel_active)}</div></div><div class="ms-auto text-end"><div class="${ssrRenderClass(["conv-rate-badge", rateClass(cf.value.trialToActiveRate, [40, 20])])}">${ssrInterpolate(cf.value.trialToActiveRate)}% </div><div class="conv-rate-label">${ssrInterpolate(__props.t.funnel_from_trials)}</div></div></div><div class="conv-bar-wrapper"><div class="conv-bar conv-bar--active" style="${ssrRenderStyle(`width:${activeBarWidth.value}%;`)}"></div></div></div></div><div class="conv-90d-box"><div class="conv-90d-title"><i class="ti ti-calendar-stats me-1"></i> ${ssrInterpolate(__props.t.conv_90d_title)}</div><div class="row g-2 mt-1"><div class="col-4 text-center"><div class="conv-90d-value">${ssrInterpolate(cf.value.trialsEnded90d)}</div><div class="conv-90d-label">${ssrInterpolate(__props.t.conv_trials_ended)}</div></div><div class="col-4 text-center"><div class="conv-90d-value text-success">${ssrInterpolate(cf.value.trialsConverted90d)}</div><div class="conv-90d-label">${ssrInterpolate(__props.t.conv_converted)}</div></div><div class="col-4 text-center"><div class="${ssrRenderClass(["conv-90d-value", trialRateClass(cf.value.trialToPaid90dRate)])}">${ssrInterpolate(cf.value.trialToPaid90dRate)}% </div><div class="conv-90d-label">${ssrInterpolate(__props.t.conv_rate)}</div></div></div></div></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/ManagerDashboard/ConversionFunnel.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
