import { ref, computed, mergeProps, withCtx, createVNode, withModifiers, withDirectives, vModelText, openBlock, createBlock, Fragment, renderList, toDisplayString, vModelSelect, createTextVNode, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderAttr, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrInterpolate } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Schedules",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    doctors: { type: Array, default: () => [] },
    covenants: { type: Array, default: () => [] },
    situations: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    schedules: { type: Array, default: () => [] },
    summary: { type: Object, default: null },
    byDoctor: { type: Array, default: () => [] }
  },
  setup(__props) {
    const props = __props;
    const form = ref({
      date_from: props.filters.date_from || "",
      date_until: props.filters.date_until || "",
      doctor_id: props.filters.doctor_id || "",
      covenant_id: props.filters.covenant_id || "",
      situation: props.filters.situation || ""
    });
    function applyFilter() {
      router.get(route("panel.reports.schedules"), form.value, { preserveState: true, preserveScroll: true });
    }
    const hasResults = computed(() => props.summary !== null);
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Relatório de Agenda",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, { title: "Relatório de Agenda" }, null, _parent2, _scopeId));
            _push2(`<div class="card border-0 shadow-sm mb-3"${_scopeId}><div class="card-body py-3"${_scopeId}><form class="row g-2 align-items-end"${_scopeId}><div class="col-md-2"${_scopeId}><label class="form-label small mb-1"${_scopeId}>De *</label><input${ssrRenderAttr("value", form.value.date_from)} type="date" class="form-control form-control-sm" required${_scopeId}></div><div class="col-md-2"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Até *</label><input${ssrRenderAttr("value", form.value.date_until)} type="date" class="form-control form-control-sm" required${_scopeId}></div><div class="col-md-2"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Médico</label><select class="form-select form-select-sm"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.doctor_id) ? ssrLooseContain(form.value.doctor_id, "") : ssrLooseEqual(form.value.doctor_id, "")) ? " selected" : ""}${_scopeId}>Todos</option><!--[-->`);
            ssrRenderList(__props.doctors, (d) => {
              _push2(`<option${ssrRenderAttr("value", d.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.doctor_id) ? ssrLooseContain(form.value.doctor_id, d.id) : ssrLooseEqual(form.value.doctor_id, d.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(d.name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-md-2"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Convênio</label><select class="form-select form-select-sm"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.covenant_id) ? ssrLooseContain(form.value.covenant_id, "") : ssrLooseEqual(form.value.covenant_id, "")) ? " selected" : ""}${_scopeId}>Todos</option><!--[-->`);
            ssrRenderList(__props.covenants, (c) => {
              _push2(`<option${ssrRenderAttr("value", c.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.covenant_id) ? ssrLooseContain(form.value.covenant_id, c.id) : ssrLooseEqual(form.value.covenant_id, c.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(c.name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-md-2"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Situação</label><select class="form-select form-select-sm"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.situation) ? ssrLooseContain(form.value.situation, "") : ssrLooseEqual(form.value.situation, "")) ? " selected" : ""}${_scopeId}>Todas</option><!--[-->`);
            ssrRenderList(__props.situations, (s) => {
              _push2(`<option${ssrRenderAttr("value", s.value)}${ssrIncludeBooleanAttr(Array.isArray(form.value.situation) ? ssrLooseContain(form.value.situation, s.value) : ssrLooseEqual(form.value.situation, s.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(s.label)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-md-2"${_scopeId}><button type="submit" class="btn btn-primary btn-sm w-100"${_scopeId}><i class="ti ti-filter me-1"${_scopeId}></i>Filtrar </button></div></form></div></div>`);
            if (hasResults.value) {
              _push2(`<!--[--><div class="row g-3 mb-3"${_scopeId}><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Total</small><div class="fw-bold fs-5"${_scopeId}>${ssrInterpolate(__props.summary.total)}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Atendidos</small><div class="fw-bold fs-5 text-success"${_scopeId}>${ssrInterpolate(__props.summary.attended)}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Faltas / Cancelados</small><div class="fw-bold fs-5 text-danger"${_scopeId}>${ssrInterpolate(__props.summary.noshow + __props.summary.cancelled)}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Taxa de presença</small><div class="fw-bold fs-5 text-info"${_scopeId}>${ssrInterpolate(__props.summary.attendance_rate)}%</div></div></div></div></div>`);
              if (__props.byDoctor.length > 0) {
                _push2(`<div class="card mb-3"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0"${_scopeId}>Por médico</h6></div><div class="table-responsive"${_scopeId}><table class="table table-sm table-hover mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Médico</th><th class="text-center"${_scopeId}>Total</th><th class="text-center"${_scopeId}>Atendidos</th><th class="text-center"${_scopeId}>Faltas</th><th class="text-center"${_scopeId}>Cancelados</th></tr></thead><tbody${_scopeId}><!--[-->`);
                ssrRenderList(__props.byDoctor, (row, i) => {
                  _push2(`<tr${_scopeId}><td class="fw-medium"${_scopeId}>${ssrInterpolate(row.doctor_name)}</td><td class="text-center"${_scopeId}>${ssrInterpolate(row.total)}</td><td class="text-center text-success"${_scopeId}>${ssrInterpolate(row.attended)}</td><td class="text-center text-danger"${_scopeId}>${ssrInterpolate(row.noshow)}</td><td class="text-center text-warning"${_scopeId}>${ssrInterpolate(row.cancelled)}</td></tr>`);
                });
                _push2(`<!--]--></tbody></table></div></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="card"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0"${_scopeId}>Agendamentos</h6></div><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Data/hora</th><th${_scopeId}>Paciente</th><th${_scopeId}>Médico</th><th${_scopeId}>Convênio</th><th${_scopeId}>Tipo</th><th class="text-center"${_scopeId}>Situação</th></tr></thead><tbody${_scopeId}>`);
              if (__props.schedules.length === 0) {
                _push2(`<tr${_scopeId}><td colspan="6" class="text-center text-muted py-5"${_scopeId}>Nenhum agendamento.</td></tr>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<!--[-->`);
              ssrRenderList(__props.schedules, (s, i) => {
                _push2(`<tr${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(s.date_time)}</td><td class="fw-medium"${_scopeId}>${ssrInterpolate(s.patient_name || "—")}</td><td${_scopeId}>${ssrInterpolate(s.doctor_name || "—")}</td><td class="text-muted small"${_scopeId}>${ssrInterpolate(s.covenant || "—")}</td><td class="text-muted small"${_scopeId}>${ssrInterpolate(s.visit_type || "—")}</td><td class="text-center"${_scopeId}><span class="badge badge-soft-secondary rounded fs-11"${_scopeId}>${ssrInterpolate(s.situation_label)}</span></td></tr>`);
              });
              _push2(`<!--]--></tbody></table></div></div><!--]-->`);
            } else {
              _push2(`<div class="alert alert-info"${_scopeId}><i class="ti ti-info-circle me-1"${_scopeId}></i> Informe o período acima para gerar o relatório. </div>`);
            }
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, { title: "Relatório de Agenda" }),
                createVNode("div", { class: "card border-0 shadow-sm mb-3" }, [
                  createVNode("div", { class: "card-body py-3" }, [
                    createVNode("form", {
                      onSubmit: withModifiers(applyFilter, ["prevent"]),
                      class: "row g-2 align-items-end"
                    }, [
                      createVNode("div", { class: "col-md-2" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "De *"),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => form.value.date_from = $event,
                          type: "date",
                          class: "form-control form-control-sm",
                          required: ""
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, form.value.date_from]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-2" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "Até *"),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => form.value.date_until = $event,
                          type: "date",
                          class: "form-control form-control-sm",
                          required: ""
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, form.value.date_until]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-2" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "Médico"),
                        withDirectives(createVNode("select", {
                          "onUpdate:modelValue": ($event) => form.value.doctor_id = $event,
                          class: "form-select form-select-sm"
                        }, [
                          createVNode("option", { value: "" }, "Todos"),
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.doctors, (d) => {
                            return openBlock(), createBlock("option", {
                              key: d.id,
                              value: d.id
                            }, toDisplayString(d.name), 9, ["value"]);
                          }), 128))
                        ], 8, ["onUpdate:modelValue"]), [
                          [vModelSelect, form.value.doctor_id]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-2" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "Convênio"),
                        withDirectives(createVNode("select", {
                          "onUpdate:modelValue": ($event) => form.value.covenant_id = $event,
                          class: "form-select form-select-sm"
                        }, [
                          createVNode("option", { value: "" }, "Todos"),
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.covenants, (c) => {
                            return openBlock(), createBlock("option", {
                              key: c.id,
                              value: c.id
                            }, toDisplayString(c.name), 9, ["value"]);
                          }), 128))
                        ], 8, ["onUpdate:modelValue"]), [
                          [vModelSelect, form.value.covenant_id]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-2" }, [
                        createVNode("label", { class: "form-label small mb-1" }, "Situação"),
                        withDirectives(createVNode("select", {
                          "onUpdate:modelValue": ($event) => form.value.situation = $event,
                          class: "form-select form-select-sm"
                        }, [
                          createVNode("option", { value: "" }, "Todas"),
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.situations, (s) => {
                            return openBlock(), createBlock("option", {
                              key: s.value,
                              value: s.value
                            }, toDisplayString(s.label), 9, ["value"]);
                          }), 128))
                        ], 8, ["onUpdate:modelValue"]), [
                          [vModelSelect, form.value.situation]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-2" }, [
                        createVNode("button", {
                          type: "submit",
                          class: "btn btn-primary btn-sm w-100"
                        }, [
                          createVNode("i", { class: "ti ti-filter me-1" }),
                          createTextVNode("Filtrar ")
                        ])
                      ])
                    ], 32)
                  ])
                ]),
                hasResults.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-6 col-md-3" }, [
                      createVNode("div", { class: "card h-100" }, [
                        createVNode("div", { class: "card-body py-3" }, [
                          createVNode("small", { class: "text-muted d-block" }, "Total"),
                          createVNode("div", { class: "fw-bold fs-5" }, toDisplayString(__props.summary.total), 1)
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "col-6 col-md-3" }, [
                      createVNode("div", { class: "card h-100" }, [
                        createVNode("div", { class: "card-body py-3" }, [
                          createVNode("small", { class: "text-muted d-block" }, "Atendidos"),
                          createVNode("div", { class: "fw-bold fs-5 text-success" }, toDisplayString(__props.summary.attended), 1)
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "col-6 col-md-3" }, [
                      createVNode("div", { class: "card h-100" }, [
                        createVNode("div", { class: "card-body py-3" }, [
                          createVNode("small", { class: "text-muted d-block" }, "Faltas / Cancelados"),
                          createVNode("div", { class: "fw-bold fs-5 text-danger" }, toDisplayString(__props.summary.noshow + __props.summary.cancelled), 1)
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "col-6 col-md-3" }, [
                      createVNode("div", { class: "card h-100" }, [
                        createVNode("div", { class: "card-body py-3" }, [
                          createVNode("small", { class: "text-muted d-block" }, "Taxa de presença"),
                          createVNode("div", { class: "fw-bold fs-5 text-info" }, toDisplayString(__props.summary.attendance_rate) + "%", 1)
                        ])
                      ])
                    ])
                  ]),
                  __props.byDoctor.length > 0 ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "card mb-3"
                  }, [
                    createVNode("div", { class: "card-header bg-transparent" }, [
                      createVNode("h6", { class: "mb-0" }, "Por médico")
                    ]),
                    createVNode("div", { class: "table-responsive" }, [
                      createVNode("table", { class: "table table-sm table-hover mb-0" }, [
                        createVNode("thead", { class: "table-light" }, [
                          createVNode("tr", null, [
                            createVNode("th", null, "Médico"),
                            createVNode("th", { class: "text-center" }, "Total"),
                            createVNode("th", { class: "text-center" }, "Atendidos"),
                            createVNode("th", { class: "text-center" }, "Faltas"),
                            createVNode("th", { class: "text-center" }, "Cancelados")
                          ])
                        ]),
                        createVNode("tbody", null, [
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.byDoctor, (row, i) => {
                            return openBlock(), createBlock("tr", { key: i }, [
                              createVNode("td", { class: "fw-medium" }, toDisplayString(row.doctor_name), 1),
                              createVNode("td", { class: "text-center" }, toDisplayString(row.total), 1),
                              createVNode("td", { class: "text-center text-success" }, toDisplayString(row.attended), 1),
                              createVNode("td", { class: "text-center text-danger" }, toDisplayString(row.noshow), 1),
                              createVNode("td", { class: "text-center text-warning" }, toDisplayString(row.cancelled), 1)
                            ]);
                          }), 128))
                        ])
                      ])
                    ])
                  ])) : createCommentVNode("", true),
                  createVNode("div", { class: "card" }, [
                    createVNode("div", { class: "card-header bg-transparent" }, [
                      createVNode("h6", { class: "mb-0" }, "Agendamentos")
                    ]),
                    createVNode("div", { class: "table-responsive" }, [
                      createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                        createVNode("thead", { class: "table-light" }, [
                          createVNode("tr", null, [
                            createVNode("th", null, "Data/hora"),
                            createVNode("th", null, "Paciente"),
                            createVNode("th", null, "Médico"),
                            createVNode("th", null, "Convênio"),
                            createVNode("th", null, "Tipo"),
                            createVNode("th", { class: "text-center" }, "Situação")
                          ])
                        ]),
                        createVNode("tbody", null, [
                          __props.schedules.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                            createVNode("td", {
                              colspan: "6",
                              class: "text-center text-muted py-5"
                            }, "Nenhum agendamento.")
                          ])) : createCommentVNode("", true),
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.schedules, (s, i) => {
                            return openBlock(), createBlock("tr", { key: i }, [
                              createVNode("td", { class: "text-muted small" }, toDisplayString(s.date_time), 1),
                              createVNode("td", { class: "fw-medium" }, toDisplayString(s.patient_name || "—"), 1),
                              createVNode("td", null, toDisplayString(s.doctor_name || "—"), 1),
                              createVNode("td", { class: "text-muted small" }, toDisplayString(s.covenant || "—"), 1),
                              createVNode("td", { class: "text-muted small" }, toDisplayString(s.visit_type || "—"), 1),
                              createVNode("td", { class: "text-center" }, [
                                createVNode("span", { class: "badge badge-soft-secondary rounded fs-11" }, toDisplayString(s.situation_label), 1)
                              ])
                            ]);
                          }), 128))
                        ])
                      ])
                    ])
                  ])
                ], 64)) : (openBlock(), createBlock("div", {
                  key: 1,
                  class: "alert alert-info"
                }, [
                  createVNode("i", { class: "ti ti-info-circle me-1" }),
                  createTextVNode(" Informe o período acima para gerar o relatório. ")
                ]))
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Reports/Schedules.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
