import { ref, mergeProps, withCtx, createVNode, createTextVNode, withModifiers, withDirectives, vModelText, toDisplayString, openBlock, createBlock, Fragment, renderList, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderAttr, ssrInterpolate, ssrRenderClass, ssrRenderList } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "CashFlow",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    summary: { type: Object, default: () => ({}) },
    byCategory: { type: Array, default: () => [] },
    byDay: { type: Array, default: () => [] },
    entries: { type: Array, default: () => [] },
    export_url: { type: String, default: "" },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const from = ref(props.filters.from);
    const to = ref(props.filters.to);
    function brl(v) {
      return "R$ " + Number(v ?? 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 });
    }
    function applyFilter() {
      router.get(
        route("panel.financial.reports.cash-flow"),
        { from: from.value, to: to.value },
        { preserveState: true, preserveScroll: true }
      );
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Relatório Fluxo de Caixa",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, { title: "Relatório de Fluxo de Caixa" }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<a${ssrRenderAttr("href", `${__props.export_url}?from=${__props.filters.from}&to=${__props.filters.to}`)} class="btn btn-outline-secondary btn-sm"${_scopeId2}><i class="ti ti-download me-1"${_scopeId2}></i>Exportar CSV </a>`);
                } else {
                  return [
                    createVNode("a", {
                      href: `${__props.export_url}?from=${__props.filters.from}&to=${__props.filters.to}`,
                      class: "btn btn-outline-secondary btn-sm"
                    }, [
                      createVNode("i", { class: "ti ti-download me-1" }),
                      createTextVNode("Exportar CSV ")
                    ], 8, ["href"])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<div class="card border-0 shadow-sm mb-3"${_scopeId}><div class="card-body py-3"${_scopeId}><form class="row g-2 align-items-end"${_scopeId}><div class="col-md-3"${_scopeId}><label class="form-label small mb-1"${_scopeId}>De</label><input${ssrRenderAttr("value", from.value)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Até</label><input${ssrRenderAttr("value", to.value)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3"${_scopeId}><button type="submit" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-filter me-1"${_scopeId}></i>Filtrar </button></div></form></div></div><div class="row g-3 mb-3"${_scopeId}><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Receitas</small><div class="fw-bold fs-5 text-success"${_scopeId}>${ssrInterpolate(brl(__props.summary.revenue))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Despesas</small><div class="fw-bold fs-5 text-danger"${_scopeId}>${ssrInterpolate(brl(__props.summary.expenses))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Saldo</small><div class="${ssrRenderClass([(__props.summary.balance ?? 0) >= 0 ? "text-success" : "text-danger", "fw-bold fs-5"])}"${_scopeId}>${ssrInterpolate(brl(__props.summary.balance))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>A receber</small><div class="fw-bold fs-5 text-warning"${_scopeId}>${ssrInterpolate(brl(__props.summary.pending))}</div></div></div></div></div><div class="row g-3 mb-3"${_scopeId}><div class="col-md-6"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-tag me-1 text-primary"${_scopeId}></i>Por categoria</h6></div><div class="table-responsive"${_scopeId}><table class="table table-sm table-hover mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Categoria</th><th${_scopeId}>Tipo</th><th class="text-end"${_scopeId}>Total</th></tr></thead><tbody${_scopeId}><!--[-->`);
            ssrRenderList(__props.byCategory, (row, i) => {
              _push2(`<tr${_scopeId}><td class="fw-medium"${_scopeId}>${ssrInterpolate(row.category)}</td><td${_scopeId}><span class="${ssrRenderClass(row.type === "income" ? "text-success" : "text-danger")}"${_scopeId}>${ssrInterpolate(row.type === "income" ? "Receita" : "Despesa")}</span></td><td class="text-end"${_scopeId}>${ssrInterpolate(brl(row.total))}</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div></div><div class="col-md-6"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-calendar me-1 text-primary"${_scopeId}></i>Por dia</h6></div><div class="table-responsive"${_scopeId}><table class="table table-sm table-hover mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Dia</th><th class="text-end"${_scopeId}>Receita</th><th class="text-end"${_scopeId}>Despesa</th></tr></thead><tbody${_scopeId}><!--[-->`);
            ssrRenderList(__props.byDay, (row, i) => {
              _push2(`<tr${_scopeId}><td class="fw-medium"${_scopeId}>${ssrInterpolate(row.day)}</td><td class="text-end text-success"${_scopeId}>${ssrInterpolate(brl(row.income))}</td><td class="text-end text-danger"${_scopeId}>${ssrInterpolate(brl(row.expense))}</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div></div></div><div class="card"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-list me-1 text-primary"${_scopeId}></i>Lançamentos</h6></div><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Data</th><th${_scopeId}>Descrição</th><th${_scopeId}>Categoria</th><th${_scopeId}>Convênio</th><th class="text-center"${_scopeId}>Tipo</th><th class="text-end"${_scopeId}>Valor</th></tr></thead><tbody${_scopeId}>`);
            if (__props.entries.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="6" class="text-center text-muted py-5"${_scopeId}>Nenhum lançamento.</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.entries, (e, i) => {
              _push2(`<tr${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(e.entry_date)}</td><td${_scopeId}>${ssrInterpolate(e.description)}</td><td class="text-muted small"${_scopeId}>${ssrInterpolate(e.category_name || "—")}</td><td class="text-muted small"${_scopeId}>${ssrInterpolate(e.covenant_name || "—")}</td><td class="text-center"${_scopeId}><span class="${ssrRenderClass(`badge ${e.type === "income" ? "badge-soft-success text-success" : "badge-soft-danger text-danger"} fs-11`)}"${_scopeId}>${ssrInterpolate(e.type === "income" ? "R" : "D")}</span></td><td class="${ssrRenderClass([e.type === "income" ? "text-success" : "text-danger", "text-end fw-bold"])}"${_scopeId}>${ssrInterpolate(brl(e.amount))}</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, { title: "Relatório de Fluxo de Caixa" }, {
                  actions: withCtx(() => [
                    createVNode("a", {
                      href: `${__props.export_url}?from=${__props.filters.from}&to=${__props.filters.to}`,
                      class: "btn btn-outline-secondary btn-sm"
                    }, [
                      createVNode("i", { class: "ti ti-download me-1" }),
                      createTextVNode("Exportar CSV ")
                    ], 8, ["href"])
                  ]),
                  _: 1
                }),
                createVNode("div", { class: "card border-0 shadow-sm mb-3" }, [
                  createVNode("div", { class: "card-body py-3" }, [
                    createVNode("form", {
                      onSubmit: withModifiers(applyFilter, ["prevent"]),
                      class: "row g-2 align-items-end"
                    }, [
                      createVNode("div", { class: "col-md-3" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "De"),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => from.value = $event,
                          type: "date",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, from.value]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-3" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "Até"),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => to.value = $event,
                          type: "date",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, to.value]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-3" }, [
                        createVNode("button", {
                          type: "submit",
                          class: "btn btn-primary btn-sm"
                        }, [
                          createVNode("i", { class: "ti ti-filter me-1" }),
                          createTextVNode("Filtrar ")
                        ])
                      ])
                    ], 32)
                  ])
                ]),
                createVNode("div", { class: "row g-3 mb-3" }, [
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Receitas"),
                        createVNode("div", { class: "fw-bold fs-5 text-success" }, toDisplayString(brl(__props.summary.revenue)), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Despesas"),
                        createVNode("div", { class: "fw-bold fs-5 text-danger" }, toDisplayString(brl(__props.summary.expenses)), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Saldo"),
                        createVNode("div", {
                          class: ["fw-bold fs-5", (__props.summary.balance ?? 0) >= 0 ? "text-success" : "text-danger"]
                        }, toDisplayString(brl(__props.summary.balance)), 3)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "A receber"),
                        createVNode("div", { class: "fw-bold fs-5 text-warning" }, toDisplayString(brl(__props.summary.pending)), 1)
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "row g-3 mb-3" }, [
                  createVNode("div", { class: "col-md-6" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-header bg-transparent" }, [
                        createVNode("h6", { class: "mb-0 fw-semibold" }, [
                          createVNode("i", { class: "ti ti-tag me-1 text-primary" }),
                          createTextVNode("Por categoria")
                        ])
                      ]),
                      createVNode("div", { class: "table-responsive" }, [
                        createVNode("table", { class: "table table-sm table-hover mb-0" }, [
                          createVNode("thead", { class: "table-light" }, [
                            createVNode("tr", null, [
                              createVNode("th", null, "Categoria"),
                              createVNode("th", null, "Tipo"),
                              createVNode("th", { class: "text-end" }, "Total")
                            ])
                          ]),
                          createVNode("tbody", null, [
                            (openBlock(true), createBlock(Fragment, null, renderList(__props.byCategory, (row, i) => {
                              return openBlock(), createBlock("tr", { key: i }, [
                                createVNode("td", { class: "fw-medium" }, toDisplayString(row.category), 1),
                                createVNode("td", null, [
                                  createVNode("span", {
                                    class: row.type === "income" ? "text-success" : "text-danger"
                                  }, toDisplayString(row.type === "income" ? "Receita" : "Despesa"), 3)
                                ]),
                                createVNode("td", { class: "text-end" }, toDisplayString(brl(row.total)), 1)
                              ]);
                            }), 128))
                          ])
                        ])
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-md-6" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-header bg-transparent" }, [
                        createVNode("h6", { class: "mb-0 fw-semibold" }, [
                          createVNode("i", { class: "ti ti-calendar me-1 text-primary" }),
                          createTextVNode("Por dia")
                        ])
                      ]),
                      createVNode("div", { class: "table-responsive" }, [
                        createVNode("table", { class: "table table-sm table-hover mb-0" }, [
                          createVNode("thead", { class: "table-light" }, [
                            createVNode("tr", null, [
                              createVNode("th", null, "Dia"),
                              createVNode("th", { class: "text-end" }, "Receita"),
                              createVNode("th", { class: "text-end" }, "Despesa")
                            ])
                          ]),
                          createVNode("tbody", null, [
                            (openBlock(true), createBlock(Fragment, null, renderList(__props.byDay, (row, i) => {
                              return openBlock(), createBlock("tr", { key: i }, [
                                createVNode("td", { class: "fw-medium" }, toDisplayString(row.day), 1),
                                createVNode("td", { class: "text-end text-success" }, toDisplayString(brl(row.income)), 1),
                                createVNode("td", { class: "text-end text-danger" }, toDisplayString(brl(row.expense)), 1)
                              ]);
                            }), 128))
                          ])
                        ])
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "card-header bg-transparent" }, [
                    createVNode("h6", { class: "mb-0 fw-semibold" }, [
                      createVNode("i", { class: "ti ti-list me-1 text-primary" }),
                      createTextVNode("Lançamentos")
                    ])
                  ]),
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Data"),
                          createVNode("th", null, "Descrição"),
                          createVNode("th", null, "Categoria"),
                          createVNode("th", null, "Convênio"),
                          createVNode("th", { class: "text-center" }, "Tipo"),
                          createVNode("th", { class: "text-end" }, "Valor")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.entries.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "6",
                            class: "text-center text-muted py-5"
                          }, "Nenhum lançamento.")
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.entries, (e, i) => {
                          return openBlock(), createBlock("tr", { key: i }, [
                            createVNode("td", { class: "text-muted small" }, toDisplayString(e.entry_date), 1),
                            createVNode("td", null, toDisplayString(e.description), 1),
                            createVNode("td", { class: "text-muted small" }, toDisplayString(e.category_name || "—"), 1),
                            createVNode("td", { class: "text-muted small" }, toDisplayString(e.covenant_name || "—"), 1),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("span", {
                                class: `badge ${e.type === "income" ? "badge-soft-success text-success" : "badge-soft-danger text-danger"} fs-11`
                              }, toDisplayString(e.type === "income" ? "R" : "D"), 3)
                            ]),
                            createVNode("td", {
                              class: ["text-end fw-bold", e.type === "income" ? "text-success" : "text-danger"]
                            }, toDisplayString(brl(e.amount)), 3)
                          ]);
                        }), 128))
                      ])
                    ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Financial/Reports/CashFlow.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
