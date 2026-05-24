import { ssrRenderTeleport, ssrRenderStyle, ssrInterpolate, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrRenderAttr, ssrRenderClass } from "vue/server-renderer";
import { ref, watch, computed, useSSRContext } from "vue";
const _sfc_main = {
  __name: "ManualCreditModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    entities: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
    permissions: { type: Object, default: () => ({}) },
    presetEntityId: { type: String, default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "submit"],
  setup(__props, { expose: __expose, emit: __emit }) {
    const props = __props;
    const form = ref({
      entity_id: "",
      provider: "",
      // opcional — só metadata analítica
      credits: 100,
      amount_cents: 0,
      reason: "",
      package_code: "manual"
    });
    const saving = ref(false);
    const errorMessage = ref("");
    watch(() => props.open, (val) => {
      if (val) {
        form.value = {
          entity_id: props.presetEntityId ?? "",
          provider: "",
          credits: 100,
          amount_cents: 0,
          reason: "",
          package_code: "manual"
        };
        errorMessage.value = "";
      }
    });
    const filteredEntities = computed(() => {
      var _a;
      if ((_a = props.permissions) == null ? void 0 : _a.create_manual_for_internal) return props.entities;
      return props.entities.filter((e) => e.is_client);
    });
    const selectedEntityIsInternal = computed(() => {
      const e = props.entities.find((x) => x.id === form.value.entity_id);
      return e ? !e.is_client : false;
    });
    const isValid = computed(
      () => form.value.entity_id && form.value.credits > 0 && form.value.reason.trim().length >= 10
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
        var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t, _u, _v, _w, _x, _y, _z, _A, _B, _C, _D, _E, _F, _G, _H;
        if (__props.open) {
          _push2(`<div class="modal fade show d-block" style="${ssrRenderStyle({ "background": "rgba(0,0,0,0.45)" })}" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title"><i class="ti ti-coin-plus me-2 text-success"></i> ${ssrInterpolate(((_b = (_a = __props.t) == null ? void 0 : _a.manual) == null ? void 0 : _b.modal_title) ?? "Adicionar crédito manual")}</h5><small class="text-muted d-block mt-1">${ssrInterpolate(((_d = (_c = __props.t) == null ? void 0 : _c.manual) == null ? void 0 : _d.modal_subtitle) ?? "")}</small></div><button type="button" class="btn-close"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}></button></div><form><div class="modal-body">`);
          if (!((_e = __props.permissions) == null ? void 0 : _e.create_manual_unlimited)) {
            _push2(`<div class="alert alert-warning d-flex align-items-start gap-2 small mb-3 py-2"><i class="ti ti-shield-half-filled mt-1"></i><div>${ssrInterpolate((((_g = (_f = __props.t) == null ? void 0 : _f.manual) == null ? void 0 : _g.limit_warning) ?? "Limite diário: :limit créditos.").replace(":limit", ((_h = __props.permissions) == null ? void 0 : _h.support_daily_limit) ?? "?").replace(":used", "—"))}</div></div>`);
          } else {
            _push2(`<!---->`);
          }
          if (errorMessage.value) {
            _push2(`<div class="alert alert-danger small mb-3 py-2"><i class="ti ti-alert-circle me-1"></i>${ssrInterpolate(errorMessage.value)}</div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`<div class="row g-3"><div class="col-12"><label class="form-label small fw-semibold mb-1">${ssrInterpolate(((_j = (_i = __props.t) == null ? void 0 : _i.manual) == null ? void 0 : _j.select_entity) ?? "Empresa destinatária")} <span class="text-danger">*</span></label><select class="form-select" required${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}><option value="" disabled${ssrIncludeBooleanAttr(Array.isArray(form.value.entity_id) ? ssrLooseContain(form.value.entity_id, "") : ssrLooseEqual(form.value.entity_id, "")) ? " selected" : ""}>—</option><!--[-->`);
          ssrRenderList(filteredEntities.value, (e) => {
            _push2(`<option${ssrRenderAttr("value", e.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.entity_id) ? ssrLooseContain(form.value.entity_id, e.id) : ssrLooseEqual(form.value.entity_id, e.id)) ? " selected" : ""}>${ssrInterpolate(e.name)}${ssrInterpolate(!e.is_client ? " ★" : "")}</option>`);
          });
          _push2(`<!--]--></select><small class="text-muted">${ssrInterpolate(((_l = (_k = __props.t) == null ? void 0 : _k.manual) == null ? void 0 : _l.select_entity_help) ?? "")}</small>`);
          if (selectedEntityIsInternal.value) {
            _push2(`<div class="mt-2"><span class="badge bg-primary-subtle text-primary border border-primary border-opacity-25"><i class="ti ti-building me-1"></i> ${ssrInterpolate(((_n = (_m = __props.t) == null ? void 0 : _m.manual) == null ? void 0 : _n.badge_internal) ?? "Sua empresa")}</span></div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div><div class="col-12 col-md-6"><label class="form-label small fw-semibold mb-1">${ssrInterpolate(((_p = (_o = __props.t) == null ? void 0 : _o.manual) == null ? void 0 : _p.select_provider) ?? "Provedor (opcional)")}</label><input id="prov-none"${ssrIncludeBooleanAttr(ssrLooseEqual(form.value.provider, "")) ? " checked" : ""} type="radio" class="btn-check" value=""${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}><label class="btn btn-outline-secondary w-100 text-nowrap mb-2" for="prov-none"><i class="ti ti-asterisk me-1"></i>${ssrInterpolate(((_r = (_q = __props.t) == null ? void 0 : _q.manual) == null ? void 0 : _r.no_provider) ?? "Sem preferência")}</label><div class="d-flex gap-2" role="group"><!--[-->`);
          ssrRenderList(__props.providers, (p) => {
            _push2(`<!--[--><input${ssrRenderAttr("id", `prov-${p.value}`)}${ssrIncludeBooleanAttr(ssrLooseEqual(form.value.provider, p.value)) ? " checked" : ""} type="radio" class="btn-check"${ssrRenderAttr("value", p.value)}${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}><label class="btn btn-outline-primary flex-fill text-nowrap"${ssrRenderAttr("for", `prov-${p.value}`)} style="${ssrRenderStyle({ "min-width": "0", "flex-basis": "0" })}"><i class="${ssrRenderClass([{
              "ti ti-brand-openai": p.value === "openai",
              "ti ti-message-chatbot": p.value === "anthropic",
              "ti ti-brand-google": p.value === "gemini"
            }, "me-1"])}"></i> ${ssrInterpolate(p.label)}</label><!--]-->`);
          });
          _push2(`<!--]--></div><small class="text-muted d-block mt-1">${ssrInterpolate(((_t = (_s = __props.t) == null ? void 0 : _s.manual) == null ? void 0 : _t.select_provider_help) ?? "Apenas etiqueta — créditos vão para o saldo único.")}</small></div><div class="col-12 col-md-6"><label class="form-label small fw-semibold mb-1">${ssrInterpolate(((_v = (_u = __props.t) == null ? void 0 : _u.manual) == null ? void 0 : _v.credits) ?? "Créditos")} <span class="text-danger">*</span></label><input${ssrRenderAttr("value", form.value.credits)} type="number" min="1" max="1000000" class="form-control" required${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}></div><div class="col-12 col-md-6"><label class="form-label small fw-semibold mb-1">${ssrInterpolate(((_x = (_w = __props.t) == null ? void 0 : _w.manual) == null ? void 0 : _x.amount_cents) ?? "Valor (centavos)")}</label><input${ssrRenderAttr("value", form.value.amount_cents)} type="number" min="0" max="9999999" class="form-control"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}><small class="text-muted">${ssrInterpolate(((_z = (_y = __props.t) == null ? void 0 : _y.manual) == null ? void 0 : _z.amount_help) ?? "")}</small></div><div class="col-12 col-md-6"><label class="form-label small fw-semibold mb-1"> Código (opcional) </label><input${ssrRenderAttr("value", form.value.package_code)} type="text" maxlength="50" class="form-control" placeholder="manual"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}></div><div class="col-12"><label class="form-label small fw-semibold mb-1">${ssrInterpolate(((_B = (_A = __props.t) == null ? void 0 : _A.manual) == null ? void 0 : _B.reason) ?? "Motivo")} <span class="text-danger">*</span></label><textarea rows="3" minlength="10" maxlength="500" class="form-control" required${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>${ssrInterpolate(form.value.reason)}</textarea><small class="text-muted d-flex justify-content-between"><span>${ssrInterpolate(((_D = (_C = __props.t) == null ? void 0 : _C.manual) == null ? void 0 : _D.reason_help) ?? "")}</span><span>${ssrInterpolate(form.value.reason.length)}/500</span></small></div></div></div><div class="modal-footer"><button type="button" class="btn btn-link text-muted"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>${ssrInterpolate(((_F = (_E = __props.t) == null ? void 0 : _E.manual) == null ? void 0 : _F.cancel) ?? "Cancelar")}</button><button type="submit" class="btn btn-success"${ssrIncludeBooleanAttr(!isValid.value || saving.value) ? " disabled" : ""}><i class="ti ti-coin-plus me-1"></i> ${ssrInterpolate(saving.value ? "..." : ((_H = (_G = __props.t) == null ? void 0 : _G.manual) == null ? void 0 : _H.submit) ?? "Lançar crédito")}</button></div></form></div></div></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/AiCreditPurchases/ManualCreditModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
