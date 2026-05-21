import { unref, withCtx, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./PortalLayout-BsSgHTUU.js";
import { _ as _sfc_main$2 } from "./TablePagination-Dj1_H7YG.js";
const _sfc_main = {
  __name: "Commissions",
  __ssrInlineRender: true,
  props: {
    commissions: { type: Object, required: true },
    totals: { type: Object, required: true }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), { title: "Minhas Comissões — Portal de Parceiros" }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, null, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<h4 class="fw-bold mb-3"${_scopeId}><i class="ti ti-coin me-1 text-warning"${_scopeId}></i>Minhas Comissões </h4><div class="row g-3 mb-3"${_scopeId}><div class="col-md-6"${_scopeId}><div class="card shadow-sm border-0 h-100 border-start border-warning border-3"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Total pendente</small><div class="fw-bold fs-3 text-warning"${_scopeId}>${ssrInterpolate(__props.totals.pending_fmt)}</div></div></div></div><div class="col-md-6"${_scopeId}><div class="card shadow-sm border-0 h-100 border-start border-success border-3"${_scopeId}><div class="card-body py-3"${_scopeId}><small class="text-muted d-block"${_scopeId}>Total pago</small><div class="fw-bold fs-3 text-success"${_scopeId}>${ssrInterpolate(__props.totals.paid_fmt)}</div></div></div></div></div><div class="card shadow-sm"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>Criada em</th><th${_scopeId}>Clínica</th><th${_scopeId}>Período</th><th class="text-end"${_scopeId}>Taxa</th><th class="text-end"${_scopeId}>Valor</th><th class="text-center"${_scopeId}>Status</th><th${_scopeId}>Vencimento</th><th${_scopeId}>Pago em</th></tr></thead><tbody${_scopeId}>`);
            if (__props.commissions.data.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="8" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-receipt-off fs-1 d-block mb-2 opacity-25"${_scopeId}></i> Nenhuma comissão registrada ainda. </td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.commissions.data, (c) => {
              _push2(`<tr${_scopeId}><td class="small text-muted"${_scopeId}>${ssrInterpolate(c.created_at)}</td><td class="fw-medium"${_scopeId}>${ssrInterpolate(c.entity_name)}</td><td class="small text-muted"${_scopeId}>${ssrInterpolate(c.period || "—")}</td><td class="text-end small text-muted"${_scopeId}>${ssrInterpolate(c.rate ? `${c.rate}%` : "—")}</td><td class="text-end fw-bold"${_scopeId}>${ssrInterpolate(c.amount_fmt)}</td><td class="text-center"${_scopeId}><span class="${ssrRenderClass(`badge ${c.status_badge}`)}"${_scopeId}>${ssrInterpolate(c.status_label)}</span></td><td class="small text-muted"${_scopeId}>${ssrInterpolate(c.due_at || "—")}</td><td class="small"${_scopeId}>`);
              if (c.paid_at) {
                _push2(`<span class="text-success"${_scopeId}>${ssrInterpolate(c.paid_at)}</span>`);
              } else {
                _push2(`<span class="text-muted"${_scopeId}>—</span>`);
              }
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              data: __props.commissions,
              class: "mt-3"
            }, null, _parent2, _scopeId));
          } else {
            return [
              createVNode("h4", { class: "fw-bold mb-3" }, [
                createVNode("i", { class: "ti ti-coin me-1 text-warning" }),
                createTextVNode("Minhas Comissões ")
              ]),
              createVNode("div", { class: "row g-3 mb-3" }, [
                createVNode("div", { class: "col-md-6" }, [
                  createVNode("div", { class: "card shadow-sm border-0 h-100 border-start border-warning border-3" }, [
                    createVNode("div", { class: "card-body py-3" }, [
                      createVNode("small", { class: "text-muted d-block" }, "Total pendente"),
                      createVNode("div", { class: "fw-bold fs-3 text-warning" }, toDisplayString(__props.totals.pending_fmt), 1)
                    ])
                  ])
                ]),
                createVNode("div", { class: "col-md-6" }, [
                  createVNode("div", { class: "card shadow-sm border-0 h-100 border-start border-success border-3" }, [
                    createVNode("div", { class: "card-body py-3" }, [
                      createVNode("small", { class: "text-muted d-block" }, "Total pago"),
                      createVNode("div", { class: "fw-bold fs-3 text-success" }, toDisplayString(__props.totals.paid_fmt), 1)
                    ])
                  ])
                ])
              ]),
              createVNode("div", { class: "card shadow-sm" }, [
                createVNode("div", { class: "table-responsive" }, [
                  createVNode("table", { class: "table table-hover align-middle mb-0" }, [
                    createVNode("thead", { class: "table-light" }, [
                      createVNode("tr", null, [
                        createVNode("th", null, "Criada em"),
                        createVNode("th", null, "Clínica"),
                        createVNode("th", null, "Período"),
                        createVNode("th", { class: "text-end" }, "Taxa"),
                        createVNode("th", { class: "text-end" }, "Valor"),
                        createVNode("th", { class: "text-center" }, "Status"),
                        createVNode("th", null, "Vencimento"),
                        createVNode("th", null, "Pago em")
                      ])
                    ]),
                    createVNode("tbody", null, [
                      __props.commissions.data.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                        createVNode("td", {
                          colspan: "8",
                          class: "text-center text-muted py-5"
                        }, [
                          createVNode("i", { class: "ti ti-receipt-off fs-1 d-block mb-2 opacity-25" }),
                          createTextVNode(" Nenhuma comissão registrada ainda. ")
                        ])
                      ])) : createCommentVNode("", true),
                      (openBlock(true), createBlock(Fragment, null, renderList(__props.commissions.data, (c) => {
                        return openBlock(), createBlock("tr", {
                          key: c.id
                        }, [
                          createVNode("td", { class: "small text-muted" }, toDisplayString(c.created_at), 1),
                          createVNode("td", { class: "fw-medium" }, toDisplayString(c.entity_name), 1),
                          createVNode("td", { class: "small text-muted" }, toDisplayString(c.period || "—"), 1),
                          createVNode("td", { class: "text-end small text-muted" }, toDisplayString(c.rate ? `${c.rate}%` : "—"), 1),
                          createVNode("td", { class: "text-end fw-bold" }, toDisplayString(c.amount_fmt), 1),
                          createVNode("td", { class: "text-center" }, [
                            createVNode("span", {
                              class: `badge ${c.status_badge}`
                            }, toDisplayString(c.status_label), 3)
                          ]),
                          createVNode("td", { class: "small text-muted" }, toDisplayString(c.due_at || "—"), 1),
                          createVNode("td", { class: "small" }, [
                            c.paid_at ? (openBlock(), createBlock("span", {
                              key: 0,
                              class: "text-success"
                            }, toDisplayString(c.paid_at), 1)) : (openBlock(), createBlock("span", {
                              key: 1,
                              class: "text-muted"
                            }, "—"))
                          ])
                        ]);
                      }), 128))
                    ])
                  ])
                ])
              ]),
              createVNode(_sfc_main$2, {
                data: __props.commissions,
                class: "mt-3"
              }, null, 8, ["data"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Portal/Commissions.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
