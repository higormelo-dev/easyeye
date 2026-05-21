import { ref, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, withDirectives, openBlock, createBlock, createCommentVNode, Fragment, renderList, vShow, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderClass, ssrInterpolate, ssrRenderStyle, ssrRenderList, ssrRenderAttr } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    eligibleSchedules: { type: Array, default: () => [] },
    claims: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
    covenants: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    tissVersionOptions: { type: Array, default: () => [] },
    tissLayoutOptions: { type: Array, default: () => [] },
    selectedTissVersion: { type: String, default: "202603" },
    selectedTissLayout: { type: String, default: "04.03.00" },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const activeTab = ref("eligible");
    function brl(v) {
      return "R$ " + Number(v ?? 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 });
    }
    const statusBadge = (s) => {
      if (s === "paid") return "bg-success";
      if (s === "denied") return "bg-danger";
      if (s === "submitted") return "bg-info";
      if (s === "pending") return "bg-warning text-dark";
      if (s === "cancelled") return "bg-secondary";
      return "bg-secondary";
    };
    function csrf() {
      var _a;
      return ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "";
    }
    async function markPaid(claim) {
      if (!confirm("Marcar esta guia como PAGA?")) return;
      const res = await fetch(claim.mark_paid_url, {
        method: "POST",
        headers: { "Accept": "application/json", "X-CSRF-TOKEN": csrf() }
      });
      if (res.ok) router.reload({ only: ["claims"] });
    }
    async function markDenied(claim) {
      const reason = window.prompt("Motivo da negativa:");
      if (!reason) return;
      const res = await fetch(claim.mark_denied_url, {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-TOKEN": csrf() },
        body: JSON.stringify({ reason })
      });
      if (res.ok) router.reload({ only: ["claims"] });
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Faturamento TISS",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, { title: "Faturamento TISS" }, null, _parent2, _scopeId));
            _push2(`<ul class="nav nav-tabs mb-3"${_scopeId}><li class="nav-item"${_scopeId}><button class="${ssrRenderClass(["nav-link", { active: activeTab.value === "eligible" }])}"${_scopeId}><i class="ti ti-list-check me-1"${_scopeId}></i> Elegíveis (${ssrInterpolate(__props.eligibleSchedules.length)}) </button></li><li class="nav-item"${_scopeId}><button class="${ssrRenderClass(["nav-link", { active: activeTab.value === "claims" }])}"${_scopeId}><i class="ti ti-file-invoice me-1"${_scopeId}></i> Guias (${ssrInterpolate(__props.claims.length)}) </button></li><li class="nav-item"${_scopeId}><button class="${ssrRenderClass(["nav-link", { active: activeTab.value === "batches" }])}"${_scopeId}><i class="ti ti-package me-1"${_scopeId}></i> Lotes (${ssrInterpolate(__props.batches.length)}) </button></li></ul><div class="card" style="${ssrRenderStyle(activeTab.value === "eligible" ? null : { display: "none" })}"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Data/hora</th><th${_scopeId}>Paciente</th><th${_scopeId}>Médico</th><th${_scopeId}>Convênio</th><th${_scopeId}>Tipo consulta</th></tr></thead><tbody${_scopeId}>`);
            if (__props.eligibleSchedules.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="5" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-clipboard-off fs-1 d-block mb-2"${_scopeId}></i> Nenhum atendimento elegível para faturar no período. </td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.eligibleSchedules, (s) => {
              _push2(`<tr${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(s.date_time)}</td><td class="fw-medium"${_scopeId}>${ssrInterpolate(s.patient_name || "—")}</td><td class="text-muted"${_scopeId}>${ssrInterpolate(s.doctor_name || "—")}</td><td${_scopeId}>${ssrInterpolate(s.covenant_name || "—")}</td><td class="text-muted"${_scopeId}>${ssrInterpolate(s.visit_type || "—")}</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div><div class="card" style="${ssrRenderStyle(activeTab.value === "claims" ? null : { display: "none" })}"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Criada em</th><th${_scopeId}>Paciente</th><th${_scopeId}>Médico</th><th${_scopeId}>Convênio</th><th class="text-center"${_scopeId}>Lote</th><th class="text-center"${_scopeId}>Status</th><th class="text-end"${_scopeId}>Valor</th><th class="text-end"${_scopeId}>Ações</th></tr></thead><tbody${_scopeId}>`);
            if (__props.claims.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="8" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-file-off fs-1 d-block mb-2"${_scopeId}></i> Nenhuma guia aberta. </td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.claims, (c) => {
              _push2(`<tr${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(c.created_at)}</td><td class="fw-medium"${_scopeId}>${ssrInterpolate(c.patient_name || "—")}</td><td class="text-muted"${_scopeId}>${ssrInterpolate(c.doctor_name || "—")}</td><td${_scopeId}>${ssrInterpolate(c.covenant_name || "—")}</td><td class="text-center"${_scopeId}>`);
              if (c.batch_id) {
                _push2(`<code class="small"${_scopeId}>${ssrInterpolate(String(c.batch_id).substring(0, 8))}…</code>`);
              } else {
                _push2(`<span class="text-muted small"${_scopeId}>—</span>`);
              }
              _push2(`</td><td class="text-center"${_scopeId}><span class="${ssrRenderClass(`badge ${statusBadge(c.status)} fs-11`)}"${_scopeId}>${ssrInterpolate(c.status)}</span></td><td class="text-end fw-bold"${_scopeId}>${ssrInterpolate(brl(c.amount))}</td><td class="text-end"${_scopeId}>`);
              if (c.status !== "paid" && c.status !== "cancelled") {
                _push2(`<button class="btn btn-sm btn-outline-success me-1"${ssrRenderAttr("title", "Marcar como paga")}${_scopeId}><i class="ti ti-check"${_scopeId}></i></button>`);
              } else {
                _push2(`<!---->`);
              }
              if (c.status !== "denied" && c.status !== "cancelled") {
                _push2(`<button class="btn btn-sm btn-outline-danger"${ssrRenderAttr("title", "Marcar como negada")}${_scopeId}><i class="ti ti-x"${_scopeId}></i></button>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div><div class="card" style="${ssrRenderStyle(activeTab.value === "batches" ? null : { display: "none" })}"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Criado em</th><th${_scopeId}>Convênio</th><th class="text-center"${_scopeId}>Guias</th><th class="text-center"${_scopeId}>Status</th><th class="text-end"${_scopeId}>Ações</th></tr></thead><tbody${_scopeId}>`);
            if (__props.batches.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="5" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-package-off fs-1 d-block mb-2"${_scopeId}></i> Nenhum lote gerado. </td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.batches, (b) => {
              _push2(`<tr${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(b.created_at)}</td><td${_scopeId}>${ssrInterpolate(b.covenant_name || "—")}</td><td class="text-center"${_scopeId}><span class="badge badge-soft-info rounded fs-12"${_scopeId}>${ssrInterpolate(b.claims_count)}</span></td><td class="text-center"${_scopeId}><span class="${ssrRenderClass(`badge ${statusBadge(b.status)} fs-11`)}"${_scopeId}>${ssrInterpolate(b.status)}</span></td><td class="text-end"${_scopeId}><a${ssrRenderAttr("href", b.xml_url)} class="btn btn-sm btn-outline-secondary me-1" title="Baixar XML"${_scopeId}><i class="ti ti-download"${_scopeId}></i></a></td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, { title: "Faturamento TISS" }),
                createVNode("ul", { class: "nav nav-tabs mb-3" }, [
                  createVNode("li", { class: "nav-item" }, [
                    createVNode("button", {
                      class: ["nav-link", { active: activeTab.value === "eligible" }],
                      onClick: ($event) => activeTab.value = "eligible"
                    }, [
                      createVNode("i", { class: "ti ti-list-check me-1" }),
                      createTextVNode(" Elegíveis (" + toDisplayString(__props.eligibleSchedules.length) + ") ", 1)
                    ], 10, ["onClick"])
                  ]),
                  createVNode("li", { class: "nav-item" }, [
                    createVNode("button", {
                      class: ["nav-link", { active: activeTab.value === "claims" }],
                      onClick: ($event) => activeTab.value = "claims"
                    }, [
                      createVNode("i", { class: "ti ti-file-invoice me-1" }),
                      createTextVNode(" Guias (" + toDisplayString(__props.claims.length) + ") ", 1)
                    ], 10, ["onClick"])
                  ]),
                  createVNode("li", { class: "nav-item" }, [
                    createVNode("button", {
                      class: ["nav-link", { active: activeTab.value === "batches" }],
                      onClick: ($event) => activeTab.value = "batches"
                    }, [
                      createVNode("i", { class: "ti ti-package me-1" }),
                      createTextVNode(" Lotes (" + toDisplayString(__props.batches.length) + ") ", 1)
                    ], 10, ["onClick"])
                  ])
                ]),
                withDirectives(createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Data/hora"),
                          createVNode("th", null, "Paciente"),
                          createVNode("th", null, "Médico"),
                          createVNode("th", null, "Convênio"),
                          createVNode("th", null, "Tipo consulta")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.eligibleSchedules.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "5",
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-clipboard-off fs-1 d-block mb-2" }),
                            createTextVNode(" Nenhum atendimento elegível para faturar no período. ")
                          ])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.eligibleSchedules, (s) => {
                          return openBlock(), createBlock("tr", {
                            key: s.id
                          }, [
                            createVNode("td", { class: "text-muted small" }, toDisplayString(s.date_time), 1),
                            createVNode("td", { class: "fw-medium" }, toDisplayString(s.patient_name || "—"), 1),
                            createVNode("td", { class: "text-muted" }, toDisplayString(s.doctor_name || "—"), 1),
                            createVNode("td", null, toDisplayString(s.covenant_name || "—"), 1),
                            createVNode("td", { class: "text-muted" }, toDisplayString(s.visit_type || "—"), 1)
                          ]);
                        }), 128))
                      ])
                    ])
                  ])
                ], 512), [
                  [vShow, activeTab.value === "eligible"]
                ]),
                withDirectives(createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Criada em"),
                          createVNode("th", null, "Paciente"),
                          createVNode("th", null, "Médico"),
                          createVNode("th", null, "Convênio"),
                          createVNode("th", { class: "text-center" }, "Lote"),
                          createVNode("th", { class: "text-center" }, "Status"),
                          createVNode("th", { class: "text-end" }, "Valor"),
                          createVNode("th", { class: "text-end" }, "Ações")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.claims.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "8",
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-file-off fs-1 d-block mb-2" }),
                            createTextVNode(" Nenhuma guia aberta. ")
                          ])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.claims, (c) => {
                          return openBlock(), createBlock("tr", {
                            key: c.id
                          }, [
                            createVNode("td", { class: "text-muted small" }, toDisplayString(c.created_at), 1),
                            createVNode("td", { class: "fw-medium" }, toDisplayString(c.patient_name || "—"), 1),
                            createVNode("td", { class: "text-muted" }, toDisplayString(c.doctor_name || "—"), 1),
                            createVNode("td", null, toDisplayString(c.covenant_name || "—"), 1),
                            createVNode("td", { class: "text-center" }, [
                              c.batch_id ? (openBlock(), createBlock("code", {
                                key: 0,
                                class: "small"
                              }, toDisplayString(String(c.batch_id).substring(0, 8)) + "…", 1)) : (openBlock(), createBlock("span", {
                                key: 1,
                                class: "text-muted small"
                              }, "—"))
                            ]),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("span", {
                                class: `badge ${statusBadge(c.status)} fs-11`
                              }, toDisplayString(c.status), 3)
                            ]),
                            createVNode("td", { class: "text-end fw-bold" }, toDisplayString(brl(c.amount)), 1),
                            createVNode("td", { class: "text-end" }, [
                              c.status !== "paid" && c.status !== "cancelled" ? (openBlock(), createBlock("button", {
                                key: 0,
                                class: "btn btn-sm btn-outline-success me-1",
                                title: "Marcar como paga",
                                onClick: ($event) => markPaid(c)
                              }, [
                                createVNode("i", { class: "ti ti-check" })
                              ], 8, ["onClick"])) : createCommentVNode("", true),
                              c.status !== "denied" && c.status !== "cancelled" ? (openBlock(), createBlock("button", {
                                key: 1,
                                class: "btn btn-sm btn-outline-danger",
                                title: "Marcar como negada",
                                onClick: ($event) => markDenied(c)
                              }, [
                                createVNode("i", { class: "ti ti-x" })
                              ], 8, ["onClick"])) : createCommentVNode("", true)
                            ])
                          ]);
                        }), 128))
                      ])
                    ])
                  ])
                ], 512), [
                  [vShow, activeTab.value === "claims"]
                ]),
                withDirectives(createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, "Criado em"),
                          createVNode("th", null, "Convênio"),
                          createVNode("th", { class: "text-center" }, "Guias"),
                          createVNode("th", { class: "text-center" }, "Status"),
                          createVNode("th", { class: "text-end" }, "Ações")
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.batches.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "5",
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-package-off fs-1 d-block mb-2" }),
                            createTextVNode(" Nenhum lote gerado. ")
                          ])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.batches, (b) => {
                          return openBlock(), createBlock("tr", {
                            key: b.id
                          }, [
                            createVNode("td", { class: "text-muted small" }, toDisplayString(b.created_at), 1),
                            createVNode("td", null, toDisplayString(b.covenant_name || "—"), 1),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("span", { class: "badge badge-soft-info rounded fs-12" }, toDisplayString(b.claims_count), 1)
                            ]),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("span", {
                                class: `badge ${statusBadge(b.status)} fs-11`
                              }, toDisplayString(b.status), 3)
                            ]),
                            createVNode("td", { class: "text-end" }, [
                              createVNode("a", {
                                href: b.xml_url,
                                class: "btn btn-sm btn-outline-secondary me-1",
                                title: "Baixar XML"
                              }, [
                                createVNode("i", { class: "ti ti-download" })
                              ], 8, ["href"])
                            ])
                          ]);
                        }), 128))
                      ])
                    ])
                  ])
                ], 512), [
                  [vShow, activeTab.value === "batches"]
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Financial/Billing/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
