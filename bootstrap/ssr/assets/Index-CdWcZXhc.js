import { ref, watch, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, openBlock, createBlock, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import { _ as _sfc_main$2 } from "./SearchInput-DBX1PwVy.js";
import _sfc_main$3 from "./UserTable-5QinWueF.js";
import _sfc_main$4 from "./UserCards-D70gWRgy.js";
import UserFormModal from "./UserFormModal-D_Mk1lpR.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./SortableTh-B7Fp64cd.js";
import "./ActionDropdown-DZW_71Hn.js";
import "./ActionIconButton-BTsQtzdl.js";
import "./ActionIconGroup-B8JEjj1z.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    users: { type: Object, required: true },
    total: { type: Number, default: 0 },
    roles: { type: Object, default: () => ({}) },
    isClient: { type: Boolean, default: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const view = ref(localStorage.getItem("users_view") ?? "table");
    function setView(v) {
      view.value = v;
      localStorage.setItem("users_view", v);
    }
    const search = ref(props.filters.search ?? "");
    let searchTimer = null;
    watch(search, (val) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        router.get(
          route("panel.accesscontrol.users.index"),
          { search: val, sort: props.filters.sort, direction: props.filters.direction },
          { preserveState: true, preserveScroll: true, replace: true }
        );
      }, 400);
    });
    function onSort({ sort, direction }) {
      router.get(
        route("panel.accesscontrol.users.index"),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true }
      );
    }
    const modalOpen = ref(false);
    const editUserId = ref(null);
    function openCreate() {
      editUserId.value = null;
      modalOpen.value = true;
    }
    function openEdit(id) {
      editUserId.value = id;
      modalOpen.value = true;
    }
    function closeModal() {
      modalOpen.value = false;
      editUserId.value = null;
    }
    function onDelete(id) {
      if (!confirm(props.t.confirm_delete)) return;
      router.delete(route("panel.accesscontrol.users.destroy", id), { preserveScroll: true });
    }
    function onRestore(id) {
      if (!confirm(props.t.confirm_restore)) return;
      router.get(route("panel.accesscontrol.users.restore", id), {}, { preserveScroll: true });
    }
    function onToggleActive(id, currentActive) {
      router.put(
        route("panel.accesscontrol.users.update", id),
        { active: !currentActive, type_method: "toggle" },
        { preserveScroll: true }
      );
    }
    const breadcrumbs = [
      { label: props.t.breadcrumb_home ?? "Dashboard", url: route("panel.dashboard") },
      { label: props.t.breadcrumb_current ?? "Usuários" }
    ];
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.page_title,
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              title: __props.t.page_title,
              total: __props.total,
              view: view.value,
              "view-table-title": __props.t.view_table,
              "view-cards-title": __props.t.view_cards,
              "show-view-toggle": true,
              onSetView: setView
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<button type="button" class="btn btn-primary btn-sm"${_scopeId2}><i class="ti ti-plus me-1"${_scopeId2}></i>${ssrInterpolate(__props.t.new_user)}</button>`);
                } else {
                  return [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary btn-sm",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode(toDisplayString(__props.t.new_user), 1)
                    ])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<div class="mb-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              modelValue: search.value,
              "onUpdate:modelValue": ($event) => search.value = $event,
              placeholder: __props.t.search_placeholder,
              "max-width": "340px"
            }, null, _parent2, _scopeId));
            _push2(`</div>`);
            if (view.value === "table") {
              _push2(ssrRenderComponent(_sfc_main$3, {
                users: __props.users,
                filters: __props.filters,
                t: __props.t,
                onSort,
                onEdit: openEdit,
                onDelete,
                onRestore,
                onToggleActive
              }, null, _parent2, _scopeId));
            } else {
              _push2(ssrRenderComponent(_sfc_main$4, {
                "cards-url": _ctx.route("panel.accesscontrol.users.cards"),
                "initial-search": search.value,
                t: __props.t,
                onEdit: openEdit,
                onDelete,
                onRestore,
                onToggleActive
              }, null, _parent2, _scopeId));
            }
            _push2(`</div>`);
            _push2(ssrRenderComponent(UserFormModal, {
              open: modalOpen.value,
              "user-id": editUserId.value,
              roles: __props.roles,
              "is-client": __props.isClient,
              t: __props.t,
              onClose: closeModal
            }, null, _parent2, _scopeId));
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, {
                  title: __props.t.page_title,
                  total: __props.total,
                  view: view.value,
                  "view-table-title": __props.t.view_table,
                  "view-cards-title": __props.t.view_cards,
                  "show-view-toggle": true,
                  onSetView: setView
                }, {
                  actions: withCtx(() => [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary btn-sm",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode(toDisplayString(__props.t.new_user), 1)
                    ])
                  ]),
                  _: 1
                }, 8, ["title", "total", "view", "view-table-title", "view-cards-title"]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode(_sfc_main$2, {
                    modelValue: search.value,
                    "onUpdate:modelValue": ($event) => search.value = $event,
                    placeholder: __props.t.search_placeholder,
                    "max-width": "340px"
                  }, null, 8, ["modelValue", "onUpdate:modelValue", "placeholder"])
                ]),
                view.value === "table" ? (openBlock(), createBlock(_sfc_main$3, {
                  key: 0,
                  users: __props.users,
                  filters: __props.filters,
                  t: __props.t,
                  onSort,
                  onEdit: openEdit,
                  onDelete,
                  onRestore,
                  onToggleActive
                }, null, 8, ["users", "filters", "t"])) : (openBlock(), createBlock(_sfc_main$4, {
                  key: 1,
                  "cards-url": _ctx.route("panel.accesscontrol.users.cards"),
                  "initial-search": search.value,
                  t: __props.t,
                  onEdit: openEdit,
                  onDelete,
                  onRestore,
                  onToggleActive
                }, null, 8, ["cards-url", "initial-search", "t"]))
              ]),
              createVNode(UserFormModal, {
                open: modalOpen.value,
                "user-id": editUserId.value,
                roles: __props.roles,
                "is-client": __props.isClient,
                t: __props.t,
                onClose: closeModal
              }, null, 8, ["open", "user-id", "roles", "is-client", "t"])
            ];
          }
        }),
        _: 1
      }, _parent));
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Users/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
