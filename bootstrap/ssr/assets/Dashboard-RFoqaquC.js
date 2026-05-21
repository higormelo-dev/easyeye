import { unref, withCtx, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./PortalLayout-BsSgHTUU.js";
const _sfc_main = {
  __name: "Dashboard",
  __ssrInlineRender: true,
  props: {
    metrics: { type: Object, required: true },
    recentLeads: { type: Array, default: () => [] },
    recentCommissions: { type: Array, default: () => [] }
  },
  setup(__props) {
    function brl(v) {
      return "R$ " + Number(v ?? 0).toLocaleString("pt-BR", { minimumFractionDigits: 2 });
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), { title: "Dashboard — Portal de Parceiros" }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, null, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<h4 class="fw-bold mb-3"${_scopeId}><i class="ti ti-dashboard me-1 text-primary"${_scopeId}></i>Dashboard </h4><div class="row g-3 mb-4"${_scopeId}><div class="col-6 col-md-3"${_scopeId}><div class="card shadow-sm border-0 h-100 border-start border-primary border-3"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Leads cadastrados</small><div class="fw-bold fs-4 text-primary"${_scopeId}>${ssrInterpolate(__props.metrics.total_leads ?? 0)}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card shadow-sm border-0 h-100 border-start border-success border-3"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Convertidos</small><div class="fw-bold fs-4 text-success"${_scopeId}>${ssrInterpolate(__props.metrics.converted_leads ?? 0)}</div>`);
            if (__props.metrics.conversion_rate) {
              _push2(`<small class="text-muted"${_scopeId}>${ssrInterpolate(__props.metrics.conversion_rate)}% de conversão </small>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card shadow-sm border-0 h-100 border-start border-warning border-3"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Comissões pendentes</small><div class="fw-bold fs-4 text-warning"${_scopeId}>${ssrInterpolate(brl(__props.metrics.pending_commissions))}</div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card shadow-sm border-0 h-100 border-start border-info border-3"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Comissões pagas</small><div class="fw-bold fs-4 text-info"${_scopeId}>${ssrInterpolate(brl(__props.metrics.paid_commissions))}</div></div></div></div></div><div class="row g-3"${_scopeId}><div class="col-md-6"${_scopeId}><div class="card shadow-sm h-100"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-users me-1 text-primary"${_scopeId}></i>Leads recentes </h6></div><div class="card-body p-0"${_scopeId}>`);
            if (__props.recentLeads.length === 0) {
              _push2(`<div class="text-center py-4 text-muted small"${_scopeId}> Nenhum lead cadastrado ainda. </div>`);
            } else {
              _push2(`<table class="table table-sm table-hover mb-0"${_scopeId}><tbody${_scopeId}><!--[-->`);
              ssrRenderList(__props.recentLeads, (l) => {
                _push2(`<tr${_scopeId}><td class="fw-medium"${_scopeId}>${ssrInterpolate(l.name)}</td><td class="text-muted small"${_scopeId}>${ssrInterpolate(l.city_state || l.email)}</td><td class="text-end"${_scopeId}><span class="${ssrRenderClass(`badge ${l.status_badge}`)}"${_scopeId}>${ssrInterpolate(l.status_label)}</span></td></tr>`);
              });
              _push2(`<!--]--></tbody></table>`);
            }
            _push2(`</div></div></div><div class="col-md-6"${_scopeId}><div class="card shadow-sm h-100"${_scopeId}><div class="card-header bg-transparent"${_scopeId}><h6 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-coin me-1 text-warning"${_scopeId}></i>Comissões recentes </h6></div><div class="card-body p-0"${_scopeId}>`);
            if (__props.recentCommissions.length === 0) {
              _push2(`<div class="text-center py-4 text-muted small"${_scopeId}> Nenhuma comissão ainda. </div>`);
            } else {
              _push2(`<table class="table table-sm table-hover mb-0"${_scopeId}><tbody${_scopeId}><!--[-->`);
              ssrRenderList(__props.recentCommissions, (c) => {
                _push2(`<tr${_scopeId}><td class="fw-medium"${_scopeId}>${ssrInterpolate(c.entity_name)}</td><td class="text-muted small"${_scopeId}>${ssrInterpolate(c.created_at)}</td><td class="text-end"${_scopeId}><strong${_scopeId}>${ssrInterpolate(c.amount_fmt)}</strong><span class="${ssrRenderClass(`badge ${c.status_badge} ms-1`)}"${_scopeId}>${ssrInterpolate(c.status_label)}</span></td></tr>`);
              });
              _push2(`<!--]--></tbody></table>`);
            }
            _push2(`</div></div></div></div>`);
          } else {
            return [
              createVNode("h4", { class: "fw-bold mb-3" }, [
                createVNode("i", { class: "ti ti-dashboard me-1 text-primary" }),
                createTextVNode("Dashboard ")
              ]),
              createVNode("div", { class: "row g-3 mb-4" }, [
                createVNode("div", { class: "col-6 col-md-3" }, [
                  createVNode("div", { class: "card shadow-sm border-0 h-100 border-start border-primary border-3" }, [
                    createVNode("div", { class: "card-body py-3" }, [
                      createVNode("small", { class: "text-muted d-block" }, "Leads cadastrados"),
                      createVNode("div", { class: "fw-bold fs-4 text-primary" }, toDisplayString(__props.metrics.total_leads ?? 0), 1)
                    ])
                  ])
                ]),
                createVNode("div", { class: "col-6 col-md-3" }, [
                  createVNode("div", { class: "card shadow-sm border-0 h-100 border-start border-success border-3" }, [
                    createVNode("div", { class: "card-body py-3" }, [
                      createVNode("small", { class: "text-muted d-block" }, "Convertidos"),
                      createVNode("div", { class: "fw-bold fs-4 text-success" }, toDisplayString(__props.metrics.converted_leads ?? 0), 1),
                      __props.metrics.conversion_rate ? (openBlock(), createBlock("small", {
                        key: 0,
                        class: "text-muted"
                      }, toDisplayString(__props.metrics.conversion_rate) + "% de conversão ", 1)) : createCommentVNode("", true)
                    ])
                  ])
                ]),
                createVNode("div", { class: "col-6 col-md-3" }, [
                  createVNode("div", { class: "card shadow-sm border-0 h-100 border-start border-warning border-3" }, [
                    createVNode("div", { class: "card-body py-3" }, [
                      createVNode("small", { class: "text-muted d-block" }, "Comissões pendentes"),
                      createVNode("div", { class: "fw-bold fs-4 text-warning" }, toDisplayString(brl(__props.metrics.pending_commissions)), 1)
                    ])
                  ])
                ]),
                createVNode("div", { class: "col-6 col-md-3" }, [
                  createVNode("div", { class: "card shadow-sm border-0 h-100 border-start border-info border-3" }, [
                    createVNode("div", { class: "card-body py-3" }, [
                      createVNode("small", { class: "text-muted d-block" }, "Comissões pagas"),
                      createVNode("div", { class: "fw-bold fs-4 text-info" }, toDisplayString(brl(__props.metrics.paid_commissions)), 1)
                    ])
                  ])
                ])
              ]),
              createVNode("div", { class: "row g-3" }, [
                createVNode("div", { class: "col-md-6" }, [
                  createVNode("div", { class: "card shadow-sm h-100" }, [
                    createVNode("div", { class: "card-header bg-transparent" }, [
                      createVNode("h6", { class: "mb-0 fw-semibold" }, [
                        createVNode("i", { class: "ti ti-users me-1 text-primary" }),
                        createTextVNode("Leads recentes ")
                      ])
                    ]),
                    createVNode("div", { class: "card-body p-0" }, [
                      __props.recentLeads.length === 0 ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "text-center py-4 text-muted small"
                      }, " Nenhum lead cadastrado ainda. ")) : (openBlock(), createBlock("table", {
                        key: 1,
                        class: "table table-sm table-hover mb-0"
                      }, [
                        createVNode("tbody", null, [
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.recentLeads, (l) => {
                            return openBlock(), createBlock("tr", {
                              key: l.id
                            }, [
                              createVNode("td", { class: "fw-medium" }, toDisplayString(l.name), 1),
                              createVNode("td", { class: "text-muted small" }, toDisplayString(l.city_state || l.email), 1),
                              createVNode("td", { class: "text-end" }, [
                                createVNode("span", {
                                  class: `badge ${l.status_badge}`
                                }, toDisplayString(l.status_label), 3)
                              ])
                            ]);
                          }), 128))
                        ])
                      ]))
                    ])
                  ])
                ]),
                createVNode("div", { class: "col-md-6" }, [
                  createVNode("div", { class: "card shadow-sm h-100" }, [
                    createVNode("div", { class: "card-header bg-transparent" }, [
                      createVNode("h6", { class: "mb-0 fw-semibold" }, [
                        createVNode("i", { class: "ti ti-coin me-1 text-warning" }),
                        createTextVNode("Comissões recentes ")
                      ])
                    ]),
                    createVNode("div", { class: "card-body p-0" }, [
                      __props.recentCommissions.length === 0 ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "text-center py-4 text-muted small"
                      }, " Nenhuma comissão ainda. ")) : (openBlock(), createBlock("table", {
                        key: 1,
                        class: "table table-sm table-hover mb-0"
                      }, [
                        createVNode("tbody", null, [
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.recentCommissions, (c) => {
                            return openBlock(), createBlock("tr", {
                              key: c.id
                            }, [
                              createVNode("td", { class: "fw-medium" }, toDisplayString(c.entity_name), 1),
                              createVNode("td", { class: "text-muted small" }, toDisplayString(c.created_at), 1),
                              createVNode("td", { class: "text-end" }, [
                                createVNode("strong", null, toDisplayString(c.amount_fmt), 1),
                                createVNode("span", {
                                  class: `badge ${c.status_badge} ms-1`
                                }, toDisplayString(c.status_label), 3)
                              ])
                            ]);
                          }), 128))
                        ])
                      ]))
                    ])
                  ])
                ])
              ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Portal/Dashboard.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
