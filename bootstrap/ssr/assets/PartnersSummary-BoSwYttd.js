import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderAttr, ssrRenderStyle, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
const _sfc_main = {
  __name: "PartnersSummary",
  __ssrInlineRender: true,
  props: {
    partnersSummary: { type: Object, required: true },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const LEAD_STATUS_BADGE = {
      new: "badge-soft-info rounded text-info border border-info fs-13 fw-medium",
      contacted: "badge-soft-primary rounded text-primary border border-primary fs-13 fw-medium",
      trial: "badge-soft-warning rounded text-warning border border-warning fs-13 fw-medium",
      converted: "badge-soft-success rounded text-success border border-success fs-13 fw-medium",
      lost: "badge-soft-secondary rounded fs-13 fw-medium"
    };
    const LEAD_STATUS_LABEL = {
      new: "Novo",
      contacted: "Contatado",
      trial: "Trial",
      converted: "Convertido",
      lost: "Perdido"
    };
    const LEAD_STATUS_ORDER = ["new", "contacted", "trial", "converted", "lost"];
    function brl(value) {
      return Number(value).toLocaleString("pt-BR", { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card mgr-chart-card h-100" }, _attrs))}><div class="card-header d-flex align-items-center justify-content-between"><span><i class="ti ti-users-group me-2"></i>${ssrInterpolate(__props.t.partners_summary)}</span><a${ssrRenderAttr("href", _ctx.route("manager.partners.index"))} class="btn btn-sm btn-outline-primary">${ssrInterpolate(__props.t.view_all)}</a></div><div class="card-body"><div class="row g-2 mb-3"><div class="col-4"><div class="mgr-mini-kpi"><div class="mini-icon mini-icon--partners"><i class="ti ti-users"></i></div><div><div class="mini-value">${ssrInterpolate(__props.partnersSummary.totalPartners)}</div><div class="mini-label">${ssrInterpolate(__props.t.partners)}</div></div></div></div><div class="col-4"><div class="mgr-mini-kpi"><div class="mini-icon mini-icon--leads"><i class="ti ti-target-arrow"></i></div><div><div class="mini-value">${ssrInterpolate(__props.partnersSummary.leadsActive)}</div><div class="mini-label">${ssrInterpolate(__props.t.leads_active)}</div></div></div></div><div class="col-4"><div class="mgr-mini-kpi"><div class="mini-icon mini-icon--commissions"><i class="ti ti-cash"></i></div><div><div class="mini-value" style="${ssrRenderStyle({ "font-size": "1rem" })}"> R$ ${ssrInterpolate(brl(__props.partnersSummary.pendingCommissions))}</div><div class="mini-label">${ssrInterpolate(__props.t.commissions_pending)}</div></div></div></div></div><h6 class="text-muted fw-semibold mb-2" style="${ssrRenderStyle({ "font-size": ".8125rem", "text-transform": "uppercase", "letter-spacing": ".04em" })}">${ssrInterpolate(__props.t.leads_funnel)}</h6><!--[-->`);
      ssrRenderList(LEAD_STATUS_ORDER, (status) => {
        var _a;
        _push(`<div class="d-flex align-items-center justify-content-between py-1"><span class="${ssrRenderClass(["badge", LEAD_STATUS_BADGE[status]])}">${ssrInterpolate(LEAD_STATUS_LABEL[status])}</span><span class="fw-bold" style="${ssrRenderStyle({ "font-size": ".875rem" })}">${ssrInterpolate(((_a = __props.partnersSummary.leadsByStatus) == null ? void 0 : _a[status]) ?? 0)}</span></div>`);
      });
      _push(`<!--]--><hr class="my-3"><div class="d-flex align-items-center justify-content-between"><div><i class="ti ti-share me-1 text-primary"></i><span class="fw-semibold" style="${ssrRenderStyle({ "font-size": ".875rem" })}">${ssrInterpolate(__props.t.referral_codes)}</span></div><span class="fw-bold">${ssrInterpolate(__props.partnersSummary.activeReferralCodes)}</span></div><div class="d-flex align-items-center justify-content-between mt-1"><div><i class="ti ti-arrow-right me-1 text-success"></i><span class="fw-semibold" style="${ssrRenderStyle({ "font-size": ".875rem" })}">${ssrInterpolate(__props.t.referral_events)}</span></div><span class="fw-bold">${ssrInterpolate(__props.partnersSummary.totalReferralEvents)}</span></div></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/ManagerDashboard/PartnersSummary.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
