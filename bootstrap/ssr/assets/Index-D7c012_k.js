import { ref, watch, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, withModifiers, withDirectives, vModelText, openBlock, createBlock, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-HN8TQqXs.js";
import { _ as _sfc_main$2 } from "./SearchInput-DBX1PwVy.js";
import _sfc_main$3 from "./SubscriptionTable-Cjvdxtgr.js";
import _sfc_main$4 from "./SubscriptionCards-C9aQg4eh.js";
import SubscriptionDetailDrawer from "./SubscriptionDetailDrawer-Bxltg8fB.js";
import _sfc_main$5 from "./SubscriptionFormModal-BhEu0DHr.js";
import _sfc_main$6 from "./SubscriptionActivateModal-BciEwjzP.js";
import _sfc_main$7 from "./SubscriptionTrialModal-CNSh4P-l.js";
import { _ as _sfc_main$8 } from "./ConfirmationWithReasonModal-CmfO7qbN.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./SortableTh-B7Fp64cd.js";
import "./BillingStateBadge-CJY8fYg_.js";
import "./TablePagination-Dj1_H7YG.js";
import "./ActionDropdown-DZW_71Hn.js";
import "./ActionIconGroup-Dj2wQrik.js";
import "./CardsPagination-B87u3Z8A.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    subscriptions: { type: Object, required: true },
    total: { type: Number, default: 0 },
    filters: { type: Object, default: () => ({}) },
    plans: { type: Array, default: () => [] },
    billingCycles: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    gateways: { type: Array, default: () => [] },
    trialDays: { type: Number, default: 14 },
    graceDays: { type: Number, default: 3 },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const view = ref(localStorage.getItem("mgr_subscriptions_view") ?? "table");
    function setView(v) {
      view.value = v;
      localStorage.setItem("mgr_subscriptions_view", v);
    }
    const search = ref(props.filters.search ?? "");
    let searchTimer = null;
    watch(search, (val) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        router.get(
          route("manager.subscriptions.index"),
          { search: val, sort: props.filters.sort, direction: props.filters.direction },
          { preserveState: true, preserveScroll: true, replace: true }
        );
      }, 400);
    });
    function onSort({ sort, direction }) {
      router.get(
        route("manager.subscriptions.index"),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true }
      );
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
    const formOpen = ref(false);
    const formId = ref(null);
    function openEdit(id) {
      formId.value = id;
      formOpen.value = true;
    }
    function closeForm() {
      formOpen.value = false;
      formId.value = null;
    }
    const activateOpen = ref(false);
    const activateSubscription = ref(null);
    function openActivate(s) {
      activateSubscription.value = s;
      activateOpen.value = true;
    }
    function closeActivate() {
      activateOpen.value = false;
      activateSubscription.value = null;
    }
    const trialOpen = ref(false);
    const trialSubscription = ref(null);
    function openTrial(s) {
      trialSubscription.value = s;
      trialOpen.value = true;
    }
    function closeTrial() {
      trialOpen.value = false;
      trialSubscription.value = null;
    }
    const reasonModal = ref({
      open: false,
      saving: false,
      title: "",
      message: "",
      confirmLabel: "",
      confirmVariant: "danger",
      onConfirm: null
      // (reason: string) => Promise<void>
    });
    function openReasonModal(config) {
      reasonModal.value = {
        open: true,
        saving: false,
        title: config.title ?? "",
        message: config.message ?? "",
        confirmLabel: config.confirmLabel ?? "",
        confirmVariant: config.confirmVariant ?? "danger",
        onConfirm: config.onConfirm
      };
    }
    function closeReasonModal() {
      if (reasonModal.value.saving) return;
      reasonModal.value.open = false;
    }
    async function handleReasonConfirm(reason) {
      if (!reasonModal.value.onConfirm) return;
      reasonModal.value.saving = true;
      try {
        await reasonModal.value.onConfirm(reason);
        reasonModal.value.open = false;
      } finally {
        reasonModal.value.saving = false;
      }
    }
    function onCancel(s) {
      openReasonModal({
        title: props.t.confirm_cancel_title,
        message: props.t.confirm_cancel_text,
        confirmVariant: "danger",
        async onConfirm(reason) {
          var _a;
          const res = await fetch(route("manager.subscriptions.cancel"), {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "",
              "Accept": "application/json"
            },
            body: JSON.stringify({ entity_id: s.entity_id, reason })
          });
          const json = await res.json();
          if (res.ok) {
            showToast(json.message, "success");
            router.reload({ only: ["subscriptions", "total"] });
          } else {
            showToast(json.message ?? "Erro", "error");
          }
        }
      });
    }
    function onBlock(s) {
      const blocking = s.entity_active;
      openReasonModal({
        title: blocking ? props.t.confirm_block_title : props.t.confirm_unblock_title,
        message: blocking ? props.t.confirm_block_text : props.t.confirm_unblock_text,
        confirmVariant: blocking ? "danger" : "warning",
        async onConfirm(reason) {
          var _a;
          const res = await fetch(route("manager.subscriptions.block-access"), {
            method: "PATCH",
            headers: {
              "Content-Type": "application/json",
              "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "",
              "Accept": "application/json"
            },
            body: JSON.stringify({ entity_id: s.entity_id, active: !blocking, reason })
          });
          const json = await res.json();
          if (res.ok) {
            showToast(json.message, "success");
            router.reload({ only: ["subscriptions", "total"] });
          } else {
            showToast(json.message ?? "Erro", "error");
          }
        }
      });
    }
    const settingsForm = ref({ trial_days: props.trialDays, grace_period_days: props.graceDays });
    const settingsSaving = ref(false);
    async function saveSettings() {
      var _a;
      settingsSaving.value = true;
      try {
        const res = await fetch(route("manager.subscriptions.settings"), {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "",
            "Accept": "application/json"
          },
          body: JSON.stringify(settingsForm.value)
        });
        const json = await res.json();
        showToast(json.message ?? props.t.settings_saved, res.ok ? "success" : "error");
        if (res.ok) router.reload({ only: ["trialDays", "graceDays"] });
      } finally {
        settingsSaving.value = false;
      }
    }
    function showToast(msg, type = "success") {
      if (type === "success" && window.showSuccessToast) return window.showSuccessToast(msg);
      if (type === "error" && window.showErrorToast) return window.showErrorToast(msg);
      alert(msg);
    }
    const breadcrumbs = [
      { label: props.t.breadcrumb_home ?? "Dashboard", url: route("panel.dashboard"), active: false },
      { label: props.t.breadcrumb_current ?? "Assinaturas", url: "#", active: true }
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
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$2, {
              modelValue: search.value,
              "onUpdate:modelValue": ($event) => search.value = $event,
              placeholder: __props.t.search_placeholder,
              "max-width": "380px"
            }, null, _parent2, _scopeId));
            _push2(`<div class="card mb-3"${_scopeId}><div class="card-header fw-semibold py-2"${_scopeId}><i class="ti ti-settings me-1 text-muted"${_scopeId}></i>${ssrInterpolate(__props.t.settings_title)}</div><div class="card-body py-3"${_scopeId}><form class="row g-3 align-items-end"${_scopeId}><div class="col-md-3 col-sm-6"${_scopeId}><label class="form-label small"${_scopeId}>${ssrInterpolate(__props.t.settings_trial_days)}</label><input${ssrRenderAttr("value", settingsForm.value.trial_days)} type="number" min="1" max="365" class="form-control form-control-sm"${_scopeId}></div><div class="col-md-3 col-sm-6"${_scopeId}><label class="form-label small"${_scopeId}>${ssrInterpolate(__props.t.settings_grace_days)}</label><input${ssrRenderAttr("value", settingsForm.value.grace_period_days)} type="number" min="0" max="30" class="form-control form-control-sm"${_scopeId}></div><div class="col-auto"${_scopeId}><button type="submit" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(settingsSaving.value) ? " disabled" : ""}${_scopeId}>`);
            if (settingsSaving.value) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate(__props.t.settings_save)}</button></div></form></div></div>`);
            if (view.value === "table") {
              _push2(ssrRenderComponent(_sfc_main$3, {
                subscriptions: __props.subscriptions,
                filters: __props.filters,
                t: __props.t,
                onSort,
                onView: openDetail,
                onEdit: openEdit,
                onActivate: openActivate,
                onTrial: openTrial,
                onCancel,
                onBlock
              }, null, _parent2, _scopeId));
            } else {
              _push2(ssrRenderComponent(_sfc_main$4, {
                "cards-url": _ctx.route("manager.subscriptions.cards"),
                "initial-search": search.value,
                t: __props.t,
                onView: openDetail,
                onEdit: openEdit,
                onActivate: openActivate,
                onTrial: openTrial,
                onCancel,
                onBlock
              }, null, _parent2, _scopeId));
            }
            _push2(`</div>`);
            _push2(ssrRenderComponent(SubscriptionDetailDrawer, {
              open: detailOpen.value,
              "subscription-id": detailId.value,
              t: __props.t,
              onClose: closeDetail,
              onEdit: (id) => {
                closeDetail();
                openEdit(id);
              }
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$5, {
              open: formOpen.value,
              "subscription-id": formId.value,
              plans: __props.plans,
              statuses: __props.statuses,
              t: __props.t,
              onClose: closeForm,
              onSaved: closeForm
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$6, {
              open: activateOpen.value,
              subscription: activateSubscription.value,
              plans: __props.plans,
              "billing-cycles": __props.billingCycles,
              gateways: __props.gateways,
              t: __props.t,
              onClose: closeActivate,
              onSaved: closeActivate
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$7, {
              open: trialOpen.value,
              subscription: trialSubscription.value,
              plans: __props.plans,
              "trial-days": __props.trialDays,
              t: __props.t,
              onClose: closeTrial,
              onSaved: closeTrial
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$8, {
              open: reasonModal.value.open,
              title: reasonModal.value.title,
              message: reasonModal.value.message,
              "confirm-label": reasonModal.value.confirmLabel,
              "confirm-variant": reasonModal.value.confirmVariant,
              saving: reasonModal.value.saving,
              onClose: closeReasonModal,
              onConfirm: handleReasonConfirm
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
                }, null, 8, ["title", "total", "view", "view-table-title", "view-cards-title"]),
                createVNode(_sfc_main$2, {
                  modelValue: search.value,
                  "onUpdate:modelValue": ($event) => search.value = $event,
                  placeholder: __props.t.search_placeholder,
                  "max-width": "380px"
                }, null, 8, ["modelValue", "onUpdate:modelValue", "placeholder"]),
                createVNode("div", { class: "card mb-3" }, [
                  createVNode("div", { class: "card-header fw-semibold py-2" }, [
                    createVNode("i", { class: "ti ti-settings me-1 text-muted" }),
                    createTextVNode(toDisplayString(__props.t.settings_title), 1)
                  ]),
                  createVNode("div", { class: "card-body py-3" }, [
                    createVNode("form", {
                      class: "row g-3 align-items-end",
                      onSubmit: withModifiers(saveSettings, ["prevent"])
                    }, [
                      createVNode("div", { class: "col-md-3 col-sm-6" }, [
                        createVNode("label", { class: "form-label small" }, toDisplayString(__props.t.settings_trial_days), 1),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => settingsForm.value.trial_days = $event,
                          type: "number",
                          min: "1",
                          max: "365",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [
                            vModelText,
                            settingsForm.value.trial_days,
                            void 0,
                            { number: true }
                          ]
                        ])
                      ]),
                      createVNode("div", { class: "col-md-3 col-sm-6" }, [
                        createVNode("label", { class: "form-label small" }, toDisplayString(__props.t.settings_grace_days), 1),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => settingsForm.value.grace_period_days = $event,
                          type: "number",
                          min: "0",
                          max: "30",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [
                            vModelText,
                            settingsForm.value.grace_period_days,
                            void 0,
                            { number: true }
                          ]
                        ])
                      ]),
                      createVNode("div", { class: "col-auto" }, [
                        createVNode("button", {
                          type: "submit",
                          class: "btn btn-primary btn-sm",
                          disabled: settingsSaving.value
                        }, [
                          settingsSaving.value ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "spinner-border spinner-border-sm me-1"
                          })) : createCommentVNode("", true),
                          createTextVNode(" " + toDisplayString(__props.t.settings_save), 1)
                        ], 8, ["disabled"])
                      ])
                    ], 32)
                  ])
                ]),
                view.value === "table" ? (openBlock(), createBlock(_sfc_main$3, {
                  key: 0,
                  subscriptions: __props.subscriptions,
                  filters: __props.filters,
                  t: __props.t,
                  onSort,
                  onView: openDetail,
                  onEdit: openEdit,
                  onActivate: openActivate,
                  onTrial: openTrial,
                  onCancel,
                  onBlock
                }, null, 8, ["subscriptions", "filters", "t"])) : (openBlock(), createBlock(_sfc_main$4, {
                  key: 1,
                  "cards-url": _ctx.route("manager.subscriptions.cards"),
                  "initial-search": search.value,
                  t: __props.t,
                  onView: openDetail,
                  onEdit: openEdit,
                  onActivate: openActivate,
                  onTrial: openTrial,
                  onCancel,
                  onBlock
                }, null, 8, ["cards-url", "initial-search", "t"]))
              ]),
              createVNode(SubscriptionDetailDrawer, {
                open: detailOpen.value,
                "subscription-id": detailId.value,
                t: __props.t,
                onClose: closeDetail,
                onEdit: (id) => {
                  closeDetail();
                  openEdit(id);
                }
              }, null, 8, ["open", "subscription-id", "t", "onEdit"]),
              createVNode(_sfc_main$5, {
                open: formOpen.value,
                "subscription-id": formId.value,
                plans: __props.plans,
                statuses: __props.statuses,
                t: __props.t,
                onClose: closeForm,
                onSaved: closeForm
              }, null, 8, ["open", "subscription-id", "plans", "statuses", "t"]),
              createVNode(_sfc_main$6, {
                open: activateOpen.value,
                subscription: activateSubscription.value,
                plans: __props.plans,
                "billing-cycles": __props.billingCycles,
                gateways: __props.gateways,
                t: __props.t,
                onClose: closeActivate,
                onSaved: closeActivate
              }, null, 8, ["open", "subscription", "plans", "billing-cycles", "gateways", "t"]),
              createVNode(_sfc_main$7, {
                open: trialOpen.value,
                subscription: trialSubscription.value,
                plans: __props.plans,
                "trial-days": __props.trialDays,
                t: __props.t,
                onClose: closeTrial,
                onSaved: closeTrial
              }, null, 8, ["open", "subscription", "plans", "trial-days", "t"]),
              createVNode(_sfc_main$8, {
                open: reasonModal.value.open,
                title: reasonModal.value.title,
                message: reasonModal.value.message,
                "confirm-label": reasonModal.value.confirmLabel,
                "confirm-variant": reasonModal.value.confirmVariant,
                saving: reasonModal.value.saving,
                onClose: closeReasonModal,
                onConfirm: handleReasonConfirm
              }, null, 8, ["open", "title", "message", "confirm-label", "confirm-variant", "saving"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Subscriptions/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
