import { ref, watch, onMounted, onUnmounted, mergeProps, withCtx, openBlock, createBlock, createVNode, createTextVNode, toDisplayString, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderStyle, ssrRenderClass } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { _ as _sfc_main$1, a as _sfc_main$3 } from "./CardsPagination-B87u3Z8A.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { _ as _sfc_main$2 } from "./ActionIconButton-BTsQtzdl.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "ReportSettingCards",
  __ssrInlineRender: true,
  props: {
    cardsUrl: { type: String, required: true },
    initialSearch: { type: String, default: "" },
    initialStatus: { type: String, default: "" },
    initialCategory: { type: String, default: "" },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["edit", "preview", "publish", "archive", "delete"],
  setup(__props, { expose: __expose, emit: __emit }) {
    const props = __props;
    const records = ref([]);
    const meta = ref({ current_page: 1, last_page: 1 });
    const loading = ref(false);
    async function fetchCards(page = 1) {
      loading.value = true;
      try {
        const params = new URLSearchParams({
          page,
          search: props.initialSearch,
          status: props.initialStatus,
          category_id: props.initialCategory
        });
        const json = await fetch(`${props.cardsUrl}?${params}`).then((r) => r.json());
        records.value = json.data;
        meta.value = json.meta;
      } finally {
        loading.value = false;
      }
    }
    watch(
      () => [props.initialSearch, props.initialStatus, props.initialCategory],
      () => fetchCards(1)
    );
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
        if (records.value.length === 0) {
          _push(`<div class="text-center text-muted py-5"><i class="ti ti-file-off fa-3x mb-3 d-block opacity-25 fs-1"></i><p>${ssrInterpolate(__props.t.empty_list)}</p></div>`);
        } else {
          _push(`<div class="row g-3"><!--[-->`);
          ssrRenderList(records.value, (r) => {
            var _a;
            _push(`<div class="col-sm-6 col-xl-4"><div class="card card-body h-100"><div class="d-flex align-items-start gap-3"><div class="rounded d-flex align-items-center justify-content-center flex-shrink-0 bg-primary-subtle" style="${ssrRenderStyle({ "width": "44px", "height": "44px" })}"><i class="ti ti-file-text fs-18 text-primary"></i></div><div class="flex-grow-1 min-w-0"><h6 class="mb-1 fw-semibold text-truncate">${ssrInterpolate(r.title)}</h6><div class="mb-2 d-flex flex-wrap gap-1"><span class="${ssrRenderClass([r.status_badge, "badge"])}">${ssrInterpolate(r.status_label)}</span>`);
            if (r.category) {
              _push(`<span class="badge badge-soft-secondary rounded fs-12">${ssrInterpolate(r.category)}</span>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div><div class="small text-muted">`);
            if (r.paper_size) {
              _push(`<div><strong>Papel:</strong> ${ssrInterpolate(r.paper_size)}</div>`);
            } else {
              _push(`<!---->`);
            }
            _push(`<div><strong>v${ssrInterpolate(r.version)}</strong>`);
            if (r.adopted_count) {
              _push(`<span class="ms-2 text-muted"> · ${ssrInterpolate(r.adopted_count)} ${ssrInterpolate((_a = __props.t.col_adoptions) == null ? void 0 : _a.toLowerCase())}</span>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div></div><div class="d-flex flex-wrap gap-1 mt-2">`);
            if (r.show_header) {
              _push(`<span class="badge badge-soft-primary rounded text-primary border border-primary fs-11"><i class="ti ti-layout-navbar me-1"></i>${ssrInterpolate(__props.t.tab_header)}</span>`);
            } else {
              _push(`<!---->`);
            }
            if (r.show_signature) {
              _push(`<span class="badge badge-soft-info rounded text-info border border-info fs-11"><i class="ti ti-signature me-1"></i>${ssrInterpolate(__props.t.tab_signature)}</span>`);
            } else {
              _push(`<!---->`);
            }
            if (r.show_footer) {
              _push(`<span class="badge badge-soft-secondary rounded fs-11"><i class="ti ti-layout-bottombar me-1"></i>${ssrInterpolate(__props.t.tab_footer)}</span>`);
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
                  _push2(ssrRenderComponent(_sfc_main$2, {
                    icon: "ti ti-file-search",
                    title: __props.t.action_preview,
                    onClick: ($event) => _ctx.$emit("preview", r)
                  }, null, _parent2, _scopeId));
                  _push2(ssrRenderComponent(_sfc_main$2, {
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
                    createVNode(_sfc_main$2, {
                      icon: "ti ti-file-search",
                      title: __props.t.action_preview,
                      onClick: ($event) => _ctx.$emit("preview", r)
                    }, null, 8, ["title", "onClick"]),
                    createVNode(_sfc_main$2, {
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
            _push(`</div></div>`);
          });
          _push(`<!--]--></div>`);
        }
        _push(ssrRenderComponent(_sfc_main$3, {
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/ReportSettings/ReportSettingCards.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
