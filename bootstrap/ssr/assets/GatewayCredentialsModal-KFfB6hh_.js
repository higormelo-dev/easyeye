import { ssrRenderTeleport, ssrRenderStyle, ssrInterpolate, ssrRenderList, ssrRenderAttr, ssrRenderDynamicModel, ssrRenderClass, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { ref, watch, useSSRContext } from "vue";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "GatewayCredentialsModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    gateway: { type: Object, default: null }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const credentials = ref([]);
    const listLoading = ref(false);
    const listError = ref("");
    const form = ref({ label: "", secret: "", webhook_secret: "", valid_from: "", valid_to: "" });
    const saving = ref(false);
    const formError = ref("");
    const showSecret = ref(false);
    const showWebhook = ref(false);
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
        const res = await fetch(props.gateway.credentials_url, { headers: { Accept: "application/json" } });
        const json = await res.json();
        if (!res.ok) throw new Error(json.message ?? "");
        credentials.value = json.data ?? [];
      } catch {
        listError.value = "Erro ao carregar credenciais.";
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
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.4)" })}"><div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ti ti-key me-2"></i>Credenciais `);
          if (__props.gateway) {
            _push2(`<span class="text-muted fw-normal ms-1">— ${ssrInterpolate(__props.gateway.name)}</span>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</h5><button type="button" class="btn-close"></button></div><div class="modal-body"><div class="mb-4"><h6 class="fw-semibold mb-3">Credenciais ativas</h6>`);
          if (listLoading.value) {
            _push2(`<div class="text-center py-3 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Carregando... </div>`);
          } else if (listError.value) {
            _push2(`<div class="alert alert-danger small">${ssrInterpolate(listError.value)}</div>`);
          } else if (credentials.value.length === 0) {
            _push2(`<div class="text-center py-3 text-muted small"><i class="ti ti-key-off d-block fs-3 mb-1 opacity-25"></i> Nenhuma credencial cadastrada. </div>`);
          } else {
            _push2(`<table class="table table-sm table-hover mb-0"><thead class="table-light"><tr><th>Label</th><th>Status</th><th>Validade</th><th>Criada em</th><th class="text-end">Ações</th></tr></thead><tbody><!--[-->`);
            ssrRenderList(credentials.value, (c) => {
              _push2(`<tr><td class="fw-medium">${ssrInterpolate(c.label)}</td><td>`);
              if (c.active) {
                _push2(`<span class="badge badge-soft-success rounded text-success border border-success fs-11">Ativa</span>`);
              } else {
                _push2(`<span class="badge badge-soft-secondary rounded fs-11">Revogada</span>`);
              }
              _push2(`</td><td class="small text-muted">${ssrInterpolate(c.valid_from || "—")} → ${ssrInterpolate(c.valid_to || "—")}</td><td class="small text-muted">${ssrInterpolate(c.created_at)}</td><td class="text-end">`);
              if (c.active) {
                _push2(`<button type="button" class="btn btn-sm btn-outline-danger" title="Revogar"><i class="ti ti-x"></i></button>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table>`);
          }
          _push2(`</div><div class="border-top pt-3"><h6 class="fw-semibold mb-3">Nova credencial</h6><div class="alert alert-warning small"><i class="ti ti-info-circle me-1"></i> Salvar uma nova credencial REVOGA a anterior automaticamente. O secret só é armazenado criptografado — não é exibido em consultas futuras. </div>`);
          if (formError.value) {
            _push2(`<div class="alert alert-danger small">${ssrInterpolate(formError.value)}</div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`<div class="row g-3"><div class="col-md-6"><label class="form-label small">Label</label><input${ssrRenderAttr("value", form.value.label)} type="text" class="form-control form-control-sm" maxlength="120" placeholder="Ex: Produção 2026"></div><div class="col-md-6"><label class="form-label small">Secret / API Key <span class="text-danger">*</span></label><div class="input-group input-group-sm"><input${ssrRenderDynamicModel(showSecret.value ? "text" : "password", form.value.secret, null)}${ssrRenderAttr("type", showSecret.value ? "text" : "password")} class="form-control" required><button type="button" class="btn btn-outline-secondary" tabindex="-1"><i class="${ssrRenderClass(showSecret.value ? "ti ti-eye-off" : "ti ti-eye")}"></i></button></div></div><div class="col-md-12"><label class="form-label small">Webhook Secret (opcional)</label><div class="input-group input-group-sm"><input${ssrRenderDynamicModel(showWebhook.value ? "text" : "password", form.value.webhook_secret, null)}${ssrRenderAttr("type", showWebhook.value ? "text" : "password")} class="form-control"><button type="button" class="btn btn-outline-secondary" tabindex="-1"><i class="${ssrRenderClass(showWebhook.value ? "ti ti-eye-off" : "ti ti-eye")}"></i></button></div></div><div class="col-md-6"><label class="form-label small">Válida de</label><input${ssrRenderAttr("value", form.value.valid_from)} type="date" class="form-control form-control-sm"></div><div class="col-md-6"><label class="form-label small">Válida até</label><input${ssrRenderAttr("value", form.value.valid_to)} type="date" class="form-control form-control-sm"></div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm">Fechar</button><button type="button" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>`);
          if (saving.value) {
            _push2(`<span class="spinner-border spinner-border-sm me-1"></span>`);
          } else {
            _push2(`<i class="ti ti-device-floppy me-1"></i>`);
          }
          _push2(`Salvar credencial </button></div></div></div></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Settings/Gateways/GatewayCredentialsModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
