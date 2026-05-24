import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderStyle, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "InternalWalletCard",
  __ssrInlineRender: true,
  props: {
    wallet: { type: Object, default: () => null },
    permissions: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["add-credit"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const PROVIDER_META = {
      openai: { label: "ChatGPT", icon: "ti ti-brand-openai", color: "#10a37f", bg: "rgba(16,163,127,0.10)" },
      anthropic: { label: "Claude", icon: "ti ti-message-chatbot", color: "#cc785c", bg: "rgba(204,120,92,0.10)" },
      gemini: { label: "Gemini", icon: "ti ti-brand-google", color: "#4285f4", bg: "rgba(66,133,244,0.10)" }
    };
    const PROVIDER_ORDER = ["openai", "anthropic", "gemini"];
    const balance = computed(() => {
      var _a;
      return ((_a = props.wallet) == null ? void 0 : _a.balance) ?? null;
    });
    const consumedByProvider = computed(() => {
      var _a;
      return ((_a = balance.value) == null ? void 0 : _a.consumed_by_provider) ?? {};
    });
    const totalConsumed = computed(
      () => PROVIDER_ORDER.reduce((sum, p) => sum + (consumedByProvider.value[p] ?? 0), 0)
    );
    const quotaPct = computed(() => {
      var _a, _b;
      const total = ((_a = balance.value) == null ? void 0 : _a.quota_total) ?? 0;
      const used = ((_b = balance.value) == null ? void 0 : _b.quota_used) ?? 0;
      return total > 0 ? Math.min(100, Math.round(used / total * 100)) : 0;
    });
    function fmt(n) {
      return Number(n ?? 0).toLocaleString("pt-BR");
    }
    function providerPct(provider) {
      if (totalConsumed.value === 0) return 0;
      return Math.round((consumedByProvider.value[provider] ?? 0) / totalConsumed.value * 100);
    }
    function fmtDate(iso) {
      if (!iso) return "—";
      try {
        return new Date(iso).toLocaleDateString("pt-BR");
      } catch {
        return "—";
      }
    }
    return (_ctx, _push, _parent, _attrs) => {
      var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t, _u, _v, _w, _x, _y, _z, _A, _B, _C, _D, _E, _F;
      if (__props.wallet) {
        _push(`<div${ssrRenderAttrs(mergeProps({ class: "card mb-3 border-primary border-opacity-25 internal-wallet-card" }, _attrs))} data-v-4c6d2d94><div class="card-body p-3" data-v-4c6d2d94><div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3" data-v-4c6d2d94><div data-v-4c6d2d94><div class="d-flex align-items-center gap-2 mb-1" data-v-4c6d2d94><span class="badge bg-primary-subtle text-primary border border-primary border-opacity-25" data-v-4c6d2d94><i class="ti ti-building me-1" data-v-4c6d2d94></i> ${ssrInterpolate(((_b = (_a = __props.t) == null ? void 0 : _a.manual) == null ? void 0 : _b.badge_internal) ?? "Sua empresa")}</span><strong class="small" data-v-4c6d2d94>${ssrInterpolate(__props.wallet.entity_name)}</strong></div><h6 class="mb-0" data-v-4c6d2d94>${ssrInterpolate(((_d = (_c = __props.t) == null ? void 0 : _c.internal_wallet) == null ? void 0 : _d.title) ?? "Sua empresa — consumo interno")}</h6><small class="text-muted" data-v-4c6d2d94>${ssrInterpolate(((_f = (_e = __props.t) == null ? void 0 : _e.internal_wallet) == null ? void 0 : _f.subtitle) ?? "")}</small></div>`);
        if ((_g = __props.permissions) == null ? void 0 : _g.create_manual_for_internal) {
          _push(`<button type="button" class="btn btn-sm btn-primary" data-v-4c6d2d94><i class="ti ti-coin-plus me-1" data-v-4c6d2d94></i> ${ssrInterpolate(((_i = (_h = __props.t) == null ? void 0 : _h.internal_wallet) == null ? void 0 : _i.add_credit) ?? "Adicionar créditos")}</button>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div><div class="row g-3 mb-3" data-v-4c6d2d94><div class="col-12 col-md-4" data-v-4c6d2d94><div class="balance-tile balance-tile--main p-3 rounded h-100" data-v-4c6d2d94><div class="text-muted small mb-1" data-v-4c6d2d94><i class="ti ti-wallet me-1" data-v-4c6d2d94></i> ${ssrInterpolate(((_k = (_j = __props.t) == null ? void 0 : _j.internal_wallet) == null ? void 0 : _k.available) ?? "Disponível agora")}</div><h3 class="mb-0 fw-bold text-primary" data-v-4c6d2d94>${ssrInterpolate(fmt((_l = balance.value) == null ? void 0 : _l.available))}</h3><small class="text-muted" data-v-4c6d2d94>${ssrInterpolate(((_n = (_m = __props.t) == null ? void 0 : _m.internal_wallet) == null ? void 0 : _n.includes_quota) ?? "cota + comprado")}</small></div></div><div class="col-12 col-md-4" data-v-4c6d2d94><div class="balance-tile p-3 rounded h-100" data-v-4c6d2d94><div class="d-flex justify-content-between align-items-start mb-1" data-v-4c6d2d94><div class="text-muted small" data-v-4c6d2d94><i class="ti ti-calendar-month me-1" data-v-4c6d2d94></i> ${ssrInterpolate(((_p = (_o = __props.t) == null ? void 0 : _o.internal_wallet) == null ? void 0 : _p.quota) ?? "Cota mensal")}</div>`);
        if ((_q = balance.value) == null ? void 0 : _q.quota_expired) {
          _push(`<span class="badge bg-danger-subtle text-danger small" data-v-4c6d2d94>${ssrInterpolate(((_s = (_r = __props.t) == null ? void 0 : _r.internal_wallet) == null ? void 0 : _s.quota_expired) ?? "expirada")}</span>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div><div class="d-flex align-items-baseline gap-2" data-v-4c6d2d94><h4 class="mb-0 fw-bold" data-v-4c6d2d94>${ssrInterpolate(fmt((_t = balance.value) == null ? void 0 : _t.quota_remaining))}</h4><small class="text-muted" data-v-4c6d2d94>/ ${ssrInterpolate(fmt((_u = balance.value) == null ? void 0 : _u.quota_total))}</small></div><div class="progress mt-2" style="${ssrRenderStyle({ "height": "6px" })}" data-v-4c6d2d94><div class="progress-bar bg-info" role="progressbar" style="${ssrRenderStyle({ width: quotaPct.value + "%" })}" data-v-4c6d2d94></div></div><small class="text-muted" data-v-4c6d2d94><i class="ti ti-clock me-1" data-v-4c6d2d94></i> ${ssrInterpolate(((_w = (_v = __props.t) == null ? void 0 : _v.internal_wallet) == null ? void 0 : _w.resets_at) ?? "Reseta em")} ${ssrInterpolate(fmtDate((_x = balance.value) == null ? void 0 : _x.quota_period_ends_at))}</small></div></div><div class="col-12 col-md-4" data-v-4c6d2d94><div class="balance-tile p-3 rounded h-100" data-v-4c6d2d94><div class="text-muted small mb-1" data-v-4c6d2d94><i class="ti ti-coin me-1" data-v-4c6d2d94></i> ${ssrInterpolate(((_z = (_y = __props.t) == null ? void 0 : _y.internal_wallet) == null ? void 0 : _z.balance) ?? "Comprados (não expiram)")}</div><h4 class="mb-0 fw-bold" data-v-4c6d2d94>${ssrInterpolate(fmt((_A = balance.value) == null ? void 0 : _A.balance))}</h4><small class="text-muted" data-v-4c6d2d94><i class="ti ti-lock me-1" data-v-4c6d2d94></i> ${ssrInterpolate(fmt((_B = balance.value) == null ? void 0 : _B.reserved))} ${ssrInterpolate(((_D = (_C = __props.t) == null ? void 0 : _C.internal_wallet) == null ? void 0 : _D.reserved) ?? "reservados")}</small></div></div></div>`);
        if (totalConsumed.value > 0) {
          _push(`<div class="provider-analytics" data-v-4c6d2d94><div class="d-flex align-items-center justify-content-between mb-2" data-v-4c6d2d94><small class="text-muted fw-semibold text-uppercase" style="${ssrRenderStyle({ "letter-spacing": "0.04em", "font-size": ".7rem" })}" data-v-4c6d2d94><i class="ti ti-chart-pie me-1" data-v-4c6d2d94></i> ${ssrInterpolate(((_F = (_E = __props.t) == null ? void 0 : _E.internal_wallet) == null ? void 0 : _F.consumed_by_provider) ?? "Consumo histórico por provedor")}</small><small class="text-muted" data-v-4c6d2d94>${ssrInterpolate(fmt(totalConsumed.value))} créditos no total</small></div><div class="row g-2" data-v-4c6d2d94><!--[-->`);
          ssrRenderList(PROVIDER_ORDER, (key) => {
            _push(`<div class="col-12 col-md-4" data-v-4c6d2d94><div class="provider-mini-tile p-2 rounded" style="${ssrRenderStyle({ background: PROVIDER_META[key].bg, borderLeft: `3px solid ${PROVIDER_META[key].color}` })}" data-v-4c6d2d94><div class="d-flex align-items-center justify-content-between mb-1 small fw-semibold" style="${ssrRenderStyle({ color: PROVIDER_META[key].color })}" data-v-4c6d2d94><span data-v-4c6d2d94><i class="${ssrRenderClass([PROVIDER_META[key].icon, "me-1"])}" data-v-4c6d2d94></i> ${ssrInterpolate(PROVIDER_META[key].label)}</span><strong data-v-4c6d2d94>${ssrInterpolate(providerPct(key))}%</strong></div><small class="text-muted" data-v-4c6d2d94>${ssrInterpolate(fmt(consumedByProvider.value[key]))} créditos consumidos</small></div></div>`);
          });
          _push(`<!--]--></div></div>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div></div>`);
      } else {
        _push(`<!---->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/AiCreditPurchases/InternalWalletCard.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const InternalWalletCard = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-4c6d2d94"]]);
export {
  InternalWalletCard as default
};
