import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderSlot, ssrRenderClass } from "vue/server-renderer";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "SortableTh",
  __ssrInlineRender: true,
  props: {
    colKey: { type: String, required: true },
    currentSort: { type: String, default: "" },
    currentDir: { type: String, default: "asc" }
  },
  emits: ["sort"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const icon = computed(() => {
      if (props.currentSort !== props.colKey) return "ti ti-arrows-sort text-muted";
      return props.currentDir === "asc" ? "ti ti-sort-ascending" : "ti ti-sort-descending";
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<th${ssrRenderAttrs(mergeProps({ class: "cursor-pointer user-select-none" }, _attrs))} data-v-17ea1d3d>`);
      ssrRenderSlot(_ctx.$slots, "default", {}, null, _push, _parent);
      _push(`<i class="${ssrRenderClass([icon.value, "ms-1 fs-11"])}" data-v-17ea1d3d></i></th>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/SortableTh.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const SortableTh = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-17ea1d3d"]]);
export {
  SortableTh as S
};
