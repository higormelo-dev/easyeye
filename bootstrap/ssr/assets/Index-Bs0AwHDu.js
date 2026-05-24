import { ref, watch, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, unref, openBlock, createBlock, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import { _ as _sfc_main$2 } from "./SearchInput-DBX1PwVy.js";
import _sfc_main$3 from "./EntityTable-7ehgfmmi.js";
import _sfc_main$4 from "./EntityCards-BPzC5A1D.js";
import _sfc_main$5 from "./EntityFormModal-LNgXXU2d.js";
import EntityDetailDrawer from "./EntityDetailDrawer-BXKxnTVv.js";
import { _ as _sfc_main$6 } from "./ConfirmationWithReasonModal-CmfO7qbN.js";
import { u as useConfirmationWithReason } from "./useConfirmationWithReason-DDlQOe6J.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./SortableTh-B7Fp64cd.js";
import "./StatusBadge-Du3rSMdo.js";
import "./TablePagination-Dj1_H7YG.js";
import "./ActionDropdown-DZW_71Hn.js";
import "./ActionIconButton-BTsQtzdl.js";
import "./ActionIconGroup-B8JEjj1z.js";
import "./CardsPagination-B87u3Z8A.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    entities: { type: Object, required: true },
    total: { type: Number, default: 0 },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const view = ref(localStorage.getItem("mgr_entities_view") ?? "table");
    function setView(v) {
      view.value = v;
      localStorage.setItem("mgr_entities_view", v);
    }
    const search = ref(props.filters.search ?? "");
    let searchTimer = null;
    watch(search, (val) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        router.get(
          route("manager.entities.index"),
          { search: val, sort: props.filters.sort, direction: props.filters.direction },
          { preserveState: true, preserveScroll: true, replace: true }
        );
      }, 400);
    });
    function onSort({ sort, direction }) {
      router.get(
        route("manager.entities.index"),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true }
      );
    }
    const formOpen = ref(false);
    const editEntityId = ref(null);
    function openCreate() {
      editEntityId.value = null;
      formOpen.value = true;
    }
    function openEdit(id) {
      editEntityId.value = id;
      formOpen.value = true;
    }
    function closeForm() {
      formOpen.value = false;
      editEntityId.value = null;
    }
    const detailOpen = ref(false);
    const detailId = ref(null);
    function openDetail(id) {
      detailId.value = id;
      detailOpen.value = true;
    }
    function closeDetail() {
      detailOpen.value = false;
      detailId.value = null;
    }
    const { state: reasonModal, open: openReasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();
    function onDelete(id) {
      openReasonModal({
        title: props.t.confirm_delete_title ?? props.t.confirm_delete ?? "Excluir empresa",
        message: props.t.confirm_delete_text ?? "Esta ação remove a empresa do sistema (soft delete).",
        confirmVariant: "danger",
        async onConfirm(reason) {
          await new Promise((resolve, reject) => {
            router.delete(route("manager.entities.destroy", id), {
              data: { reason },
              preserveScroll: true,
              onSuccess: resolve,
              onError: reject
            });
          });
        }
      });
    }
    function onToggleActive(id, currentActive) {
      router.put(
        route("manager.entities.update", id),
        { active: !currentActive, type_method: "toggle" },
        { preserveScroll: true }
      );
    }
    const breadcrumbs = [
      { label: props.t.breadcrumb_home ?? "Dashboard", url: route("panel.dashboard"), active: false },
      { label: props.t.breadcrumb_current ?? "Empresas", url: "#", active: true }
    ];
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.page_title,
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div${_scopeId}>`);
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
                  _push3(`<button type="button" class="btn btn-primary fs-13"${_scopeId2}><i class="ti ti-plus me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.btn_new)}</button>`);
                } else {
                  return [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary fs-13",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode(" " + toDisplayString(__props.t.btn_new), 1)
                    ])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$2, {
              modelValue: search.value,
              "onUpdate:modelValue": ($event) => search.value = $event,
              placeholder: __props.t.search_placeholder,
              "max-width": "380px"
            }, null, _parent2, _scopeId));
            if (view.value === "table") {
              _push2(ssrRenderComponent(_sfc_main$3, {
                entities: __props.entities,
                filters: __props.filters,
                t: __props.t,
                onSort,
                onView: openDetail,
                onEdit: openEdit,
                onDelete,
                onToggleActive
              }, null, _parent2, _scopeId));
            } else {
              _push2(ssrRenderComponent(_sfc_main$4, {
                "cards-url": _ctx.route("manager.entities.cards"),
                "initial-search": search.value,
                t: __props.t,
                onView: openDetail,
                onEdit: openEdit,
                onDelete,
                onToggleActive
              }, null, _parent2, _scopeId));
            }
            _push2(`</div>`);
            _push2(ssrRenderComponent(_sfc_main$5, {
              open: formOpen.value,
              "entity-id": editEntityId.value,
              t: __props.t,
              onClose: closeForm
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(EntityDetailDrawer, {
              open: detailOpen.value,
              "entity-id": detailId.value,
              t: __props.t,
              onClose: closeDetail,
              onEdit: (id) => {
                closeDetail();
                openEdit(id);
              }
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$6, {
              open: unref(reasonModal).open,
              title: unref(reasonModal).title,
              message: unref(reasonModal).message,
              "confirm-variant": unref(reasonModal).confirmVariant,
              saving: unref(reasonModal).saving,
              onClose: unref(closeReasonModal),
              onConfirm: unref(handleReasonConfirm)
            }, null, _parent2, _scopeId));
          } else {
            return [
              createVNode("div", null, [
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
                      class: "btn btn-primary fs-13",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode(" " + toDisplayString(__props.t.btn_new), 1)
                    ])
                  ]),
                  _: 1
                }, 8, ["title", "total", "view", "view-table-title", "view-cards-title"]),
                createVNode(_sfc_main$2, {
                  modelValue: search.value,
                  "onUpdate:modelValue": ($event) => search.value = $event,
                  placeholder: __props.t.search_placeholder,
                  "max-width": "380px"
                }, null, 8, ["modelValue", "onUpdate:modelValue", "placeholder"]),
                view.value === "table" ? (openBlock(), createBlock(_sfc_main$3, {
                  key: 0,
                  entities: __props.entities,
                  filters: __props.filters,
                  t: __props.t,
                  onSort,
                  onView: openDetail,
                  onEdit: openEdit,
                  onDelete,
                  onToggleActive
                }, null, 8, ["entities", "filters", "t"])) : (openBlock(), createBlock(_sfc_main$4, {
                  key: 1,
                  "cards-url": _ctx.route("manager.entities.cards"),
                  "initial-search": search.value,
                  t: __props.t,
                  onView: openDetail,
                  onEdit: openEdit,
                  onDelete,
                  onToggleActive
                }, null, 8, ["cards-url", "initial-search", "t"]))
              ]),
              createVNode(_sfc_main$5, {
                open: formOpen.value,
                "entity-id": editEntityId.value,
                t: __props.t,
                onClose: closeForm
              }, null, 8, ["open", "entity-id", "t"]),
              createVNode(EntityDetailDrawer, {
                open: detailOpen.value,
                "entity-id": detailId.value,
                t: __props.t,
                onClose: closeDetail,
                onEdit: (id) => {
                  closeDetail();
                  openEdit(id);
                }
              }, null, 8, ["open", "entity-id", "t", "onEdit"]),
              createVNode(_sfc_main$6, {
                open: unref(reasonModal).open,
                title: unref(reasonModal).title,
                message: unref(reasonModal).message,
                "confirm-variant": unref(reasonModal).confirmVariant,
                saving: unref(reasonModal).saving,
                onClose: unref(closeReasonModal),
                onConfirm: unref(handleReasonConfirm)
              }, null, 8, ["open", "title", "message", "confirm-variant", "saving", "onClose", "onConfirm"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Entities/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
