import { ref, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderStyle, ssrInterpolate, ssrRenderClass, ssrIncludeBooleanAttr, ssrRenderAttr } from "vue/server-renderer";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "GatewayCard",
  __ssrInlineRender: true,
  props: {
    gateway: { type: Object, required: true },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["open-credentials", "open-entity-access", "open-priority", "open-set-default"],
  setup(__props, { emit: __emit }) {
    const toggling = ref(false);
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({
        class: ["card h-100", { "opacity-75": !__props.gateway.active }],
        style: __props.gateway.is_default ? "border:2px solid #fdd835 !important;" : ""
      }, _attrs))}><div class="card-header d-flex align-items-center justify-content-between py-2 px-3" style="${ssrRenderStyle(__props.gateway.is_default ? "background:linear-gradient(135deg,#fff8e1,#fffde7);" : "")}"><div class="d-flex align-items-center gap-2 flex-wrap min-w-0">`);
      if (__props.gateway.is_default) {
        _push(`<i class="ti ti-star-filled flex-shrink-0" style="${ssrRenderStyle({ "color": "#f9a825" })}"></i>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<span class="fw-bold text-truncate">${ssrInterpolate(__props.gateway.name)}</span><span class="badge badge-soft-secondary text-uppercase flex-shrink-0" style="${ssrRenderStyle({ "font-size": ".7rem", "letter-spacing": ".04em" })}">${ssrInterpolate(__props.gateway.code)}</span>`);
      if (__props.gateway.is_default) {
        _push(`<span class="badge flex-shrink-0" style="${ssrRenderStyle({ "background": "#fdd835", "color": "#5d4037", "font-size": ".7rem" })}">${ssrInterpolate(__props.t.default_badge)}</span>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div><div class="d-flex align-items-center gap-2 flex-shrink-0"><span class="${ssrRenderClass([__props.gateway.active ? "badge-soft-success" : "badge-soft-secondary", "badge"])}" style="${ssrRenderStyle({ "font-size": ".72rem" })}">${ssrInterpolate(__props.gateway.active ? __props.t.status_active : __props.t.status_inactive)}</span><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch"${ssrIncludeBooleanAttr(__props.gateway.active) ? " checked" : ""}${ssrIncludeBooleanAttr(toggling.value) ? " disabled" : ""}${ssrRenderAttr("title", __props.gateway.active ? __props.t.toggle_deactivate : __props.t.toggle_activate)}></div></div></div><div class="card-body py-3 px-3"><div class="d-flex align-items-center gap-2 mb-3"><span class="text-muted small">${ssrInterpolate(__props.t.priority_label)}</span><span class="badge badge-soft-info"><i class="ti ti-sort-ascending me-1"></i>${ssrInterpolate(__props.gateway.priority)}</span><button type="button" class="btn btn-link btn-sm p-0 text-muted" style="${ssrRenderStyle({ "font-size": ".78rem" })}">${ssrInterpolate(__props.t.priority_change)}</button></div><div class="d-flex align-items-center justify-content-between mb-2"><div class="d-flex align-items-center gap-2"><i class="ti ti-key text-muted" style="${ssrRenderStyle({ "font-size": ".95rem" })}"></i><span class="small text-muted">${ssrInterpolate(__props.t.billing_credentials)}</span></div>`);
      if (__props.gateway.credentials_label) {
        _push(`<span class="badge badge-soft-success" style="${ssrRenderStyle({ "font-size": ".72rem" })}"><i class="ti ti-check me-1"></i>${ssrInterpolate(__props.gateway.credentials_label)}</span>`);
      } else {
        _push(`<span class="badge badge-soft-warning" style="${ssrRenderStyle({ "font-size": ".72rem" })}"><i class="ti ti-alert-triangle me-1"></i>${ssrInterpolate(__props.t.credentials_none)}</span>`);
      }
      _push(`</div><div class="d-flex align-items-center justify-content-between mb-3"><div class="d-flex align-items-center gap-2"><i class="ti ti-building-hospital text-muted" style="${ssrRenderStyle({ "font-size": ".95rem" })}"></i><span class="small text-muted">${ssrInterpolate(__props.t.clinics_with_access)}</span></div>`);
      if (__props.gateway.clinics_label) {
        _push(`<span class="badge badge-soft-primary" style="${ssrRenderStyle({ "font-size": ".72rem" })}">${ssrInterpolate(__props.gateway.clinics_label)}</span>`);
      } else {
        _push(`<span class="badge badge-soft-secondary" style="${ssrRenderStyle({ "font-size": ".72rem" })}">${ssrInterpolate(__props.t.clinics_none)}</span>`);
      }
      _push(`</div><div class="d-flex flex-wrap gap-1">`);
      if (__props.gateway.supports_subscriptions) {
        _push(`<span class="badge badge-soft-success" style="${ssrRenderStyle({ "font-size": ".7rem" })}"><i class="ti ti-refresh me-1"></i>${ssrInterpolate(__props.t.cap_subscriptions)}</span>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.gateway.supports_one_time_charges) {
        _push(`<span class="badge badge-soft-success" style="${ssrRenderStyle({ "font-size": ".7rem" })}"><i class="ti ti-bolt me-1"></i>${ssrInterpolate(__props.t.cap_one_time)}</span>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.gateway.supports_refunds) {
        _push(`<span class="badge badge-soft-success" style="${ssrRenderStyle({ "font-size": ".7rem" })}"><i class="ti ti-arrow-back me-1"></i>${ssrInterpolate(__props.t.cap_refunds)}</span>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.gateway.supports_webhooks) {
        _push(`<span class="badge badge-soft-success" style="${ssrRenderStyle({ "font-size": ".7rem" })}"><i class="ti ti-webhook me-1"></i>${ssrInterpolate(__props.t.cap_webhooks)}</span>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div></div><div class="card-footer bg-transparent py-2 px-3"><div class="d-flex gap-2 mb-2"><button type="button" class="btn btn-sm btn-outline-primary flex-grow-1"><i class="ti ti-key me-1"></i>${ssrInterpolate(__props.t.btn_credentials)}</button><button type="button" class="btn btn-sm btn-outline-success flex-grow-1"><i class="ti ti-building-hospital me-1"></i>${ssrInterpolate(__props.t.btn_entity_access)}</button></div>`);
      if (!__props.gateway.is_default) {
        _push(`<button type="button" class="${ssrRenderClass([__props.gateway.can_be_default ? "btn-outline-warning" : "btn-outline-secondary", "btn btn-sm w-100"])}"${ssrIncludeBooleanAttr(!__props.gateway.can_be_default) ? " disabled" : ""}${ssrRenderAttr("title", !__props.gateway.active ? __props.t.btn_activate_first : !__props.gateway.credentials_label ? __props.t.btn_add_credential : __props.t.btn_set_default_title)}><i class="ti ti-star me-1"></i>${ssrInterpolate(__props.t.btn_set_default)}</button>`);
      } else {
        _push(`<button type="button" class="btn btn-sm w-100 btn-outline-secondary" disabled style="${ssrRenderStyle({ "border-color": "#fdd835", "color": "#f9a825" })}"><i class="ti ti-star-filled me-1"></i>${ssrInterpolate(__props.t.btn_current_default)}</button>`);
      }
      _push(`</div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Gateways/GatewayCard.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
