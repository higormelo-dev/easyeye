import { ssrRenderTeleport, ssrRenderStyle, ssrRenderAttr, ssrInterpolate } from "vue/server-renderer";
import { computed, onMounted, onBeforeUnmount, useSSRContext } from "vue";
const _sfc_main = {
  __name: "PdfPreviewModal",
  __ssrInlineRender: true,
  props: {
    url: { type: String, required: true },
    title: { type: String, default: "" },
    /** Nome de arquivo sugerido para o atributo download. Sem extensão = .pdf adicionado. */
    filename: { type: String, default: "" }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const downloadName = computed(() => {
      const base = (props.filename || props.title || "prontuario").trim();
      const slug = base.normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/[^a-zA-Z0-9._-]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 100);
      return /\.pdf$/i.test(slug) ? slug : `${slug || "arquivo"}.pdf`;
    });
    function onKey(e) {
      if (e.key === "Escape") emit("close");
    }
    onMounted(() => window.addEventListener("keydown", onKey));
    onBeforeUnmount(() => window.removeEventListener("keydown", onKey));
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-xl modal-dialog-centered" style="${ssrRenderStyle({ "height": "90vh" })}"><div class="modal-content" style="${ssrRenderStyle({ "height": "100%" })}"><div class="modal-header py-2"><h6 class="modal-title mb-0 d-flex align-items-center"><i class="ti ti-file-text-ai me-2 text-info"></i><span class="text-truncate"${ssrRenderAttr("title", __props.title)}>${ssrInterpolate(__props.title || "Pré-visualização do PDF")}</span></h6><div class="ms-auto d-flex gap-2 align-items-center">`);
        if (__props.url) {
          _push2(`<a${ssrRenderAttr("href", __props.url)} target="_blank" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1" title="Abrir em nova aba"><i class="ti ti-external-link"></i><span class="d-none d-sm-inline">Nova aba</span></a>`);
        } else {
          _push2(`<!---->`);
        }
        if (__props.url) {
          _push2(`<a${ssrRenderAttr("href", __props.url)}${ssrRenderAttr("download", downloadName.value)} class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1" title="Baixar PDF"><i class="ti ti-download"></i><span class="d-none d-sm-inline">Baixar</span></a>`);
        } else {
          _push2(`<!---->`);
        }
        _push2(`<button type="button" class="btn-close ms-1"></button></div></div><div class="modal-body p-0" style="${ssrRenderStyle({ "flex": "1", "overflow": "hidden", "background": "#525659" })}"><iframe${ssrRenderAttr("src", __props.url)} style="${ssrRenderStyle({ "width": "100%", "height": "100%", "border": "none", "display": "block" })}" title="PDF Preview"></iframe></div></div></div></div>`);
      }, "body", false, _parent);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/MedicalRecords/Components/PdfPreviewModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
