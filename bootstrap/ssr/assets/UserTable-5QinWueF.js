import { computed, withCtx, createTextVNode, toDisplayString, createVNode, openBlock, createBlock, Fragment, createCommentVNode, unref, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderAttr, ssrRenderStyle } from "vue/server-renderer";
import { Link } from "@inertiajs/vue3";
import { S as SortableTh } from "./SortableTh-B7Fp64cd.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { _ as _sfc_main$1 } from "./ActionIconButton-BTsQtzdl.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "UserTable",
  __ssrInlineRender: true,
  props: {
    users: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["sort", "edit", "delete", "restore", "toggle-active"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const currentSort = computed(() => props.filters.sort ?? "created_at");
    const currentDir = computed(() => props.filters.direction ?? "desc");
    function onSort(payload) {
      emit("sort", payload);
    }
    const showing = computed(() => {
      if (!props.users.from) return "";
      return (props.t.showing ?? "Exibindo :from–:to de :total usuários").replace(":from", props.users.from).replace(":to", props.users.to).replace(":total", props.users.total);
    });
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
            _push2(`${ssrInterpolate(__props.t.col_created_at)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_created_at), 1)
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
        "col-key": "email",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_email)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_email), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "rule",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_role)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_role), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<th class="text-center">${ssrInterpolate(__props.t.col_status)}</th><th class="text-end">${ssrInterpolate(__props.t.col_actions)}</th></tr></thead><tbody>`);
      if (__props.users.data.length === 0) {
        _push(`<tr><td colspan="6" class="text-center text-muted py-5"><i class="ti ti-users fs-1 d-block mb-2 opacity-40"></i> ${ssrInterpolate(__props.t.empty)}</td></tr>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--[-->`);
      ssrRenderList(__props.users.data, (u) => {
        _push(`<tr class="${ssrRenderClass({ "table-secondary opacity-75": u.deleted })}"><td class="text-muted small">${ssrInterpolate(u.created_at)}</td><td><div class="d-flex align-items-center gap-2"><img${ssrRenderAttr("src", u.photo_url)}${ssrRenderAttr("alt", u.name)} class="rounded-circle flex-shrink-0" style="${ssrRenderStyle({ "width": "28px", "height": "28px", "object-fit": "cover" })}"><span class="fw-medium">${ssrInterpolate(u.name)}</span>`);
        if (u.is_owner) {
          _push(`<span class="badge badge-soft-warning rounded fs-11 ms-1"><i class="ti ti-crown me-1"></i>${ssrInterpolate(__props.t.badge_owner)}</span>`);
        } else {
          _push(`<!---->`);
        }
        if (u.deleted) {
          _push(`<i class="ti ti-trash text-danger ms-1 fs-12"></i>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div></td><td class="text-muted small">${ssrInterpolate(u.email)}</td><td><span class="badge badge-soft-secondary rounded fs-12">${ssrInterpolate(u.rule_label)}</span></td><td class="text-center">`);
        if (u.deleted) {
          _push(`<span class="badge badge-soft-secondary rounded fs-13">${ssrInterpolate(__props.t.status_deleted)}</span>`);
        } else if (u.active) {
          _push(`<span class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium">${ssrInterpolate(__props.t.status_active)}</span>`);
        } else {
          _push(`<span class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium">${ssrInterpolate(__props.t.status_inactive)}</span>`);
        }
        _push(`</td><td class="text-end">`);
        _push(ssrRenderComponent(ActionIconGroup, {
          align: "end",
          gap: "tight"
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              if (u.mode === "restore") {
                _push2(ssrRenderComponent(_sfc_main$1, {
                  icon: "ti ti-recycle",
                  title: __props.t.btn_restore,
                  onClick: ($event) => _ctx.$emit("restore", u.id)
                }, null, _parent2, _scopeId));
              } else if (u.mode === "full") {
                _push2(`<!--[-->`);
                _push2(ssrRenderComponent(_sfc_main$1, {
                  icon: "ti ti-edit",
                  title: __props.t.btn_edit,
                  onClick: ($event) => _ctx.$emit("edit", u.id)
                }, null, _parent2, _scopeId));
                if (!u.is_owner && !u.is_self) {
                  _push2(ssrRenderComponent(ActionDropdown, {
                    "btn-class": "ee-action-icon ee-action-icon--default",
                    icon: "ti ti-dots-vertical"
                  }, {
                    default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                      if (_push3) {
                        _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1"${_scopeId2}><i class="${ssrRenderClass(`ti me-1 ${u.active ? "ti-lock-open" : "ti-lock"}`)}"${_scopeId2}></i> ${ssrInterpolate(u.active ? __props.t.btn_deactivate : __props.t.btn_activate)}</button></li><li${_scopeId2}><hr class="dropdown-divider"${_scopeId2}></li><li${_scopeId2}><button class="dropdown-item rounded-1 text-danger"${_scopeId2}><i class="ti ti-trash me-1"${_scopeId2}></i>${ssrInterpolate(__props.t.btn_delete)}</button></li>`);
                      } else {
                        return [
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1",
                              onClick: ($event) => _ctx.$emit("toggle-active", u.id, u.active)
                            }, [
                              createVNode("i", {
                                class: `ti me-1 ${u.active ? "ti-lock-open" : "ti-lock"}`
                              }, null, 2),
                              createTextVNode(" " + toDisplayString(u.active ? __props.t.btn_deactivate : __props.t.btn_activate), 1)
                            ], 8, ["onClick"])
                          ]),
                          createVNode("li", null, [
                            createVNode("hr", { class: "dropdown-divider" })
                          ]),
                          createVNode("li", null, [
                            createVNode("button", {
                              class: "dropdown-item rounded-1 text-danger",
                              onClick: ($event) => _ctx.$emit("delete", u.id)
                            }, [
                              createVNode("i", { class: "ti ti-trash me-1" }),
                              createTextVNode(toDisplayString(__props.t.btn_delete), 1)
                            ], 8, ["onClick"])
                          ])
                        ];
                      }
                    }),
                    _: 2
                  }, _parent2, _scopeId));
                } else {
                  _push2(`<!---->`);
                }
                _push2(`<!--]-->`);
              } else {
                _push2(`<!---->`);
              }
            } else {
              return [
                u.mode === "restore" ? (openBlock(), createBlock(_sfc_main$1, {
                  key: 0,
                  icon: "ti ti-recycle",
                  title: __props.t.btn_restore,
                  onClick: ($event) => _ctx.$emit("restore", u.id)
                }, null, 8, ["title", "onClick"])) : u.mode === "full" ? (openBlock(), createBlock(Fragment, { key: 1 }, [
                  createVNode(_sfc_main$1, {
                    icon: "ti ti-edit",
                    title: __props.t.btn_edit,
                    onClick: ($event) => _ctx.$emit("edit", u.id)
                  }, null, 8, ["title", "onClick"]),
                  !u.is_owner && !u.is_self ? (openBlock(), createBlock(ActionDropdown, {
                    key: 0,
                    "btn-class": "ee-action-icon ee-action-icon--default",
                    icon: "ti ti-dots-vertical"
                  }, {
                    default: withCtx(() => [
                      createVNode("li", null, [
                        createVNode("button", {
                          class: "dropdown-item rounded-1",
                          onClick: ($event) => _ctx.$emit("toggle-active", u.id, u.active)
                        }, [
                          createVNode("i", {
                            class: `ti me-1 ${u.active ? "ti-lock-open" : "ti-lock"}`
                          }, null, 2),
                          createTextVNode(" " + toDisplayString(u.active ? __props.t.btn_deactivate : __props.t.btn_activate), 1)
                        ], 8, ["onClick"])
                      ]),
                      createVNode("li", null, [
                        createVNode("hr", { class: "dropdown-divider" })
                      ]),
                      createVNode("li", null, [
                        createVNode("button", {
                          class: "dropdown-item rounded-1 text-danger",
                          onClick: ($event) => _ctx.$emit("delete", u.id)
                        }, [
                          createVNode("i", { class: "ti ti-trash me-1" }),
                          createTextVNode(toDisplayString(__props.t.btn_delete), 1)
                        ], 8, ["onClick"])
                      ])
                    ]),
                    _: 2
                  }, 1024)) : createCommentVNode("", true)
                ], 64)) : createCommentVNode("", true)
              ];
            }
          }),
          _: 2
        }, _parent));
        _push(`</td></tr>`);
      });
      _push(`<!--]--></tbody></table></div>`);
      if (__props.users.last_page > 1) {
        _push(`<div class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2"><p class="text-muted small mb-0">${ssrInterpolate(showing.value)}</p><nav><ul class="pagination pagination-sm mb-0"><li class="${ssrRenderClass([{ disabled: __props.users.current_page === 1 }, "page-item"])}">`);
        _push(ssrRenderComponent(unref(Link), {
          class: "page-link",
          href: __props.users.prev_page_url ?? "#",
          "preserve-scroll": "",
          "preserve-state": ""
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(`<i class="ti ti-arrow-left"${_scopeId}></i>`);
            } else {
              return [
                createVNode("i", { class: "ti ti-arrow-left" })
              ];
            }
          }),
          _: 1
        }, _parent));
        _push(`</li><!--[-->`);
        ssrRenderList(__props.users.links.slice(1, -1), (link) => {
          _push(`<li class="${ssrRenderClass([{ active: link.active, disabled: !link.url }, "page-item"])}">`);
          _push(ssrRenderComponent(unref(Link), {
            class: "page-link",
            href: link.url ?? "#",
            "preserve-scroll": "",
            "preserve-state": ""
          }, null, _parent));
          _push(`</li>`);
        });
        _push(`<!--]--><li class="${ssrRenderClass([{ disabled: __props.users.current_page === __props.users.last_page }, "page-item"])}">`);
        _push(ssrRenderComponent(unref(Link), {
          class: "page-link",
          href: __props.users.next_page_url ?? "#",
          "preserve-scroll": "",
          "preserve-state": ""
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(`<i class="ti ti-arrow-right"${_scopeId}></i>`);
            } else {
              return [
                createVNode("i", { class: "ti ti-arrow-right" })
              ];
            }
          }),
          _: 1
        }, _parent));
        _push(`</li></ul></nav></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Users/UserTable.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
