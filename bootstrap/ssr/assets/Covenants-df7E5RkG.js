import { ref, mergeProps, withCtx, createVNode, createTextVNode, withModifiers, withDirectives, vModelText, toDisplayString, openBlock, createBlock, createCommentVNode, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderAttr, ssrInterpolate, ssrRenderList } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Covenants",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    summary: { type: Object, default: () => ({}) },
    byCovenant: { type: Array, default: () => [] },
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
        route("panel.financial.reports.covenants"),
        { from: from.value, to: to.value },
        { preserveState: true, preserveScroll: true }
      );
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Relatório por Convênio",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, { title: "Relatório de Faturamento por Convênio" }, {
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
            _push2(`<div class="card border-0 shadow-sm mb-3"${_scopeId}><div class="card-body py-3"${_scopeId}><form class="row g-2 align-items-end"${_scopeId}><div class="col-md-3"${_scopeId}><label class="form-label small mb-1"${_scopeId}>De</label><input${ssrRenderAttr("value", from.value)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Até</label><input${ssrRenderAttr("value", to.value)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3"${_scopeId}><button type="submit" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-filter me-1"${_scopeId}></i>Filtrar </button></div></form></div></div><div class="row g-3 mb-3"${_scopeId}><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Guias</small><div class="fw-bold fs-5"${_scopeId}>${ssrInterpolate(__props.summary.total_claims ?? 0)}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Total faturado</small><div class="fw-bold fs-5 text-primary"${_scopeId}>${ssrInterpolate(brl(__props.summary.total_amount))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Total pago</small><div class="fw-bold fs-5 text-success"${_scopeId}>${ssrInterpolate(brl(__props.summary.total_paid))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Glosado</small><div class="fw-bold fs-5 text-danger"${_scopeId}>${ssrInterpolate(brl(__props.summary.total_denied))}</div></div></div></div></div><div class="card"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-medical-cross me-1 text-primary"${_scopeId}></i>Por convênio</h6></div><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Convênio</th><th class="text-center"${_scopeId}>Guias</th><th class="text-end"${_scopeId}>Faturado</th><th class="text-end"${_scopeId}>Pago</th><th class="text-end"${_scopeId}>Glosado</th></tr></thead><tbody${_scopeId}>`);
            if (__props.byCovenant.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="5" class="text-center text-muted py-5"${_scopeId}>Sem dados no período.</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.byCovenant, (row, i) => {
              _push2(`<tr${_scopeId}><td class="fw-medium"${_scopeId}>${ssrInterpolate(row.covenant)}</td><td class="text-center"${_scopeId}>${ssrInterpolate(row.claims)}</td><td class="text-end"${_scopeId}>${ssrInterpolate(brl(row.amount))}</td><td class="text-end text-success"${_scopeId}>${ssrInterpolate(brl(row.paid))}</td><td class="text-end text-danger"${_scopeId}>${ssrInterpolate(brl(row.denied))}</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, { title: "Relatório de Faturamento por Convênio" }, {
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
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Guias"),
                        createVNode("div", { class: "fw-bold fs-5" }, toDisplayString(__props.summary.total_claims ?? 0), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Total faturado"),
                        createVNode("div", { class: "fw-bold fs-5 text-primary" }, toDisplayString(brl(__props.summary.total_amount)), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Total pago"),
                        createVNode("div", { class: "fw-bold fs-5 text-success" }, toDisplayString(brl(__props.summary.total_paid)), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Glosado"),
                        createVNode("div", { class: "fw-bold fs-5 text-danger" }, toDisplayString(brl(__props.summary.total_denied)), 1)
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "card-header bg-transparent" }, [
                    createVNode("h6", { class: "mb-0 fw-semibold" }, [
                      createVNode("i", { class: "ti ti-medical-cross me-1 text-primary" }),
                      createTextVNode("Por convênio")
                    ])
                  ]),
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Convênio"),
                          createVNode("th", { class: "text-center" }, "Guias"),
                          createVNode("th", { class: "text-end" }, "Faturado"),
                          createVNode("th", { class: "text-end" }, "Pago"),
                          createVNode("th", { class: "text-end" }, "Glosado")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.byCovenant.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "5",
                            class: "text-center text-muted py-5"
                          }, "Sem dados no período.")
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.byCovenant, (row, i) => {
                          return openBlock(), createBlock("tr", { key: i }, [
                            createVNode("td", { class: "fw-medium" }, toDisplayString(row.covenant), 1),
                            createVNode("td", { class: "text-center" }, toDisplayString(row.claims), 1),
                            createVNode("td", { class: "text-end" }, toDisplayString(brl(row.amount)), 1),
                            createVNode("td", { class: "text-end text-success" }, toDisplayString(brl(row.paid)), 1),
                            createVNode("td", { class: "text-end text-danger" }, toDisplayString(brl(row.denied)), 1)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Financial/Reports/Covenants.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
