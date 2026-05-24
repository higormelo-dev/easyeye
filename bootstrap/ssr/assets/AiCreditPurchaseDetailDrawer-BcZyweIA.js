import { ssrRenderTeleport, ssrRenderStyle, ssrInterpolate, ssrRenderClass, ssrRenderList } from "vue/server-renderer";
import { ref, watch, computed, useSSRContext } from "vue";
const _sfc_main = {
  __name: "AiCreditPurchaseDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    purchase: { type: Object, default: null },
    permissions: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "credit", "cancel", "fail", "refund"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const tab = ref("info");
    const detail = ref(null);
    const loading = ref(false);
    const errorMsg = ref("");
    watch(() => props.open, async (val) => {
      var _a, _b;
      if (val && ((_b = (_a = props.purchase) == null ? void 0 : _a.actions_url) == null ? void 0 : _b.show)) {
        await loadDetail();
      } else {
        detail.value = null;
        tab.value = "info";
      }
    });
    async function loadDetail() {
      loading.value = true;
      errorMsg.value = "";
      try {
        const res = await fetch(props.purchase.actions_url.show, {
          headers: { "Accept": "application/json" }
        });
        if (!res.ok) throw new Error(`Erro ${res.status}`);
        const json = await res.json();
        detail.value = json.purchase;
      } catch (e) {
        errorMsg.value = e.message;
      } finally {
        loading.value = false;
      }
    }
    const events = computed(() => {
      var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j;
      if (!detail.value) return [];
      const items = [];
      if (detail.value.created_at) {
        items.push({
          label: ((_b = (_a = props.t) == null ? void 0 : _a.timeline) == null ? void 0 : _b.created) ?? "Pedido criado",
          at: detail.value.created_at,
          icon: "ti ti-plus",
          color: "text-secondary"
        });
      }
      if (detail.value.credited_at) {
        items.push({
          label: ((_d = (_c = props.t) == null ? void 0 : _c.timeline) == null ? void 0 : _d.credited) ?? "Creditado",
          at: detail.value.credited_at,
          icon: "ti ti-check",
          color: "text-success"
        });
      }
      if (detail.value.cancelled_at) {
        items.push({
          label: ((_f = (_e = props.t) == null ? void 0 : _e.timeline) == null ? void 0 : _f.cancelled) ?? "Cancelado",
          at: detail.value.cancelled_at,
          icon: "ti ti-x",
          color: "text-secondary"
        });
      }
      if (detail.value.failed_at) {
        items.push({
          label: ((_h = (_g = props.t) == null ? void 0 : _g.timeline) == null ? void 0 : _h.failed) ?? "Falha de gateway",
          at: detail.value.failed_at,
          icon: "ti ti-alert-triangle",
          color: "text-danger"
        });
      }
      if (detail.value.refunded_at) {
        items.push({
          label: ((_j = (_i = props.t) == null ? void 0 : _i.timeline) == null ? void 0 : _j.refunded) ?? "Reembolsado",
          at: detail.value.refunded_at,
          icon: "ti ti-arrow-back-up",
          color: "text-info"
        });
      }
      return items;
    });
    const prettyMetadata = computed(() => {
      var _a;
      if (!((_a = detail.value) == null ? void 0 : _a.metadata)) return "";
      try {
        return JSON.stringify(detail.value.metadata, null, 2);
      } catch {
        return "";
      }
    });
    const allowed = computed(() => {
      var _a, _b;
      return ((_a = detail.value) == null ? void 0 : _a.allowed) ?? ((_b = props.purchase) == null ? void 0 : _b.allowed) ?? {};
    });
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t, _u, _v, _w, _x, _y, _z, _A, _B, _C, _D, _E, _F, _G, _H, _I, _J, _K, _L, _M, _N, _O, _P, _Q, _R;
        if (__props.open) {
          _push2(`<div class="modal fade show d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.5)" })}"><div class="modal-dialog modal-lg modal-dialog-scrollable" style="${ssrRenderStyle({ "max-width": "720px" })}"><div class="modal-content"><div class="modal-header py-2"><h6 class="modal-title"><i class="ti ti-coin text-warning me-1"></i> Pedido de compra de créditos `);
          if (__props.purchase) {
            _push2(`<small class="text-muted">${ssrInterpolate((_a = __props.purchase.id) == null ? void 0 : _a.slice(0, 8))}</small>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</h6><button type="button" class="btn-close"></button></div><div class="modal-body p-0"><div class="px-3 py-2 border-bottom bg-light"><div class="d-flex justify-content-between align-items-start"><div><div class="fw-bold">${ssrInterpolate(((_b = __props.purchase) == null ? void 0 : _b.entity_name) ?? "—")}</div><small class="text-muted">${ssrInterpolate((_c = __props.purchase) == null ? void 0 : _c.package_name)} (${ssrInterpolate((_d = __props.purchase) == null ? void 0 : _d.package_code)})</small></div><div class="text-end"><div class="h5 mb-0">${ssrInterpolate((_e = __props.purchase) == null ? void 0 : _e.amount_formatted)}</div><small class="text-muted">${ssrInterpolate((_g = (_f = __props.purchase) == null ? void 0 : _f.credits) == null ? void 0 : _g.toLocaleString("pt-BR"))} créditos</small></div></div><div class="mt-2"><span class="${ssrRenderClass([(_h = __props.purchase) == null ? void 0 : _h.status_badge, "badge"])}">${ssrInterpolate((_i = __props.purchase) == null ? void 0 : _i.status_label)}</span></div></div><ul class="nav nav-tabs px-3 pt-2"><li class="nav-item"><button class="${ssrRenderClass([{ active: tab.value === "info" }, "nav-link"])}"><i class="ti ti-info-circle me-1"></i>${ssrInterpolate(((_k = (_j = __props.t) == null ? void 0 : _j.detail) == null ? void 0 : _k.tab_info) ?? "Informações")}</button></li><li class="nav-item"><button class="${ssrRenderClass([{ active: tab.value === "timeline" }, "nav-link"])}"><i class="ti ti-history me-1"></i>${ssrInterpolate(((_m = (_l = __props.t) == null ? void 0 : _l.detail) == null ? void 0 : _m.tab_timeline) ?? "Linha do tempo")}</button></li><li class="nav-item"><button class="${ssrRenderClass([{ active: tab.value === "metadata" }, "nav-link"])}"><i class="ti ti-code me-1"></i>${ssrInterpolate(((_o = (_n = __props.t) == null ? void 0 : _n.detail) == null ? void 0 : _o.tab_metadata) ?? "Metadados")}</button></li></ul><div class="p-3">`);
          if (loading.value) {
            _push2(`<div class="text-center text-muted py-4"><i class="ti ti-loader ti-rotate"></i> Carregando… </div>`);
          } else if (errorMsg.value) {
            _push2(`<div class="alert alert-danger small">${ssrInterpolate(errorMsg.value)}</div>`);
          } else if (tab.value === "info") {
            _push2(`<div class="small"><dl class="row mb-0"><dt class="col-5 text-muted">Solicitado em</dt><dd class="col-7">${ssrInterpolate(((_p = __props.purchase) == null ? void 0 : _p.created_at) ?? "—")}</dd><dt class="col-5 text-muted">Solicitante</dt><dd class="col-7">${ssrInterpolate(((_q = __props.purchase) == null ? void 0 : _q.requested_by) ?? "—")} `);
            if ((_r = __props.purchase) == null ? void 0 : _r.requested_email) {
              _push2(`<div class="text-muted small">${ssrInterpolate(__props.purchase.requested_email)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</dd><dt class="col-5 text-muted">${ssrInterpolate(((_t = (_s = __props.t) == null ? void 0 : _s.detail) == null ? void 0 : _t.package_code) ?? "Código do pacote")}</dt><dd class="col-7"><code>${ssrInterpolate((_u = __props.purchase) == null ? void 0 : _u.package_code)}</code></dd>`);
            if ((_v = detail.value) == null ? void 0 : _v.subscription) {
              _push2(`<dt class="col-5 text-muted">${ssrInterpolate(((_x = (_w = __props.t) == null ? void 0 : _w.detail) == null ? void 0 : _x.subscription) ?? "Assinatura")}</dt>`);
            } else {
              _push2(`<!---->`);
            }
            if ((_y = detail.value) == null ? void 0 : _y.subscription) {
              _push2(`<dd class="col-7">${ssrInterpolate(detail.value.subscription.plan_name)} <small class="text-muted d-block">${ssrInterpolate(detail.value.subscription.id)}</small></dd>`);
            } else {
              _push2(`<!---->`);
            }
            if ((_z = detail.value) == null ? void 0 : _z.idempotency_key) {
              _push2(`<dt class="col-5 text-muted">${ssrInterpolate(((_B = (_A = __props.t) == null ? void 0 : _A.detail) == null ? void 0 : _B.idempotency_key) ?? "Idempotency")}</dt>`);
            } else {
              _push2(`<!---->`);
            }
            if ((_C = detail.value) == null ? void 0 : _C.idempotency_key) {
              _push2(`<dd class="col-7"><code class="small">${ssrInterpolate(detail.value.idempotency_key)}</code></dd>`);
            } else {
              _push2(`<!---->`);
            }
            if ((_D = detail.value) == null ? void 0 : _D.wallet_after) {
              _push2(`<dt class="col-5 text-muted">${ssrInterpolate(((_F = (_E = __props.t) == null ? void 0 : _E.detail) == null ? void 0 : _F.wallet_balance) ?? "Saldo carteira")}</dt>`);
            } else {
              _push2(`<!---->`);
            }
            if ((_G = detail.value) == null ? void 0 : _G.wallet_after) {
              _push2(`<dd class="col-7"><span class="fw-bold">${ssrInterpolate((_H = detail.value.wallet_after.available) == null ? void 0 : _H.toLocaleString("pt-BR"))}</span> disponíveis <small class="text-muted d-block"> Total comprado: ${ssrInterpolate((_I = detail.value.wallet_after.lifetime_purchased) == null ? void 0 : _I.toLocaleString("pt-BR"))} · consumido: ${ssrInterpolate((_J = detail.value.wallet_after.lifetime_consumed) == null ? void 0 : _J.toLocaleString("pt-BR"))}</small></dd>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</dl></div>`);
          } else if (tab.value === "timeline") {
            _push2(`<div>`);
            if (events.value.length === 0) {
              _push2(`<div class="text-muted text-center small py-3"> Sem eventos registrados. </div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(events.value, (e, i) => {
              _push2(`<div class="d-flex align-items-start gap-2 py-2 border-bottom"><i class="${ssrRenderClass(`${e.icon} ${e.color} mt-1`)}" style="${ssrRenderStyle({ "font-size": "1.1rem" })}"></i><div class="flex-grow-1"><div class="fw-semibold small">${ssrInterpolate(e.label)}</div><small class="text-muted">${ssrInterpolate(e.at)}</small></div></div>`);
            });
            _push2(`<!--]--></div>`);
          } else if (tab.value === "metadata") {
            _push2(`<div>`);
            if (prettyMetadata.value) {
              _push2(`<pre class="small bg-light p-2 rounded" style="${ssrRenderStyle({ "max-height": "320px", "overflow-y": "auto" })}">${ssrInterpolate(prettyMetadata.value)}</pre>`);
            } else {
              _push2(`<div class="text-muted small">Sem metadados.</div>`);
            }
            _push2(`</div>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div></div><div class="modal-footer py-2 flex-wrap gap-2"><button type="button" class="btn btn-sm btn-outline-secondary"> Fechar </button>`);
          if (__props.permissions.credit && allowed.value.credit) {
            _push2(`<button type="button" class="btn btn-sm btn-success"><i class="ti ti-check me-1"></i>${ssrInterpolate(((_L = (_K = __props.t) == null ? void 0 : _K.actions) == null ? void 0 : _L.credit) ?? "Aprovar e creditar")}</button>`);
          } else {
            _push2(`<!---->`);
          }
          if (__props.permissions.cancel && allowed.value.cancel) {
            _push2(`<button type="button" class="btn btn-sm btn-outline-secondary"><i class="ti ti-x me-1"></i>${ssrInterpolate(((_N = (_M = __props.t) == null ? void 0 : _M.actions) == null ? void 0 : _N.cancel) ?? "Cancelar")}</button>`);
          } else {
            _push2(`<!---->`);
          }
          if (__props.permissions.fail && allowed.value.fail) {
            _push2(`<button type="button" class="btn btn-sm btn-outline-warning"><i class="ti ti-alert-triangle me-1"></i>${ssrInterpolate(((_P = (_O = __props.t) == null ? void 0 : _O.actions) == null ? void 0 : _P.fail) ?? "Marcar como falha")}</button>`);
          } else {
            _push2(`<!---->`);
          }
          if (__props.permissions.refund && allowed.value.refund) {
            _push2(`<button type="button" class="btn btn-sm btn-outline-danger"><i class="ti ti-arrow-back-up me-1"></i>${ssrInterpolate(((_R = (_Q = __props.t) == null ? void 0 : _Q.actions) == null ? void 0 : _R.refund) ?? "Reembolsar")}</button>`);
          } else {
            _push2(`<!---->`);
          }
          _push2(`</div></div></div></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/AiCreditPurchases/AiCreditPurchaseDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
