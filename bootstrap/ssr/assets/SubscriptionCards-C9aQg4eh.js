import { ref, watch, onMounted, onUnmounted, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderStyle } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { _ as _sfc_main$1, a as _sfc_main$4 } from "./CardsPagination-B87u3Z8A.js";
import { _ as _sfc_main$2 } from "./BillingStateBadge-CJY8fYg_.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$3 } from "./ActionIconGroup-Dj2wQrik.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "SubscriptionCards",
  __ssrInlineRender: true,
  props: {
    cardsUrl: { type: String, required: true },
    initialSearch: { type: String, default: "" },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["view", "edit", "activate", "trial", "cancel", "block"],
  setup(__props, { expose: __expose, emit: __emit }) {
    const props = __props;
    const subscriptions = ref([]);
    const meta = ref({ current_page: 1, last_page: 1 });
    const loading = ref(false);
    async function fetchCards(page = 1) {
      loading.value = true;
      try {
        const params = new URLSearchParams({ page, search: props.initialSearch });
        const json = await fetch(`${props.cardsUrl}?${params}`).then((r) => r.json());
        subscriptions.value = json.data;
        meta.value = json.meta;
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.initialSearch, () => fetchCards(1));
    let removeSuccessListener;
    onMounted(() => {
      fetchCards(1);
      removeSuccessListener = router.on("success", () => fetchCards(meta.value.current_page));
    });
    onUnmounted(() => removeSuccessListener == null ? void 0 : removeSuccessListener());
    __expose({ fetchCards });
    return (_ctx, _push, _parent, _attrs) => {
      if (loading.value) {
        _push(ssrRenderComponent(_sfc_main$1, mergeProps({
          label: __props.t.loading
        }, _attrs), null, _parent));
      } else {
        _push(`<!--[-->`);
        if (subscriptions.value.length === 0) {
          _push(`<div class="text-center text-muted py-5"><i class="fas fa-file-contract fa-3x mb-3 opacity-25"></i><p>${ssrInterpolate(__props.t.empty_list)}</p></div>`);
        } else {
          _push(`<div class="row g-3"><!--[-->`);
          ssrRenderList(subscriptions.value, (s) => {
            _push(`<div class="col-sm-6 col-xl-4"><div class="${ssrRenderClass([s.needs_attention ? "border-danger border-2" : "", "card card-body h-100 position-relative"])}">`);
            if (s.needs_attention) {
              _push(`<span class="position-absolute top-0 end-0 m-2"><span class="badge badge-soft-danger"><i class="ti ti-alert-triangle me-1"></i>${ssrInterpolate(__props.t.attention_badge)}</span></span>`);
            } else {
              _push(`<!---->`);
            }
            _push(`<div class="d-flex align-items-start gap-3"><div class="${ssrRenderClass([s.entity_active ? "bg-success-subtle" : "bg-danger-subtle", "avatar-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"])}" style="${ssrRenderStyle({ "width": "44px", "height": "44px" })}"><i class="${ssrRenderClass([s.entity_active ? "text-success" : "text-danger", "fas fa-file-contract fs-16"])}"></i></div><div class="flex-grow-1 min-w-0"><h6 class="mb-1 fw-semibold text-truncate">${ssrInterpolate(s.entity_name)}</h6><div class="mb-2 d-flex flex-wrap gap-1"><span class="${ssrRenderClass([s.status_badge, "badge"])}">${ssrInterpolate(s.status_label)}</span>`);
            if (s.billing_state) {
              _push(ssrRenderComponent(_sfc_main$2, {
                badge: s.billing_state_badge,
                label: s.billing_state_label
              }, null, _parent));
            } else {
              _push(`<!---->`);
            }
            _push(`</div><div class="small text-muted"><div><strong>Plano:</strong> ${ssrInterpolate(s.plan_name)}</div>`);
            if (s.gateway) {
              _push(`<div><strong>Gateway:</strong><span class="text-uppercase fw-semibold ms-1">${ssrInterpolate(s.gateway)}</span></div>`);
            } else {
              _push(`<!---->`);
            }
            _push(`<div><strong>Início:</strong> ${ssrInterpolate(s.starts_at ?? "—")}</div><div><strong>Vencimento:</strong> ${ssrInterpolate(s.ends_at ?? "∞")}</div>`);
            if (s.next_billing_at) {
              _push(`<div><strong>Próxima cobrança:</strong> ${ssrInterpolate(s.next_billing_at)}</div>`);
            } else {
              _push(`<!---->`);
            }
            if (s.trial_ends_at) {
              _push(`<div><strong>Trial até:</strong> ${ssrInterpolate(s.trial_ends_at)}</div>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div>`);
            if (s.last_billing_error) {
              _push(`<div class="alert alert-danger py-1 px-2 small mt-2 mb-0"><i class="ti ti-alert-circle me-1"></i>${ssrInterpolate(s.last_billing_error)}</div>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div></div><hr class="my-2">`);
            _push(ssrRenderComponent(ActionIconGroup, {
              align: "end",
              gap: "tight"
            }, {
              default: withCtx((_, _push2, _parent2, _scopeId) => {
                if (_push2) {
                  _push2(ssrRenderComponent(_sfc_main$3, {
                    icon: "ti ti-eye",
                    title: __props.t.action_view,
                    onClick: ($event) => _ctx.$emit("view", s.id)
                  }, null, _parent2, _scopeId));
                  _push2(ssrRenderComponent(_sfc_main$3, {
                    icon: "ti ti-edit",
                    title: __props.t.action_edit,
                    onClick: ($event) => _ctx.$emit("edit", s.id)
                  }, null, _parent2, _scopeId));
                  _push2(ssrRenderComponent(ActionDropdown, {
                    "min-width": 180,
                    "btn-class": "ee-action-icon ee-action-icon--default",
                    icon: "ti ti-dots-vertical"
                  }, {
                    default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                      if (_push3) {
                        _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1 text-success"${_scopeId2}><i class="ti ti-player-play me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_activate)}</button></li><li${_scopeId2}><button class="dropdown-item rounded-1 text-info"${_scopeId2}><i class="ti ti-clock-play me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_trial)}</button></li>`);
                        if (s.is_accessible) {
                          _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1 text-warning"${_scopeId2}><i class="ti ti-ban me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_cancel)}</button></li>`);
                        } else {
                          _push3(`<!---->`);
                        }
                        _push3(`<li${_scopeId2}><hr class="dropdown-divider my-1"${_scopeId2}></li><li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="${ssrRenderClass(`ti me-1 ${s.entity_active ? "ti-lock" : "ti-lock-open"}`)}"${_scopeId2}></i> ${ssrInterpolate(s.entity_active ? __props.t.action_block : __props.t.action_unblock)}</button></li>`);
                      } else {
                        return [
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1 text-success",
                              onClick: ($event) => _ctx.$emit("activate", s)
                            }, [
                              createVNode("i", { class: "ti ti-player-play me-1" }),
                              createTextVNode(" " + toDisplayString(__props.t.action_activate), 1)
                            ], 8, ["onClick"])
                          ]),
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1 text-info",
                              onClick: ($event) => _ctx.$emit("trial", s)
                            }, [
                              createVNode("i", { class: "ti ti-clock-play me-1" }),
                              createTextVNode(" " + toDisplayString(__props.t.action_trial), 1)
                            ], 8, ["onClick"])
                          ]),
                          s.is_accessible ? (openBlock(), createBlock("li", { key: 0 }, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1 text-warning",
                              onClick: ($event) => _ctx.$emit("cancel", s)
                            }, [
                              createVNode("i", { class: "ti ti-ban me-1" }),
                              createTextVNode(" " + toDisplayString(__props.t.action_cancel), 1)
                            ], 8, ["onClick"])
                          ])) : createCommentVNode("", true),
                          createVNode("li", null, [
                            createVNode("hr", { class: "dropdown-divider my-1" })
                          ]),
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1",
                              onClick: ($event) => _ctx.$emit("block", s)
                            }, [
                              createVNode("i", {
                                class: `ti me-1 ${s.entity_active ? "ti-lock" : "ti-lock-open"}`
                              }, null, 2),
                              createTextVNode(" " + toDisplayString(s.entity_active ? __props.t.action_block : __props.t.action_unblock), 1)
                            ], 8, ["onClick"])
                          ])
                        ];
                      }
                    }),
                    _: 2
                  }, _parent2, _scopeId));
                } else {
                  return [
                    createVNode(_sfc_main$3, {
                      icon: "ti ti-eye",
                      title: __props.t.action_view,
                      onClick: ($event) => _ctx.$emit("view", s.id)
                    }, null, 8, ["title", "onClick"]),
                    createVNode(_sfc_main$3, {
                      icon: "ti ti-edit",
                      title: __props.t.action_edit,
                      onClick: ($event) => _ctx.$emit("edit", s.id)
                    }, null, 8, ["title", "onClick"]),
                    createVNode(ActionDropdown, {
                      "min-width": 180,
                      "btn-class": "ee-action-icon ee-action-icon--default",
                      icon: "ti ti-dots-vertical"
                    }, {
                      default: withCtx(() => [
                        createVNode("li", null, [
                          createVNode("button", {
                            class: "dropdown-item rounded-1 text-success",
                            onClick: ($event) => _ctx.$emit("activate", s)
                          }, [
                            createVNode("i", { class: "ti ti-player-play me-1" }),
                            createTextVNode(" " + toDisplayString(__props.t.action_activate), 1)
                          ], 8, ["onClick"])
                        ]),
                        createVNode("li", null, [
                          createVNode("button", {
                            class: "dropdown-item rounded-1 text-info",
                            onClick: ($event) => _ctx.$emit("trial", s)
                          }, [
                            createVNode("i", { class: "ti ti-clock-play me-1" }),
                            createTextVNode(" " + toDisplayString(__props.t.action_trial), 1)
                          ], 8, ["onClick"])
                        ]),
                        s.is_accessible ? (openBlock(), createBlock("li", { key: 0 }, [
                          createVNode("button", {
                            class: "dropdown-item rounded-1 text-warning",
                            onClick: ($event) => _ctx.$emit("cancel", s)
                          }, [
                            createVNode("i", { class: "ti ti-ban me-1" }),
                            createTextVNode(" " + toDisplayString(__props.t.action_cancel), 1)
                          ], 8, ["onClick"])
                        ])) : createCommentVNode("", true),
                        createVNode("li", null, [
                          createVNode("hr", { class: "dropdown-divider my-1" })
                        ]),
                        createVNode("li", null, [
                          createVNode("button", {
                            class: "dropdown-item rounded-1",
                            onClick: ($event) => _ctx.$emit("block", s)
                          }, [
                            createVNode("i", {
                              class: `ti me-1 ${s.entity_active ? "ti-lock" : "ti-lock-open"}`
                            }, null, 2),
                            createTextVNode(" " + toDisplayString(s.entity_active ? __props.t.action_block : __props.t.action_unblock), 1)
                          ], 8, ["onClick"])
                        ])
                      ]),
                      _: 2
                    }, 1024)
                  ];
                }
              }),
              _: 2
            }, _parent));
            _push(`</div></div>`);
          });
          _push(`<!--]--></div>`);
        }
        _push(ssrRenderComponent(_sfc_main$4, {
          meta: meta.value,
          onChange: fetchCards
        }, null, _parent));
        _push(`<!--]-->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Subscriptions/SubscriptionCards.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
