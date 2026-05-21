import { computed, withCtx, createTextVNode, toDisplayString, openBlock, createBlock, createVNode, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderStyle, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { S as SortableTh } from "./SortableTh-B7Fp64cd.js";
import { _ as _sfc_main$2 } from "./TablePagination-Dj1_H7YG.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { A as ActionIconGroup, _ as _sfc_main$1 } from "./ActionIconGroup-Dj2wQrik.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "@inertiajs/vue3";
const _sfc_main = {
  __name: "ReportSettingTable",
  __ssrInlineRender: true,
  props: {
    reportSettings: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["sort", "edit", "preview", "publish", "archive", "delete"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const currentSort = computed(() => props.filters.sort ?? "title");
    const currentDir = computed(() => props.filters.direction ?? "asc");
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[--><div class="table-responsive"><table class="table table-nowrap table-hover align-middle mb-0"><thead class="table-light"><tr>`);
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "title",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort: ($event) => _ctx.$emit("sort", $event)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_title)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_title), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "category",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort: ($event) => _ctx.$emit("sort", $event)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_category)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_category), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<th class="text-center" style="${ssrRenderStyle({ "min-width": "70px" })}">${ssrInterpolate(__props.t.col_paper_size)}</th><th class="text-center" style="${ssrRenderStyle({ "min-width": "90px" })}">${ssrInterpolate(__props.t.col_header)}</th><th class="text-center" style="${ssrRenderStyle({ "min-width": "90px" })}">${ssrInterpolate(__props.t.col_signature)}</th><th class="text-center" style="${ssrRenderStyle({ "min-width": "80px" })}">${ssrInterpolate(__props.t.col_footer)}</th>`);
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "status",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        onSort: ($event) => _ctx.$emit("sort", $event)
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
      _push(ssrRenderComponent(SortableTh, {
        "col-key": "version",
        "current-sort": currentSort.value,
        "current-dir": currentDir.value,
        class: "text-center",
        onSort: ($event) => _ctx.$emit("sort", $event)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`${ssrInterpolate(__props.t.col_version)}`);
          } else {
            return [
              createTextVNode(toDisplayString(__props.t.col_version), 1)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<th class="text-center" style="${ssrRenderStyle({ "min-width": "70px" })}">${ssrInterpolate(__props.t.col_adoptions)}</th><th class="text-end" style="${ssrRenderStyle({ "min-width": "130px" })}">${ssrInterpolate(__props.t.col_actions)}</th></tr></thead><tbody>`);
      if (__props.reportSettings.data.length === 0) {
        _push(`<tr><td colspan="10" class="text-center text-muted py-5"><i class="ti ti-file-off fs-1 mb-2 d-block opacity-50"></i> ${ssrInterpolate(__props.t.empty_list)}</td></tr>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<!--[-->`);
      ssrRenderList(__props.reportSettings.data, (r) => {
        _push(`<tr><td><div class="fw-semibold">${ssrInterpolate(r.title)}</div>`);
        if (r.description) {
          _push(`<div class="text-muted small text-truncate" style="${ssrRenderStyle({ "max-width": "220px" })}">${ssrInterpolate(r.description)}</div>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</td><td class="text-muted small">${ssrInterpolate(r.category ?? "—")}</td><td class="text-center"><span class="badge badge-soft-secondary rounded fs-12">${ssrInterpolate(r.paper_size ?? "—")}</span></td><td class="text-center"><span class="${ssrRenderClass(r.show_header ? "badge badge-soft-primary rounded text-primary border border-primary fs-12" : "badge badge-soft-secondary rounded fs-12")}">${ssrInterpolate(r.show_header ? __props.t.badge_yes : __props.t.badge_no)}</span></td><td class="text-center"><span class="${ssrRenderClass(r.show_signature ? "badge badge-soft-primary rounded text-primary border border-primary fs-12" : "badge badge-soft-secondary rounded fs-12")}">${ssrInterpolate(r.show_signature ? __props.t.badge_yes : __props.t.badge_no)}</span></td><td class="text-center"><span class="${ssrRenderClass(r.show_footer ? "badge badge-soft-primary rounded text-primary border border-primary fs-12" : "badge badge-soft-secondary rounded fs-12")}">${ssrInterpolate(r.show_footer ? __props.t.badge_yes : __props.t.badge_no)}</span></td><td><span class="${ssrRenderClass([r.status_badge, "badge"])}">${ssrInterpolate(r.status_label)}</span></td><td class="text-center text-muted small">v${ssrInterpolate(r.version)}</td><td class="text-center text-muted small">${ssrInterpolate(r.adopted_count)}</td><td class="text-end">`);
        _push(ssrRenderComponent(ActionIconGroup, {
          align: "end",
          gap: "tight"
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(ssrRenderComponent(_sfc_main$1, {
                icon: "ti ti-file-search",
                title: __props.t.action_preview,
                onClick: ($event) => _ctx.$emit("preview", r)
              }, null, _parent2, _scopeId));
              _push2(ssrRenderComponent(_sfc_main$1, {
                icon: "ti ti-edit",
                title: __props.t.action_edit,
                onClick: ($event) => _ctx.$emit("edit", r.id)
              }, null, _parent2, _scopeId));
              _push2(ssrRenderComponent(ActionDropdown, {
                "min-width": 160,
                "btn-class": "ee-action-icon ee-action-icon--default",
                icon: "ti ti-dots-vertical"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    if (r.status === "draft" || r.status === "archived") {
                      _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1 text-success"${_scopeId2}><i class="ti ti-send me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_publish)}</button></li>`);
                    } else {
                      _push3(`<!---->`);
                    }
                    if (r.status === "published") {
                      _push3(`<li${_scopeId2}><button class="dropdown-item rounded-1 text-warning"${_scopeId2}><i class="ti ti-archive me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_archive)}</button></li>`);
                    } else {
                      _push3(`<!---->`);
                    }
                    _push3(`<li${_scopeId2}><hr class="dropdown-divider my-1"${_scopeId2}></li><li${_scopeId2}><button class="dropdown-item rounded-1 text-danger"${_scopeId2}><i class="ti ti-trash me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.action_delete)}</button></li>`);
                  } else {
                    return [
                      r.status === "draft" || r.status === "archived" ? (openBlock(), createBlock("li", { key: 0 }, [
                        createVNode("button", {
                          class: "dropdown-item rounded-1 text-success",
                          onClick: ($event) => _ctx.$emit("publish", r)
                        }, [
                          createVNode("i", { class: "ti ti-send me-1" }),
                          createTextVNode(" " + toDisplayString(__props.t.action_publish), 1)
                        ], 8, ["onClick"])
                      ])) : createCommentVNode("", true),
                      r.status === "published" ? (openBlock(), createBlock("li", { key: 1 }, [
                        createVNode("button", {
                          class: "dropdown-item rounded-1 text-warning",
                          onClick: ($event) => _ctx.$emit("archive", r)
                        }, [
                          createVNode("i", { class: "ti ti-archive me-1" }),
                          createTextVNode(" " + toDisplayString(__props.t.action_archive), 1)
                        ], 8, ["onClick"])
                      ])) : createCommentVNode("", true),
                      createVNode("li", null, [
                        createVNode("hr", { class: "dropdown-divider my-1" })
                      ]),
                      createVNode("li", null, [
                        createVNode("button", {
                          class: "dropdown-item rounded-1 text-danger",
                          onClick: ($event) => _ctx.$emit("delete", r)
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
                createVNode(_sfc_main$1, {
                  icon: "ti ti-file-search",
                  title: __props.t.action_preview,
                  onClick: ($event) => _ctx.$emit("preview", r)
                }, null, 8, ["title", "onClick"]),
                createVNode(_sfc_main$1, {
                  icon: "ti ti-edit",
                  title: __props.t.action_edit,
                  onClick: ($event) => _ctx.$emit("edit", r.id)
                }, null, 8, ["title", "onClick"]),
                createVNode(ActionDropdown, {
                  "min-width": 160,
                  "btn-class": "ee-action-icon ee-action-icon--default",
                  icon: "ti ti-dots-vertical"
                }, {
                  default: withCtx(() => [
                    r.status === "draft" || r.status === "archived" ? (openBlock(), createBlock("li", { key: 0 }, [
                      createVNode("button", {
                        class: "dropdown-item rounded-1 text-success",
                        onClick: ($event) => _ctx.$emit("publish", r)
                      }, [
                        createVNode("i", { class: "ti ti-send me-1" }),
                        createTextVNode(" " + toDisplayString(__props.t.action_publish), 1)
                      ], 8, ["onClick"])
                    ])) : createCommentVNode("", true),
                    r.status === "published" ? (openBlock(), createBlock("li", { key: 1 }, [
                      createVNode("button", {
                        class: "dropdown-item rounded-1 text-warning",
                        onClick: ($event) => _ctx.$emit("archive", r)
                      }, [
                        createVNode("i", { class: "ti ti-archive me-1" }),
                        createTextVNode(" " + toDisplayString(__props.t.action_archive), 1)
                      ], 8, ["onClick"])
                    ])) : createCommentVNode("", true),
                    createVNode("li", null, [
                      createVNode("hr", { class: "dropdown-divider my-1" })
                    ]),
                    createVNode("li", null, [
                      createVNode("button", {
                        class: "dropdown-item rounded-1 text-danger",
                        onClick: ($event) => _ctx.$emit("delete", r)
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
      _push(ssrRenderComponent(_sfc_main$2, { data: __props.reportSettings }, null, _parent));
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/ReportSettings/ReportSettingTable.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
