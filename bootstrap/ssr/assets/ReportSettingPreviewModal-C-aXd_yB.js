import { ssrRenderTeleport, ssrInterpolate, ssrRenderAttr, ssrRenderClass } from "vue/server-renderer";
import { ref, watch, useSSRContext } from "vue";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "ReportSettingPreviewModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    previewUrl: { type: String, default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const loading = ref(false);
    const srcUrl = ref("");
    watch(() => props.open, (val) => {
      if (val && props.previewUrl) {
        loading.value = true;
        srcUrl.value = props.previewUrl;
      } else {
        srcUrl.value = "";
      }
    });
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        if (__props.open) {
          _push2(`<div class="rs-preview-backdrop" data-v-dc9fac2b></div>`);
        } else {
          _push2(`<!---->`);
        }
        if (__props.open) {
          _push2(`<div class="rs-preview-modal" role="dialog" aria-modal="true" data-v-dc9fac2b><div class="rs-preview-header" data-v-dc9fac2b><span class="fw-semibold" data-v-dc9fac2b><i class="ti ti-file-search me-2 text-primary" data-v-dc9fac2b></i>${ssrInterpolate(__props.t.preview_title)}</span><button type="button" class="btn-close" data-v-dc9fac2b></button></div><div class="rs-preview-body" data-v-dc9fac2b>`);
          if (loading.value) {
            _push2(`<div class="text-center py-5" data-v-dc9fac2b><div class="spinner-border text-primary" role="status" data-v-dc9fac2b></div></div>`);
          } else {
            _push2(`<!---->`);
          }
          if (srcUrl.value) {
            _push2(`<iframe${ssrRenderAttr("src", srcUrl.value)} class="${ssrRenderClass([{ "d-none": loading.value }, "rs-preview-iframe"])}" frameborder="0" data-v-dc9fac2b></iframe>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div><div class="rs-preview-footer" data-v-dc9fac2b><button class="btn btn-secondary btn-sm" data-v-dc9fac2b>${ssrInterpolate(__props.t.preview_close)}</button></div></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/ReportSettings/ReportSettingPreviewModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const ReportSettingPreviewModal = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-dc9fac2b"]]);
export {
  ReportSettingPreviewModal as default
};
