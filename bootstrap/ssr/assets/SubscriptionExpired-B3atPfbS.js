import { mergeProps, withCtx, unref, createVNode, createTextVNode, openBlock, createBlock, toDisplayString, createCommentVNode, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrRenderList } from "vue/server-renderer";
import { Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "SubscriptionExpired",
  __ssrInlineRender: true,
  props: {
    entity: { type: Object, default: null },
    lastSubscription: { type: Object, default: null },
    plans: { type: Array, default: () => [] },
    urls: { type: Object, required: true }
  },
  setup(__props) {
    function brl(value) {
      return "R$ " + Number(value ?? 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 });
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Assinatura expirada",
        breadcrumbs: []
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container py-5"${_scopeId}><div class="row justify-content-center"${_scopeId}><div class="col-lg-10 col-xl-9"${_scopeId}><div class="text-center mb-5"${_scopeId}><div class="d-inline-flex align-items-center justify-content-center mb-3 bg-danger-subtle rounded-circle" style="${ssrRenderStyle({ "width": "96px", "height": "96px" })}"${_scopeId}><i class="ti ti-lock fs-1 text-danger"${_scopeId}></i></div><h2 class="fw-bold mb-2"${_scopeId}>Assinatura expirada</h2><p class="text-muted mb-0"${_scopeId}>`);
            if (__props.entity) {
              _push2(`<span${_scopeId}>A empresa <strong${_scopeId}>${ssrInterpolate(__props.entity.name)}</strong> está com o acesso bloqueado.</span>`);
            } else {
              _push2(`<span${_scopeId}>O acesso ao sistema está bloqueado.</span>`);
            }
            _push2(` Renove para continuar usando o EasyEye. </p></div>`);
            if (__props.lastSubscription) {
              _push2(`<div class="alert alert-warning d-flex align-items-start mb-4"${_scopeId}><i class="ti ti-info-circle fs-4 me-2 mt-1"${_scopeId}></i><div${_scopeId}><strong${_scopeId}>Última assinatura:</strong> ${ssrInterpolate(__props.lastSubscription.plan_name ?? "—")} `);
              if (__props.lastSubscription.ends_at) {
                _push2(`<span${_scopeId}> — encerrada em ${ssrInterpolate(__props.lastSubscription.ends_at)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              if (__props.lastSubscription.status) {
                _push2(`<span class="badge bg-secondary ms-2 fs-11"${_scopeId}>${ssrInterpolate(__props.lastSubscription.status)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<h5 class="fw-semibold mb-3"${_scopeId}>Planos disponíveis</h5>`);
            if (__props.plans.length === 0) {
              _push2(`<div class="alert alert-info"${_scopeId}> Nenhum plano público disponível no momento. Entre em contato com o suporte. </div>`);
            } else {
              _push2(`<div class="row g-3 mb-4"${_scopeId}><!--[-->`);
              ssrRenderList(__props.plans, (plan) => {
                _push2(`<div class="col-md-4"${_scopeId}><div class="card h-100 shadow-sm"${_scopeId}><div class="card-body d-flex flex-column"${_scopeId}><h6 class="fw-bold mb-1"${_scopeId}>${ssrInterpolate(plan.name)}</h6>`);
                if (plan.description) {
                  _push2(`<p class="text-muted small mb-3"${_scopeId}>${ssrInterpolate(plan.description)}</p>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`<div class="mb-3"${_scopeId}><span class="fs-3 fw-bold text-primary"${_scopeId}>${ssrInterpolate(brl(plan.price))}</span><small class="text-muted"${_scopeId}>/ ${ssrInterpolate(plan.billing_cycle === "monthly" ? "mês" : "ano")}</small></div><ul class="list-unstyled small mb-3 flex-grow-1"${_scopeId}><!--[-->`);
                ssrRenderList(plan.features_map, (value, key) => {
                  _push2(`<li class="mb-1"${_scopeId}><i class="ti ti-check text-success me-1"${_scopeId}></i><span class="text-muted"${_scopeId}>${ssrInterpolate(key)}:</span><strong${_scopeId}>${ssrInterpolate(value)}</strong></li>`);
                });
                _push2(`<!--]--></ul><button type="button" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-shopping-cart me-1"${_scopeId}></i>Contratar este plano </button></div></div></div>`);
              });
              _push2(`<!--]--></div>`);
            }
            _push2(`<div class="text-center text-muted small"${_scopeId}><p class="mb-2"${_scopeId}>Em caso de dúvidas, entre em contato com o suporte.</p>`);
            _push2(ssrRenderComponent(unref(Link), {
              href: __props.urls.logout,
              method: "post",
              as: "button",
              class: "btn btn-link btn-sm"
            }, {
              default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<i class="ti ti-logout me-1"${_scopeId2}></i>Sair `);
                } else {
                  return [
                    createVNode("i", { class: "ti ti-logout me-1" }),
                    createTextVNode("Sair ")
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`</div></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container py-5" }, [
                createVNode("div", { class: "row justify-content-center" }, [
                  createVNode("div", { class: "col-lg-10 col-xl-9" }, [
                    createVNode("div", { class: "text-center mb-5" }, [
                      createVNode("div", {
                        class: "d-inline-flex align-items-center justify-content-center mb-3 bg-danger-subtle rounded-circle",
                        style: { "width": "96px", "height": "96px" }
                      }, [
                        createVNode("i", { class: "ti ti-lock fs-1 text-danger" })
                      ]),
                      createVNode("h2", { class: "fw-bold mb-2" }, "Assinatura expirada"),
                      createVNode("p", { class: "text-muted mb-0" }, [
                        __props.entity ? (openBlock(), createBlock("span", { key: 0 }, [
                          createTextVNode("A empresa "),
                          createVNode("strong", null, toDisplayString(__props.entity.name), 1),
                          createTextVNode(" está com o acesso bloqueado.")
                        ])) : (openBlock(), createBlock("span", { key: 1 }, "O acesso ao sistema está bloqueado.")),
                        createTextVNode(" Renove para continuar usando o EasyEye. ")
                      ])
                    ]),
                    __props.lastSubscription ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "alert alert-warning d-flex align-items-start mb-4"
                    }, [
                      createVNode("i", { class: "ti ti-info-circle fs-4 me-2 mt-1" }),
                      createVNode("div", null, [
                        createVNode("strong", null, "Última assinatura:"),
                        createTextVNode(" " + toDisplayString(__props.lastSubscription.plan_name ?? "—") + " ", 1),
                        __props.lastSubscription.ends_at ? (openBlock(), createBlock("span", { key: 0 }, " — encerrada em " + toDisplayString(__props.lastSubscription.ends_at), 1)) : createCommentVNode("", true),
                        __props.lastSubscription.status ? (openBlock(), createBlock("span", {
                          key: 1,
                          class: "badge bg-secondary ms-2 fs-11"
                        }, toDisplayString(__props.lastSubscription.status), 1)) : createCommentVNode("", true)
                      ])
                    ])) : createCommentVNode("", true),
                    createVNode("h5", { class: "fw-semibold mb-3" }, "Planos disponíveis"),
                    __props.plans.length === 0 ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "alert alert-info"
                    }, " Nenhum plano público disponível no momento. Entre em contato com o suporte. ")) : (openBlock(), createBlock("div", {
                      key: 2,
                      class: "row g-3 mb-4"
                    }, [
                      (openBlock(true), createBlock(Fragment, null, renderList(__props.plans, (plan) => {
                        return openBlock(), createBlock("div", {
                          key: plan.id,
                          class: "col-md-4"
                        }, [
                          createVNode("div", { class: "card h-100 shadow-sm" }, [
                            createVNode("div", { class: "card-body d-flex flex-column" }, [
                              createVNode("h6", { class: "fw-bold mb-1" }, toDisplayString(plan.name), 1),
                              plan.description ? (openBlock(), createBlock("p", {
                                key: 0,
                                class: "text-muted small mb-3"
                              }, toDisplayString(plan.description), 1)) : createCommentVNode("", true),
                              createVNode("div", { class: "mb-3" }, [
                                createVNode("span", { class: "fs-3 fw-bold text-primary" }, toDisplayString(brl(plan.price)), 1),
                                createVNode("small", { class: "text-muted" }, "/ " + toDisplayString(plan.billing_cycle === "monthly" ? "mês" : "ano"), 1)
                              ]),
                              createVNode("ul", { class: "list-unstyled small mb-3 flex-grow-1" }, [
                                (openBlock(true), createBlock(Fragment, null, renderList(plan.features_map, (value, key) => {
                                  return openBlock(), createBlock("li", {
                                    key,
                                    class: "mb-1"
                                  }, [
                                    createVNode("i", { class: "ti ti-check text-success me-1" }),
                                    createVNode("span", { class: "text-muted" }, toDisplayString(key) + ":", 1),
                                    createVNode("strong", null, toDisplayString(value), 1)
                                  ]);
                                }), 128))
                              ]),
                              createVNode("button", {
                                type: "button",
                                class: "btn btn-primary btn-sm"
                              }, [
                                createVNode("i", { class: "ti ti-shopping-cart me-1" }),
                                createTextVNode("Contratar este plano ")
                              ])
                            ])
                          ])
                        ]);
                      }), 128))
                    ])),
                    createVNode("div", { class: "text-center text-muted small" }, [
                      createVNode("p", { class: "mb-2" }, "Em caso de dúvidas, entre em contato com o suporte."),
                      createVNode(unref(Link), {
                        href: __props.urls.logout,
                        method: "post",
                        as: "button",
                        class: "btn btn-link btn-sm"
                      }, {
                        default: withCtx(() => [
                          createVNode("i", { class: "ti ti-logout me-1" }),
                          createTextVNode("Sair ")
                        ]),
                        _: 1
                      }, 8, ["href"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/SubscriptionExpired.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
