import { reactive, ref, computed, mergeProps, withCtx, openBlock, createBlock, createVNode, createTextVNode, toDisplayString, createCommentVNode, unref, Fragment, renderList, withModifiers, withDirectives, vModelSelect, vModelText, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderStyle, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderAttr, ssrRenderClass } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import { _ as _sfc_main$3 } from "./ActionIconButton-BTsQtzdl.js";
import { _ as _sfc_main$5 } from "./ConfirmationWithReasonModal-CmfO7qbN.js";
import { u as useConfirmationWithReason } from "./useConfirmationWithReason-DDlQOe6J.js";
import _sfc_main$4 from "./AiCreditPurchaseDetailDrawer-BcZyweIA.js";
import _sfc_main$6 from "./ManualCreditModal-Bbj6mCYH.js";
import _sfc_main$2 from "./ConsumptionByProviderChart-CZSNCYRJ.js";
import InternalWalletCard from "./InternalWalletCard-LXUk6MPn.js";
import ProviderCostsCard from "./ProviderCostsCard-BYPQpeO5.js";
import _sfc_main$7 from "./ProviderTopupModal-C4xE_32_.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "chart.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    purchases: { type: Object, default: () => ({ data: [], meta: {}, links: {} }) },
    kpis: { type: Object, default: () => ({}) },
    providerCosts: { type: Object, default: () => null },
    consumptionByProvider: { type: Object, default: () => ({}) },
    internalWallet: { type: Object, default: () => null },
    topConsumers: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    statusOptions: { type: Array, default: () => [] },
    providerOptions: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
    permissions: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const filterForm = reactive({
      status: props.filters.status ?? "",
      provider: props.filters.provider ?? "",
      entity_id: props.filters.entity_id ?? "",
      date_from: props.filters.date_from ?? "",
      date_to: props.filters.date_to ?? "",
      q: props.filters.q ?? ""
    });
    function applyFilters() {
      const params = {};
      Object.entries(filterForm).forEach(([k, v]) => {
        if (v) params[k] = v;
      });
      router.get(route("manager.ai-credit-purchases.index"), params, {
        preserveScroll: true,
        preserveState: true,
        only: ["purchases", "kpis", "consumptionByProvider", "topConsumers", "filters"]
      });
    }
    function clearFilters() {
      Object.keys(filterForm).forEach((k) => filterForm[k] = "");
      applyFilters();
    }
    const drawerOpen = ref(false);
    const drawerPurchase = ref(null);
    function openDetail(purchase) {
      drawerPurchase.value = purchase;
      drawerOpen.value = true;
    }
    function closeDetail() {
      drawerOpen.value = false;
      drawerPurchase.value = null;
    }
    const manualModalOpen = ref(false);
    const manualModalRef = ref(null);
    const presetEntityId = ref(null);
    const topupModalOpen = ref(false);
    const topupModalRef = ref(null);
    const presetTopupProvider = ref("");
    function openTopupModal(provider = "") {
      presetTopupProvider.value = provider;
      topupModalOpen.value = true;
    }
    function closeTopupModal() {
      topupModalOpen.value = false;
      presetTopupProvider.value = "";
    }
    async function submitTopup(payload) {
      var _a, _b, _c, _d, _e;
      (_a = topupModalRef.value) == null ? void 0 : _a.setSaving(true);
      (_b = topupModalRef.value) == null ? void 0 : _b.setError("");
      try {
        const res = await fetch(route("manager.ai-provider-topups.store"), {
          method: "POST",
          headers: {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": csrfToken()
          },
          body: JSON.stringify(payload)
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
          (_c = topupModalRef.value) == null ? void 0 : _c.setError(json.message ?? `Erro ${res.status}`);
          return;
        }
        closeTopupModal();
        reload();
      } catch (e) {
        (_d = topupModalRef.value) == null ? void 0 : _d.setError(e.message);
      } finally {
        (_e = topupModalRef.value) == null ? void 0 : _e.setSaving(false);
      }
    }
    function openManualModal(entityId = null) {
      presetEntityId.value = entityId;
      manualModalOpen.value = true;
    }
    function closeManualModal() {
      manualModalOpen.value = false;
      presetEntityId.value = null;
    }
    async function submitManual(payload) {
      var _a, _b, _c, _d, _e;
      (_a = manualModalRef.value) == null ? void 0 : _a.setSaving(true);
      (_b = manualModalRef.value) == null ? void 0 : _b.setError("");
      try {
        const res = await fetch(route("manager.ai-credit-purchases.manual"), {
          method: "POST",
          headers: {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": csrfToken()
          },
          body: JSON.stringify(payload)
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
          (_c = manualModalRef.value) == null ? void 0 : _c.setError(json.message ?? `Erro ${res.status}`);
          return;
        }
        closeManualModal();
        reload();
      } catch (e) {
        (_d = manualModalRef.value) == null ? void 0 : _d.setError(e.message);
      } finally {
        (_e = manualModalRef.value) == null ? void 0 : _e.setSaving(false);
      }
    }
    const {
      state: reasonModal,
      open: openReasonModal,
      close: closeReasonModal,
      handle: handleReasonConfirm
    } = useConfirmationWithReason();
    function csrfToken() {
      var _a;
      return ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "";
    }
    async function callAction(url, payload = {}) {
      const res = await fetch(url, {
        method: "PATCH",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": csrfToken()
        },
        body: JSON.stringify(payload)
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json.message ?? `Erro ${res.status}`);
      return json;
    }
    function showToast(message, type = "success") {
      if (type === "error") alert(message);
    }
    function reload() {
      router.reload({ only: ["purchases", "kpis", "providerCosts", "consumptionByProvider", "topConsumers", "internalWallet"] });
    }
    function onCredit(purchase) {
      var _a, _b, _c, _d;
      if (!props.permissions.credit) return;
      openReasonModal({
        title: ((_b = (_a = props.t) == null ? void 0 : _a.confirm) == null ? void 0 : _b.credit_title) ?? "Aprovar e creditar?",
        message: (((_d = (_c = props.t) == null ? void 0 : _c.confirm) == null ? void 0 : _d.credit_body) ?? "").replace(":credits", purchase.credits),
        confirmVariant: "primary",
        async onConfirm(reason) {
          try {
            const json = await callAction(purchase.actions_url.credit, { reason });
            showToast(json.message, "success");
            reload();
          } catch (e) {
            showToast(e.message, "error");
          }
        }
      });
    }
    function onCancel(purchase) {
      var _a, _b, _c, _d;
      if (!props.permissions.cancel) return;
      openReasonModal({
        title: ((_b = (_a = props.t) == null ? void 0 : _a.confirm) == null ? void 0 : _b.cancel_title) ?? "Cancelar pedido pendente?",
        message: ((_d = (_c = props.t) == null ? void 0 : _c.confirm) == null ? void 0 : _d.cancel_body) ?? "",
        confirmVariant: "warning",
        async onConfirm(reason) {
          try {
            const json = await callAction(purchase.actions_url.cancel, { reason });
            showToast(json.message, "success");
            reload();
          } catch (e) {
            showToast(e.message, "error");
          }
        }
      });
    }
    function onMarkFailed(purchase) {
      var _a, _b, _c, _d;
      if (!props.permissions.fail) return;
      openReasonModal({
        title: ((_b = (_a = props.t) == null ? void 0 : _a.confirm) == null ? void 0 : _b.fail_title) ?? "Marcar como falha?",
        message: ((_d = (_c = props.t) == null ? void 0 : _c.confirm) == null ? void 0 : _d.fail_body) ?? "",
        confirmVariant: "danger",
        async onConfirm(reason) {
          try {
            const json = await callAction(purchase.actions_url.fail, { reason });
            showToast(json.message, "success");
            reload();
          } catch (e) {
            showToast(e.message, "error");
          }
        }
      });
    }
    function onRefund(purchase) {
      var _a, _b, _c, _d;
      if (!props.permissions.refund) return;
      openReasonModal({
        title: ((_b = (_a = props.t) == null ? void 0 : _a.confirm) == null ? void 0 : _b.refund_title) ?? "Reembolsar e estornar?",
        message: (((_d = (_c = props.t) == null ? void 0 : _c.confirm) == null ? void 0 : _d.refund_body) ?? "").replace(":credits", purchase.credits),
        confirmVariant: "danger",
        async onConfirm(reason) {
          try {
            const json = await callAction(purchase.actions_url.refund, { reason });
            showToast(json.message, "success");
            reload();
          } catch (e) {
            showToast(e.message, "error");
          }
        }
      });
    }
    const hasResults = computed(() => {
      var _a;
      return (((_a = props.purchases) == null ? void 0 : _a.data) ?? []).length > 0;
    });
    function goToPage(url) {
      if (!url) return;
      router.get(url, {}, { preserveScroll: true, preserveState: true, only: ["purchases"] });
    }
    function providerBadgeClass(provider) {
      switch (provider) {
        case "openai":
          return "badge bg-success-subtle text-success border border-success border-opacity-25";
        case "anthropic":
          return "badge bg-warning-subtle text-warning border border-warning border-opacity-25";
        case "gemini":
          return "badge bg-primary-subtle text-primary border border-primary border-opacity-25";
        default:
          return "badge bg-light text-muted";
      }
    }
    function providerIcon(provider) {
      switch (provider) {
        case "openai":
          return "ti ti-brand-openai";
        case "anthropic":
          return "ti ti-message-chatbot";
        case "gemini":
          return "ti ti-brand-google";
        default:
          return "ti ti-minus";
      }
    }
    return (_ctx, _push, _parent, _attrs) => {
      var _a;
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: ((_a = __props.t) == null ? void 0 : _a.title) ?? "Compras de créditos IA",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a2, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n, _o, _p, _q, _r, _s, _t, _u, _v, _w, _x, _y, _z, _A, _B, _C, _D, _E, _F, _G, _H, _I, _J, _K, _L, _M, _N, _O, _P, _Q, _R, _S, _T, _U, _V, _W, _X, _Y, _Z, __, _$, _aa, _ba, _ca, _da, _ea, _fa, _ga, _ha, _ia, _ja, _ka, _la, _ma, _na, _oa, _pa, _qa, _ra, _sa, _ta, _ua, _va, _wa, _xa, _ya, _za, _Aa, _Ba, _Ca, _Da, _Ea, _Fa, _Ga, _Ha, _Ia, _Ja, _Ka, _La, _Ma, _Na, _Oa, _Pa, _Qa, _Ra, _Sa, _Ta, _Ua, _Va, _Wa, _Xa, _Ya, _Za, __a, _$a, _ab, _bb, _cb, _db, _eb, _fb, _gb, _hb, _ib, _jb, _kb, _lb, _mb, _nb, _ob, _pb, _qb, _rb, _sb, _tb, _ub, _vb, _wb, _xb, _yb, _zb, _Ab, _Bb, _Cb, _Db, _Eb, _Fb, _Gb, _Hb, _Ib, _Jb, _Kb, _Lb, _Mb, _Nb, _Ob, _Pb, _Qb, _Rb, _Sb, _Tb, _Ub, _Vb, _Wb, _Xb, _Yb, _Zb, __b, _$b, _ac, _bc;
          if (_push2) {
            _push2(`<div class="ai-credit-purchases-screen" data-v-9eb22c9b${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: ((_a2 = __props.t) == null ? void 0 : _a2.title) ?? "Compras de créditos IA",
              subtitle: (_b = __props.t) == null ? void 0 : _b.subtitle,
              total: ((_d = (_c = __props.purchases) == null ? void 0 : _c.meta) == null ? void 0 : _d.total) > 0 ? __props.purchases.meta.total : null
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                var _a3, _b2, _c2, _d2, _e2, _f2;
                if (_push3) {
                  if ((_a3 = __props.permissions) == null ? void 0 : _a3.create_manual) {
                    _push3(`<button type="button" class="btn btn-success btn-sm" data-v-9eb22c9b${_scopeId2}><i class="ti ti-coin-plus me-1" data-v-9eb22c9b${_scopeId2}></i> ${ssrInterpolate(((_c2 = (_b2 = __props.t) == null ? void 0 : _b2.actions) == null ? void 0 : _c2.create_manual) ?? "Adicionar crédito manual")}</button>`);
                  } else {
                    _push3(`<!---->`);
                  }
                } else {
                  return [
                    ((_d2 = __props.permissions) == null ? void 0 : _d2.create_manual) ? (openBlock(), createBlock("button", {
                      key: 0,
                      type: "button",
                      class: "btn btn-success btn-sm",
                      onClick: ($event) => openManualModal()
                    }, [
                      createVNode("i", { class: "ti ti-coin-plus me-1" }),
                      createTextVNode(" " + toDisplayString(((_f2 = (_e2 = __props.t) == null ? void 0 : _e2.actions) == null ? void 0 : _f2.create_manual) ?? "Adicionar crédito manual"), 1)
                    ], 8, ["onClick"])) : createCommentVNode("", true)
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            if (__props.internalWallet) {
              _push2(ssrRenderComponent(InternalWalletCard, {
                wallet: __props.internalWallet,
                permissions: __props.permissions,
                t: __props.t,
                onAddCredit: openManualModal
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
            if (__props.providerCosts) {
              _push2(ssrRenderComponent(ProviderCostsCard, {
                costs: __props.providerCosts,
                permissions: __props.permissions,
                t: __props.t,
                onAddTopup: openTopupModal
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="row g-2 mb-3" data-v-9eb22c9b${_scopeId}><div class="col-12 col-md-6 col-xl-3" data-v-9eb22c9b${_scopeId}><div class="card h-100" data-v-9eb22c9b${_scopeId}><div class="card-body p-3" data-v-9eb22c9b${_scopeId}><div class="d-flex align-items-center justify-content-between" data-v-9eb22c9b${_scopeId}><span class="text-muted small" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_f = (_e = __props.t) == null ? void 0 : _e.kpi) == null ? void 0 : _f.pending) ?? "Pendentes")}</span><i class="ti ti-clock text-warning" data-v-9eb22c9b${_scopeId}></i></div><h4 class="mb-0 mt-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_h = (_g = __props.kpis) == null ? void 0 : _g.pending) == null ? void 0 : _h.count) ?? 0)}</h4><small class="text-warning fw-semibold" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_j = (_i = __props.kpis) == null ? void 0 : _i.pending) == null ? void 0 : _j.amount_formatted) ?? "R$ 0,00")}</small><div class="small text-muted mt-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate((_l = (_k = __props.t) == null ? void 0 : _k.kpi) == null ? void 0 : _l.pending_help)}</div></div></div></div><div class="col-12 col-md-6 col-xl-3" data-v-9eb22c9b${_scopeId}><div class="card h-100" data-v-9eb22c9b${_scopeId}><div class="card-body p-3" data-v-9eb22c9b${_scopeId}><div class="d-flex align-items-center justify-content-between" data-v-9eb22c9b${_scopeId}><span class="text-muted small" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_n = (_m = __props.t) == null ? void 0 : _m.kpi) == null ? void 0 : _n.credited_30d) ?? "Creditados (30d)")}</span><i class="ti ti-coin text-success" data-v-9eb22c9b${_scopeId}></i></div><h4 class="mb-0 mt-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_p = (_o = __props.kpis) == null ? void 0 : _o.credited_30d) == null ? void 0 : _p.amount_formatted) ?? "R$ 0,00")}</h4><small class="text-success fw-semibold" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_r = (_q = __props.kpis) == null ? void 0 : _q.credited_30d) == null ? void 0 : _r.credits_sold) ?? 0)} ${ssrInterpolate(((_t = (_s = __props.t) == null ? void 0 : _s.kpi) == null ? void 0 : _t.credits_sold) ?? "créditos vendidos")}</small><div class="small text-muted mt-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_v = (_u = __props.kpis) == null ? void 0 : _u.credited_30d) == null ? void 0 : _v.count) ?? 0)} pedidos </div></div></div></div><div class="col-12 col-md-6 col-xl-3" data-v-9eb22c9b${_scopeId}><div class="card h-100" data-v-9eb22c9b${_scopeId}><div class="card-body p-3" data-v-9eb22c9b${_scopeId}><div class="d-flex align-items-center justify-content-between" data-v-9eb22c9b${_scopeId}><span class="text-muted small" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_x = (_w = __props.t) == null ? void 0 : _w.kpi) == null ? void 0 : _x.conversion) ?? "Conversão (30d)")}</span><i class="ti ti-trending-up text-info" data-v-9eb22c9b${_scopeId}></i></div><h4 class="mb-0 mt-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_z = (_y = __props.kpis) == null ? void 0 : _y.funnel_30d) == null ? void 0 : _z.conversion_pct) ?? 0)}%</h4><small class="text-info fw-semibold" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_B = (_A = __props.kpis) == null ? void 0 : _A.funnel_30d) == null ? void 0 : _B.credited) ?? 0)} de ${ssrInterpolate(((_D = (_C = __props.kpis) == null ? void 0 : _C.funnel_30d) == null ? void 0 : _D.total) ?? 0)}</small><div class="small text-muted mt-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate((_F = (_E = __props.t) == null ? void 0 : _E.kpi) == null ? void 0 : _F.conversion_help)}</div></div></div></div><div class="col-12 col-md-6 col-xl-3" data-v-9eb22c9b${_scopeId}><div class="card h-100" data-v-9eb22c9b${_scopeId}><div class="card-body p-3" data-v-9eb22c9b${_scopeId}><div class="d-flex align-items-center justify-content-between" data-v-9eb22c9b${_scopeId}><span class="text-muted small" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_H = (_G = __props.t) == null ? void 0 : _G.kpi) == null ? void 0 : _H.abandonment) ?? "Abandono (30d)")}</span><i class="ti ti-x text-danger" data-v-9eb22c9b${_scopeId}></i></div><h4 class="mb-0 mt-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_J = (_I = __props.kpis) == null ? void 0 : _I.funnel_30d) == null ? void 0 : _J.abandonment_pct) ?? 0)}%</h4><small class="text-danger fw-semibold" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_L = (_K = __props.kpis) == null ? void 0 : _K.funnel_30d) == null ? void 0 : _L.cancelled) ?? 0)} cancelados / ${ssrInterpolate(((_N = (_M = __props.kpis) == null ? void 0 : _M.funnel_30d) == null ? void 0 : _N.failed) ?? 0)} falhas </small><div class="small text-muted mt-1" data-v-9eb22c9b${_scopeId}>do funil dos últimos 30 dias</div></div></div></div></div><div class="row g-2 mb-3" data-v-9eb22c9b${_scopeId}><div class="col-12 col-xl-4" data-v-9eb22c9b${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              consumption: __props.consumptionByProvider,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="col-12 col-xl-4" data-v-9eb22c9b${_scopeId}><div class="card h-100" data-v-9eb22c9b${_scopeId}><div class="card-header py-2 d-flex align-items-center" data-v-9eb22c9b${_scopeId}><i class="ti ti-trophy text-warning me-2" data-v-9eb22c9b${_scopeId}></i><strong class="small" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_P = (_O = __props.t) == null ? void 0 : _O.kpi) == null ? void 0 : _P.top_consumers) ?? "Top 5 (30 dias)")}</strong></div><div class="card-body p-2" data-v-9eb22c9b${_scopeId}>`);
            if (__props.topConsumers.length === 0) {
              _push2(`<div class="text-muted text-center small py-3" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_R = (_Q = __props.t) == null ? void 0 : _Q.kpi) == null ? void 0 : _R.no_consumers) ?? "Nenhum consumo no período.")}</div>`);
            } else {
              _push2(`<table class="table table-sm table-hover mb-0" data-v-9eb22c9b${_scopeId}><tbody data-v-9eb22c9b${_scopeId}><!--[-->`);
              ssrRenderList(__props.topConsumers, (c, i) => {
                _push2(`<tr data-v-9eb22c9b${_scopeId}><td class="ps-2" style="${ssrRenderStyle({ "width": "24px" })}" data-v-9eb22c9b${_scopeId}><span class="badge bg-light text-dark" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(i + 1)}</span></td><td data-v-9eb22c9b${_scopeId}><div class="fw-semibold small d-flex align-items-center gap-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(c.entity_name)} `);
                if (c.is_internal) {
                  _push2(`<span class="badge bg-primary-subtle text-primary small ms-1" data-v-9eb22c9b${_scopeId}><i class="ti ti-building" data-v-9eb22c9b${_scopeId}></i></span>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</div><small class="text-muted" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(c.purchases_total)} compras</small></td><td class="text-end" data-v-9eb22c9b${_scopeId}><div class="fw-semibold small" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(c.credits_total.toLocaleString("pt-BR"))}</div><small class="text-muted" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(c.amount_formatted)}</small></td></tr>`);
              });
              _push2(`<!--]--></tbody></table>`);
            }
            _push2(`</div></div></div><div class="col-12 col-xl-4" data-v-9eb22c9b${_scopeId}><div class="card h-100" data-v-9eb22c9b${_scopeId}><div class="card-header py-2 d-flex align-items-center" data-v-9eb22c9b${_scopeId}><i class="ti ti-filter text-secondary me-2" data-v-9eb22c9b${_scopeId}></i><strong class="small" data-v-9eb22c9b${_scopeId}>Filtros</strong></div><div class="card-body p-3" data-v-9eb22c9b${_scopeId}><form class="row g-2" data-v-9eb22c9b${_scopeId}><div class="col-6" data-v-9eb22c9b${_scopeId}><label class="form-label small mb-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_T = (_S = __props.t) == null ? void 0 : _S.filters) == null ? void 0 : _T.status) ?? "Status")}</label><select class="form-select form-select-sm" data-v-9eb22c9b${_scopeId}><option value="" data-v-9eb22c9b${ssrIncludeBooleanAttr(Array.isArray(filterForm.status) ? ssrLooseContain(filterForm.status, "") : ssrLooseEqual(filterForm.status, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(((_V = (_U = __props.t) == null ? void 0 : _U.filters) == null ? void 0 : _V.all) ?? "Todos")}</option><!--[-->`);
            ssrRenderList(__props.statusOptions, (s) => {
              _push2(`<option${ssrRenderAttr("value", s.value)} data-v-9eb22c9b${ssrIncludeBooleanAttr(Array.isArray(filterForm.status) ? ssrLooseContain(filterForm.status, s.value) : ssrLooseEqual(filterForm.status, s.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(s.label)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-6" data-v-9eb22c9b${_scopeId}><label class="form-label small mb-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_X = (_W = __props.t) == null ? void 0 : _W.filters) == null ? void 0 : _X.provider) ?? "Provedor")}</label><select class="form-select form-select-sm" data-v-9eb22c9b${_scopeId}><option value="" data-v-9eb22c9b${ssrIncludeBooleanAttr(Array.isArray(filterForm.provider) ? ssrLooseContain(filterForm.provider, "") : ssrLooseEqual(filterForm.provider, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(((_Z = (_Y = __props.t) == null ? void 0 : _Y.filters) == null ? void 0 : _Z.all) ?? "Todos")}</option><!--[-->`);
            ssrRenderList(__props.providerOptions, (p) => {
              _push2(`<option${ssrRenderAttr("value", p.value)} data-v-9eb22c9b${ssrIncludeBooleanAttr(Array.isArray(filterForm.provider) ? ssrLooseContain(filterForm.provider, p.value) : ssrLooseEqual(filterForm.provider, p.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(p.label)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-12" data-v-9eb22c9b${_scopeId}><label class="form-label small mb-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_$ = (__ = __props.t) == null ? void 0 : __.filters) == null ? void 0 : _$.entity) ?? "Empresa")}</label><select class="form-select form-select-sm" data-v-9eb22c9b${_scopeId}><option value="" data-v-9eb22c9b${ssrIncludeBooleanAttr(Array.isArray(filterForm.entity_id) ? ssrLooseContain(filterForm.entity_id, "") : ssrLooseEqual(filterForm.entity_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(((_ba = (_aa = __props.t) == null ? void 0 : _aa.filters) == null ? void 0 : _ba.all) ?? "Todas")}</option><!--[-->`);
            ssrRenderList(__props.entities, (e) => {
              _push2(`<option${ssrRenderAttr("value", e.id)} data-v-9eb22c9b${ssrIncludeBooleanAttr(Array.isArray(filterForm.entity_id) ? ssrLooseContain(filterForm.entity_id, e.id) : ssrLooseEqual(filterForm.entity_id, e.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(e.name)}${ssrInterpolate(!e.is_client ? " ★" : "")}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-6" data-v-9eb22c9b${_scopeId}><label class="form-label small mb-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_da = (_ca = __props.t) == null ? void 0 : _ca.filters) == null ? void 0 : _da.date_from) ?? "De")}</label><input${ssrRenderAttr("value", filterForm.date_from)} type="date" class="form-control form-control-sm" data-v-9eb22c9b${_scopeId}></div><div class="col-6" data-v-9eb22c9b${_scopeId}><label class="form-label small mb-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_fa = (_ea = __props.t) == null ? void 0 : _ea.filters) == null ? void 0 : _fa.date_to) ?? "Até")}</label><input${ssrRenderAttr("value", filterForm.date_to)} type="date" class="form-control form-control-sm" data-v-9eb22c9b${_scopeId}></div><div class="col-12 d-flex justify-content-between mt-1" data-v-9eb22c9b${_scopeId}><button type="button" class="btn btn-sm btn-link text-muted p-0" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_ha = (_ga = __props.t) == null ? void 0 : _ga.filters) == null ? void 0 : _ha.clear) ?? "Limpar")}</button><button type="submit" class="btn btn-sm btn-primary" data-v-9eb22c9b${_scopeId}><i class="ti ti-search me-1" data-v-9eb22c9b${_scopeId}></i>Filtrar </button></div></form></div></div></div></div><div class="card" data-v-9eb22c9b${_scopeId}><div class="table-responsive" data-v-9eb22c9b${_scopeId}><table class="table table-hover align-middle mb-0" data-v-9eb22c9b${_scopeId}><thead class="table-light" data-v-9eb22c9b${_scopeId}><tr data-v-9eb22c9b${_scopeId}><th class="ps-3" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_ja = (_ia = __props.t) == null ? void 0 : _ia.columns) == null ? void 0 : _ja.created_at) ?? "Solicitado em")}</th><th data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_la = (_ka = __props.t) == null ? void 0 : _ka.columns) == null ? void 0 : _la.entity) ?? "Empresa")}</th><th data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_na = (_ma = __props.t) == null ? void 0 : _ma.columns) == null ? void 0 : _na.provider) ?? "Provedor")}</th><th data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_pa = (_oa = __props.t) == null ? void 0 : _oa.columns) == null ? void 0 : _pa.package) ?? "Pacote")}</th><th class="text-end" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_ra = (_qa = __props.t) == null ? void 0 : _qa.columns) == null ? void 0 : _ra.credits) ?? "Créditos")}</th><th class="text-end" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_ta = (_sa = __props.t) == null ? void 0 : _sa.columns) == null ? void 0 : _ta.amount) ?? "Valor")}</th><th data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_va = (_ua = __props.t) == null ? void 0 : _ua.columns) == null ? void 0 : _va.requested_by) ?? "Solicitante")}</th><th data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_xa = (_wa = __props.t) == null ? void 0 : _wa.columns) == null ? void 0 : _xa.status) ?? "Status")}</th><th class="text-end pe-3" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_za = (_ya = __props.t) == null ? void 0 : _ya.columns) == null ? void 0 : _za.actions) ?? "Ações")}</th></tr></thead><tbody data-v-9eb22c9b${_scopeId}>`);
            if (!hasResults.value) {
              _push2(`<tr data-v-9eb22c9b${_scopeId}><td colspan="9" class="text-center text-muted small py-4" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(((_Aa = __props.t) == null ? void 0 : _Aa.empty) ?? "Nenhum pedido encontrado.")}</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.purchases.data, (p) => {
              _push2(`<tr style="${ssrRenderStyle({ "cursor": "pointer" })}" data-v-9eb22c9b${_scopeId}><td class="ps-3" data-v-9eb22c9b${_scopeId}><div class="small" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(p.created_at)}</div></td><td data-v-9eb22c9b${_scopeId}><div class="fw-semibold small d-flex align-items-center gap-1" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(p.entity_name ?? "—")} `);
              if (p.is_internal) {
                _push2(`<span class="badge bg-primary-subtle text-primary small ms-1" data-v-9eb22c9b${_scopeId}><i class="ti ti-building" data-v-9eb22c9b${_scopeId}></i></span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></td><td data-v-9eb22c9b${_scopeId}>`);
              if (p.provider) {
                _push2(`<span class="${ssrRenderClass(providerBadgeClass(p.provider))}" data-v-9eb22c9b${_scopeId}><i class="${ssrRenderClass([providerIcon(p.provider), "me-1"])}" data-v-9eb22c9b${_scopeId}></i> ${ssrInterpolate(p.provider_label)}</span>`);
              } else {
                _push2(`<span class="text-muted small" data-v-9eb22c9b${_scopeId}>—</span>`);
              }
              _push2(`</td><td data-v-9eb22c9b${_scopeId}><div class="small" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(p.package_name)}</div><small class="text-muted" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(p.package_code)}</small></td><td class="text-end fw-semibold" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(p.credits.toLocaleString("pt-BR"))}</td><td class="text-end fw-semibold" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(p.amount_formatted)}</td><td data-v-9eb22c9b${_scopeId}><div class="small" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(p.requested_by ?? "—")}</div>`);
              if (p.requested_email) {
                _push2(`<small class="text-muted" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(p.requested_email)}</small>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</td><td data-v-9eb22c9b${_scopeId}><span class="${ssrRenderClass([p.status_badge, "badge"])}" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(p.status_label)}</span></td><td class="text-end pe-3" data-v-9eb22c9b${_scopeId}>`);
              _push2(ssrRenderComponent(ActionIconGroup, null, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  var _a3, _b2, _c2, _d2, _e2, _f2, _g2, _h2, _i2, _j2, _k2, _l2, _m2, _n2, _o2, _p2, _q2, _r2, _s2, _t2;
                  if (_push3) {
                    _push3(ssrRenderComponent(_sfc_main$3, {
                      icon: "ti ti-eye",
                      variant: "info",
                      title: ((_b2 = (_a3 = __props.t) == null ? void 0 : _a3.actions) == null ? void 0 : _b2.view) ?? "Detalhes",
                      onClick: ($event) => openDetail(p)
                    }, null, _parent3, _scopeId2));
                    if (__props.permissions.credit && p.allowed.credit) {
                      _push3(ssrRenderComponent(_sfc_main$3, {
                        icon: "ti ti-check",
                        variant: "success",
                        title: ((_d2 = (_c2 = __props.t) == null ? void 0 : _c2.actions) == null ? void 0 : _d2.credit) ?? "Aprovar e creditar",
                        onClick: ($event) => onCredit(p)
                      }, null, _parent3, _scopeId2));
                    } else {
                      _push3(`<!---->`);
                    }
                    if (__props.permissions.cancel && p.allowed.cancel) {
                      _push3(ssrRenderComponent(_sfc_main$3, {
                        icon: "ti ti-x",
                        variant: "secondary",
                        title: ((_f2 = (_e2 = __props.t) == null ? void 0 : _e2.actions) == null ? void 0 : _f2.cancel) ?? "Cancelar",
                        onClick: ($event) => onCancel(p)
                      }, null, _parent3, _scopeId2));
                    } else {
                      _push3(`<!---->`);
                    }
                    if (__props.permissions.fail && p.allowed.fail) {
                      _push3(ssrRenderComponent(_sfc_main$3, {
                        icon: "ti ti-alert-triangle",
                        variant: "warning",
                        title: ((_h2 = (_g2 = __props.t) == null ? void 0 : _g2.actions) == null ? void 0 : _h2.fail) ?? "Marcar como falha",
                        onClick: ($event) => onMarkFailed(p)
                      }, null, _parent3, _scopeId2));
                    } else {
                      _push3(`<!---->`);
                    }
                    if (__props.permissions.refund && p.allowed.refund) {
                      _push3(ssrRenderComponent(_sfc_main$3, {
                        icon: "ti ti-arrow-back-up",
                        variant: "danger",
                        title: ((_j2 = (_i2 = __props.t) == null ? void 0 : _i2.actions) == null ? void 0 : _j2.refund) ?? "Reembolsar",
                        onClick: ($event) => onRefund(p)
                      }, null, _parent3, _scopeId2));
                    } else {
                      _push3(`<!---->`);
                    }
                  } else {
                    return [
                      createVNode(_sfc_main$3, {
                        icon: "ti ti-eye",
                        variant: "info",
                        title: ((_l2 = (_k2 = __props.t) == null ? void 0 : _k2.actions) == null ? void 0 : _l2.view) ?? "Detalhes",
                        onClick: ($event) => openDetail(p)
                      }, null, 8, ["title", "onClick"]),
                      __props.permissions.credit && p.allowed.credit ? (openBlock(), createBlock(_sfc_main$3, {
                        key: 0,
                        icon: "ti ti-check",
                        variant: "success",
                        title: ((_n2 = (_m2 = __props.t) == null ? void 0 : _m2.actions) == null ? void 0 : _n2.credit) ?? "Aprovar e creditar",
                        onClick: ($event) => onCredit(p)
                      }, null, 8, ["title", "onClick"])) : createCommentVNode("", true),
                      __props.permissions.cancel && p.allowed.cancel ? (openBlock(), createBlock(_sfc_main$3, {
                        key: 1,
                        icon: "ti ti-x",
                        variant: "secondary",
                        title: ((_p2 = (_o2 = __props.t) == null ? void 0 : _o2.actions) == null ? void 0 : _p2.cancel) ?? "Cancelar",
                        onClick: ($event) => onCancel(p)
                      }, null, 8, ["title", "onClick"])) : createCommentVNode("", true),
                      __props.permissions.fail && p.allowed.fail ? (openBlock(), createBlock(_sfc_main$3, {
                        key: 2,
                        icon: "ti ti-alert-triangle",
                        variant: "warning",
                        title: ((_r2 = (_q2 = __props.t) == null ? void 0 : _q2.actions) == null ? void 0 : _r2.fail) ?? "Marcar como falha",
                        onClick: ($event) => onMarkFailed(p)
                      }, null, 8, ["title", "onClick"])) : createCommentVNode("", true),
                      __props.permissions.refund && p.allowed.refund ? (openBlock(), createBlock(_sfc_main$3, {
                        key: 3,
                        icon: "ti ti-arrow-back-up",
                        variant: "danger",
                        title: ((_t2 = (_s2 = __props.t) == null ? void 0 : _s2.actions) == null ? void 0 : _t2.refund) ?? "Reembolsar",
                        onClick: ($event) => onRefund(p)
                      }, null, 8, ["title", "onClick"])) : createCommentVNode("", true)
                    ];
                  }
                }),
                _: 2
              }, _parent2, _scopeId));
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div>`);
            if (hasResults.value && ((_Ba = __props.purchases.meta) == null ? void 0 : _Ba.last_page) > 1) {
              _push2(`<div class="card-footer py-2 d-flex justify-content-between align-items-center" data-v-9eb22c9b${_scopeId}><small class="text-muted" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(__props.purchases.meta.from)}–${ssrInterpolate(__props.purchases.meta.to)} de ${ssrInterpolate(__props.purchases.meta.total)}</small><div class="btn-group btn-group-sm" data-v-9eb22c9b${_scopeId}><button class="btn btn-outline-secondary"${ssrIncludeBooleanAttr(!__props.purchases.links.prev) ? " disabled" : ""} data-v-9eb22c9b${_scopeId}><i class="ti ti-chevron-left" data-v-9eb22c9b${_scopeId}></i></button><span class="btn btn-outline-secondary disabled" data-v-9eb22c9b${_scopeId}>${ssrInterpolate(__props.purchases.meta.current_page)} / ${ssrInterpolate(__props.purchases.meta.last_page)}</span><button class="btn btn-outline-secondary"${ssrIncludeBooleanAttr(!__props.purchases.links.next) ? " disabled" : ""} data-v-9eb22c9b${_scopeId}><i class="ti ti-chevron-right" data-v-9eb22c9b${_scopeId}></i></button></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div>`);
            if (drawerOpen.value) {
              _push2(ssrRenderComponent(_sfc_main$4, {
                open: drawerOpen.value,
                purchase: drawerPurchase.value,
                permissions: __props.permissions,
                t: __props.t,
                onClose: closeDetail,
                onCredit,
                onCancel,
                onFail: onMarkFailed,
                onRefund
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
            _push2(ssrRenderComponent(_sfc_main$5, {
              open: unref(reasonModal).open,
              title: unref(reasonModal).title,
              message: unref(reasonModal).message,
              "confirm-variant": unref(reasonModal).confirmVariant,
              saving: unref(reasonModal).saving,
              onClose: unref(closeReasonModal),
              onConfirm: unref(handleReasonConfirm)
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$6, {
              ref_key: "manualModalRef",
              ref: manualModalRef,
              open: manualModalOpen.value,
              entities: __props.entities,
              providers: __props.providerOptions,
              permissions: __props.permissions,
              "preset-entity-id": presetEntityId.value,
              t: __props.t,
              onClose: closeManualModal,
              onSubmit: submitManual
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$7, {
              ref_key: "topupModalRef",
              ref: topupModalRef,
              open: topupModalOpen.value,
              "preset-provider": presetTopupProvider.value,
              t: __props.t,
              onClose: closeTopupModal,
              onSubmit: submitTopup
            }, null, _parent2, _scopeId));
          } else {
            return [
              createVNode("div", { class: "ai-credit-purchases-screen" }, [
                createVNode(_sfc_main$1, {
                  title: ((_Ca = __props.t) == null ? void 0 : _Ca.title) ?? "Compras de créditos IA",
                  subtitle: (_Da = __props.t) == null ? void 0 : _Da.subtitle,
                  total: ((_Fa = (_Ea = __props.purchases) == null ? void 0 : _Ea.meta) == null ? void 0 : _Fa.total) > 0 ? __props.purchases.meta.total : null
                }, {
                  actions: withCtx(() => {
                    var _a3, _b2, _c2;
                    return [
                      ((_a3 = __props.permissions) == null ? void 0 : _a3.create_manual) ? (openBlock(), createBlock("button", {
                        key: 0,
                        type: "button",
                        class: "btn btn-success btn-sm",
                        onClick: ($event) => openManualModal()
                      }, [
                        createVNode("i", { class: "ti ti-coin-plus me-1" }),
                        createTextVNode(" " + toDisplayString(((_c2 = (_b2 = __props.t) == null ? void 0 : _b2.actions) == null ? void 0 : _c2.create_manual) ?? "Adicionar crédito manual"), 1)
                      ], 8, ["onClick"])) : createCommentVNode("", true)
                    ];
                  }),
                  _: 1
                }, 8, ["title", "subtitle", "total"]),
                __props.internalWallet ? (openBlock(), createBlock(InternalWalletCard, {
                  key: 0,
                  wallet: __props.internalWallet,
                  permissions: __props.permissions,
                  t: __props.t,
                  onAddCredit: openManualModal
                }, null, 8, ["wallet", "permissions", "t"])) : createCommentVNode("", true),
                __props.providerCosts ? (openBlock(), createBlock(ProviderCostsCard, {
                  key: 1,
                  costs: __props.providerCosts,
                  permissions: __props.permissions,
                  t: __props.t,
                  onAddTopup: openTopupModal
                }, null, 8, ["costs", "permissions", "t"])) : createCommentVNode("", true),
                createVNode("div", { class: "row g-2 mb-3" }, [
                  createVNode("div", { class: "col-12 col-md-6 col-xl-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body p-3" }, [
                        createVNode("div", { class: "d-flex align-items-center justify-content-between" }, [
                          createVNode("span", { class: "text-muted small" }, toDisplayString(((_Ha = (_Ga = __props.t) == null ? void 0 : _Ga.kpi) == null ? void 0 : _Ha.pending) ?? "Pendentes"), 1),
                          createVNode("i", { class: "ti ti-clock text-warning" })
                        ]),
                        createVNode("h4", { class: "mb-0 mt-1" }, toDisplayString(((_Ja = (_Ia = __props.kpis) == null ? void 0 : _Ia.pending) == null ? void 0 : _Ja.count) ?? 0), 1),
                        createVNode("small", { class: "text-warning fw-semibold" }, toDisplayString(((_La = (_Ka = __props.kpis) == null ? void 0 : _Ka.pending) == null ? void 0 : _La.amount_formatted) ?? "R$ 0,00"), 1),
                        createVNode("div", { class: "small text-muted mt-1" }, toDisplayString((_Na = (_Ma = __props.t) == null ? void 0 : _Ma.kpi) == null ? void 0 : _Na.pending_help), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-12 col-md-6 col-xl-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body p-3" }, [
                        createVNode("div", { class: "d-flex align-items-center justify-content-between" }, [
                          createVNode("span", { class: "text-muted small" }, toDisplayString(((_Pa = (_Oa = __props.t) == null ? void 0 : _Oa.kpi) == null ? void 0 : _Pa.credited_30d) ?? "Creditados (30d)"), 1),
                          createVNode("i", { class: "ti ti-coin text-success" })
                        ]),
                        createVNode("h4", { class: "mb-0 mt-1" }, toDisplayString(((_Ra = (_Qa = __props.kpis) == null ? void 0 : _Qa.credited_30d) == null ? void 0 : _Ra.amount_formatted) ?? "R$ 0,00"), 1),
                        createVNode("small", { class: "text-success fw-semibold" }, toDisplayString(((_Ta = (_Sa = __props.kpis) == null ? void 0 : _Sa.credited_30d) == null ? void 0 : _Ta.credits_sold) ?? 0) + " " + toDisplayString(((_Va = (_Ua = __props.t) == null ? void 0 : _Ua.kpi) == null ? void 0 : _Va.credits_sold) ?? "créditos vendidos"), 1),
                        createVNode("div", { class: "small text-muted mt-1" }, toDisplayString(((_Xa = (_Wa = __props.kpis) == null ? void 0 : _Wa.credited_30d) == null ? void 0 : _Xa.count) ?? 0) + " pedidos ", 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-12 col-md-6 col-xl-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body p-3" }, [
                        createVNode("div", { class: "d-flex align-items-center justify-content-between" }, [
                          createVNode("span", { class: "text-muted small" }, toDisplayString(((_Za = (_Ya = __props.t) == null ? void 0 : _Ya.kpi) == null ? void 0 : _Za.conversion) ?? "Conversão (30d)"), 1),
                          createVNode("i", { class: "ti ti-trending-up text-info" })
                        ]),
                        createVNode("h4", { class: "mb-0 mt-1" }, toDisplayString(((_$a = (__a = __props.kpis) == null ? void 0 : __a.funnel_30d) == null ? void 0 : _$a.conversion_pct) ?? 0) + "%", 1),
                        createVNode("small", { class: "text-info fw-semibold" }, toDisplayString(((_bb = (_ab = __props.kpis) == null ? void 0 : _ab.funnel_30d) == null ? void 0 : _bb.credited) ?? 0) + " de " + toDisplayString(((_db = (_cb = __props.kpis) == null ? void 0 : _cb.funnel_30d) == null ? void 0 : _db.total) ?? 0), 1),
                        createVNode("div", { class: "small text-muted mt-1" }, toDisplayString((_fb = (_eb = __props.t) == null ? void 0 : _eb.kpi) == null ? void 0 : _fb.conversion_help), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-12 col-md-6 col-xl-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body p-3" }, [
                        createVNode("div", { class: "d-flex align-items-center justify-content-between" }, [
                          createVNode("span", { class: "text-muted small" }, toDisplayString(((_hb = (_gb = __props.t) == null ? void 0 : _gb.kpi) == null ? void 0 : _hb.abandonment) ?? "Abandono (30d)"), 1),
                          createVNode("i", { class: "ti ti-x text-danger" })
                        ]),
                        createVNode("h4", { class: "mb-0 mt-1" }, toDisplayString(((_jb = (_ib = __props.kpis) == null ? void 0 : _ib.funnel_30d) == null ? void 0 : _jb.abandonment_pct) ?? 0) + "%", 1),
                        createVNode("small", { class: "text-danger fw-semibold" }, toDisplayString(((_lb = (_kb = __props.kpis) == null ? void 0 : _kb.funnel_30d) == null ? void 0 : _lb.cancelled) ?? 0) + " cancelados / " + toDisplayString(((_nb = (_mb = __props.kpis) == null ? void 0 : _mb.funnel_30d) == null ? void 0 : _nb.failed) ?? 0) + " falhas ", 1),
                        createVNode("div", { class: "small text-muted mt-1" }, "do funil dos últimos 30 dias")
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "row g-2 mb-3" }, [
                  createVNode("div", { class: "col-12 col-xl-4" }, [
                    createVNode(_sfc_main$2, {
                      consumption: __props.consumptionByProvider,
                      t: __props.t
                    }, null, 8, ["consumption", "t"])
                  ]),
                  createVNode("div", { class: "col-12 col-xl-4" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-header py-2 d-flex align-items-center" }, [
                        createVNode("i", { class: "ti ti-trophy text-warning me-2" }),
                        createVNode("strong", { class: "small" }, toDisplayString(((_pb = (_ob = __props.t) == null ? void 0 : _ob.kpi) == null ? void 0 : _pb.top_consumers) ?? "Top 5 (30 dias)"), 1)
                      ]),
                      createVNode("div", { class: "card-body p-2" }, [
                        __props.topConsumers.length === 0 ? (openBlock(), createBlock("div", {
                          key: 0,
                          class: "text-muted text-center small py-3"
                        }, toDisplayString(((_rb = (_qb = __props.t) == null ? void 0 : _qb.kpi) == null ? void 0 : _rb.no_consumers) ?? "Nenhum consumo no período."), 1)) : (openBlock(), createBlock("table", {
                          key: 1,
                          class: "table table-sm table-hover mb-0"
                        }, [
                          createVNode("tbody", null, [
                            (openBlock(true), createBlock(Fragment, null, renderList(__props.topConsumers, (c, i) => {
                              return openBlock(), createBlock("tr", {
                                key: c.entity_id
                              }, [
                                createVNode("td", {
                                  class: "ps-2",
                                  style: { "width": "24px" }
                                }, [
                                  createVNode("span", { class: "badge bg-light text-dark" }, toDisplayString(i + 1), 1)
                                ]),
                                createVNode("td", null, [
                                  createVNode("div", { class: "fw-semibold small d-flex align-items-center gap-1" }, [
                                    createTextVNode(toDisplayString(c.entity_name) + " ", 1),
                                    c.is_internal ? (openBlock(), createBlock("span", {
                                      key: 0,
                                      class: "badge bg-primary-subtle text-primary small ms-1"
                                    }, [
                                      createVNode("i", { class: "ti ti-building" })
                                    ])) : createCommentVNode("", true)
                                  ]),
                                  createVNode("small", { class: "text-muted" }, toDisplayString(c.purchases_total) + " compras", 1)
                                ]),
                                createVNode("td", { class: "text-end" }, [
                                  createVNode("div", { class: "fw-semibold small" }, toDisplayString(c.credits_total.toLocaleString("pt-BR")), 1),
                                  createVNode("small", { class: "text-muted" }, toDisplayString(c.amount_formatted), 1)
                                ])
                              ]);
                            }), 128))
                          ])
                        ]))
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-12 col-xl-4" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-header py-2 d-flex align-items-center" }, [
                        createVNode("i", { class: "ti ti-filter text-secondary me-2" }),
                        createVNode("strong", { class: "small" }, "Filtros")
                      ]),
                      createVNode("div", { class: "card-body p-3" }, [
                        createVNode("form", {
                          onSubmit: withModifiers(applyFilters, ["prevent"]),
                          class: "row g-2"
                        }, [
                          createVNode("div", { class: "col-6" }, [
                            createVNode("label", { class: "form-label small mb-1" }, toDisplayString(((_tb = (_sb = __props.t) == null ? void 0 : _sb.filters) == null ? void 0 : _tb.status) ?? "Status"), 1),
                            withDirectives(createVNode("select", {
                              "onUpdate:modelValue": ($event) => filterForm.status = $event,
                              class: "form-select form-select-sm"
                            }, [
                              createVNode("option", { value: "" }, toDisplayString(((_vb = (_ub = __props.t) == null ? void 0 : _ub.filters) == null ? void 0 : _vb.all) ?? "Todos"), 1),
                              (openBlock(true), createBlock(Fragment, null, renderList(__props.statusOptions, (s) => {
                                return openBlock(), createBlock("option", {
                                  key: s.value,
                                  value: s.value
                                }, toDisplayString(s.label), 9, ["value"]);
                              }), 128))
                            ], 8, ["onUpdate:modelValue"]), [
                              [vModelSelect, filterForm.status]
                            ])
                          ]),
                          createVNode("div", { class: "col-6" }, [
                            createVNode("label", { class: "form-label small mb-1" }, toDisplayString(((_xb = (_wb = __props.t) == null ? void 0 : _wb.filters) == null ? void 0 : _xb.provider) ?? "Provedor"), 1),
                            withDirectives(createVNode("select", {
                              "onUpdate:modelValue": ($event) => filterForm.provider = $event,
                              class: "form-select form-select-sm"
                            }, [
                              createVNode("option", { value: "" }, toDisplayString(((_zb = (_yb = __props.t) == null ? void 0 : _yb.filters) == null ? void 0 : _zb.all) ?? "Todos"), 1),
                              (openBlock(true), createBlock(Fragment, null, renderList(__props.providerOptions, (p) => {
                                return openBlock(), createBlock("option", {
                                  key: p.value,
                                  value: p.value
                                }, toDisplayString(p.label), 9, ["value"]);
                              }), 128))
                            ], 8, ["onUpdate:modelValue"]), [
                              [vModelSelect, filterForm.provider]
                            ])
                          ]),
                          createVNode("div", { class: "col-12" }, [
                            createVNode("label", { class: "form-label small mb-1" }, toDisplayString(((_Bb = (_Ab = __props.t) == null ? void 0 : _Ab.filters) == null ? void 0 : _Bb.entity) ?? "Empresa"), 1),
                            withDirectives(createVNode("select", {
                              "onUpdate:modelValue": ($event) => filterForm.entity_id = $event,
                              class: "form-select form-select-sm"
                            }, [
                              createVNode("option", { value: "" }, toDisplayString(((_Db = (_Cb = __props.t) == null ? void 0 : _Cb.filters) == null ? void 0 : _Db.all) ?? "Todas"), 1),
                              (openBlock(true), createBlock(Fragment, null, renderList(__props.entities, (e) => {
                                return openBlock(), createBlock("option", {
                                  key: e.id,
                                  value: e.id
                                }, toDisplayString(e.name) + toDisplayString(!e.is_client ? " ★" : ""), 9, ["value"]);
                              }), 128))
                            ], 8, ["onUpdate:modelValue"]), [
                              [vModelSelect, filterForm.entity_id]
                            ])
                          ]),
                          createVNode("div", { class: "col-6" }, [
                            createVNode("label", { class: "form-label small mb-1" }, toDisplayString(((_Fb = (_Eb = __props.t) == null ? void 0 : _Eb.filters) == null ? void 0 : _Fb.date_from) ?? "De"), 1),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => filterForm.date_from = $event,
                              type: "date",
                              class: "form-control form-control-sm"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, filterForm.date_from]
                            ])
                          ]),
                          createVNode("div", { class: "col-6" }, [
                            createVNode("label", { class: "form-label small mb-1" }, toDisplayString(((_Hb = (_Gb = __props.t) == null ? void 0 : _Gb.filters) == null ? void 0 : _Hb.date_to) ?? "Até"), 1),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => filterForm.date_to = $event,
                              type: "date",
                              class: "form-control form-control-sm"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, filterForm.date_to]
                            ])
                          ]),
                          createVNode("div", { class: "col-12 d-flex justify-content-between mt-1" }, [
                            createVNode("button", {
                              type: "button",
                              class: "btn btn-sm btn-link text-muted p-0",
                              onClick: clearFilters
                            }, toDisplayString(((_Jb = (_Ib = __props.t) == null ? void 0 : _Ib.filters) == null ? void 0 : _Jb.clear) ?? "Limpar"), 1),
                            createVNode("button", {
                              type: "submit",
                              class: "btn btn-sm btn-primary"
                            }, [
                              createVNode("i", { class: "ti ti-search me-1" }),
                              createTextVNode("Filtrar ")
                            ])
                          ])
                        ], 32)
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", { class: "ps-3" }, toDisplayString(((_Lb = (_Kb = __props.t) == null ? void 0 : _Kb.columns) == null ? void 0 : _Lb.created_at) ?? "Solicitado em"), 1),
                          createVNode("th", null, toDisplayString(((_Nb = (_Mb = __props.t) == null ? void 0 : _Mb.columns) == null ? void 0 : _Nb.entity) ?? "Empresa"), 1),
                          createVNode("th", null, toDisplayString(((_Pb = (_Ob = __props.t) == null ? void 0 : _Ob.columns) == null ? void 0 : _Pb.provider) ?? "Provedor"), 1),
                          createVNode("th", null, toDisplayString(((_Rb = (_Qb = __props.t) == null ? void 0 : _Qb.columns) == null ? void 0 : _Rb.package) ?? "Pacote"), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(((_Tb = (_Sb = __props.t) == null ? void 0 : _Sb.columns) == null ? void 0 : _Tb.credits) ?? "Créditos"), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(((_Vb = (_Ub = __props.t) == null ? void 0 : _Ub.columns) == null ? void 0 : _Vb.amount) ?? "Valor"), 1),
                          createVNode("th", null, toDisplayString(((_Xb = (_Wb = __props.t) == null ? void 0 : _Wb.columns) == null ? void 0 : _Xb.requested_by) ?? "Solicitante"), 1),
                          createVNode("th", null, toDisplayString(((_Zb = (_Yb = __props.t) == null ? void 0 : _Yb.columns) == null ? void 0 : _Zb.status) ?? "Status"), 1),
                          createVNode("th", { class: "text-end pe-3" }, toDisplayString(((_$b = (__b = __props.t) == null ? void 0 : __b.columns) == null ? void 0 : _$b.actions) ?? "Ações"), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        !hasResults.value ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "9",
                            class: "text-center text-muted small py-4"
                          }, toDisplayString(((_ac = __props.t) == null ? void 0 : _ac.empty) ?? "Nenhum pedido encontrado."), 1)
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.purchases.data, (p) => {
                          return openBlock(), createBlock("tr", {
                            key: p.id,
                            onClick: ($event) => openDetail(p),
                            style: { "cursor": "pointer" }
                          }, [
                            createVNode("td", { class: "ps-3" }, [
                              createVNode("div", { class: "small" }, toDisplayString(p.created_at), 1)
                            ]),
                            createVNode("td", null, [
                              createVNode("div", { class: "fw-semibold small d-flex align-items-center gap-1" }, [
                                createTextVNode(toDisplayString(p.entity_name ?? "—") + " ", 1),
                                p.is_internal ? (openBlock(), createBlock("span", {
                                  key: 0,
                                  class: "badge bg-primary-subtle text-primary small ms-1"
                                }, [
                                  createVNode("i", { class: "ti ti-building" })
                                ])) : createCommentVNode("", true)
                              ])
                            ]),
                            createVNode("td", null, [
                              p.provider ? (openBlock(), createBlock("span", {
                                key: 0,
                                class: providerBadgeClass(p.provider)
                              }, [
                                createVNode("i", {
                                  class: [providerIcon(p.provider), "me-1"]
                                }, null, 2),
                                createTextVNode(" " + toDisplayString(p.provider_label), 1)
                              ], 2)) : (openBlock(), createBlock("span", {
                                key: 1,
                                class: "text-muted small"
                              }, "—"))
                            ]),
                            createVNode("td", null, [
                              createVNode("div", { class: "small" }, toDisplayString(p.package_name), 1),
                              createVNode("small", { class: "text-muted" }, toDisplayString(p.package_code), 1)
                            ]),
                            createVNode("td", { class: "text-end fw-semibold" }, toDisplayString(p.credits.toLocaleString("pt-BR")), 1),
                            createVNode("td", { class: "text-end fw-semibold" }, toDisplayString(p.amount_formatted), 1),
                            createVNode("td", null, [
                              createVNode("div", { class: "small" }, toDisplayString(p.requested_by ?? "—"), 1),
                              p.requested_email ? (openBlock(), createBlock("small", {
                                key: 0,
                                class: "text-muted"
                              }, toDisplayString(p.requested_email), 1)) : createCommentVNode("", true)
                            ]),
                            createVNode("td", null, [
                              createVNode("span", {
                                class: ["badge", p.status_badge]
                              }, toDisplayString(p.status_label), 3)
                            ]),
                            createVNode("td", {
                              class: "text-end pe-3",
                              onClick: withModifiers(() => {
                              }, ["stop"])
                            }, [
                              createVNode(ActionIconGroup, null, {
                                default: withCtx(() => {
                                  var _a3, _b2, _c2, _d2, _e2, _f2, _g2, _h2, _i2, _j2;
                                  return [
                                    createVNode(_sfc_main$3, {
                                      icon: "ti ti-eye",
                                      variant: "info",
                                      title: ((_b2 = (_a3 = __props.t) == null ? void 0 : _a3.actions) == null ? void 0 : _b2.view) ?? "Detalhes",
                                      onClick: ($event) => openDetail(p)
                                    }, null, 8, ["title", "onClick"]),
                                    __props.permissions.credit && p.allowed.credit ? (openBlock(), createBlock(_sfc_main$3, {
                                      key: 0,
                                      icon: "ti ti-check",
                                      variant: "success",
                                      title: ((_d2 = (_c2 = __props.t) == null ? void 0 : _c2.actions) == null ? void 0 : _d2.credit) ?? "Aprovar e creditar",
                                      onClick: ($event) => onCredit(p)
                                    }, null, 8, ["title", "onClick"])) : createCommentVNode("", true),
                                    __props.permissions.cancel && p.allowed.cancel ? (openBlock(), createBlock(_sfc_main$3, {
                                      key: 1,
                                      icon: "ti ti-x",
                                      variant: "secondary",
                                      title: ((_f2 = (_e2 = __props.t) == null ? void 0 : _e2.actions) == null ? void 0 : _f2.cancel) ?? "Cancelar",
                                      onClick: ($event) => onCancel(p)
                                    }, null, 8, ["title", "onClick"])) : createCommentVNode("", true),
                                    __props.permissions.fail && p.allowed.fail ? (openBlock(), createBlock(_sfc_main$3, {
                                      key: 2,
                                      icon: "ti ti-alert-triangle",
                                      variant: "warning",
                                      title: ((_h2 = (_g2 = __props.t) == null ? void 0 : _g2.actions) == null ? void 0 : _h2.fail) ?? "Marcar como falha",
                                      onClick: ($event) => onMarkFailed(p)
                                    }, null, 8, ["title", "onClick"])) : createCommentVNode("", true),
                                    __props.permissions.refund && p.allowed.refund ? (openBlock(), createBlock(_sfc_main$3, {
                                      key: 3,
                                      icon: "ti ti-arrow-back-up",
                                      variant: "danger",
                                      title: ((_j2 = (_i2 = __props.t) == null ? void 0 : _i2.actions) == null ? void 0 : _j2.refund) ?? "Reembolsar",
                                      onClick: ($event) => onRefund(p)
                                    }, null, 8, ["title", "onClick"])) : createCommentVNode("", true)
                                  ];
                                }),
                                _: 2
                              }, 1024)
                            ], 8, ["onClick"])
                          ], 8, ["onClick"]);
                        }), 128))
                      ])
                    ])
                  ]),
                  hasResults.value && ((_bc = __props.purchases.meta) == null ? void 0 : _bc.last_page) > 1 ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "card-footer py-2 d-flex justify-content-between align-items-center"
                  }, [
                    createVNode("small", { class: "text-muted" }, toDisplayString(__props.purchases.meta.from) + "–" + toDisplayString(__props.purchases.meta.to) + " de " + toDisplayString(__props.purchases.meta.total), 1),
                    createVNode("div", { class: "btn-group btn-group-sm" }, [
                      createVNode("button", {
                        class: "btn btn-outline-secondary",
                        disabled: !__props.purchases.links.prev,
                        onClick: ($event) => goToPage(__props.purchases.links.prev)
                      }, [
                        createVNode("i", { class: "ti ti-chevron-left" })
                      ], 8, ["disabled", "onClick"]),
                      createVNode("span", { class: "btn btn-outline-secondary disabled" }, toDisplayString(__props.purchases.meta.current_page) + " / " + toDisplayString(__props.purchases.meta.last_page), 1),
                      createVNode("button", {
                        class: "btn btn-outline-secondary",
                        disabled: !__props.purchases.links.next,
                        onClick: ($event) => goToPage(__props.purchases.links.next)
                      }, [
                        createVNode("i", { class: "ti ti-chevron-right" })
                      ], 8, ["disabled", "onClick"])
                    ])
                  ])) : createCommentVNode("", true)
                ])
              ]),
              drawerOpen.value ? (openBlock(), createBlock(_sfc_main$4, {
                key: 0,
                open: drawerOpen.value,
                purchase: drawerPurchase.value,
                permissions: __props.permissions,
                t: __props.t,
                onClose: closeDetail,
                onCredit,
                onCancel,
                onFail: onMarkFailed,
                onRefund
              }, null, 8, ["open", "purchase", "permissions", "t"])) : createCommentVNode("", true),
              createVNode(_sfc_main$5, {
                open: unref(reasonModal).open,
                title: unref(reasonModal).title,
                message: unref(reasonModal).message,
                "confirm-variant": unref(reasonModal).confirmVariant,
                saving: unref(reasonModal).saving,
                onClose: unref(closeReasonModal),
                onConfirm: unref(handleReasonConfirm)
              }, null, 8, ["open", "title", "message", "confirm-variant", "saving", "onClose", "onConfirm"]),
              createVNode(_sfc_main$6, {
                ref_key: "manualModalRef",
                ref: manualModalRef,
                open: manualModalOpen.value,
                entities: __props.entities,
                providers: __props.providerOptions,
                permissions: __props.permissions,
                "preset-entity-id": presetEntityId.value,
                t: __props.t,
                onClose: closeManualModal,
                onSubmit: submitManual
              }, null, 8, ["open", "entities", "providers", "permissions", "preset-entity-id", "t"]),
              createVNode(_sfc_main$7, {
                ref_key: "topupModalRef",
                ref: topupModalRef,
                open: topupModalOpen.value,
                "preset-provider": presetTopupProvider.value,
                t: __props.t,
                onClose: closeTopupModal,
                onSubmit: submitTopup
              }, null, 8, ["open", "preset-provider", "t"])
            ];
          }
        }),
        _: 1
      }, _parent));
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/AiCreditPurchases/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const Index = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-9eb22c9b"]]);
export {
  Index as default
};
