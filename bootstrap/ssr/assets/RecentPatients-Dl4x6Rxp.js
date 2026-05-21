import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderAttr, ssrRenderStyle, ssrRenderList } from "vue/server-renderer";
const _sfc_main = {
  __name: "RecentPatients",
  __ssrInlineRender: true,
  props: {
    patients: { type: Array, default: () => [] },
    t: { type: Object, required: true }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card mb-4" }, _attrs))}><div class="card-header d-flex align-items-center justify-content-between"><span><i class="ti ti-users me-2 text-primary"></i> ${ssrInterpolate(__props.t.section_recent_patients)}</span><a${ssrRenderAttr("href", _ctx.route("panel.patients.index"))} class="btn btn-sm btn-outline-primary">${ssrInterpolate(__props.t.btn_see_all)} <i class="ti ti-arrow-right ms-1"></i></a></div><div class="card-body p-0">`);
      if (__props.patients.length === 0) {
        _push(`<div class="text-center text-muted py-4"><i class="ti ti-users" style="${ssrRenderStyle({ "font-size": "2rem", "display": "block", "margin-bottom": ".5rem" })}"></i> ${ssrInterpolate(__props.t.empty_patients)}</div>`);
      } else {
        _push(`<table class="schedule-table table table-hover mb-0"><thead><tr><th>${ssrInterpolate(__props.t.col_name)}</th><th class="d-none d-md-table-cell">${ssrInterpolate(__props.t.col_phone)}</th><th class="d-none d-sm-table-cell">${ssrInterpolate(__props.t.col_code)}</th><th></th></tr></thead><tbody><!--[-->`);
        ssrRenderList(__props.patients, (p) => {
          _push(`<tr><td><div class="d-flex align-items-center gap-2"><div class="patient-initial" style="${ssrRenderStyle({ background: p.color })}">${ssrInterpolate(p.initial)}</div><span class="fw-medium" style="${ssrRenderStyle({ "font-size": ".875rem" })}">${ssrInterpolate(p.name)}</span></div></td><td class="text-muted small d-none d-md-table-cell">${ssrInterpolate(p.phone)}</td><td class="d-none d-sm-table-cell"><code class="text-muted small">${ssrInterpolate(p.code)}</code></td><td class="text-end"><a${ssrRenderAttr("href", p.url)} class="btn btn-xs btn-outline-secondary">${ssrInterpolate(__props.t.btn_view)}</a></td></tr>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Dashboard/RecentPatients.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
