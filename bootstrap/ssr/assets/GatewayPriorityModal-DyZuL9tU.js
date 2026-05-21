import { ssrRenderTeleport, ssrRenderStyle, ssrInterpolate, ssrRenderAttr, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { ref, watch, useSSRContext } from "vue";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "GatewayPriorityModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    gateway: { type: Object, default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const priority = ref(1);
    const saving = ref(false);
    const error = ref("");
    watch(() => props.open, (val) => {
      if (val && props.gateway) {
        priority.value = props.gateway.priority;
        error.value = "";
      }
    });
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        var _a;
        if (__props.open) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.4)" })}"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ti ti-sort-ascending me-2"></i>${ssrInterpolate(__props.t.modal_priority_title)}</h5><button type="button" class="btn-close"></button></div><form><div class="modal-body"><p class="fw-semibold mb-1 small">${ssrInterpolate((_a = __props.gateway) == null ? void 0 : _a.name)}</p><p class="text-muted small mb-3">${ssrInterpolate(__props.t.modal_priority_desc)}</p><input${ssrRenderAttr("value", priority.value)} type="number" class="form-control form-control-sm" min="1" max="999" required>`);
          if (error.value) {
            _push2(`<div class="alert alert-danger mt-2 py-2 small mb-0"><i class="ti ti-alert-circle me-1"></i>${ssrInterpolate(error.value)}</div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div><div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(__props.t.modal_priority_cancel)}</button><button type="submit" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>`);
          if (saving.value) {
            _push2(`<span class="spinner-border spinner-border-sm me-1"></span>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(` ${ssrInterpolate(__props.t.modal_priority_save)}</button></div></form></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Gateways/GatewayPriorityModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
