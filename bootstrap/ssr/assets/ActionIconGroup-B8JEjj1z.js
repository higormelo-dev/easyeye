import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderSlot } from "vue/server-renderer";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "ActionIconGroup",
  __ssrInlineRender: true,
  props: {
    gap: { type: String, default: "normal" },
    // tight | normal | wide
    align: { type: String, default: "start" },
    // start | center | end
    wrap: { type: Boolean, default: false }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({
        class: ["ee-action-group", `ee-action-group--gap-${__props.gap}`, `ee-action-group--align-${__props.align}`, __props.wrap && "ee-action-group--wrap"]
      }, _attrs))} data-v-1c9c59ba>`);
      ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
      _push(`</div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/ActionIconGroup.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const ActionIconGroup = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-1c9c59ba"]]);
export {
  ActionIconGroup as A
};
