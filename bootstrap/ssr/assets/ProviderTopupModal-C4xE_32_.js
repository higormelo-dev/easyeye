import { ssrRenderTeleport, ssrRenderStyle, ssrInterpolate, ssrIncludeBooleanAttr, ssrRenderList, ssrRenderAttr, ssrLooseEqual, ssrRenderClass } from "vue/server-renderer";
import { ref, watch, computed, useSSRContext } from "vue";
const _sfc_main = {
  __name: "ProviderTopupModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    presetProvider: { type: String, default: "" },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "submit"],
  setup(__props, { expose: __expose, emit: __emit }) {
    const props = __props;
    const PROVIDERS = [
      { value: "openai", label: "ChatGPT", icon: "ti ti-brand-openai" },
      { value: "anthropic", label: "Claude", icon: "ti ti-message-chatbot" },
      { value: "gemini", label: "Gemini", icon: "ti ti-brand-google" }
    ];
    const form = ref({
      provider: "openai",
      amount_usd: 100,
      topped_up_at: (/* @__PURE__ */ new Date()).toISOString().slice(0, 16),
      reference: "",
      note: ""
    });
    const saving = ref(false);
    const errorMessage = ref("");
    watch(() => props.open, (val) => {
      if (val) {
        form.value = {
          provider: props.presetProvider || "openai",
          amount_usd: 100,
          topped_up_at: (/* @__PURE__ */ new Date()).toISOString().slice(0, 16),
          reference: "",
          note: ""
        };
        errorMessage.value = "";
      }
    });
    const isValid = computed(
      () => form.value.provider && Number(form.value.amount_usd) > 0 && form.value.topped_up_at
    );
    function setSaving(value) {
      saving.value = value;
    }
    function setError(msg) {
      errorMessage.value = msg;
    }
    __expose({ setSaving, setError });
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t, _u, _v;
        if (__props.open) {
          _push2(`<div class="modal fade show d-block" style="${ssrRenderStyle({ "background": "rgba(0,0,0,0.45)" })}" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title"><i class="ti ti-receipt-2 me-2 text-info"></i> ${ssrInterpolate(((_b = (_a = __props.t) == null ? void 0 : _a.topup) == null ? void 0 : _b.modal_title) ?? "Registrar recarga no provedor")}</h5><small class="text-muted d-block mt-1">${ssrInterpolate(((_d = (_c = __props.t) == null ? void 0 : _c.topup) == null ? void 0 : _d.modal_subtitle) ?? "Registre o valor que você carregou no painel do provedor. NÃO faz cobrança real — apenas atualiza o saldo estimado.")}</small></div><button type="button" class="btn-close"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}></button></div><form><div class="modal-body">`);
          if (errorMessage.value) {
            _push2(`<div class="alert alert-danger small mb-3 py-2"><i class="ti ti-alert-circle me-1"></i>${ssrInterpolate(errorMessage.value)}</div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`<div class="row g-3"><div class="col-12"><label class="form-label small fw-semibold mb-1">${ssrInterpolate(((_f = (_e = __props.t) == null ? void 0 : _e.topup) == null ? void 0 : _f.provider) ?? "Provedor")} <span class="text-danger">*</span></label><div class="provider-selector d-flex gap-2 flex-wrap" role="group"><!--[-->`);
          ssrRenderList(PROVIDERS, (p) => {
            _push2(`<!--[--><input${ssrRenderAttr("id", `topup-prov-${p.value}`)}${ssrIncludeBooleanAttr(ssrLooseEqual(form.value.provider, p.value)) ? " checked" : ""} type="radio" class="btn-check"${ssrRenderAttr("value", p.value)}${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}><label class="btn btn-outline-primary flex-fill text-nowrap"${ssrRenderAttr("for", `topup-prov-${p.value}`)} style="${ssrRenderStyle({ "min-width": "0" })}"><i class="${ssrRenderClass([p.icon, "me-1"])}"></i> ${ssrInterpolate(p.label)}</label><!--]-->`);
          });
          _push2(`<!--]--></div></div><div class="col-12 col-md-6"><label class="form-label small fw-semibold mb-1">${ssrInterpolate(((_h = (_g = __props.t) == null ? void 0 : _g.topup) == null ? void 0 : _h.amount) ?? "Valor (USD)")} <span class="text-danger">*</span></label><div class="input-group"><span class="input-group-text">$</span><input${ssrRenderAttr("value", form.value.amount_usd)} type="number" step="0.01" min="0.01" max="1000000" class="form-control" required${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}></div></div><div class="col-12 col-md-6"><label class="form-label small fw-semibold mb-1">${ssrInterpolate(((_j = (_i = __props.t) == null ? void 0 : _i.topup) == null ? void 0 : _j.topped_up_at) ?? "Data da recarga")} <span class="text-danger">*</span></label><input${ssrRenderAttr("value", form.value.topped_up_at)} type="datetime-local" class="form-control" required${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}></div><div class="col-12"><label class="form-label small fw-semibold mb-1">${ssrInterpolate(((_l = (_k = __props.t) == null ? void 0 : _k.topup) == null ? void 0 : _l.reference) ?? "Referência (opcional)")}</label><input${ssrRenderAttr("value", form.value.reference)} type="text" maxlength="120" class="form-control"${ssrRenderAttr("placeholder", ((_n = (_m = __props.t) == null ? void 0 : _m.topup) == null ? void 0 : _n.reference_placeholder) ?? "Ex.: ch_3MtIxhXKt8, invoice #INV-001")}${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}><small class="text-muted">${ssrInterpolate(((_p = (_o = __props.t) == null ? void 0 : _o.topup) == null ? void 0 : _p.reference_help) ?? "ID/comprovante da transação no painel do provedor — útil para auditoria.")}</small></div><div class="col-12"><label class="form-label small fw-semibold mb-1">${ssrInterpolate(((_r = (_q = __props.t) == null ? void 0 : _q.topup) == null ? void 0 : _r.note) ?? "Observação (opcional)")}</label><textarea rows="2" maxlength="500" class="form-control"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>${ssrInterpolate(form.value.note)}</textarea></div></div></div><div class="modal-footer"><button type="button" class="btn btn-link text-muted"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>${ssrInterpolate(((_t = (_s = __props.t) == null ? void 0 : _s.topup) == null ? void 0 : _t.cancel) ?? "Cancelar")}</button><button type="submit" class="btn btn-info"${ssrIncludeBooleanAttr(!isValid.value || saving.value) ? " disabled" : ""}><i class="ti ti-check me-1"></i> ${ssrInterpolate(saving.value ? "..." : ((_v = (_u = __props.t) == null ? void 0 : _u.topup) == null ? void 0 : _v.submit) ?? "Registrar recarga")}</button></div></form></div></div></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/AiCreditPurchases/ProviderTopupModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
