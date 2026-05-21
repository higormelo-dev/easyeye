import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderStyle } from "vue/server-renderer";
const _sfc_main = {
  __name: "SubscriptionFunnel",
  __ssrInlineRender: true,
  props: {
    subscriptionKpis: { type: Object, required: true },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const STATUS_COLORS = {
      trial: "#17a2b8",
      active: "#28a745",
      expired: "#dc3545",
      cancelled: "#6c757d",
      past_due: "#ffc107"
    };
    const STATUS_ORDER = ["active", "trial", "past_due", "expired", "cancelled"];
    const STATUS_BADGE = {
      trial: "badge-soft-info rounded text-info border border-info fs-13 fw-medium",
      active: "badge-soft-success rounded text-success border border-success fs-13 fw-medium",
      expired: "badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium",
      cancelled: "badge-soft-secondary rounded fs-13 fw-medium",
      past_due: "badge-soft-warning rounded text-warning border border-warning fs-13 fw-medium"
    };
    const STATUS_LABEL = {
      trial: "Trial",
      active: "Ativo",
      expired: "Expirado",
      cancelled: "Cancelado",
      past_due: "Em atraso"
    };
    const counts = computed(() => props.subscriptionKpis.subscriptionCounts ?? {});
    const total = computed(() => props.subscriptionKpis.totalSubscriptions ?? 0);
    const maxCount = computed(
      () => Math.max(1, ...STATUS_ORDER.map((s) => counts.value[s] ?? 0))
    );
    function barWidth(status) {
      const count = counts.value[status] ?? 0;
      return maxCount.value > 0 ? Math.round(count / maxCount.value * 100) : 0;
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card mgr-chart-card h-100" }, _attrs))}><div class="card-header d-flex align-items-center justify-content-between"><span><i class="ti ti-chart-bar me-2"></i>${ssrInterpolate(__props.t.subscription_funnel)}</span><span class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13">${ssrInterpolate(__props.t.actions_total)}: ${ssrInterpolate(total.value)}</span></div><div class="card-body"><!--[-->`);
      ssrRenderList(STATUS_ORDER, (status) => {
        _push(`<div class="funnel-row"><span class="funnel-label"><span class="${ssrRenderClass(["badge", STATUS_BADGE[status]])}">${ssrInterpolate(STATUS_LABEL[status])}</span></span><div class="funnel-bar-wrapper"><div class="funnel-bar" style="${ssrRenderStyle(`width:${barWidth(status)}%;background:${STATUS_COLORS[status]};`)}"></div></div><span class="funnel-count">${ssrInterpolate(counts.value[status] ?? 0)}</span></div>`);
      });
      _push(`<!--]--></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/ManagerDashboard/SubscriptionFunnel.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
