import { ref, computed, mergeProps, withCtx, createVNode, withModifiers, withDirectives, vModelText, openBlock, createBlock, Fragment, renderList, toDisplayString, vModelSelect, createTextVNode, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderAttr, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrInterpolate } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Absenteeism",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    doctors: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    schedules: { type: Array, default: () => [] },
    summary: { type: Object, default: null }
  },
  setup(__props) {
    const props = __props;
    const form = ref({
      date_from: props.filters.date_from || "",
      date_until: props.filters.date_until || "",
      doctor_id: props.filters.doctor_id || ""
    });
    function applyFilter() {
      router.get(route("panel.reports.absenteeism"), form.value, { preserveState: true, preserveScroll: true });
    }
    const hasResults = computed(() => props.summary !== null);
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Relatório de Absenteísmo",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, { title: "Relatório de Absenteísmo" }, null, _parent2, _scopeId));
            _push2(`<div class="card border-0 shadow-sm mb-3"${_scopeId}><div class="card-body py-3"${_scopeId}><form class="row g-2 align-items-end"${_scopeId}><div class="col-md-3"${_scopeId}><label class="form-label small mb-1"${_scopeId}>De *</label><input${ssrRenderAttr("value", form.value.date_from)} type="date" class="form-control form-control-sm" required${_scopeId}></div><div class="col-md-3"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Até *</label><input${ssrRenderAttr("value", form.value.date_until)} type="date" class="form-control form-control-sm" required${_scopeId}></div><div class="col-md-3"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Médico</label><select class="form-select form-select-sm"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.doctor_id) ? ssrLooseContain(form.value.doctor_id, "") : ssrLooseEqual(form.value.doctor_id, "")) ? " selected" : ""}${_scopeId}>Todos</option><!--[-->`);
            ssrRenderList(__props.doctors, (d) => {
              _push2(`<option${ssrRenderAttr("value", d.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.doctor_id) ? ssrLooseContain(form.value.doctor_id, d.id) : ssrLooseEqual(form.value.doctor_id, d.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(d.name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-md-3"${_scopeId}><button type="submit" class="btn btn-primary btn-sm w-100"${_scopeId}><i class="ti ti-filter me-1"${_scopeId}></i>Filtrar </button></div></form></div></div>`);
            if (hasResults.value) {
              _push2(`<!--[--><div class="row g-3 mb-3"${_scopeId}><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Total ausentes</small><div class="fw-bold fs-5 text-danger"${_scopeId}>${ssrInterpolate(__props.summary.total_absent)}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Não comparecimentos</small><div class="fw-bold fs-5 text-warning"${_scopeId}>${ssrInterpolate(__props.summary.noshow)}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Cancelados</small><div class="fw-bold fs-5 text-secondary"${_scopeId}>${ssrInterpolate(__props.summary.cancelled)}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Taxa de absenteísmo</small><div class="fw-bold fs-5 text-danger"${_scopeId}>${ssrInterpolate(__props.summary.absenteeism_rate)}%</div></div></div></div></div><div class="card"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0"${_scopeId}>Detalhe</h6></div><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Data/hora</th><th${_scopeId}>Paciente</th><th${_scopeId}>Médico</th><th${_scopeId}>Convênio</th><th class="text-center"${_scopeId}>Situação</th></tr></thead><tbody${_scopeId}>`);
              if (__props.schedules.length === 0) {
                _push2(`<tr${_scopeId}><td colspan="5" class="text-center text-muted py-5"${_scopeId}>Nenhum registro.</td></tr>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<!--[-->`);
              ssrRenderList(__props.schedules, (s, i) => {
                _push2(`<tr${_scopeId}><td class="text-muted small"${_scopeId}>${ssrInterpolate(s.date_time)}</td><td class="fw-medium"${_scopeId}>${ssrInterpolate(s.patient_name || "—")}</td><td${_scopeId}>${ssrInterpolate(s.doctor_name || "—")}</td><td class="text-muted small"${_scopeId}>${ssrInterpolate(s.covenant || "—")}</td><td class="text-center"${_scopeId}><span class="badge badge-soft-warning rounded fs-11"${_scopeId}>${ssrInterpolate(s.situation_label)}</span></td></tr>`);
              });
              _push2(`<!--]--></tbody></table></div></div><!--]-->`);
            } else {
              _push2(`<div class="alert alert-info"${_scopeId}><i class="ti ti-info-circle me-1"${_scopeId}></i> Informe o período para gerar o relatório. </div>`);
            }
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, { title: "Relatório de Absenteísmo" }),
                createVNode("div", { class: "card border-0 shadow-sm mb-3" }, [
                  createVNode("div", { class: "card-body py-3" }, [
                    createVNode("form", {
                      onSubmit: withModifiers(applyFilter, ["prevent"]),
                      class: "row g-2 align-items-end"
                    }, [
                      createVNode("div", { class: "col-md-3" }, [
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
                      createVNode("div", { class: "col-md-3" }, [
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
                      createVNode("div", { class: "col-md-3" }, [
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
                      createVNode("div", { class: "col-md-3" }, [
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
                          createVNode("small", { class: "text-muted d-block" }, "Total ausentes"),
                          createVNode("div", { class: "fw-bold fs-5 text-danger" }, toDisplayString(__props.summary.total_absent), 1)
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "col-6 col-md-3" }, [
                      createVNode("div", { class: "card h-100" }, [
                        createVNode("div", { class: "card-body py-3" }, [
                          createVNode("small", { class: "text-muted d-block" }, "Não comparecimentos"),
                          createVNode("div", { class: "fw-bold fs-5 text-warning" }, toDisplayString(__props.summary.noshow), 1)
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "col-6 col-md-3" }, [
                      createVNode("div", { class: "card h-100" }, [
                        createVNode("div", { class: "card-body py-3" }, [
                          createVNode("small", { class: "text-muted d-block" }, "Cancelados"),
                          createVNode("div", { class: "fw-bold fs-5 text-secondary" }, toDisplayString(__props.summary.cancelled), 1)
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "col-6 col-md-3" }, [
                      createVNode("div", { class: "card h-100" }, [
                        createVNode("div", { class: "card-body py-3" }, [
                          createVNode("small", { class: "text-muted d-block" }, "Taxa de absenteísmo"),
                          createVNode("div", { class: "fw-bold fs-5 text-danger" }, toDisplayString(__props.summary.absenteeism_rate) + "%", 1)
                        ])
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "card" }, [
                    createVNode("div", { class: "card-header bg-transparent" }, [
                      createVNode("h6", { class: "mb-0" }, "Detalhe")
                    ]),
                    createVNode("div", { class: "table-responsive" }, [
                      createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                        createVNode("thead", { class: "table-light" }, [
                          createVNode("tr", null, [
                            createVNode("th", null, "Data/hora"),
                            createVNode("th", null, "Paciente"),
                            createVNode("th", null, "Médico"),
                            createVNode("th", null, "Convênio"),
                            createVNode("th", { class: "text-center" }, "Situação")
                          ])
                        ]),
                        createVNode("tbody", null, [
                          __props.schedules.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                            createVNode("td", {
                              colspan: "5",
                              class: "text-center text-muted py-5"
                            }, "Nenhum registro.")
                          ])) : createCommentVNode("", true),
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.schedules, (s, i) => {
                            return openBlock(), createBlock("tr", { key: i }, [
                              createVNode("td", { class: "text-muted small" }, toDisplayString(s.date_time), 1),
                              createVNode("td", { class: "fw-medium" }, toDisplayString(s.patient_name || "—"), 1),
                              createVNode("td", null, toDisplayString(s.doctor_name || "—"), 1),
                              createVNode("td", { class: "text-muted small" }, toDisplayString(s.covenant || "—"), 1),
                              createVNode("td", { class: "text-center" }, [
                                createVNode("span", { class: "badge badge-soft-warning rounded fs-11" }, toDisplayString(s.situation_label), 1)
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
                  createTextVNode(" Informe o período para gerar o relatório. ")
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Reports/Absenteeism.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
