import { ref, mergeProps, withCtx, createVNode, createTextVNode, withDirectives, vModelText, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderAttr, ssrRenderClass } from "vue/server-renderer";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import "@inertiajs/vue3";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    exports: { type: Object, required: true }
  },
  setup(__props) {
    const auditForm = ref({ date_from: "", date_until: "" });
    const accessForm = ref({ date_from: "", date_until: "" });
    function buildUrl(base, params) {
      const url = new URL(base, window.location.origin);
      Object.entries(params).forEach(([k, v]) => v && url.searchParams.append(k, v));
      return url.toString();
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Compliance & Auditoria",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: "Compliance & Auditoria",
              subtitle: "LGPD/CFM — exporte trilhas de auditoria do período"
            }, null, _parent2, _scopeId));
            _push2(`<div class="row g-3"${_scopeId}><div class="col-md-6"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-shield-check me-1 text-primary"${_scopeId}></i> Audit log (criações, alterações e exclusões) </h6></div><div class="card-body"${_scopeId}><p class="small text-muted mb-3"${_scopeId}> Trilha CUD de todos os models auditáveis (Patient, MedicalRecord, Schedule etc.) no período. </p><form class="row g-2"${_scopeId}><div class="col-md-6"${_scopeId}><label class="form-label small mb-1"${_scopeId}>De *</label><input${ssrRenderAttr("value", auditForm.value.date_from)} type="date" class="form-control form-control-sm" required${_scopeId}></div><div class="col-md-6"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Até *</label><input${ssrRenderAttr("value", auditForm.value.date_until)} type="date" class="form-control form-control-sm" required${_scopeId}></div><div class="col-12 mt-3"${_scopeId}><a${ssrRenderAttr("href", buildUrl(__props.exports.audit, auditForm.value))} class="${ssrRenderClass([{ disabled: !auditForm.value.date_from || !auditForm.value.date_until }, "btn btn-primary btn-sm w-100"])}"${_scopeId}><i class="ti ti-download me-1"${_scopeId}></i>Exportar CSV </a></div></form></div></div></div><div class="col-md-6"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-eye me-1 text-warning"${_scopeId}></i> Logs de acesso a dados sensíveis (LGPD) </h6></div><div class="card-body"${_scopeId}><p class="small text-muted mb-3"${_scopeId}> Quem acessou quais prontuários e qual a justificativa LGPD (rastreio para responder Solicitações de Titular). </p><form class="row g-2"${_scopeId}><div class="col-md-6"${_scopeId}><label class="form-label small mb-1"${_scopeId}>De *</label><input${ssrRenderAttr("value", accessForm.value.date_from)} type="date" class="form-control form-control-sm" required${_scopeId}></div><div class="col-md-6"${_scopeId}><label class="form-label small mb-1"${_scopeId}>Até *</label><input${ssrRenderAttr("value", accessForm.value.date_until)} type="date" class="form-control form-control-sm" required${_scopeId}></div><div class="col-12 mt-3"${_scopeId}><a${ssrRenderAttr("href", buildUrl(__props.exports.data_access, accessForm.value))} class="${ssrRenderClass([{ disabled: !accessForm.value.date_from || !accessForm.value.date_until }, "btn btn-warning btn-sm w-100"])}"${_scopeId}><i class="ti ti-download me-1"${_scopeId}></i>Exportar CSV </a></div></form></div></div></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: "Compliance & Auditoria",
                  subtitle: "LGPD/CFM — exporte trilhas de auditoria do período"
                }),
                createVNode("div", { class: "row g-3" }, [
                  createVNode("div", { class: "col-md-6" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-header bg-transparent" }, [
                        createVNode("h6", { class: "mb-0 fw-semibold" }, [
                          createVNode("i", { class: "ti ti-shield-check me-1 text-primary" }),
                          createTextVNode(" Audit log (criações, alterações e exclusões) ")
                        ])
                      ]),
                      createVNode("div", { class: "card-body" }, [
                        createVNode("p", { class: "small text-muted mb-3" }, " Trilha CUD de todos os models auditáveis (Patient, MedicalRecord, Schedule etc.) no período. "),
                        createVNode("form", { class: "row g-2" }, [
                          createVNode("div", { class: "col-md-6" }, [
                            createVNode("label", { class: "form-label small mb-1" }, "De *"),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => auditForm.value.date_from = $event,
                              type: "date",
                              class: "form-control form-control-sm",
                              required: ""
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, auditForm.value.date_from]
                            ])
                          ]),
                          createVNode("div", { class: "col-md-6" }, [
                            createVNode("label", { class: "form-label small mb-1" }, "Até *"),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => auditForm.value.date_until = $event,
                              type: "date",
                              class: "form-control form-control-sm",
                              required: ""
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, auditForm.value.date_until]
                            ])
                          ]),
                          createVNode("div", { class: "col-12 mt-3" }, [
                            createVNode("a", {
                              href: buildUrl(__props.exports.audit, auditForm.value),
                              class: ["btn btn-primary btn-sm w-100", { disabled: !auditForm.value.date_from || !auditForm.value.date_until }]
                            }, [
                              createVNode("i", { class: "ti ti-download me-1" }),
                              createTextVNode("Exportar CSV ")
                            ], 10, ["href"])
                          ])
                        ])
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-md-6" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-header bg-transparent" }, [
                        createVNode("h6", { class: "mb-0 fw-semibold" }, [
                          createVNode("i", { class: "ti ti-eye me-1 text-warning" }),
                          createTextVNode(" Logs de acesso a dados sensíveis (LGPD) ")
                        ])
                      ]),
                      createVNode("div", { class: "card-body" }, [
                        createVNode("p", { class: "small text-muted mb-3" }, " Quem acessou quais prontuários e qual a justificativa LGPD (rastreio para responder Solicitações de Titular). "),
                        createVNode("form", { class: "row g-2" }, [
                          createVNode("div", { class: "col-md-6" }, [
                            createVNode("label", { class: "form-label small mb-1" }, "De *"),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => accessForm.value.date_from = $event,
                              type: "date",
                              class: "form-control form-control-sm",
                              required: ""
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, accessForm.value.date_from]
                            ])
                          ]),
                          createVNode("div", { class: "col-md-6" }, [
                            createVNode("label", { class: "form-label small mb-1" }, "Até *"),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => accessForm.value.date_until = $event,
                              type: "date",
                              class: "form-control form-control-sm",
                              required: ""
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, accessForm.value.date_until]
                            ])
                          ]),
                          createVNode("div", { class: "col-12 mt-3" }, [
                            createVNode("a", {
                              href: buildUrl(__props.exports.data_access, accessForm.value),
                              class: ["btn btn-warning btn-sm w-100", { disabled: !accessForm.value.date_from || !accessForm.value.date_until }]
                            }, [
                              createVNode("i", { class: "ti ti-download me-1" }),
                              createTextVNode("Exportar CSV ")
                            ], 10, ["href"])
                          ])
                        ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Compliance/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
