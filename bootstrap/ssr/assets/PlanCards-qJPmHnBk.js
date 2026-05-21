import { ref, watch, onMounted, onUnmounted, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderStyle, ssrRenderClass } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { _ as _sfc_main$1, a as _sfc_main$4 } from "./CardsPagination-B87u3Z8A.js";
import { _ as _sfc_main$2 } from "./StatusBadge-Du3rSMdo.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$3 } from "./ActionIconGroup-Dj2wQrik.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "PlanCards",
  __ssrInlineRender: true,
  props: {
    cardsUrl: { type: String, required: true },
    initialSearch: { type: String, default: "" },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["view", "edit", "delete", "toggleActive"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const plans = ref([]);
    const meta = ref({ current_page: 1, last_page: 1 });
    const loading = ref(false);
    async function fetchCards(p = 1) {
      loading.value = true;
      try {
        const params = new URLSearchParams({ page: p, search: props.initialSearch });
        const json = await fetch(`${props.cardsUrl}?${params}`).then((r) => r.json());
        plans.value = json.data;
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
        if (plans.value.length === 0) {
          _push(`<div class="text-center text-muted py-5"><i class="ti ti-box fs-1 mb-2 d-block"></i><p>${ssrInterpolate(__props.t.empty_list)}</p></div>`);
        } else {
          _push(`<div class="row g-3"><!--[-->`);
          ssrRenderList(plans.value, (p) => {
            _push(`<div class="col-sm-6 col-xl-4"><div class="card card-body h-100"><div class="d-flex align-items-start gap-3"><div class="avatar-sm rounded-circle bg-info-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="${ssrRenderStyle({ "width": "44px", "height": "44px" })}"><i class="ti ti-box text-info fs-18"></i></div><div class="flex-grow-1"><div class="d-flex align-items-center gap-2 mb-1"><h6 class="mb-0 fw-semibold lh-sm">${ssrInterpolate(p.name)}</h6>`);
            _push(ssrRenderComponent(_sfc_main$2, {
              active: p.active,
              "label-active": __props.t.status_active,
              "label-inactive": __props.t.status_inactive
            }, null, _parent));
            _push(`</div><div class="text-muted small mb-2"><div><strong class="fw-semibold text-body">${ssrInterpolate(p.price)}</strong><span class="ms-1 badge badge-soft-info rounded fs-11">${ssrInterpolate(p.billing_cycle)}</span></div>`);
            if (p.description) {
              _push(`<div class="mt-1 text-muted" style="${ssrRenderStyle({ "font-size": ".8rem" })}">${ssrInterpolate(p.description)}</div>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div></div></div><hr class="my-2">`);
            _push(ssrRenderComponent(ActionIconGroup, {
              align: "end",
              gap: "tight"
            }, {
              default: withCtx((_, _push2, _parent2, _scopeId) => {
                if (_push2) {
                  _push2(ssrRenderComponent(_sfc_main$3, {
                    icon: "ti ti-eye",
                    title: __props.t.action_view,
                    onClick: ($event) => _ctx.$emit("view", p.id)
                  }, null, _parent2, _scopeId));
                  _push2(ssrRenderComponent(_sfc_main$3, {
                    icon: "ti ti-edit",
                    title: __props.t.action_edit,
                    onClick: ($event) => _ctx.$emit("edit", p.id)
                  }, null, _parent2, _scopeId));
                  _push2(ssrRenderComponent(ActionDropdown, {
                    "btn-class": "ee-action-icon ee-action-icon--default",
                    icon: "ti ti-dots-vertical"
                  }, {
                    default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                      if (_push3) {
                        _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="${ssrRenderClass(`ti me-1 ${p.active ? "ti-lock-open" : "ti-lock"}`)}"${_scopeId2}></i> ${ssrInterpolate(p.active ? __props.t.action_deactivate : __props.t.action_activate)}</button></li><li${_scopeId2}><button class="dropdown-item rounded-1 text-danger"${_scopeId2}><i class="ti ti-trash me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_delete)}</button></li>`);
                      } else {
                        return [
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1",
                              onClick: ($event) => _ctx.$emit("toggleActive", p.id, p.active)
                            }, [
                              createVNode("i", {
                                class: `ti me-1 ${p.active ? "ti-lock-open" : "ti-lock"}`
                              }, null, 2),
                              createTextVNode(" " + toDisplayString(p.active ? __props.t.action_deactivate : __props.t.action_activate), 1)
                            ], 8, ["onClick"])
                          ]),
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1 text-danger",
                              onClick: ($event) => _ctx.$emit("delete", p.id)
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
                      onClick: ($event) => _ctx.$emit("view", p.id)
                    }, null, 8, ["title", "onClick"]),
                    createVNode(_sfc_main$3, {
                      icon: "ti ti-edit",
                      title: __props.t.action_edit,
                      onClick: ($event) => _ctx.$emit("edit", p.id)
                    }, null, 8, ["title", "onClick"]),
                    createVNode(ActionDropdown, {
                      "btn-class": "ee-action-icon ee-action-icon--default",
                      icon: "ti ti-dots-vertical"
                    }, {
                      default: withCtx(() => [
                        createVNode("li", null, [
                          createVNode("button", {
                            class: "dropdown-item rounded-1",
                            onClick: ($event) => _ctx.$emit("toggleActive", p.id, p.active)
                          }, [
                            createVNode("i", {
                              class: `ti me-1 ${p.active ? "ti-lock-open" : "ti-lock"}`
                            }, null, 2),
                            createTextVNode(" " + toDisplayString(p.active ? __props.t.action_deactivate : __props.t.action_activate), 1)
                          ], 8, ["onClick"])
                        ]),
                        createVNode("li", null, [
                          createVNode("button", {
                            class: "dropdown-item rounded-1 text-danger",
                            onClick: ($event) => _ctx.$emit("delete", p.id)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Plans/PlanCards.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
