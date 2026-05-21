import { ssrRenderTeleport, ssrRenderStyle, ssrRenderSlot, ssrInterpolate } from "vue/server-renderer";
import { useSSRContext } from "vue";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "OffcanvasPanel",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    width: { type: Number, default: 540 },
    loading: { type: Boolean, default: false },
    loadingLabel: { type: String, default: "Carregando..." }
  },
  emits: ["close"],
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        if (__props.open) {
          _push2(`<div class="ee-modal__backdrop" data-v-8380c996></div>`);
        } else {
          _push2(`<!---->`);
        }
        if (__props.open) {
          _push2(`<div class="ee-modal__wrap" role="dialog" aria-modal="true" data-v-8380c996><div class="ee-modal__dialog" style="${ssrRenderStyle({ maxWidth: `${__props.width}px` })}" data-v-8380c996><div class="ee-modal__header" data-v-8380c996><div class="ee-modal__header-content" data-v-8380c996>`);
          ssrRenderSlot(_ctx.$slots, "header", {}, null, _push2, _parent);
          _push2(`</div><button type="button" class="btn-close flex-shrink-0" data-v-8380c996></button></div>`);
          if (_ctx.$slots.tabs) {
            _push2(`<div class="ee-modal__tabs border-bottom" data-v-8380c996>`);
            ssrRenderSlot(_ctx.$slots, "tabs", {}, null, _push2, _parent);
            _push2(`</div>`);
          } else {
            _push2(`<!---->`);
          }
          if (__props.loading) {
            _push2(`<div class="text-center py-5" data-v-8380c996><div class="spinner-border text-primary" role="status" data-v-8380c996><span class="visually-hidden" data-v-8380c996>${ssrInterpolate(__props.loadingLabel)}</span></div></div>`);
          } else {
            _push2(`<!--[--><div class="ee-modal__body" data-v-8380c996>`);
            ssrRenderSlot(_ctx.$slots, "default", {}, null, _push2, _parent);
            _push2(`</div>`);
            if (_ctx.$slots.footer) {
              _push2(`<div class="ee-modal__footer" data-v-8380c996>`);
              ssrRenderSlot(_ctx.$slots, "footer", {}, null, _push2, _parent);
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--]-->`);
          }
          _push2(`</div></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/OffcanvasPanel.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const OffcanvasPanel = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-8380c996"]]);
export {
  OffcanvasPanel as O
};
