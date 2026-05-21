import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderList } from "vue/server-renderer";
const _sfc_main = {
  __name: "TopEntities",
  __ssrInlineRender: true,
  props: {
    topEntities: { type: Array, default: () => [] },
    t: { type: Object, required: true }
  },
  setup(__props) {
    function formatDate(date) {
      return date ? new Date(date).toLocaleDateString("pt-BR") : "—";
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card mgr-chart-card" }, _attrs))}><div class="card-header"><i class="ti ti-trophy me-2"></i>${ssrInterpolate(__props.t.top_entities)}</div><div class="card-body p-0"><table class="table mgr-table mb-0"><thead><tr><th>#</th><th>${ssrInterpolate(__props.t.col_entity)}</th><th>${ssrInterpolate(__props.t.col_patients)}</th><th>${ssrInterpolate(__props.t.col_date)}</th></tr></thead><tbody>`);
      if (__props.topEntities.length === 0) {
        _push(`<tr><td colspan="4" class="text-center text-muted py-3">${ssrInterpolate(__props.t.no_entities)}</td></tr>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--[-->`);
      ssrRenderList(__props.topEntities, (entity, i) => {
        _push(`<tr><td>`);
        if (i === 0) {
          _push(`<span class="text-warning"><i class="ti ti-trophy"></i></span>`);
        } else {
          _push(`<span class="text-muted">${ssrInterpolate(i + 1)}</span>`);
        }
        _push(`</td><td><div class="fw-semibold">${ssrInterpolate(entity.name)}</div><small class="text-muted">${ssrInterpolate(entity.code)}</small></td><td><span class="fw-bold">${ssrInterpolate(Number(entity.patients_count).toLocaleString("pt-BR"))}</span></td><td><small>${ssrInterpolate(formatDate(entity.created_at))}</small></td></tr>`);
      });
      _push(`<!--]--></tbody></table></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/ManagerDashboard/TopEntities.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
