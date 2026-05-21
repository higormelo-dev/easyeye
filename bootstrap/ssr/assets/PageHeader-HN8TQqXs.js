import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderStyle, ssrRenderClass, ssrRenderAttr, ssrRenderSlot } from "vue/server-renderer";
const _sfc_main = {
  __name: "PageHeader",
  __ssrInlineRender: true,
  props: {
    title: { type: String, default: "" },
    total: { type: Number, default: null },
    view: { type: String, default: "table" },
    viewTableTitle: { type: String, default: "Tabela" },
    viewCardsTitle: { type: String, default: "Cards" }
  },
  emits: ["set-view"],
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "d-flex align-items-center gap-2 pb-3 mb-3 border-bottom" }, _attrs))}><div class="d-flex align-items-center gap-2 me-auto"><h4 class="mb-0 fw-bold">${ssrInterpolate(__props.title)}</h4>`);
      if (__props.total !== null) {
        _push(`<span style="${ssrRenderStyle({ "font-size": ".78rem", "font-weight": "600", "color": "#0d6efd", "background": "#eff4ff", "border": "1.5px solid #0d6efd", "border-radius": "20px", "padding": "2px 12px", "white-space": "nowrap", "line-height": "1.6" })}"> Total: ${ssrInterpolate(__props.total)}</span>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div><div class="bg-white border shadow-sm rounded px-1 d-flex align-items-center"><button type="button" class="${ssrRenderClass([__props.view === "table" ? "bg-light" : "bg-white", "rounded p-1 d-flex align-items-center border-0"])}"${ssrRenderAttr("title", __props.viewTableTitle)}><i class="ti ti-list fs-14 text-body"></i></button><button type="button" class="${ssrRenderClass([__props.view === "cards" ? "bg-light" : "bg-white", "rounded p-1 d-flex align-items-center border-0"])}"${ssrRenderAttr("title", __props.viewCardsTitle)}><i class="ti ti-layout-grid fs-14 text-body"></i></button></div>`);
      ssrRenderSlot(_ctx.$slots, "actions", {}, null, _push, _parent);
      _push(`</div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/PageHeader.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
