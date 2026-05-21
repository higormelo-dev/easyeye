import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderClass } from "vue/server-renderer";
const _sfc_main = {
  __name: "FinancialKpis",
  __ssrInlineRender: true,
  props: {
    financialKpis: { type: Object, required: true },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const fk = computed(() => props.financialKpis);
    const churnVariant = computed(
      () => fk.value.churnRate > 5 ? "churn-high" : "churn"
    );
    const riskVariant = computed(
      () => fk.value.revenueAtRisk > 0 ? "risk-high" : "risk"
    );
    function brl(value) {
      return Number(value).toLocaleString("pt-BR", { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "row g-3 mb-3" }, _attrs))}><div class="col-6 col-md-3"><div class="card stat-card stat-card--arr h-100"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="stat-icon stat-icon--arr"><i class="ti ti-chart-line"></i></div><div><div class="stat-value">R$ ${ssrInterpolate(brl(fk.value.arr))}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_arr)}</div><div class="d-flex gap-1 mt-1"><span class="period-badge"> MRR <span class="period-value">R$ ${ssrInterpolate(brl(fk.value.mrr))}</span></span></div></div></div></div></div><div class="col-6 col-md-3"><div class="card stat-card stat-card--arpu h-100"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="stat-icon stat-icon--arpu"><i class="ti ti-user-dollar"></i></div><div><div class="stat-value">R$ ${ssrInterpolate(brl(fk.value.arpu))}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_arpu)}</div><div class="d-flex gap-1 mt-1"><span class="period-badge"><span class="period-value">${ssrInterpolate(fk.value.activeCount)}</span> ${ssrInterpolate(__props.t.active_subs)}</span></div></div></div></div></div><div class="col-6 col-md-3"><div class="${ssrRenderClass(["card stat-card h-100", `stat-card--${churnVariant.value}`])}"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="${ssrRenderClass(["stat-icon", `stat-icon--${churnVariant.value}`])}"><i class="ti ti-trending-down"></i></div><div><div class="stat-value">${ssrInterpolate(Number(fk.value.churnRate).toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 1 }))}% </div><div class="stat-label">${ssrInterpolate(__props.t.kpi_churn_rate)}</div><div class="d-flex gap-1 mt-1"><span class="period-badge"><span class="period-value">${ssrInterpolate(fk.value.cancelledThisMonth)}</span> ${ssrInterpolate(__props.t.cancelled_this_month)}</span></div></div></div></div></div><div class="col-6 col-md-3"><div class="${ssrRenderClass(["card stat-card h-100", `stat-card--${riskVariant.value}`])}"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="${ssrRenderClass(["stat-icon", `stat-icon--${riskVariant.value}`])}"><i class="ti ti-alert-circle"></i></div><div><div class="stat-value">R$ ${ssrInterpolate(brl(fk.value.revenueAtRisk))}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_revenue_at_risk)}</div>`);
      if (fk.value.revenueAtRisk > 0) {
        _push(`<span class="badge badge-soft-danger rounded text-danger border border-danger fs-11 fw-medium mt-1"><i class="ti ti-alert-triangle me-1"></i>${ssrInterpolate(__props.t.past_due ?? "Em atraso")}</span>`);
      } else {
        _push(`<span class="badge badge-soft-success rounded text-success border border-success fs-11 fw-medium mt-1"><i class="ti ti-circle-check me-1"></i>${ssrInterpolate(__props.t.all_clear)}</span>`);
      }
      _push(`</div></div></div></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/ManagerDashboard/FinancialKpis.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
