import { computed, mergeProps, withCtx, unref, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderStyle, ssrRenderClass, ssrRenderList } from "vue/server-renderer";
import { Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Dashboard",
  __ssrInlineRender: true,
  props: {
    balance: { type: Object, required: true },
    period: { type: Object, required: true },
    plan_quota: { type: Number, default: 0 },
    consumed: { type: Object, required: true },
    by_workflow: { type: Array, default: () => [] },
    by_provider: { type: Array, default: () => [] },
    approval: { type: Object, required: true },
    top_runs: { type: Array, default: () => [] },
    labels: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const label = (key, fallback = "") => {
      var _a;
      return ((_a = props.labels) == null ? void 0 : _a[key]) ?? fallback;
    };
    const breadcrumbs = [
      { label: label("dashboard", "Dashboard"), url: route("panel.dashboard"), active: false },
      { label: label("panel", "Painel de IA"), url: route("panel.ai-runs.index"), active: false },
      { label: label("title", "Consumo"), url: "#", active: true }
    ];
    const usagePercent = computed(() => {
      var _a;
      return (_a = props.consumed) == null ? void 0 : _a.usage_percent;
    });
    const usageBarClass = computed(() => {
      const p = usagePercent.value ?? 0;
      if (p >= 100) return "bg-danger";
      if (p >= 80) return "bg-warning";
      return "bg-success";
    });
    const workflowLabel = (workflow) => label(`workflow_${workflow}`, workflow);
    const modeLabel = (mode) => label(`mode_${mode}`, mode);
    const statusLabel = (status) => label(`status_${status}`, status);
    const statusClass = (status) => {
      return {
        approved: "badge bg-success-subtle text-success",
        rejected: "badge bg-danger-subtle text-danger",
        waiting_approval: "badge bg-warning-subtle text-warning",
        failed: "badge bg-danger-subtle text-danger",
        running: "badge bg-primary-subtle text-primary",
        reserved: "badge bg-info-subtle text-info"
      }[status] ?? "badge bg-light text-dark";
    };
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: label("title", "Consumo de IA"),
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: label("title", "Consumo de IA"),
              view: "cards"
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(ssrRenderComponent(unref(Link), {
                    href: _ctx.route("panel.ai-runs.index"),
                    class: "btn btn-outline-secondary btn-sm"
                  }, {
                    default: withCtx((_3, _push4, _parent4, _scopeId3) => {
                      if (_push4) {
                        _push4(`<i class="ti ti-arrow-left me-1"${_scopeId3}></i>${ssrInterpolate(label("back_to_runs", "Voltar"))}`);
                      } else {
                        return [
                          createVNode("i", { class: "ti ti-arrow-left me-1" }),
                          createTextVNode(toDisplayString(label("back_to_runs", "Voltar")), 1)
                        ];
                      }
                    }),
                    _: 1
                  }, _parent3, _scopeId2));
                } else {
                  return [
                    createVNode(unref(Link), {
                      href: _ctx.route("panel.ai-runs.index"),
                      class: "btn btn-outline-secondary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-arrow-left me-1" }),
                        createTextVNode(toDisplayString(label("back_to_runs", "Voltar")), 1)
                      ]),
                      _: 1
                    }, 8, ["href"])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<div class="text-muted mb-3"${_scopeId}>${ssrInterpolate(label("period", "Período"))}: <strong${_scopeId}>${ssrInterpolate(__props.period.label)}</strong></div><div class="row g-3 mb-3"${_scopeId}><div class="col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body"${_scopeId}><div class="text-muted small"${_scopeId}>${ssrInterpolate(label("plan_quota", "Franquia"))}</div><div class="fs-3 fw-semibold"${_scopeId}>${ssrInterpolate(__props.plan_quota)}</div><div class="text-muted small"${_scopeId}>${ssrInterpolate(label("credits", "créditos"))}</div></div></div></div><div class="col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body"${_scopeId}><div class="text-muted small"${_scopeId}>${ssrInterpolate(label("consumed_month", "Consumido"))}</div><div class="fs-3 fw-semibold"${_scopeId}>${ssrInterpolate(__props.consumed.this_month)}</div>`);
            if (usagePercent.value !== null) {
              _push2(`<div class="progress mt-2" style="${ssrRenderStyle({ "height": "6px" })}"${_scopeId}><div class="${ssrRenderClass([usageBarClass.value, "progress-bar"])}" style="${ssrRenderStyle({ width: usagePercent.value + "%" })}"${_scopeId}></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (usagePercent.value !== null) {
              _push2(`<div class="text-muted small mt-1"${_scopeId}>${ssrInterpolate(usagePercent.value)}% ${ssrInterpolate(label("percent", "%"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></div><div class="col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body"${_scopeId}><div class="text-muted small"${_scopeId}>${ssrInterpolate(label("over_quota", "Excedente"))}</div><div class="${ssrRenderClass([__props.consumed.over_quota > 0 ? "text-danger" : "", "fs-3 fw-semibold"])}"${_scopeId}>${ssrInterpolate(__props.consumed.over_quota)}</div></div></div></div><div class="col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body"${_scopeId}><div class="text-muted small"${_scopeId}>${ssrInterpolate(label("approval_rate", "Aprovação"))}</div><div class="fs-3 fw-semibold"${_scopeId}>${ssrInterpolate(__props.approval.approval_rate)}%</div><div class="text-muted small"${_scopeId}>${ssrInterpolate(__props.approval.approved)} ${ssrInterpolate(label("approved", "aprovadas"))} / ${ssrInterpolate(__props.approval.rejected)} ${ssrInterpolate(label("rejected", "rejeitadas"))}</div></div></div></div></div><div class="card mb-3"${_scopeId}><div class="card-header bg-white"${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("by_workflow", "Consumo por workflow"))}</strong></div><div class="card-body"${_scopeId}>`);
            if (__props.by_workflow.length === 0) {
              _push2(`<div class="text-muted small text-center py-3"${_scopeId}>${ssrInterpolate(label("empty", "Sem dados."))}</div>`);
            } else {
              _push2(`<table class="table table-sm mb-0"${_scopeId}><thead class="text-muted"${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(label("workflow", "Workflow"))}</th><th class="text-end"${_scopeId}>${ssrInterpolate(label("runs", "Execuções"))}</th><th class="text-end"${_scopeId}>${ssrInterpolate(label("credits", "Créditos"))}</th><th style="${ssrRenderStyle({ "width": "30%" })}"${_scopeId}>${ssrInterpolate(label("percent", "%"))}</th></tr></thead><tbody${_scopeId}><!--[-->`);
              ssrRenderList(__props.by_workflow, (row) => {
                _push2(`<tr${_scopeId}><td${_scopeId}>${ssrInterpolate(workflowLabel(row.workflow))}</td><td class="text-end"${_scopeId}>${ssrInterpolate(row.runs)}</td><td class="text-end"${_scopeId}>${ssrInterpolate(row.credits)}</td><td${_scopeId}><div class="progress" style="${ssrRenderStyle({ "height": "6px" })}"${_scopeId}><div class="progress-bar bg-primary" style="${ssrRenderStyle({ width: row.percent + "%" })}"${_scopeId}></div></div><small class="text-muted"${_scopeId}>${ssrInterpolate(row.percent)}%</small></td></tr>`);
              });
              _push2(`<!--]--></tbody></table>`);
            }
            _push2(`</div></div><div class="card mb-3"${_scopeId}><div class="card-header bg-white"${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("by_provider", "Chamadas por provedor"))}</strong></div><div class="card-body"${_scopeId}>`);
            if (__props.by_provider.length === 0) {
              _push2(`<div class="text-muted small text-center py-3"${_scopeId}>${ssrInterpolate(label("empty", "Sem dados."))}</div>`);
            } else {
              _push2(`<table class="table table-sm mb-0"${_scopeId}><thead class="text-muted"${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(label("provider", "Provedor"))}</th><th class="text-end"${_scopeId}>Total</th><th class="text-end text-success"${_scopeId}>${ssrInterpolate(label("success", "Sucesso"))}</th><th class="text-end text-danger"${_scopeId}>${ssrInterpolate(label("failed", "Falhas"))}</th><th class="text-end text-muted"${_scopeId}>${ssrInterpolate(label("skipped", "Puladas"))}</th><th style="${ssrRenderStyle({ "width": "25%" })}"${_scopeId}>${ssrInterpolate(label("percent", "%"))}</th></tr></thead><tbody${_scopeId}><!--[-->`);
              ssrRenderList(__props.by_provider, (row) => {
                _push2(`<tr${_scopeId}><td class="text-uppercase"${_scopeId}>${ssrInterpolate(row.provider)}</td><td class="text-end"${_scopeId}>${ssrInterpolate(row.calls)}</td><td class="text-end text-success"${_scopeId}>${ssrInterpolate(row.success)}</td><td class="text-end text-danger"${_scopeId}>${ssrInterpolate(row.failed)}</td><td class="text-end text-muted"${_scopeId}>${ssrInterpolate(row.skipped)}</td><td${_scopeId}><div class="progress" style="${ssrRenderStyle({ "height": "6px" })}"${_scopeId}><div class="progress-bar bg-info" style="${ssrRenderStyle({ width: row.percent_calls + "%" })}"${_scopeId}></div></div><small class="text-muted"${_scopeId}>${ssrInterpolate(row.percent_calls)}%</small></td></tr>`);
              });
              _push2(`<!--]--></tbody></table>`);
            }
            _push2(`</div></div><div class="card mb-3"${_scopeId}><div class="card-header bg-white"${_scopeId}><strong${_scopeId}>${ssrInterpolate(label("top_runs", "Top execuções por créditos"))}</strong></div><div class="card-body p-0"${_scopeId}><table class="table table-sm mb-0"${_scopeId}><thead class="text-muted"${_scopeId}><tr${_scopeId}><th${_scopeId}>Data</th><th${_scopeId}>${ssrInterpolate(label("workflow", "Workflow"))}</th><th${_scopeId}>${ssrInterpolate(label("mode", "Modo"))}</th><th${_scopeId}>${ssrInterpolate(label("status", "Status"))}</th><th class="text-end"${_scopeId}>${ssrInterpolate(label("credits", "Créditos"))}</th></tr></thead><tbody${_scopeId}><!--[-->`);
            ssrRenderList(__props.top_runs, (run) => {
              _push2(`<tr${_scopeId}><td${_scopeId}>${ssrInterpolate(run.created_at)}</td><td${_scopeId}>${ssrInterpolate(workflowLabel(run.workflow))}</td><td${_scopeId}>${ssrInterpolate(modeLabel(run.mode))}</td><td${_scopeId}><span class="${ssrRenderClass(statusClass(run.status))}"${_scopeId}>${ssrInterpolate(statusLabel(run.status))}</span></td><td class="text-end fw-semibold"${_scopeId}>${ssrInterpolate(run.credits)}</td></tr>`);
            });
            _push2(`<!--]-->`);
            if (__props.top_runs.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="5" class="text-center text-muted py-3"${_scopeId}>${ssrInterpolate(label("empty", "Sem dados."))}</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</tbody></table></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: label("title", "Consumo de IA"),
                  view: "cards"
                }, {
                  actions: withCtx(() => [
                    createVNode(unref(Link), {
                      href: _ctx.route("panel.ai-runs.index"),
                      class: "btn btn-outline-secondary btn-sm"
                    }, {
                      default: withCtx(() => [
                        createVNode("i", { class: "ti ti-arrow-left me-1" }),
                        createTextVNode(toDisplayString(label("back_to_runs", "Voltar")), 1)
                      ]),
                      _: 1
                    }, 8, ["href"])
                  ]),
                  _: 1
                }, 8, ["title"]),
                createVNode("div", { class: "text-muted mb-3" }, [
                  createTextVNode(toDisplayString(label("period", "Período")) + ": ", 1),
                  createVNode("strong", null, toDisplayString(__props.period.label), 1)
                ]),
                createVNode("div", { class: "row g-3 mb-3" }, [
                  createVNode("div", { class: "col-md-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body" }, [
                        createVNode("div", { class: "text-muted small" }, toDisplayString(label("plan_quota", "Franquia")), 1),
                        createVNode("div", { class: "fs-3 fw-semibold" }, toDisplayString(__props.plan_quota), 1),
                        createVNode("div", { class: "text-muted small" }, toDisplayString(label("credits", "créditos")), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-md-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body" }, [
                        createVNode("div", { class: "text-muted small" }, toDisplayString(label("consumed_month", "Consumido")), 1),
                        createVNode("div", { class: "fs-3 fw-semibold" }, toDisplayString(__props.consumed.this_month), 1),
                        usagePercent.value !== null ? (openBlock(), createBlock("div", {
                          key: 0,
                          class: "progress mt-2",
                          style: { "height": "6px" }
                        }, [
                          createVNode("div", {
                            class: ["progress-bar", usageBarClass.value],
                            style: { width: usagePercent.value + "%" }
                          }, null, 6)
                        ])) : createCommentVNode("", true),
                        usagePercent.value !== null ? (openBlock(), createBlock("div", {
                          key: 1,
                          class: "text-muted small mt-1"
                        }, toDisplayString(usagePercent.value) + "% " + toDisplayString(label("percent", "%")), 1)) : createCommentVNode("", true)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-md-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body" }, [
                        createVNode("div", { class: "text-muted small" }, toDisplayString(label("over_quota", "Excedente")), 1),
                        createVNode("div", {
                          class: ["fs-3 fw-semibold", __props.consumed.over_quota > 0 ? "text-danger" : ""]
                        }, toDisplayString(__props.consumed.over_quota), 3)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-md-3" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-body" }, [
                        createVNode("div", { class: "text-muted small" }, toDisplayString(label("approval_rate", "Aprovação")), 1),
                        createVNode("div", { class: "fs-3 fw-semibold" }, toDisplayString(__props.approval.approval_rate) + "%", 1),
                        createVNode("div", { class: "text-muted small" }, toDisplayString(__props.approval.approved) + " " + toDisplayString(label("approved", "aprovadas")) + " / " + toDisplayString(__props.approval.rejected) + " " + toDisplayString(label("rejected", "rejeitadas")), 1)
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "card mb-3" }, [
                  createVNode("div", { class: "card-header bg-white" }, [
                    createVNode("strong", null, toDisplayString(label("by_workflow", "Consumo por workflow")), 1)
                  ]),
                  createVNode("div", { class: "card-body" }, [
                    __props.by_workflow.length === 0 ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "text-muted small text-center py-3"
                    }, toDisplayString(label("empty", "Sem dados.")), 1)) : (openBlock(), createBlock("table", {
                      key: 1,
                      class: "table table-sm mb-0"
                    }, [
                      createVNode("thead", { class: "text-muted" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, toDisplayString(label("workflow", "Workflow")), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(label("runs", "Execuções")), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(label("credits", "Créditos")), 1),
                          createVNode("th", { style: { "width": "30%" } }, toDisplayString(label("percent", "%")), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.by_workflow, (row) => {
                          return openBlock(), createBlock("tr", {
                            key: row.workflow
                          }, [
                            createVNode("td", null, toDisplayString(workflowLabel(row.workflow)), 1),
                            createVNode("td", { class: "text-end" }, toDisplayString(row.runs), 1),
                            createVNode("td", { class: "text-end" }, toDisplayString(row.credits), 1),
                            createVNode("td", null, [
                              createVNode("div", {
                                class: "progress",
                                style: { "height": "6px" }
                              }, [
                                createVNode("div", {
                                  class: "progress-bar bg-primary",
                                  style: { width: row.percent + "%" }
                                }, null, 4)
                              ]),
                              createVNode("small", { class: "text-muted" }, toDisplayString(row.percent) + "%", 1)
                            ])
                          ]);
                        }), 128))
                      ])
                    ]))
                  ])
                ]),
                createVNode("div", { class: "card mb-3" }, [
                  createVNode("div", { class: "card-header bg-white" }, [
                    createVNode("strong", null, toDisplayString(label("by_provider", "Chamadas por provedor")), 1)
                  ]),
                  createVNode("div", { class: "card-body" }, [
                    __props.by_provider.length === 0 ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "text-muted small text-center py-3"
                    }, toDisplayString(label("empty", "Sem dados.")), 1)) : (openBlock(), createBlock("table", {
                      key: 1,
                      class: "table table-sm mb-0"
                    }, [
                      createVNode("thead", { class: "text-muted" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, toDisplayString(label("provider", "Provedor")), 1),
                          createVNode("th", { class: "text-end" }, "Total"),
                          createVNode("th", { class: "text-end text-success" }, toDisplayString(label("success", "Sucesso")), 1),
                          createVNode("th", { class: "text-end text-danger" }, toDisplayString(label("failed", "Falhas")), 1),
                          createVNode("th", { class: "text-end text-muted" }, toDisplayString(label("skipped", "Puladas")), 1),
                          createVNode("th", { style: { "width": "25%" } }, toDisplayString(label("percent", "%")), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.by_provider, (row) => {
                          return openBlock(), createBlock("tr", {
                            key: row.provider
                          }, [
                            createVNode("td", { class: "text-uppercase" }, toDisplayString(row.provider), 1),
                            createVNode("td", { class: "text-end" }, toDisplayString(row.calls), 1),
                            createVNode("td", { class: "text-end text-success" }, toDisplayString(row.success), 1),
                            createVNode("td", { class: "text-end text-danger" }, toDisplayString(row.failed), 1),
                            createVNode("td", { class: "text-end text-muted" }, toDisplayString(row.skipped), 1),
                            createVNode("td", null, [
                              createVNode("div", {
                                class: "progress",
                                style: { "height": "6px" }
                              }, [
                                createVNode("div", {
                                  class: "progress-bar bg-info",
                                  style: { width: row.percent_calls + "%" }
                                }, null, 4)
                              ]),
                              createVNode("small", { class: "text-muted" }, toDisplayString(row.percent_calls) + "%", 1)
                            ])
                          ]);
                        }), 128))
                      ])
                    ]))
                  ])
                ]),
                createVNode("div", { class: "card mb-3" }, [
                  createVNode("div", { class: "card-header bg-white" }, [
                    createVNode("strong", null, toDisplayString(label("top_runs", "Top execuções por créditos")), 1)
                  ]),
                  createVNode("div", { class: "card-body p-0" }, [
                    createVNode("table", { class: "table table-sm mb-0" }, [
                      createVNode("thead", { class: "text-muted" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Data"),
                          createVNode("th", null, toDisplayString(label("workflow", "Workflow")), 1),
                          createVNode("th", null, toDisplayString(label("mode", "Modo")), 1),
                          createVNode("th", null, toDisplayString(label("status", "Status")), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(label("credits", "Créditos")), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.top_runs, (run) => {
                          return openBlock(), createBlock("tr", {
                            key: run.id
                          }, [
                            createVNode("td", null, toDisplayString(run.created_at), 1),
                            createVNode("td", null, toDisplayString(workflowLabel(run.workflow)), 1),
                            createVNode("td", null, toDisplayString(modeLabel(run.mode)), 1),
                            createVNode("td", null, [
                              createVNode("span", {
                                class: statusClass(run.status)
                              }, toDisplayString(statusLabel(run.status)), 3)
                            ]),
                            createVNode("td", { class: "text-end fw-semibold" }, toDisplayString(run.credits), 1)
                          ]);
                        }), 128)),
                        __props.top_runs.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "5",
                            class: "text-center text-muted py-3"
                          }, toDisplayString(label("empty", "Sem dados.")), 1)
                        ])) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/AI/Dashboard.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
