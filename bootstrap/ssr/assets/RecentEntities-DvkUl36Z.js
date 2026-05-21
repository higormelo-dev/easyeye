import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderAttr, ssrRenderList, ssrRenderClass, ssrRenderStyle } from "vue/server-renderer";
const _sfc_main = {
  __name: "RecentEntities",
  __ssrInlineRender: true,
  props: {
    recentEntities: { type: Array, default: () => [] },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const SUB_BADGE = {
      trial: "badge-soft-info rounded text-info border border-info fs-13 fw-medium",
      active: "badge-soft-success rounded text-success border border-success fs-13 fw-medium",
      expired: "badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium",
      cancelled: "badge-soft-secondary rounded fs-13 fw-medium",
      past_due: "badge-soft-warning rounded text-warning border border-warning fs-13 fw-medium"
    };
    const SUB_LABEL = {
      trial: "Trial",
      active: "Ativo",
      expired: "Expirado",
      cancelled: "Cancelado",
      past_due: "Em atraso"
    };
    function scoreClass(score) {
      if (score >= 70) return "high";
      if (score >= 40) return "mid";
      return "low";
    }
    function formatDate(date) {
      return date ? new Date(date).toLocaleDateString("pt-BR") : "—";
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card mgr-chart-card h-100" }, _attrs))}><div class="card-header d-flex align-items-center justify-content-between"><span><i class="ti ti-building-plus me-2"></i>${ssrInterpolate(__props.t.recent_entities)}</span><a${ssrRenderAttr("href", _ctx.route("manager.entities.index"))} class="btn btn-sm btn-outline-primary">${ssrInterpolate(__props.t.view_all)}</a></div><div class="card-body p-0">`);
      if (__props.recentEntities.length === 0) {
        _push(`<div class="text-center text-muted py-4">${ssrInterpolate(__props.t.no_entities)}</div>`);
      } else {
        _push(`<table class="table mgr-table mb-0"><thead><tr><th>${ssrInterpolate(__props.t.col_entity)}</th><th>${ssrInterpolate(__props.t.col_date)}</th><th>${ssrInterpolate(__props.t.col_subscription)}</th><th>${ssrInterpolate(__props.t.col_activation)}</th></tr></thead><tbody><!--[-->`);
        ssrRenderList(__props.recentEntities, (entity) => {
          var _a, _b;
          _push(`<tr><td><div class="fw-semibold">${ssrInterpolate(entity.name)}</div><small class="text-muted">${ssrInterpolate(entity.code)}</small></td><td><small>${ssrInterpolate(formatDate(entity.created_at))}</small></td><td>`);
          if (entity.latest_sub) {
            _push(`<!--[--><span class="${ssrRenderClass(["badge", SUB_BADGE[((_a = entity.latest_sub.status) == null ? void 0 : _a.value) ?? entity.latest_sub.status]])}">${ssrInterpolate(SUB_LABEL[((_b = entity.latest_sub.status) == null ? void 0 : _b.value) ?? entity.latest_sub.status] ?? entity.latest_sub.status)}</span>`);
            if (entity.latest_sub.plan) {
              _push(`<small class="d-block text-muted mt-1">${ssrInterpolate(entity.latest_sub.plan.name)}</small>`);
            } else {
              _push(`<!---->`);
            }
            _push(`<!--]-->`);
          } else {
            _push(`<span class="text-muted">—</span>`);
          }
          _push(`</td><td><div class="d-flex align-items-center gap-2"><div class="score-bar"><div class="${ssrRenderClass(["score-bar-fill", `score-${scoreClass(entity.activation_score)}`])}" style="${ssrRenderStyle(`width:${entity.activation_score}%;`)}"></div></div><span class="${ssrRenderClass(["fw-semibold", `text-score-${scoreClass(entity.activation_score)}`])}" style="${ssrRenderStyle({ "font-size": ".8rem" })}">${ssrInterpolate(entity.activation_score)}% </span></div></td></tr>`);
        });
        _push(`<!--]--></tbody></table>`);
      }
      _push(`</div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/ManagerDashboard/RecentEntities.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
