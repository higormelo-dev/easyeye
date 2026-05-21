import { ref, computed, mergeProps, withCtx, unref, createVNode, toDisplayString, createTextVNode, openBlock, createBlock, createCommentVNode, withDirectives, Fragment, renderList, vShow, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderClass, ssrRenderAttr, ssrRenderStyle, ssrRenderList, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import _sfc_main$2 from "./PartnerFormModal-BqLYDJEQ.js";
import { _ as _sfc_main$1 } from "./LiveStatusBar-CQZOQaZ5.js";
import { _ as _sfc_main$3 } from "./ConfirmationWithReasonModal-CmfO7qbN.js";
import { u as useConfirmationWithReason } from "./useConfirmationWithReason-DDlQOe6J.js";
import { u as useDashboardPolling } from "./useDashboardPolling-D1jTH2om.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Show",
  __ssrInlineRender: true,
  props: {
    partner: { type: Object, required: true },
    leads: { type: Object, required: true },
    commissions: { type: Object, required: true },
    leadStatuses: { type: Array, default: () => [] },
    partnerTypes: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const { isRefreshing, lastUpdated, refresh } = useDashboardPolling(
      ["leads", "commissions", "partner"],
      15e3
    );
    const activeTab = ref("leads");
    const formOpen = ref(false);
    const advancingId = ref(null);
    async function advanceLead(lead, newStatus) {
      var _a;
      if (!newStatus || !confirm(props.t.confirm_advance)) return;
      advancingId.value = lead.id;
      try {
        const res = await fetch(lead.advance_url, {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
          },
          body: JSON.stringify({ status: newStatus })
        });
        const json = await res.json();
        showToast(json.message, res.ok ? "success" : "error");
        if (res.ok) router.reload({ only: ["leads", "partner"] });
      } finally {
        advancingId.value = null;
      }
    }
    const payingId = ref(null);
    const { state: reasonModal, open: openReasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();
    function payCommission(commission) {
      openReasonModal({
        title: props.t.confirm_pay_title ?? "Pagar comissão",
        message: props.t.confirm_pay ?? "",
        confirmVariant: "primary",
        async onConfirm(reason) {
          var _a;
          payingId.value = commission.id;
          try {
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
            if (res.ok) router.reload({ only: ["commissions", "partner"] });
          } finally {
            payingId.value = null;
          }
        }
      });
    }
    function goLeadsPage(page) {
      var _a;
      router.get(
        route("manager.partners.show", props.partner.id),
        { leads_page: page, commissions_page: ((_a = props.commissions.meta) == null ? void 0 : _a.current_page) ?? 1 },
        { preserveState: true, preserveScroll: true }
      );
      activeTab.value = "leads";
    }
    function goCommissionsPage(page) {
      var _a;
      router.get(
        route("manager.partners.show", props.partner.id),
        { leads_page: ((_a = props.leads.meta) == null ? void 0 : _a.current_page) ?? 1, commissions_page: page },
        { preserveState: true, preserveScroll: true }
      );
      activeTab.value = "commissions";
    }
    const tokenCopied = ref(false);
    function copyToken() {
      navigator.clipboard.writeText(props.partner.token).then(() => {
        tokenCopied.value = true;
        setTimeout(() => {
          tokenCopied.value = false;
        }, 2e3);
      });
    }
    function showToast(msg, type = "success") {
      if (type === "success" && window.showSuccessToast) return window.showSuccessToast(msg);
      if (type === "error" && window.showErrorToast) return window.showErrorToast(msg);
      alert(msg);
    }
    const breadcrumbs = computed(() => [
      { label: props.t.breadcrumb_home ?? "Dashboard", url: route("panel.dashboard"), active: false },
      { label: props.t.breadcrumb_current ?? "Parceiros", url: route("manager.partners.index"), active: false },
      { label: props.partner.name, url: "#", active: true }
    ]);
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.partner.name,
        breadcrumbs: breadcrumbs.value
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l;
          if (_push2) {
            _push2(`<div${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              "is-refreshing": unref(isRefreshing),
              "last-updated": unref(lastUpdated),
              t: __props.t,
              onRefresh: unref(refresh)
            }, null, _parent2, _scopeId));
            _push2(`<div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-4 border-bottom"${_scopeId}><div class="flex-grow-1"${_scopeId}><div class="d-flex align-items-center gap-2 flex-wrap"${_scopeId}><h4 class="fw-bold mb-0"${_scopeId}>${ssrInterpolate(__props.partner.name)}</h4><span class="${ssrRenderClass([__props.partner.status_badge, "badge"])}"${_scopeId}>${ssrInterpolate(__props.partner.status_label)}</span></div><div class="text-muted small mt-1"${_scopeId}>${ssrInterpolate(__props.partner.email)}</div></div><div class="d-flex gap-2"${_scopeId}><a${ssrRenderAttr("href", _ctx.route("manager.partners.index"))} class="btn btn-outline-secondary btn-sm"${_scopeId}><i class="ti ti-arrow-left me-1"${_scopeId}></i>${ssrInterpolate(__props.t.back)}</a><button type="button" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-edit me-1"${_scopeId}></i>${ssrInterpolate(__props.t.edit)}</button></div></div><div class="row g-4"${_scopeId}><div class="col-12 col-md-4"${_scopeId}><div class="card h-100"${_scopeId}><div class="card-header fw-semibold py-2"${_scopeId}><i class="ti ti-info-circle me-2 text-muted"${_scopeId}></i>${ssrInterpolate(__props.t.sidebar_info)}</div><div class="card-body"${_scopeId}><div class="row g-2 mb-4"${_scopeId}><div class="col-6"${_scopeId}><div class="p-3 rounded border text-center"${_scopeId}><div class="fw-bold fs-20 lh-1 mb-1"${_scopeId}>${ssrInterpolate(__props.partner.leads_total)}</div><div class="text-muted small"${_scopeId}>${ssrInterpolate(__props.t.label_leads)}</div></div></div><div class="col-6"${_scopeId}><div class="p-3 rounded border text-center"${_scopeId}><div class="fw-bold fs-20 lh-1 mb-1"${_scopeId}>${ssrInterpolate(__props.partner.commissions_total)}</div><div class="text-muted small"${_scopeId}>${ssrInterpolate(__props.t.label_commissions)}</div></div></div></div><ul class="list-unstyled mb-0 small"${_scopeId}><li class="d-flex justify-content-between align-items-center py-2 border-bottom"${_scopeId}><span class="text-muted"${_scopeId}>${ssrInterpolate(__props.t.label_type)}</span><span class="badge badge-soft-secondary rounded fs-12"${_scopeId}>${ssrInterpolate(__props.partner.type_label)}</span></li><li class="d-flex justify-content-between align-items-center py-2 border-bottom"${_scopeId}><span class="text-muted"${_scopeId}>${ssrInterpolate(__props.t.label_status)}</span><span class="${ssrRenderClass([__props.partner.status_badge, "badge"])}"${_scopeId}>${ssrInterpolate(__props.partner.status_label)}</span></li><li class="d-flex justify-content-between align-items-center py-2 border-bottom"${_scopeId}><span class="text-muted"${_scopeId}>${ssrInterpolate(__props.t.label_commission)}</span><span class="fw-semibold"${_scopeId}>${ssrInterpolate(__props.partner.commission_rate)}%</span></li>`);
            if (__props.partner.document) {
              _push2(`<li class="d-flex justify-content-between align-items-center py-2 border-bottom"${_scopeId}><span class="text-muted"${_scopeId}>${ssrInterpolate(__props.t.label_document)}</span><span${_scopeId}>${ssrInterpolate(__props.partner.document)}</span></li>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<li class="d-flex justify-content-between align-items-center py-2 border-bottom"${_scopeId}><span class="text-muted"${_scopeId}>${ssrInterpolate(__props.t.label_token)}</span><div class="d-flex align-items-center gap-1"${_scopeId}><code class="small"${_scopeId}>${ssrInterpolate(__props.partner.token)}</code><button type="button" class="btn btn-xs btn-outline-secondary"${ssrRenderAttr("title", __props.t.copy)}${_scopeId}><i class="${ssrRenderClass(tokenCopied.value ? "ti ti-check text-success" : "ti ti-copy")}"${_scopeId}></i></button></div></li><li class="d-flex justify-content-between align-items-center py-2"${_scopeId}><span class="text-muted"${_scopeId}>${ssrInterpolate(__props.t.label_registered)}</span><span${_scopeId}>${ssrInterpolate(__props.partner.created_at)}</span></li></ul>`);
            if (__props.partner.notes) {
              _push2(`<div class="mt-3 p-3 rounded bg-light small text-muted"${_scopeId}><i class="ti ti-notes me-1"${_scopeId}></i>${ssrInterpolate(__props.partner.notes)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></div><div class="col-12 col-md-8"${_scopeId}><div class="card"${_scopeId}><div class="card-header p-0"${_scopeId}><ul class="nav nav-tabs border-bottom-0 px-3 pt-1"${_scopeId}><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "leads" }, "nav-link fw-semibold"])}"${_scopeId}><i class="ti ti-target-arrow me-1"${_scopeId}></i>${ssrInterpolate(__props.t.tab_leads)} <span class="badge badge-soft-secondary rounded ms-1 fs-12"${_scopeId}>${ssrInterpolate(((_a = __props.leads.meta) == null ? void 0 : _a.total) ?? 0)}</span></button></li><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "commissions" }, "nav-link fw-semibold"])}"${_scopeId}><i class="ti ti-cash me-1"${_scopeId}></i>${ssrInterpolate(__props.t.tab_commissions)} <span class="badge badge-soft-secondary rounded ms-1 fs-12"${_scopeId}>${ssrInterpolate(((_b = __props.commissions.meta) == null ? void 0 : _b.total) ?? 0)}</span></button></li></ul></div><div style="${ssrRenderStyle(activeTab.value === "leads" ? null : { display: "none" })}"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(__props.t.col_name)}</th><th${_scopeId}>${ssrInterpolate(__props.t.col_city)}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_status)}</th><th class="text-center" style="${ssrRenderStyle({ "min-width": "140px" })}"${_scopeId}>${ssrInterpolate(__props.t.col_advance)}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_date)}</th></tr></thead><tbody${_scopeId}>`);
            if (((_c = __props.leads.data) == null ? void 0 : _c.length) === 0) {
              _push2(`<tr${_scopeId}><td colspan="5" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-target-arrow fs-1 d-block mb-2 opacity-25"${_scopeId}></i> ${ssrInterpolate(__props.t.no_leads)}</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.leads.data, (lead) => {
              _push2(`<tr${_scopeId}><td${_scopeId}><div class="fw-semibold"${_scopeId}>${ssrInterpolate(lead.name)}</div><div class="text-muted small"${_scopeId}>${ssrInterpolate(lead.email)}</div></td><td class="text-muted small"${_scopeId}>${ssrInterpolate(lead.city_state ?? "—")}</td><td class="text-center"${_scopeId}><span class="${ssrRenderClass([lead.status_badge, "badge"])}"${_scopeId}>${ssrInterpolate(lead.status_label)}</span></td><td class="text-center"${_scopeId}>`);
              if (lead.is_active) {
                _push2(`<select class="form-select form-select-sm" style="${ssrRenderStyle({ "min-width": "130px" })}"${ssrIncludeBooleanAttr(advancingId.value === lead.id) ? " disabled" : ""}${_scopeId}><option value=""${_scopeId}>${ssrInterpolate(__props.t.field_advance)}</option><!--[-->`);
                ssrRenderList(__props.leadStatuses.filter((s) => s.value !== lead.status), (s) => {
                  _push2(`<option${ssrRenderAttr("value", s.value)}${_scopeId}>${ssrInterpolate(s.label)}</option>`);
                });
                _push2(`<!--]--></select>`);
              } else {
                _push2(`<span class="text-muted small"${_scopeId}>—</span>`);
              }
              _push2(`</td><td class="text-center text-muted small"${_scopeId}>${ssrInterpolate(lead.created_at)}</td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div>`);
            if (((_d = __props.leads.meta) == null ? void 0 : _d.last_page) > 1) {
              _push2(`<div class="d-flex justify-content-center py-3 gap-1"${_scopeId}><button class="btn btn-sm btn-outline-secondary"${ssrIncludeBooleanAttr(__props.leads.meta.current_page === 1) ? " disabled" : ""}${_scopeId}><i class="ti ti-arrow-left"${_scopeId}></i></button><!--[-->`);
              ssrRenderList(__props.leads.meta.last_page, (p) => {
                _push2(`<button class="${ssrRenderClass([p === __props.leads.meta.current_page ? "btn-primary" : "btn-outline-secondary", "btn btn-sm"])}"${_scopeId}>${ssrInterpolate(p)}</button>`);
              });
              _push2(`<!--]--><button class="btn btn-sm btn-outline-secondary"${ssrIncludeBooleanAttr(__props.leads.meta.current_page === __props.leads.meta.last_page) ? " disabled" : ""}${_scopeId}><i class="ti ti-arrow-right"${_scopeId}></i></button></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div style="${ssrRenderStyle(activeTab.value === "commissions" ? null : { display: "none" })}"${_scopeId}><div class="table-responsive"${_scopeId}><table class="table table-nowrap align-middle mb-0"${_scopeId}><thead class="table-light"${_scopeId}><tr${_scopeId}><th${_scopeId}>${ssrInterpolate(__props.t.col_clinic)}</th><th class="text-end"${_scopeId}>${ssrInterpolate(__props.t.col_value)}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_rate)}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_period)}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_status)}</th><th class="text-center"${_scopeId}>${ssrInterpolate(__props.t.col_due)}</th></tr></thead><tbody${_scopeId}>`);
            if (((_e = __props.commissions.data) == null ? void 0 : _e.length) === 0) {
              _push2(`<tr${_scopeId}><td colspan="6" class="text-center text-muted py-5"${_scopeId}><i class="ti ti-cash fs-1 d-block mb-2 opacity-25"${_scopeId}></i> ${ssrInterpolate(__props.t.no_commissions)}</td></tr>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<!--[-->`);
            ssrRenderList(__props.commissions.data, (c) => {
              _push2(`<tr${_scopeId}><td class="fw-semibold"${_scopeId}>${ssrInterpolate(c.entity_name)}</td><td class="text-end fw-semibold"${_scopeId}>${ssrInterpolate(c.amount_fmt)}</td><td class="text-center text-muted small"${_scopeId}>${ssrInterpolate(c.rate)}%</td><td class="text-center text-muted small"${_scopeId}>${ssrInterpolate(c.period)}</td><td class="text-center"${_scopeId}><span class="${ssrRenderClass([c.status_badge, "badge"])}"${_scopeId}>${ssrInterpolate(c.status_label)}</span></td><td class="text-center"${_scopeId}><div class="d-flex align-items-center justify-content-center gap-2"${_scopeId}><span class="small"${_scopeId}>${ssrInterpolate(c.due_at ?? "—")}</span>`);
              if (c.is_pending) {
                _push2(`<button class="btn btn-xs btn-success"${ssrIncludeBooleanAttr(payingId.value === c.id) ? " disabled" : ""}${_scopeId}>`);
                if (payingId.value === c.id) {
                  _push2(`<span class="spinner-border spinner-border-sm"${_scopeId}></span>`);
                } else {
                  _push2(`<span${_scopeId}>${ssrInterpolate(__props.t.pay)}</span>`);
                }
                _push2(`</button>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></td></tr>`);
            });
            _push2(`<!--]--></tbody></table></div>`);
            if (((_f = __props.commissions.meta) == null ? void 0 : _f.last_page) > 1) {
              _push2(`<div class="d-flex justify-content-center py-3 gap-1"${_scopeId}><button class="btn btn-sm btn-outline-secondary"${ssrIncludeBooleanAttr(__props.commissions.meta.current_page === 1) ? " disabled" : ""}${_scopeId}><i class="ti ti-arrow-left"${_scopeId}></i></button><!--[-->`);
              ssrRenderList(__props.commissions.meta.last_page, (p) => {
                _push2(`<button class="${ssrRenderClass([p === __props.commissions.meta.current_page ? "btn-primary" : "btn-outline-secondary", "btn btn-sm"])}"${_scopeId}>${ssrInterpolate(p)}</button>`);
              });
              _push2(`<!--]--><button class="btn btn-sm btn-outline-secondary"${ssrIncludeBooleanAttr(__props.commissions.meta.current_page === __props.commissions.meta.last_page) ? " disabled" : ""}${_scopeId}><i class="ti ti-arrow-right"${_scopeId}></i></button></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></div></div></div>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              open: formOpen.value,
              "partner-id": __props.partner.id,
              "edit-data-url": __props.partner.edit_url,
              "update-url": __props.partner.update_url,
              "partner-types": __props.partnerTypes,
              t: __props.t,
              onClose: ($event) => formOpen.value = false,
              onSaved: ($event) => {
                formOpen.value = false;
                unref(router).reload({ only: ["partner"] });
              }
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$3, {
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
                  "is-refreshing": unref(isRefreshing),
                  "last-updated": unref(lastUpdated),
                  t: __props.t,
                  onRefresh: unref(refresh)
                }, null, 8, ["is-refreshing", "last-updated", "t", "onRefresh"]),
                createVNode("div", { class: "d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-4 border-bottom" }, [
                  createVNode("div", { class: "flex-grow-1" }, [
                    createVNode("div", { class: "d-flex align-items-center gap-2 flex-wrap" }, [
                      createVNode("h4", { class: "fw-bold mb-0" }, toDisplayString(__props.partner.name), 1),
                      createVNode("span", {
                        class: ["badge", __props.partner.status_badge]
                      }, toDisplayString(__props.partner.status_label), 3)
                    ]),
                    createVNode("div", { class: "text-muted small mt-1" }, toDisplayString(__props.partner.email), 1)
                  ]),
                  createVNode("div", { class: "d-flex gap-2" }, [
                    createVNode("a", {
                      href: _ctx.route("manager.partners.index"),
                      class: "btn btn-outline-secondary btn-sm"
                    }, [
                      createVNode("i", { class: "ti ti-arrow-left me-1" }),
                      createTextVNode(toDisplayString(__props.t.back), 1)
                    ], 8, ["href"]),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-primary btn-sm",
                      onClick: ($event) => formOpen.value = true
                    }, [
                      createVNode("i", { class: "ti ti-edit me-1" }),
                      createTextVNode(toDisplayString(__props.t.edit), 1)
                    ], 8, ["onClick"])
                  ])
                ]),
                createVNode("div", { class: "row g-4" }, [
                  createVNode("div", { class: "col-12 col-md-4" }, [
                    createVNode("div", { class: "card h-100" }, [
                      createVNode("div", { class: "card-header fw-semibold py-2" }, [
                        createVNode("i", { class: "ti ti-info-circle me-2 text-muted" }),
                        createTextVNode(toDisplayString(__props.t.sidebar_info), 1)
                      ]),
                      createVNode("div", { class: "card-body" }, [
                        createVNode("div", { class: "row g-2 mb-4" }, [
                          createVNode("div", { class: "col-6" }, [
                            createVNode("div", { class: "p-3 rounded border text-center" }, [
                              createVNode("div", { class: "fw-bold fs-20 lh-1 mb-1" }, toDisplayString(__props.partner.leads_total), 1),
                              createVNode("div", { class: "text-muted small" }, toDisplayString(__props.t.label_leads), 1)
                            ])
                          ]),
                          createVNode("div", { class: "col-6" }, [
                            createVNode("div", { class: "p-3 rounded border text-center" }, [
                              createVNode("div", { class: "fw-bold fs-20 lh-1 mb-1" }, toDisplayString(__props.partner.commissions_total), 1),
                              createVNode("div", { class: "text-muted small" }, toDisplayString(__props.t.label_commissions), 1)
                            ])
                          ])
                        ]),
                        createVNode("ul", { class: "list-unstyled mb-0 small" }, [
                          createVNode("li", { class: "d-flex justify-content-between align-items-center py-2 border-bottom" }, [
                            createVNode("span", { class: "text-muted" }, toDisplayString(__props.t.label_type), 1),
                            createVNode("span", { class: "badge badge-soft-secondary rounded fs-12" }, toDisplayString(__props.partner.type_label), 1)
                          ]),
                          createVNode("li", { class: "d-flex justify-content-between align-items-center py-2 border-bottom" }, [
                            createVNode("span", { class: "text-muted" }, toDisplayString(__props.t.label_status), 1),
                            createVNode("span", {
                              class: ["badge", __props.partner.status_badge]
                            }, toDisplayString(__props.partner.status_label), 3)
                          ]),
                          createVNode("li", { class: "d-flex justify-content-between align-items-center py-2 border-bottom" }, [
                            createVNode("span", { class: "text-muted" }, toDisplayString(__props.t.label_commission), 1),
                            createVNode("span", { class: "fw-semibold" }, toDisplayString(__props.partner.commission_rate) + "%", 1)
                          ]),
                          __props.partner.document ? (openBlock(), createBlock("li", {
                            key: 0,
                            class: "d-flex justify-content-between align-items-center py-2 border-bottom"
                          }, [
                            createVNode("span", { class: "text-muted" }, toDisplayString(__props.t.label_document), 1),
                            createVNode("span", null, toDisplayString(__props.partner.document), 1)
                          ])) : createCommentVNode("", true),
                          createVNode("li", { class: "d-flex justify-content-between align-items-center py-2 border-bottom" }, [
                            createVNode("span", { class: "text-muted" }, toDisplayString(__props.t.label_token), 1),
                            createVNode("div", { class: "d-flex align-items-center gap-1" }, [
                              createVNode("code", { class: "small" }, toDisplayString(__props.partner.token), 1),
                              createVNode("button", {
                                type: "button",
                                class: "btn btn-xs btn-outline-secondary",
                                title: __props.t.copy,
                                onClick: copyToken
                              }, [
                                createVNode("i", {
                                  class: tokenCopied.value ? "ti ti-check text-success" : "ti ti-copy"
                                }, null, 2)
                              ], 8, ["title"])
                            ])
                          ]),
                          createVNode("li", { class: "d-flex justify-content-between align-items-center py-2" }, [
                            createVNode("span", { class: "text-muted" }, toDisplayString(__props.t.label_registered), 1),
                            createVNode("span", null, toDisplayString(__props.partner.created_at), 1)
                          ])
                        ]),
                        __props.partner.notes ? (openBlock(), createBlock("div", {
                          key: 0,
                          class: "mt-3 p-3 rounded bg-light small text-muted"
                        }, [
                          createVNode("i", { class: "ti ti-notes me-1" }),
                          createTextVNode(toDisplayString(__props.partner.notes), 1)
                        ])) : createCommentVNode("", true)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "col-12 col-md-8" }, [
                    createVNode("div", { class: "card" }, [
                      createVNode("div", { class: "card-header p-0" }, [
                        createVNode("ul", { class: "nav nav-tabs border-bottom-0 px-3 pt-1" }, [
                          createVNode("li", { class: "nav-item" }, [
                            createVNode("button", {
                              class: ["nav-link fw-semibold", { active: activeTab.value === "leads" }],
                              onClick: ($event) => activeTab.value = "leads"
                            }, [
                              createVNode("i", { class: "ti ti-target-arrow me-1" }),
                              createTextVNode(toDisplayString(__props.t.tab_leads) + " ", 1),
                              createVNode("span", { class: "badge badge-soft-secondary rounded ms-1 fs-12" }, toDisplayString(((_g = __props.leads.meta) == null ? void 0 : _g.total) ?? 0), 1)
                            ], 10, ["onClick"])
                          ]),
                          createVNode("li", { class: "nav-item" }, [
                            createVNode("button", {
                              class: ["nav-link fw-semibold", { active: activeTab.value === "commissions" }],
                              onClick: ($event) => activeTab.value = "commissions"
                            }, [
                              createVNode("i", { class: "ti ti-cash me-1" }),
                              createTextVNode(toDisplayString(__props.t.tab_commissions) + " ", 1),
                              createVNode("span", { class: "badge badge-soft-secondary rounded ms-1 fs-12" }, toDisplayString(((_h = __props.commissions.meta) == null ? void 0 : _h.total) ?? 0), 1)
                            ], 10, ["onClick"])
                          ])
                        ])
                      ]),
                      withDirectives(createVNode("div", null, [
                        createVNode("div", { class: "table-responsive" }, [
                          createVNode("table", { class: "table table-nowrap align-middle mb-0" }, [
                            createVNode("thead", { class: "table-light" }, [
                              createVNode("tr", null, [
                                createVNode("th", null, toDisplayString(__props.t.col_name), 1),
                                createVNode("th", null, toDisplayString(__props.t.col_city), 1),
                                createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_status), 1),
                                createVNode("th", {
                                  class: "text-center",
                                  style: { "min-width": "140px" }
                                }, toDisplayString(__props.t.col_advance), 1),
                                createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_date), 1)
                              ])
                            ]),
                            createVNode("tbody", null, [
                              ((_i = __props.leads.data) == null ? void 0 : _i.length) === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                                createVNode("td", {
                                  colspan: "5",
                                  class: "text-center text-muted py-5"
                                }, [
                                  createVNode("i", { class: "ti ti-target-arrow fs-1 d-block mb-2 opacity-25" }),
                                  createTextVNode(" " + toDisplayString(__props.t.no_leads), 1)
                                ])
                              ])) : createCommentVNode("", true),
                              (openBlock(true), createBlock(Fragment, null, renderList(__props.leads.data, (lead) => {
                                return openBlock(), createBlock("tr", {
                                  key: lead.id
                                }, [
                                  createVNode("td", null, [
                                    createVNode("div", { class: "fw-semibold" }, toDisplayString(lead.name), 1),
                                    createVNode("div", { class: "text-muted small" }, toDisplayString(lead.email), 1)
                                  ]),
                                  createVNode("td", { class: "text-muted small" }, toDisplayString(lead.city_state ?? "—"), 1),
                                  createVNode("td", { class: "text-center" }, [
                                    createVNode("span", {
                                      class: ["badge", lead.status_badge]
                                    }, toDisplayString(lead.status_label), 3)
                                  ]),
                                  createVNode("td", { class: "text-center" }, [
                                    lead.is_active ? (openBlock(), createBlock("select", {
                                      key: 0,
                                      class: "form-select form-select-sm",
                                      style: { "min-width": "130px" },
                                      disabled: advancingId.value === lead.id,
                                      onChange: ($event) => {
                                        advanceLead(lead, $event.target.value);
                                        $event.target.value = "";
                                      }
                                    }, [
                                      createVNode("option", { value: "" }, toDisplayString(__props.t.field_advance), 1),
                                      (openBlock(true), createBlock(Fragment, null, renderList(__props.leadStatuses.filter((s) => s.value !== lead.status), (s) => {
                                        return openBlock(), createBlock("option", {
                                          key: s.value,
                                          value: s.value
                                        }, toDisplayString(s.label), 9, ["value"]);
                                      }), 128))
                                    ], 40, ["disabled", "onChange"])) : (openBlock(), createBlock("span", {
                                      key: 1,
                                      class: "text-muted small"
                                    }, "—"))
                                  ]),
                                  createVNode("td", { class: "text-center text-muted small" }, toDisplayString(lead.created_at), 1)
                                ]);
                              }), 128))
                            ])
                          ])
                        ]),
                        ((_j = __props.leads.meta) == null ? void 0 : _j.last_page) > 1 ? (openBlock(), createBlock("div", {
                          key: 0,
                          class: "d-flex justify-content-center py-3 gap-1"
                        }, [
                          createVNode("button", {
                            class: "btn btn-sm btn-outline-secondary",
                            disabled: __props.leads.meta.current_page === 1,
                            onClick: ($event) => goLeadsPage(__props.leads.meta.current_page - 1)
                          }, [
                            createVNode("i", { class: "ti ti-arrow-left" })
                          ], 8, ["disabled", "onClick"]),
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.leads.meta.last_page, (p) => {
                            return openBlock(), createBlock("button", {
                              key: p,
                              class: ["btn btn-sm", p === __props.leads.meta.current_page ? "btn-primary" : "btn-outline-secondary"],
                              onClick: ($event) => goLeadsPage(p)
                            }, toDisplayString(p), 11, ["onClick"]);
                          }), 128)),
                          createVNode("button", {
                            class: "btn btn-sm btn-outline-secondary",
                            disabled: __props.leads.meta.current_page === __props.leads.meta.last_page,
                            onClick: ($event) => goLeadsPage(__props.leads.meta.current_page + 1)
                          }, [
                            createVNode("i", { class: "ti ti-arrow-right" })
                          ], 8, ["disabled", "onClick"])
                        ])) : createCommentVNode("", true)
                      ], 512), [
                        [vShow, activeTab.value === "leads"]
                      ]),
                      withDirectives(createVNode("div", null, [
                        createVNode("div", { class: "table-responsive" }, [
                          createVNode("table", { class: "table table-nowrap align-middle mb-0" }, [
                            createVNode("thead", { class: "table-light" }, [
                              createVNode("tr", null, [
                                createVNode("th", null, toDisplayString(__props.t.col_clinic), 1),
                                createVNode("th", { class: "text-end" }, toDisplayString(__props.t.col_value), 1),
                                createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_rate), 1),
                                createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_period), 1),
                                createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_status), 1),
                                createVNode("th", { class: "text-center" }, toDisplayString(__props.t.col_due), 1)
                              ])
                            ]),
                            createVNode("tbody", null, [
                              ((_k = __props.commissions.data) == null ? void 0 : _k.length) === 0 ? (openBlock(), createBlock("tr", { key: 0 }, [
                                createVNode("td", {
                                  colspan: "6",
                                  class: "text-center text-muted py-5"
                                }, [
                                  createVNode("i", { class: "ti ti-cash fs-1 d-block mb-2 opacity-25" }),
                                  createTextVNode(" " + toDisplayString(__props.t.no_commissions), 1)
                                ])
                              ])) : createCommentVNode("", true),
                              (openBlock(true), createBlock(Fragment, null, renderList(__props.commissions.data, (c) => {
                                return openBlock(), createBlock("tr", {
                                  key: c.id
                                }, [
                                  createVNode("td", { class: "fw-semibold" }, toDisplayString(c.entity_name), 1),
                                  createVNode("td", { class: "text-end fw-semibold" }, toDisplayString(c.amount_fmt), 1),
                                  createVNode("td", { class: "text-center text-muted small" }, toDisplayString(c.rate) + "%", 1),
                                  createVNode("td", { class: "text-center text-muted small" }, toDisplayString(c.period), 1),
                                  createVNode("td", { class: "text-center" }, [
                                    createVNode("span", {
                                      class: ["badge", c.status_badge]
                                    }, toDisplayString(c.status_label), 3)
                                  ]),
                                  createVNode("td", { class: "text-center" }, [
                                    createVNode("div", { class: "d-flex align-items-center justify-content-center gap-2" }, [
                                      createVNode("span", { class: "small" }, toDisplayString(c.due_at ?? "—"), 1),
                                      c.is_pending ? (openBlock(), createBlock("button", {
                                        key: 0,
                                        class: "btn btn-xs btn-success",
                                        disabled: payingId.value === c.id,
                                        onClick: ($event) => payCommission(c)
                                      }, [
                                        payingId.value === c.id ? (openBlock(), createBlock("span", {
                                          key: 0,
                                          class: "spinner-border spinner-border-sm"
                                        })) : (openBlock(), createBlock("span", { key: 1 }, toDisplayString(__props.t.pay), 1))
                                      ], 8, ["disabled", "onClick"])) : createCommentVNode("", true)
                                    ])
                                  ])
                                ]);
                              }), 128))
                            ])
                          ])
                        ]),
                        ((_l = __props.commissions.meta) == null ? void 0 : _l.last_page) > 1 ? (openBlock(), createBlock("div", {
                          key: 0,
                          class: "d-flex justify-content-center py-3 gap-1"
                        }, [
                          createVNode("button", {
                            class: "btn btn-sm btn-outline-secondary",
                            disabled: __props.commissions.meta.current_page === 1,
                            onClick: ($event) => goCommissionsPage(__props.commissions.meta.current_page - 1)
                          }, [
                            createVNode("i", { class: "ti ti-arrow-left" })
                          ], 8, ["disabled", "onClick"]),
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.commissions.meta.last_page, (p) => {
                            return openBlock(), createBlock("button", {
                              key: p,
                              class: ["btn btn-sm", p === __props.commissions.meta.current_page ? "btn-primary" : "btn-outline-secondary"],
                              onClick: ($event) => goCommissionsPage(p)
                            }, toDisplayString(p), 11, ["onClick"]);
                          }), 128)),
                          createVNode("button", {
                            class: "btn btn-sm btn-outline-secondary",
                            disabled: __props.commissions.meta.current_page === __props.commissions.meta.last_page,
                            onClick: ($event) => goCommissionsPage(__props.commissions.meta.current_page + 1)
                          }, [
                            createVNode("i", { class: "ti ti-arrow-right" })
                          ], 8, ["disabled", "onClick"])
                        ])) : createCommentVNode("", true)
                      ], 512), [
                        [vShow, activeTab.value === "commissions"]
                      ])
                    ])
                  ])
                ])
              ]),
              createVNode(_sfc_main$2, {
                open: formOpen.value,
                "partner-id": __props.partner.id,
                "edit-data-url": __props.partner.edit_url,
                "update-url": __props.partner.update_url,
                "partner-types": __props.partnerTypes,
                t: __props.t,
                onClose: ($event) => formOpen.value = false,
                onSaved: ($event) => {
                  formOpen.value = false;
                  unref(router).reload({ only: ["partner"] });
                }
              }, null, 8, ["open", "partner-id", "edit-data-url", "update-url", "partner-types", "t", "onClose", "onSaved"]),
              createVNode(_sfc_main$3, {
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Partners/Show.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
