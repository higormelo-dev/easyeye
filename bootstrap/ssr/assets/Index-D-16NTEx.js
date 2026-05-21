import { ref, mergeProps, withCtx, createVNode, openBlock, createBlock, toDisplayString, createTextVNode, Fragment, renderList, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrRenderList } from "vue/server-renderer";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import _sfc_main$1 from "./GatewayCard-Bm85Gf00.js";
import _sfc_main$3 from "./GatewayCredentialsModal-CMCW6XVG.js";
import _sfc_main$4 from "./GatewayEntityAccessModal-CPlDYVls.js";
import _sfc_main$5 from "./GatewayPriorityModal-DyZuL9tU.js";
import _sfc_main$2 from "./GatewayChangeDefaultModal-Cs3bvGle.js";
import "@inertiajs/vue3";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./ConfirmationWithReasonModal-CmfO7qbN.js";
import "./useConfirmationWithReason-DDlQOe6J.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    gateways: { type: Array, default: () => [] },
    defaultGateway: { type: Object, default: null },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const breadcrumbs = [
      { label: props.t.breadcrumb ?? "Gateways de Pagamento" }
    ];
    const credOpen = ref(false);
    const credGateway = ref(null);
    function openCredentials(g) {
      credGateway.value = g;
      credOpen.value = true;
    }
    function closeCredentials() {
      credOpen.value = false;
      credGateway.value = null;
    }
    const eaOpen = ref(false);
    const eaGateway = ref(null);
    function openEntityAccess(g) {
      eaGateway.value = g;
      eaOpen.value = true;
    }
    function closeEntityAccess() {
      eaOpen.value = false;
      eaGateway.value = null;
    }
    const prioOpen = ref(false);
    const prioGateway = ref(null);
    function openPriority(g) {
      prioGateway.value = g;
      prioOpen.value = true;
    }
    function closePriority() {
      prioOpen.value = false;
      prioGateway.value = null;
    }
    const defaultOpen = ref(false);
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.title,
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}><p class="text-muted small mb-4"${_scopeId}>${__props.t.subtitle ?? ""}</p>`);
            if (__props.defaultGateway) {
              _push2(`<div class="alert d-flex align-items-center gap-3 py-3 mb-4" style="${ssrRenderStyle({ "background": "linear-gradient(135deg,#fff8e1 0%,#fffde7 100%)", "border": "1.5px solid #fdd835", "border-radius": "10px" })}"${_scopeId}><div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle" style="${ssrRenderStyle({ "width": "42px", "height": "42px", "background": "#fdd835" })}"${_scopeId}><i class="ti ti-star-filled text-dark fs-20"${_scopeId}></i></div><div class="flex-grow-1"${_scopeId}><div class="fw-bold mb-0" style="${ssrRenderStyle({ "color": "#5d4037" })}"${_scopeId}>${ssrInterpolate(__props.t.default_banner_title)}</div><div class="d-flex align-items-center gap-2 mt-1 flex-wrap"${_scopeId}><span class="fw-semibold" style="${ssrRenderStyle({ "color": "#333" })}"${_scopeId}>${ssrInterpolate(__props.defaultGateway.name)}</span><span class="badge text-uppercase" style="${ssrRenderStyle({ "background": "#fdd835", "color": "#5d4037", "font-size": ".7rem" })}"${_scopeId}>${ssrInterpolate(__props.defaultGateway.code)}</span><span class="text-muted small"${_scopeId}>${ssrInterpolate(__props.t.default_banner_subtitle)}</span></div></div><div class="flex-shrink-0"${_scopeId}><button type="button" class="btn btn-sm btn-outline-secondary"${_scopeId}><i class="ti ti-switch-horizontal me-1"${_scopeId}></i>${ssrInterpolate(__props.t.default_banner_change)}</button></div></div>`);
            } else {
              _push2(`<div class="alert alert-danger d-flex align-items-center gap-3 py-3 mb-4" style="${ssrRenderStyle({ "border-radius": "10px" })}"${_scopeId}><i class="ti ti-alert-octagon fs-22 flex-shrink-0"${_scopeId}></i><div class="flex-grow-1"${_scopeId}><strong${_scopeId}>${ssrInterpolate(__props.t.no_default_title)}</strong><span class="ms-1 small"${_scopeId}>${ssrInterpolate(__props.t.no_default_subtitle)}</span></div><button type="button" class="btn btn-sm btn-danger flex-shrink-0"${_scopeId}><i class="ti ti-star me-1"${_scopeId}></i>${ssrInterpolate(__props.t.no_default_action)}</button></div>`);
            }
            _push2(`<div class="row g-3 mb-4"${_scopeId}><div class="col-md-6"${_scopeId}><div class="card border-primary border-opacity-50 h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><div class="d-flex gap-3 align-items-start"${_scopeId}><div class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 bg-primary bg-opacity-10" style="${ssrRenderStyle({ "width": "38px", "height": "38px" })}"${_scopeId}><i class="ti ti-building-store text-primary fs-18"${_scopeId}></i></div><div${_scopeId}><p class="fw-semibold mb-1 small"${_scopeId}>${ssrInterpolate(__props.t.ctx_saas_title)}</p><p class="text-muted mb-1" style="${ssrRenderStyle({ "font-size": ".8rem" })}"${_scopeId}>${__props.t.ctx_saas_desc ?? ""}</p><span class="badge badge-soft-primary" style="${ssrRenderStyle({ "font-size": ".72rem" })}"${_scopeId}>${ssrInterpolate(__props.t.ctx_saas_badge)}</span></div></div></div></div></div><div class="col-md-6"${_scopeId}><div class="card border-success border-opacity-50 h-100"${_scopeId}><div class="card-body py-3"${_scopeId}><div class="d-flex gap-3 align-items-start"${_scopeId}><div class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 bg-success bg-opacity-10" style="${ssrRenderStyle({ "width": "38px", "height": "38px" })}"${_scopeId}><i class="ti ti-building-hospital text-success fs-18"${_scopeId}></i></div><div${_scopeId}><p class="fw-semibold mb-1 small"${_scopeId}>${ssrInterpolate(__props.t.ctx_tenant_title)}</p><p class="text-muted mb-1" style="${ssrRenderStyle({ "font-size": ".8rem" })}"${_scopeId}>${__props.t.ctx_tenant_desc ?? ""}</p><span class="badge badge-soft-success" style="${ssrRenderStyle({ "font-size": ".72rem" })}"${_scopeId}>${ssrInterpolate(__props.t.ctx_tenant_badge)}</span></div></div></div></div></div></div><div class="row g-3"${_scopeId}><!--[-->`);
            ssrRenderList(__props.gateways, (gateway) => {
              _push2(`<div class="col-md-6 col-xl-4"${_scopeId}>`);
              _push2(ssrRenderComponent(_sfc_main$1, {
                gateway,
                t: __props.t,
                onOpenCredentials: openCredentials,
                onOpenEntityAccess: openEntityAccess,
                onOpenPriority: openPriority,
                onOpenSetDefault: ($event) => defaultOpen.value = true
              }, null, _parent2, _scopeId));
              _push2(`</div>`);
            });
            _push2(`<!--]-->`);
            if (__props.gateways.length === 0) {
              _push2(`<div class="col-12"${_scopeId}><div class="text-center py-5 text-muted"${_scopeId}><i class="ti ti-credit-card-off fs-40 d-block mb-2 opacity-40"${_scopeId}></i><p class="small mb-0"${_scopeId}>Nenhum gateway cadastrado.</p></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              open: defaultOpen.value,
              gateways: __props.gateways,
              "default-gateway": __props.defaultGateway,
              t: __props.t,
              onClose: ($event) => defaultOpen.value = false
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$3, {
              open: credOpen.value,
              gateway: credGateway.value,
              t: __props.t,
              onClose: closeCredentials
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$4, {
              open: eaOpen.value,
              gateway: eaGateway.value,
              t: __props.t,
              onClose: closeEntityAccess
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$5, {
              open: prioOpen.value,
              gateway: prioGateway.value,
              t: __props.t,
              onClose: closePriority
            }, null, _parent2, _scopeId));
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode("p", {
                  class: "text-muted small mb-4",
                  innerHTML: __props.t.subtitle
                }, null, 8, ["innerHTML"]),
                __props.defaultGateway ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "alert d-flex align-items-center gap-3 py-3 mb-4",
                  style: { "background": "linear-gradient(135deg,#fff8e1 0%,#fffde7 100%)", "border": "1.5px solid #fdd835", "border-radius": "10px" }
                }, [
                  createVNode("div", {
                    class: "flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle",
                    style: { "width": "42px", "height": "42px", "background": "#fdd835" }
                  }, [
                    createVNode("i", { class: "ti ti-star-filled text-dark fs-20" })
                  ]),
                  createVNode("div", { class: "flex-grow-1" }, [
                    createVNode("div", {
                      class: "fw-bold mb-0",
                      style: { "color": "#5d4037" }
                    }, toDisplayString(__props.t.default_banner_title), 1),
                    createVNode("div", { class: "d-flex align-items-center gap-2 mt-1 flex-wrap" }, [
                      createVNode("span", {
                        class: "fw-semibold",
                        style: { "color": "#333" }
                      }, toDisplayString(__props.defaultGateway.name), 1),
                      createVNode("span", {
                        class: "badge text-uppercase",
                        style: { "background": "#fdd835", "color": "#5d4037", "font-size": ".7rem" }
                      }, toDisplayString(__props.defaultGateway.code), 1),
                      createVNode("span", { class: "text-muted small" }, toDisplayString(__props.t.default_banner_subtitle), 1)
                    ])
                  ]),
                  createVNode("div", { class: "flex-shrink-0" }, [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-sm btn-outline-secondary",
                      onClick: ($event) => defaultOpen.value = true
                    }, [
                      createVNode("i", { class: "ti ti-switch-horizontal me-1" }),
                      createTextVNode(toDisplayString(__props.t.default_banner_change), 1)
                    ], 8, ["onClick"])
                  ])
                ])) : (openBlock(), createBlock("div", {
                  key: 1,
                  class: "alert alert-danger d-flex align-items-center gap-3 py-3 mb-4",
                  style: { "border-radius": "10px" }
                }, [
                  createVNode("i", { class: "ti ti-alert-octagon fs-22 flex-shrink-0" }),
                  createVNode("div", { class: "flex-grow-1" }, [
                    createVNode("strong", null, toDisplayString(__props.t.no_default_title), 1),
                    createVNode("span", { class: "ms-1 small" }, toDisplayString(__props.t.no_default_subtitle), 1)
                  ]),
                  createVNode("button", {
                    type: "button",
                    class: "btn btn-sm btn-danger flex-shrink-0",
                    onClick: ($event) => defaultOpen.value = true
                  }, [
                    createVNode("i", { class: "ti ti-star me-1" }),
                    createTextVNode(toDisplayString(__props.t.no_default_action), 1)
                  ], 8, ["onClick"])
                ])),
                createVNode("div", { class: "row g-3 mb-4" }, [
                  createVNode("div", { class: "col-md-6" }, [
                    createVNode("div", { class: "card border-primary border-opacity-50 h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("div", { class: "d-flex gap-3 align-items-start" }, [
                          createVNode("div", {
                            class: "d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 bg-primary bg-opacity-10",
                            style: { "width": "38px", "height": "38px" }
                          }, [
                            createVNode("i", { class: "ti ti-building-store text-primary fs-18" })
                          ]),
                          createVNode("div", null, [
                            createVNode("p", { class: "fw-semibold mb-1 small" }, toDisplayString(__props.t.ctx_saas_title), 1),
                            createVNode("p", {
                              class: "text-muted mb-1",
                              style: { "font-size": ".8rem" },
                              innerHTML: __props.t.ctx_saas_desc
                            }, null, 8, ["innerHTML"]),
                            createVNode("span", {
                              class: "badge badge-soft-primary",
                              style: { "font-size": ".72rem" }
                            }, toDisplayString(__props.t.ctx_saas_badge), 1)
                          ])
                        ])
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-md-6" }, [
                    createVNode("div", { class: "card border-success border-opacity-50 h-100" }, [
                      createVNode("div", { class: "card-body py-3" }, [
                        createVNode("div", { class: "d-flex gap-3 align-items-start" }, [
                          createVNode("div", {
                            class: "d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 bg-success bg-opacity-10",
                            style: { "width": "38px", "height": "38px" }
                          }, [
                            createVNode("i", { class: "ti ti-building-hospital text-success fs-18" })
                          ]),
                          createVNode("div", null, [
                            createVNode("p", { class: "fw-semibold mb-1 small" }, toDisplayString(__props.t.ctx_tenant_title), 1),
                            createVNode("p", {
                              class: "text-muted mb-1",
                              style: { "font-size": ".8rem" },
                              innerHTML: __props.t.ctx_tenant_desc
                            }, null, 8, ["innerHTML"]),
                            createVNode("span", {
                              class: "badge badge-soft-success",
                              style: { "font-size": ".72rem" }
                            }, toDisplayString(__props.t.ctx_tenant_badge), 1)
                          ])
                        ])
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "row g-3" }, [
                  (openBlock(true), createBlock(Fragment, null, renderList(__props.gateways, (gateway) => {
                    return openBlock(), createBlock("div", {
                      key: gateway.id,
                      class: "col-md-6 col-xl-4"
                    }, [
                      createVNode(_sfc_main$1, {
                        gateway,
                        t: __props.t,
                        onOpenCredentials: openCredentials,
                        onOpenEntityAccess: openEntityAccess,
                        onOpenPriority: openPriority,
                        onOpenSetDefault: ($event) => defaultOpen.value = true
                      }, null, 8, ["gateway", "t", "onOpenSetDefault"])
                    ]);
                  }), 128)),
                  __props.gateways.length === 0 ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "col-12"
                  }, [
                    createVNode("div", { class: "text-center py-5 text-muted" }, [
                      createVNode("i", { class: "ti ti-credit-card-off fs-40 d-block mb-2 opacity-40" }),
                      createVNode("p", { class: "small mb-0" }, "Nenhum gateway cadastrado.")
                    ])
                  ])) : createCommentVNode("", true)
                ])
              ]),
              createVNode(_sfc_main$2, {
                open: defaultOpen.value,
                gateways: __props.gateways,
                "default-gateway": __props.defaultGateway,
                t: __props.t,
                onClose: ($event) => defaultOpen.value = false
              }, null, 8, ["open", "gateways", "default-gateway", "t", "onClose"]),
              createVNode(_sfc_main$3, {
                open: credOpen.value,
                gateway: credGateway.value,
                t: __props.t,
                onClose: closeCredentials
              }, null, 8, ["open", "gateway", "t"]),
              createVNode(_sfc_main$4, {
                open: eaOpen.value,
                gateway: eaGateway.value,
                t: __props.t,
                onClose: closeEntityAccess
              }, null, 8, ["open", "gateway", "t"]),
              createVNode(_sfc_main$5, {
                open: prioOpen.value,
                gateway: prioGateway.value,
                t: __props.t,
                onClose: closePriority
              }, null, 8, ["open", "gateway", "t"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Gateways/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
