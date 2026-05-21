import { ref, watch, mergeProps, withCtx, openBlock, createBlock, Fragment, createVNode, toDisplayString, createTextVNode, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "EntityIntegratorEquipmentDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    showUrl: { type: String, default: "" },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props) {
    const props = __props;
    const loading = ref(false);
    const item = ref(null);
    async function loadDetail(url) {
      loading.value = true;
      item.value = null;
      try {
        const res = await fetch(url, { headers: { Accept: "application/json" } });
        const json = await res.json();
        item.value = json.data;
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, (val) => {
      if (val && props.showUrl) loadDetail(props.showUrl);
      if (!val) item.value = null;
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 480,
        loading: loading.value,
        "loading-label": __props.t.detail_loading,
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b;
          if (_push2) {
            _push2(`<div data-v-a3f6acb9${_scopeId}><h5 class="mb-0 fw-semibold" data-v-a3f6acb9${_scopeId}><i class="ti ti-device-laptop me-2 text-info" data-v-a3f6acb9${_scopeId}></i> ${ssrInterpolate(((_a = item.value) == null ? void 0 : _a.name) ?? __props.t.detail_loading)}</h5>`);
            if (item.value) {
              _push2(`<code class="text-muted small" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(item.value.code)}</code>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
          } else {
            return [
              createVNode("div", null, [
                createVNode("h5", { class: "mb-0 fw-semibold" }, [
                  createVNode("i", { class: "ti ti-device-laptop me-2 text-info" }),
                  createTextVNode(" " + toDisplayString(((_b = item.value) == null ? void 0 : _b.name) ?? __props.t.detail_loading), 1)
                ]),
                item.value ? (openBlock(), createBlock("code", {
                  key: 0,
                  class: "text-muted small"
                }, toDisplayString(item.value.code), 1)) : createCommentVNode("", true)
              ])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (item.value) {
              _push2(`<!--[--><div class="mb-4" data-v-a3f6acb9${_scopeId}>`);
              if (item.value.deleted) {
                _push2(`<span class="badge bg-secondary" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(__props.t.status_deleted ?? "Removido")}</span>`);
              } else if (item.value.active) {
                _push2(`<span class="badge bg-success" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(__props.t.status_active ?? "Ativo")}</span>`);
              } else {
                _push2(`<span class="badge bg-danger" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(__props.t.status_inactive ?? "Inativo")}</span>`);
              }
              _push2(`</div><div class="detail-section" data-v-a3f6acb9${_scopeId}><div class="detail-section__title" data-v-a3f6acb9${_scopeId}><i class="ti ti-id-badge-2 me-1" data-v-a3f6acb9${_scopeId}></i> ${ssrInterpolate(__props.t.detail_section_identity ?? "Identidade")}</div><div class="detail-table" data-v-a3f6acb9${_scopeId}><div class="detail-row" data-v-a3f6acb9${_scopeId}><span class="detail-label" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(__props.t.col_code ?? "Código")}</span><span class="detail-value" data-v-a3f6acb9${_scopeId}><code data-v-a3f6acb9${_scopeId}>${ssrInterpolate(item.value.code)}</code></span></div><div class="detail-row" data-v-a3f6acb9${_scopeId}><span class="detail-label" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(__props.t.col_name ?? "Nome")}</span><span class="detail-value" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(item.value.name)}</span></div><div class="detail-row" data-v-a3f6acb9${_scopeId}><span class="detail-label" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(__props.t.col_serial ?? "Nº Série")}</span><span class="detail-value" data-v-a3f6acb9${_scopeId}><code data-v-a3f6acb9${_scopeId}>${ssrInterpolate(item.value.serial_number || "—")}</code></span></div></div></div><div class="detail-section" data-v-a3f6acb9${_scopeId}><div class="detail-section__title" data-v-a3f6acb9${_scopeId}><i class="ti ti-network me-1" data-v-a3f6acb9${_scopeId}></i> ${ssrInterpolate(__props.t.detail_section_network ?? "Rede")}</div><div class="detail-table" data-v-a3f6acb9${_scopeId}><div class="detail-row" data-v-a3f6acb9${_scopeId}><span class="detail-label" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(__props.t.col_ip ?? "IP")}</span><span class="detail-value" data-v-a3f6acb9${_scopeId}><code data-v-a3f6acb9${_scopeId}>${ssrInterpolate(item.value.ip || "—")}</code></span></div><div class="detail-row" data-v-a3f6acb9${_scopeId}><span class="detail-label" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(__props.t.col_mac ?? "MAC")}</span><span class="detail-value" data-v-a3f6acb9${_scopeId}><code data-v-a3f6acb9${_scopeId}>${ssrInterpolate(item.value.mac || "—")}</code></span></div></div></div><div class="detail-section" data-v-a3f6acb9${_scopeId}><div class="detail-section__title" data-v-a3f6acb9${_scopeId}><i class="ti ti-history me-1" data-v-a3f6acb9${_scopeId}></i> ${ssrInterpolate(__props.t.detail_section_audit ?? "Auditoria")}</div><div class="detail-table" data-v-a3f6acb9${_scopeId}><div class="detail-row" data-v-a3f6acb9${_scopeId}><span class="detail-label" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(__props.t.detail_registered_at ?? "Cadastrado em")}</span><span class="detail-value" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(item.value.created_at || "—")}</span></div>`);
              if (item.value.deleted_at) {
                _push2(`<div class="detail-row" data-v-a3f6acb9${_scopeId}><span class="detail-label" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(__props.t.detail_deleted_at ?? "Removido em")}</span><span class="detail-value" data-v-a3f6acb9${_scopeId}>${ssrInterpolate(item.value.deleted_at)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><!--]-->`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              item.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                createVNode("div", { class: "mb-4" }, [
                  item.value.deleted ? (openBlock(), createBlock("span", {
                    key: 0,
                    class: "badge bg-secondary"
                  }, toDisplayString(__props.t.status_deleted ?? "Removido"), 1)) : item.value.active ? (openBlock(), createBlock("span", {
                    key: 1,
                    class: "badge bg-success"
                  }, toDisplayString(__props.t.status_active ?? "Ativo"), 1)) : (openBlock(), createBlock("span", {
                    key: 2,
                    class: "badge bg-danger"
                  }, toDisplayString(__props.t.status_inactive ?? "Inativo"), 1))
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-id-badge-2 me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.detail_section_identity ?? "Identidade"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.col_code ?? "Código"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("code", null, toDisplayString(item.value.code), 1)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.col_name ?? "Nome"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(item.value.name), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.col_serial ?? "Nº Série"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("code", null, toDisplayString(item.value.serial_number || "—"), 1)
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-network me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.detail_section_network ?? "Rede"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.col_ip ?? "IP"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("code", null, toDisplayString(item.value.ip || "—"), 1)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.col_mac ?? "MAC"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("code", null, toDisplayString(item.value.mac || "—"), 1)
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-history me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.detail_section_audit ?? "Auditoria"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_registered_at ?? "Cadastrado em"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(item.value.created_at || "—"), 1)
                    ]),
                    item.value.deleted_at ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_deleted_at ?? "Removido em"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(item.value.deleted_at), 1)
                    ])) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/EntityIntegratorEquipments/EntityIntegratorEquipmentDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const EntityIntegratorEquipmentDetailDrawer = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-a3f6acb9"]]);
export {
  EntityIntegratorEquipmentDetailDrawer as default
};
