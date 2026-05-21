import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderStyle, ssrRenderAttr } from "vue/server-renderer";
const _sfc_main = {
  __name: "SearchInput",
  __ssrInlineRender: true,
  props: {
    modelValue: { type: String, default: "" },
    placeholder: { type: String, default: "Buscar..." },
    maxWidth: { type: String, default: "380px" }
  },
  emits: ["update:modelValue"],
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "mb-3" }, _attrs))}><div class="input-group input-group-sm" style="${ssrRenderStyle({ maxWidth: __props.maxWidth })}"><span class="input-group-text bg-white"><i class="ti ti-search fs-12"></i></span><input${ssrRenderAttr("value", __props.modelValue)} type="text" class="form-control border-start-0"${ssrRenderAttr("placeholder", __props.placeholder)}>`);
      if (__props.modelValue) {
        _push(`<button class="btn btn-outline-secondary border-start-0" type="button"><i class="ti ti-x fs-12"></i></button>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/SearchInput.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
