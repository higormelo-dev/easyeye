import { ref, watch, mergeProps, withCtx, unref, openBlock, createBlock, Fragment, createVNode, toDisplayString, createTextVNode, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
function useTrans(tOrGetter) {
  function resolve() {
    return typeof tOrGetter === "function" ? tOrGetter() : tOrGetter;
  }
  function tx(key, params = {}) {
    const t = resolve();
    let str = t[key] ?? key;
    for (const [k, v] of Object.entries(params)) {
      str = str.replaceAll(`:${k}`, v);
    }
    return str;
  }
  return { tx };
}
const _sfc_main = {
  __name: "EntityDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    entityId: { type: String, default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "edit"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const loading = ref(false);
    const entity = ref(null);
    const { tx } = useTrans(() => props.t);
    async function loadDetail(id) {
      loading.value = true;
      entity.value = null;
      try {
        const res = await fetch(route("manager.entities.show", id));
        const json = await res.json();
        entity.value = json.data;
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, (val) => {
      if (val && props.entityId) loadDetail(props.entityId);
      if (!val) entity.value = null;
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
            _push2(`<div data-v-f904fd81${_scopeId}><h5 class="mb-0 fw-semibold" data-v-f904fd81${_scopeId}><i class="ti ti-building me-2 text-primary" data-v-f904fd81${_scopeId}></i> ${ssrInterpolate(((_a = entity.value) == null ? void 0 : _a.name) ?? __props.t.detail_loading)}</h5>`);
            if (entity.value) {
              _push2(`<code class="text-muted small" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.code)}</code>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (entity.value) {
              _push2(`<button class="btn btn-sm btn-outline-primary ms-2" data-v-f904fd81${_scopeId}><i class="ti ti-edit me-1" data-v-f904fd81${_scopeId}></i> ${ssrInterpolate(__props.t.detail_btn_edit)}</button>`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              createVNode("div", null, [
                createVNode("h5", { class: "mb-0 fw-semibold" }, [
                  createVNode("i", { class: "ti ti-building me-2 text-primary" }),
                  createTextVNode(" " + toDisplayString(((_b = entity.value) == null ? void 0 : _b.name) ?? __props.t.detail_loading), 1)
                ]),
                entity.value ? (openBlock(), createBlock("code", {
                  key: 0,
                  class: "text-muted small"
                }, toDisplayString(entity.value.code), 1)) : createCommentVNode("", true)
              ]),
              entity.value ? (openBlock(), createBlock("button", {
                key: 0,
                class: "btn btn-sm btn-outline-primary ms-2",
                onClick: ($event) => _ctx.$emit("edit", entity.value.id)
              }, [
                createVNode("i", { class: "ti ti-edit me-1" }),
                createTextVNode(" " + toDisplayString(__props.t.detail_btn_edit), 1)
              ], 8, ["onClick"])) : createCommentVNode("", true)
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (entity.value) {
              _push2(`<!--[--><div class="mb-4" data-v-f904fd81${_scopeId}>`);
              if (entity.value.deleted_at) {
                _push2(`<span class="badge bg-danger" data-v-f904fd81${_scopeId}>${ssrInterpolate(unref(tx)("detail_deleted_at", { date: entity.value.deleted_at }))}</span>`);
              } else if (entity.value.active) {
                _push2(`<span class="badge bg-success" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.status_active)}</span>`);
              } else {
                _push2(`<span class="badge bg-secondary" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.status_inactive)}</span>`);
              }
              _push2(`</div><div class="detail-section" data-v-f904fd81${_scopeId}><div class="detail-section__title" data-v-f904fd81${_scopeId}><i class="ti ti-building me-1" data-v-f904fd81${_scopeId}></i> ${ssrInterpolate(__props.t.section_data)}</div><div class="detail-table" data-v-f904fd81${_scopeId}><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_email)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.email || "—")}</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_subdomain)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.subdomain || "—")}</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_telephone)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.telephone || "—")}</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_cellphone)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.cellphone || "—")}</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_website)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>`);
              if (entity.value.website) {
                _push2(`<a${ssrRenderAttr("href", entity.value.website)} target="_blank" rel="noopener" class="text-primary" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.website)}</a>`);
              } else {
                _push2(`<span data-v-f904fd81${_scopeId}>—</span>`);
              }
              _push2(`</span></div></div></div><div class="detail-section" data-v-f904fd81${_scopeId}><div class="detail-section__title" data-v-f904fd81${_scopeId}><i class="ti ti-file-text me-1" data-v-f904fd81${_scopeId}></i> ${ssrInterpolate(__props.t.section_docs)}</div><div class="detail-table" data-v-f904fd81${_scopeId}><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_national_registration)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.national_registration || "—")}</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_state_registration)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.state_registration || "—")}</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_municipal_registration)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.municipal_registration || "—")}</span></div></div></div><div class="detail-section" data-v-f904fd81${_scopeId}><div class="detail-section__title" data-v-f904fd81${_scopeId}><i class="ti ti-map-pin me-1" data-v-f904fd81${_scopeId}></i> ${ssrInterpolate(__props.t.section_address)}</div><div class="detail-table" data-v-f904fd81${_scopeId}><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_zipcode)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.zipcode || "—")}</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_address)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.address || "—")} `);
              if (entity.value.number) {
                _push2(`<!--[-->, ${ssrInterpolate(entity.value.number)}<!--]-->`);
              } else {
                _push2(`<!---->`);
              }
              if (entity.value.complement) {
                _push2(`<!--[--> — ${ssrInterpolate(entity.value.complement)}<!--]-->`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_district)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.district || "—")}</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_city_state)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.city || "—")}${ssrInterpolate(entity.value.state ? ` / ${entity.value.state}` : "")}</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_country)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.country || "—")}</span></div></div></div><div class="detail-section" data-v-f904fd81${_scopeId}><div class="detail-section__title" data-v-f904fd81${_scopeId}><i class="ti ti-settings me-1" data-v-f904fd81${_scopeId}></i> ${ssrInterpolate(__props.t.section_config)}</div><div class="detail-table" data-v-f904fd81${_scopeId}><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_schedule_interval)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(unref(tx)("detail_interval_minutes", { value: entity.value.schedule_interval }))}</span></div><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_registered_at)}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.created_at)}</span></div></div></div><div class="detail-section" data-v-f904fd81${_scopeId}><div class="detail-section__title" data-v-f904fd81${_scopeId}><i class="ti ti-shield-lock me-1" data-v-f904fd81${_scopeId}></i> ${ssrInterpolate(__props.t.section_security ?? "Segurança")}</div><div class="detail-table" data-v-f904fd81${_scopeId}><div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_2fa_required ?? "Exige 2FA")}</span><span class="detail-value" data-v-f904fd81${_scopeId}>`);
              if (entity.value.requires_two_factor) {
                _push2(`<span class="badge badge-soft-success rounded text-success border border-success" data-v-f904fd81${_scopeId}><i class="ti ti-shield-lock-filled me-1" data-v-f904fd81${_scopeId}></i>${ssrInterpolate(__props.t.detail_2fa_yes ?? "Sim")}</span>`);
              } else {
                _push2(`<span class="badge badge-soft-secondary rounded" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_2fa_no ?? "Não")}</span>`);
              }
              _push2(`</span></div>`);
              if (entity.value.requires_two_factor && entity.value.two_factor_enabled_at) {
                _push2(`<div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_2fa_enabled_at ?? "Ativado em")}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.two_factor_enabled_at)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (entity.value.requires_two_factor && entity.value.two_factor_enabled_by) {
                _push2(`<div class="detail-row" data-v-f904fd81${_scopeId}><span class="detail-label" data-v-f904fd81${_scopeId}>${ssrInterpolate(__props.t.detail_2fa_enabled_by ?? "Ativado por")}</span><span class="detail-value" data-v-f904fd81${_scopeId}>${ssrInterpolate(entity.value.two_factor_enabled_by)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><!--]-->`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              entity.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                createVNode("div", { class: "mb-4" }, [
                  entity.value.deleted_at ? (openBlock(), createBlock("span", {
                    key: 0,
                    class: "badge bg-danger"
                  }, toDisplayString(unref(tx)("detail_deleted_at", { date: entity.value.deleted_at })), 1)) : entity.value.active ? (openBlock(), createBlock("span", {
                    key: 1,
                    class: "badge bg-success"
                  }, toDisplayString(__props.t.status_active), 1)) : (openBlock(), createBlock("span", {
                    key: 2,
                    class: "badge bg-secondary"
                  }, toDisplayString(__props.t.status_inactive), 1))
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-building me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.section_data), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_email), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.email || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_subdomain), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.subdomain || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_telephone), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.telephone || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_cellphone), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.cellphone || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_website), 1),
                      createVNode("span", { class: "detail-value" }, [
                        entity.value.website ? (openBlock(), createBlock("a", {
                          key: 0,
                          href: entity.value.website,
                          target: "_blank",
                          rel: "noopener",
                          class: "text-primary"
                        }, toDisplayString(entity.value.website), 9, ["href"])) : (openBlock(), createBlock("span", { key: 1 }, "—"))
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-file-text me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.section_docs), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_national_registration), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.national_registration || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_state_registration), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.state_registration || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_municipal_registration), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.municipal_registration || "—"), 1)
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-map-pin me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.section_address), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_zipcode), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.zipcode || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_address), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(entity.value.address || "—") + " ", 1),
                        entity.value.number ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                          createTextVNode(", " + toDisplayString(entity.value.number), 1)
                        ], 64)) : createCommentVNode("", true),
                        entity.value.complement ? (openBlock(), createBlock(Fragment, { key: 1 }, [
                          createTextVNode(" — " + toDisplayString(entity.value.complement), 1)
                        ], 64)) : createCommentVNode("", true)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_district), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.district || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_city_state), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.city || "—") + toDisplayString(entity.value.state ? ` / ${entity.value.state}` : ""), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_country), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.country || "—"), 1)
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-settings me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.section_config), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_schedule_interval), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(unref(tx)("detail_interval_minutes", { value: entity.value.schedule_interval })), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_registered_at), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.created_at), 1)
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-shield-lock me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.section_security ?? "Segurança"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_2fa_required ?? "Exige 2FA"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        entity.value.requires_two_factor ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "badge badge-soft-success rounded text-success border border-success"
                        }, [
                          createVNode("i", { class: "ti ti-shield-lock-filled me-1" }),
                          createTextVNode(toDisplayString(__props.t.detail_2fa_yes ?? "Sim"), 1)
                        ])) : (openBlock(), createBlock("span", {
                          key: 1,
                          class: "badge badge-soft-secondary rounded"
                        }, toDisplayString(__props.t.detail_2fa_no ?? "Não"), 1))
                      ])
                    ]),
                    entity.value.requires_two_factor && entity.value.two_factor_enabled_at ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_2fa_enabled_at ?? "Ativado em"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.two_factor_enabled_at), 1)
                    ])) : createCommentVNode("", true),
                    entity.value.requires_two_factor && entity.value.two_factor_enabled_by ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.detail_2fa_enabled_by ?? "Ativado por"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(entity.value.two_factor_enabled_by), 1)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Entities/EntityDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const EntityDetailDrawer = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-f904fd81"]]);
export {
  EntityDetailDrawer as default
};
