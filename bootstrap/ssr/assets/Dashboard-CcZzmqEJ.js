import { computed, mergeProps, withCtx, unref, createVNode, openBlock, createBlock, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent } from "vue/server-renderer";
import { usePage } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import _sfc_main$2 from "./WelcomeBanner-CTT5czyj.js";
import _sfc_main$3 from "./Activation-BQIic9MK.js";
import _sfc_main$4 from "./KpiCards-DxVNZls_.js";
import _sfc_main$5 from "./ModuleShortcuts-C5H4zsNf.js";
import _sfc_main$6 from "./ScheduleToday-DVL5h45N.js";
import _sfc_main$7 from "./DaySummary-CDuv2Heq.js";
import _sfc_main$8 from "./RecentPatients-Dl4x6Rxp.js";
import { _ as _sfc_main$1 } from "./LiveStatusBar-CQZOQaZ5.js";
import { u as useDashboardPolling } from "./useDashboardPolling-D1jTH2om.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Dashboard",
  __ssrInlineRender: true,
  props: {
    stats: { type: Object, required: true },
    scheduleToday: { type: Array, default: () => [] },
    recentPatients: { type: Array, default: () => [] },
    activation: { type: Array, default: () => [] },
    activationScore: { type: Number, default: 0 },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const page = usePage();
    const entity = computed(() => {
      var _a;
      return ((_a = page.props.auth) == null ? void 0 : _a.entity) ?? {};
    });
    const rule = computed(() => entity.value.rule ?? "");
    const isDoctor = computed(() => rule.value === "doctor");
    const { isRefreshing, lastUpdated, refresh } = useDashboardPolling(
      ["stats", "scheduleToday", "recentPatients"],
      3e4
    );
    const breadcrumbs = [];
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Dashboard",
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="page-dashboard"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              "is-refreshing": unref(isRefreshing),
              "last-updated": unref(lastUpdated),
              t: __props.t,
              onRefresh: unref(refresh)
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$2, { t: __props.t }, null, _parent2, _scopeId));
            if (__props.activationScore < 100) {
              _push2(ssrRenderComponent(_sfc_main$3, {
                activation: __props.activation,
                "activation-score": __props.activationScore,
                t: __props.t
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
            _push2(ssrRenderComponent(_sfc_main$4, {
              stats: __props.stats,
              "is-doctor": isDoctor.value,
              "is-refreshing": unref(isRefreshing),
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$5, {
              rule: rule.value,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`<div class="row g-3 mb-4"${_scopeId}><div class="col-lg-8"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$6, {
              items: __props.scheduleToday,
              "is-refreshing": unref(isRefreshing),
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div><div class="col-lg-4"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$7, {
              stats: __props.stats,
              "is-refreshing": unref(isRefreshing),
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div></div>`);
            _push2(ssrRenderComponent(_sfc_main$8, {
              patients: __props.recentPatients,
              t: __props.t
            }, null, _parent2, _scopeId));
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "page-dashboard" }, [
                createVNode(_sfc_main$1, {
                  "is-refreshing": unref(isRefreshing),
                  "last-updated": unref(lastUpdated),
                  t: __props.t,
                  onRefresh: unref(refresh)
                }, null, 8, ["is-refreshing", "last-updated", "t", "onRefresh"]),
                createVNode(_sfc_main$2, { t: __props.t }, null, 8, ["t"]),
                __props.activationScore < 100 ? (openBlock(), createBlock(_sfc_main$3, {
                  key: 0,
                  activation: __props.activation,
                  "activation-score": __props.activationScore,
                  t: __props.t
                }, null, 8, ["activation", "activation-score", "t"])) : createCommentVNode("", true),
                createVNode(_sfc_main$4, {
                  stats: __props.stats,
                  "is-doctor": isDoctor.value,
                  "is-refreshing": unref(isRefreshing),
                  t: __props.t
                }, null, 8, ["stats", "is-doctor", "is-refreshing", "t"]),
                createVNode(_sfc_main$5, {
                  rule: rule.value,
                  t: __props.t
                }, null, 8, ["rule", "t"]),
                createVNode("div", { class: "row g-3 mb-4" }, [
                  createVNode("div", { class: "col-lg-8" }, [
                    createVNode(_sfc_main$6, {
                      items: __props.scheduleToday,
                      "is-refreshing": unref(isRefreshing),
                      t: __props.t
                    }, null, 8, ["items", "is-refreshing", "t"])
                  ]),
                  createVNode("div", { class: "col-lg-4" }, [
                    createVNode(_sfc_main$7, {
                      stats: __props.stats,
                      "is-refreshing": unref(isRefreshing),
                      t: __props.t
                    }, null, 8, ["stats", "is-refreshing", "t"])
                  ])
                ]),
                createVNode(_sfc_main$8, {
                  patients: __props.recentPatients,
                  t: __props.t
                }, null, 8, ["patients", "t"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Dashboard.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
