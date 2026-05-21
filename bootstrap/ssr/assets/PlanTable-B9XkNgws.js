import { computed, withCtx, createTextVNode, toDisplayString, createVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderStyle, ssrRenderClass } from "vue/server-renderer";
import { S as SortableTh } from "./SortableTh-B7Fp64cd.js";
import { _ as _sfc_main$1 } from "./StatusBadge-Du3rSMdo.js";
import { _ as _sfc_main$3 } from "./TablePagination-Dj1_H7YG.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$2 } from "./ActionIconGroup-Dj2wQrik.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "PlanTable",
  __ssrInlineRender: true,
  props: {
    plans: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["sort", "view", "edit", "delete", "toggleActive"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const currentSort = computed(() => props.filters.sort ?? "sort_order");
    const currentDir = computed(() => props.filters.direction ?? "asc");
    function onSort(payload) {
      emit("sort", payload);
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[--><div class="table-responsive"><table class="table table-nowrap table-hover align-middle mb-0"><thead class="table-light"><tr>`);
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "sort_order",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        style: { "width": "70px" },
        onSort
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_order)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_order), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "name",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_name)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_name), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "price",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_price)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_price), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "billing_cycle",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_cycle)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_cycle), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "active",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        class: "text-center",
        onSort
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_status)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_status), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<th class="text-end">${ssrInterpolate(__props.t.col_actions)}</th></tr></thead><tbody>`);
      if (__props.plans.data.length === 0) {
        _push(`<tr><td colspan="6" class="text-center text-muted py-5"><i class="ti ti-box fs-1 d-block mb-2"></i> ${ssrInterpolate(__props.t.empty_list)}</td></tr>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--[-->`);
      ssrRenderList(__props.plans.data, (p) => {
        _push(`<tr><td class="text-center"><span class="badge badge-soft-secondary rounded fs-12">${ssrInterpolate(p.sort_order)}</span></td><td><div class="fw-medium" style="${ssrRenderStyle({ "font-size": ".875rem" })}">${ssrInterpolate(p.name)}</div>`);
        if (p.description) {
          _push(`<div class="text-muted text-truncate" style="${ssrRenderStyle({ "font-size": ".75rem", "max-width": "280px" })}">${ssrInterpolate(p.description)}</div>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</td><td class="fw-semibold">${ssrInterpolate(p.price)}</td><td><span class="badge badge-soft-info rounded fs-12">${ssrInterpolate(p.billing_label)}</span></td><td class="text-center">`);
        _push(ssrRenderComponent(_sfc_main$1, {
          active: p.active,
          "label-active": __props.t.status_active,
          "label-inactive": __props.t.status_inactive
        }, null, _parent));
        _push(`</td><td class="text-end">`);
        _push(ssrRenderComponent(ActionIconGroup, {
          align: "end",
          gap: "tight"
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(ssrRenderComponent(_sfc_main$2, {
                icon: "ti ti-eye",
                title: __props.t.action_view,
                onClick: ($event) => _ctx.$emit("view", p.id)
              }, null, _parent2, _scopeId));
              _push2(ssrRenderComponent(ActionDropdown, {
                "btn-class": "ee-action-icon ee-action-icon--default",
                icon: "ti ti-dots-vertical"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="ti ti-edit me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_edit)}</button></li><li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="${ssrRenderClass(`ti me-1 ${p.active ? "ti-lock-open" : "ti-lock"}`)}"${_scopeId2}></i> ${ssrInterpolate(p.active ? __props.t.action_deactivate : __props.t.action_activate)}</button></li><li${_scopeId2}><hr class="dropdown-divider"${_scopeId2}></li><li${_scopeId2}><button class="dropdown-item rounded-1 text-danger"${_scopeId2}><i class="ti ti-trash me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_delete)}</button></li>`);
                  } else {
                    return [
                      createVNode("li", null, [
                        createVNode("button", {
                          class: "dropdown-item rounded-1",
                          onClick: ($event) => _ctx.$emit("edit", p.id)
                        }, [
                          createVNode("i", { class: "ti ti-edit me-1" }),
                          createTextVNode(" " + toDisplayString(__props.t.action_edit), 1)
                        ], 8, ["onClick"])
                      ]),
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
                        createVNode("hr", { class: "dropdown-divider" })
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
                createVNode(_sfc_main$2, {
                  icon: "ti ti-eye",
                  title: __props.t.action_view,
                  onClick: ($event) => _ctx.$emit("view", p.id)
                }, null, 8, ["title", "onClick"]),
                createVNode(ActionDropdown, {
                  "btn-class": "ee-action-icon ee-action-icon--default",
                  icon: "ti ti-dots-vertical"
                }, {
                  default: withCtx(() => [
                    createVNode("li", null, [
                      createVNode("button", {
                        class: "dropdown-item rounded-1",
                        onClick: ($event) => _ctx.$emit("edit", p.id)
                      }, [
                        createVNode("i", { class: "ti ti-edit me-1" }),
                        createTextVNode(" " + toDisplayString(__props.t.action_edit), 1)
                      ], 8, ["onClick"])
                    ]),
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
                      createVNode("hr", { class: "dropdown-divider" })
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
        _push(`</td></tr>`);
      });
      _push(`<!--]--></tbody></table></div>`);
      _push(ssrRenderComponent(_sfc_main$3, {
        data: __props.plans,
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Plans/PlanTable.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
