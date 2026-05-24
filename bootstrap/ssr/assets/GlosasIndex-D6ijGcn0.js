import { ref, mergeProps, withCtx, createVNode, withModifiers, withDirectives, vModelText, createTextVNode, toDisplayString, openBlock, createBlock, Fragment, renderList, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderAttr, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderStyle, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "GlosasIndex",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    glosas: { type: Array, default: () => [] },
    byOperator: { type: Array, default: () => [] },
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
        route("panel.financial.tiss.glosas.index"),
        { from: from.value, to: to.value },
        { preserveState: true, preserveScroll: true }
      );
    }
    const statusBadge = (s) => {
      if (s === "reversed") return "bg-success";
      if (s === "partial_reversed") return "bg-info text-dark";
      if (s === "appealed") return "bg-warning text-dark";
      if (s === "rejected") return "bg-danger";
      if (s === "open") return "bg-secondary";
      return "bg-light text-dark";
    };
    const appealOpen = ref(false);
    const appealItem = ref(null);
    const appealReason = ref("");
    const appealSaving = ref(false);
    function openAppeal(g) {
      appealItem.value = g;
      appealReason.value = "";
      appealOpen.value = true;
    }
    async function submitAppeal() {
      var _a;
      if (appealReason.value.trim().length < 10) {
        if (window.showErrorToast) window.showErrorToast("Justifique o recurso (mínimo 10 caracteres).");
        return;
      }
      appealSaving.value = true;
      try {
        const res = await fetch(appealItem.value.appeal_url, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
          },
          body: JSON.stringify({ reason: appealReason.value })
        });
        if (res.ok || res.status === 302) {
          if (window.showSuccessToast) window.showSuccessToast("Recurso aberto.");
          appealOpen.value = false;
          router.reload({ only: ["glosas", "summary", "byOperator"] });
        } else if (window.showErrorToast) {
          window.showErrorToast("Erro ao abrir recurso.");
        }
      } finally {
        appealSaving.value = false;
      }
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Glosas TISS",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d;
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, { title: "Conciliação de Glosas TISS" }, null, _parent2, _scopeId));
            _push2(`<div class="card border-0 shadow-sm mb-3"${_scopeId}><div class="card-body py-3"${_scopeId}><form class="row g-2 align-items-end"${_scopeId}><div class="col-md-3"${_scopeId}><label class="form-label small mb-1"${_scopeId}>De</label><input${ssrRenderAttr("value", from.value)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Até</label><input${ssrRenderAttr("value", to.value)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3"${_scopeId}><button type="submit" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-filter me-1"${_scopeId}></i>Filtrar </button></div></form></div></div><div class="row g-3 mb-3"${_scopeId}><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm border-start border-info border-3 h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Total glosado</small><div class="fw-bold fs-5"${_scopeId}>${ssrInterpolate(brl(__props.summary.total))}</div><small class="text-muted"${_scopeId}>${ssrInterpolate(__props.summary.count)} glosas</small></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm border-start border-warning border-3 h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Em aberto</small><div class="fw-bold fs-5 text-warning"${_scopeId}>${ssrInterpolate(brl(__props.summary.open))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm border-start border-primary border-3 h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Recorrida</small><div class="fw-bold fs-5 text-primary"${_scopeId}>${ssrInterpolate(brl(__props.summary.appealed))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm border-start border-success border-3 h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Recuperada</small><div class="fw-bold fs-5 text-success"${_scopeId}>${ssrInterpolate(brl(__props.summary.recovered))}</div></div></div></div></div>`);
            if (__props.byOperator.length > 0) {
              _push2(`<div class="card mb-3"${_scopeId}><div class="card-header bg-transparent border-bottom"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-chart-pie me-1 text-primary"${_scopeId}></i>Por operadora</h6></div><div class="table-responsive"${_scopeId}><table class="table table-sm table-hover mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Operadora</th><th class="text-center"${_scopeId}>Qtd</th><th class="text-end"${_scopeId}>Total</th><th class="text-end"${_scopeId}>Em aberto</th></tr></thead><tbody${_scopeId}><!--[-->`);
              ssrRenderList(__props.byOperator, (op, i) => {
                _push2(`<tr${_scopeId}><td class="fw-medium"${_scopeId}>${ssrInterpolate(op.name)}</td><td class="text-center"${_scopeId}>${ssrInterpolate(op.count)}</td><td class="text-end"${_scopeId}>${ssrInterpolate(brl(op.total))}</td><td class="text-end text-warning fw-semibold"${_scopeId}>${ssrInterpolate(brl(op.open))}</td></tr>`);
              });
              _push2(`<!--]--></tbody></table></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="card"${_scopeId}><div class="card-header bg-transparent border-bottom"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-gavel me-1 text-primary"${_scopeId}></i>Glosas</h6></div><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Data</th><th${_scopeId}>Operadora</th><th${_scopeId}>Guia</th><th${_scopeId}>Motivo</th><th class="text-center"${_scopeId}>Status</th><th class="text-end"${_scopeId}>Valor</th><th class="text-end"${_scopeId}>Ações</th></tr></thead><tbody${_scopeId}>`);
            if (__props.glosas.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="7" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-checks fs-1 d-block mb-2"${_scopeId}></i> Nenhuma glosa no período. </td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.glosas, (g) => {
              _push2(`<tr${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(g.identified_at)}</td><td${_scopeId}>${ssrInterpolate(g.operator_name || "—")}</td><td${_scopeId}><code class="small"${_scopeId}>${ssrInterpolate(g.guide_number || "—")}</code></td><td class="small"${_scopeId}><span class="badge badge-soft-secondary me-1"${_scopeId}>${ssrInterpolate(g.reason_code)}</span> ${ssrInterpolate(g.reason_text)}</td><td class="text-center"${_scopeId}><span class="${ssrRenderClass(`badge ${statusBadge(g.status)} fs-11`)}"${_scopeId}>${ssrInterpolate(g.status_label)}</span>`);
              if (g.appeals_count > 0) {
                _push2(`<span class="badge badge-soft-info ms-1 fs-11"${_scopeId}>${ssrInterpolate(g.appeals_count)} recursos </span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</td><td class="text-end fw-bold"${_scopeId}>${ssrInterpolate(brl(g.amount))}</td><td class="text-end"${_scopeId}>`);
              if (g.is_actionable) {
                _push2(`<button class="btn btn-sm btn-outline-warning" title="Recorrer"${_scopeId}><i class="ti ti-message-circle-up me-1"${_scopeId}></i>Recorrer </button>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div>`);
            if (appealOpen.value) {
              _push2(`<div class="modal d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.45)" })}"${_scopeId}><div class="modal-dialog modal-dialog-centered"${_scopeId}><div class="modal-content"${_scopeId}><div class="modal-header"${_scopeId}><h5 class="modal-title"${_scopeId}><i class="ti ti-message-circle-up me-1 text-warning"${_scopeId}></i> Abrir recurso de glosa </h5><button type="button" class="btn-close"${_scopeId}></button></div><div class="modal-body"${_scopeId}><div class="alert alert-warning small mb-3"${_scopeId}><strong${_scopeId}>Glosa:</strong> ${ssrInterpolate((_a = appealItem.value) == null ? void 0 : _a.reason_text)} <br${_scopeId}><strong${_scopeId}>Valor:</strong> ${ssrInterpolate(brl((_b = appealItem.value) == null ? void 0 : _b.amount))}</div><label class="form-label"${_scopeId}>Justificativa do recurso <span class="text-danger"${_scopeId}>*</span></label><textarea rows="4" class="form-control" maxlength="1000" placeholder="Argumente por que a glosa deve ser revertida..."${_scopeId}>${ssrInterpolate(appealReason.value)}</textarea><small class="text-muted"${_scopeId}>Mínimo 10 caracteres. Será registrado no audit log.</small></div><div class="modal-footer"${_scopeId}><button type="button" class="btn btn-outline-secondary btn-sm"${_scopeId}> Cancelar </button><button type="button" class="btn btn-warning btn-sm"${ssrIncludeBooleanAttr(appealSaving.value) ? " disabled" : ""}${_scopeId}>`);
              if (appealSaving.value) {
                _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
              } else {
                _push2(`<i class="ti ti-send me-1"${_scopeId}></i>`);
              }
              _push2(` Enviar recurso </button></div></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, { title: "Conciliação de Glosas TISS" }),
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
                    createVNode("div", { class: "card border-0 shadow-sm border-start border-info border-3 h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Total glosado"),
                        createVNode("div", { class: "fw-bold fs-5" }, toDisplayString(brl(__props.summary.total)), 1),
                        createVNode("small", { class: "text-muted" }, toDisplayString(__props.summary.count) + " glosas", 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm border-start border-warning border-3 h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Em aberto"),
                        createVNode("div", { class: "fw-bold fs-5 text-warning" }, toDisplayString(brl(__props.summary.open)), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm border-start border-primary border-3 h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Recorrida"),
                        createVNode("div", { class: "fw-bold fs-5 text-primary" }, toDisplayString(brl(__props.summary.appealed)), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm border-start border-success border-3 h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Recuperada"),
                        createVNode("div", { class: "fw-bold fs-5 text-success" }, toDisplayString(brl(__props.summary.recovered)), 1)
                      ])
                    ])
                  ])
                ]),
                __props.byOperator.length > 0 ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "card mb-3"
                }, [
                  createVNode("div", { class: "card-header bg-transparent border-bottom" }, [
                    createVNode("h6", { class: "mb-0 fw-semibold" }, [
                      createVNode("i", { class: "ti ti-chart-pie me-1 text-primary" }),
                      createTextVNode("Por operadora")
                    ])
                  ]),
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-sm table-hover mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Operadora"),
                          createVNode("th", { class: "text-center" }, "Qtd"),
                          createVNode("th", { class: "text-end" }, "Total"),
                          createVNode("th", { class: "text-end" }, "Em aberto")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.byOperator, (op, i) => {
                          return openBlock(), createBlock("tr", { key: i }, [
                            createVNode("td", { class: "fw-medium" }, toDisplayString(op.name), 1),
                            createVNode("td", { class: "text-center" }, toDisplayString(op.count), 1),
                            createVNode("td", { class: "text-end" }, toDisplayString(brl(op.total)), 1),
                            createVNode("td", { class: "text-end text-warning fw-semibold" }, toDisplayString(brl(op.open)), 1)
                          ]);
                        }), 128))
                      ])
                    ])
                  ])
                ])) : createCommentVNode("", true),
                createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "card-header bg-transparent border-bottom" }, [
                    createVNode("h6", { class: "mb-0 fw-semibold" }, [
                      createVNode("i", { class: "ti ti-gavel me-1 text-primary" }),
                      createTextVNode("Glosas")
                    ])
                  ]),
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Data"),
                          createVNode("th", null, "Operadora"),
                          createVNode("th", null, "Guia"),
                          createVNode("th", null, "Motivo"),
                          createVNode("th", { class: "text-center" }, "Status"),
                          createVNode("th", { class: "text-end" }, "Valor"),
                          createVNode("th", { class: "text-end" }, "Ações")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.glosas.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "7",
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-checks fs-1 d-block mb-2" }),
                            createTextVNode(" Nenhuma glosa no período. ")
                          ])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.glosas, (g) => {
                          return openBlock(), createBlock("tr", {
                            key: g.id
                          }, [
                            createVNode("td", { class: "text-muted small" }, toDisplayString(g.identified_at), 1),
                            createVNode("td", null, toDisplayString(g.operator_name || "—"), 1),
                            createVNode("td", null, [
                              createVNode("code", { class: "small" }, toDisplayString(g.guide_number || "—"), 1)
                            ]),
                            createVNode("td", { class: "small" }, [
                              createVNode("span", { class: "badge badge-soft-secondary me-1" }, toDisplayString(g.reason_code), 1),
                              createTextVNode(" " + toDisplayString(g.reason_text), 1)
                            ]),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("span", {
                                class: `badge ${statusBadge(g.status)} fs-11`
                              }, toDisplayString(g.status_label), 3),
                              g.appeals_count > 0 ? (openBlock(), createBlock("span", {
                                key: 0,
                                class: "badge badge-soft-info ms-1 fs-11"
                              }, toDisplayString(g.appeals_count) + " recursos ", 1)) : createCommentVNode("", true)
                            ]),
                            createVNode("td", { class: "text-end fw-bold" }, toDisplayString(brl(g.amount)), 1),
                            createVNode("td", { class: "text-end" }, [
                              g.is_actionable ? (openBlock(), createBlock("button", {
                                key: 0,
                                class: "btn btn-sm btn-outline-warning",
                                title: "Recorrer",
                                onClick: ($event) => openAppeal(g)
                              }, [
                                createVNode("i", { class: "ti ti-message-circle-up me-1" }),
                                createTextVNode("Recorrer ")
                              ], 8, ["onClick"])) : createCommentVNode("", true)
                            ])
                          ]);
                        }), 128))
                      ])
                    ])
                  ])
                ]),
                appealOpen.value ? (openBlock(), createBlock("div", {
                  key: 1,
                  class: "modal d-block",
                  tabindex: "-1",
                  style: { "background": "rgba(0,0,0,.45)" },
                  onClick: withModifiers(($event) => appealOpen.value = false, ["self"])
                }, [
                  createVNode("div", { class: "modal-dialog modal-dialog-centered" }, [
                    createVNode("div", { class: "modal-content" }, [
                      createVNode("div", { class: "modal-header" }, [
                        createVNode("h5", { class: "modal-title" }, [
                          createVNode("i", { class: "ti ti-message-circle-up me-1 text-warning" }),
                          createTextVNode(" Abrir recurso de glosa ")
                        ]),
                        createVNode("button", {
                          type: "button",
                          class: "btn-close",
                          onClick: ($event) => appealOpen.value = false
                        }, null, 8, ["onClick"])
                      ]),
                      createVNode("div", { class: "modal-body" }, [
                        createVNode("div", { class: "alert alert-warning small mb-3" }, [
                          createVNode("strong", null, "Glosa:"),
                          createTextVNode(" " + toDisplayString((_c = appealItem.value) == null ? void 0 : _c.reason_text) + " ", 1),
                          createVNode("br"),
                          createVNode("strong", null, "Valor:"),
                          createTextVNode(" " + toDisplayString(brl((_d = appealItem.value) == null ? void 0 : _d.amount)), 1)
                        ]),
                        createVNode("label", { class: "form-label" }, [
                          createTextVNode("Justificativa do recurso "),
                          createVNode("span", { class: "text-danger" }, "*")
                        ]),
                        withDirectives(createVNode("textarea", {
                          "onUpdate:modelValue": ($event) => appealReason.value = $event,
                          rows: "4",
                          class: "form-control",
                          maxlength: "1000",
                          placeholder: "Argumente por que a glosa deve ser revertida..."
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, appealReason.value]
                        ]),
                        createVNode("small", { class: "text-muted" }, "Mínimo 10 caracteres. Será registrado no audit log.")
                      ]),
                      createVNode("div", { class: "modal-footer" }, [
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-outline-secondary btn-sm",
                          onClick: ($event) => appealOpen.value = false
                        }, " Cancelar ", 8, ["onClick"]),
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-warning btn-sm",
                          disabled: appealSaving.value,
                          onClick: submitAppeal
                        }, [
                          appealSaving.value ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "spinner-border spinner-border-sm me-1"
                          })) : (openBlock(), createBlock("i", {
                            key: 1,
                            class: "ti ti-send me-1"
                          })),
                          createTextVNode(" Enviar recurso ")
                        ], 8, ["disabled"])
                      ])
                    ])
                  ])
                ], 8, ["onClick"])) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Financial/Tiss/GlosasIndex.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
