import { ref, watch, computed, mergeProps, unref, useSSRContext, defineAsyncComponent, withCtx, createVNode, toDisplayString, createTextVNode, openBlock, createBlock, Fragment, createCommentVNode, withDirectives, vModelText, renderList } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderList, ssrRenderClass, ssrRenderComponent, ssrRenderStyle, ssrRenderAttr, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import _sfc_main$4 from "./ScheduleCard-Cb_X2xQF.js";
import _sfc_main$5 from "./EventCard-M507_Jal.js";
import _sfc_main$6 from "./ScheduleFormModal-BSeqf69p.js";
import _sfc_main$7 from "./RescheduleModal-Dchdnf0c.js";
import _sfc_main$8 from "./CancelModal-Ct-L6eHq.js";
import _sfc_main$9 from "./BulkCancelModal-BG-WWE_s.js";
import _sfc_main$a from "./BulkRescheduleModal-Dqx62ELy.js";
import _sfc_main$2 from "./NoticesPanel-Cab5iTJp.js";
import _sfc_main$3 from "./WaitingListPanel-D4wbE-pn.js";
import _sfc_main$b from "./WaitingListFormModal-mDwRD_7v.js";
import ScheduleDetailDrawer from "./ScheduleDetailDrawer-DEme-sHP.js";
import { u as useDashboardPolling } from "./useDashboardPolling-D1jTH2om.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./ActionDropdown-DZW_71Hn.js";
import "./ActionIconButton-BTsQtzdl.js";
import "./ActionIconGroup-B8JEjj1z.js";
import "./SlotPicker-Bgvng9-B.js";
import "./OffcanvasPanel-VfE7yaou.js";
const _sfc_main$1 = {
  __name: "MiniCalendar",
  __ssrInlineRender: true,
  props: {
    modelValue: { type: String, default: "" },
    // YYYY-MM-DD
    locale: { type: String, default: "pt-BR" }
  },
  emits: ["update:modelValue"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const today = (/* @__PURE__ */ new Date()).toISOString().substring(0, 10);
    function parseDate(str) {
      if (!str) return /* @__PURE__ */ new Date();
      return /* @__PURE__ */ new Date(str + "T12:00:00");
    }
    const viewYear = ref(parseDate(props.modelValue).getFullYear());
    const viewMonth = ref(parseDate(props.modelValue).getMonth());
    watch(() => props.modelValue, (v) => {
      if (!v) return;
      const d = parseDate(v);
      viewYear.value = d.getFullYear();
      viewMonth.value = d.getMonth();
    });
    const monthLabel = computed(
      () => new Date(viewYear.value, viewMonth.value, 1).toLocaleDateString(props.locale, { month: "long" }).replace(/^\w/, (c) => c.toUpperCase())
    );
    const dayAbbrevs = computed(
      () => Array.from(
        { length: 7 },
        (_, i) => new Date(2023, 0, 1 + i).toLocaleDateString(props.locale, { weekday: "short" }).replace(/\.$/, "").substring(0, 3).replace(/^\w/, (c) => c.toUpperCase())
      )
    );
    const cells = computed(() => {
      const first = new Date(viewYear.value, viewMonth.value, 1);
      const daysInMonth = new Date(viewYear.value, viewMonth.value + 1, 0).getDate();
      const startDow = first.getDay();
      const grid = [];
      for (let i = 0; i < startDow; i++) grid.push(null);
      for (let d = 1; d <= daysInMonth; d++) {
        const month = String(viewMonth.value + 1).padStart(2, "0");
        const day = String(d).padStart(2, "0");
        grid.push(`${viewYear.value}-${month}-${day}`);
      }
      while (grid.length % 7 !== 0) grid.push(null);
      return grid;
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "mini-cal" }, _attrs))} data-v-2bb444a0><div class="mini-cal-header" data-v-2bb444a0><button type="button" class="mini-cal-nav" data-v-2bb444a0>‹</button><span class="mini-cal-title" data-v-2bb444a0>${ssrInterpolate(monthLabel.value)} ${ssrInterpolate(viewYear.value)}</span><button type="button" class="mini-cal-nav" data-v-2bb444a0>›</button></div><div class="mini-cal-grid" data-v-2bb444a0><!--[-->`);
      ssrRenderList(dayAbbrevs.value, (abbr) => {
        _push(`<div class="mini-cal-dow" data-v-2bb444a0>${ssrInterpolate(abbr)}</div>`);
      });
      _push(`<!--]--><!--[-->`);
      ssrRenderList(cells.value, (cell, i) => {
        _push(`<div class="${ssrRenderClass([{
          "is-today": cell === unref(today),
          "is-selected": cell === __props.modelValue && cell !== unref(today),
          "is-empty": cell === null
        }, "mini-cal-cell"])}" data-v-2bb444a0>`);
        if (cell) {
          _push(`<span data-v-2bb444a0>${ssrInterpolate(Number(cell.substring(8)))}</span>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div>`);
      });
      _push(`<!--]--></div></div>`);
    };
  }
};
const _sfc_setup$1 = _sfc_main$1.setup;
_sfc_main$1.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/MiniCalendar.vue");
  return _sfc_setup$1 ? _sfc_setup$1(props, ctx) : void 0;
};
const MiniCalendar = /* @__PURE__ */ _export_sfc(_sfc_main$1, [["__scopeId", "data-v-2bb444a0"]]);
const _sfc_main = {
  __name: "Index",
  __ssrInlineRender: true,
  props: {
    scheduleItems: { type: Array, default: () => [] },
    doctors: { type: Array, default: () => [] },
    covenants: { type: Array, default: () => [] },
    visitTypes: { type: Array, default: () => [] },
    situations: { type: Array, default: () => [] },
    moods: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    isDoctor: { type: Boolean, default: false },
    isStaff: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const CalendarView = defineAsyncComponent(() => import("./CalendarView-C6TrKfxo.js"));
    const props = __props;
    useDashboardPolling(["scheduleItems"], 3e4);
    const viewMode = ref(localStorage.getItem("schedules_view_mode") ?? "list");
    watch(viewMode, (mode) => {
      localStorage.setItem("schedules_view_mode", mode);
      if (mode === "calendar") {
        selectionMode.value = false;
        selectedIds.value = [];
      }
    });
    const date = ref(props.filters.date ?? (/* @__PURE__ */ new Date()).toISOString().substring(0, 10));
    const doctor = ref(props.filters.doctor ?? "tudo");
    const bout = ref(props.filters.bout ?? 1);
    const search = ref(props.filters.search ?? "");
    let searchDebounce = null;
    function applyFilters(partial = false) {
      router.get(
        route("panel.schedules.index"),
        { date: date.value, doctor: doctor.value, bout: bout.value, search: search.value },
        { preserveState: true, replace: true, only: partial ? ["scheduleItems", "filters"] : void 0 }
      );
    }
    function onSearchInput() {
      clearTimeout(searchDebounce);
      searchDebounce = setTimeout(() => applyFilters(true), 400);
    }
    function setDoctor(id) {
      doctor.value = id;
      applyFilters(true);
    }
    function setBout(b) {
      bout.value = b;
      applyFilters(true);
    }
    function goToDate(d) {
      date.value = d;
      applyFilters(true);
    }
    const sessionLocale = typeof window !== "undefined" && window.sessionLocale ? window.sessionLocale : "pt-BR";
    const noticesPanelRef = ref(null);
    const noticesUnread = ref(0);
    const wlPanelRef = ref(null);
    const wlCount = ref(0);
    const wlFormOpen = ref(false);
    function onScheduleFromWaiting(entry) {
      prefillData.value = entry;
      editSchedule.value = null;
      formOpen.value = true;
    }
    function onWlSaved() {
      var _a;
      wlFormOpen.value = false;
      (_a = wlPanelRef.value) == null ? void 0 : _a.fetch();
    }
    const selectionMode = ref(false);
    const selectedIds = ref([]);
    function toggleSelectionMode() {
      selectionMode.value = !selectionMode.value;
      selectedIds.value = [];
    }
    function toggleSelect(id) {
      const idx = selectedIds.value.indexOf(id);
      if (idx === -1) selectedIds.value.push(id);
      else selectedIds.value.splice(idx, 1);
    }
    function isSelected(id) {
      return selectedIds.value.includes(id);
    }
    async function bulkUpdate(situation) {
      var _a;
      if (selectedIds.value.length === 0) return;
      const res = await fetch(route("panel.schedules.bulk-update"), {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
        },
        body: JSON.stringify({ ids: selectedIds.value, situation })
      });
      const json = await res.json();
      showToast(json.message, res.ok ? "success" : "error");
      if (res.ok) {
        selectedIds.value = [];
        selectionMode.value = false;
        router.reload({ only: ["scheduleItems"] });
      }
    }
    const bulkCancelOpen = ref(false);
    function onBulkCancelled(json) {
      bulkCancelOpen.value = false;
      showToast(json.message, "success");
      selectedIds.value = [];
      selectionMode.value = false;
      router.reload({ only: ["scheduleItems"] });
    }
    const bulkRescheduleOpen = ref(false);
    function onBulkRescheduled(json) {
      bulkRescheduleOpen.value = false;
      showToast(json.message, "success");
      selectedIds.value = [];
      selectionMode.value = false;
      router.reload({ only: ["scheduleItems"] });
    }
    const formOpen = ref(false);
    const editSchedule = ref(null);
    const prefillData = ref(null);
    function openCreate() {
      editSchedule.value = null;
      prefillData.value = null;
      formOpen.value = true;
    }
    async function openEdit(item) {
      const res = await fetch(item.show_url, { headers: { Accept: "application/json" } });
      if (res.ok) {
        const json = await res.json();
        editSchedule.value = json.data;
      }
      prefillData.value = null;
      formOpen.value = true;
    }
    const detailOpen = ref(false);
    const viewScheduleId = ref(null);
    function onView(item) {
      viewScheduleId.value = item.id;
      detailOpen.value = true;
    }
    function closeDetail() {
      detailOpen.value = false;
      viewScheduleId.value = null;
    }
    function onSaved() {
      formOpen.value = false;
      editSchedule.value = null;
      prefillData.value = null;
      router.reload({ only: ["scheduleItems"] });
    }
    async function onCalendarEventClick(item) {
      if (item.type !== "schedule") return;
      await openEdit(item);
    }
    function onCalendarSlotClick({ datetime }) {
      editSchedule.value = null;
      prefillData.value = { date_time: datetime };
      formOpen.value = true;
    }
    const rescheduleItem = ref(null);
    function openReschedule(item) {
      rescheduleItem.value = item;
    }
    function onRescheduled(json) {
      rescheduleItem.value = null;
      showToast(json.message, "success");
      router.reload({ only: ["scheduleItems"] });
    }
    const cancelItem = ref(null);
    function openCancel(item) {
      cancelItem.value = item;
    }
    function onCancelled(json) {
      cancelItem.value = null;
      showToast(json.message, "success");
      router.reload({ only: ["scheduleItems"] });
    }
    async function onChangeSituation({ item, to }) {
      var _a;
      const res = await fetch(item.situation_url, {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
        },
        body: JSON.stringify({ situation: to })
      });
      const json = await res.json();
      showToast(json.message, res.ok ? "success" : "error");
      if (res.ok) router.reload({ only: ["scheduleItems"] });
    }
    async function onChangeMood({ item, mood }) {
      var _a;
      const res = await fetch(item.mood_url, {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
        },
        body: JSON.stringify({ mood })
      });
      const json = await res.json();
      showToast(json.message, res.ok ? "success" : "error");
      if (res.ok) router.reload({ only: ["scheduleItems"] });
    }
    function showToast(msg, type = "success") {
      if (type === "success" && window.showSuccessToast) return window.showSuccessToast(msg);
      if (type === "error" && window.showErrorToast) return window.showErrorToast(msg);
      alert(msg);
    }
    const storeUrl = route("panel.schedules.store");
    const breadcrumbs = [
      { label: props.t.breadcrumb_home ?? "Dashboard", url: route("panel.dashboard"), active: false },
      { label: props.t.breadcrumb_current ?? "Agenda", url: "#", active: true }
    ];
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: __props.t.page_title,
        breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div${_scopeId}><div class="d-flex align-items-center gap-2 pb-3 mb-0 border-bottom flex-wrap"${_scopeId}><div class="d-flex align-items-center gap-2 me-auto"${_scopeId}><h4 class="mb-0 fw-bold"${_scopeId}>${ssrInterpolate(__props.t.page_title)}</h4><span style="${ssrRenderStyle({ "font-size": ".78rem", "font-weight": "600", "color": "#0d6efd", "background": "#eff4ff", "border": "1.5px solid #0d6efd", "border-radius": "20px", "padding": "2px 12px", "white-space": "nowrap", "line-height": "1.6" })}"${_scopeId}>Total: ${ssrInterpolate(__props.scheduleItems.length)}</span></div><div class="bg-white border shadow-sm rounded px-1 d-flex align-items-center"${_scopeId}><button type="button" class="${ssrRenderClass([viewMode.value === "list" ? "bg-light" : "bg-white", "rounded p-1 d-flex align-items-center border-0"])}"${ssrRenderAttr("title", __props.t.view_list)}${_scopeId}><i class="ti ti-list fs-14 text-body"${_scopeId}></i></button><button type="button" class="${ssrRenderClass([viewMode.value === "calendar" ? "bg-light" : "bg-white", "rounded p-1 d-flex align-items-center border-0"])}"${ssrRenderAttr("title", __props.t.view_calendar)}${_scopeId}><i class="ti ti-calendar fs-14 text-body"${_scopeId}></i></button></div><button type="button" class="btn btn-primary btn-sm"${_scopeId}><i class="ti ti-plus me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_new)}</button></div><div class="d-flex align-items-center gap-2 py-2 border-bottom mb-3 flex-wrap"${_scopeId}>`);
            if (!selectionMode.value) {
              _push2(`<!--[--><button type="button" class="btn btn-sm btn-outline-primary position-relative"${_scopeId}><i class="fas fa-bullhorn me-1"${_scopeId}></i>${ssrInterpolate(__props.t.notices_title)} `);
              if (noticesUnread.value > 0) {
                _push2(`<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="${ssrRenderStyle({ "font-size": ".6rem" })}"${_scopeId}>${ssrInterpolate(noticesUnread.value)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</button>`);
              if (!__props.isDoctor) {
                _push2(`<!--[--><button type="button" class="btn btn-sm btn-outline-warning position-relative"${_scopeId}><i class="fas fa-hourglass-half me-1"${_scopeId}></i>${ssrInterpolate(__props.t.waiting_title)} `);
                if (wlCount.value > 0) {
                  _push2(`<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="${ssrRenderStyle({ "font-size": ".6rem" })}"${_scopeId}>${ssrInterpolate(wlCount.value)}</span>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</button><button type="button" class="btn btn-sm btn-outline-secondary"${ssrRenderAttr("title", __props.t.waiting_add_queue)}${_scopeId}><i class="fas fa-user-plus me-1"${_scopeId}></i>${ssrInterpolate(__props.t.waiting_add_queue)}</button><!--]-->`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="vr mx-1 d-none d-sm-block flex-shrink-0"${_scopeId}></div><div class="d-flex align-items-center gap-2 ms-auto"${_scopeId}><div class="input-group input-group-sm" style="${ssrRenderStyle({ "min-width": "180px", "max-width": "240px" })}"${_scopeId}><span class="input-group-text"${_scopeId}><i class="fas fa-search"${_scopeId}></i></span><input${ssrRenderAttr("value", search.value)} type="text" class="form-control"${ssrRenderAttr("placeholder", __props.t.search_placeholder)}${_scopeId}>`);
              if (search.value) {
                _push2(`<button type="button" class="btn btn-outline-secondary"${_scopeId}><i class="fas fa-times"${_scopeId}></i></button>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
              if (__props.isStaff && viewMode.value === "list") {
                _push2(`<button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"${_scopeId}><i class="fas fa-check-square me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_select)}</button>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><!--]-->`);
            } else {
              _push2(`<!--[--><button type="button" class="btn btn-outline-secondary btn-sm"${_scopeId}><i class="fas fa-times me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_cancel_select)}</button><span class="badge bg-secondary rounded-pill"${_scopeId}>${ssrInterpolate(selectedIds.value.length)} ${ssrInterpolate(__props.t.selected_count)}</span><div class="vr flex-shrink-0"${_scopeId}></div><button type="button" class="btn btn-info btn-sm"${ssrIncludeBooleanAttr(selectedIds.value.length === 0) ? " disabled" : ""}${_scopeId}><i class="fas fa-check-circle me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_bulk_confirm)}</button><button type="button" class="btn btn-warning btn-sm text-dark"${ssrIncludeBooleanAttr(selectedIds.value.length === 0) ? " disabled" : ""}${_scopeId}><i class="fas fa-user-times me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_bulk_noshow)}</button><button type="button" class="btn btn-danger btn-sm"${ssrIncludeBooleanAttr(selectedIds.value.length === 0) ? " disabled" : ""}${_scopeId}><i class="fas fa-ban me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_bulk_cancel)}</button><button type="button" class="btn btn-secondary btn-sm"${ssrIncludeBooleanAttr(selectedIds.value.length === 0) ? " disabled" : ""}${_scopeId}><i class="fas fa-calendar-alt me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_bulk_change_date)}</button><!--]-->`);
            }
            _push2(`</div>`);
            _push2(ssrRenderComponent(_sfc_main$2, {
              ref_key: "noticesPanelRef",
              ref: noticesPanelRef,
              t: __props.t,
              "onUpdate:unreadCount": ($event) => noticesUnread.value = $event
            }, null, _parent2, _scopeId));
            if (!__props.isDoctor) {
              _push2(ssrRenderComponent(_sfc_main$3, {
                ref_key: "wlPanelRef",
                ref: wlPanelRef,
                doctor: doctor.value,
                t: __props.t,
                "onUpdate:count": ($event) => wlCount.value = $event,
                onScheduleFromWaiting
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="row g-3"${_scopeId}>`);
            if (!__props.isDoctor) {
              _push2(`<div class="col-12 col-md-3"${_scopeId}><div class="card"${_scopeId}><div class="card-body"${_scopeId}>`);
              _push2(ssrRenderComponent(MiniCalendar, {
                "model-value": date.value,
                locale: unref(sessionLocale),
                "onUpdate:modelValue": goToDate
              }, null, _parent2, _scopeId));
              if (__props.doctors.length > 0) {
                _push2(`<!--[--><hr class="my-3"${_scopeId}><h6 class="fw-bold text-uppercase mb-2"${_scopeId}>${ssrInterpolate(__props.t.sidebar_doctors)}</h6><div class="d-flex align-items-center mb-3 gap-2" style="${ssrRenderStyle({ "cursor": "pointer" })}"${_scopeId}><div class="rounded d-flex align-items-center justify-content-center flex-shrink-0" style="${ssrRenderStyle([{ "width": "42px", "height": "42px" }, { border: doctor.value === "tudo" ? "2px solid #333" : "1px solid #ccc" }])}"${_scopeId}><i class="fas fa-users text-muted"${_scopeId}></i></div><div${_scopeId}><div class="fw-bold small"${_scopeId}>${ssrInterpolate(__props.t.sidebar_all)}</div><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}"${_scopeId}>${ssrInterpolate(__props.t.sidebar_select_all)}</div></div></div><!--[-->`);
                ssrRenderList(__props.doctors, (d) => {
                  _push2(`<div class="d-flex align-items-center mb-3 gap-2" style="${ssrRenderStyle({ "cursor": "pointer" })}"${_scopeId}><div class="rounded d-flex align-items-center justify-content-center flex-shrink-0 overflow-hidden" style="${ssrRenderStyle([{ "width": "42px", "height": "42px" }, { border: doctor.value === d.id ? `2px solid ${d.color}` : `1px solid ${d.color}` }])}"${_scopeId}>`);
                  if (d.photo_url) {
                    _push2(`<img${ssrRenderAttr("src", d.photo_url)}${ssrRenderAttr("alt", d.name)} style="${ssrRenderStyle({ "width": "100%", "height": "100%", "object-fit": "cover" })}"${_scopeId}>`);
                  } else {
                    _push2(`<i class="fas fa-user" style="${ssrRenderStyle({ color: d.color })}"${_scopeId}></i>`);
                  }
                  _push2(`</div><div${_scopeId}><div class="fw-semibold small" style="${ssrRenderStyle({ color: d.color })}"${_scopeId}>${ssrInterpolate(d.name)}</div><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".72rem" })}"${_scopeId}>${ssrInterpolate(d.record)}</div></div></div>`);
                });
                _push2(`<!--]--><hr class="my-3"${_scopeId}><h6 class="fw-bold text-uppercase mb-2"${_scopeId}>${ssrInterpolate(__props.t.sidebar_time)}</h6><div class="d-flex w-100 border rounded overflow-hidden" role="group"${_scopeId}><!--[-->`);
                ssrRenderList({ 1: "fa-th", 2: "fa-sun", 3: "fa-cloud-sun", 4: "fa-moon" }, (icon, b) => {
                  _push2(`<button type="button" class="${ssrRenderClass([bout.value == b ? "btn-dark" : "btn-light", "flex-fill btn btn-sm rounded-0 border-0 py-2 px-1 text-center"])}"${_scopeId}><i class="${ssrRenderClass([icon, "fas d-block"])}" style="${ssrRenderStyle({ "font-size": "1rem" })}"${_scopeId}></i><span class="d-block" style="${ssrRenderStyle({ "font-size": ".6rem", "font-weight": "700", "letter-spacing": ".05em", "margin-top": ".2rem" })}"${_scopeId}>${ssrInterpolate({ 1: __props.t.sidebar_all, 2: __props.t.sidebar_morning, 3: __props.t.sidebar_afternoon, 4: __props.t.sidebar_evening }[b])}</span></button>`);
                });
                _push2(`<!--]--></div><!--]-->`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="${ssrRenderClass(!__props.isDoctor ? "col-12 col-md-9" : "col-12")}"${_scopeId}>`);
            if (viewMode.value === "calendar") {
              _push2(ssrRenderComponent(unref(CalendarView), {
                "schedule-items": __props.scheduleItems,
                date: date.value,
                t: __props.t,
                onEventClick: onCalendarEventClick,
                onSlotClick: onCalendarSlotClick
              }, null, _parent2, _scopeId));
            } else {
              _push2(`<!--[-->`);
              if (__props.scheduleItems.length === 0) {
                _push2(`<div class="text-center py-5 text-muted"${_scopeId}><i class="fas fa-calendar-times fa-3x mb-3 opacity-25"${_scopeId}></i><p${_scopeId}>${ssrInterpolate(__props.t.empty)}</p><button type="button" class="btn btn-primary btn-sm"${_scopeId}><i class="fas fa-plus me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_new)}</button></div>`);
              } else {
                _push2(`<!--[-->`);
                ssrRenderList(__props.scheduleItems, (item) => {
                  _push2(`<!--[-->`);
                  if (item.type === "schedule") {
                    _push2(ssrRenderComponent(_sfc_main$4, {
                      item,
                      "is-staff": __props.isStaff,
                      "is-doctor": __props.isDoctor,
                      moods: __props.moods,
                      "selection-mode": selectionMode.value,
                      selected: isSelected(item.id),
                      t: __props.t,
                      onToggleSelect: toggleSelect,
                      onView,
                      onEdit: openEdit,
                      onReschedule: openReschedule,
                      onCancel: openCancel,
                      onChangeSituation,
                      onChangeMood
                    }, null, _parent2, _scopeId));
                  } else {
                    _push2(ssrRenderComponent(_sfc_main$5, {
                      item,
                      t: __props.t
                    }, null, _parent2, _scopeId));
                  }
                  _push2(`<!--]-->`);
                });
                _push2(`<!--]-->`);
              }
              _push2(`<!--]-->`);
            }
            _push2(`</div></div></div>`);
            _push2(ssrRenderComponent(_sfc_main$6, {
              open: formOpen.value,
              "edit-schedule": editSchedule.value,
              "prefill-data": prefillData.value,
              "default-date": date.value,
              doctors: __props.doctors,
              covenants: __props.covenants,
              "visit-types": __props.visitTypes,
              "store-url": unref(storeUrl),
              t: __props.t,
              onClose: ($event) => {
                formOpen.value = false;
                editSchedule.value = null;
                prefillData.value = null;
              },
              onSaved
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$7, {
              item: rescheduleItem.value,
              doctors: __props.doctors,
              t: __props.t,
              onClose: ($event) => rescheduleItem.value = null,
              onRescheduled
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$8, {
              item: cancelItem.value,
              t: __props.t,
              onClose: ($event) => cancelItem.value = null,
              onCancelled
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$9, {
              open: bulkCancelOpen.value,
              "selected-ids": selectedIds.value,
              t: __props.t,
              onClose: ($event) => bulkCancelOpen.value = false,
              onDone: onBulkCancelled
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$a, {
              open: bulkRescheduleOpen.value,
              "selected-ids": selectedIds.value,
              t: __props.t,
              onClose: ($event) => bulkRescheduleOpen.value = false,
              onDone: onBulkRescheduled
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(_sfc_main$b, {
              open: wlFormOpen.value,
              doctors: __props.doctors,
              covenants: __props.covenants,
              "visit-types": __props.visitTypes,
              t: __props.t,
              onClose: ($event) => wlFormOpen.value = false,
              onSaved: onWlSaved
            }, null, _parent2, _scopeId));
            _push2(ssrRenderComponent(ScheduleDetailDrawer, {
              open: detailOpen.value,
              "schedule-id": viewScheduleId.value,
              t: __props.t,
              onClose: closeDetail
            }, null, _parent2, _scopeId));
          } else {
            return [
              createVNode("div", null, [
                createVNode("div", { class: "d-flex align-items-center gap-2 pb-3 mb-0 border-bottom flex-wrap" }, [
                  createVNode("div", { class: "d-flex align-items-center gap-2 me-auto" }, [
                    createVNode("h4", { class: "mb-0 fw-bold" }, toDisplayString(__props.t.page_title), 1),
                    createVNode("span", { style: { "font-size": ".78rem", "font-weight": "600", "color": "#0d6efd", "background": "#eff4ff", "border": "1.5px solid #0d6efd", "border-radius": "20px", "padding": "2px 12px", "white-space": "nowrap", "line-height": "1.6" } }, "Total: " + toDisplayString(__props.scheduleItems.length), 1)
                  ]),
                  createVNode("div", { class: "bg-white border shadow-sm rounded px-1 d-flex align-items-center" }, [
                    createVNode("button", {
                      type: "button",
                      class: ["rounded p-1 d-flex align-items-center border-0", viewMode.value === "list" ? "bg-light" : "bg-white"],
                      title: __props.t.view_list,
                      onClick: ($event) => viewMode.value = "list"
                    }, [
                      createVNode("i", { class: "ti ti-list fs-14 text-body" })
                    ], 10, ["title", "onClick"]),
                    createVNode("button", {
                      type: "button",
                      class: ["rounded p-1 d-flex align-items-center border-0", viewMode.value === "calendar" ? "bg-light" : "bg-white"],
                      title: __props.t.view_calendar,
                      onClick: ($event) => viewMode.value = "calendar"
                    }, [
                      createVNode("i", { class: "ti ti-calendar fs-14 text-body" })
                    ], 10, ["title", "onClick"])
                  ]),
                  createVNode("button", {
                    type: "button",
                    class: "btn btn-primary btn-sm",
                    onClick: openCreate
                  }, [
                    createVNode("i", { class: "ti ti-plus me-1" }),
                    createTextVNode(toDisplayString(__props.t.btn_new), 1)
                  ])
                ]),
                createVNode("div", { class: "d-flex align-items-center gap-2 py-2 border-bottom mb-3 flex-wrap" }, [
                  !selectionMode.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-sm btn-outline-primary position-relative",
                      onClick: ($event) => {
                        var _a;
                        return (_a = noticesPanelRef.value) == null ? void 0 : _a.toggle();
                      }
                    }, [
                      createVNode("i", { class: "fas fa-bullhorn me-1" }),
                      createTextVNode(toDisplayString(__props.t.notices_title) + " ", 1),
                      noticesUnread.value > 0 ? (openBlock(), createBlock("span", {
                        key: 0,
                        class: "position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger",
                        style: { "font-size": ".6rem" }
                      }, toDisplayString(noticesUnread.value), 1)) : createCommentVNode("", true)
                    ], 8, ["onClick"]),
                    !__props.isDoctor ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-outline-warning position-relative",
                        onClick: ($event) => {
                          var _a;
                          return (_a = wlPanelRef.value) == null ? void 0 : _a.toggle();
                        }
                      }, [
                        createVNode("i", { class: "fas fa-hourglass-half me-1" }),
                        createTextVNode(toDisplayString(__props.t.waiting_title) + " ", 1),
                        wlCount.value > 0 ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger",
                          style: { "font-size": ".6rem" }
                        }, toDisplayString(wlCount.value), 1)) : createCommentVNode("", true)
                      ], 8, ["onClick"]),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-sm btn-outline-secondary",
                        title: __props.t.waiting_add_queue,
                        onClick: ($event) => wlFormOpen.value = true
                      }, [
                        createVNode("i", { class: "fas fa-user-plus me-1" }),
                        createTextVNode(toDisplayString(__props.t.waiting_add_queue), 1)
                      ], 8, ["title", "onClick"])
                    ], 64)) : createCommentVNode("", true),
                    createVNode("div", { class: "vr mx-1 d-none d-sm-block flex-shrink-0" }),
                    createVNode("div", { class: "d-flex align-items-center gap-2 ms-auto" }, [
                      createVNode("div", {
                        class: "input-group input-group-sm",
                        style: { "min-width": "180px", "max-width": "240px" }
                      }, [
                        createVNode("span", { class: "input-group-text" }, [
                          createVNode("i", { class: "fas fa-search" })
                        ]),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => search.value = $event,
                          type: "text",
                          class: "form-control",
                          placeholder: __props.t.search_placeholder,
                          onInput: onSearchInput
                        }, null, 40, ["onUpdate:modelValue", "placeholder"]), [
                          [vModelText, search.value]
                        ]),
                        search.value ? (openBlock(), createBlock("button", {
                          key: 0,
                          type: "button",
                          class: "btn btn-outline-secondary",
                          onClick: ($event) => {
                            search.value = "";
                            applyFilters(true);
                          }
                        }, [
                          createVNode("i", { class: "fas fa-times" })
                        ], 8, ["onClick"])) : createCommentVNode("", true)
                      ]),
                      __props.isStaff && viewMode.value === "list" ? (openBlock(), createBlock("button", {
                        key: 0,
                        type: "button",
                        class: "btn btn-sm btn-outline-secondary flex-shrink-0",
                        onClick: toggleSelectionMode
                      }, [
                        createVNode("i", { class: "fas fa-check-square me-1" }),
                        createTextVNode(toDisplayString(__props.t.btn_select), 1)
                      ])) : createCommentVNode("", true)
                    ])
                  ], 64)) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-outline-secondary btn-sm",
                      onClick: toggleSelectionMode
                    }, [
                      createVNode("i", { class: "fas fa-times me-1" }),
                      createTextVNode(toDisplayString(__props.t.btn_cancel_select), 1)
                    ]),
                    createVNode("span", { class: "badge bg-secondary rounded-pill" }, toDisplayString(selectedIds.value.length) + " " + toDisplayString(__props.t.selected_count), 1),
                    createVNode("div", { class: "vr flex-shrink-0" }),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-info btn-sm",
                      disabled: selectedIds.value.length === 0,
                      onClick: ($event) => bulkUpdate(2)
                    }, [
                      createVNode("i", { class: "fas fa-check-circle me-1" }),
                      createTextVNode(toDisplayString(__props.t.btn_bulk_confirm), 1)
                    ], 8, ["disabled", "onClick"]),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-warning btn-sm text-dark",
                      disabled: selectedIds.value.length === 0,
                      onClick: ($event) => bulkUpdate(8)
                    }, [
                      createVNode("i", { class: "fas fa-user-times me-1" }),
                      createTextVNode(toDisplayString(__props.t.btn_bulk_noshow), 1)
                    ], 8, ["disabled", "onClick"]),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-danger btn-sm",
                      disabled: selectedIds.value.length === 0,
                      onClick: ($event) => bulkCancelOpen.value = true
                    }, [
                      createVNode("i", { class: "fas fa-ban me-1" }),
                      createTextVNode(toDisplayString(__props.t.btn_bulk_cancel), 1)
                    ], 8, ["disabled", "onClick"]),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-secondary btn-sm",
                      disabled: selectedIds.value.length === 0,
                      onClick: ($event) => bulkRescheduleOpen.value = true
                    }, [
                      createVNode("i", { class: "fas fa-calendar-alt me-1" }),
                      createTextVNode(toDisplayString(__props.t.btn_bulk_change_date), 1)
                    ], 8, ["disabled", "onClick"])
                  ], 64))
                ]),
                createVNode(_sfc_main$2, {
                  ref_key: "noticesPanelRef",
                  ref: noticesPanelRef,
                  t: __props.t,
                  "onUpdate:unreadCount": ($event) => noticesUnread.value = $event
                }, null, 8, ["t", "onUpdate:unreadCount"]),
                !__props.isDoctor ? (openBlock(), createBlock(_sfc_main$3, {
                  key: 0,
                  ref_key: "wlPanelRef",
                  ref: wlPanelRef,
                  doctor: doctor.value,
                  t: __props.t,
                  "onUpdate:count": ($event) => wlCount.value = $event,
                  onScheduleFromWaiting
                }, null, 8, ["doctor", "t", "onUpdate:count"])) : createCommentVNode("", true),
                createVNode("div", { class: "row g-3" }, [
                  !__props.isDoctor ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "col-12 col-md-3"
                  }, [
                    createVNode("div", { class: "card" }, [
                      createVNode("div", { class: "card-body" }, [
                        createVNode(MiniCalendar, {
                          "model-value": date.value,
                          locale: unref(sessionLocale),
                          "onUpdate:modelValue": goToDate
                        }, null, 8, ["model-value", "locale"]),
                        __props.doctors.length > 0 ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                          createVNode("hr", { class: "my-3" }),
                          createVNode("h6", { class: "fw-bold text-uppercase mb-2" }, toDisplayString(__props.t.sidebar_doctors), 1),
                          createVNode("div", {
                            class: "d-flex align-items-center mb-3 gap-2",
                            style: { "cursor": "pointer" },
                            onClick: ($event) => setDoctor("tudo")
                          }, [
                            createVNode("div", {
                              class: "rounded d-flex align-items-center justify-content-center flex-shrink-0",
                              style: [{ "width": "42px", "height": "42px" }, { border: doctor.value === "tudo" ? "2px solid #333" : "1px solid #ccc" }]
                            }, [
                              createVNode("i", { class: "fas fa-users text-muted" })
                            ], 4),
                            createVNode("div", null, [
                              createVNode("div", { class: "fw-bold small" }, toDisplayString(__props.t.sidebar_all), 1),
                              createVNode("div", {
                                class: "text-muted",
                                style: { "font-size": ".75rem" }
                              }, toDisplayString(__props.t.sidebar_select_all), 1)
                            ])
                          ], 8, ["onClick"]),
                          (openBlock(true), createBlock(Fragment, null, renderList(__props.doctors, (d) => {
                            return openBlock(), createBlock("div", {
                              key: d.id,
                              class: "d-flex align-items-center mb-3 gap-2",
                              style: { "cursor": "pointer" },
                              onClick: ($event) => setDoctor(d.id)
                            }, [
                              createVNode("div", {
                                class: "rounded d-flex align-items-center justify-content-center flex-shrink-0 overflow-hidden",
                                style: [{ "width": "42px", "height": "42px" }, { border: doctor.value === d.id ? `2px solid ${d.color}` : `1px solid ${d.color}` }]
                              }, [
                                d.photo_url ? (openBlock(), createBlock("img", {
                                  key: 0,
                                  src: d.photo_url,
                                  alt: d.name,
                                  style: { "width": "100%", "height": "100%", "object-fit": "cover" }
                                }, null, 8, ["src", "alt"])) : (openBlock(), createBlock("i", {
                                  key: 1,
                                  class: "fas fa-user",
                                  style: { color: d.color }
                                }, null, 4))
                              ], 4),
                              createVNode("div", null, [
                                createVNode("div", {
                                  class: "fw-semibold small",
                                  style: { color: d.color }
                                }, toDisplayString(d.name), 5),
                                createVNode("div", {
                                  class: "text-muted",
                                  style: { "font-size": ".72rem" }
                                }, toDisplayString(d.record), 1)
                              ])
                            ], 8, ["onClick"]);
                          }), 128)),
                          createVNode("hr", { class: "my-3" }),
                          createVNode("h6", { class: "fw-bold text-uppercase mb-2" }, toDisplayString(__props.t.sidebar_time), 1),
                          createVNode("div", {
                            class: "d-flex w-100 border rounded overflow-hidden",
                            role: "group"
                          }, [
                            (openBlock(), createBlock(Fragment, null, renderList({ 1: "fa-th", 2: "fa-sun", 3: "fa-cloud-sun", 4: "fa-moon" }, (icon, b) => {
                              return createVNode("button", {
                                key: b,
                                type: "button",
                                class: ["flex-fill btn btn-sm rounded-0 border-0 py-2 px-1 text-center", bout.value == b ? "btn-dark" : "btn-light"],
                                onClick: ($event) => setBout(Number(b))
                              }, [
                                createVNode("i", {
                                  class: ["fas d-block", icon],
                                  style: { "font-size": "1rem" }
                                }, null, 2),
                                createVNode("span", {
                                  class: "d-block",
                                  style: { "font-size": ".6rem", "font-weight": "700", "letter-spacing": ".05em", "margin-top": ".2rem" }
                                }, toDisplayString({ 1: __props.t.sidebar_all, 2: __props.t.sidebar_morning, 3: __props.t.sidebar_afternoon, 4: __props.t.sidebar_evening }[b]), 1)
                              ], 10, ["onClick"]);
                            }), 64))
                          ])
                        ], 64)) : createCommentVNode("", true)
                      ])
                    ])
                  ])) : createCommentVNode("", true),
                  createVNode("div", {
                    class: !__props.isDoctor ? "col-12 col-md-9" : "col-12"
                  }, [
                    viewMode.value === "calendar" ? (openBlock(), createBlock(unref(CalendarView), {
                      key: 0,
                      "schedule-items": __props.scheduleItems,
                      date: date.value,
                      t: __props.t,
                      onEventClick: onCalendarEventClick,
                      onSlotClick: onCalendarSlotClick
                    }, null, 8, ["schedule-items", "date", "t"])) : (openBlock(), createBlock(Fragment, { key: 1 }, [
                      __props.scheduleItems.length === 0 ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "text-center py-5 text-muted"
                      }, [
                        createVNode("i", { class: "fas fa-calendar-times fa-3x mb-3 opacity-25" }),
                        createVNode("p", null, toDisplayString(__props.t.empty), 1),
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-primary btn-sm",
                          onClick: openCreate
                        }, [
                          createVNode("i", { class: "fas fa-plus me-1" }),
                          createTextVNode(toDisplayString(__props.t.btn_new), 1)
                        ])
                      ])) : (openBlock(true), createBlock(Fragment, { key: 1 }, renderList(__props.scheduleItems, (item) => {
                        return openBlock(), createBlock(Fragment, {
                          key: item.id
                        }, [
                          item.type === "schedule" ? (openBlock(), createBlock(_sfc_main$4, {
                            key: 0,
                            item,
                            "is-staff": __props.isStaff,
                            "is-doctor": __props.isDoctor,
                            moods: __props.moods,
                            "selection-mode": selectionMode.value,
                            selected: isSelected(item.id),
                            t: __props.t,
                            onToggleSelect: toggleSelect,
                            onView,
                            onEdit: openEdit,
                            onReschedule: openReschedule,
                            onCancel: openCancel,
                            onChangeSituation,
                            onChangeMood
                          }, null, 8, ["item", "is-staff", "is-doctor", "moods", "selection-mode", "selected", "t"])) : (openBlock(), createBlock(_sfc_main$5, {
                            key: 1,
                            item,
                            t: __props.t
                          }, null, 8, ["item", "t"]))
                        ], 64);
                      }), 128))
                    ], 64))
                  ], 2)
                ])
              ]),
              createVNode(_sfc_main$6, {
                open: formOpen.value,
                "edit-schedule": editSchedule.value,
                "prefill-data": prefillData.value,
                "default-date": date.value,
                doctors: __props.doctors,
                covenants: __props.covenants,
                "visit-types": __props.visitTypes,
                "store-url": unref(storeUrl),
                t: __props.t,
                onClose: ($event) => {
                  formOpen.value = false;
                  editSchedule.value = null;
                  prefillData.value = null;
                },
                onSaved
              }, null, 8, ["open", "edit-schedule", "prefill-data", "default-date", "doctors", "covenants", "visit-types", "store-url", "t", "onClose"]),
              createVNode(_sfc_main$7, {
                item: rescheduleItem.value,
                doctors: __props.doctors,
                t: __props.t,
                onClose: ($event) => rescheduleItem.value = null,
                onRescheduled
              }, null, 8, ["item", "doctors", "t", "onClose"]),
              createVNode(_sfc_main$8, {
                item: cancelItem.value,
                t: __props.t,
                onClose: ($event) => cancelItem.value = null,
                onCancelled
              }, null, 8, ["item", "t", "onClose"]),
              createVNode(_sfc_main$9, {
                open: bulkCancelOpen.value,
                "selected-ids": selectedIds.value,
                t: __props.t,
                onClose: ($event) => bulkCancelOpen.value = false,
                onDone: onBulkCancelled
              }, null, 8, ["open", "selected-ids", "t", "onClose"]),
              createVNode(_sfc_main$a, {
                open: bulkRescheduleOpen.value,
                "selected-ids": selectedIds.value,
                t: __props.t,
                onClose: ($event) => bulkRescheduleOpen.value = false,
                onDone: onBulkRescheduled
              }, null, 8, ["open", "selected-ids", "t", "onClose"]),
              createVNode(_sfc_main$b, {
                open: wlFormOpen.value,
                doctors: __props.doctors,
                covenants: __props.covenants,
                "visit-types": __props.visitTypes,
                t: __props.t,
                onClose: ($event) => wlFormOpen.value = false,
                onSaved: onWlSaved
              }, null, 8, ["open", "doctors", "covenants", "visit-types", "t", "onClose"]),
              createVNode(ScheduleDetailDrawer, {
                open: detailOpen.value,
                "schedule-id": viewScheduleId.value,
                t: __props.t,
                onClose: closeDetail
              }, null, 8, ["open", "schedule-id", "t"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/Index.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
