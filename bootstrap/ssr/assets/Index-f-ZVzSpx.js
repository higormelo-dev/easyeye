import { ref, mergeProps, withCtx, createVNode, openBlock, createBlock, createTextVNode, Fragment, renderList, toDisplayString, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderList, ssrInterpolate } from "vue/server-renderer";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import _sfc_main$2 from "./GatewayCredentialsModal-KFfB6hh_.js";
import "@inertiajs/vue3";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    gateways: { type: Array, default: () => [] }
  },
  setup(__props) {
    const credentialsOpen = ref(false);
    const selectedGateway = ref(null);
    function openCredentials(gateway) {
      selectedGateway.value = gateway;
      credentialsOpen.value = true;
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Gateways de pagamento",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: "Gateways de pagamento",
              subtitle: "Configure as credenciais da clínica para cada gateway disponibilizado pelo SaaS."
            }, null, _parent2, _scopeId));
            if (__props.gateways.length === 0) {
              _push2(`<div class="alert alert-info"${_scopeId}><i class="ti ti-info-circle me-1"${_scopeId}></i> Nenhum gateway de pagamento habilitado para sua clínica. Entre em contato com o suporte para liberar. </div>`);
            } else {
              _push2(`<div class="row g-3"${_scopeId}><!--[-->`);
              ssrRenderList(__props.gateways, (gw) => {
                _push2(`<div class="col-md-6 col-lg-4"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-body d-flex flex-column"${_scopeId}><div class="d-flex align-items-start justify-content-between mb-3"${_scopeId}><div${_scopeId}><h6 class="fw-semibold mb-1"${_scopeId}>${ssrInterpolate(gw.name)}</h6><code class="small text-muted"${_scopeId}>${ssrInterpolate(gw.code)}</code></div>`);
                if (gw.has_active_credential) {
                  _push2(`<span class="badge badge-soft-success rounded text-success border border-success fs-11"${_scopeId}><i class="ti ti-shield-check me-1"${_scopeId}></i>Configurado </span>`);
                } else {
                  _push2(`<span class="badge badge-soft-warning rounded fs-11"${_scopeId}><i class="ti ti-shield-off me-1"${_scopeId}></i>Pendente </span>`);
                }
                _push2(`</div><div class="small text-muted mb-3 flex-grow-1"${_scopeId}><div class="mb-1"${_scopeId}><i class="ti ti-key me-1"${_scopeId}></i> ${ssrInterpolate(gw.credentials_count)} credencial(is) registrada(s) </div></div><button type="button" class="btn btn-sm btn-outline-primary"${_scopeId}><i class="ti ti-settings me-1"${_scopeId}></i>Gerenciar credenciais </button></div></div></div>`);
              });
              _push2(`<!--]--></div>`);
            }
            _push2(ssrRenderComponent(_sfc_main$2, {
              open: credentialsOpen.value,
              gateway: selectedGateway.value,
              onClose: ($event) => credentialsOpen.value = false
            }, null, _parent2, _scopeId));
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: "Gateways de pagamento",
                  subtitle: "Configure as credenciais da clínica para cada gateway disponibilizado pelo SaaS."
                }),
                __props.gateways.length === 0 ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "alert alert-info"
                }, [
                  createVNode("i", { class: "ti ti-info-circle me-1" }),
                  createTextVNode(" Nenhum gateway de pagamento habilitado para sua clínica. Entre em contato com o suporte para liberar. ")
                ])) : (openBlock(), createBlock("div", {
                  key: 1,
                  class: "row g-3"
                }, [
                  (openBlock(true), createBlock(Fragment, null, renderList(__props.gateways, (gw) => {
                    return openBlock(), createBlock("div", {
                      key: gw.id,
                      class: "col-md-6 col-lg-4"
                    }, [
                      createVNode("div", { class: "card h-100" }, [
                        createVNode("div", { class: "card-body d-flex flex-column" }, [
                          createVNode("div", { class: "d-flex align-items-start justify-content-between mb-3" }, [
                            createVNode("div", null, [
                              createVNode("h6", { class: "fw-semibold mb-1" }, toDisplayString(gw.name), 1),
                              createVNode("code", { class: "small text-muted" }, toDisplayString(gw.code), 1)
                            ]),
                            gw.has_active_credential ? (openBlock(), createBlock("span", {
                              key: 0,
                              class: "badge badge-soft-success rounded text-success border border-success fs-11"
                            }, [
                              createVNode("i", { class: "ti ti-shield-check me-1" }),
                              createTextVNode("Configurado ")
                            ])) : (openBlock(), createBlock("span", {
                              key: 1,
                              class: "badge badge-soft-warning rounded fs-11"
                            }, [
                              createVNode("i", { class: "ti ti-shield-off me-1" }),
                              createTextVNode("Pendente ")
                            ]))
                          ]),
                          createVNode("div", { class: "small text-muted mb-3 flex-grow-1" }, [
                            createVNode("div", { class: "mb-1" }, [
                              createVNode("i", { class: "ti ti-key me-1" }),
                              createTextVNode(" " + toDisplayString(gw.credentials_count) + " credencial(is) registrada(s) ", 1)
                            ])
                          ]),
                          createVNode("button", {
                            type: "button",
                            class: "btn btn-sm btn-outline-primary",
                            onClick: ($event) => openCredentials(gw)
                          }, [
                            createVNode("i", { class: "ti ti-settings me-1" }),
                            createTextVNode("Gerenciar credenciais ")
                          ], 8, ["onClick"])
                        ])
                      ])
                    ]);
                  }), 128))
                ])),
                createVNode(_sfc_main$2, {
                  open: credentialsOpen.value,
                  gateway: selectedGateway.value,
                  onClose: ($event) => credentialsOpen.value = false
                }, null, 8, ["open", "gateway", "onClose"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Settings/Gateways/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
