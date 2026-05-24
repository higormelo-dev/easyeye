import { ssrRenderTeleport, ssrRenderStyle, ssrRenderClass, ssrInterpolate, ssrRenderAttr, ssrIncludeBooleanAttr, ssrRenderList } from "vue/server-renderer";
import { reactive, watch, ref, computed, onBeforeUnmount, useSSRContext } from "vue";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "MedicalRecordFileUploadModal",
  __ssrInlineRender: true,
  props: {
    show: { type: Boolean, required: true },
    storeUrl: { type: String, required: true },
    storage: { type: Object, required: true },
    csrfToken: { type: String, required: true }
  },
  emits: ["close", "uploaded", "storage-updated"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const quota = reactive({ ...props.storage });
    watch(() => props.storage, (v) => Object.assign(quota, v), { deep: true });
    const items = ref([]);
    const dragging = ref(false);
    const isFull = computed(
      () => !quota.is_unlimited && quota.remaining_bytes !== null && quota.remaining_bytes <= 0
    );
    const quotaColor = computed(() => {
      if (quota.is_unlimited) return "bg-success";
      if (quota.percent >= 95) return "bg-danger";
      if (quota.percent >= 80) return "bg-warning";
      return "bg-info";
    });
    const quotaLabel = computed(() => {
      if (quota.is_unlimited) {
        return `${formatBytes(quota.used_bytes)} usados — Ilimitado`;
      }
      return `${formatBytes(quota.used_bytes)} de ${formatBytes(quota.limit_bytes)} usados`;
    });
    const hasActive = computed(() => items.value.some((i) => i.status === "uploading"));
    const allDone = computed(
      () => items.value.length > 0 && items.value.every((i) => ["success", "error", "cancelled"].includes(i.status))
    );
    const pendingBytes = computed(
      () => items.value.filter((i) => i.status === "pending").reduce((sum, i) => sum + i.size, 0)
    );
    function formatBytes(bytes) {
      if (bytes === 0 || bytes == null) return "0 B";
      const units = ["B", "KB", "MB", "GB", "TB"];
      const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
      const value = bytes / Math.pow(1024, i);
      return `${value.toFixed(value >= 10 || i === 0 ? 0 : 1)} ${units[i]}`;
    }
    function statusLabel(s) {
      return {
        pending: "Aguardando",
        uploading: "Enviando…",
        success: "Concluído",
        error: "Erro",
        cancelled: "Cancelado"
      }[s] ?? s;
    }
    function statusClass(s) {
      return {
        pending: "text-muted",
        uploading: "text-info",
        success: "text-success",
        error: "text-danger",
        cancelled: "text-warning"
      }[s] ?? "";
    }
    function iconFor(item) {
      if (item.isImage) return null;
      const map = {
        pdf: "fas fa-file-pdf text-danger",
        doc: "fas fa-file-word text-primary",
        docx: "fas fa-file-word text-primary",
        gif: "fas fa-file-image text-info",
        webp: "fas fa-file-image text-info"
      };
      return map[item.ext] ?? "fas fa-file text-secondary";
    }
    function cleanup() {
      items.value.forEach((i) => {
        if (i.previewUrl) URL.revokeObjectURL(i.previewUrl);
      });
      items.value = [];
      dragging.value = false;
    }
    onBeforeUnmount(() => {
      items.value.forEach((i) => {
        if (i.previewUrl) URL.revokeObjectURL(i.previewUrl);
      });
    });
    watch(() => props.show, (v) => {
      if (v) cleanup();
    });
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        if (__props.show) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0, 0, 0, .5)" })}" data-v-6887a6e2><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" data-v-6887a6e2><div class="modal-content" data-v-6887a6e2><div class="modal-header py-2" data-v-6887a6e2><h6 class="modal-title" data-v-6887a6e2><i class="fas fa-paperclip me-2" style="${ssrRenderStyle({ "color": "#607d8b" })}" data-v-6887a6e2></i> Anexar arquivos ao prontuário </h6><button type="button" class="btn-close" data-v-6887a6e2></button></div><div class="modal-body p-3" data-v-6887a6e2><div class="mb-3" data-v-6887a6e2><div class="d-flex justify-content-between align-items-center small mb-1" data-v-6887a6e2><span class="text-muted" data-v-6887a6e2><i class="fas fa-hdd me-1" data-v-6887a6e2></i> Armazenamento </span><span class="${ssrRenderClass(quota.percent >= 95 ? "text-danger fw-bold" : "text-muted")}" data-v-6887a6e2>${ssrInterpolate(quotaLabel.value)}</span></div><div class="progress" style="${ssrRenderStyle({ "height": "6px" })}" data-v-6887a6e2><div class="${ssrRenderClass([quotaColor.value, "progress-bar"])}" style="${ssrRenderStyle(`width: ${quota.is_unlimited ? 0 : quota.percent}%`)}" role="progressbar"${ssrRenderAttr("aria-valuenow", quota.percent)} aria-valuemin="0" aria-valuemax="100" data-v-6887a6e2></div></div>`);
          if (quota.percent >= 80 && !quota.is_unlimited) {
            _push2(`<div class="${ssrRenderClass([quota.percent >= 95 ? "text-danger" : "text-warning", "small mt-1"])}" data-v-6887a6e2><i class="fas fa-exclamation-triangle me-1" data-v-6887a6e2></i>`);
            if (quota.percent >= 95) {
              _push2(`<!--[--> Armazenamento quase esgotado — considere adquirir um pacote adicional. <!--]-->`);
            } else {
              _push2(`<!--[--> Você já usou ${ssrInterpolate(quota.percent)}% da cota. Pode contratar mais espaço. <!--]-->`);
            }
            _push2(`</div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div><div class="${ssrRenderClass([{ "is-dragging": dragging.value, "is-disabled": isFull.value }, "upload-dropzone"])}" data-v-6887a6e2><input type="file" id="upload-picker" multiple${ssrRenderAttr("accept", quota.accept)}${ssrIncludeBooleanAttr(isFull.value) ? " disabled" : ""} class="d-none" data-v-6887a6e2><div class="upload-dropzone-inner" data-v-6887a6e2><i class="fas fa-cloud-upload-alt upload-dropzone-icon" data-v-6887a6e2></i><p class="mb-1 fw-semibold" data-v-6887a6e2>`);
          if (isFull.value) {
            _push2(`<!--[--> Cota esgotada <!--]-->`);
          } else {
            _push2(`<!--[--> Arraste arquivos aqui <!--]-->`);
          }
          _push2(`</p><p class="text-muted small mb-2" data-v-6887a6e2> ou <label for="upload-picker" class="text-primary" style="${ssrRenderStyle({ "cursor": "pointer", "text-decoration": "underline" })}" data-v-6887a6e2> clique para selecionar </label></p><p class="text-muted small mb-0" data-v-6887a6e2> Até ${ssrInterpolate(quota.max_files_per_batch)} arquivos por envio, máx. ${ssrInterpolate(formatBytes(quota.max_file_size_bytes))} cada<br data-v-6887a6e2> Aceitos: JPG, PNG, GIF, WEBP, PDF, DOC, DOCX </p></div></div>`);
          if (items.value.length > 0) {
            _push2(`<div class="upload-items mt-3" data-v-6887a6e2><!--[-->`);
            ssrRenderList(items.value, (item) => {
              _push2(`<div class="${ssrRenderClass([`is-${item.status}`, "upload-item"])}" data-v-6887a6e2><div class="upload-item-thumb" data-v-6887a6e2>`);
              if (item.isImage && item.previewUrl) {
                _push2(`<img${ssrRenderAttr("src", item.previewUrl)}${ssrRenderAttr("alt", item.name)} data-v-6887a6e2>`);
              } else {
                _push2(`<i class="${ssrRenderClass(iconFor(item))}" data-v-6887a6e2></i>`);
              }
              _push2(`</div><div class="upload-item-body" data-v-6887a6e2><div class="upload-item-head" data-v-6887a6e2><span class="upload-item-name"${ssrRenderAttr("title", item.name)} data-v-6887a6e2>${ssrInterpolate(item.name)}</span><span class="upload-item-size text-muted small" data-v-6887a6e2>${ssrInterpolate(formatBytes(item.size))}</span></div><div class="progress mt-1" style="${ssrRenderStyle({ "height": "4px" })}" data-v-6887a6e2><div class="${ssrRenderClass([{
                "bg-info": item.status === "uploading",
                "bg-success": item.status === "success",
                "bg-danger": item.status === "error",
                "bg-warning": item.status === "cancelled",
                "bg-secondary": item.status === "pending"
              }, "progress-bar"])}" style="${ssrRenderStyle(`width: ${item.status === "pending" ? 0 : item.progress}%`)}" data-v-6887a6e2></div></div><div class="d-flex justify-content-between align-items-center mt-1" data-v-6887a6e2><span class="${ssrRenderClass([statusClass(item.status), "small"])}" data-v-6887a6e2>`);
              if (item.status === "success") {
                _push2(`<i class="fas fa-check-circle me-1" data-v-6887a6e2></i>`);
              } else if (item.status === "error") {
                _push2(`<i class="fas fa-times-circle me-1" data-v-6887a6e2></i>`);
              } else if (item.status === "uploading") {
                _push2(`<i class="fas fa-spinner fa-spin me-1" data-v-6887a6e2></i>`);
              } else if (item.status === "cancelled") {
                _push2(`<i class="fas fa-ban me-1" data-v-6887a6e2></i>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(` ${ssrInterpolate(statusLabel(item.status))} `);
              if (item.status === "uploading") {
                _push2(`<!--[--> (${ssrInterpolate(item.progress)}%) <!--]-->`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span>`);
              if (item.error) {
                _push2(`<span class="small text-danger ms-2" data-v-6887a6e2>${ssrInterpolate(item.error)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><div class="upload-item-actions" data-v-6887a6e2>`);
              if (item.status === "uploading") {
                _push2(`<button type="button" class="btn btn-sm btn-link text-warning" title="Cancelar" data-v-6887a6e2><i class="fas fa-stop-circle" data-v-6887a6e2></i></button>`);
              } else if (item.status === "error" || item.status === "cancelled") {
                _push2(`<button type="button" class="btn btn-sm btn-link text-info" title="Tentar novamente" data-v-6887a6e2><i class="fas fa-redo" data-v-6887a6e2></i></button>`);
              } else {
                _push2(`<!---->`);
              }
              if (item.status !== "uploading") {
                _push2(`<button type="button" class="btn btn-sm btn-link text-danger" title="Remover" data-v-6887a6e2><i class="fas fa-trash" data-v-6887a6e2></i></button>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div>`);
            });
            _push2(`<!--]--></div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div><div class="modal-footer py-2" data-v-6887a6e2><div class="me-auto small text-muted" data-v-6887a6e2>`);
          if (pendingBytes.value > 0) {
            _push2(`<!--[--><i class="fas fa-clock me-1" data-v-6887a6e2></i> ${ssrInterpolate(items.value.filter((i) => i.status === "pending").length)} arquivo(s) pendente(s) — ${ssrInterpolate(formatBytes(pendingBytes.value))}<!--]-->`);
          } else if (allDone.value) {
            _push2(`<!--[--><i class="fas fa-check-circle text-success me-1" data-v-6887a6e2></i> Envio finalizado <!--]-->`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div><button type="button" class="btn btn-sm btn-outline-secondary" data-v-6887a6e2> Fechar </button><button type="button" class="btn btn-sm btn-primary"${ssrIncludeBooleanAttr(hasActive.value || pendingBytes.value === 0 || isFull.value) ? " disabled" : ""} data-v-6887a6e2><i class="fas fa-upload me-1" data-v-6887a6e2></i> Enviar `);
          if (pendingBytes.value > 0) {
            _push2(`<!--[--> (${ssrInterpolate(items.value.filter((i) => i.status === "pending").length)}) <!--]-->`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</button></div></div></div></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/MedicalRecords/Components/MedicalRecordFileUploadModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const MedicalRecordFileUploadModal = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-6887a6e2"]]);
export {
  MedicalRecordFileUploadModal as default
};
