import { ref, computed, watch, unref, useSSRContext } from "vue";
import { ssrRenderTeleport, ssrRenderStyle, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderAttr, ssrRenderDynamicModel, ssrIncludeBooleanAttr, ssrRenderComponent } from "vue/server-renderer";
import "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./ConfirmationWithReasonModal-CmfO7qbN.js";
import { u as useConfirmationWithReason } from "./useConfirmationWithReason-DDlQOe6J.js";
const _sfc_main = {
  __name: "GatewayCredentialsModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    gateway: { type: Object, default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const { state: reasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();
    const credentials = ref([]);
    const listLoading = ref(false);
    const listError = ref("");
    const form = ref({ label: "", secret: "", webhook_secret: "", valid_from: "", valid_to: "" });
    const saving = ref(false);
    const formError = ref("");
    const showSecret = ref(false);
    const showWebhook = ref(false);
    const secretInfo = computed(() => {
      var _a, _b, _c, _d;
      const code = ((_a = props.gateway) == null ? void 0 : _a.code) ?? "";
      return ((_c = (_b = props.t) == null ? void 0 : _b.secret_label) == null ? void 0 : _c[code]) ?? { label: ((_d = props.t) == null ? void 0 : _d.modal_cred_api_key) ?? "API Key", hint: "" };
    });
    watch(() => props.open, async (val) => {
      if (val && props.gateway) {
        resetForm();
        await loadCredentials();
      }
      if (!val) {
        credentials.value = [];
        listError.value = "";
      }
    });
    async function loadCredentials() {
      listLoading.value = true;
      listError.value = "";
      credentials.value = [];
      try {
        const res = await fetch(props.gateway.credentials_url);
        const json = await res.json();
        if (!res.ok) throw new Error(json.message);
        credentials.value = json.data ?? [];
      } catch {
        listError.value = props.t.js_error_load ?? "Erro ao carregar.";
      } finally {
        listLoading.value = false;
      }
    }
    function resetForm() {
      form.value = { label: "", secret: "", webhook_secret: "", valid_from: "", valid_to: "" };
      formError.value = "";
      showSecret.value = false;
      showWebhook.value = false;
    }
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        if (__props.open) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.4)" })}"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ti ti-key me-2"></i>${ssrInterpolate(__props.t.modal_cred_title)} `);
          if (__props.gateway) {
            _push2(`<span class="text-muted fw-normal ms-1">— ${ssrInterpolate(__props.gateway.name)}</span>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</h5><button type="button" class="btn-close"></button></div><div class="modal-body"><div class="alert alert-info d-flex gap-2 align-items-start py-2 mb-4"><i class="ti ti-info-circle flex-shrink-0 mt-1"></i><div class="small">${__props.t.modal_cred_alert ?? ""}</div></div><h6 class="fw-semibold mb-2 small text-uppercase text-muted">${ssrInterpolate(__props.t.modal_cred_history)}</h6>`);
          if (listLoading.value) {
            _push2(`<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>`);
          } else if (listError.value) {
            _push2(`<div class="alert alert-danger small py-2"><i class="ti ti-alert-circle me-1"></i>${ssrInterpolate(listError.value)}</div>`);
          } else if (credentials.value.length === 0) {
            _push2(`<div class="text-center py-3 text-muted small">${ssrInterpolate(__props.t.modal_cred_empty)}</div>`);
          } else {
            _push2(`<div class="mb-4"><!--[-->`);
            ssrRenderList(credentials.value, (c) => {
              _push2(`<div class="d-flex align-items-center justify-content-between py-2 border-bottom gap-3"><div class="flex-grow-1 min-w-0"><div class="fw-semibold small text-truncate">${ssrInterpolate(c.label ?? __props.t.js_no_label)}</div><div class="d-flex flex-wrap gap-1 mt-1"><span class="${ssrRenderClass([c.active ? "badge-soft-success" : "badge-soft-secondary", "badge"])}" style="${ssrRenderStyle({ "font-size": ".7rem" })}">${ssrInterpolate(c.active ? __props.t.modal_cred_active : __props.t.modal_cred_inactive)}</span>`);
              if (c.valid_from) {
                _push2(`<span class="badge badge-soft-info" style="${ssrRenderStyle({ "font-size": ".7rem" })}">${ssrInterpolate(c.valid_from)} → ${ssrInterpolate(c.valid_to ?? "∞")}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<span class="text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}">${ssrInterpolate(c.created_at)}</span></div></div><div class="d-flex align-items-center gap-2 flex-shrink-0"><span class="text-muted fst-italic" style="${ssrRenderStyle({ "font-size": ".75rem" })}">${ssrInterpolate(__props.t.modal_cred_hidden)}</span>`);
              if (c.active) {
                _push2(`<button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="${ssrRenderStyle({ "font-size": ".75rem" })}">${ssrInterpolate(__props.t.modal_cred_revoke)}</button>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div>`);
            });
            _push2(`<!--]--></div>`);
          }
          _push2(`<hr class="my-3"><h6 class="fw-semibold mb-3 small text-uppercase text-muted"><i class="ti ti-plus me-1"></i>${ssrInterpolate(__props.t.modal_cred_new)}</h6><form autocomplete="off"><div class="row g-3"><div class="col-12"><label class="form-label small fw-semibold">${ssrInterpolate(__props.t.modal_cred_label)}</label><input${ssrRenderAttr("value", form.value.label)} type="text" class="form-control form-control-sm"${ssrRenderAttr("placeholder", __props.t.modal_cred_label_ph)}></div><div class="col-12"><label class="form-label small fw-semibold">${ssrInterpolate(secretInfo.value.label)} <span class="text-danger">*</span></label><div class="input-group input-group-sm"><input${ssrRenderDynamicModel(showSecret.value ? "text" : "password", form.value.secret, null)}${ssrRenderAttr("type", showSecret.value ? "text" : "password")} class="form-control font-monospace" autocomplete="new-password" required${ssrRenderAttr("placeholder", __props.t.modal_cred_api_key_ph)}><button type="button" class="btn btn-outline-secondary"><i class="${ssrRenderClass(`ti ${showSecret.value ? "ti-eye-off" : "ti-eye"}`)}"></i></button></div>`);
          if (secretInfo.value.hint) {
            _push2(`<div class="form-text">${ssrInterpolate(secretInfo.value.hint)}</div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div><div class="col-12"><label class="form-label small fw-semibold">${ssrInterpolate(__props.t.modal_cred_webhook)} <span class="text-muted fw-normal">${ssrInterpolate(__props.t.modal_cred_webhook_opt)}</span></label><div class="input-group input-group-sm"><input${ssrRenderDynamicModel(showWebhook.value ? "text" : "password", form.value.webhook_secret, null)}${ssrRenderAttr("type", showWebhook.value ? "text" : "password")} class="form-control font-monospace" autocomplete="new-password"><button type="button" class="btn btn-outline-secondary"><i class="${ssrRenderClass(`ti ${showWebhook.value ? "ti-eye-off" : "ti-eye"}`)}"></i></button></div></div><div class="col-md-6"><label class="form-label small fw-semibold">${ssrInterpolate(__props.t.modal_cred_valid_from)}</label><input${ssrRenderAttr("value", form.value.valid_from)} type="date" class="form-control form-control-sm"></div><div class="col-md-6"><label class="form-label small fw-semibold">${ssrInterpolate(__props.t.modal_cred_valid_to)}</label><input${ssrRenderAttr("value", form.value.valid_to)} type="date" class="form-control form-control-sm"></div></div>`);
          if (formError.value) {
            _push2(`<div class="alert alert-danger mt-3 py-2 small mb-0"><i class="ti ti-alert-circle me-1"></i>${ssrInterpolate(formError.value)}</div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</form></div><div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(__props.t.modal_cred_close)}</button><button type="button" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>`);
          if (saving.value) {
            _push2(`<span class="spinner-border spinner-border-sm me-1"></span>`);
          } else {
            _push2(`<i class="ti ti-device-floppy me-1"></i>`);
          }
          _push2(` ${ssrInterpolate(__props.t.modal_cred_save)}</button></div></div></div></div>`);
        } else {
          _push2(`<!---->`);
        }
        _push2(ssrRenderComponent(_sfc_main$1, {
          open: unref(reasonModal).open,
          title: unref(reasonModal).title,
          message: unref(reasonModal).message,
          "confirm-variant": unref(reasonModal).confirmVariant,
          saving: unref(reasonModal).saving,
          onClose: unref(closeReasonModal),
          onConfirm: unref(handleReasonConfirm)
        }, null, _parent));
      }, "body", false, _parent);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Gateways/GatewayCredentialsModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
