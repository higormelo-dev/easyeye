import { ref, computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderStyle, ssrRenderAttr } from "vue/server-renderer";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "ProviderCostsCard",
  __ssrInlineRender: true,
  props: {
    costs: { type: Object, default: () => null },
    permissions: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["add-topup"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const PROVIDER_META = {
      openai: { label: "ChatGPT", icon: "ti ti-brand-openai", color: "#10a37f", billingUrl: "https://platform.openai.com/settings/organization/limits" },
      anthropic: { label: "Claude", icon: "ti ti-message-chatbot", color: "#cc785c", billingUrl: "https://console.anthropic.com/settings/limits" },
      gemini: { label: "Gemini", icon: "ti ti-brand-google", color: "#4285f4", billingUrl: "https://console.cloud.google.com/billing/budgets" }
    };
    const PROVIDER_ORDER = ["openai", "anthropic", "gemini"];
    const showChecklist = ref(false);
    function fmtUsd(value) {
      return "$" + Number(value ?? 0).toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: value < 1 ? 4 : 2
      });
    }
    function fmtNum(n) {
      return Number(n ?? 0).toLocaleString("pt-BR");
    }
    const summary = computed(() => {
      var _a;
      return ((_a = props.costs) == null ? void 0 : _a.summary) ?? {};
    });
    const byProvider = computed(() => {
      var _a;
      return ((_a = props.costs) == null ? void 0 : _a.by_provider) ?? {};
    });
    const margin = computed(() => {
      var _a;
      return ((_a = props.costs) == null ? void 0 : _a.margin) ?? {};
    });
    const marginVariant = computed(() => {
      var _a;
      const pct = ((_a = margin.value) == null ? void 0 : _a.gross_margin_pct) ?? 0;
      if (pct >= 40) return "success";
      if (pct >= 20) return "warning";
      return "danger";
    });
    function forecastVariant(forecast, mtd) {
      if (!mtd || mtd <= 0) return "secondary";
      const ratio = forecast / mtd;
      if (ratio > 5) return "danger";
      if (ratio > 2) return "warning";
      return "info";
    }
    const ALERT_META = {
      ok: { color: "success", icon: "ti ti-circle-check", label: "OK" },
      warning: { color: "warning", icon: "ti ti-alert-triangle", label: "Atenção" },
      critical: { color: "danger", icon: "ti ti-flame", label: "Crítico" },
      exhausted: { color: "danger", icon: "ti ti-x", label: "Esgotado" },
      unknown: { color: "secondary", icon: "ti ti-question-mark", label: "Sem topup" }
    };
    function alertMeta(level) {
      return ALERT_META[level] ?? ALERT_META.unknown;
    }
    function fmtDateOnly(iso) {
      if (!iso) return "—";
      try {
        return new Date(iso).toLocaleDateString("pt-BR");
      } catch {
        return "—";
      }
    }
    return (_ctx, _push, _parent, _attrs) => {
      var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t, _u, _v, _w, _x, _y, _z, _A, _B, _C, _D, _E, _F, _G, _H;
      if (__props.costs) {
        _push(`<div${ssrRenderAttrs(mergeProps({ class: "card mb-3 provider-costs-card" }, _attrs))} data-v-349ff23a><div class="card-header py-2 d-flex align-items-center justify-content-between" data-v-349ff23a><div data-v-349ff23a><i class="ti ti-receipt-tax text-info me-2" data-v-349ff23a></i><strong class="small" data-v-349ff23a>${ssrInterpolate(((_b = (_a = __props.t) == null ? void 0 : _a.provider_costs) == null ? void 0 : _b.title) ?? "Custo do EasyEye nos provedores (lado supplier)")}</strong><small class="text-muted ms-2" data-v-349ff23a>${ssrInterpolate(((_d = (_c = __props.t) == null ? void 0 : _c.provider_costs) == null ? void 0 : _d.subtitle) ?? "Quanto vocês gastam em USD nas APIs")}</small></div><button type="button" class="btn btn-sm btn-link text-decoration-none" data-v-349ff23a><i class="ti ti-shield-check me-1" data-v-349ff23a></i> ${ssrInterpolate(((_f = (_e = __props.t) == null ? void 0 : _e.provider_costs) == null ? void 0 : _f.alerts_setup) ?? "Alertas externos")}</button></div><div class="card-body p-3" data-v-349ff23a><div class="row g-2 mb-3" data-v-349ff23a><div class="col-6 col-md-3" data-v-349ff23a><div class="cost-tile" data-v-349ff23a><div class="text-muted small" data-v-349ff23a>${ssrInterpolate(((_h = (_g = __props.t) == null ? void 0 : _g.provider_costs) == null ? void 0 : _h.mtd) ?? "Mês até hoje")}</div><h5 class="mb-0 fw-bold" data-v-349ff23a>${ssrInterpolate(fmtUsd(summary.value.month_to_date_usd))}</h5><small class="text-muted" data-v-349ff23a>${ssrInterpolate(fmtNum(summary.value.total_calls_mtd))} chamadas</small></div></div><div class="col-6 col-md-3" data-v-349ff23a><div class="cost-tile" data-v-349ff23a><div class="text-muted small" data-v-349ff23a>${ssrInterpolate(((_j = (_i = __props.t) == null ? void 0 : _i.provider_costs) == null ? void 0 : _j.last_7d) ?? "Últimos 7 dias")}</div><h5 class="mb-0 fw-bold" data-v-349ff23a>${ssrInterpolate(fmtUsd(summary.value.last_7d_usd))}</h5><small class="text-muted" data-v-349ff23a>~${ssrInterpolate(fmtUsd((summary.value.last_7d_usd ?? 0) / 7))}/dia</small></div></div><div class="col-6 col-md-3" data-v-349ff23a><div class="cost-tile" data-v-349ff23a><div class="text-muted small" data-v-349ff23a>${ssrInterpolate(((_l = (_k = __props.t) == null ? void 0 : _k.provider_costs) == null ? void 0 : _l.yesterday) ?? "Ontem")}</div><h5 class="mb-0 fw-bold" data-v-349ff23a>${ssrInterpolate(fmtUsd(summary.value.yesterday_usd))}</h5></div></div><div class="col-6 col-md-3" data-v-349ff23a><div class="cost-tile cost-tile--forecast" data-v-349ff23a><div class="text-muted small" data-v-349ff23a><i class="ti ti-chart-arrows me-1" data-v-349ff23a></i> ${ssrInterpolate(((_n = (_m = __props.t) == null ? void 0 : _m.provider_costs) == null ? void 0 : _n.forecast) ?? "Forecast mensal")}</div><h5 class="mb-0 fw-bold text-info" data-v-349ff23a>${ssrInterpolate(fmtUsd(summary.value.month_forecast_usd))}</h5><small class="text-muted" data-v-349ff23a>${ssrInterpolate(((_p = (_o = __props.t) == null ? void 0 : _o.provider_costs) == null ? void 0 : _p.forecast_help) ?? "extrapolação 7d")}</small></div></div></div><div class="row g-2 mb-3" data-v-349ff23a><!--[-->`);
        ssrRenderList(PROVIDER_ORDER, (key) => {
          var _a2, _b2, _c2, _d2, _e2, _f2, _g2, _h2, _i2, _j2, _k2, _l2, _m2, _n2, _o2, _p2, _q2, _r2, _s2, _t2, _u2, _v2, _w2, _x2, _y2, _z2, _A2, _B2, _C2, _D2, _E2;
          _push(`<div class="col-12 col-md-4" data-v-349ff23a><div class="${ssrRenderClass([((_b2 = (_a2 = byProvider.value[key]) == null ? void 0 : _a2.estimated_balance) == null ? void 0 : _b2.alert_level) === "critical" || ((_d2 = (_c2 = byProvider.value[key]) == null ? void 0 : _c2.estimated_balance) == null ? void 0 : _d2.alert_level) === "exhausted" ? "provider-cost-tile--danger" : "", "provider-cost-tile p-3 rounded h-100"])}" style="${ssrRenderStyle({ borderLeft: `4px solid ${PROVIDER_META[key].color}` })}" data-v-349ff23a><div class="d-flex align-items-center justify-content-between mb-2" data-v-349ff23a><div class="fw-semibold" style="${ssrRenderStyle({ color: PROVIDER_META[key].color })}" data-v-349ff23a><i class="${ssrRenderClass([PROVIDER_META[key].icon, "me-1"])}" data-v-349ff23a></i> ${ssrInterpolate(PROVIDER_META[key].label)}</div><div class="d-flex align-items-center gap-1" data-v-349ff23a>`);
          if ((_e2 = __props.permissions) == null ? void 0 : _e2.create_topup) {
            _push(`<button type="button" class="btn btn-sm btn-outline-info py-0 px-2"${ssrRenderAttr("title", ((_g2 = (_f2 = __props.t) == null ? void 0 : _f2.topup) == null ? void 0 : _g2.add) ?? "Registrar recarga")} data-v-349ff23a><i class="ti ti-plus" style="${ssrRenderStyle({ "font-size": ".85rem" })}" data-v-349ff23a></i></button>`);
          } else {
            _push(`<!---->`);
          }
          _push(`<a${ssrRenderAttr("href", PROVIDER_META[key].billingUrl)} target="_blank" rel="noopener" class="text-muted small text-decoration-none" title="Abrir painel de billing externo" data-v-349ff23a><i class="ti ti-external-link" data-v-349ff23a></i></a></div></div>`);
          if ((_i2 = (_h2 = byProvider.value[key]) == null ? void 0 : _h2.estimated_balance) == null ? void 0 : _i2.has_topups) {
            _push(`<div class="${ssrRenderClass([`estimated-balance--${alertMeta(byProvider.value[key].estimated_balance.alert_level).color}`, "estimated-balance mb-2 p-2 rounded"])}" data-v-349ff23a><div class="d-flex justify-content-between align-items-baseline" data-v-349ff23a><div data-v-349ff23a><div class="text-muted small" style="${ssrRenderStyle({ "font-size": ".65rem", "text-transform": "uppercase" })}" data-v-349ff23a>${ssrInterpolate(((_k2 = (_j2 = __props.t) == null ? void 0 : _j2.provider_costs) == null ? void 0 : _k2.estimated_balance) ?? "Saldo estimado")}</div><strong class="${ssrRenderClass([`text-${alertMeta(byProvider.value[key].estimated_balance.alert_level).color}`, "fs-5"])}" data-v-349ff23a>${ssrInterpolate(fmtUsd(byProvider.value[key].estimated_balance.remaining_usd))}</strong></div><div class="text-end" data-v-349ff23a><i class="${ssrRenderClass(alertMeta(byProvider.value[key].estimated_balance.alert_level).icon)}" style="${ssrRenderStyle({ color: `var(--bs-${alertMeta(byProvider.value[key].estimated_balance.alert_level).color})` })}" data-v-349ff23a></i>`);
            if (byProvider.value[key].estimated_balance.days_remaining !== null) {
              _push(`<div class="${ssrRenderClass([`text-${alertMeta(byProvider.value[key].estimated_balance.alert_level).color}`, "small"])}" data-v-349ff23a> ~${ssrInterpolate(byProvider.value[key].estimated_balance.days_remaining)} ${ssrInterpolate(((_m2 = (_l2 = __props.t) == null ? void 0 : _l2.provider_costs) == null ? void 0 : _m2.days_left) ?? "dias")}</div>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div></div></div>`);
          } else {
            _push(`<div class="estimated-balance estimated-balance--secondary mb-2 p-2 rounded" data-v-349ff23a><div class="text-muted small text-center" data-v-349ff23a><i class="ti ti-info-circle me-1" data-v-349ff23a></i> ${ssrInterpolate(((_o2 = (_n2 = __props.t) == null ? void 0 : _n2.provider_costs) == null ? void 0 : _o2.no_topups) ?? "Registre uma recarga para ver o saldo estimado")}</div></div>`);
          }
          _push(`<div class="row g-1" data-v-349ff23a><div class="col-6" data-v-349ff23a><div class="text-muted small" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-349ff23a>${ssrInterpolate(((_q2 = (_p2 = __props.t) == null ? void 0 : _p2.provider_costs) == null ? void 0 : _q2.mtd) ?? "Mês")}</div><strong data-v-349ff23a>${ssrInterpolate(fmtUsd((_r2 = byProvider.value[key]) == null ? void 0 : _r2.month_to_date_usd))}</strong></div><div class="col-6" data-v-349ff23a><div class="text-muted small" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-349ff23a>${ssrInterpolate(((_t2 = (_s2 = __props.t) == null ? void 0 : _s2.provider_costs) == null ? void 0 : _t2.forecast) ?? "Forecast")}</div><span class="${ssrRenderClass([`text-${forecastVariant((_u2 = byProvider.value[key]) == null ? void 0 : _u2.month_forecast_usd, (_v2 = byProvider.value[key]) == null ? void 0 : _v2.month_to_date_usd)}`, "fw-semibold"])}" data-v-349ff23a>${ssrInterpolate(fmtUsd((_w2 = byProvider.value[key]) == null ? void 0 : _w2.month_forecast_usd))}</span></div></div><hr class="my-2" data-v-349ff23a><div class="small text-muted" data-v-349ff23a><div class="d-flex justify-content-between" data-v-349ff23a><span data-v-349ff23a>${ssrInterpolate(fmtNum((_x2 = byProvider.value[key]) == null ? void 0 : _x2.calls_mtd))} chamadas</span><span data-v-349ff23a>${ssrInterpolate(fmtUsd((_y2 = byProvider.value[key]) == null ? void 0 : _y2.avg_cost_per_call_usd))}/chamada</span></div>`);
          if ((_A2 = (_z2 = byProvider.value[key]) == null ? void 0 : _z2.models_breakdown) == null ? void 0 : _A2.length) {
            _push(`<div class="mt-2" data-v-349ff23a><div style="${ssrRenderStyle({ "font-size": ".65rem", "text-transform": "uppercase", "letter-spacing": "0.04em" })}" class="mb-1" data-v-349ff23a> Top modelos </div><!--[-->`);
            ssrRenderList(byProvider.value[key].models_breakdown.slice(0, 3), (m) => {
              _push(`<div class="d-flex justify-content-between" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-349ff23a><span class="text-truncate" style="${ssrRenderStyle({ "max-width": "60%" })}"${ssrRenderAttr("title", m.model)} data-v-349ff23a>${ssrInterpolate(m.model)}</span><span data-v-349ff23a>${ssrInterpolate(fmtUsd(m.cost_usd))}</span></div>`);
            });
            _push(`<!--]--></div>`);
          } else {
            _push(`<!---->`);
          }
          if ((_C2 = (_B2 = byProvider.value[key]) == null ? void 0 : _B2.recent_topups) == null ? void 0 : _C2.length) {
            _push(`<div class="mt-2 pt-2 border-top" data-v-349ff23a><div style="${ssrRenderStyle({ "font-size": ".65rem", "text-transform": "uppercase", "letter-spacing": "0.04em" })}" class="mb-1" data-v-349ff23a>${ssrInterpolate(((_E2 = (_D2 = __props.t) == null ? void 0 : _D2.provider_costs) == null ? void 0 : _E2.recent_topups) ?? "Últimas recargas")}</div><!--[-->`);
            ssrRenderList(byProvider.value[key].recent_topups.slice(0, 3), (topup) => {
              _push(`<div class="d-flex justify-content-between" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-349ff23a><span data-v-349ff23a>${ssrInterpolate(fmtDateOnly(topup.topped_up_at))}</span><strong class="text-success" data-v-349ff23a>+${ssrInterpolate(fmtUsd(topup.amount_usd))}</strong></div>`);
            });
            _push(`<!--]--></div>`);
          } else {
            _push(`<!---->`);
          }
          _push(`</div></div></div>`);
        });
        _push(`<!--]--></div><div class="${ssrRenderClass([`margin-strip--${marginVariant.value}`, "margin-strip p-3 rounded"])}" data-v-349ff23a><div class="row align-items-center g-2" data-v-349ff23a><div class="col-12 col-md-3" data-v-349ff23a><div class="text-muted small" data-v-349ff23a>${ssrInterpolate(((_s = (_r = (_q = __props.t) == null ? void 0 : _q.provider_costs) == null ? void 0 : _r.margin) == null ? void 0 : _s.title) ?? "Margem bruta (mês)")}</div><h4 class="${ssrRenderClass([`text-${marginVariant.value}`, "mb-0 fw-bold"])}" data-v-349ff23a>${ssrInterpolate(margin.value.gross_margin_pct ?? 0)}% </h4></div><div class="col-6 col-md-3" data-v-349ff23a><div class="text-muted small" data-v-349ff23a><i class="ti ti-cash me-1" data-v-349ff23a></i> ${ssrInterpolate(((_v = (_u = (_t = __props.t) == null ? void 0 : _t.provider_costs) == null ? void 0 : _u.margin) == null ? void 0 : _v.revenue) ?? "Receita estimada")}</div><strong class="text-success" data-v-349ff23a>${ssrInterpolate(fmtUsd(margin.value.revenue_estimate_usd))}</strong></div><div class="col-6 col-md-3" data-v-349ff23a><div class="text-muted small" data-v-349ff23a><i class="ti ti-arrow-down-right me-1" data-v-349ff23a></i> ${ssrInterpolate(((_y = (_x = (_w = __props.t) == null ? void 0 : _w.provider_costs) == null ? void 0 : _x.margin) == null ? void 0 : _y.cost) ?? "Custo provedores")}</div><strong class="text-danger" data-v-349ff23a>${ssrInterpolate(fmtUsd(margin.value.cost_mtd_usd))}</strong></div><div class="col-12 col-md-3" data-v-349ff23a><div class="text-muted small" data-v-349ff23a>${ssrInterpolate(((_B = (_A = (_z = __props.t) == null ? void 0 : _z.provider_costs) == null ? void 0 : _A.margin) == null ? void 0 : _B.gross) ?? "Lucro bruto")}</div><strong class="${ssrRenderClass(`text-${marginVariant.value}`)}" data-v-349ff23a>${ssrInterpolate(fmtUsd(margin.value.gross_margin_usd))}</strong><small class="text-muted d-block" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-349ff23a> multiplicador config: ${ssrInterpolate(margin.value.margin_multiplier)}× </small></div></div></div>`);
        if (showChecklist.value) {
          _push(`<div class="alert alert-info mt-3 small mb-0" data-v-349ff23a><div class="d-flex align-items-start gap-2 mb-2" data-v-349ff23a><i class="ti ti-info-circle mt-1" data-v-349ff23a></i><div data-v-349ff23a><strong data-v-349ff23a>${ssrInterpolate(((_E = (_D = (_C = __props.t) == null ? void 0 : _C.provider_costs) == null ? void 0 : _D.checklist) == null ? void 0 : _E.title) ?? "Configure alertas no painel dos provedores")}</strong><p class="mb-2 small text-muted" data-v-349ff23a>${ssrInterpolate(((_H = (_G = (_F = __props.t) == null ? void 0 : _F.provider_costs) == null ? void 0 : _G.checklist) == null ? void 0 : _H.subtitle) ?? "O EasyEye não consegue recarregar saldo automaticamente — você precisa configurar limites/alertas em cada provedor.")}</p></div></div><ul class="mb-0" style="${ssrRenderStyle({ "list-style": "none", "padding-left": "0" })}" data-v-349ff23a><!--[-->`);
          ssrRenderList(PROVIDER_ORDER, (key) => {
            _push(`<li class="mb-2" data-v-349ff23a><i class="${ssrRenderClass([PROVIDER_META[key].icon, "me-1"])}" style="${ssrRenderStyle({ color: PROVIDER_META[key].color })}" data-v-349ff23a></i><strong data-v-349ff23a>${ssrInterpolate(PROVIDER_META[key].label)}:</strong> configure limite mensal em <a${ssrRenderAttr("href", PROVIDER_META[key].billingUrl)} target="_blank" rel="noopener" class="text-decoration-underline" data-v-349ff23a>${ssrInterpolate(PROVIDER_META[key].billingUrl.replace("https://", ""))}</a></li>`);
          });
          _push(`<!--]--></ul></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/AiCreditPurchases/ProviderCostsCard.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const ProviderCostsCard = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-349ff23a"]]);
export {
  ProviderCostsCard as default
};
