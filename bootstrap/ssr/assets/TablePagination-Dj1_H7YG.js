import { mergeProps, unref, withCtx, createVNode, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderClass, ssrRenderComponent, ssrRenderList } from "vue/server-renderer";
import { Link } from "@inertiajs/vue3";
const _sfc_main = {
  __name: "TablePagination",
  __ssrInlineRender: true,
  props: {
    data: { type: Object, required: true },
    showingFrom: { type: String, default: "Exibindo" },
    showingOf: { type: String, default: "de" },
    showingSuffix: { type: String, default: "" }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      if (__props.data.last_page > 1) {
        _push(`<div${ssrRenderAttrs(mergeProps({ class: "d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2" }, _attrs))}><p class="text-muted small mb-0">${ssrInterpolate(__props.showingFrom)} ${ssrInterpolate(__props.data.from)}–${ssrInterpolate(__props.data.to)} ${ssrInterpolate(__props.showingOf)} ${ssrInterpolate(__props.data.total)} ${ssrInterpolate(__props.showingSuffix)}</p><nav><ul class="pagination pagination-sm mb-0"><li class="${ssrRenderClass([{ disabled: __props.data.current_page === 1 }, "page-item"])}">`);
        _push(ssrRenderComponent(unref(Link), {
          class: "page-link",
          href: __props.data.prev_page_url ?? "#",
          "preserve-scroll": "",
          "preserve-state": ""
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(`<i class="ti ti-arrow-left"${_scopeId}></i>`);
            } else {
              return [
                createVNode("i", { class: "ti ti-arrow-left" })
              ];
            }
          }),
          _: 1
        }, _parent));
        _push(`</li><!--[-->`);
        ssrRenderList(__props.data.links.slice(1, -1), (link) => {
          _push(`<li class="${ssrRenderClass([{ active: link.active, disabled: !link.url }, "page-item"])}">`);
          _push(ssrRenderComponent(unref(Link), {
            class: "page-link",
            href: link.url ?? "#",
            "preserve-scroll": "",
            "preserve-state": ""
          }, null, _parent));
          _push(`</li>`);
        });
        _push(`<!--]--><li class="${ssrRenderClass([{ disabled: __props.data.current_page === __props.data.last_page }, "page-item"])}">`);
        _push(ssrRenderComponent(unref(Link), {
          class: "page-link",
          href: __props.data.next_page_url ?? "#",
          "preserve-scroll": "",
          "preserve-state": ""
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(`<i class="ti ti-arrow-right"${_scopeId}></i>`);
            } else {
              return [
                createVNode("i", { class: "ti ti-arrow-right" })
              ];
            }
          }),
          _: 1
        }, _parent));
        _push(`</li></ul></nav></div>`);
      } else {
        _push(`<!---->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/TablePagination.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
