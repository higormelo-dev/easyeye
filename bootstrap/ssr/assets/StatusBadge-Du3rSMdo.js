import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate } from "vue/server-renderer";
const _sfc_main = {
  __name: "StatusBadge",
  __ssrInlineRender: true,
  props: {
    active: { type: Boolean, default: true },
    deleted: { type: Boolean, default: false },
    labelActive: { type: String, default: "Ativo" },
    labelInactive: { type: String, default: "Inativo" },
    labelDeleted: { type: String, default: "Excluído" }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      if (__props.deleted) {
        _push(`<span${ssrRenderAttrs(mergeProps({ class: "badge badge-soft-danger rounded fs-13" }, _attrs))}>${ssrInterpolate(__props.labelDeleted)}</span>`);
      } else if (__props.active) {
        _push(`<span${ssrRenderAttrs(mergeProps({ class: "badge badge-soft-success rounded text-success border border-success fs-13 fw-medium" }, _attrs))}>${ssrInterpolate(__props.labelActive)}</span>`);
      } else {
        _push(`<span${ssrRenderAttrs(mergeProps({ class: "badge badge-soft-secondary rounded fs-13 fw-medium" }, _attrs))}>${ssrInterpolate(__props.labelInactive)}</span>`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/StatusBadge.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
