import { mergeProps, withCtx, openBlock, createBlock, Fragment, createVNode, toDisplayString, createTextVNode, createCommentVNode, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderStyle } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "CatalogDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    item: { type: Object, default: null },
    columns: { type: Array, required: true },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "edit"],
  setup(__props) {
    const props = __props;
    function display(item, col) {
      const v = item == null ? void 0 : item[col.key];
      if (v === null || v === void 0 || v === "") return "—";
      if (col.type === "yesno") return v ? props.t.yes ?? "Sim" : props.t.no ?? "Não";
      if (col.type === "numeric") return Number(v).toLocaleString("pt-BR");
      return v;
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 440,
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d;
          if (_push2) {
            _push2(`<div data-v-96ac9d2d${_scopeId}><h5 class="mb-0 fw-semibold" data-v-96ac9d2d${_scopeId}><i class="ti ti-database me-2 text-info" data-v-96ac9d2d${_scopeId}></i> ${ssrInterpolate(((_a = __props.item) == null ? void 0 : _a.name) ?? "—")}</h5>`);
            if ((_b = __props.item) == null ? void 0 : _b.code) {
              _push2(`<code class="text-muted small" data-v-96ac9d2d${_scopeId}>${ssrInterpolate(__props.item.code)}</code>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (__props.item && !__props.item.deleted) {
              _push2(`<button class="btn btn-sm btn-outline-primary ms-2" data-v-96ac9d2d${_scopeId}><i class="ti ti-edit me-1" data-v-96ac9d2d${_scopeId}></i> ${ssrInterpolate(__props.t.btn_edit ?? "Editar")}</button>`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              createVNode("div", null, [
                createVNode("h5", { class: "mb-0 fw-semibold" }, [
                  createVNode("i", { class: "ti ti-database me-2 text-info" }),
                  createTextVNode(" " + toDisplayString(((_c = __props.item) == null ? void 0 : _c.name) ?? "—"), 1)
                ]),
                ((_d = __props.item) == null ? void 0 : _d.code) ? (openBlock(), createBlock("code", {
                  key: 0,
                  class: "text-muted small"
                }, toDisplayString(__props.item.code), 1)) : createCommentVNode("", true)
              ]),
              __props.item && !__props.item.deleted ? (openBlock(), createBlock("button", {
                key: 0,
                class: "btn btn-sm btn-outline-primary ms-2",
                onClick: ($event) => _ctx.$emit("edit", __props.item)
              }, [
                createVNode("i", { class: "ti ti-edit me-1" }),
                createTextVNode(" " + toDisplayString(__props.t.btn_edit ?? "Editar"), 1)
              ], 8, ["onClick"])) : createCommentVNode("", true)
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (__props.item) {
              _push2(`<!--[--><div class="mb-4" data-v-96ac9d2d${_scopeId}>`);
              if (__props.item.deleted) {
                _push2(`<span class="badge bg-secondary" data-v-96ac9d2d${_scopeId}>${ssrInterpolate(__props.t.status_deleted ?? "Removido")}</span>`);
              } else if (__props.item.active) {
                _push2(`<span class="badge bg-success" data-v-96ac9d2d${_scopeId}>${ssrInterpolate(__props.t.status_active ?? "Ativo")}</span>`);
              } else {
                _push2(`<span class="badge bg-danger" data-v-96ac9d2d${_scopeId}>${ssrInterpolate(__props.t.status_inactive ?? "Inativo")}</span>`);
              }
              if (__props.item.is_global) {
                _push2(`<span class="badge bg-info ms-1" data-v-96ac9d2d${_scopeId}><i class="ti ti-star-filled me-1" data-v-96ac9d2d${_scopeId}></i>${ssrInterpolate(__props.t.status_global ?? "Padrão do sistema")}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><div class="detail-section" data-v-96ac9d2d${_scopeId}><div class="detail-table" data-v-96ac9d2d${_scopeId}><!--[-->`);
              ssrRenderList(__props.columns, (col) => {
                _push2(`<div class="detail-row" data-v-96ac9d2d${_scopeId}><span class="detail-label" data-v-96ac9d2d${_scopeId}>${ssrInterpolate(col.label)}</span><span class="detail-value" data-v-96ac9d2d${_scopeId}>`);
                if (col.type === "code") {
                  _push2(`<code data-v-96ac9d2d${_scopeId}>${ssrInterpolate(display(__props.item, col))}</code>`);
                } else if (col.type === "color") {
                  _push2(`<span class="d-inline-flex align-items-center gap-1" data-v-96ac9d2d${_scopeId}><span class="rounded-circle border" style="${ssrRenderStyle(`background:${__props.item[col.key] ?? "#ccc"}; width:18px; height:18px; display:inline-block;`)}" data-v-96ac9d2d${_scopeId}></span><code data-v-96ac9d2d${_scopeId}>${ssrInterpolate(display(__props.item, col))}</code></span>`);
                } else {
                  _push2(`<!--[-->${ssrInterpolate(display(__props.item, col))}<!--]-->`);
                }
                _push2(`</span></div>`);
              });
              _push2(`<!--]--><div class="detail-row" data-v-96ac9d2d${_scopeId}><span class="detail-label" data-v-96ac9d2d${_scopeId}>${ssrInterpolate(__props.t.detail_registered_at ?? "Cadastrado em")}</span><span class="detail-value" data-v-96ac9d2d${_scopeId}>${ssrInterpolate(__props.item.created_at || "—")}</span></div><div class="detail-row" data-v-96ac9d2d${_scopeId}><span class="detail-label" data-v-96ac9d2d${_scopeId}>${ssrInterpolate(__props.t.detail_origin ?? "Origem")}</span><span class="detail-value" data-v-96ac9d2d${_scopeId}>${ssrInterpolate(__props.item.is_global ? __props.t.detail_origin_global ?? "Padrão do sistema" : __props.t.detail_origin_clinic ?? "Cadastrado pela clínica")}</span></div></div></div><!--]-->`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              __props.item ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                createVNode("div", { class: "mb-4" }, [
                  __props.item.deleted ? (openBlock(), createBlock("span", {
                    key: 0,
                    class: "badge bg-secondary"
                  }, toDisplayString(__props.t.status_deleted ?? "Removido"), 1)) : __props.item.active ? (openBlock(), createBlock("span", {
                    key: 1,
                    class: "badge bg-success"
                  }, toDisplayString(__props.t.status_active ?? "Ativo"), 1)) : (openBlock(), createBlock("span", {
                    key: 2,
                    class: "badge bg-danger"
                  }, toDisplayString(__props.t.status_inactive ?? "Inativo"), 1)),
                  __props.item.is_global ? (openBlock(), createBlock("span", {
                    key: 3,
                    class: "badge bg-info ms-1"
                  }, [
                    createVNode("i", { class: "ti ti-star-filled me-1" }),
                    createTextVNode(toDisplayString(__props.t.status_global ?? "Padrão do sistema"), 1)
                  ])) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-table" }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.columns, (col) => {
                      return openBlock(), createBlock("div", {
                        key: col.key,
                        class: "detail-row"
                      }, [
                        createVNode("span", { class: "detail-label" }, toDisplayString(col.label), 1),
                        createVNode("span", { class: "detail-value" }, [
                          col.type === "code" ? (openBlock(), createBlock("code", { key: 0 }, toDisplayString(display(__props.item, col)), 1)) : col.type === "color" ? (openBlock(), createBlock("span", {
                            key: 1,
                            class: "d-inline-flex align-items-center gap-1"
                          }, [
                            createVNode("span", {
                              class: "rounded-circle border",
                              style: `background:${__props.item[col.key] ?? "#ccc"}; width:18px; height:18px; display:inline-block;`
                            }, null, 4),
                            createVNode("code", null, toDisplayString(display(__props.item, col)), 1)
                          ])) : (openBlock(), createBlock(Fragment, { key: 2 }, [
                            createTextVNode(toDisplayString(display(__props.item, col)), 1)
                          ], 64))
                        ])
                      ]);
                    }), 128)),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_registered_at ?? "Cadastrado em"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(__props.item.created_at || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_origin ?? "Origem"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(__props.item.is_global ? __props.t.detail_origin_global ?? "Padrão do sistema" : __props.t.detail_origin_clinic ?? "Cadastrado pela clínica"), 1)
                    ])
                  ])
                ])
              ], 64)) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Settings/Catalog/CatalogDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const CatalogDetailDrawer = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-96ac9d2d"]]);
export {
  CatalogDetailDrawer as default
};
