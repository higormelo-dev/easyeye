import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderClass, ssrInterpolate, ssrRenderList } from "vue/server-renderer";
const _sfc_main$1 = {
  __name: "LoadingSpinner",
  __ssrInlineRender: true,
  props: {
    label: { type: String, default: "Carregando..." },
    color: { type: String, default: "primary" },
    padding: { type: String, default: "py-5" }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({
        class: ["text-center", __props.padding]
      }, _attrs))}><div class="${ssrRenderClass(`spinner-border text-${__props.color}`)}" role="status"><span class="visually-hidden">${ssrInterpolate(__props.label)}</span></div></div>`);
    };
  }
};
const _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/LoadingSpinner.vue");
  return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
const _sfc_main = {
  __name: "CardsPagination",
  __ssrInlineRender: true,
  props: {
    meta: {
      type: Object,
      default: () => ({ current_page: 1, last_page: 1 })
    }
  },
  emits: ["change"],
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      if (__props.meta.last_page > 1) {
        _push(`<nav${ssrRenderAttrs(mergeProps({ class: "d-flex justify-content-center mt-3" }, _attrs))}><ul class="pagination pagination-sm mb-0"><li class="${ssrRenderClass([{ disabled: __props.meta.current_page === 1 }, "page-item"])}"><button class="page-link"><i class="ti ti-arrow-left"></i></button></li><!--[-->`);
        ssrRenderList(__props.meta.last_page, (p) => {
          _push(`<li class="${ssrRenderClass([{ active: p === __props.meta.current_page }, "page-item"])}"><button class="page-link">${ssrInterpolate(p)}</button></li>`);
        });
        _push(`<!--]--><li class="${ssrRenderClass([{ disabled: __props.meta.current_page === __props.meta.last_page }, "page-item"])}"><button class="page-link"><i class="ti ti-arrow-right"></i></button></li></ul></nav>`);
      } else {
        _push(`<!---->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/CardsPagination.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main$1 as _,
  _sfc_main as a
};
