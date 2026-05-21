import { computed, withCtx, createTextVNode, toDisplayString, createVNode, openBlock, createBlock, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderStyle, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { S as SortableTh } from "./SortableTh-B7Fp64cd.js";
import { _ as _sfc_main$1 } from "./BillingStateBadge-CJY8fYg_.js";
import { _ as _sfc_main$3 } from "./TablePagination-Dj1_H7YG.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$2 } from "./ActionIconGroup-Dj2wQrik.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "SubscriptionTable",
  __ssrInlineRender: true,
  props: {
    subscriptions: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["sort", "view", "edit", "activate", "trial", "cancel", "block"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const currentSort = computed(() => props.filters.sort ?? "created_at");
    const currentDir = computed(() => props.filters.direction ?? "desc");
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[--><div class="table-responsive"><table class="table table-nowrap table-hover align-middle mb-0"><thead class="table-light"><tr>`);
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "entity_name",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort: ($event) => _ctx.$emit("sort", $event)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_entity)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_entity), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "plan_name",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort: ($event) => _ctx.$emit("sort", $event)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_plan)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_plan), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<th class="text-center" style="${ssrRenderStyle({ "min-width": "90px" })}">${ssrInterpolate(__props.t.col_status)}</th><th class="text-center" style="${ssrRenderStyle({ "min-width": "100px" })}">${ssrInterpolate(__props.t.col_billing_state)}</th><th class="text-center" style="${ssrRenderStyle({ "min-width": "90px" })}">${ssrInterpolate(__props.t.col_gateway)}</th>`);
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "next_billing_at",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort: ($event) => _ctx.$emit("sort", $event)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_next_billing)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_next_billing), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "starts_at",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort: ($event) => _ctx.$emit("sort", $event)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_starts_at)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_starts_at), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "ends_at",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort: ($event) => _ctx.$emit("sort", $event)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_ends_at)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_ends_at), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<th class="text-end">${ssrInterpolate(__props.t.col_actions)}</th></tr></thead><tbody>`);
      if (__props.subscriptions.data.length === 0) {
        _push(`<tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-file-contract fs-1 d-block mb-2 opacity-25"></i> ${ssrInterpolate(__props.t.empty_list)}</td></tr>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--[-->`);
      ssrRenderList(__props.subscriptions.data, (s) => {
        _push(`<tr class="${ssrRenderClass({ "table-warning": s.needs_attention })}"><td><div class="d-flex align-items-center gap-2"><span class="${ssrRenderClass([s.entity_active ? "bg-success-subtle" : "bg-danger-subtle", "avatar-xs rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0"])}" style="${ssrRenderStyle({ "width": "28px", "height": "28px" })}"><i class="${ssrRenderClass([s.entity_active ? "text-success" : "text-danger", "fas fa-file-contract fs-11"])}"></i></span><div><div class="fw-medium" style="${ssrRenderStyle({ "font-size": ".875rem" })}">${ssrInterpolate(s.entity_name)}</div>`);
        if (s.needs_attention) {
          _push(`<div class="text-danger" style="${ssrRenderStyle({ "font-size": ".7rem" })}"><i class="ti ti-alert-triangle me-1"></i>${ssrInterpolate(__props.t.attention_badge)}</div>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div></div></td><td class="text-muted small">${ssrInterpolate(s.plan_name)}</td><td class="text-center"><span class="${ssrRenderClass([s.status_badge, "badge"])}">${ssrInterpolate(s.status_label)}</span></td><td class="text-center">`);
        _push(ssrRenderComponent(_sfc_main$1, {
          badge: s.billing_state_badge,
          label: s.billing_state_label,
          state: s.billing_state
        }, null, _parent));
        _push(`</td><td class="text-center">`);
        if (s.gateway) {
          _push(`<span class="badge badge-soft-primary text-uppercase">${ssrInterpolate(s.gateway)}</span>`);
        } else {
          _push(`<span class="text-muted">—</span>`);
        }
        _push(`</td><td class="small">${ssrInterpolate(s.next_billing_at ?? "—")}</td><td class="small">${ssrInterpolate(s.starts_at ?? "—")}</td><td class="small">${ssrInterpolate(s.ends_at ?? "∞")}</td><td class="text-end">`);
        _push(ssrRenderComponent(ActionIconGroup, {
          align: "end",
          gap: "tight"
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(ssrRenderComponent(_sfc_main$2, {
                icon: "ti ti-eye",
                title: __props.t.action_view,
                onClick: ($event) => _ctx.$emit("view", s.id)
              }, null, _parent2, _scopeId));
              _push2(ssrRenderComponent(ActionDropdown, {
                "min-width": 180,
                "btn-class": "ee-action-icon ee-action-icon--default",
                icon: "ti ti-dots-vertical"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="ti ti-edit me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_edit)}</button></li><li${_scopeId2}><button class="dropdown-item rounded-1 text-success"${_scopeId2}><i class="ti ti-player-play me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_activate)}</button></li><li${_scopeId2}><button class="dropdown-item rounded-1 text-info"${_scopeId2}><i class="ti ti-clock-play me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_trial)}</button></li>`);
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
                          class: "dropdown-item rounded-1",
                          onClick: ($event) => _ctx.$emit("edit", s.id)
                        }, [
                          createVNode("i", { class: "ti ti-edit me-1" }),
                          createTextVNode(" " + toDisplayString(__props.t.action_edit), 1)
                        ], 8, ["onClick"])
                      ]),
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
                createVNode(_sfc_main$2, {
                  icon: "ti ti-eye",
                  title: __props.t.action_view,
                  onClick: ($event) => _ctx.$emit("view", s.id)
                }, null, 8, ["title", "onClick"]),
                createVNode(ActionDropdown, {
                  "min-width": 180,
                  "btn-class": "ee-action-icon ee-action-icon--default",
                  icon: "ti ti-dots-vertical"
                }, {
                  default: withCtx(() => [
                    createVNode("li", null, [
                      createVNode("button", {
                        class: "dropdown-item rounded-1",
                        onClick: ($event) => _ctx.$emit("edit", s.id)
                      }, [
                        createVNode("i", { class: "ti ti-edit me-1" }),
                        createTextVNode(" " + toDisplayString(__props.t.action_edit), 1)
                      ], 8, ["onClick"])
                    ]),
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
        _push(`</td></tr>`);
      });
      _push(`<!--]--></tbody></table></div>`);
      _push(ssrRenderComponent(_sfc_main$3, {
        data: __props.subscriptions,
        "showing-from": __props.t.showing_from,
        "showing-of": __props.t.showing_of,
        "showing-suffix": __props.t.showing_suffix
      }, null, _parent));
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Subscriptions/SubscriptionTable.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
