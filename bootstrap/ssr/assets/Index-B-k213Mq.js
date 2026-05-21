import { ref, watch, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, unref, withDirectives, openBlock, createBlock, Fragment, renderList, vModelSelect, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderStyle, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrRenderAttr } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import { _ as _sfc_main$2 } from "./SearchInput-DBX1PwVy.js";
import _sfc_main$3 from "./ReportSettingTable-DSwXSZsy.js";
import _sfc_main$4 from "./ReportSettingCards-DIHioxoj.js";
import ReportSettingFormModal from "./ReportSettingFormModal-C2lSD68w.js";
import ReportSettingPreviewModal from "./ReportSettingPreviewModal-C-aXd_yB.js";
import { _ as _sfc_main$5 } from "./ConfirmationWithReasonModal-CmfO7qbN.js";
import { u as useConfirmationWithReason } from "./useConfirmationWithReason-DDlQOe6J.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./SortableTh-B7Fp64cd.js";
import "./TablePagination-Dj1_H7YG.js";
import "./ActionDropdown-DZW_71Hn.js";
import "./ActionIconGroup-Dj2wQrik.js";
import "./CardsPagination-B87u3Z8A.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    reportSettings: { type: Object, required: true },
    total: { type: Number, default: 0 },
    categories: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    paperSizes: { type: Array, default: () => [] },
    fontFamilies: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    cardsUrl: { type: String, required: true },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const view = ref(localStorage.getItem("mgr_report_settings_view") ?? "table");
    function setView(v) {
      view.value = v;
      localStorage.setItem("mgr_report_settings_view", v);
    }
    const search = ref(props.filters.search ?? "");
    const statusFilter = ref(props.filters.status ?? "");
    const categoryFilter = ref(props.filters.category_id ?? "");
    let searchTimer = null;
    function applyFilters() {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        router.get(
          route("manager.report-settings.index"),
          {
            search: search.value,
            status: statusFilter.value,
            category_id: categoryFilter.value,
            sort: props.filters.sort,
            direction: props.filters.direction
          },
          { preserveState: true, preserveScroll: true, replace: true }
        );
      }, 400);
    }
    watch(search, applyFilters);
    watch(statusFilter, applyFilters);
    watch(categoryFilter, applyFilters);
    function onSort({ sort, direction }) {
      router.get(
        route("manager.report-settings.index"),
        {
          search: search.value,
          status: statusFilter.value,
          category_id: categoryFilter.value,
          sort,
          direction
        },
        { preserveState: true, preserveScroll: true }
      );
    }
    const formOpen = ref(false);
    const recordId = ref(null);
    function openCreate() {
      recordId.value = null;
      formOpen.value = true;
    }
    function openEdit(id) {
      recordId.value = id;
      formOpen.value = true;
    }
    function closeForm() {
      formOpen.value = false;
      recordId.value = null;
    }
    const previewOpen = ref(false);
    const previewUrl = ref(null);
    function openPreview(r) {
      previewUrl.value = r.preview_url;
      previewOpen.value = true;
    }
    function closePreview() {
      previewOpen.value = false;
      previewUrl.value = null;
    }
    const { state: reasonModal, open: openReasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();
    function onPublish(r) {
      openReasonModal({
        title: props.t.confirm_publish_title ?? "Publicar modelo",
        message: props.t.confirm_publish ?? "",
        confirmVariant: "primary",
        async onConfirm(reason) {
          await postActionWithReason(r.publish_url, "POST", reason);
        }
      });
    }
    function onArchive(r) {
      openReasonModal({
        title: props.t.confirm_archive_title ?? "Arquivar modelo",
        message: props.t.confirm_archive ?? "",
        confirmVariant: "warning",
        async onConfirm(reason) {
          await postActionWithReason(r.archive_url, "POST", reason);
        }
      });
    }
    function onDelete(r) {
      openReasonModal({
        title: props.t.confirm_delete_title ?? "Excluir modelo",
        message: props.t.confirm_delete ?? "",
        confirmVariant: "danger",
        async onConfirm(reason) {
          await postActionWithReason(route("manager.report-settings.destroy", r.id), "DELETE", reason);
        }
      });
    }
    async function postActionWithReason(url, method, reason) {
      var _a;
      const res = await fetch(url, {
        method,
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
        },
        body: JSON.stringify({ reason })
      });
      const json = await res.json();
      if (res.ok) {
        showToast(json.message, "success");
        router.reload({ only: ["reportSettings", "total"] });
      } else {
        showToast(json.message ?? "Erro", "error");
      }
    }
    function showToast(msg, type = "success") {
      if (type === "success" && window.showSuccessToast) return window.showSuccessToast(msg);
      if (type === "error" && window.showErrorToast) return window.showErrorToast(msg);
      alert(msg);
    }
    const breadcrumbs = [
      { label: props.t.breadcrumb_home ?? "Dashboard", url: route("panel.dashboard"), active: false },
      { label: props.t.breadcrumb_current ?? "Modelos de Documento", url: "#", active: true }
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
              onSetView: setView
            }, {
              actions: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<button type="button" class="btn btn-primary btn-md fs-13"${_scopeId2}><i class="ti ti-plus me-1"${_scopeId2}></i> ${ssrInterpolate(__props.t.new)}</button>`);
                } else {
                  return [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary btn-md fs-13",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode(" " + toDisplayString(__props.t.new), 1)
                    ])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<div class="d-flex flex-wrap gap-2 mb-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              modelValue: search.value,
              "onUpdate:modelValue": ($event) => search.value = $event,
              placeholder: __props.t.search_placeholder,
              "max-width": "320px"
            }, null, _parent2, _scopeId));
            _push2(`<select class="form-select form-select-sm" style="${ssrRenderStyle({ "max-width": "180px" })}"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(statusFilter.value) ? ssrLooseContain(statusFilter.value, "") : ssrLooseEqual(statusFilter.value, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.all_statuses)}</option><!--[-->`);
            ssrRenderList(__props.statuses, (s) => {
              _push2(`<option${ssrRenderAttr("value", s.value)}${ssrIncludeBooleanAttr(Array.isArray(statusFilter.value) ? ssrLooseContain(statusFilter.value, s.value) : ssrLooseEqual(statusFilter.value, s.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(s.label)}</option>`);
            });
            _push2(`<!--]--></select><select class="form-select form-select-sm" style="${ssrRenderStyle({ "max-width": "200px" })}"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(categoryFilter.value) ? ssrLooseContain(categoryFilter.value, "") : ssrLooseEqual(categoryFilter.value, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.all_categories)}</option><!--[-->`);
            ssrRenderList(__props.categories, (c) => {
              _push2(`<option${ssrRenderAttr("value", c.id)}${ssrIncludeBooleanAttr(Array.isArray(categoryFilter.value) ? ssrLooseContain(categoryFilter.value, c.id) : ssrLooseEqual(categoryFilter.value, c.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(c.name)}</option>`);
            });
            _push2(`<!--]--></select></div>`);
            if (view.value === "table") {
              _push2(ssrRenderComponent(_sfc_main$3, {
                "report-settings": __props.reportSettings,
                filters: __props.filters,
                t: __props.t,
                onSort,
                onEdit: openEdit,
                onPreview: openPreview,
                onPublish,
                onArchive,
                onDelete
              }, null, _parent2, _scopeId));
            } else {
              _push2(ssrRenderComponent(_sfc_main$4, {
                "cards-url": __props.cardsUrl,
                "initial-search": search.value,
                "initial-status": statusFilter.value,
                "initial-category": categoryFilter.value,
                t: __props.t,
                onEdit: openEdit,
                onPreview: openPreview,
                onPublish,
                onArchive,
                onDelete
              }, null, _parent2, _scopeId));
            }
            _push2(`</div>`);
            _push2(ssrRenderComponent(ReportSettingFormModal, {
              open: formOpen.value,
              "record-id": recordId.value,
              categories: __props.categories,
              "paper-sizes": __props.paperSizes,
              "font-families": __props.fontFamilies,
              t: __props.t,
              onClose: closeForm,
              onSaved: closeForm
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(ReportSettingPreviewModal, {
              open: previewOpen.value,
              "preview-url": previewUrl.value,
              t: __props.t,
              onClose: closePreview
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$5, {
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
                  onSetView: setView
                }, {
                  actions: withCtx(() => [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary btn-md fs-13",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode(" " + toDisplayString(__props.t.new), 1)
                    ])
                  ]),
                  _: 1
                }, 8, ["title", "total", "view", "view-table-title", "view-cards-title"]),
                createVNode("div", { class: "d-flex flex-wrap gap-2 mb-3" }, [
                  createVNode(_sfc_main$2, {
                    modelValue: search.value,
                    "onUpdate:modelValue": ($event) => search.value = $event,
                    placeholder: __props.t.search_placeholder,
                    "max-width": "320px"
                  }, null, 8, ["modelValue", "onUpdate:modelValue", "placeholder"]),
                  withDirectives(createVNode("select", {
                    "onUpdate:modelValue": ($event) => statusFilter.value = $event,
                    class: "form-select form-select-sm",
                    style: { "max-width": "180px" }
                  }, [
                    createVNode("option", { value: "" }, toDisplayString(__props.t.all_statuses), 1),
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.statuses, (s) => {
                      return openBlock(), createBlock("option", {
                        key: s.value,
                        value: s.value
                      }, toDisplayString(s.label), 9, ["value"]);
                    }), 128))
                  ], 8, ["onUpdate:modelValue"]), [
                    [vModelSelect, statusFilter.value]
                  ]),
                  withDirectives(createVNode("select", {
                    "onUpdate:modelValue": ($event) => categoryFilter.value = $event,
                    class: "form-select form-select-sm",
                    style: { "max-width": "200px" }
                  }, [
                    createVNode("option", { value: "" }, toDisplayString(__props.t.all_categories), 1),
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.categories, (c) => {
                      return openBlock(), createBlock("option", {
                        key: c.id,
                        value: c.id
                      }, toDisplayString(c.name), 9, ["value"]);
                    }), 128))
                  ], 8, ["onUpdate:modelValue"]), [
                    [vModelSelect, categoryFilter.value]
                  ])
                ]),
                view.value === "table" ? (openBlock(), createBlock(_sfc_main$3, {
                  key: 0,
                  "report-settings": __props.reportSettings,
                  filters: __props.filters,
                  t: __props.t,
                  onSort,
                  onEdit: openEdit,
                  onPreview: openPreview,
                  onPublish,
                  onArchive,
                  onDelete
                }, null, 8, ["report-settings", "filters", "t"])) : (openBlock(), createBlock(_sfc_main$4, {
                  key: 1,
                  "cards-url": __props.cardsUrl,
                  "initial-search": search.value,
                  "initial-status": statusFilter.value,
                  "initial-category": categoryFilter.value,
                  t: __props.t,
                  onEdit: openEdit,
                  onPreview: openPreview,
                  onPublish,
                  onArchive,
                  onDelete
                }, null, 8, ["cards-url", "initial-search", "initial-status", "initial-category", "t"]))
              ]),
              createVNode(ReportSettingFormModal, {
                open: formOpen.value,
                "record-id": recordId.value,
                categories: __props.categories,
                "paper-sizes": __props.paperSizes,
                "font-families": __props.fontFamilies,
                t: __props.t,
                onClose: closeForm,
                onSaved: closeForm
              }, null, 8, ["open", "record-id", "categories", "paper-sizes", "font-families", "t"]),
              createVNode(ReportSettingPreviewModal, {
                open: previewOpen.value,
                "preview-url": previewUrl.value,
                t: __props.t,
                onClose: closePreview
              }, null, 8, ["open", "preview-url", "t"]),
              createVNode(_sfc_main$5, {
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/ReportSettings/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
