import { ref, unref, withCtx, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, Fragment, renderList, withModifiers, withDirectives, vModelText, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderStyle, ssrIncludeBooleanAttr, ssrRenderAttr } from "vue/server-renderer";
import { useForm, Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./PortalLayout-BsSgHTUU.js";
import { _ as _sfc_main$2 } from "./TablePagination-Dj1_H7YG.js";
const _sfc_main = {
  __name: "Leads",
  __ssrInlineRender: true,
  props: {
    leads: { type: Object, required: true },
    urls: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const formOpen = ref(false);
    const form = useForm({
      name: "",
      email: "",
      phone: "",
      city: "",
      state: "",
      notes: ""
    });
    function submit() {
      form.post(props.urls.store, {
        preserveScroll: true,
        onSuccess: () => {
          form.reset();
          formOpen.value = false;
        }
      });
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), { title: "Meus Leads — Portal de Parceiros" }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, null, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="d-flex justify-content-between align-items-center mb-3"${_scopeId}><h4 class="fw-bold mb-0"${_scopeId}><i class="ti ti-users me-1 text-primary"${_scopeId}></i>Meus Leads <span class="badge bg-secondary fs-13 ms-1"${_scopeId}>${ssrInterpolate(__props.leads.total)}</span></h4><button type="button" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-plus me-1"${_scopeId}></i>Indicar novo lead </button></div><div class="card shadow-sm"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Data</th><th${_scopeId}>Nome</th><th${_scopeId}>Contato</th><th${_scopeId}>Cidade/UF</th><th class="text-center"${_scopeId}>Status</th></tr></thead><tbody${_scopeId}>`);
            if (__props.leads.data.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="5" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-user-off fs-1 d-block mb-2 opacity-25"${_scopeId}></i> Você ainda não indicou nenhum lead. <div class="mt-2"${_scopeId}><button type="button" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-plus me-1"${_scopeId}></i>Indicar agora </button></div></td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.leads.data, (l) => {
              _push2(`<tr${_scopeId}><td class="small text-muted"${_scopeId}>${ssrInterpolate(l.created_at)}</td><td class="fw-medium"${_scopeId}>${ssrInterpolate(l.name)}</td><td class="text-muted small"${_scopeId}>`);
              if (l.email) {
                _push2(`<div${_scopeId}><i class="ti ti-mail me-1"${_scopeId}></i>${ssrInterpolate(l.email)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              if (l.phone) {
                _push2(`<div${_scopeId}><i class="ti ti-phone me-1"${_scopeId}></i>${ssrInterpolate(l.phone)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</td><td class="text-muted small"${_scopeId}>${ssrInterpolate(l.city_state || "—")}</td><td class="text-center"${_scopeId}><span class="${ssrRenderClass(`badge ${l.status_badge}`)}"${_scopeId}>${ssrInterpolate(l.status_label)}</span></td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              data: __props.leads,
              class: "mt-3"
            }, null, _parent2, _scopeId));
            if (formOpen.value) {
              _push2(`<div class="modal d-block" tabindex="-1" style="${ssrRenderStyle({ "background": "rgba(0,0,0,.45)" })}"${_scopeId}><div class="modal-dialog modal-dialog-centered"${_scopeId}><div class="modal-content"${_scopeId}><div class="modal-header"${_scopeId}><h5 class="modal-title"${_scopeId}><i class="ti ti-user-plus me-1 text-primary"${_scopeId}></i>Indicar novo lead </h5><button type="button" class="btn-close"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}></button></div><form${_scopeId}><div class="modal-body"${_scopeId}><div class="row g-3"${_scopeId}><div class="col-12"${_scopeId}><label class="form-label"${_scopeId}>Nome <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).name)} type="text" maxlength="255" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.name }, "form-control"])}" required${_scopeId}>`);
              if (unref(form).errors.name) {
                _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.name)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div class="col-md-6"${_scopeId}><label class="form-label"${_scopeId}>E-mail <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).email)} type="email" maxlength="255" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.email }, "form-control"])}" required${_scopeId}>`);
              if (unref(form).errors.email) {
                _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.email)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div class="col-md-6"${_scopeId}><label class="form-label"${_scopeId}>Telefone</label><input${ssrRenderAttr("value", unref(form).phone)} type="text" maxlength="20" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.phone }, "form-control"])}"${_scopeId}></div><div class="col-md-9"${_scopeId}><label class="form-label"${_scopeId}>Cidade</label><input${ssrRenderAttr("value", unref(form).city)} type="text" maxlength="100" class="form-control"${_scopeId}></div><div class="col-md-3"${_scopeId}><label class="form-label"${_scopeId}>UF</label><input${ssrRenderAttr("value", unref(form).state)} type="text" maxlength="2" class="form-control text-uppercase"${_scopeId}></div><div class="col-12"${_scopeId}><label class="form-label"${_scopeId}>Observações</label><textarea rows="3" maxlength="500" class="form-control"${_scopeId}>${ssrInterpolate(unref(form).notes)}</textarea></div></div></div><div class="modal-footer"${_scopeId}><button type="button" class="btn btn-outline-secondary btn-sm"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}> Cancelar </button><button type="submit" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}>`);
              if (unref(form).processing) {
                _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
              } else {
                _push2(`<i class="ti ti-check me-1"${_scopeId}></i>`);
              }
              _push2(` Cadastrar lead </button></div></form></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              createVNode("div", { class: "d-flex justify-content-between align-items-center mb-3" }, [
                createVNode("h4", { class: "fw-bold mb-0" }, [
                  createVNode("i", { class: "ti ti-users me-1 text-primary" }),
                  createTextVNode("Meus Leads "),
                  createVNode("span", { class: "badge bg-secondary fs-13 ms-1" }, toDisplayString(__props.leads.total), 1)
                ]),
                createVNode("button", {
                  type: "button",
                  class: "btn btn-primary btn-sm",
                  onClick: ($event) => formOpen.value = true
                }, [
                  createVNode("i", { class: "ti ti-plus me-1" }),
                  createTextVNode("Indicar novo lead ")
                ], 8, ["onClick"])
              ]),
              createVNode("div", { class: "card shadow-sm" }, [
                createVNode("div", { class: "table-responsive" }, [
                  createVNode("table", { class: "table table-hover align-middle mb-0" }, [
                    createVNode("thead", { class: "table-light" }, [
                      createVNode("tr", null, [
                        createVNode("th", null, "Data"),
                        createVNode("th", null, "Nome"),
                        createVNode("th", null, "Contato"),
                        createVNode("th", null, "Cidade/UF"),
                        createVNode("th", { class: "text-center" }, "Status")
                      ])
                    ]),
                    createVNode("tbody", null, [
                      __props.leads.data.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                        createVNode("td", {
                          colspan: "5",
                          class: "text-center text-muted py-5"
                        }, [
                          createVNode("i", { class: "ti ti-user-off fs-1 d-block mb-2 opacity-25" }),
                          createTextVNode(" Você ainda não indicou nenhum lead. "),
                          createVNode("div", { class: "mt-2" }, [
                            createVNode("button", {
                              type: "button",
                              class: "btn btn-primary btn-sm",
                              onClick: ($event) => formOpen.value = true
                            }, [
                              createVNode("i", { class: "ti ti-plus me-1" }),
                              createTextVNode("Indicar agora ")
                            ], 8, ["onClick"])
                          ])
                        ])
                      ])) : createCommentVNode("", true),
                      (openBlock(true), createBlock(Fragment, null, renderList(__props.leads.data, (l) => {
                        return openBlock(), createBlock("tr", {
                          key: l.id
                        }, [
                          createVNode("td", { class: "small text-muted" }, toDisplayString(l.created_at), 1),
                          createVNode("td", { class: "fw-medium" }, toDisplayString(l.name), 1),
                          createVNode("td", { class: "text-muted small" }, [
                            l.email ? (openBlock(), createBlock("div", { key: 0 }, [
                              createVNode("i", { class: "ti ti-mail me-1" }),
                              createTextVNode(toDisplayString(l.email), 1)
                            ])) : createCommentVNode("", true),
                            l.phone ? (openBlock(), createBlock("div", { key: 1 }, [
                              createVNode("i", { class: "ti ti-phone me-1" }),
                              createTextVNode(toDisplayString(l.phone), 1)
                            ])) : createCommentVNode("", true)
                          ]),
                          createVNode("td", { class: "text-muted small" }, toDisplayString(l.city_state || "—"), 1),
                          createVNode("td", { class: "text-center" }, [
                            createVNode("span", {
                              class: `badge ${l.status_badge}`
                            }, toDisplayString(l.status_label), 3)
                          ])
                        ]);
                      }), 128))
                    ])
                  ])
                ])
              ]),
              createVNode(_sfc_main$2, {
                data: __props.leads,
                class: "mt-3"
              }, null, 8, ["data"]),
              formOpen.value ? (openBlock(), createBlock("div", {
                key: 0,
                class: "modal d-block",
                tabindex: "-1",
                style: { "background": "rgba(0,0,0,.45)" },
                onClick: withModifiers(($event) => formOpen.value = false, ["self"])
              }, [
                createVNode("div", { class: "modal-dialog modal-dialog-centered" }, [
                  createVNode("div", { class: "modal-content" }, [
                    createVNode("div", { class: "modal-header" }, [
                      createVNode("h5", { class: "modal-title" }, [
                        createVNode("i", { class: "ti ti-user-plus me-1 text-primary" }),
                        createTextVNode("Indicar novo lead ")
                      ]),
                      createVNode("button", {
                        type: "button",
                        class: "btn-close",
                        disabled: unref(form).processing,
                        onClick: ($event) => formOpen.value = false
                      }, null, 8, ["disabled", "onClick"])
                    ]),
                    createVNode("form", {
                      onSubmit: withModifiers(submit, ["prevent"])
                    }, [
                      createVNode("div", { class: "modal-body" }, [
                        createVNode("div", { class: "row g-3" }, [
                          createVNode("div", { class: "col-12" }, [
                            createVNode("label", { class: "form-label" }, [
                              createTextVNode("Nome "),
                              createVNode("span", { class: "text-danger" }, "*")
                            ]),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).name = $event,
                              type: "text",
                              maxlength: "255",
                              class: ["form-control", { "is-invalid": unref(form).errors.name }],
                              required: ""
                            }, null, 10, ["onUpdate:modelValue"]), [
                              [vModelText, unref(form).name]
                            ]),
                            unref(form).errors.name ? (openBlock(), createBlock("div", {
                              key: 0,
                              class: "invalid-feedback"
                            }, toDisplayString(unref(form).errors.name), 1)) : createCommentVNode("", true)
                          ]),
                          createVNode("div", { class: "col-md-6" }, [
                            createVNode("label", { class: "form-label" }, [
                              createTextVNode("E-mail "),
                              createVNode("span", { class: "text-danger" }, "*")
                            ]),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).email = $event,
                              type: "email",
                              maxlength: "255",
                              class: ["form-control", { "is-invalid": unref(form).errors.email }],
                              required: ""
                            }, null, 10, ["onUpdate:modelValue"]), [
                              [vModelText, unref(form).email]
                            ]),
                            unref(form).errors.email ? (openBlock(), createBlock("div", {
                              key: 0,
                              class: "invalid-feedback"
                            }, toDisplayString(unref(form).errors.email), 1)) : createCommentVNode("", true)
                          ]),
                          createVNode("div", { class: "col-md-6" }, [
                            createVNode("label", { class: "form-label" }, "Telefone"),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).phone = $event,
                              type: "text",
                              maxlength: "20",
                              class: ["form-control", { "is-invalid": unref(form).errors.phone }]
                            }, null, 10, ["onUpdate:modelValue"]), [
                              [vModelText, unref(form).phone]
                            ])
                          ]),
                          createVNode("div", { class: "col-md-9" }, [
                            createVNode("label", { class: "form-label" }, "Cidade"),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).city = $event,
                              type: "text",
                              maxlength: "100",
                              class: "form-control"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, unref(form).city]
                            ])
                          ]),
                          createVNode("div", { class: "col-md-3" }, [
                            createVNode("label", { class: "form-label" }, "UF"),
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(form).state = $event,
                              type: "text",
                              maxlength: "2",
                              class: "form-control text-uppercase"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, unref(form).state]
                            ])
                          ]),
                          createVNode("div", { class: "col-12" }, [
                            createVNode("label", { class: "form-label" }, "Observações"),
                            withDirectives(createVNode("textarea", {
                              "onUpdate:modelValue": ($event) => unref(form).notes = $event,
                              rows: "3",
                              maxlength: "500",
                              class: "form-control"
                            }, null, 8, ["onUpdate:modelValue"]), [
                              [vModelText, unref(form).notes]
                            ])
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "modal-footer" }, [
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-outline-secondary btn-sm",
                          disabled: unref(form).processing,
                          onClick: ($event) => formOpen.value = false
                        }, " Cancelar ", 8, ["disabled", "onClick"]),
                        createVNode("button", {
                          type: "submit",
                          class: "btn btn-primary btn-sm",
                          disabled: unref(form).processing
                        }, [
                          unref(form).processing ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "spinner-border spinner-border-sm me-1"
                          })) : (openBlock(), createBlock("i", {
                            key: 1,
                            class: "ti ti-check me-1"
                          })),
                          createTextVNode(" Cadastrar lead ")
                        ], 8, ["disabled"])
                      ])
                    ], 32)
                  ])
                ])
              ], 8, ["onClick"])) : createCommentVNode("", true)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Portal/Leads.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
