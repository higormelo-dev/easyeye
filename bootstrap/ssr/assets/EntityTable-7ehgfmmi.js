import { computed, withCtx, createTextVNode, toDisplayString, createVNode, openBlock, createBlock, Fragment, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderStyle, ssrRenderAttr } from "vue/server-renderer";
import { S as SortableTh } from "./SortableTh-B7Fp64cd.js";
import { _ as _sfc_main$1 } from "./StatusBadge-Du3rSMdo.js";
import { _ as _sfc_main$3 } from "./TablePagination-Dj1_H7YG.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { _ as _sfc_main$2 } from "./ActionIconButton-BTsQtzdl.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "EntityTable",
  __ssrInlineRender: true,
  props: {
    entities: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["sort", "view", "edit", "delete", "toggleActive"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const currentSort = computed(() => props.filters.sort ?? "created_at");
    const currentDir = computed(() => props.filters.direction ?? "desc");
    function onSort(payload) {
      emit("sort", payload);
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[--><div class="table-responsive"><table class="table table-nowrap table-hover align-middle mb-0"><thead class="table-light"><tr>`);
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "created_at",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_registered_at)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_registered_at), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "code",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_code)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_code), 1)
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
            _push2(`${ssrInterpolate(__props.t.col_company)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_company), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "city",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_city_state)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_city_state), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<th class="text-center">${ssrInterpolate(__props.t.col_users)}</th>`);
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
      if (__props.entities.data.length === 0) {
        _push(`<tr><td colspan="7" class="text-center text-muted py-5"><i class="ti ti-building fs-1 d-block mb-2"></i> ${ssrInterpolate(__props.t.empty_list)}</td></tr>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--[-->`);
      ssrRenderList(__props.entities.data, (e) => {
        _push(`<tr class="${ssrRenderClass({ "table-secondary opacity-75": e.deleted })}"><td class="text-muted small">${ssrInterpolate(e.created_at)}</td><td><code class="text-muted small">${ssrInterpolate(e.code)}</code></td><td><div class="d-flex align-items-center gap-2"><div class="avatar-sm rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0" style="${ssrRenderStyle({ "width": "32px", "height": "32px" })}"><i class="ti ti-building text-primary fs-14"></i></div><div><div class="fw-medium lh-sm" style="${ssrRenderStyle({ "font-size": ".875rem" })}">${ssrInterpolate(e.name)}</div>`);
        if (e.email) {
          _push(`<div class="text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}">${ssrInterpolate(e.email)}</div>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div></div></td><td class="text-muted small">`);
        if (e.city || e.state) {
          _push(`<!--[-->${ssrInterpolate(e.city || "—")}${ssrInterpolate(e.state ? ` / ${e.state}` : "")}<!--]-->`);
        } else {
          _push(`<span>—</span>`);
        }
        _push(`</td><td class="text-center"><span class="badge badge-soft-secondary rounded fs-12">${ssrInterpolate(e.entity_users_count)}</span></td><td class="text-center">`);
        _push(ssrRenderComponent(_sfc_main$1, {
          active: e.active,
          deleted: e.deleted,
          "label-active": __props.t.status_active,
          "label-inactive": __props.t.status_inactive,
          "label-deleted": __props.t.status_deleted
        }, null, _parent));
        if (e.requires_two_factor) {
          _push(`<span class="badge badge-soft-success rounded text-success border border-success fs-11 ms-1"${ssrRenderAttr("title", __props.t.badge_2fa_required_hint ?? "Esta empresa exige 2FA de todos os usuários")}><i class="ti ti-shield-lock-filled me-1"></i>2FA </span>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</td><td class="text-end">`);
        _push(ssrRenderComponent(ActionIconGroup, {
          align: "end",
          gap: "tight"
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              if (e.mode === "restore") {
                _push2(ssrRenderComponent(_sfc_main$2, {
                  icon: "ti ti-recycle",
                  title: __props.t.action_restore,
                  disabled: ""
                }, null, _parent2, _scopeId));
              } else if (e.mode === "full") {
                _push2(`<!--[-->`);
                _push2(ssrRenderComponent(_sfc_main$2, {
                  icon: "ti ti-eye",
                  title: __props.t.action_view,
                  onClick: ($event) => _ctx.$emit("view", e.id)
                }, null, _parent2, _scopeId));
                _push2(ssrRenderComponent(ActionDropdown, {
                  "min-width": 210,
                  "btn-class": "ee-action-icon ee-action-icon--default",
                  icon: "ti ti-dots-vertical"
                }, {
                  default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                    if (_push3) {
                      _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="ti ti-edit me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_edit)}</button></li><li${_scopeId2}><hr class="dropdown-divider"${_scopeId2}></li>`);
                      if (e.entity_users_count > 0) {
                        _push3(`<li${_scopeId2}><a${ssrRenderAttr("href", e.users_url)} class="dropdown-item rounded-1"${_scopeId2}><i class="ti ti-users me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_users)} <span class="badge badge-soft-primary ms-1 fs-11"${_scopeId2}>${ssrInterpolate(e.entity_users_count)}</span></a></li>`);
                      } else {
                        _push3(`<li${_scopeId2}><span class="dropdown-item rounded-1 text-muted disabled"${_scopeId2}><i class="ti ti-users me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_users)}</span></li>`);
                      }
                      if (e.entity_user_integrators_count > 0) {
                        _push3(`<li${_scopeId2}><a${ssrRenderAttr("href", e.user_integrators_url)} class="dropdown-item rounded-1"${_scopeId2}><i class="ti ti-user-cog me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_user_integrators)} <span class="badge badge-soft-primary ms-1 fs-11"${_scopeId2}>${ssrInterpolate(e.entity_user_integrators_count)}</span></a></li>`);
                      } else {
                        _push3(`<li${_scopeId2}><span class="dropdown-item rounded-1 text-muted disabled"${_scopeId2}><i class="ti ti-user-cog me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_user_integrators)}</span></li>`);
                      }
                      _push3(`<li${_scopeId2}><hr class="dropdown-divider"${_scopeId2}></li><li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="${ssrRenderClass(`ti me-1 ${e.active ? "ti-lock-open" : "ti-lock"}`)}"${_scopeId2}></i> ${ssrInterpolate(e.active ? __props.t.action_deactivate : __props.t.action_activate)}</button></li><li${_scopeId2}><button class="dropdown-item rounded-1 text-danger"${_scopeId2}><i class="ti ti-trash me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_delete)}</button></li>`);
                    } else {
                      return [
                        createVNode("li", null, [
                          createVNode("button", {
                            class: "dropdown-item rounded-1",
                            onClick: ($event) => _ctx.$emit("edit", e.id)
                          }, [
                            createVNode("i", { class: "ti ti-edit me-1" }),
                            createTextVNode(" " + toDisplayString(__props.t.action_edit), 1)
                          ], 8, ["onClick"])
                        ]),
                        createVNode("li", null, [
                          createVNode("hr", { class: "dropdown-divider" })
                        ]),
                        e.entity_users_count > 0 ? (openBlock(), createBlock("li", { key: 0 }, [
                          createVNode("a", {
                            href: e.users_url,
                            class: "dropdown-item rounded-1"
                          }, [
                            createVNode("i", { class: "ti ti-users me-1" }),
                            createTextVNode(" " + toDisplayString(__props.t.action_users) + " ", 1),
                            createVNode("span", { class: "badge badge-soft-primary ms-1 fs-11" }, toDisplayString(e.entity_users_count), 1)
                          ], 8, ["href"])
                        ])) : (openBlock(), createBlock("li", { key: 1 }, [
                          createVNode("span", { class: "dropdown-item rounded-1 text-muted disabled" }, [
                            createVNode("i", { class: "ti ti-users me-1" }),
                            createTextVNode(" " + toDisplayString(__props.t.action_users), 1)
                          ])
                        ])),
                        e.entity_user_integrators_count > 0 ? (openBlock(), createBlock("li", { key: 2 }, [
                          createVNode("a", {
                            href: e.user_integrators_url,
                            class: "dropdown-item rounded-1"
                          }, [
                            createVNode("i", { class: "ti ti-user-cog me-1" }),
                            createTextVNode(" " + toDisplayString(__props.t.action_user_integrators) + " ", 1),
                            createVNode("span", { class: "badge badge-soft-primary ms-1 fs-11" }, toDisplayString(e.entity_user_integrators_count), 1)
                          ], 8, ["href"])
                        ])) : (openBlock(), createBlock("li", { key: 3 }, [
                          createVNode("span", { class: "dropdown-item rounded-1 text-muted disabled" }, [
                            createVNode("i", { class: "ti ti-user-cog me-1" }),
                            createTextVNode(" " + toDisplayString(__props.t.action_user_integrators), 1)
                          ])
                        ])),
                        createVNode("li", null, [
                          createVNode("hr", { class: "dropdown-divider" })
                        ]),
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
                _push2(`<!--]-->`);
              } else {
                _push2(`<!---->`);
              }
            } else {
              return [
                e.mode === "restore" ? (openBlock(), createBlock(_sfc_main$2, {
                  key: 0,
                  icon: "ti ti-recycle",
                  title: __props.t.action_restore,
                  disabled: ""
                }, null, 8, ["title"])) : e.mode === "full" ? (openBlock(), createBlock(Fragment, { key: 1 }, [
                  createVNode(_sfc_main$2, {
                    icon: "ti ti-eye",
                    title: __props.t.action_view,
                    onClick: ($event) => _ctx.$emit("view", e.id)
                  }, null, 8, ["title", "onClick"]),
                  createVNode(ActionDropdown, {
                    "min-width": 210,
                    "btn-class": "ee-action-icon ee-action-icon--default",
                    icon: "ti ti-dots-vertical"
                  }, {
                    default: withCtx(() => [
                      createVNode("li", null, [
                        createVNode("button", {
                          class: "dropdown-item rounded-1",
                          onClick: ($event) => _ctx.$emit("edit", e.id)
                        }, [
                          createVNode("i", { class: "ti ti-edit me-1" }),
                          createTextVNode(" " + toDisplayString(__props.t.action_edit), 1)
                        ], 8, ["onClick"])
                      ]),
                      createVNode("li", null, [
                        createVNode("hr", { class: "dropdown-divider" })
                      ]),
                      e.entity_users_count > 0 ? (openBlock(), createBlock("li", { key: 0 }, [
                        createVNode("a", {
                          href: e.users_url,
                          class: "dropdown-item rounded-1"
                        }, [
                          createVNode("i", { class: "ti ti-users me-1" }),
                          createTextVNode(" " + toDisplayString(__props.t.action_users) + " ", 1),
                          createVNode("span", { class: "badge badge-soft-primary ms-1 fs-11" }, toDisplayString(e.entity_users_count), 1)
                        ], 8, ["href"])
                      ])) : (openBlock(), createBlock("li", { key: 1 }, [
                        createVNode("span", { class: "dropdown-item rounded-1 text-muted disabled" }, [
                          createVNode("i", { class: "ti ti-users me-1" }),
                          createTextVNode(" " + toDisplayString(__props.t.action_users), 1)
                        ])
                      ])),
                      e.entity_user_integrators_count > 0 ? (openBlock(), createBlock("li", { key: 2 }, [
                        createVNode("a", {
                          href: e.user_integrators_url,
                          class: "dropdown-item rounded-1"
                        }, [
                          createVNode("i", { class: "ti ti-user-cog me-1" }),
                          createTextVNode(" " + toDisplayString(__props.t.action_user_integrators) + " ", 1),
                          createVNode("span", { class: "badge badge-soft-primary ms-1 fs-11" }, toDisplayString(e.entity_user_integrators_count), 1)
                        ], 8, ["href"])
                      ])) : (openBlock(), createBlock("li", { key: 3 }, [
                        createVNode("span", { class: "dropdown-item rounded-1 text-muted disabled" }, [
                          createVNode("i", { class: "ti ti-user-cog me-1" }),
                          createTextVNode(" " + toDisplayString(__props.t.action_user_integrators), 1)
                        ])
                      ])),
                      createVNode("li", null, [
                        createVNode("hr", { class: "dropdown-divider" })
                      ]),
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
                ], 64)) : createCommentVNode("", true)
              ];
            }
          }),
          _: 2
        }, _parent));
        _push(`</td></tr>`);
      });
      _push(`<!--]--></tbody></table></div>`);
      _push(ssrRenderComponent(_sfc_main$3, {
        data: __props.entities,
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Entities/EntityTable.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
