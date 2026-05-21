import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderStyle } from "vue/server-renderer";
const _sfc_main = {
  __name: "TrialsExpiring",
  __ssrInlineRender: true,
  props: {
    trialsExpiring: { type: Array, default: () => [] },
    t: { type: Object, required: true }
  },
  setup(__props) {
    function daysLeft(trialEndsAt) {
      const diff = Math.floor((new Date(trialEndsAt) - /* @__PURE__ */ new Date()) / 864e5);
      return diff;
    }
    function scoreClass(score) {
      if (score >= 70) return "high";
      if (score >= 40) return "mid";
      return "low";
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card mgr-chart-card h-100" }, _attrs))}><div class="card-header d-flex align-items-center justify-content-between"><span><i class="ti ti-alert-triangle me-2 text-warning"></i>${ssrInterpolate(__props.t.trials_expiring)}</span><span class="badge bg-warning text-dark">${ssrInterpolate(__props.trialsExpiring.length)}</span></div><div class="card-body p-0">`);
      if (__props.trialsExpiring.length === 0) {
        _push(`<div class="text-center text-muted py-4"><i class="ti ti-mood-happy fs-1 d-block mb-2"></i> ${ssrInterpolate(__props.t.no_trials_expiring)}</div>`);
      } else {
        _push(`<table class="table mgr-table mb-0"><thead><tr><th>${ssrInterpolate(__props.t.col_entity)}</th><th>${ssrInterpolate(__props.t.col_plan)}</th><th>${ssrInterpolate(__props.t.col_expires)}</th><th>${ssrInterpolate(__props.t.col_activation)}</th></tr></thead><tbody><!--[-->`);
        ssrRenderList(__props.trialsExpiring, (trial) => {
          var _a, _b, _c;
          _push(`<tr><td><div class="fw-semibold">${ssrInterpolate(((_a = trial.entity) == null ? void 0 : _a.name) ?? "—")}</div><small class="text-muted">${ssrInterpolate((_b = trial.entity) == null ? void 0 : _b.code)}</small></td><td>${ssrInterpolate(((_c = trial.plan) == null ? void 0 : _c.name) ?? "—")}</td><td><span class="${ssrRenderClass(["badge", daysLeft(trial.trial_ends_at) <= 2 ? "bg-danger" : "bg-warning text-dark"])}">${ssrInterpolate(daysLeft(trial.trial_ends_at))}d </span></td><td><div class="d-flex align-items-center gap-2"><div class="score-bar"><div class="${ssrRenderClass(["score-bar-fill", `score-${scoreClass(trial.activation_score)}`])}" style="${ssrRenderStyle(`width:${trial.activation_score}%;`)}"></div></div><span class="${ssrRenderClass(["fw-semibold", `text-score-${scoreClass(trial.activation_score)}`])}" style="${ssrRenderStyle({ "font-size": ".8rem" })}">${ssrInterpolate(trial.activation_score)}% </span></div></td></tr>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/ManagerDashboard/TrialsExpiring.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
