import { ref, watch, mergeProps, withCtx, openBlock, createBlock, Fragment, createVNode, toDisplayString, createTextVNode, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "EntityIntegratorDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    showUrl: { type: String, default: "" },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "edit"],
  setup(__props, { emit: __emit }) {
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
      if (val && props.showUrl) {
        loadDetail(props.showUrl);
      }
      if (!val) {
        item.value = null;
      }
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
            _push2(`<div data-v-68b350b9${_scopeId}><h5 class="mb-0 fw-semibold" data-v-68b350b9${_scopeId}><i class="ti ti-plug me-2 text-info" data-v-68b350b9${_scopeId}></i> ${ssrInterpolate(((_a = item.value) == null ? void 0 : _a.name) ?? __props.t.detail_loading)}</h5>`);
            if (item.value) {
              _push2(`<code class="text-muted small" data-v-68b350b9${_scopeId}>${ssrInterpolate(item.value.code)}</code>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (item.value && !item.value.deleted) {
              _push2(`<button class="btn btn-sm btn-outline-primary ms-2" data-v-68b350b9${_scopeId}><i class="ti ti-edit me-1" data-v-68b350b9${_scopeId}></i> ${ssrInterpolate(__props.t.detail_btn_edit ?? "Editar")}</button>`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              createVNode("div", null, [
                createVNode("h5", { class: "mb-0 fw-semibold" }, [
                  createVNode("i", { class: "ti ti-plug me-2 text-info" }),
                  createTextVNode(" " + toDisplayString(((_b = item.value) == null ? void 0 : _b.name) ?? __props.t.detail_loading), 1)
                ]),
                item.value ? (openBlock(), createBlock("code", {
                  key: 0,
                  class: "text-muted small"
                }, toDisplayString(item.value.code), 1)) : createCommentVNode("", true)
              ]),
              item.value && !item.value.deleted ? (openBlock(), createBlock("button", {
                key: 0,
                class: "btn btn-sm btn-outline-primary ms-2",
                onClick: ($event) => _ctx.$emit("edit", item.value)
              }, [
                createVNode("i", { class: "ti ti-edit me-1" }),
                createTextVNode(" " + toDisplayString(__props.t.detail_btn_edit ?? "Editar"), 1)
              ], 8, ["onClick"])) : createCommentVNode("", true)
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (item.value) {
              _push2(`<!--[--><div class="mb-4" data-v-68b350b9${_scopeId}>`);
              if (item.value.deleted) {
                _push2(`<span class="badge bg-secondary" data-v-68b350b9${_scopeId}>${ssrInterpolate(__props.t.status_deleted ?? "Removido")}</span>`);
              } else if (item.value.active) {
                _push2(`<span class="badge bg-success" data-v-68b350b9${_scopeId}>${ssrInterpolate(__props.t.status_active ?? "Ativo")}</span>`);
              } else {
                _push2(`<span class="badge bg-danger" data-v-68b350b9${_scopeId}>${ssrInterpolate(__props.t.status_inactive ?? "Inativo")}</span>`);
              }
              _push2(`</div><div class="detail-section" data-v-68b350b9${_scopeId}><div class="detail-section__title" data-v-68b350b9${_scopeId}><i class="ti ti-id-badge-2 me-1" data-v-68b350b9${_scopeId}></i> ${ssrInterpolate(__props.t.detail_section_identity ?? "Identidade")}</div><div class="detail-table" data-v-68b350b9${_scopeId}><div class="detail-row" data-v-68b350b9${_scopeId}><span class="detail-label" data-v-68b350b9${_scopeId}>${ssrInterpolate(__props.t.col_code ?? "Código")}</span><span class="detail-value" data-v-68b350b9${_scopeId}><code data-v-68b350b9${_scopeId}>${ssrInterpolate(item.value.code)}</code></span></div><div class="detail-row" data-v-68b350b9${_scopeId}><span class="detail-label" data-v-68b350b9${_scopeId}>${ssrInterpolate(__props.t.col_name ?? "Nome")}</span><span class="detail-value" data-v-68b350b9${_scopeId}>${ssrInterpolate(item.value.name)}</span></div><div class="detail-row" data-v-68b350b9${_scopeId}><span class="detail-label" data-v-68b350b9${_scopeId}>${ssrInterpolate(__props.t.detail_registered_at ?? "Cadastrado em")}</span><span class="detail-value" data-v-68b350b9${_scopeId}>${ssrInterpolate(item.value.created_at || "—")}</span></div></div></div><div class="detail-section" data-v-68b350b9${_scopeId}><div class="detail-section__title" data-v-68b350b9${_scopeId}><i class="ti ti-network me-1" data-v-68b350b9${_scopeId}></i> ${ssrInterpolate(__props.t.detail_section_network ?? "Rede")}</div><div class="detail-table" data-v-68b350b9${_scopeId}><div class="detail-row" data-v-68b350b9${_scopeId}><span class="detail-label" data-v-68b350b9${_scopeId}>${ssrInterpolate(__props.t.col_ip ?? "IP")}</span><span class="detail-value" data-v-68b350b9${_scopeId}><code data-v-68b350b9${_scopeId}>${ssrInterpolate(item.value.ip || "—")}</code></span></div><div class="detail-row" data-v-68b350b9${_scopeId}><span class="detail-label" data-v-68b350b9${_scopeId}>${ssrInterpolate(__props.t.col_mac ?? "MAC")}</span><span class="detail-value" data-v-68b350b9${_scopeId}><code data-v-68b350b9${_scopeId}>${ssrInterpolate(item.value.mac || "—")}</code></span></div></div></div><div class="detail-section" data-v-68b350b9${_scopeId}><div class="detail-section__title" data-v-68b350b9${_scopeId}><i class="ti ti-shield-lock me-1" data-v-68b350b9${_scopeId}></i> ${ssrInterpolate(__props.t.detail_section_security ?? "Segurança")}</div><div class="detail-table" data-v-68b350b9${_scopeId}><div class="detail-row" data-v-68b350b9${_scopeId}><span class="detail-label" data-v-68b350b9${_scopeId}>${ssrInterpolate(__props.t.detail_active_tokens ?? "Tokens ativos")}</span><span class="detail-value" data-v-68b350b9${_scopeId}><span class="badge bg-info-subtle text-info-emphasis fw-medium" data-v-68b350b9${_scopeId}>${ssrInterpolate(item.value.active_tokens ?? 0)}</span></span></div></div><small class="text-muted d-block mt-1" data-v-68b350b9${_scopeId}>${ssrInterpolate(__props.t.detail_active_tokens_hint)}</small></div><div class="detail-section" data-v-68b350b9${_scopeId}><div class="detail-section__title" data-v-68b350b9${_scopeId}><i class="ti ti-device-laptop me-1" data-v-68b350b9${_scopeId}></i> ${ssrInterpolate(__props.t.detail_section_equipments ?? "Equipamentos vinculados")}</div><div class="d-flex align-items-center justify-content-between" data-v-68b350b9${_scopeId}><span class="fs-5 fw-semibold text-info" data-v-68b350b9${_scopeId}>${ssrInterpolate(item.value.equipments_count ?? 0)}</span>`);
              if (item.value.equipments_url && !item.value.deleted) {
                _push2(`<a${ssrRenderAttr("href", item.value.equipments_url)} class="btn btn-sm btn-outline-info" data-v-68b350b9${_scopeId}><i class="ti ti-external-link me-1" data-v-68b350b9${_scopeId}></i> ${ssrInterpolate(__props.t.detail_open_equipments ?? "Abrir equipamentos")}</a>`);
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
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_registered_at ?? "Cadastrado em"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(item.value.created_at || "—"), 1)
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
                    createVNode("i", { class: "ti ti-shield-lock me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.detail_section_security ?? "Segurança"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_active_tokens ?? "Tokens ativos"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("span", { class: "badge bg-info-subtle text-info-emphasis fw-medium" }, toDisplayString(item.value.active_tokens ?? 0), 1)
                      ])
                    ])
                  ]),
                  createVNode("small", { class: "text-muted d-block mt-1" }, toDisplayString(__props.t.detail_active_tokens_hint), 1)
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-device-laptop me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.detail_section_equipments ?? "Equipamentos vinculados"), 1)
                  ]),
                  createVNode("div", { class: "d-flex align-items-center justify-content-between" }, [
                    createVNode("span", { class: "fs-5 fw-semibold text-info" }, toDisplayString(item.value.equipments_count ?? 0), 1),
                    item.value.equipments_url && !item.value.deleted ? (openBlock(), createBlock("a", {
                      key: 0,
                      href: item.value.equipments_url,
                      class: "btn btn-sm btn-outline-info"
                    }, [
                      createVNode("i", { class: "ti ti-external-link me-1" }),
                      createTextVNode(" " + toDisplayString(__props.t.detail_open_equipments ?? "Abrir equipamentos"), 1)
                    ], 8, ["href"])) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/EntityIntegrators/EntityIntegratorDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const EntityIntegratorDetailDrawer = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-68b350b9"]]);
export {
  EntityIntegratorDetailDrawer as default
};
