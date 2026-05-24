import { ref, computed, mergeProps, withCtx, createVNode, withModifiers, toDisplayString, withDirectives, vModelText, createTextVNode, openBlock, createBlock, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    entity: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    trend: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const fromDate = ref(props.filters.from);
    const toDate = ref(props.filters.to);
    const k = computed(() => {
      var _a;
      return ((_a = props.summary) == null ? void 0 : _a.kpis) ?? {};
    });
    function applyFilter() {
      router.get(
        route("panel.financial.bi.index"),
        { from: fromDate.value, to: toDate.value },
        { preserveState: true, preserveScroll: true }
      );
    }
    function resetFilter() {
      router.get(route("panel.financial.bi.index"));
    }
    function brl(value) {
      return "R$ " + Number(value ?? 0).toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function pct(value, dec = 1) {
      return Number(value ?? 0).toLocaleString("pt-BR", { minimumFractionDigits: dec, maximumFractionDigits: dec }) + "%";
    }
    const kpis = computed(() => {
      var _a, _b, _c, _d, _e, _f;
      return [
        { title: ((_a = props.t.bi) == null ? void 0 : _a.revenue) ?? "Receita", value: brl(k.value.revenue), icon: "ti-trending-up", color: "success" },
        { title: ((_b = props.t.bi) == null ? void 0 : _b.expenses) ?? "Despesa", value: brl(k.value.expenses), icon: "ti-trending-down", color: "danger" },
        { title: ((_c = props.t.bi) == null ? void 0 : _c.balance) ?? "Saldo", value: brl(k.value.balance), icon: "ti-wallet", color: (k.value.balance ?? 0) >= 0 ? "success" : "danger" },
        { title: ((_d = props.t.bi) == null ? void 0 : _d.receipt_rate) ?? "Taxa de recebimento", value: pct(k.value.receipt_rate), icon: "ti-receipt", color: "primary" },
        { title: ((_e = props.t.bi) == null ? void 0 : _e.attendance_rate) ?? "Taxa de presença", value: pct(k.value.attendance_rate), icon: "ti-user-check", color: "primary" },
        { title: ((_f = props.t.bi) == null ? void 0 : _f.occupancy_rate) ?? "Ocupação", value: pct(k.value.occupancy_rate), icon: "ti-calendar-check", color: "warning" }
      ];
    });
    return (_ctx, _push, _parent, _attrs) => {
      var _a;
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: ((_a = __props.t.bi) == null ? void 0 : _a.title) ?? "Dashboard gerencial",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a2, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l, _m, _n;
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: ((_a2 = __props.t.bi) == null ? void 0 : _a2.title) ?? "Dashboard gerencial",
              subtitle: __props.entity.name
            }, null, _parent2, _scopeId));
            _push2(`<div class="card border-0 shadow-sm mb-4"${_scopeId}><div class="card-body py-3"${_scopeId}><form class="row g-3 align-items-end"${_scopeId}><div class="col-md-3"${_scopeId}><label class="form-label small fw-semibold mb-1"${_scopeId}>${ssrInterpolate(__props.t.period_from ?? "De")}</label><input${ssrRenderAttr("value", fromDate.value)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3"${_scopeId}><label class="form-label small fw-semibold mb-1"${_scopeId}>${ssrInterpolate(__props.t.period_to ?? "Até")}</label><input${ssrRenderAttr("value", toDate.value)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-auto ms-auto d-flex gap-2"${_scopeId}><button type="submit" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-filter me-1"${_scopeId}></i>${ssrInterpolate(__props.t.filter ?? "Filtrar")}</button><button type="button" class="btn btn-outline-secondary btn-sm"${_scopeId}><i class="ti ti-refresh me-1"${_scopeId}></i>${ssrInterpolate(__props.t.current_month ?? "Mês atual")}</button></div></form></div></div><div class="row g-3 mb-3"${_scopeId}><!--[-->`);
            ssrRenderList(kpis.value, (kpi) => {
              _push2(`<div class="col-6 col-md-4 col-xl-2"${_scopeId}><div class="${ssrRenderClass(`card border-0 shadow-sm h-100 border-start border-3 border-${kpi.color}`)}"${_scopeId}><div class="card-body px-3 py-3"${_scopeId}><div class="d-flex align-items-center gap-2 mb-2"${_scopeId}><span class="${ssrRenderClass(`avatar avatar-sm rounded-circle bg-${kpi.color}-subtle`)}"${_scopeId}><i class="${ssrRenderClass(`ti ${kpi.icon} text-${kpi.color}`)}"${_scopeId}></i></span><span class="small text-muted"${_scopeId}>${ssrInterpolate(kpi.title)}</span></div><div class="${ssrRenderClass(`fw-bold fs-5 text-${kpi.color}`)}"${_scopeId}>${ssrInterpolate(kpi.value)}</div></div></div></div>`);
            });
            _push2(`<!--]--></div><div class="card border-0 shadow-sm"${_scopeId}><div class="card-header bg-transparent border-bottom"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-chart-line me-1 text-primary"${_scopeId}></i> ${ssrInterpolate(((_b = __props.t.bi) == null ? void 0 : _b.monthly_trend) ?? "Tendência mensal")}</h6></div><div class="card-body"${_scopeId}>`);
            if (__props.trend.length === 0) {
              _push2(`<div class="text-center text-muted py-4"${_scopeId}>${ssrInterpolate(((_c = __props.t.bi) == null ? void 0 : _c.no_trend_data) ?? "Sem dados suficientes.")}</div>`);
            } else {
              _push2(`<table class="table table-sm table-hover mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(((_d = __props.t.bi) == null ? void 0 : _d.month) ?? "Mês")}</th><th class="text-end"${_scopeId}>${ssrInterpolate(((_e = __props.t.bi) == null ? void 0 : _e.revenue) ?? "Receita")}</th><th class="text-end"${_scopeId}>${ssrInterpolate(((_f = __props.t.bi) == null ? void 0 : _f.expenses) ?? "Despesa")}</th><th class="text-end"${_scopeId}>${ssrInterpolate(((_g = __props.t.bi) == null ? void 0 : _g.balance) ?? "Saldo")}</th></tr></thead><tbody${_scopeId}><!--[-->`);
              ssrRenderList(__props.trend, (row, idx) => {
                _push2(`<tr${_scopeId}><td class="fw-medium"${_scopeId}>${ssrInterpolate(row.month)}</td><td class="text-end text-success"${_scopeId}>${ssrInterpolate(brl(row.revenue))}</td><td class="text-end text-danger"${_scopeId}>${ssrInterpolate(brl(row.expenses))}</td><td class="${ssrRenderClass([(row.balance ?? 0) >= 0 ? "text-success" : "text-danger", "text-end fw-semibold"])}"${_scopeId}>${ssrInterpolate(brl(row.balance))}</td></tr>`);
              });
              _push2(`<!--]--></tbody></table>`);
            }
            _push2(`</div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: ((_h = __props.t.bi) == null ? void 0 : _h.title) ?? "Dashboard gerencial",
                  subtitle: __props.entity.name
                }, null, 8, ["title", "subtitle"]),
                createVNode("div", { class: "card border-0 shadow-sm mb-4" }, [
                  createVNode("div", { class: "card-body py-3" }, [
                    createVNode("form", {
                      onSubmit: withModifiers(applyFilter, ["prevent"]),
                      class: "row g-3 align-items-end"
                    }, [
                      createVNode("div", { class: "col-md-3" }, [
                        createVNode("label", { class: "form-label small fw-semibold mb-1" }, toDisplayString(__props.t.period_from ?? "De"), 1),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => fromDate.value = $event,
                          type: "date",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, fromDate.value]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-3" }, [
                        createVNode("label", { class: "form-label small fw-semibold mb-1" }, toDisplayString(__props.t.period_to ?? "Até"), 1),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => toDate.value = $event,
                          type: "date",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, toDate.value]
                        ])
                      ]),
                      createVNode("div", { class: "col-auto ms-auto d-flex gap-2" }, [
                        createVNode("button", {
                          type: "submit",
                          class: "btn btn-primary btn-sm"
                        }, [
                          createVNode("i", { class: "ti ti-filter me-1" }),
                          createTextVNode(toDisplayString(__props.t.filter ?? "Filtrar"), 1)
                        ]),
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-outline-secondary btn-sm",
                          onClick: resetFilter
                        }, [
                          createVNode("i", { class: "ti ti-refresh me-1" }),
                          createTextVNode(toDisplayString(__props.t.current_month ?? "Mês atual"), 1)
                        ])
                      ])
                    ], 32)
                  ])
                ]),
                createVNode("div", { class: "row g-3 mb-3" }, [
                  (openBlock(true), createBlock(Fragment, null, renderList(kpis.value, (kpi) => {
                    return openBlock(), createBlock("div", {
                      key: kpi.title,
                      class: "col-6 col-md-4 col-xl-2"
                    }, [
                      createVNode("div", {
                        class: `card border-0 shadow-sm h-100 border-start border-3 border-${kpi.color}`
                      }, [
                        createVNode("div", { class: "card-body px-3 py-3" }, [
                          createVNode("div", { class: "d-flex align-items-center gap-2 mb-2" }, [
                            createVNode("span", {
                              class: `avatar avatar-sm rounded-circle bg-${kpi.color}-subtle`
                            }, [
                              createVNode("i", {
                                class: `ti ${kpi.icon} text-${kpi.color}`
                              }, null, 2)
                            ], 2),
                            createVNode("span", { class: "small text-muted" }, toDisplayString(kpi.title), 1)
                          ]),
                          createVNode("div", {
                            class: `fw-bold fs-5 text-${kpi.color}`
                          }, toDisplayString(kpi.value), 3)
                        ])
                      ], 2)
                    ]);
                  }), 128))
                ]),
                createVNode("div", { class: "card border-0 shadow-sm" }, [
                  createVNode("div", { class: "card-header bg-transparent border-bottom" }, [
                    createVNode("h6", { class: "mb-0 fw-semibold" }, [
                      createVNode("i", { class: "ti ti-chart-line me-1 text-primary" }),
                      createTextVNode(" " + toDisplayString(((_i = __props.t.bi) == null ? void 0 : _i.monthly_trend) ?? "Tendência mensal"), 1)
                    ])
                  ]),
                  createVNode("div", { class: "card-body" }, [
                    __props.trend.length === 0 ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "text-center text-muted py-4"
                    }, toDisplayString(((_j = __props.t.bi) == null ? void 0 : _j.no_trend_data) ?? "Sem dados suficientes."), 1)) : (openBlock(), createBlock("table", {
                      key: 1,
                      class: "table table-sm table-hover mb-0"
                    }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, toDisplayString(((_k = __props.t.bi) == null ? void 0 : _k.month) ?? "Mês"), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(((_l = __props.t.bi) == null ? void 0 : _l.revenue) ?? "Receita"), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(((_m = __props.t.bi) == null ? void 0 : _m.expenses) ?? "Despesa"), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(((_n = __props.t.bi) == null ? void 0 : _n.balance) ?? "Saldo"), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.trend, (row, idx) => {
                          return openBlock(), createBlock("tr", { key: idx }, [
                            createVNode("td", { class: "fw-medium" }, toDisplayString(row.month), 1),
                            createVNode("td", { class: "text-end text-success" }, toDisplayString(brl(row.revenue)), 1),
                            createVNode("td", { class: "text-end text-danger" }, toDisplayString(brl(row.expenses)), 1),
                            createVNode("td", {
                              class: ["text-end fw-semibold", (row.balance ?? 0) >= 0 ? "text-success" : "text-danger"]
                            }, toDisplayString(brl(row.balance)), 3)
                          ]);
                        }), 128))
                      ])
                    ]))
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Financial/Bi/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
