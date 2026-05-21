import { ssrRenderClass, ssrRenderAttr, ssrRenderTeleport, ssrRenderStyle, ssrRenderSlot } from "vue/server-renderer";
import { ref, onBeforeUnmount, useSSRContext } from "vue";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "ActionDropdown",
  __ssrInlineRender: true,
  props: {
    title: { type: String, default: "Mais ações" },
    align: { type: String, default: "right" },
    // 'right' | 'left'
    minWidth: { type: Number, default: 180 },
    icon: { type: String, default: "ti ti-dots-vertical" },
    btnClass: { type: String, default: "btn btn-sm btn-outline-secondary" }
  },
  setup(__props) {
    const open = ref(false);
    const triggerRef = ref(null);
    const menuRef = ref(null);
    const menuStyle = ref({});
    function close() {
      open.value = false;
      document.removeEventListener("click", onOutsideClick, true);
      document.removeEventListener("keydown", onEsc);
      window.removeEventListener("resize", close);
      window.removeEventListener("scroll", close, true);
    }
    function onOutsideClick(e) {
      var _a, _b;
      if ((_a = triggerRef.value) == null ? void 0 : _a.contains(e.target)) return;
      if ((_b = menuRef.value) == null ? void 0 : _b.contains(e.target)) return;
      close();
    }
    function onEsc(e) {
      if (e.key === "Escape") close();
    }
    onBeforeUnmount(close);
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[--><button type="button" class="${ssrRenderClass(__props.btnClass)}"${ssrRenderAttr("title", __props.title)} data-v-c1fcedf5><i class="${ssrRenderClass(__props.icon)}" data-v-c1fcedf5></i></button>`);
      ssrRenderTeleport(_push, (_push2) => {
        if (open.value) {
          _push2(`<ul class="dropdown-menu show p-2" style="${ssrRenderStyle(menuStyle.value)}" data-v-c1fcedf5>`);
          ssrRenderSlot(_ctx.$slots, "default", {}, null, _push2, _parent);
          _push2(`</ul>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/ActionDropdown.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const ActionDropdown = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-c1fcedf5"]]);
export {
  ActionDropdown as A
};
