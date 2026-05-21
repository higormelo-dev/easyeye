import { ref, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, withModifiers, withDirectives, vModelText, vModelSelect, openBlock, createBlock, Fragment, renderList, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderClass, ssrRenderAttr, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import { _ as _sfc_main$3 } from "./TablePagination-Dj1_H7YG.js";
import "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$2 } from "./ActionIconGroup-Dj2wQrik.js";
import _sfc_main$4 from "./CashEntryFormModal-3jnpI666.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    entries: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const form = ref({ ...props.filters });
    function applyFilter() {
      router.get(route("panel.financial.cash-flow.index"), form.value, {
        preserveState: true,
        preserveScroll: true
      });
    }
    function resetFilter() {
      router.get(route("panel.financial.cash-flow.index"));
    }
    function brl(v) {
      return "R$ " + Number(v ?? 0).toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function csrf() {
      var _a;
      return ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "";
    }
    const formOpen = ref(false);
    const editingId = ref(null);
    function openCreate() {
      editingId.value = null;
      formOpen.value = true;
    }
    function openEdit(e) {
      editingId.value = e.id;
      formOpen.value = true;
    }
    function onSaved() {
      formOpen.value = false;
      router.reload({ only: ["entries", "summary"] });
    }
    async function onDelete(entry) {
      if (!confirm("Excluir este lançamento?")) return;
      const res = await fetch(route("panel.financial.cash-flow.destroy", entry.id), {
        method: "DELETE",
        headers: { "Accept": "application/json", "X-CSRF-TOKEN": csrf() }
      });
      if (res.ok) {
        if (window.showSuccessToast) window.showSuccessToast("Lançamento removido.");
        router.reload({ only: ["entries", "summary"] });
      } else if (window.showErrorToast) {
        window.showErrorToast("Erro ao remover.");
      }
    }
    const typeBadge = (t) => t === "revenue" ? "badge badge-soft-success rounded text-success border border-success" : "badge badge-soft-danger rounded text-danger border border-danger";
    const statusBadge = (s) => {
      if (s === "paid") return "badge bg-success";
      if (s === "pending") return "badge bg-warning text-dark";
      if (s === "overdue") return "badge bg-danger";
      return "badge bg-secondary";
    };
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Fluxo de Caixa",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, { title: "Fluxo de Caixa" }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<button type="button" class="btn btn-primary btn-sm"${_scopeId2}><i class="ti ti-plus me-1"${_scopeId2}></i>Novo lançamento </button>`);
                } else {
                  return [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary btn-sm",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode("Novo lançamento ")
                    ])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<div class="row g-3 mb-3"${_scopeId}><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm border-start border-success border-3 h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Receitas (período)</small><div class="fw-bold fs-5 text-success"${_scopeId}>${ssrInterpolate(brl(__props.summary.revenue))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm border-start border-danger border-3 h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Despesas (período)</small><div class="fw-bold fs-5 text-danger"${_scopeId}>${ssrInterpolate(brl(__props.summary.expenses))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm border-start border-info border-3 h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Saldo</small><div class="${ssrRenderClass([(__props.summary.balance ?? 0) >= 0 ? "text-success" : "text-danger", "fw-bold fs-5"])}"${_scopeId}>${ssrInterpolate(brl(__props.summary.balance))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card border-0 shadow-sm border-start border-warning border-3 h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>A receber</small><div class="fw-bold fs-5 text-warning"${_scopeId}>${ssrInterpolate(brl(__props.summary.pending))}</div></div></div></div></div><div class="card border-0 shadow-sm mb-3"${_scopeId}><div class="card-body py-3"${_scopeId}><form class="row g-2 align-items-end"${_scopeId}><div class="col-md-2"${_scopeId}><label class="form-label small mb-1"${_scopeId}>De</label><input${ssrRenderAttr("value", form.value.from)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-2"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Até</label><input${ssrRenderAttr("value", form.value.to)} type="date" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-2"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Tipo</label><select class="form-select form-select-sm"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.type) ? ssrLooseContain(form.value.type, "") : ssrLooseEqual(form.value.type, "")) ? " selected" : ""}${_scopeId}>Todos</option><option value="revenue"${ssrIncludeBooleanAttr(Array.isArray(form.value.type) ? ssrLooseContain(form.value.type, "revenue") : ssrLooseEqual(form.value.type, "revenue")) ? " selected" : ""}${_scopeId}>Receita</option><option value="expense"${ssrIncludeBooleanAttr(Array.isArray(form.value.type) ? ssrLooseContain(form.value.type, "expense") : ssrLooseEqual(form.value.type, "expense")) ? " selected" : ""}${_scopeId}>Despesa</option></select></div><div class="col-md-2"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Status</label><select class="form-select form-select-sm"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, "") : ssrLooseEqual(form.value.status, "")) ? " selected" : ""}${_scopeId}>Todos</option><option value="pending"${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, "pending") : ssrLooseEqual(form.value.status, "pending")) ? " selected" : ""}${_scopeId}>Pendente</option><option value="paid"${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, "paid") : ssrLooseEqual(form.value.status, "paid")) ? " selected" : ""}${_scopeId}>Pago</option><option value="overdue"${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, "overdue") : ssrLooseEqual(form.value.status, "overdue")) ? " selected" : ""}${_scopeId}>Atrasado</option></select></div><div class="col-md-3"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Categoria</label><select class="form-select form-select-sm"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.category_id) ? ssrLooseContain(form.value.category_id, "") : ssrLooseEqual(form.value.category_id, "")) ? " selected" : ""}${_scopeId}>Todas</option><!--[-->`);
            ssrRenderList(__props.categories, (cat) => {
              _push2(`<option${ssrRenderAttr("value", cat.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.category_id) ? ssrLooseContain(form.value.category_id, cat.id) : ssrLooseEqual(form.value.category_id, cat.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(cat.name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-md-1 d-flex gap-1"${_scopeId}><button type="submit" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-filter"${_scopeId}></i></button><button type="button" class="btn btn-outline-secondary btn-sm"${_scopeId}><i class="ti ti-refresh"${_scopeId}></i></button></div></form></div></div><div class="card"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Data</th><th${_scopeId}>Descrição</th><th${_scopeId}>Categoria</th><th${_scopeId}>Convênio</th><th class="text-center"${_scopeId}>Tipo</th><th class="text-center"${_scopeId}>Status</th><th class="text-end"${_scopeId}>Valor</th><th class="text-end"${_scopeId}>Ações</th></tr></thead><tbody${_scopeId}>`);
            if (__props.entries.data.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="8" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-cash-register fs-1 d-block mb-2"${_scopeId}></i> Nenhum lançamento no período. </td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.entries.data, (entry) => {
              _push2(`<tr${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(entry.entry_date)}</td><td class="fw-medium"${_scopeId}>${ssrInterpolate(entry.description)}</td><td class="text-muted"${_scopeId}>${ssrInterpolate(entry.category_name || "—")}</td><td class="text-muted"${_scopeId}>${ssrInterpolate(entry.covenant_name || "—")}</td><td class="text-center"${_scopeId}><span class="${ssrRenderClass(typeBadge(entry.type))}"${_scopeId}>${ssrInterpolate(entry.type === "revenue" ? "Receita" : "Despesa")}</span></td><td class="text-center"${_scopeId}><span class="${ssrRenderClass([statusBadge(entry.status), "rounded fs-11 fw-medium"])}"${_scopeId}>${ssrInterpolate(entry.status === "paid" ? "Pago" : entry.status === "pending" ? "Pendente" : entry.status === "overdue" ? "Atrasado" : entry.status)}</span></td><td class="${ssrRenderClass([entry.type === "revenue" ? "text-success" : "text-danger", "text-end fw-bold"])}"${_scopeId}>${ssrInterpolate(brl(entry.amount))}</td><td class="text-end"${_scopeId}>`);
              _push2(ssrRenderComponent(ActionIconGroup, {
                align: "end",
                gap: "tight"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    _push3(ssrRenderComponent(_sfc_main$2, {
                      icon: "ti ti-edit",
                      title: "Editar",
                      disabled: entry.has_claim,
                      onClick: ($event) => openEdit(entry)
                    }, null, _parent3, _scopeId2));
                    _push3(ssrRenderComponent(_sfc_main$2, {
                      icon: "ti ti-trash",
                      title: "Excluir",
                      variant: "danger",
                      disabled: entry.has_claim,
                      onClick: ($event) => onDelete(entry)
                    }, null, _parent3, _scopeId2));
                  } else {
                    return [
                      createVNode(_sfc_main$2, {
                        icon: "ti ti-edit",
                        title: "Editar",
                        disabled: entry.has_claim,
                        onClick: ($event) => openEdit(entry)
                      }, null, 8, ["disabled", "onClick"]),
                      createVNode(_sfc_main$2, {
                        icon: "ti ti-trash",
                        title: "Excluir",
                        variant: "danger",
                        disabled: entry.has_claim,
                        onClick: ($event) => onDelete(entry)
                      }, null, 8, ["disabled", "onClick"])
                    ];
                  }
                }),
                _: 2
              }, _parent2, _scopeId));
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div>`);
            _push2(ssrRenderComponent(_sfc_main$3, {
              data: __props.entries,
              class: "mt-3"
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$4, {
              open: formOpen.value,
              "entry-id": editingId.value,
              categories: __props.categories,
              onClose: ($event) => formOpen.value = false,
              onSaved
            }, null, _parent2, _scopeId));
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, { title: "Fluxo de Caixa" }, {
                  actions: withCtx(() => [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary btn-sm",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode("Novo lançamento ")
                    ])
                  ]),
                  _: 1
                }),
                createVNode("div", { class: "row g-3 mb-3" }, [
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm border-start border-success border-3 h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Receitas (período)"),
                        createVNode("div", { class: "fw-bold fs-5 text-success" }, toDisplayString(brl(__props.summary.revenue)), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm border-start border-danger border-3 h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Despesas (período)"),
                        createVNode("div", { class: "fw-bold fs-5 text-danger" }, toDisplayString(brl(__props.summary.expenses)), 1)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm border-start border-info border-3 h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "Saldo"),
                        createVNode("div", {
                          class: ["fw-bold fs-5", (__props.summary.balance ?? 0) >= 0 ? "text-success" : "text-danger"]
                        }, toDisplayString(brl(__props.summary.balance)), 3)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card border-0 shadow-sm border-start border-warning border-3 h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("small", { class: "text-muted d-block" }, "A receber"),
                        createVNode("div", { class: "fw-bold fs-5 text-warning" }, toDisplayString(brl(__props.summary.pending)), 1)
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "card border-0 shadow-sm mb-3" }, [
                  createVNode("div", { class: "card-body py-3" }, [
                    createVNode("form", {
                      onSubmit: withModifiers(applyFilter, ["prevent"]),
                      class: "row g-2 align-items-end"
                    }, [
                      createVNode("div", { class: "col-md-2" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "De"),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => form.value.from = $event,
                          type: "date",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, form.value.from]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-2" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "Até"),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => form.value.to = $event,
                          type: "date",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, form.value.to]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-2" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "Tipo"),
                        withDirectives(createVNode("select", {
                          "onUpdate:modelValue": ($event) => form.value.type = $event,
                          class: "form-select form-select-sm"
                        }, [
                          createVNode("option", { value: "" }, "Todos"),
                          createVNode("option", { value: "revenue" }, "Receita"),
                          createVNode("option", { value: "expense" }, "Despesa")
                        ], 8, ["onUpdate:modelValue"]), [
                          [vModelSelect, form.value.type]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-2" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "Status"),
                        withDirectives(createVNode("select", {
                          "onUpdate:modelValue": ($event) => form.value.status = $event,
                          class: "form-select form-select-sm"
                        }, [
                          createVNode("option", { value: "" }, "Todos"),
                          createVNode("option", { value: "pending" }, "Pendente"),
                          createVNode("option", { value: "paid" }, "Pago"),
                          createVNode("option", { value: "overdue" }, "Atrasado")
                        ], 8, ["onUpdate:modelValue"]), [
                          [vModelSelect, form.value.status]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-3" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "Categoria"),
                        withDirectives(createVNode("select", {
                          "onUpdate:modelValue": ($event) => form.value.category_id = $event,
                          class: "form-select form-select-sm"
                        }, [
                          createVNode("option", { value: "" }, "Todas"),
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.categories, (cat) => {
                            return openBlock(), createBlock("option", {
                              key: cat.id,
                              value: cat.id
                            }, toDisplayString(cat.name), 9, ["value"]);
                          }), 128))
                        ], 8, ["onUpdate:modelValue"]), [
                          [vModelSelect, form.value.category_id]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-1 d-flex gap-1" }, [
                        createVNode("button", {
                          type: "submit",
                          class: "btn btn-primary btn-sm"
                        }, [
                          createVNode("i", { class: "ti ti-filter" })
                        ]),
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-outline-secondary btn-sm",
                          onClick: resetFilter
                        }, [
                          createVNode("i", { class: "ti ti-refresh" })
                        ])
                      ])
                    ], 32)
                  ])
                ]),
                createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Data"),
                          createVNode("th", null, "Descrição"),
                          createVNode("th", null, "Categoria"),
                          createVNode("th", null, "Convênio"),
                          createVNode("th", { class: "text-center" }, "Tipo"),
                          createVNode("th", { class: "text-center" }, "Status"),
                          createVNode("th", { class: "text-end" }, "Valor"),
                          createVNode("th", { class: "text-end" }, "Ações")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.entries.data.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "8",
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-cash-register fs-1 d-block mb-2" }),
                            createTextVNode(" Nenhum lançamento no período. ")
                          ])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.entries.data, (entry) => {
                          return openBlock(), createBlock("tr", {
                            key: entry.id
                          }, [
                            createVNode("td", { class: "text-muted small" }, toDisplayString(entry.entry_date), 1),
                            createVNode("td", { class: "fw-medium" }, toDisplayString(entry.description), 1),
                            createVNode("td", { class: "text-muted" }, toDisplayString(entry.category_name || "—"), 1),
                            createVNode("td", { class: "text-muted" }, toDisplayString(entry.covenant_name || "—"), 1),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("span", {
                                class: typeBadge(entry.type)
                              }, toDisplayString(entry.type === "revenue" ? "Receita" : "Despesa"), 3)
                            ]),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("span", {
                                class: [statusBadge(entry.status), "rounded fs-11 fw-medium"]
                              }, toDisplayString(entry.status === "paid" ? "Pago" : entry.status === "pending" ? "Pendente" : entry.status === "overdue" ? "Atrasado" : entry.status), 3)
                            ]),
                            createVNode("td", {
                              class: ["text-end fw-bold", entry.type === "revenue" ? "text-success" : "text-danger"]
                            }, toDisplayString(brl(entry.amount)), 3),
                            createVNode("td", { class: "text-end" }, [
                              createVNode(ActionIconGroup, {
                                align: "end",
                                gap: "tight"
                              }, {
                                default: withCtx(() => [
                                  createVNode(_sfc_main$2, {
                                    icon: "ti ti-edit",
                                    title: "Editar",
                                    disabled: entry.has_claim,
                                    onClick: ($event) => openEdit(entry)
                                  }, null, 8, ["disabled", "onClick"]),
                                  createVNode(_sfc_main$2, {
                                    icon: "ti ti-trash",
                                    title: "Excluir",
                                    variant: "danger",
                                    disabled: entry.has_claim,
                                    onClick: ($event) => onDelete(entry)
                                  }, null, 8, ["disabled", "onClick"])
                                ]),
                                _: 2
                              }, 1024)
                            ])
                          ]);
                        }), 128))
                      ])
                    ])
                  ])
                ]),
                createVNode(_sfc_main$3, {
                  data: __props.entries,
                  class: "mt-3"
                }, null, 8, ["data"]),
                createVNode(_sfc_main$4, {
                  open: formOpen.value,
                  "entry-id": editingId.value,
                  categories: __props.categories,
                  onClose: ($event) => formOpen.value = false,
                  onSaved
                }, null, 8, ["open", "entry-id", "categories", "onClose"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Financial/CashFlow/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
