import { ref, watch, onMounted, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderStyle, ssrRenderAttr, ssrIncludeBooleanAttr, ssrLooseContain, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
const _sfc_main = {
  __name: "NoticesPanel",
  __ssrInlineRender: true,
  props: {
    t: { type: Object, required: true }
  },
  emits: ["update:unread-count"],
  setup(__props, { expose: __expose, emit: __emit }) {
    const emit = __emit;
    const open = ref(false);
    const notices = ref([]);
    const unreadCount = ref(0);
    const loading = ref(false);
    const showForm = ref(false);
    const saving = ref(false);
    const newContent = ref("");
    const newUrgent = ref(false);
    const newExpires = ref("");
    async function fetchNotices() {
      loading.value = true;
      try {
        const res = await fetch(route("panel.notices.index"), {
          headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }
        });
        const data = await res.json();
        notices.value = data.data ?? [];
        unreadCount.value = data.unread_count ?? 0;
      } catch {
      }
      loading.value = false;
    }
    function toggle() {
      open.value = !open.value;
      if (open.value && notices.value.length === 0) fetchNotices();
    }
    watch(unreadCount, (v) => emit("update:unread-count", v));
    onMounted(fetchNotices);
    __expose({ toggle, open, unreadCount });
    return (_ctx, _push, _parent, _attrs) => {
      if (open.value) {
        _push(`<div${ssrRenderAttrs(mergeProps({ class: "border border-primary rounded mb-3" }, _attrs))}><div class="d-flex align-items-center justify-content-between px-3 py-2 bg-primary text-white rounded-top"><span class="fw-semibold small"><i class="fas fa-bullhorn me-1"></i>${ssrInterpolate(__props.t.notices_title)} `);
        if (unreadCount.value > 0) {
          _push(`<span class="badge bg-warning text-dark ms-1" style="${ssrRenderStyle({ "font-size": ".65rem" })}">${ssrInterpolate(unreadCount.value)} ${ssrInterpolate(__props.t.notices_unread)}</span>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</span><div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-light py-0"><i class="fas fa-plus me-1"></i><span style="${ssrRenderStyle({ "font-size": ".8rem" })}">${ssrInterpolate(__props.t.notices_new)}</span></button><button type="button" class="btn-close btn-close-white"></button></div></div>`);
        if (showForm.value) {
          _push(`<div class="p-3 border-bottom bg-light"><textarea class="form-control form-control-sm mb-2" rows="2"${ssrRenderAttr("placeholder", __props.t.notices_placeholder)} maxlength="1000">${ssrInterpolate(newContent.value)}</textarea><div class="d-flex align-items-center gap-3 flex-wrap"><div class="form-check form-check-inline mb-0"><input id="notice-urgent"${ssrIncludeBooleanAttr(Array.isArray(newUrgent.value) ? ssrLooseContain(newUrgent.value, null) : newUrgent.value) ? " checked" : ""} type="checkbox" class="form-check-input"><label for="notice-urgent" class="form-check-label small text-danger fw-semibold"><i class="fas fa-exclamation-triangle me-1"></i>${ssrInterpolate(__props.t.notices_urgent)}</label></div><div class="d-flex align-items-center gap-1"><label class="small text-muted mb-0">${ssrInterpolate(__props.t.notices_expires)}</label><input${ssrRenderAttr("value", newExpires.value)} type="date" class="form-control form-control-sm" style="${ssrRenderStyle({ "max-width": "150px" })}"></div><div class="ms-auto d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-secondary">${ssrInterpolate(__props.t.notices_cancel)}</button><button type="button" class="btn btn-sm btn-primary"${ssrIncludeBooleanAttr(saving.value || !newContent.value.trim()) ? " disabled" : ""}>`);
          if (saving.value) {
            _push(`<span class="spinner-border spinner-border-sm me-1"></span>`);
          } else {
            _push(`<!---->`);
          }
          _push(` ${ssrInterpolate(__props.t.notices_publish)}</button></div></div></div>`);
        } else {
          _push(`<!---->`);
        }
        if (loading.value) {
          _push(`<div class="text-center py-3 text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>${ssrInterpolate(__props.t.notices_loading)}</div>`);
        } else if (!loading.value && notices.value.length === 0) {
          _push(`<p class="text-muted text-center py-3 mb-0 small"><i class="fas fa-check-circle me-1"></i>${ssrInterpolate(__props.t.notices_empty)}</p>`);
        } else {
          _push(`<!---->`);
        }
        _push(`<div class="list-group list-group-flush rounded-bottom" style="${ssrRenderStyle({ "max-height": "300px", "overflow-y": "auto" })}"><!--[-->`);
        ssrRenderList(notices.value, (notice) => {
          _push(`<div class="${ssrRenderClass([{
            "border-start border-danger border-3": notice.is_urgent,
            "bg-light": !notice.is_read
          }, "list-group-item px-3 py-2"])}"><div class="d-flex gap-2 align-items-start"><div class="flex-shrink-0 pt-1" style="${ssrRenderStyle({ "width": "20px" })}">`);
          if (notice.is_urgent) {
            _push(`<i class="fas fa-exclamation-triangle text-danger"${ssrRenderAttr("title", __props.t.notices_urgent)}></i>`);
          } else if (notice.pinned) {
            _push(`<i class="fas fa-thumbtack text-primary"></i>`);
          } else {
            _push(`<!---->`);
          }
          _push(`</div><div class="flex-grow-1 min-w-0"><p class="mb-1 small" style="${ssrRenderStyle({ "white-space": "pre-wrap" })}">${ssrInterpolate(notice.content)}</p><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".72rem" })}">${ssrInterpolate(notice.author)} · ${ssrInterpolate(notice.created_at)} `);
          if (notice.expires_at) {
            _push(`<span class="ms-1"> · ${ssrInterpolate(__props.t.notices_expires_at)} ${ssrInterpolate(notice.expires_at)}</span>`);
          } else {
            _push(`<!---->`);
          }
          _push(`</div></div><div class="flex-shrink-0 d-flex gap-1">`);
          if (!notice.is_read) {
            _push(`<button type="button" class="btn btn-xs btn-outline-success py-0 px-1" style="${ssrRenderStyle({ "font-size": ".72rem" })}"><i class="fas fa-check"></i> ${ssrInterpolate(__props.t.notices_read_btn)}</button>`);
          } else {
            _push(`<span class="text-success" style="${ssrRenderStyle({ "font-size": ".72rem", "padding": ".1rem .25rem" })}"><i class="fas fa-check-double"></i></span>`);
          }
          _push(`<button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" style="${ssrRenderStyle({ "font-size": ".72rem" })}"><i class="fas fa-times"></i></button></div></div></div>`);
        });
        _push(`<!--]--></div></div>`);
      } else {
        _push(`<!---->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/NoticesPanel.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
