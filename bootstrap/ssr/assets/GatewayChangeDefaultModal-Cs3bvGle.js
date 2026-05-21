import { ssrRenderTeleport, ssrRenderStyle, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { ref, useSSRContext } from "vue";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "GatewayChangeDefaultModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    gateways: { type: Array, default: () => [] },
    defaultGateway: { type: Object, default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const saving = ref(null);
    function isDefault(g) {
      var _a;
      return ((_a = props.defaultGateway) == null ? void 0 : _a.id) === g.id;
    }
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        if (__props.open) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.4)" })}"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ti ti-star me-2 text-warning"></i>${ssrInterpolate(__props.t.modal_default_title)}</h5><button type="button" class="btn-close"></button></div><div class="modal-body"><div class="alert alert-warning d-flex gap-2 align-items-start py-2 mb-4"><i class="ti ti-alert-triangle flex-shrink-0 mt-1"></i><div class="small">${__props.t.modal_default_alert ?? ""}</div></div><div class="list-group list-group-flush"><!--[-->`);
          ssrRenderList(__props.gateways, (g) => {
            _push2(`<div class="${ssrRenderClass([{ "opacity-50": !g.can_be_default }, "list-group-item list-group-item-action d-flex align-items-center gap-3 px-0 py-3"])}" style="${ssrRenderStyle({ "border-left": "0", "border-right": "0" })}"><div class="flex-grow-1"><div class="d-flex align-items-center gap-2 flex-wrap">`);
            if (isDefault(g)) {
              _push2(`<i class="ti ti-star-filled" style="${ssrRenderStyle({ "color": "#f9a825" })}"></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<span class="fw-semibold small">${ssrInterpolate(g.name)}</span><span class="badge badge-soft-secondary text-uppercase" style="${ssrRenderStyle({ "font-size": ".68rem" })}">${ssrInterpolate(g.code)}</span>`);
            if (isDefault(g)) {
              _push2(`<span class="badge" style="${ssrRenderStyle({ "background": "#fdd835", "color": "#5d4037", "font-size": ".68rem" })}">${ssrInterpolate(__props.t.modal_default_current)}</span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="d-flex gap-1 mt-1">`);
            if (!g.active) {
              _push2(`<span class="badge badge-soft-secondary" style="${ssrRenderStyle({ "font-size": ".68rem" })}">${ssrInterpolate(__props.t.status_inactive)}</span>`);
            } else {
              _push2(`<!---->`);
            }
            if (g.credentials_label) {
              _push2(`<span class="badge badge-soft-success" style="${ssrRenderStyle({ "font-size": ".68rem" })}">${ssrInterpolate(g.credentials_label)}</span>`);
            } else {
              _push2(`<span class="badge badge-soft-warning" style="${ssrRenderStyle({ "font-size": ".68rem" })}">${ssrInterpolate(__props.t.credentials_none)}</span>`);
            }
            _push2(`</div></div><div class="flex-shrink-0">`);
            if (isDefault(g)) {
              _push2(`<button class="btn btn-sm" style="${ssrRenderStyle({ "background": "#fdd835", "color": "#5d4037", "border": "none" })}" disabled><i class="ti ti-check me-1"></i>${ssrInterpolate(__props.t.default_badge)}</button>`);
            } else if (g.can_be_default) {
              _push2(`<button type="button" class="btn btn-sm btn-outline-warning"${ssrIncludeBooleanAttr(saving.value === g.id) ? " disabled" : ""}>`);
              if (saving.value === g.id) {
                _push2(`<span class="spinner-border spinner-border-sm me-1"></span>`);
              } else {
                _push2(`<i class="ti ti-star me-1"></i>`);
              }
              _push2(`${ssrInterpolate(__props.t.modal_default_btn)}</button>`);
            } else {
              _push2(`<button class="btn btn-sm btn-outline-secondary" disabled>${ssrInterpolate(__props.t.modal_default_unavailable)}</button>`);
            }
            _push2(`</div></div>`);
          });
          _push2(`<!--]--></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm">${ssrInterpolate(__props.t.modal_default_close)}</button></div></div></div></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Gateways/GatewayChangeDefaultModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
