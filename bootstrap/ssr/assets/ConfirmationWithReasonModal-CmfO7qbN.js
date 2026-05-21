import { computed, ref, watch, nextTick, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrIncludeBooleanAttr, ssrRenderClass, ssrRenderAttr } from "vue/server-renderer";
import { usePage } from "@inertiajs/vue3";
const _sfc_main = {
  __name: "ConfirmationWithReasonModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    title: { type: String, default: "" },
    message: { type: String, default: "" },
    confirmLabel: { type: String, default: "" },
    confirmVariant: { type: String, default: "danger" },
    // danger | warning | primary
    saving: { type: Boolean, default: false },
    minLength: { type: Number, default: 20 },
    maxLength: { type: Number, default: 1e3 }
  },
  emits: ["close", "confirm"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const page = usePage();
    const t = computed(() => page.props.t_hardening ?? {});
    const reason = ref("");
    const textarea = ref(null);
    const length = computed(() => reason.value.trim().length);
    const isValid = computed(() => length.value >= props.minLength);
    const isTooLong = computed(() => reason.value.length > props.maxLength);
    const canSubmit = computed(() => isValid.value && !isTooLong.value && !props.saving);
    const counterClass = computed(() => {
      if (isTooLong.value) return "text-danger";
      if (isValid.value) return "text-success";
      return "text-muted";
    });
    const counterText = computed(() => {
      const tpl = t.value.modal_counter ?? ":current / :min mínimo";
      return tpl.replace(":current", length.value).replace(":min", props.minLength);
    });
    watch(() => props.open, async (val) => {
      var _a;
      if (val) {
        reason.value = "";
        await nextTick();
        (_a = textarea.value) == null ? void 0 : _a.focus();
      }
    });
    const btnClass = computed(() => `btn btn-${props.confirmVariant} btn-sm`);
    return (_ctx, _push, _parent, _attrs) => {
      if (__props.open) {
        _push(`<div${ssrRenderAttrs(mergeProps({
          class: "modal d-block",
          tabindex: "-1",
          style: { "background": "rgba(0,0,0,.55)" }
        }, _attrs))}><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ti ti-shield-lock-filled me-1 text-warning"></i> ${ssrInterpolate(__props.title || t.value.modal_title || "Confirmar ação")}</h5><button type="button" class="btn-close"${ssrIncludeBooleanAttr(__props.saving) ? " disabled" : ""}></button></div><div class="modal-body"><div class="alert alert-warning small d-flex align-items-start mb-3"><i class="ti ti-info-circle me-2 fs-5 mt-1"></i><div>`);
        if (__props.message) {
          _push(`<strong>${ssrInterpolate(__props.message)}</strong>`);
        } else {
          _push(`<!---->`);
        }
        _push(`<p class="mb-0 mt-1">${ssrInterpolate(t.value.modal_warning ?? "Esta ação é registrada no log de auditoria e não pode ser desfeita silenciosamente.")}</p></div></div><label class="form-label fw-medium">${ssrInterpolate(t.value.modal_reason_label ?? "Justificativa")} <span class="text-danger">*</span></label><textarea class="${ssrRenderClass([{ "is-invalid": isTooLong.value }, "form-control"])}" rows="4"${ssrRenderAttr("maxlength", __props.maxLength + 50)}${ssrRenderAttr("placeholder", t.value.modal_reason_placeholder ?? "Descreva o motivo...")}${ssrIncludeBooleanAttr(__props.saving) ? " disabled" : ""}>${ssrInterpolate(reason.value)}</textarea><div class="d-flex justify-content-between mt-1"><small class="text-muted">${ssrInterpolate(t.value.modal_reason_hint)}</small><small class="${ssrRenderClass(counterClass.value)}">${ssrInterpolate(counterText.value)}</small></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm"${ssrIncludeBooleanAttr(__props.saving) ? " disabled" : ""}>${ssrInterpolate(t.value.modal_cancel ?? "Cancelar")}</button><button type="button" class="${ssrRenderClass(btnClass.value)}"${ssrIncludeBooleanAttr(!canSubmit.value) ? " disabled" : ""}>`);
        if (__props.saving) {
          _push(`<span class="spinner-border spinner-border-sm me-1"></span>`);
        } else {
          _push(`<i class="ti ti-check me-1"></i>`);
        }
        _push(` ${ssrInterpolate(__props.confirmLabel || t.value.modal_confirm || "Confirmar")}</button></div></div></div></div>`);
      } else {
        _push(`<!---->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/ConfirmationWithReasonModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
