import { ssrRenderTeleport, ssrRenderStyle, ssrInterpolate, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrRenderAttr } from "vue/server-renderer";
import { ref, watch, useSSRContext } from "vue";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "SubscriptionActivateModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    subscription: { type: Object, default: null },
    plans: { type: Array, default: () => [] },
    billingCycles: { type: Array, default: () => [] },
    gateways: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "saved"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const saving = ref(false);
    const error = ref("");
    const form = ref({ plan_id: "", billing_cycle: "", gateway: "" });
    watch(() => props.open, (val) => {
      var _a;
      if (val) {
        form.value = { plan_id: "", billing_cycle: ((_a = props.billingCycles[0]) == null ? void 0 : _a.value) ?? "", gateway: "" };
        error.value = "";
      }
    });
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        var _a;
        if (__props.open) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.4)" })}"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ti ti-player-play me-2 text-success"></i>${ssrInterpolate(__props.t.activate_title)}</h5><button type="button" class="btn-close"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label fw-semibold">${ssrInterpolate(__props.t.activate_company)}</label><p class="form-control-plaintext fw-bold mb-0">${ssrInterpolate(((_a = __props.subscription) == null ? void 0 : _a.entity_name) ?? "—")}</p></div><div class="mb-3"><label class="form-label">${ssrInterpolate(__props.t.activate_plan)}</label><select class="form-select" required><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.plan_id) ? ssrLooseContain(form.value.plan_id, "") : ssrLooseEqual(form.value.plan_id, "")) ? " selected" : ""}>${ssrInterpolate(__props.t.activate_plan_select)}</option><!--[-->`);
          ssrRenderList(__props.plans, (p) => {
            _push2(`<option${ssrRenderAttr("value", p.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.plan_id) ? ssrLooseContain(form.value.plan_id, p.id) : ssrLooseEqual(form.value.plan_id, p.id)) ? " selected" : ""}>${ssrInterpolate(p.name)}${ssrInterpolate(p.price ? ` — ${p.price}` : "")}</option>`);
          });
          _push2(`<!--]--></select></div><div class="mb-3"><label class="form-label">${ssrInterpolate(__props.t.activate_cycle)}</label><select class="form-select" required><!--[-->`);
          ssrRenderList(__props.billingCycles, (c) => {
            _push2(`<option${ssrRenderAttr("value", c.value)}${ssrIncludeBooleanAttr(Array.isArray(form.value.billing_cycle) ? ssrLooseContain(form.value.billing_cycle, c.value) : ssrLooseEqual(form.value.billing_cycle, c.value)) ? " selected" : ""}>${ssrInterpolate(c.label)}</option>`);
          });
          _push2(`<!--]--></select></div><div class="mb-3"><label class="form-label">${ssrInterpolate(__props.t.activate_gateway)}</label><select class="form-select"><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.gateway) ? ssrLooseContain(form.value.gateway, "") : ssrLooseEqual(form.value.gateway, "")) ? " selected" : ""}>${ssrInterpolate(__props.t.activate_gateway_default)}</option><!--[-->`);
          ssrRenderList(__props.gateways, (g) => {
            _push2(`<option${ssrRenderAttr("value", g.value)}${ssrIncludeBooleanAttr(Array.isArray(form.value.gateway) ? ssrLooseContain(form.value.gateway, g.value) : ssrLooseEqual(form.value.gateway, g.value)) ? " selected" : ""}>${ssrInterpolate(g.label)}</option>`);
          });
          _push2(`<!--]--></select><div class="form-text">${ssrInterpolate(__props.t.activate_gateway_hint)}</div></div>`);
          if (error.value) {
            _push2(`<div class="alert alert-danger py-2 small mb-0"><i class="ti ti-alert-circle me-1"></i>${ssrInterpolate(error.value)}</div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div><div class="modal-footer"><button type="button" class="btn btn-secondary">${ssrInterpolate(__props.t.btn_cancel)}</button><button type="button" class="btn btn-success"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>`);
          if (saving.value) {
            _push2(`<span class="spinner-border spinner-border-sm me-1"></span>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(` ${ssrInterpolate(__props.t.activate_btn)}</button></div></div></div></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Subscriptions/SubscriptionActivateModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
