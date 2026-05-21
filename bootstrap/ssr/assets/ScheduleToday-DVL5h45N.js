import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderStyle, ssrRenderAttr, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
const _sfc_main = {
  __name: "ScheduleToday",
  __ssrInlineRender: true,
  props: {
    items: { type: Array, default: () => [] },
    isRefreshing: { type: Boolean, default: false },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const activeCount = computed(
      () => props.items.filter((i) => i.is_active).length
    );
    const badgeColorMap = {
      "bg-secondary": { bg: "#e2e8f0", text: "#475569" },
      "bg-info text-dark": { bg: "#e0f2fe", text: "#0369a1" },
      "bg-warning text-dark": { bg: "#fef3c7", text: "#92400e" },
      "bg-purple text-white": { bg: "#ede9fe", text: "#7c3aed" },
      "bg-orange text-white": { bg: "#ffedd5", text: "#c2410c" },
      "bg-primary": { bg: "#dbeafe", text: "#1d4ed8" },
      "bg-success": { bg: "#dcfce7", text: "#166534" },
      "bg-danger": { bg: "#fee2e2", text: "#991b1b" },
      "bg-dark": { bg: "#f1f5f9", text: "#334155" }
    };
    function badgeStyle(badge) {
      const c = badgeColorMap[badge] ?? badgeColorMap["bg-secondary"];
      return { backgroundColor: c.bg, color: c.text, fontWeight: 600 };
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card h-100" }, _attrs))}><div class="card-header d-flex align-items-center justify-content-between"><span class="d-flex align-items-center gap-2"><i class="ti ti-calendar-event text-primary"></i> ${ssrInterpolate(__props.t.section_schedule_today)} `);
      if (activeCount.value > 0) {
        _push(`<span class="badge bg-primary ms-1" style="${ssrRenderStyle({ "font-size": ".7rem" })}">${ssrInterpolate(activeCount.value)} ${ssrInterpolate(__props.t.live_label)}</span>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</span><div class="d-flex align-items-center gap-2">`);
      if (__props.isRefreshing) {
        _push(`<span class="text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}"><i class="ti ti-loader-2 db-spin"></i></span>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<a${ssrRenderAttr("href", _ctx.route("panel.schedules.index"))} class="btn btn-sm btn-outline-primary">${ssrInterpolate(__props.t.btn_see_schedule)} <i class="ti ti-arrow-right ms-1"></i></a></div></div><div class="card-body p-0">`);
      if (__props.items.length === 0) {
        _push(`<div class="text-center text-muted py-5"><i class="ti ti-calendar-off" style="${ssrRenderStyle({ "font-size": "2.5rem", "display": "block", "margin-bottom": ".75rem" })}"></i> ${ssrInterpolate(__props.t.empty_schedules)}</div>`);
      } else {
        _push(`<table class="schedule-table table table-hover mb-0"><thead><tr><th style="${ssrRenderStyle({ "width": "60px" })}">${ssrInterpolate(__props.t.col_time)}</th><th>${ssrInterpolate(__props.t.col_name)}</th><th class="d-none d-md-table-cell">${ssrInterpolate(__props.t.col_doctor)}</th><th>${ssrInterpolate(__props.t.col_situation)}</th></tr></thead><tbody><!--[-->`);
        ssrRenderList(__props.items, (item) => {
          _push(`<tr class="${ssrRenderClass(item.is_active ? "schedule-row--active" : "")}"><td class="fw-semibold" style="${ssrRenderStyle({ "font-size": ".9rem", "letter-spacing": ".02em" })}">${ssrInterpolate(item.time)}</td><td><div class="d-flex align-items-center gap-2">`);
          if (item.arrived) {
            _push(`<span class="ti ti-circle-check text-success" title="Chegou"></span>`);
          } else {
            _push(`<!---->`);
          }
          _push(`<span class="fw-medium" style="${ssrRenderStyle({ "font-size": ".875rem" })}">${ssrInterpolate(item.name)}</span></div></td><td class="text-muted small d-none d-md-table-cell">${ssrInterpolate(item.doctor)}</td><td><span class="badge rounded-pill px-2 py-1" style="${ssrRenderStyle(badgeStyle(item.badge))}"><i class="${ssrRenderClass(`fa ${item.icon} me-1`)}" style="${ssrRenderStyle({ "font-size": ".65rem" })}"></i> ${ssrInterpolate(item.label)}</span></td></tr>`);
        });
        _push(`<!--]--></tbody></table>`);
      }
      _push(`</div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Dashboard/ScheduleToday.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
