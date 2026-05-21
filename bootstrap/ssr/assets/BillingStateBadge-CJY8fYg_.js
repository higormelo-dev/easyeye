import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate } from "vue/server-renderer";
const _sfc_main = {
  __name: "BillingStateBadge",
  __ssrInlineRender: true,
  props: {
    badge: { type: String, default: "badge-soft-secondary" },
    label: { type: String, default: null },
    state: { type: String, default: null }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      if (__props.label || __props.state) {
        _push(`<span${ssrRenderAttrs(mergeProps({
          class: `badge ${__props.badge}`
        }, _attrs))}>${ssrInterpolate(__props.label ?? __props.state)}</span>`);
      } else {
        _push(`<span${ssrRenderAttrs(mergeProps({ class: "text-muted small" }, _attrs))}>—</span>`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/BillingStateBadge.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
