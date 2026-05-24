import { ref, watch, onMounted, onUnmounted, mergeProps, withCtx, openBlock, createBlock, Fragment, createVNode, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderAttr, ssrRenderStyle, ssrRenderComponent } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./ActionIconButton-BTsQtzdl.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "UserCards",
  __ssrInlineRender: true,
  props: {
    cardsUrl: { type: String, required: true },
    initialSearch: { type: String, default: "" },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["edit", "delete", "restore", "toggle-active"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const users = ref([]);
    const meta = ref({ current_page: 1, last_page: 1, total: 0 });
    const loading = ref(false);
    async function fetchCards(page = 1) {
      loading.value = true;
      try {
        const params = new URLSearchParams({ page, search: props.initialSearch });
        const json = await fetch(`${props.cardsUrl}?${params}`).then((r) => r.json());
        users.value = json.data;
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
        _push(`<div${ssrRenderAttrs(mergeProps({ class: "text-center py-5" }, _attrs))}><div class="spinner-border text-primary" role="status"></div></div>`);
      } else {
        _push(`<!--[-->`);
        if (users.value.length === 0) {
          _push(`<div class="text-center text-muted py-5"><i class="ti ti-users fs-1 mb-2 d-block opacity-40"></i><p class="small mb-0">${ssrInterpolate(__props.t.empty)}</p></div>`);
        } else {
          _push(`<div class="row g-3"><!--[-->`);
          ssrRenderList(users.value, (u) => {
            _push(`<div class="col-sm-6 col-md-4 col-xl-3"><div class="${ssrRenderClass([{ "opacity-75": u.deleted }, "card card-body h-100"])}"><div class="d-flex align-items-center gap-3 mb-2"><img${ssrRenderAttr("src", u.photo_url)}${ssrRenderAttr("alt", u.full_name)} class="rounded-circle flex-shrink-0" style="${ssrRenderStyle({ "width": "44px", "height": "44px", "object-fit": "cover" })}"><div class="min-w-0"><h6 class="mb-0 fw-semibold lh-sm text-truncate">${ssrInterpolate(u.full_name)}</h6><span class="badge badge-soft-secondary rounded fs-12">${ssrInterpolate(u.rule_label)}</span>`);
            if (u.is_owner) {
              _push(`<span class="badge badge-soft-warning rounded fs-11 ms-1"><i class="ti ti-crown me-1"></i>${ssrInterpolate(__props.t.badge_owner)}</span>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div></div><div class="small text-muted mb-2 text-truncate">${ssrInterpolate(u.email)}</div><div class="mb-2">`);
            if (u.deleted) {
              _push(`<span class="badge badge-soft-secondary">${ssrInterpolate(__props.t.status_deleted)}</span>`);
            } else if (u.active) {
              _push(`<span class="badge badge-soft-success text-success border border-success">${ssrInterpolate(__props.t.status_active)}</span>`);
            } else {
              _push(`<span class="badge badge-soft-danger text-danger border border-danger">${ssrInterpolate(__props.t.status_inactive)}</span>`);
            }
            _push(`</div><hr class="my-2">`);
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
                      _push2(`<!--[-->`);
                      _push2(ssrRenderComponent(_sfc_main$1, {
                        icon: `ti ${u.active ? "ti-lock-open" : "ti-lock"}`,
                        title: u.active ? __props.t.btn_deactivate : __props.t.btn_activate,
                        onClick: ($event) => _ctx.$emit("toggle-active", u.id, u.active)
                      }, null, _parent2, _scopeId));
                      _push2(ssrRenderComponent(_sfc_main$1, {
                        icon: "ti ti-trash",
                        title: __props.t.btn_delete,
                        variant: "danger",
                        onClick: ($event) => _ctx.$emit("delete", u.id)
                      }, null, _parent2, _scopeId));
                      _push2(`<!--]-->`);
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
                      !u.is_owner && !u.is_self ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                        createVNode(_sfc_main$1, {
                          icon: `ti ${u.active ? "ti-lock-open" : "ti-lock"}`,
                          title: u.active ? __props.t.btn_deactivate : __props.t.btn_activate,
                          onClick: ($event) => _ctx.$emit("toggle-active", u.id, u.active)
                        }, null, 8, ["icon", "title", "onClick"]),
                        createVNode(_sfc_main$1, {
                          icon: "ti ti-trash",
                          title: __props.t.btn_delete,
                          variant: "danger",
                          onClick: ($event) => _ctx.$emit("delete", u.id)
                        }, null, 8, ["title", "onClick"])
                      ], 64)) : createCommentVNode("", true)
                    ], 64)) : createCommentVNode("", true)
                  ];
                }
              }),
              _: 2
            }, _parent));
            _push(`</div></div>`);
          });
          _push(`<!--]--></div>`);
        }
        if (meta.value.last_page > 1) {
          _push(`<nav class="d-flex justify-content-center mt-3"><ul class="pagination pagination-sm mb-0"><li class="${ssrRenderClass([{ disabled: meta.value.current_page === 1 }, "page-item"])}"><button class="page-link"><i class="ti ti-arrow-left"></i></button></li><!--[-->`);
          ssrRenderList(meta.value.last_page, (p) => {
            _push(`<li class="${ssrRenderClass([{ active: p === meta.value.current_page }, "page-item"])}"><button class="page-link">${ssrInterpolate(p)}</button></li>`);
          });
          _push(`<!--]--><li class="${ssrRenderClass([{ disabled: meta.value.current_page === meta.value.last_page }, "page-item"])}"><button class="page-link"><i class="ti ti-arrow-right"></i></button></li></ul></nav>`);
        } else {
          _push(`<!---->`);
        }
        _push(`<!--]-->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Users/UserCards.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
