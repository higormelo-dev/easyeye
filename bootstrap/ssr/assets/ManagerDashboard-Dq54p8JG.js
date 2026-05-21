import { computed, mergeProps, withCtx, createVNode, toDisplayString, createTextVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderStyle, ssrRenderAttr } from "vue/server-renderer";
import { usePage } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import _sfc_main$1 from "./PrimaryKpis-CtByvl7i.js";
import _sfc_main$2 from "./FinancialKpis-DSH7hqfh.js";
import _sfc_main$3 from "./MrrTrendChart-CFweQ76g.js";
import _sfc_main$6 from "./GrowthChart-6r6CCS_U.js";
import _sfc_main$4 from "./ConversionFunnel-BuoxcxD9.js";
import _sfc_main$5 from "./SubscriptionFunnel-ByQbuogs.js";
import _sfc_main$7 from "./TrialsExpiring-BbPuCpb_.js";
import _sfc_main$9 from "./RecentEntities-DvkUl36Z.js";
import _sfc_main$a from "./TopEntities-Be9trsQm.js";
import _sfc_main$8 from "./PartnersSummary-BoSwYttd.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "chart.js";
const _sfc_main = {
  __name: "ManagerDashboard",
  __ssrInlineRender: true,
  props: {
    greeting: { type: String, default: "" },
    primaryKpis: { type: Object, required: true },
    subscriptionKpis: { type: Object, required: true },
    financialKpis: { type: Object, required: true },
    mrrTrend: { type: Object, required: true },
    conversionFunnel: { type: Object, required: true },
    growthTrend: { type: Object, required: true },
    trialsExpiring: { type: Array, default: () => [] },
    recentEntities: { type: Array, default: () => [] },
    topEntities: { type: Array, default: () => [] },
    partnersSummary: { type: Object, required: true },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const page = usePage();
    const user = computed(() => {
      var _a;
      return ((_a = page.props.auth) == null ? void 0 : _a.user) ?? {};
    });
    const breadcrumbs = [];
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Dashboard",
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b;
          if (_push2) {
            _push2(`<div class="page-dashboard"${_scopeId}><div class="welcome-banner mb-4 mt-4"${_scopeId}><div class="d-flex align-items-center justify-content-between flex-wrap gap-3"${_scopeId}><div${_scopeId}><h4 class="mb-1"${_scopeId}>${ssrInterpolate(__props.greeting)}, ${ssrInterpolate((_a = user.value.name) == null ? void 0 : _a.split(" ")[0])}! 👋</h4><p${_scopeId}>${ssrInterpolate(__props.t.subtitle)}</p></div><div class="d-flex gap-2 flex-wrap" style="${ssrRenderStyle({ "position": "relative", "z-index": "1" })}"${_scopeId}><a${ssrRenderAttr("href", _ctx.route("manager.entities.index"))} class="btn btn-sm btn-banner"${_scopeId}><i class="ti ti-building me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_entities)}</a><a${ssrRenderAttr("href", _ctx.route("manager.plans.index"))} class="btn btn-sm btn-banner"${_scopeId}><i class="ti ti-list-check me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_plans)}</a><a${ssrRenderAttr("href", _ctx.route("manager.partners.index"))} class="btn btn-sm btn-banner btn-banner-solid"${_scopeId}><i class="ti ti-users-group me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_partners)}</a></div></div></div>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              "primary-kpis": __props.primaryKpis,
              "subscription-kpis": __props.subscriptionKpis,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$2, {
              "financial-kpis": __props.financialKpis,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`<div class="row g-3 mb-4"${_scopeId}><div class="col-12 col-lg-7"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$3, {
              "mrr-trend": __props.mrrTrend,
              "financial-kpis": __props.financialKpis,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="col-12 col-lg-5"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$4, {
              "conversion-funnel": __props.conversionFunnel,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div></div><div class="row g-3 mb-4"${_scopeId}><div class="col-12 col-lg-6"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$5, {
              "subscription-kpis": __props.subscriptionKpis,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="col-12 col-lg-6"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$6, {
              "growth-trend": __props.growthTrend,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div></div><div class="row g-3 mb-4"${_scopeId}><div class="col-12 col-lg-7"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$7, {
              "trials-expiring": __props.trialsExpiring,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="col-12 col-lg-5"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$8, {
              "partners-summary": __props.partnersSummary,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div></div><div class="row g-3 mb-4"${_scopeId}><div class="col-12"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$9, {
              "recent-entities": __props.recentEntities,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div></div><div class="row g-3 mb-4"${_scopeId}><div class="col-12"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$a, {
              "top-entities": __props.topEntities,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "page-dashboard" }, [
                createVNode("div", { class: "welcome-banner mb-4 mt-4" }, [
                  createVNode("div", { class: "d-flex align-items-center justify-content-between flex-wrap gap-3" }, [
                    createVNode("div", null, [
                      createVNode("h4", { class: "mb-1" }, toDisplayString(__props.greeting) + ", " + toDisplayString((_b = user.value.name) == null ? void 0 : _b.split(" ")[0]) + "! 👋", 1),
                      createVNode("p", null, toDisplayString(__props.t.subtitle), 1)
                    ]),
                    createVNode("div", {
                      class: "d-flex gap-2 flex-wrap",
                      style: { "position": "relative", "z-index": "1" }
                    }, [
                      createVNode("a", {
                        href: _ctx.route("manager.entities.index"),
                        class: "btn btn-sm btn-banner"
                      }, [
                        createVNode("i", { class: "ti ti-building me-1" }),
                        createTextVNode(toDisplayString(__props.t.btn_entities), 1)
                      ], 8, ["href"]),
                      createVNode("a", {
                        href: _ctx.route("manager.plans.index"),
                        class: "btn btn-sm btn-banner"
                      }, [
                        createVNode("i", { class: "ti ti-list-check me-1" }),
                        createTextVNode(toDisplayString(__props.t.btn_plans), 1)
                      ], 8, ["href"]),
                      createVNode("a", {
                        href: _ctx.route("manager.partners.index"),
                        class: "btn btn-sm btn-banner btn-banner-solid"
                      }, [
                        createVNode("i", { class: "ti ti-users-group me-1" }),
                        createTextVNode(toDisplayString(__props.t.btn_partners), 1)
                      ], 8, ["href"])
                    ])
                  ])
                ]),
                createVNode(_sfc_main$1, {
                  "primary-kpis": __props.primaryKpis,
                  "subscription-kpis": __props.subscriptionKpis,
                  t: __props.t
                }, null, 8, ["primary-kpis", "subscription-kpis", "t"]),
                createVNode(_sfc_main$2, {
                  "financial-kpis": __props.financialKpis,
                  t: __props.t
                }, null, 8, ["financial-kpis", "t"]),
                createVNode("div", { class: "row g-3 mb-4" }, [
                  createVNode("div", { class: "col-12 col-lg-7" }, [
                    createVNode(_sfc_main$3, {
                      "mrr-trend": __props.mrrTrend,
                      "financial-kpis": __props.financialKpis,
                      t: __props.t
                    }, null, 8, ["mrr-trend", "financial-kpis", "t"])
                  ]),
                  createVNode("div", { class: "col-12 col-lg-5" }, [
                    createVNode(_sfc_main$4, {
                      "conversion-funnel": __props.conversionFunnel,
                      t: __props.t
                    }, null, 8, ["conversion-funnel", "t"])
                  ])
                ]),
                createVNode("div", { class: "row g-3 mb-4" }, [
                  createVNode("div", { class: "col-12 col-lg-6" }, [
                    createVNode(_sfc_main$5, {
                      "subscription-kpis": __props.subscriptionKpis,
                      t: __props.t
                    }, null, 8, ["subscription-kpis", "t"])
                  ]),
                  createVNode("div", { class: "col-12 col-lg-6" }, [
                    createVNode(_sfc_main$6, {
                      "growth-trend": __props.growthTrend,
                      t: __props.t
                    }, null, 8, ["growth-trend", "t"])
                  ])
                ]),
                createVNode("div", { class: "row g-3 mb-4" }, [
                  createVNode("div", { class: "col-12 col-lg-7" }, [
                    createVNode(_sfc_main$7, {
                      "trials-expiring": __props.trialsExpiring,
                      t: __props.t
                    }, null, 8, ["trials-expiring", "t"])
                  ]),
                  createVNode("div", { class: "col-12 col-lg-5" }, [
                    createVNode(_sfc_main$8, {
                      "partners-summary": __props.partnersSummary,
                      t: __props.t
                    }, null, 8, ["partners-summary", "t"])
                  ])
                ]),
                createVNode("div", { class: "row g-3 mb-4" }, [
                  createVNode("div", { class: "col-12" }, [
                    createVNode(_sfc_main$9, {
                      "recent-entities": __props.recentEntities,
                      t: __props.t
                    }, null, 8, ["recent-entities", "t"])
                  ])
                ]),
                createVNode("div", { class: "row g-3 mb-4" }, [
                  createVNode("div", { class: "col-12" }, [
                    createVNode(_sfc_main$a, {
                      "top-entities": __props.topEntities,
                      t: __props.t
                    }, null, 8, ["top-entities", "t"])
                  ])
                ])
              ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/ManagerDashboard.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
