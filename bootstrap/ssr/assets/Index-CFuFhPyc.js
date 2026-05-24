import { ref, mergeProps, withCtx, createVNode, toDisplayString, withDirectives, vModelText, vModelSelect, createTextVNode, unref, openBlock, createBlock, Fragment, renderList, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderStyle, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import _sfc_main$3 from "./PartnerFormModal-BqLYDJEQ.js";
import { _ as _sfc_main$1 } from "./LiveStatusBar-CQZOQaZ5.js";
import { _ as _sfc_main$2 } from "./ActionIconButton-BTsQtzdl.js";
import { A as ActionIconGroup } from "./ActionIconGroup-B8JEjj1z.js";
import { A as ActionDropdown } from "./ActionDropdown-DZW_71Hn.js";
import { _ as _sfc_main$4 } from "./ConfirmationWithReasonModal-CmfO7qbN.js";
import { u as useConfirmationWithReason } from "./useConfirmationWithReason-DDlQOe6J.js";
import { u as useDashboardPolling } from "./useDashboardPolling-D1jTH2om.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    partners: { type: Array, default: () => [] },
    funnel: { type: Array, default: () => [] },
    kpis: { type: Object, default: () => ({}) },
    recentCommissions: { type: Array, default: () => [] },
    partnerTypes: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const { isRefreshing, lastUpdated, refresh } = useDashboardPolling(
      ["partners", "kpis", "funnel", "recentCommissions"],
      15e3
    );
    const formOpen = ref(false);
    const editId = ref(null);
    const editDataUrl = ref("");
    const updateUrl = ref("");
    function openCreate() {
      editId.value = null;
      editDataUrl.value = "";
      updateUrl.value = "";
      formOpen.value = true;
    }
    function openEdit(partner) {
      editId.value = partner.id;
      editDataUrl.value = partner.edit_data_url;
      updateUrl.value = partner.update_url;
      formOpen.value = true;
    }
    function closeForm() {
      formOpen.value = false;
      editId.value = null;
    }
    const { state: reasonModal, open: openReasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();
    function onDelete(partner) {
      openReasonModal({
        title: props.t.confirm_delete_title ?? "Excluir parceiro",
        message: props.t.confirm_delete ?? "",
        confirmVariant: "danger",
        async onConfirm(reason) {
          var _a;
          const res = await fetch(partner.destroy_url, {
            method: "DELETE",
            headers: {
              "Accept": "application/json",
              "Content-Type": "application/json",
              "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
            },
            body: JSON.stringify({ reason })
          });
          const json = await res.json();
          showToast(json.message, res.ok ? "success" : "error");
          if (res.ok) router.reload({ only: ["partners", "kpis", "funnel", "recentCommissions"] });
        }
      });
    }
    function onPay(commission) {
      openReasonModal({
        title: props.t.confirm_pay_title ?? "Pagar comissão",
        message: props.t.confirm_pay ?? "",
        confirmVariant: "primary",
        async onConfirm(reason) {
          var _a;
          const res = await fetch(commission.pay_url, {
            method: "PATCH",
            headers: {
              "Accept": "application/json",
              "Content-Type": "application/json",
              "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
            },
            body: JSON.stringify({ reason })
          });
          const json = await res.json();
          showToast(json.message, res.ok ? "success" : "error");
          if (res.ok) router.reload({ only: ["recentCommissions", "kpis"] });
        }
      });
    }
    function showToast(msg, type = "success") {
      if (type === "success" && window.showSuccessToast) return window.showSuccessToast(msg);
      if (type === "error" && window.showErrorToast) return window.showErrorToast(msg);
      alert(msg);
    }
    function fmtBrl(val) {
      return "R$ " + Number(val ?? 0).toLocaleString("pt-BR", { minimumFractionDigits: 0 });
    }
    const breadcrumbs = [
      { label: props.t.breadcrumb_home ?? "Dashboard", url: route("panel.dashboard"), active: false },
      { label: props.t.breadcrumb_current ?? "Parceiros", url: "#", active: true }
    ];
    const exportFrom = ref("");
    const exportTo = ref("");
    const exportStatus = ref("");
    function exportUrl(format) {
      const base = format === "pdf" ? route("manager.partners.export.pdf") : route("manager.partners.export.excel");
      const params = new URLSearchParams();
      if (exportFrom.value) params.set("from", exportFrom.value);
      if (exportTo.value) params.set("to", exportTo.value);
      if (exportStatus.value) params.set("status", exportStatus.value);
      const qs = params.toString();
      return qs ? `${base}?${qs}` : base;
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.page_title,
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div${_scopeId}><div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom flex-wrap"${_scopeId}><h4 class="mb-0 fw-bold me-auto"${_scopeId}>${ssrInterpolate(__props.t.page_title)}</h4>`);
            _push2(ssrRenderComponent(ActionDropdown, {
              "btn-class": "btn btn-outline-primary btn-md fs-13 d-inline-flex align-items-center",
              "min-width": 280,
              icon: "ti ti-download",
              title: __props.t.export ?? "Exportar"
            }, {
              default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<li class="px-2 pb-2"${_scopeId2}><div class="small text-muted fw-semibold mb-1"${_scopeId2}>${ssrInterpolate(__props.t.export_filters ?? "Filtros do export")}</div><label class="form-label small mb-1"${_scopeId2}>${ssrInterpolate(__props.t.export_from ?? "De")}</label><input${ssrRenderAttr("value", exportFrom.value)} type="date" class="form-control form-control-sm mb-2"${_scopeId2}><label class="form-label small mb-1"${_scopeId2}>${ssrInterpolate(__props.t.export_to ?? "Até")}</label><input${ssrRenderAttr("value", exportTo.value)} type="date" class="form-control form-control-sm mb-2"${_scopeId2}><label class="form-label small mb-1"${_scopeId2}>${ssrInterpolate(__props.t.export_status ?? "Status (comissões)")}</label><select class="form-select form-select-sm"${_scopeId2}><option value=""${ssrIncludeBooleanAttr(Array.isArray(exportStatus.value) ? ssrLooseContain(exportStatus.value, "") : ssrLooseEqual(exportStatus.value, "")) ? " selected" : ""}${_scopeId2}>${ssrInterpolate(__props.t.export_status_all ?? "Todos")}</option><option value="pending"${ssrIncludeBooleanAttr(Array.isArray(exportStatus.value) ? ssrLooseContain(exportStatus.value, "pending") : ssrLooseEqual(exportStatus.value, "pending")) ? " selected" : ""}${_scopeId2}>${ssrInterpolate(__props.t.export_status_pending ?? "Pendentes")}</option><option value="paid"${ssrIncludeBooleanAttr(Array.isArray(exportStatus.value) ? ssrLooseContain(exportStatus.value, "paid") : ssrLooseEqual(exportStatus.value, "paid")) ? " selected" : ""}${_scopeId2}>${ssrInterpolate(__props.t.export_status_paid ?? "Pagas")}</option><option value="cancelled"${ssrIncludeBooleanAttr(Array.isArray(exportStatus.value) ? ssrLooseContain(exportStatus.value, "cancelled") : ssrLooseEqual(exportStatus.value, "cancelled")) ? " selected" : ""}${_scopeId2}>${ssrInterpolate(__props.t.export_status_cancelled ?? "Canceladas")}</option></select></li><li${_scopeId2}><hr class="dropdown-divider my-1"${_scopeId2}></li><li${_scopeId2}><a${ssrRenderAttr("href", exportUrl("pdf"))} target="_blank" class="dropdown-item rounded-1"${_scopeId2}><i class="ti ti-file-type-pdf me-1 text-danger"${_scopeId2}></i> ${ssrInterpolate(__props.t.export_pdf ?? "Exportar PDF")}</a></li><li${_scopeId2}><a${ssrRenderAttr("href", exportUrl("excel"))} class="dropdown-item rounded-1"${_scopeId2}><i class="ti ti-file-type-xls me-1 text-success"${_scopeId2}></i> ${ssrInterpolate(__props.t.export_excel ?? "Exportar Excel")}</a></li>`);
                } else {
                  return [
                    createVNode("li", { class: "px-2 pb-2" }, [
                      createVNode("div", { class: "small text-muted fw-semibold mb-1" }, toDisplayString(__props.t.export_filters ?? "Filtros do export"), 1),
                      createVNode("label", { class: "form-label small mb-1" }, toDisplayString(__props.t.export_from ?? "De"), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => exportFrom.value = $event,
                        type: "date",
                        class: "form-control form-control-sm mb-2"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, exportFrom.value]
                      ]),
                      createVNode("label", { class: "form-label small mb-1" }, toDisplayString(__props.t.export_to ?? "Até"), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => exportTo.value = $event,
                        type: "date",
                        class: "form-control form-control-sm mb-2"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, exportTo.value]
                      ]),
                      createVNode("label", { class: "form-label small mb-1" }, toDisplayString(__props.t.export_status ?? "Status (comissões)"), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => exportStatus.value = $event,
                        class: "form-select form-select-sm"
                      }, [
                        createVNode("option", { value: "" }, toDisplayString(__props.t.export_status_all ?? "Todos"), 1),
                        createVNode("option", { value: "pending" }, toDisplayString(__props.t.export_status_pending ?? "Pendentes"), 1),
                        createVNode("option", { value: "paid" }, toDisplayString(__props.t.export_status_paid ?? "Pagas"), 1),
                        createVNode("option", { value: "cancelled" }, toDisplayString(__props.t.export_status_cancelled ?? "Canceladas"), 1)
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, exportStatus.value]
                      ])
                    ]),
                    createVNode("li", null, [
                      createVNode("hr", { class: "dropdown-divider my-1" })
                    ]),
                    createVNode("li", null, [
                      createVNode("a", {
                        href: exportUrl("pdf"),
                        target: "_blank",
                        class: "dropdown-item rounded-1"
                      }, [
                        createVNode("i", { class: "ti ti-file-type-pdf me-1 text-danger" }),
                        createTextVNode(" " + toDisplayString(__props.t.export_pdf ?? "Exportar PDF"), 1)
                      ], 8, ["href"])
                    ]),
                    createVNode("li", null, [
                      createVNode("a", {
                        href: exportUrl("excel"),
                        class: "dropdown-item rounded-1"
                      }, [
                        createVNode("i", { class: "ti ti-file-type-xls me-1 text-success" }),
                        createTextVNode(" " + toDisplayString(__props.t.export_excel ?? "Exportar Excel"), 1)
                      ], 8, ["href"])
                    ])
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<button type="button" class="btn btn-primary btn-md fs-13"${_scopeId}><i class="ti ti-plus me-1"${_scopeId}></i>${ssrInterpolate(__props.t.new)}</button></div>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              "is-refreshing": unref(isRefreshing),
              "last-updated": unref(lastUpdated),
              t: __props.t,
              onRefresh: unref(refresh)
            }, null, _parent2, _scopeId));
            _push2(`<div class="row g-3 mb-4"${_scopeId}><div class="col-6 col-md-3"${_scopeId}><div class="card card-body h-100 border-start border-primary border-3 ps-3"${_scopeId}><div class="d-flex align-items-center gap-3"${_scopeId}><div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-primary-subtle" style="${ssrRenderStyle({ "width": "42px", "height": "42px" })}"${_scopeId}><i class="ti ti-affiliate fs-18 text-primary"${_scopeId}></i></div><div${_scopeId}><div class="fs-22 fw-bold lh-1"${_scopeId}>${ssrInterpolate(__props.kpis.partners ?? 0)}</div><div class="text-muted small"${_scopeId}>${ssrInterpolate(__props.t.kpi_partners)}</div></div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card card-body h-100 border-start border-info border-3 ps-3"${_scopeId}><div class="d-flex align-items-center gap-3"${_scopeId}><div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-info-subtle" style="${ssrRenderStyle({ "width": "42px", "height": "42px" })}"${_scopeId}><i class="ti ti-target-arrow fs-18 text-info"${_scopeId}></i></div><div${_scopeId}><div class="fs-22 fw-bold lh-1"${_scopeId}>${ssrInterpolate(__props.kpis.leads ?? 0)}</div><div class="text-muted small"${_scopeId}>${ssrInterpolate(__props.t.kpi_leads)}</div></div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card card-body h-100 border-start border-warning border-3 ps-3"${_scopeId}><div class="d-flex align-items-center gap-3"${_scopeId}><div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-warning-subtle" style="${ssrRenderStyle({ "width": "42px", "height": "42px" })}"${_scopeId}><i class="ti ti-cash fs-18 text-warning"${_scopeId}></i></div><div${_scopeId}><div class="fs-18 fw-bold lh-1"${_scopeId}>${ssrInterpolate(fmtBrl(__props.kpis.pending))}</div><div class="text-muted small"${_scopeId}>${ssrInterpolate(__props.t.kpi_pending)}</div></div></div></div></div><div class="col-6 col-md-3"${_scopeId}><div class="card card-body h-100 border-start border-success border-3 ps-3"${_scopeId}><div class="d-flex align-items-center gap-3"${_scopeId}><div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-success-subtle" style="${ssrRenderStyle({ "width": "42px", "height": "42px" })}"${_scopeId}><i class="ti ti-circle-check fs-18 text-success"${_scopeId}></i></div><div${_scopeId}><div class="fs-18 fw-bold lh-1"${_scopeId}>${ssrInterpolate(fmtBrl(__props.kpis.paid))}</div><div class="text-muted small"${_scopeId}>${ssrInterpolate(__props.t.kpi_paid)}</div></div></div></div></div></div><div class="card mb-4"${_scopeId}><div class="card-header fw-semibold py-2"${_scopeId}><i class="ti ti-filter me-2 text-muted"${_scopeId}></i>${ssrInterpolate(__props.t.lead_funnel)}</div><div class="card-body"${_scopeId}><div class="row g-3 text-center"${_scopeId}><!--[-->`);
            ssrRenderList(__props.funnel, (stage) => {
              _push2(`<div class="col"${_scopeId}><div class="p-3 rounded border"${_scopeId}><div class="fw-bold mb-2" style="${ssrRenderStyle({ "font-size": "1.75rem" })}"${_scopeId}>${ssrInterpolate(stage.count)}</div><span class="${ssrRenderClass([stage.badge, "badge"])}"${_scopeId}>${ssrInterpolate(stage.label)}</span></div></div>`);
            });
            _push2(`<!--]--></div></div></div><div class="card mb-4"${_scopeId}><div class="card-header d-flex align-items-center justify-content-between py-2"${_scopeId}><span class="fw-semibold"${_scopeId}><i class="ti ti-affiliate me-2 text-muted"${_scopeId}></i>${ssrInterpolate(__props.t.page_title)}</span><button type="button" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-plus me-1"${_scopeId}></i>${ssrInterpolate(__props.t.new)}</button></div><div class="table-responsive"${_scopeId}><table class="table table-nowrap table-hover align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(__props.t.col_partner)}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_type)}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_leads)}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_commissions)}</th><th class="text-end"${_scopeId}>${ssrInterpolate(__props.t.col_pending)}</th><th class="text-end"${_scopeId}>${ssrInterpolate(__props.t.col_paid)}</th><th class="text-end" style="${ssrRenderStyle({ "min-width": "110px" })}"${_scopeId}>${ssrInterpolate(__props.t.col_actions)}</th></tr></thead><tbody${_scopeId}>`);
            if (__props.partners.length === 0) {
              _push2(`<tr${_scopeId}><td colspan="7" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-affiliate fs-1 d-block mb-2 opacity-25"${_scopeId}></i> ${ssrInterpolate(__props.t.empty)} <button type="button" class="btn btn-link p-0 ms-1"${_scopeId}>${ssrInterpolate(__props.t.empty_cta)}</button></td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.partners, (p) => {
              _push2(`<tr${_scopeId}><td${_scopeId}><div class="fw-semibold"${_scopeId}>${ssrInterpolate(p.name)}</div><div class="text-muted small"${_scopeId}>${ssrInterpolate(p.email)}</div></td><td${_scopeId}><span class="badge badge-soft-secondary rounded fs-12"${_scopeId}>${ssrInterpolate(p.type_label)}</span></td><td class="text-center"${_scopeId}>${ssrInterpolate(p.leads_count)}</td><td class="text-center"${_scopeId}>${ssrInterpolate(p.commissions_count)}</td><td class="text-end fw-semibold text-warning"${_scopeId}>${ssrInterpolate(p.pending_fmt)}</td><td class="text-end fw-semibold text-success"${_scopeId}>${ssrInterpolate(p.paid_fmt)}</td><td class="text-end"${_scopeId}>`);
              _push2(ssrRenderComponent(ActionIconGroup, {
                align: "end",
                gap: "tight"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    _push3(ssrRenderComponent(_sfc_main$2, {
                      icon: "ti ti-eye",
                      title: __props.t.view,
                      variant: "info",
                      href: p.show_url
                    }, null, _parent3, _scopeId2));
                    _push3(ssrRenderComponent(_sfc_main$2, {
                      icon: "ti ti-edit",
                      title: __props.t.edit,
                      onClick: ($event) => openEdit(p)
                    }, null, _parent3, _scopeId2));
                    _push3(ssrRenderComponent(_sfc_main$2, {
                      icon: "ti ti-trash",
                      title: __props.t.delete,
                      variant: "danger",
                      onClick: ($event) => onDelete(p)
                    }, null, _parent3, _scopeId2));
                  } else {
                    return [
                      createVNode(_sfc_main$2, {
                        icon: "ti ti-eye",
                        title: __props.t.view,
                        variant: "info",
                        href: p.show_url
                      }, null, 8, ["title", "href"]),
                      createVNode(_sfc_main$2, {
                        icon: "ti ti-edit",
                        title: __props.t.edit,
                        onClick: ($event) => openEdit(p)
                      }, null, 8, ["title", "onClick"]),
                      createVNode(_sfc_main$2, {
                        icon: "ti ti-trash",
                        title: __props.t.delete,
                        variant: "danger",
                        onClick: ($event) => onDelete(p)
                      }, null, 8, ["title", "onClick"])
                    ];
                  }
                }),
                _: 2
              }, _parent2, _scopeId));
              _push2(`</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div></div>`);
            if (__props.recentCommissions.length > 0) {
              _push2(`<div class="card mb-4"${_scopeId}><div class="card-header fw-semibold py-2"${_scopeId}><i class="ti ti-cash me-2 text-muted"${_scopeId}></i>${ssrInterpolate(__props.t.recent_commissions)}</div><div class="table-responsive"${_scopeId}><table class="table table-nowrap align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(__props.t.col_partner)}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_clinic)}</th><th class="text-end"${_scopeId}>${ssrInterpolate(__props.t.col_value)}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_status)}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_due)}</th></tr></thead><tbody${_scopeId}><!--[-->`);
              ssrRenderList(__props.recentCommissions, (c) => {
                _push2(`<tr${_scopeId}><td class="fw-semibold"${_scopeId}>${ssrInterpolate(c.partner_name)}</td><td class="text-muted"${_scopeId}>${ssrInterpolate(c.entity_name)}</td><td class="text-end fw-semibold"${_scopeId}>${ssrInterpolate(c.amount_fmt)}</td><td class="text-center"${_scopeId}><span class="${ssrRenderClass([c.status_badge, "badge"])}"${_scopeId}>${ssrInterpolate(c.status_label)}</span></td><td class="text-center"${_scopeId}><div class="d-flex align-items-center justify-content-center gap-2"${_scopeId}><span${_scopeId}>${ssrInterpolate(c.due_at ?? "—")}</span>`);
                if (c.is_pending) {
                  _push2(`<button class="btn btn-xs btn-success"${_scopeId}>${ssrInterpolate(__props.t.pay)}</button>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</div></td></tr>`);
              });
              _push2(`<!--]--></tbody></table></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            _push2(ssrRenderComponent(_sfc_main$3, {
              open: formOpen.value,
              "partner-id": editId.value,
              "edit-data-url": editDataUrl.value,
              "update-url": updateUrl.value,
              "partner-types": __props.partnerTypes,
              t: __props.t,
              onClose: closeForm,
              onSaved: closeForm
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$4, {
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
                createVNode("div", { class: "d-flex align-items-center gap-2 pb-3 mb-3 border-bottom flex-wrap" }, [
                  createVNode("h4", { class: "mb-0 fw-bold me-auto" }, toDisplayString(__props.t.page_title), 1),
                  createVNode(ActionDropdown, {
                    "btn-class": "btn btn-outline-primary btn-md fs-13 d-inline-flex align-items-center",
                    "min-width": 280,
                    icon: "ti ti-download",
                    title: __props.t.export ?? "Exportar"
                  }, {
                    default: withCtx(() => [
                      createVNode("li", { class: "px-2 pb-2" }, [
                        createVNode("div", { class: "small text-muted fw-semibold mb-1" }, toDisplayString(__props.t.export_filters ?? "Filtros do export"), 1),
                        createVNode("label", { class: "form-label small mb-1" }, toDisplayString(__props.t.export_from ?? "De"), 1),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => exportFrom.value = $event,
                          type: "date",
                          class: "form-control form-control-sm mb-2"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, exportFrom.value]
                        ]),
                        createVNode("label", { class: "form-label small mb-1" }, toDisplayString(__props.t.export_to ?? "Até"), 1),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => exportTo.value = $event,
                          type: "date",
                          class: "form-control form-control-sm mb-2"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, exportTo.value]
                        ]),
                        createVNode("label", { class: "form-label small mb-1" }, toDisplayString(__props.t.export_status ?? "Status (comissões)"), 1),
                        withDirectives(createVNode("select", {
                          "onUpdate:modelValue": ($event) => exportStatus.value = $event,
                          class: "form-select form-select-sm"
                        }, [
                          createVNode("option", { value: "" }, toDisplayString(__props.t.export_status_all ?? "Todos"), 1),
                          createVNode("option", { value: "pending" }, toDisplayString(__props.t.export_status_pending ?? "Pendentes"), 1),
                          createVNode("option", { value: "paid" }, toDisplayString(__props.t.export_status_paid ?? "Pagas"), 1),
                          createVNode("option", { value: "cancelled" }, toDisplayString(__props.t.export_status_cancelled ?? "Canceladas"), 1)
                        ], 8, ["onUpdate:modelValue"]), [
                          [vModelSelect, exportStatus.value]
                        ])
                      ]),
                      createVNode("li", null, [
                        createVNode("hr", { class: "dropdown-divider my-1" })
                      ]),
                      createVNode("li", null, [
                        createVNode("a", {
                          href: exportUrl("pdf"),
                          target: "_blank",
                          class: "dropdown-item rounded-1"
                        }, [
                          createVNode("i", { class: "ti ti-file-type-pdf me-1 text-danger" }),
                          createTextVNode(" " + toDisplayString(__props.t.export_pdf ?? "Exportar PDF"), 1)
                        ], 8, ["href"])
                      ]),
                      createVNode("li", null, [
                        createVNode("a", {
                          href: exportUrl("excel"),
                          class: "dropdown-item rounded-1"
                        }, [
                          createVNode("i", { class: "ti ti-file-type-xls me-1 text-success" }),
                          createTextVNode(" " + toDisplayString(__props.t.export_excel ?? "Exportar Excel"), 1)
                        ], 8, ["href"])
                      ])
                    ]),
                    _: 1
                  }, 8, ["title"]),
                  createVNode("button", {
                    type: "button",
                    class: "btn btn-primary btn-md fs-13",
                    onClick: openCreate
                  }, [
                    createVNode("i", { class: "ti ti-plus me-1" }),
                    createTextVNode(toDisplayString(__props.t.new), 1)
                  ])
                ]),
                createVNode(_sfc_main$1, {
                  "is-refreshing": unref(isRefreshing),
                  "last-updated": unref(lastUpdated),
                  t: __props.t,
                  onRefresh: unref(refresh)
                }, null, 8, ["is-refreshing", "last-updated", "t", "onRefresh"]),
                createVNode("div", { class: "row g-3 mb-4" }, [
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card card-body h-100 border-start border-primary border-3 ps-3" }, [
                      createVNode("div", { class: "d-flex align-items-center gap-3" }, [
                        createVNode("div", {
                          class: "rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-primary-subtle",
                          style: { "width": "42px", "height": "42px" }
                        }, [
                          createVNode("i", { class: "ti ti-affiliate fs-18 text-primary" })
                        ]),
                        createVNode("div", null, [
                          createVNode("div", { class: "fs-22 fw-bold lh-1" }, toDisplayString(__props.kpis.partners ?? 0), 1),
                          createVNode("div", { class: "text-muted small" }, toDisplayString(__props.t.kpi_partners), 1)
                        ])
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card card-body h-100 border-start border-info border-3 ps-3" }, [
                      createVNode("div", { class: "d-flex align-items-center gap-3" }, [
                        createVNode("div", {
                          class: "rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-info-subtle",
                          style: { "width": "42px", "height": "42px" }
                        }, [
                          createVNode("i", { class: "ti ti-target-arrow fs-18 text-info" })
                        ]),
                        createVNode("div", null, [
                          createVNode("div", { class: "fs-22 fw-bold lh-1" }, toDisplayString(__props.kpis.leads ?? 0), 1),
                          createVNode("div", { class: "text-muted small" }, toDisplayString(__props.t.kpi_leads), 1)
                        ])
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card card-body h-100 border-start border-warning border-3 ps-3" }, [
                      createVNode("div", { class: "d-flex align-items-center gap-3" }, [
                        createVNode("div", {
                          class: "rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-warning-subtle",
                          style: { "width": "42px", "height": "42px" }
                        }, [
                          createVNode("i", { class: "ti ti-cash fs-18 text-warning" })
                        ]),
                        createVNode("div", null, [
                          createVNode("div", { class: "fs-18 fw-bold lh-1" }, toDisplayString(fmtBrl(__props.kpis.pending)), 1),
                          createVNode("div", { class: "text-muted small" }, toDisplayString(__props.t.kpi_pending), 1)
                        ])
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-6 col-md-3" }, [
                    createVNode("div", { class: "card card-body h-100 border-start border-success border-3 ps-3" }, [
                      createVNode("div", { class: "d-flex align-items-center gap-3" }, [
                        createVNode("div", {
                          class: "rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 bg-success-subtle",
                          style: { "width": "42px", "height": "42px" }
                        }, [
                          createVNode("i", { class: "ti ti-circle-check fs-18 text-success" })
                        ]),
                        createVNode("div", null, [
                          createVNode("div", { class: "fs-18 fw-bold lh-1" }, toDisplayString(fmtBrl(__props.kpis.paid)), 1),
                          createVNode("div", { class: "text-muted small" }, toDisplayString(__props.t.kpi_paid), 1)
                        ])
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "card mb-4" }, [
                  createVNode("div", { class: "card-header fw-semibold py-2" }, [
                    createVNode("i", { class: "ti ti-filter me-2 text-muted" }),
                    createTextVNode(toDisplayString(__props.t.lead_funnel), 1)
                  ]),
                  createVNode("div", { class: "card-body" }, [
                    createVNode("div", { class: "row g-3 text-center" }, [
                      (openBlock(true), createBlock(Fragment, null, renderList(__props.funnel, (stage) => {
                        return openBlock(), createBlock("div", {
                          key: stage.value,
                          class: "col"
                        }, [
                          createVNode("div", { class: "p-3 rounded border" }, [
                            createVNode("div", {
                              class: "fw-bold mb-2",
                              style: { "font-size": "1.75rem" }
                            }, toDisplayString(stage.count), 1),
                            createVNode("span", {
                              class: ["badge", stage.badge]
                            }, toDisplayString(stage.label), 3)
                          ])
                        ]);
                      }), 128))
                    ])
                  ])
                ]),
                createVNode("div", { class: "card mb-4" }, [
                  createVNode("div", { class: "card-header d-flex align-items-center justify-content-between py-2" }, [
                    createVNode("span", { class: "fw-semibold" }, [
                      createVNode("i", { class: "ti ti-affiliate me-2 text-muted" }),
                      createTextVNode(toDisplayString(__props.t.page_title), 1)
                    ]),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary btn-sm",
                      onClick: openCreate
                    }, [
                      createVNode("i", { class: "ti ti-plus me-1" }),
                      createTextVNode(toDisplayString(__props.t.new), 1)
                    ])
                  ]),
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap table-hover align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, toDisplayString(__props.t.col_partner), 1),
                          createVNode("th", null, toDisplayString(__props.t.col_type), 1),
                          createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_leads), 1),
                          createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_commissions), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(__props.t.col_pending), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(__props.t.col_paid), 1),
                          createVNode("th", {
                            class: "text-end",
                            style: { "min-width": "110px" }
                          }, toDisplayString(__props.t.col_actions), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        __props.partners.length === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("td", {
                            colspan: "7",
                            class: "text-center text-muted py-5"
                          }, [
                            createVNode("i", { class: "ti ti-affiliate fs-1 d-block mb-2 opacity-25" }),
                            createTextVNode(" " + toDisplayString(__props.t.empty) + " ", 1),
                            createVNode("button", {
                              type: "button",
                              class: "btn btn-link p-0 ms-1",
                              onClick: openCreate
                            }, toDisplayString(__props.t.empty_cta), 1)
                          ])
                        ])) : createCommentVNode("", true),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.partners, (p) => {
                          return openBlock(), createBlock("tr", {
                            key: p.id
                          }, [
                            createVNode("td", null, [
                              createVNode("div", { class: "fw-semibold" }, toDisplayString(p.name), 1),
                              createVNode("div", { class: "text-muted small" }, toDisplayString(p.email), 1)
                            ]),
                            createVNode("td", null, [
                              createVNode("span", { class: "badge badge-soft-secondary rounded fs-12" }, toDisplayString(p.type_label), 1)
                            ]),
                            createVNode("td", { class: "text-center" }, toDisplayString(p.leads_count), 1),
                            createVNode("td", { class: "text-center" }, toDisplayString(p.commissions_count), 1),
                            createVNode("td", { class: "text-end fw-semibold text-warning" }, toDisplayString(p.pending_fmt), 1),
                            createVNode("td", { class: "text-end fw-semibold text-success" }, toDisplayString(p.paid_fmt), 1),
                            createVNode("td", { class: "text-end" }, [
                              createVNode(ActionIconGroup, {
                                align: "end",
                                gap: "tight"
                              }, {
                                default: withCtx(() => [
                                  createVNode(_sfc_main$2, {
                                    icon: "ti ti-eye",
                                    title: __props.t.view,
                                    variant: "info",
                                    href: p.show_url
                                  }, null, 8, ["title", "href"]),
                                  createVNode(_sfc_main$2, {
                                    icon: "ti ti-edit",
                                    title: __props.t.edit,
                                    onClick: ($event) => openEdit(p)
                                  }, null, 8, ["title", "onClick"]),
                                  createVNode(_sfc_main$2, {
                                    icon: "ti ti-trash",
                                    title: __props.t.delete,
                                    variant: "danger",
                                    onClick: ($event) => onDelete(p)
                                  }, null, 8, ["title", "onClick"])
                                ]),
                                _: 2
                              }, 1024)
                            ])
                          ]);
                        }), 128))
                      ])
                    ])
                  ])
                ]),
                __props.recentCommissions.length > 0 ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "card mb-4"
                }, [
                  createVNode("div", { class: "card-header fw-semibold py-2" }, [
                    createVNode("i", { class: "ti ti-cash me-2 text-muted" }),
                    createTextVNode(toDisplayString(__props.t.recent_commissions), 1)
                  ]),
                  createVNode("div", { class: "table-responsive" }, [
                    createVNode("table", { class: "table table-nowrap align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", null, toDisplayString(__props.t.col_partner), 1),
                          createVNode("th", null, toDisplayString(__props.t.col_clinic), 1),
                          createVNode("th", { class: "text-end" }, toDisplayString(__props.t.col_value), 1),
                          createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_status), 1),
                          createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_due), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.recentCommissions, (c) => {
                          return openBlock(), createBlock("tr", {
                            key: c.id
                          }, [
                            createVNode("td", { class: "fw-semibold" }, toDisplayString(c.partner_name), 1),
                            createVNode("td", { class: "text-muted" }, toDisplayString(c.entity_name), 1),
                            createVNode("td", { class: "text-end fw-semibold" }, toDisplayString(c.amount_fmt), 1),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("span", {
                                class: ["badge", c.status_badge]
                              }, toDisplayString(c.status_label), 3)
                            ]),
                            createVNode("td", { class: "text-center" }, [
                              createVNode("div", { class: "d-flex align-items-center justify-content-center gap-2" }, [
                                createVNode("span", null, toDisplayString(c.due_at ?? "—"), 1),
                                c.is_pending ? (openBlock(), createBlock("button", {
                                  key: 0,
                                  class: "btn btn-xs btn-success",
                                  onClick: ($event) => onPay(c)
                                }, toDisplayString(__props.t.pay), 9, ["onClick"])) : createCommentVNode("", true)
                              ])
                            ])
                          ]);
                        }), 128))
                      ])
                    ])
                  ])
                ])) : createCommentVNode("", true)
              ]),
              createVNode(_sfc_main$3, {
                open: formOpen.value,
                "partner-id": editId.value,
                "edit-data-url": editDataUrl.value,
                "update-url": updateUrl.value,
                "partner-types": __props.partnerTypes,
                t: __props.t,
                onClose: closeForm,
                onSaved: closeForm
              }, null, 8, ["open", "partner-id", "edit-data-url", "update-url", "partner-types", "t"]),
              createVNode(_sfc_main$4, {
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Partners/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
