import { ref, watch, mergeProps, withCtx, openBlock, createBlock, Fragment, withDirectives, createVNode, toDisplayString, createTextVNode, createCommentVNode, vShow, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrRenderClass, ssrRenderList } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _sfc_main$1 } from "./BillingStateBadge-CJY8fYg_.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "SubscriptionDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    subscriptionId: { type: String, default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "edit"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const loading = ref(false);
    const subscription = ref(null);
    const activeTab = ref("overview");
    const invoices = ref([]);
    const retries = ref([]);
    const invoicesLoading = ref(false);
    const retriesLoading = ref(false);
    const invoicesLoaded = ref(false);
    const retriesLoaded = ref(false);
    async function loadDetail(id) {
      loading.value = true;
      subscription.value = null;
      activeTab.value = "overview";
      invoices.value = [];
      retries.value = [];
      invoicesLoaded.value = false;
      retriesLoaded.value = false;
      try {
        const res = await fetch(route("manager.subscriptions.show", id));
        const json = await res.json();
        subscription.value = json.data;
      } finally {
        loading.value = false;
      }
    }
    async function loadInvoices() {
      if (invoicesLoaded.value) return;
      invoicesLoading.value = true;
      try {
        const res = await fetch(route("manager.subscriptions.invoices", props.subscriptionId));
        const json = await res.json();
        invoices.value = json.data ?? [];
        invoicesLoaded.value = true;
      } finally {
        invoicesLoading.value = false;
      }
    }
    async function loadRetries() {
      if (retriesLoaded.value) return;
      retriesLoading.value = true;
      try {
        const res = await fetch(route("manager.subscriptions.retries", props.subscriptionId));
        const json = await res.json();
        retries.value = json.data ?? [];
        retriesLoaded.value = true;
      } finally {
        retriesLoading.value = false;
      }
    }
    function switchTab(tab) {
      activeTab.value = tab;
      if (tab === "invoices") loadInvoices();
      if (tab === "retries") loadRetries();
    }
    watch(() => props.open, (val) => {
      if (val && props.subscriptionId) loadDetail(props.subscriptionId);
      if (!val) subscription.value = null;
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 520,
        loading: loading.value,
        "loading-label": __props.t.loading,
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b;
          if (_push2) {
            _push2(`<div class="flex-grow-1 min-w-0" data-v-7e7b5447${_scopeId}><h5 class="mb-0 fw-semibold text-truncate" data-v-7e7b5447${_scopeId}><i class="fas fa-file-contract me-2 text-primary" data-v-7e7b5447${_scopeId}></i> ${ssrInterpolate(((_a = subscription.value) == null ? void 0 : _a.entity_name) ?? __props.t.loading)}</h5>`);
            if (subscription.value) {
              _push2(`<div class="mt-1 d-flex flex-wrap gap-1 align-items-center" data-v-7e7b5447${_scopeId}><span class="${ssrRenderClass([subscription.value.status_badge, "badge"])}" data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.status_label)}</span>`);
              if (subscription.value.billing_state) {
                _push2(ssrRenderComponent(_sfc_main$1, {
                  badge: subscription.value.billing_state_badge,
                  label: subscription.value.billing_state_label
                }, null, _parent2, _scopeId));
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (subscription.value) {
              _push2(`<button class="btn btn-sm btn-outline-primary flex-shrink-0 ms-2" data-v-7e7b5447${_scopeId}><i class="ti ti-edit me-1" data-v-7e7b5447${_scopeId}></i> ${ssrInterpolate(__props.t.detail_btn_edit)}</button>`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              createVNode("div", { class: "flex-grow-1 min-w-0" }, [
                createVNode("h5", { class: "mb-0 fw-semibold text-truncate" }, [
                  createVNode("i", { class: "fas fa-file-contract me-2 text-primary" }),
                  createTextVNode(" " + toDisplayString(((_b = subscription.value) == null ? void 0 : _b.entity_name) ?? __props.t.loading), 1)
                ]),
                subscription.value ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "mt-1 d-flex flex-wrap gap-1 align-items-center"
                }, [
                  createVNode("span", {
                    class: ["badge", subscription.value.status_badge]
                  }, toDisplayString(subscription.value.status_label), 3),
                  subscription.value.billing_state ? (openBlock(), createBlock(_sfc_main$1, {
                    key: 0,
                    badge: subscription.value.billing_state_badge,
                    label: subscription.value.billing_state_label
                  }, null, 8, ["badge", "label"])) : createCommentVNode("", true)
                ])) : createCommentVNode("", true)
              ]),
              subscription.value ? (openBlock(), createBlock("button", {
                key: 0,
                class: "btn btn-sm btn-outline-primary flex-shrink-0 ms-2",
                onClick: ($event) => _ctx.$emit("edit", subscription.value.id)
              }, [
                createVNode("i", { class: "ti ti-edit me-1" }),
                createTextVNode(" " + toDisplayString(__props.t.detail_btn_edit), 1)
              ], 8, ["onClick"])) : createCommentVNode("", true)
            ];
          }
        }),
        tabs: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<ul class="nav nav-tabs border-0" data-v-7e7b5447${_scopeId}><li class="nav-item" data-v-7e7b5447${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "overview" }, "nav-link"])}" data-v-7e7b5447${_scopeId}><i class="ti ti-info-circle me-1" data-v-7e7b5447${_scopeId}></i>${ssrInterpolate(__props.t.tab_overview)}</button></li><li class="nav-item" data-v-7e7b5447${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "invoices" }, "nav-link"])}" data-v-7e7b5447${_scopeId}><i class="ti ti-receipt me-1" data-v-7e7b5447${_scopeId}></i>${ssrInterpolate(__props.t.tab_invoices)}</button></li><li class="nav-item" data-v-7e7b5447${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "retries" }, "nav-link"])}" data-v-7e7b5447${_scopeId}><i class="ti ti-refresh-alert me-1" data-v-7e7b5447${_scopeId}></i>${ssrInterpolate(__props.t.tab_retries)}</button></li></ul>`);
          } else {
            return [
              createVNode("ul", { class: "nav nav-tabs border-0" }, [
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "overview" }],
                    onClick: ($event) => switchTab("overview")
                  }, [
                    createVNode("i", { class: "ti ti-info-circle me-1" }),
                    createTextVNode(toDisplayString(__props.t.tab_overview), 1)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "invoices" }],
                    onClick: ($event) => switchTab("invoices")
                  }, [
                    createVNode("i", { class: "ti ti-receipt me-1" }),
                    createTextVNode(toDisplayString(__props.t.tab_invoices), 1)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "retries" }],
                    onClick: ($event) => switchTab("retries")
                  }, [
                    createVNode("i", { class: "ti ti-refresh-alert me-1" }),
                    createTextVNode(toDisplayString(__props.t.tab_retries), 1)
                  ], 10, ["onClick"])
                ])
              ])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (subscription.value) {
              _push2(`<!--[--><div style="${ssrRenderStyle(activeTab.value === "overview" ? null : { display: "none" })}" data-v-7e7b5447${_scopeId}><div class="sdd-section" data-v-7e7b5447${_scopeId}><table class="table table-sm mb-0" data-v-7e7b5447${_scopeId}><tbody data-v-7e7b5447${_scopeId}><tr data-v-7e7b5447${_scopeId}><th class="ps-0" style="${ssrRenderStyle({ "width": "45%" })}" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_entity)}</th><td data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.entity_name)}</td></tr><tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_plan)}</th><td data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.plan_name)}</td></tr></tbody></table></div><div class="sdd-section" data-v-7e7b5447${_scopeId}><div class="sdd-section__title" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.section_subscription_status)}</div><table class="table table-sm mb-0" data-v-7e7b5447${_scopeId}><tbody data-v-7e7b5447${_scopeId}><tr data-v-7e7b5447${_scopeId}><th class="ps-0" style="${ssrRenderStyle({ "width": "45%" })}" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_status)}</th><td data-v-7e7b5447${_scopeId}><span class="${ssrRenderClass([subscription.value.status_badge, "badge"])}" data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.status_label)}</span></td></tr><tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_billing_state)}</th><td data-v-7e7b5447${_scopeId}>`);
              _push2(ssrRenderComponent(_sfc_main$1, {
                badge: subscription.value.billing_state_badge,
                label: subscription.value.billing_state_label,
                state: subscription.value.billing_state
              }, null, _parent2, _scopeId));
              _push2(`</td></tr>`);
              if (subscription.value.last_billing_error) {
                _push2(`<tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_billing_error)}</th><td data-v-7e7b5447${_scopeId}><div class="alert alert-danger py-1 px-2 small mb-0" data-v-7e7b5447${_scopeId}><i class="ti ti-alert-circle me-1" data-v-7e7b5447${_scopeId}></i>${ssrInterpolate(subscription.value.last_billing_error)}</div></td></tr>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</tbody></table></div><div class="sdd-section" data-v-7e7b5447${_scopeId}><div class="sdd-section__title" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.section_gateway)}</div><table class="table table-sm mb-0" data-v-7e7b5447${_scopeId}><tbody data-v-7e7b5447${_scopeId}><tr data-v-7e7b5447${_scopeId}><th class="ps-0" style="${ssrRenderStyle({ "width": "45%" })}" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_gateway)}</th><td data-v-7e7b5447${_scopeId}>`);
              if (subscription.value.gateway) {
                _push2(`<span class="badge badge-soft-primary text-uppercase" data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.gateway)}</span>`);
              } else {
                _push2(`<span class="text-muted small" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_gateway_empty)}</span>`);
              }
              _push2(`</td></tr>`);
              if (subscription.value.gateway_customer_id) {
                _push2(`<tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_gateway_customer_id)}</th><td data-v-7e7b5447${_scopeId}><code class="small" data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.gateway_customer_id)}</code></td></tr>`);
              } else {
                _push2(`<!---->`);
              }
              if (subscription.value.gateway_subscription_id) {
                _push2(`<tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_gateway_subscription_id)}</th><td data-v-7e7b5447${_scopeId}><code class="small" data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.gateway_subscription_id)}</code></td></tr>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</tbody></table></div><div class="sdd-section" data-v-7e7b5447${_scopeId}><div class="sdd-section__title" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.section_dates)}</div><table class="table table-sm mb-0" data-v-7e7b5447${_scopeId}><tbody data-v-7e7b5447${_scopeId}><tr data-v-7e7b5447${_scopeId}><th class="ps-0" style="${ssrRenderStyle({ "width": "45%" })}" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_starts_at)}</th><td data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.starts_at ?? "—")}</td></tr><tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_ends_at)}</th><td data-v-7e7b5447${_scopeId}>`);
              if (subscription.value.ends_at) {
                _push2(`<span data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.ends_at)}</span>`);
              } else {
                _push2(`<span class="text-muted" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_ends_at_lifetime)}</span>`);
              }
              _push2(`</td></tr>`);
              if (subscription.value.next_billing_at) {
                _push2(`<tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_next_billing)}</th><td data-v-7e7b5447${_scopeId}><span class="fw-semibold" data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.next_billing_at)}</span>`);
                if (subscription.value.next_billing_overdue) {
                  _push2(`<span class="badge badge-soft-danger ms-1" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_next_billing_overdue)}</span>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</td></tr>`);
              } else {
                _push2(`<!---->`);
              }
              if (subscription.value.last_payment_at) {
                _push2(`<tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_last_payment)}</th><td data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.last_payment_at)}</td></tr>`);
              } else {
                _push2(`<!---->`);
              }
              if (subscription.value.trial_ends_at) {
                _push2(`<tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_trial_ends_at)}</th><td data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.trial_ends_at)}</td></tr>`);
              } else {
                _push2(`<!---->`);
              }
              if (subscription.value.grace_period_ends_at) {
                _push2(`<tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_grace_period_ends_at)}</th><td data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.grace_period_ends_at)}</td></tr>`);
              } else {
                _push2(`<!---->`);
              }
              if (subscription.value.cancelled_at) {
                _push2(`<tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_cancelled_at)}</th><td data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.cancelled_at)}</td></tr>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<tr data-v-7e7b5447${_scopeId}><th class="ps-0" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.detail_created_at)}</th><td data-v-7e7b5447${_scopeId}>${ssrInterpolate(subscription.value.created_at)}</td></tr></tbody></table></div></div><div style="${ssrRenderStyle(activeTab.value === "invoices" ? null : { display: "none" })}" data-v-7e7b5447${_scopeId}>`);
              if (invoicesLoading.value) {
                _push2(`<div class="text-center py-4" data-v-7e7b5447${_scopeId}><div class="spinner-border spinner-border-sm text-primary" data-v-7e7b5447${_scopeId}></div><span class="ms-2 text-muted small" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.loading)}</span></div>`);
              } else {
                _push2(`<!--[-->`);
                if (invoices.value.length === 0) {
                  _push2(`<div class="text-center py-5 text-muted" data-v-7e7b5447${_scopeId}><i class="ti ti-receipt fs-2 d-block mb-2 opacity-50" data-v-7e7b5447${_scopeId}></i> ${ssrInterpolate(__props.t.empty_invoices)}</div>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`<!--[-->`);
                ssrRenderList(invoices.value, (inv) => {
                  _push2(`<div class="card mb-2" data-v-7e7b5447${_scopeId}><div class="card-header py-2 d-flex align-items-center justify-content-between" data-v-7e7b5447${_scopeId}><div class="d-flex align-items-center gap-2 flex-wrap" data-v-7e7b5447${_scopeId}><span class="fw-semibold small" data-v-7e7b5447${_scopeId}>${ssrInterpolate(inv.reference)}</span><span class="${ssrRenderClass([inv.status_badge, "badge"])}" data-v-7e7b5447${_scopeId}>${ssrInterpolate(inv.status_label)}</span>`);
                  if (inv.billing_reason) {
                    _push2(`<span class="badge badge-soft-secondary" data-v-7e7b5447${_scopeId}>${ssrInterpolate(inv.billing_reason)}</span>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  _push2(`</div><div class="text-end flex-shrink-0" data-v-7e7b5447${_scopeId}><span class="fw-bold text-success" data-v-7e7b5447${_scopeId}>R$ ${ssrInterpolate(inv.amount)}</span>`);
                  if (inv.gateway_code) {
                    _push2(`<span class="badge badge-soft-primary ms-1 text-uppercase" data-v-7e7b5447${_scopeId}>${ssrInterpolate(inv.gateway_code)}</span>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  _push2(`</div></div><div class="card-body py-2 small text-muted" data-v-7e7b5447${_scopeId}><div class="d-flex flex-wrap gap-3" data-v-7e7b5447${_scopeId}>`);
                  if (inv.period_start) {
                    _push2(`<span data-v-7e7b5447${_scopeId}><i class="ti ti-calendar me-1" data-v-7e7b5447${_scopeId}></i>${ssrInterpolate(__props.t.invoice_period)} ${ssrInterpolate(inv.period_start)} – ${ssrInterpolate(inv.period_end)}</span>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  if (inv.due_at) {
                    _push2(`<span data-v-7e7b5447${_scopeId}><i class="ti ti-clock me-1" data-v-7e7b5447${_scopeId}></i>${ssrInterpolate(__props.t.invoice_due_at)} ${ssrInterpolate(inv.due_at)}</span>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  if (inv.paid_at) {
                    _push2(`<span class="text-success" data-v-7e7b5447${_scopeId}><i class="ti ti-check me-1" data-v-7e7b5447${_scopeId}></i>${ssrInterpolate(__props.t.invoice_paid_at)} ${ssrInterpolate(inv.paid_at)}</span>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  _push2(`</div>`);
                  if (inv.payments && inv.payments.length > 0) {
                    _push2(`<div class="mt-2 border-top pt-2" data-v-7e7b5447${_scopeId}><div class="fw-semibold text-body mb-1" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.invoice_payments)}</div><!--[-->`);
                    ssrRenderList(inv.payments, (pay) => {
                      _push2(`<div class="d-flex align-items-center justify-content-between py-1 border-bottom" data-v-7e7b5447${_scopeId}><div class="d-flex align-items-center gap-2 flex-wrap" data-v-7e7b5447${_scopeId}><span class="${ssrRenderClass([pay.status_badge, "badge"])}" data-v-7e7b5447${_scopeId}>${ssrInterpolate(pay.status)}</span><span class="text-uppercase" data-v-7e7b5447${_scopeId}>${ssrInterpolate(pay.gateway_code)}</span>`);
                      if (pay.external_payment_id) {
                        _push2(`<code class="small" data-v-7e7b5447${_scopeId}>${ssrInterpolate(pay.external_payment_id)}</code>`);
                      } else {
                        _push2(`<!---->`);
                      }
                      _push2(`</div><div class="text-end" data-v-7e7b5447${_scopeId}><div class="fw-semibold" data-v-7e7b5447${_scopeId}>R$ ${ssrInterpolate(pay.amount)}</div>`);
                      if (pay.paid_at) {
                        _push2(`<div class="text-success small" data-v-7e7b5447${_scopeId}><i class="ti ti-check me-1" data-v-7e7b5447${_scopeId}></i>${ssrInterpolate(pay.paid_at)}</div>`);
                      } else {
                        _push2(`<!---->`);
                      }
                      if (pay.failed_at) {
                        _push2(`<div class="text-danger small" data-v-7e7b5447${_scopeId}><i class="ti ti-x me-1" data-v-7e7b5447${_scopeId}></i>${ssrInterpolate(pay.failed_at)}</div>`);
                      } else {
                        _push2(`<!---->`);
                      }
                      _push2(`</div></div>`);
                    });
                    _push2(`<!--]--></div>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  _push2(`</div></div>`);
                });
                _push2(`<!--]--><!--]-->`);
              }
              _push2(`</div><div style="${ssrRenderStyle(activeTab.value === "retries" ? null : { display: "none" })}" data-v-7e7b5447${_scopeId}>`);
              if (retriesLoading.value) {
                _push2(`<div class="text-center py-4" data-v-7e7b5447${_scopeId}><div class="spinner-border spinner-border-sm text-primary" data-v-7e7b5447${_scopeId}></div><span class="ms-2 text-muted small" data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.loading)}</span></div>`);
              } else {
                _push2(`<!--[-->`);
                if (retries.value.length === 0) {
                  _push2(`<div class="text-center py-5 text-muted" data-v-7e7b5447${_scopeId}><i class="ti ti-refresh-alert fs-2 d-block mb-2 opacity-50" data-v-7e7b5447${_scopeId}></i> ${ssrInterpolate(__props.t.empty_retries)}</div>`);
                } else {
                  _push2(`<div class="table-responsive" data-v-7e7b5447${_scopeId}><table class="table table-sm table-bordered align-middle" data-v-7e7b5447${_scopeId}><thead class="table-light" data-v-7e7b5447${_scopeId}><tr data-v-7e7b5447${_scopeId}><th data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.retry_col_attempt)}</th><th data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.retry_col_status)}</th><th data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.retry_col_gateway)}</th><th data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.retry_col_scheduled)}</th><th data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.retry_col_executed)}</th><th data-v-7e7b5447${_scopeId}>${ssrInterpolate(__props.t.retry_col_result)}</th></tr></thead><tbody data-v-7e7b5447${_scopeId}><!--[-->`);
                  ssrRenderList(retries.value, (retry) => {
                    _push2(`<tr data-v-7e7b5447${_scopeId}><td class="text-center fw-bold" data-v-7e7b5447${_scopeId}>#${ssrInterpolate(retry.attempt_number)}</td><td data-v-7e7b5447${_scopeId}><span class="${ssrRenderClass([retry.status_badge, "badge"])}" data-v-7e7b5447${_scopeId}>${ssrInterpolate(retry.status)}</span></td><td class="text-uppercase small" data-v-7e7b5447${_scopeId}>${ssrInterpolate(retry.gateway_code ?? "—")}</td><td class="small" data-v-7e7b5447${_scopeId}>${ssrInterpolate(retry.scheduled_for)}</td><td class="small" data-v-7e7b5447${_scopeId}>${ssrInterpolate(retry.executed_at ?? "—")}</td><td class="small text-muted" data-v-7e7b5447${_scopeId}>${ssrInterpolate(retry.result_message ?? "—")}</td></tr>`);
                  });
                  _push2(`<!--]--></tbody></table></div>`);
                }
                _push2(`<!--]-->`);
              }
              _push2(`</div><!--]-->`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              subscription.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "sdd-section" }, [
                    createVNode("table", { class: "table table-sm mb-0" }, [
                      createVNode("tbody", null, [
                        createVNode("tr", null, [
                          createVNode("th", {
                            class: "ps-0",
                            style: { "width": "45%" }
                          }, toDisplayString(__props.t.detail_entity), 1),
                          createVNode("td", null, toDisplayString(subscription.value.entity_name), 1)
                        ]),
                        createVNode("tr", null, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_plan), 1),
                          createVNode("td", null, toDisplayString(subscription.value.plan_name), 1)
                        ])
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "sdd-section" }, [
                    createVNode("div", { class: "sdd-section__title" }, toDisplayString(__props.t.section_subscription_status), 1),
                    createVNode("table", { class: "table table-sm mb-0" }, [
                      createVNode("tbody", null, [
                        createVNode("tr", null, [
                          createVNode("th", {
                            class: "ps-0",
                            style: { "width": "45%" }
                          }, toDisplayString(__props.t.detail_status), 1),
                          createVNode("td", null, [
                            createVNode("span", {
                              class: ["badge", subscription.value.status_badge]
                            }, toDisplayString(subscription.value.status_label), 3)
                          ])
                        ]),
                        createVNode("tr", null, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_billing_state), 1),
                          createVNode("td", null, [
                            createVNode(_sfc_main$1, {
                              badge: subscription.value.billing_state_badge,
                              label: subscription.value.billing_state_label,
                              state: subscription.value.billing_state
                            }, null, 8, ["badge", "label", "state"])
                          ])
                        ]),
                        subscription.value.last_billing_error ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_billing_error), 1),
                          createVNode("td", null, [
                            createVNode("div", { class: "alert alert-danger py-1 px-2 small mb-0" }, [
                              createVNode("i", { class: "ti ti-alert-circle me-1" }),
                              createTextVNode(toDisplayString(subscription.value.last_billing_error), 1)
                            ])
                          ])
                        ])) : createCommentVNode("", true)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "sdd-section" }, [
                    createVNode("div", { class: "sdd-section__title" }, toDisplayString(__props.t.section_gateway), 1),
                    createVNode("table", { class: "table table-sm mb-0" }, [
                      createVNode("tbody", null, [
                        createVNode("tr", null, [
                          createVNode("th", {
                            class: "ps-0",
                            style: { "width": "45%" }
                          }, toDisplayString(__props.t.detail_gateway), 1),
                          createVNode("td", null, [
                            subscription.value.gateway ? (openBlock(), createBlock("span", {
                              key: 0,
                              class: "badge badge-soft-primary text-uppercase"
                            }, toDisplayString(subscription.value.gateway), 1)) : (openBlock(), createBlock("span", {
                              key: 1,
                              class: "text-muted small"
                            }, toDisplayString(__props.t.detail_gateway_empty), 1))
                          ])
                        ]),
                        subscription.value.gateway_customer_id ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_gateway_customer_id), 1),
                          createVNode("td", null, [
                            createVNode("code", { class: "small" }, toDisplayString(subscription.value.gateway_customer_id), 1)
                          ])
                        ])) : createCommentVNode("", true),
                        subscription.value.gateway_subscription_id ? (openBlock(), createBlock("tr", { key: 1 }, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_gateway_subscription_id), 1),
                          createVNode("td", null, [
                            createVNode("code", { class: "small" }, toDisplayString(subscription.value.gateway_subscription_id), 1)
                          ])
                        ])) : createCommentVNode("", true)
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "sdd-section" }, [
                    createVNode("div", { class: "sdd-section__title" }, toDisplayString(__props.t.section_dates), 1),
                    createVNode("table", { class: "table table-sm mb-0" }, [
                      createVNode("tbody", null, [
                        createVNode("tr", null, [
                          createVNode("th", {
                            class: "ps-0",
                            style: { "width": "45%" }
                          }, toDisplayString(__props.t.detail_starts_at), 1),
                          createVNode("td", null, toDisplayString(subscription.value.starts_at ?? "—"), 1)
                        ]),
                        createVNode("tr", null, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_ends_at), 1),
                          createVNode("td", null, [
                            subscription.value.ends_at ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString(subscription.value.ends_at), 1)) : (openBlock(), createBlock("span", {
                              key: 1,
                              class: "text-muted"
                            }, toDisplayString(__props.t.detail_ends_at_lifetime), 1))
                          ])
                        ]),
                        subscription.value.next_billing_at ? (openBlock(), createBlock("tr", { key: 0 }, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_next_billing), 1),
                          createVNode("td", null, [
                            createVNode("span", { class: "fw-semibold" }, toDisplayString(subscription.value.next_billing_at), 1),
                            subscription.value.next_billing_overdue ? (openBlock(), createBlock("span", {
                              key: 0,
                              class: "badge badge-soft-danger ms-1"
                            }, toDisplayString(__props.t.detail_next_billing_overdue), 1)) : createCommentVNode("", true)
                          ])
                        ])) : createCommentVNode("", true),
                        subscription.value.last_payment_at ? (openBlock(), createBlock("tr", { key: 1 }, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_last_payment), 1),
                          createVNode("td", null, toDisplayString(subscription.value.last_payment_at), 1)
                        ])) : createCommentVNode("", true),
                        subscription.value.trial_ends_at ? (openBlock(), createBlock("tr", { key: 2 }, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_trial_ends_at), 1),
                          createVNode("td", null, toDisplayString(subscription.value.trial_ends_at), 1)
                        ])) : createCommentVNode("", true),
                        subscription.value.grace_period_ends_at ? (openBlock(), createBlock("tr", { key: 3 }, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_grace_period_ends_at), 1),
                          createVNode("td", null, toDisplayString(subscription.value.grace_period_ends_at), 1)
                        ])) : createCommentVNode("", true),
                        subscription.value.cancelled_at ? (openBlock(), createBlock("tr", { key: 4 }, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_cancelled_at), 1),
                          createVNode("td", null, toDisplayString(subscription.value.cancelled_at), 1)
                        ])) : createCommentVNode("", true),
                        createVNode("tr", null, [
                          createVNode("th", { class: "ps-0" }, toDisplayString(__props.t.detail_created_at), 1),
                          createVNode("td", null, toDisplayString(subscription.value.created_at), 1)
                        ])
                      ])
                    ])
                  ])
                ], 512), [
                  [vShow, activeTab.value === "overview"]
                ]),
                withDirectives(createVNode("div", null, [
                  invoicesLoading.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "text-center py-4"
                  }, [
                    createVNode("div", { class: "spinner-border spinner-border-sm text-primary" }),
                    createVNode("span", { class: "ms-2 text-muted small" }, toDisplayString(__props.t.loading), 1)
                  ])) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                    invoices.value.length === 0 ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "text-center py-5 text-muted"
                    }, [
                      createVNode("i", { class: "ti ti-receipt fs-2 d-block mb-2 opacity-50" }),
                      createTextVNode(" " + toDisplayString(__props.t.empty_invoices), 1)
                    ])) : createCommentVNode("", true),
                    (openBlock(true), createBlock(Fragment, null, renderList(invoices.value, (inv) => {
                      return openBlock(), createBlock("div", {
                        key: inv.id,
                        class: "card mb-2"
                      }, [
                        createVNode("div", { class: "card-header py-2 d-flex align-items-center justify-content-between" }, [
                          createVNode("div", { class: "d-flex align-items-center gap-2 flex-wrap" }, [
                            createVNode("span", { class: "fw-semibold small" }, toDisplayString(inv.reference), 1),
                            createVNode("span", {
                              class: ["badge", inv.status_badge]
                            }, toDisplayString(inv.status_label), 3),
                            inv.billing_reason ? (openBlock(), createBlock("span", {
                              key: 0,
                              class: "badge badge-soft-secondary"
                            }, toDisplayString(inv.billing_reason), 1)) : createCommentVNode("", true)
                          ]),
                          createVNode("div", { class: "text-end flex-shrink-0" }, [
                            createVNode("span", { class: "fw-bold text-success" }, "R$ " + toDisplayString(inv.amount), 1),
                            inv.gateway_code ? (openBlock(), createBlock("span", {
                              key: 0,
                              class: "badge badge-soft-primary ms-1 text-uppercase"
                            }, toDisplayString(inv.gateway_code), 1)) : createCommentVNode("", true)
                          ])
                        ]),
                        createVNode("div", { class: "card-body py-2 small text-muted" }, [
                          createVNode("div", { class: "d-flex flex-wrap gap-3" }, [
                            inv.period_start ? (openBlock(), createBlock("span", { key: 0 }, [
                              createVNode("i", { class: "ti ti-calendar me-1" }),
                              createTextVNode(toDisplayString(__props.t.invoice_period) + " " + toDisplayString(inv.period_start) + " – " + toDisplayString(inv.period_end), 1)
                            ])) : createCommentVNode("", true),
                            inv.due_at ? (openBlock(), createBlock("span", { key: 1 }, [
                              createVNode("i", { class: "ti ti-clock me-1" }),
                              createTextVNode(toDisplayString(__props.t.invoice_due_at) + " " + toDisplayString(inv.due_at), 1)
                            ])) : createCommentVNode("", true),
                            inv.paid_at ? (openBlock(), createBlock("span", {
                              key: 2,
                              class: "text-success"
                            }, [
                              createVNode("i", { class: "ti ti-check me-1" }),
                              createTextVNode(toDisplayString(__props.t.invoice_paid_at) + " " + toDisplayString(inv.paid_at), 1)
                            ])) : createCommentVNode("", true)
                          ]),
                          inv.payments && inv.payments.length > 0 ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "mt-2 border-top pt-2"
                          }, [
                            createVNode("div", { class: "fw-semibold text-body mb-1" }, toDisplayString(__props.t.invoice_payments), 1),
                            (openBlock(true), createBlock(Fragment, null, renderList(inv.payments, (pay) => {
                              return openBlock(), createBlock("div", {
                                key: pay.id,
                                class: "d-flex align-items-center justify-content-between py-1 border-bottom"
                              }, [
                                createVNode("div", { class: "d-flex align-items-center gap-2 flex-wrap" }, [
                                  createVNode("span", {
                                    class: ["badge", pay.status_badge]
                                  }, toDisplayString(pay.status), 3),
                                  createVNode("span", { class: "text-uppercase" }, toDisplayString(pay.gateway_code), 1),
                                  pay.external_payment_id ? (openBlock(), createBlock("code", {
                                    key: 0,
                                    class: "small"
                                  }, toDisplayString(pay.external_payment_id), 1)) : createCommentVNode("", true)
                                ]),
                                createVNode("div", { class: "text-end" }, [
                                  createVNode("div", { class: "fw-semibold" }, "R$ " + toDisplayString(pay.amount), 1),
                                  pay.paid_at ? (openBlock(), createBlock("div", {
                                    key: 0,
                                    class: "text-success small"
                                  }, [
                                    createVNode("i", { class: "ti ti-check me-1" }),
                                    createTextVNode(toDisplayString(pay.paid_at), 1)
                                  ])) : createCommentVNode("", true),
                                  pay.failed_at ? (openBlock(), createBlock("div", {
                                    key: 1,
                                    class: "text-danger small"
                                  }, [
                                    createVNode("i", { class: "ti ti-x me-1" }),
                                    createTextVNode(toDisplayString(pay.failed_at), 1)
                                  ])) : createCommentVNode("", true)
                                ])
                              ]);
                            }), 128))
                          ])) : createCommentVNode("", true)
                        ])
                      ]);
                    }), 128))
                  ], 64))
                ], 512), [
                  [vShow, activeTab.value === "invoices"]
                ]),
                withDirectives(createVNode("div", null, [
                  retriesLoading.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "text-center py-4"
                  }, [
                    createVNode("div", { class: "spinner-border spinner-border-sm text-primary" }),
                    createVNode("span", { class: "ms-2 text-muted small" }, toDisplayString(__props.t.loading), 1)
                  ])) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                    retries.value.length === 0 ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "text-center py-5 text-muted"
                    }, [
                      createVNode("i", { class: "ti ti-refresh-alert fs-2 d-block mb-2 opacity-50" }),
                      createTextVNode(" " + toDisplayString(__props.t.empty_retries), 1)
                    ])) : (openBlock(), createBlock("div", {
                      key: 1,
                      class: "table-responsive"
                    }, [
                      createVNode("table", { class: "table table-sm table-bordered align-middle" }, [
                        createVNode("thead", { class: "table-light" }, [
                          createVNode("tr", null, [
                            createVNode("th", null, toDisplayString(__props.t.retry_col_attempt), 1),
                            createVNode("th", null, toDisplayString(__props.t.retry_col_status), 1),
                            createVNode("th", null, toDisplayString(__props.t.retry_col_gateway), 1),
                            createVNode("th", null, toDisplayString(__props.t.retry_col_scheduled), 1),
                            createVNode("th", null, toDisplayString(__props.t.retry_col_executed), 1),
                            createVNode("th", null, toDisplayString(__props.t.retry_col_result), 1)
                          ])
                        ]),
                        createVNode("tbody", null, [
                          (openBlock(true), createBlock(Fragment, null, renderList(retries.value, (retry) => {
                            return openBlock(), createBlock("tr", {
                              key: retry.id
                            }, [
                              createVNode("td", { class: "text-center fw-bold" }, "#" + toDisplayString(retry.attempt_number), 1),
                              createVNode("td", null, [
                                createVNode("span", {
                                  class: ["badge", retry.status_badge]
                                }, toDisplayString(retry.status), 3)
                              ]),
                              createVNode("td", { class: "text-uppercase small" }, toDisplayString(retry.gateway_code ?? "—"), 1),
                              createVNode("td", { class: "small" }, toDisplayString(retry.scheduled_for), 1),
                              createVNode("td", { class: "small" }, toDisplayString(retry.executed_at ?? "—"), 1),
                              createVNode("td", { class: "small text-muted" }, toDisplayString(retry.result_message ?? "—"), 1)
                            ]);
                          }), 128))
                        ])
                      ])
                    ]))
                  ], 64))
                ], 512), [
                  [vShow, activeTab.value === "retries"]
                ])
              ], 64)) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Subscriptions/SubscriptionDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const SubscriptionDetailDrawer = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-7e7b5447"]]);
export {
  SubscriptionDetailDrawer as default
};
