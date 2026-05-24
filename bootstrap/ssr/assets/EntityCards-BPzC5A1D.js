import { ref, watch, onMounted, onUnmounted, mergeProps, withCtx, openBlock, createBlock, createCommentVNode, createVNode, createTextVNode, toDisplayString, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderStyle, ssrRenderAttr } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { _ as _sfc_main$1, a as _sfc_main$4 } from "./CardsPagination-B87u3Z8A.js";
import { _ as _sfc_main$2 } from "./StatusBadge-Du3rSMdo.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { _ as _sfc_main$3 } from "./ActionIconButton-BTsQtzdl.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "EntityCards",
  __ssrInlineRender: true,
  props: {
    cardsUrl: { type: String, required: true },
    initialSearch: { type: String, default: "" },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["view", "edit", "delete", "toggleActive"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const items = ref([]);
    const meta = ref({ current_page: 1, last_page: 1 });
    const loading = ref(false);
    async function fetchCards(p = 1) {
      loading.value = true;
      try {
        const params = new URLSearchParams({ page: p, search: props.initialSearch });
        const json = await fetch(`${props.cardsUrl}?${params}`).then((r) => r.json());
        items.value = json.data;
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
    return (_ctx, _push, _parent, _attrs) => {
      if (loading.value) {
        _push(ssrRenderComponent(_sfc_main$1, mergeProps({
          label: __props.t.loading
        }, _attrs), null, _parent));
      } else {
        _push(`<!--[-->`);
        if (items.value.length === 0) {
          _push(`<div class="text-center text-muted py-5"><i class="ti ti-building fs-1 mb-2 d-block"></i><p>${ssrInterpolate(__props.t.empty_list)}</p></div>`);
        } else {
          _push(`<div class="row g-3"><!--[-->`);
          ssrRenderList(items.value, (e) => {
            _push(`<div class="col-sm-6 col-xl-4"><div class="${ssrRenderClass([{ "border-danger opacity-75": e.deleted }, "card card-body h-100"])}"><div class="d-flex align-items-start gap-3"><div class="avatar-sm rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="${ssrRenderStyle({ "width": "44px", "height": "44px" })}"><i class="ti ti-building text-primary fs-18"></i></div><div class="flex-grow-1 min-w-0"><h6 class="mb-1 fw-semibold lh-sm text-truncate">${ssrInterpolate(e.name)}</h6><div class="mb-1 d-flex flex-wrap gap-1">`);
            _push(ssrRenderComponent(_sfc_main$2, {
              active: e.active,
              deleted: e.deleted,
              "label-active": __props.t.status_active,
              "label-inactive": __props.t.status_inactive,
              "label-deleted": __props.t.status_deleted
            }, null, _parent));
            if (e.requires_two_factor) {
              _push(`<span class="badge badge-soft-success rounded text-success border border-success fs-11"${ssrRenderAttr("title", __props.t.badge_2fa_required_hint ?? "Esta empresa exige 2FA de todos os usuários")}><i class="ti ti-shield-lock-filled me-1"></i>2FA </span>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div><address class="small text-muted mb-2"><div><strong>${ssrInterpolate(__props.t.card_code)}</strong> ${ssrInterpolate(e.code)}</div>`);
            if (e.city || e.state) {
              _push(`<div><strong>${ssrInterpolate(__props.t.card_location)}</strong> ${ssrInterpolate(e.city || "—")}${ssrInterpolate(e.state ? ` / ${e.state}` : "")}</div>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</address><div class="d-flex gap-2 mb-2"><span class="${ssrRenderClass([{ "opacity-50": e.entity_users_count === 0 }, "badge badge-soft-secondary rounded fs-12"])}"${ssrRenderAttr("title", `${e.entity_users_count} ${__props.t.action_users}`)}><i class="ti ti-users me-1"></i>${ssrInterpolate(e.entity_users_count)}</span><span class="${ssrRenderClass([{ "opacity-50": e.entity_user_integrators_count === 0 }, "badge badge-soft-secondary rounded fs-12"])}"${ssrRenderAttr("title", `${e.entity_user_integrators_count} ${__props.t.action_user_integrators}`)}><i class="ti ti-user-cog me-1"></i>${ssrInterpolate(e.entity_user_integrators_count)}</span></div></div></div><hr class="my-2"><div class="d-flex align-items-center justify-content-between">`);
            _push(ssrRenderComponent(ActionIconGroup, { gap: "tight" }, {
              default: withCtx((_, _push2, _parent2, _scopeId) => {
                if (_push2) {
                  if (e.entity_users_count > 0) {
                    _push2(ssrRenderComponent(_sfc_main$3, {
                      icon: "ti ti-users",
                      title: __props.t.action_users,
                      href: e.users_url
                    }, null, _parent2, _scopeId));
                  } else {
                    _push2(`<!---->`);
                  }
                  if (e.entity_user_integrators_count > 0) {
                    _push2(ssrRenderComponent(_sfc_main$3, {
                      icon: "ti ti-user-cog",
                      title: __props.t.action_user_integrators,
                      href: e.user_integrators_url
                    }, null, _parent2, _scopeId));
                  } else {
                    _push2(`<!---->`);
                  }
                } else {
                  return [
                    e.entity_users_count > 0 ? (openBlock(), createBlock(_sfc_main$3, {
                      key: 0,
                      icon: "ti ti-users",
                      title: __props.t.action_users,
                      href: e.users_url
                    }, null, 8, ["title", "href"])) : createCommentVNode("", true),
                    e.entity_user_integrators_count > 0 ? (openBlock(), createBlock(_sfc_main$3, {
                      key: 1,
                      icon: "ti ti-user-cog",
                      title: __props.t.action_user_integrators,
                      href: e.user_integrators_url
                    }, null, 8, ["title", "href"])) : createCommentVNode("", true)
                  ];
                }
              }),
              _: 2
            }, _parent));
            if (e.mode === "full") {
              _push(ssrRenderComponent(ActionIconGroup, {
                align: "end",
                gap: "tight"
              }, {
                default: withCtx((_, _push2, _parent2, _scopeId) => {
                  if (_push2) {
                    _push2(ssrRenderComponent(_sfc_main$3, {
                      icon: "ti ti-eye",
                      title: __props.t.action_view,
                      onClick: ($event) => _ctx.$emit("view", e.id)
                    }, null, _parent2, _scopeId));
                    _push2(ssrRenderComponent(_sfc_main$3, {
                      icon: "ti ti-edit",
                      title: __props.t.action_edit,
                      onClick: ($event) => _ctx.$emit("edit", e.id)
                    }, null, _parent2, _scopeId));
                    _push2(ssrRenderComponent(ActionDropdown, {
                      "btn-class": "ee-action-icon ee-action-icon--default",
                      icon: "ti ti-dots-vertical"
                    }, {
                      default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                        if (_push3) {
                          _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="${ssrRenderClass(`ti me-1 ${e.active ? "ti-lock-open" : "ti-lock"}`)}"${_scopeId2}></i> ${ssrInterpolate(e.active ? __props.t.action_deactivate : __props.t.action_activate)}</button></li><li${_scopeId2}><button class="dropdown-item rounded-1 text-danger"${_scopeId2}><i class="ti ti-trash me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_delete)}</button></li>`);
                        } else {
                          return [
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1",
                                onClick: ($event) => _ctx.$emit("toggleActive", e.id, e.active)
                              }, [
                                createVNode("i", {
                                  class: `ti me-1 ${e.active ? "ti-lock-open" : "ti-lock"}`
                                }, null, 2),
                                createTextVNode(" " + toDisplayString(e.active ? __props.t.action_deactivate : __props.t.action_activate), 1)
                              ], 8, ["onClick"])
                            ]),
                            createVNode("li", null, [
                              createVNode("button", {
                                class: "dropdown-item rounded-1 text-danger",
                                onClick: ($event) => _ctx.$emit("delete", e.id)
                              }, [
                                createVNode("i", { class: "ti ti-trash me-1" }),
                                createTextVNode(" " + toDisplayString(__props.t.action_delete), 1)
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
                        onClick: ($event) => _ctx.$emit("view", e.id)
                      }, null, 8, ["title", "onClick"]),
                      createVNode(_sfc_main$3, {
                        icon: "ti ti-edit",
                        title: __props.t.action_edit,
                        onClick: ($event) => _ctx.$emit("edit", e.id)
                      }, null, 8, ["title", "onClick"]),
                      createVNode(ActionDropdown, {
                        "btn-class": "ee-action-icon ee-action-icon--default",
                        icon: "ti ti-dots-vertical"
                      }, {
                        default: withCtx(() => [
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1",
                              onClick: ($event) => _ctx.$emit("toggleActive", e.id, e.active)
                            }, [
                              createVNode("i", {
                                class: `ti me-1 ${e.active ? "ti-lock-open" : "ti-lock"}`
                              }, null, 2),
                              createTextVNode(" " + toDisplayString(e.active ? __props.t.action_deactivate : __props.t.action_activate), 1)
                            ], 8, ["onClick"])
                          ]),
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1 text-danger",
                              onClick: ($event) => _ctx.$emit("delete", e.id)
                            }, [
                              createVNode("i", { class: "ti ti-trash me-1" }),
                              createTextVNode(" " + toDisplayString(__props.t.action_delete), 1)
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
            } else {
              _push(`<!---->`);
            }
            _push(`</div></div></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Entities/EntityCards.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
