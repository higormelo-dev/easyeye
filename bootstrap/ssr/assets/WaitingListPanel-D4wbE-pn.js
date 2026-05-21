import { ref, computed, watch, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderList, ssrRenderStyle, ssrIncludeBooleanAttr, ssrRenderAttr } from "vue/server-renderer";
const _sfc_main = {
  __name: "WaitingListPanel",
  __ssrInlineRender: true,
  props: {
    doctor: { type: String, default: "tudo" },
    t: { type: Object, required: true }
  },
  emits: ["schedule-from-waiting", "update:count"],
  setup(__props, { expose: __expose, emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const open = ref(false);
    const entries = ref([]);
    const loading = ref(false);
    const count = computed(() => entries.value.length);
    watch(count, (v) => emit("update:count", v));
    async function fetch() {
      loading.value = true;
      try {
        const params = props.doctor !== "tudo" ? `?doctor_id=${props.doctor}` : "";
        const res = await window.fetch(route("panel.waiting-list.index") + params, {
          headers: { Accept: "application/json" }
        });
        const data = await res.json();
        entries.value = data.data ?? [];
      } catch {
      }
      loading.value = false;
    }
    function toggle() {
      open.value = !open.value;
      if (open.value) fetch();
    }
    __expose({ fetch, toggle, open, count });
    return (_ctx, _push, _parent, _attrs) => {
      if (open.value) {
        _push(`<div${ssrRenderAttrs(mergeProps({ class: "border border-warning rounded mb-3" }, _attrs))}><div class="d-flex align-items-center justify-content-between px-3 py-2 bg-warning rounded-top"><span class="fw-semibold small"><i class="fas fa-hourglass-half me-2"></i>${ssrInterpolate(__props.t.waiting_title)} <span class="badge bg-dark ms-2">${ssrInterpolate(count.value)}</span></span><button type="button" class="btn-close"></button></div>`);
        if (loading.value) {
          _push(`<div class="text-center py-3 text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>${ssrInterpolate(__props.t.loading)}</div>`);
        } else if (!loading.value && entries.value.length === 0) {
          _push(`<p class="text-muted text-center py-3 mb-0 small"><i class="fas fa-check-circle me-1"></i>${ssrInterpolate(__props.t.waiting_empty)}</p>`);
        } else {
          _push(`<div class="list-group list-group-flush rounded-bottom"><!--[-->`);
          ssrRenderList(entries.value, (entry, index) => {
            _push(`<div class="list-group-item px-3 py-2"><div class="d-flex align-items-center gap-2"><span class="badge bg-secondary rounded-pill flex-shrink-0" style="${ssrRenderStyle({ "min-width": "1.6rem" })}">${ssrInterpolate(index + 1)}</span><div class="d-flex flex-column flex-shrink-0" style="${ssrRenderStyle({ "gap": "0" })}"><button type="button" class="btn p-0 lh-1 border-0 text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}"${ssrIncludeBooleanAttr(index === 0) ? " disabled" : ""}><i class="fas fa-caret-up"></i></button><button type="button" class="btn p-0 lh-1 border-0 text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}"${ssrIncludeBooleanAttr(index === entries.value.length - 1) ? " disabled" : ""}><i class="fas fa-caret-down"></i></button></div><div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate small">${ssrInterpolate(entry.full_name)}</div><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}"><span>${ssrInterpolate(entry.doctor_name)}</span>`);
            if (entry.covenant_name) {
              _push(`<span> · ${ssrInterpolate(entry.covenant_name)}</span>`);
            } else {
              _push(`<!---->`);
            }
            if (entry.visit_name) {
              _push(`<span> · ${ssrInterpolate(entry.visit_name)}</span>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div>`);
            if (entry.preferred_date_from) {
              _push(`<div class="text-muted" style="${ssrRenderStyle({ "font-size": ".7rem" })}"><i class="fas fa-calendar me-1"></i> ${ssrInterpolate(entry.preferred_date_from)} `);
              if (entry.preferred_date_until) {
                _push(`<span>${ssrInterpolate(__props.t.waiting_until)} ${ssrInterpolate(entry.preferred_date_until)}</span>`);
              } else {
                _push(`<!---->`);
              }
              _push(`</div>`);
            } else {
              _push(`<!---->`);
            }
            if (entry.notes) {
              _push(`<p class="mb-0 mt-1 text-muted fst-italic" style="${ssrRenderStyle({ "font-size": ".7rem" })}">${ssrInterpolate(entry.notes)}</p>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div><div class="d-flex gap-1 flex-shrink-0"><button type="button" class="btn btn-sm btn-info"${ssrRenderAttr("title", __props.t.waiting_schedule_btn)}><i class="fas fa-calendar-plus"></i></button><button type="button" class="btn btn-sm btn-outline-danger"${ssrRenderAttr("title", __props.t.waiting_remove_btn)}><i class="fas fa-times"></i></button></div></div></div>`);
          });
          _push(`<!--]--></div>`);
        }
        _push(`</div>`);
      } else {
        _push(`<!---->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/WaitingListPanel.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
