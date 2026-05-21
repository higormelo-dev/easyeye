import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderStyle } from "vue/server-renderer";
const _sfc_main = {
  __name: "DaySummary",
  __ssrInlineRender: true,
  props: {
    stats: { type: Object, required: true },
    isRefreshing: { type: Boolean, default: false },
    t: { type: Object, required: true }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card h-100" }, _attrs))}><div class="card-header d-flex align-items-center justify-content-between"><span><i class="ti ti-chart-bar me-2 text-warning"></i> ${ssrInterpolate(__props.t.section_day_summary)}</span>`);
      if (__props.isRefreshing) {
        _push(`<span class="text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}"><i class="ti ti-loader-2 db-spin"></i></span>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div><div class="card-body d-flex flex-column gap-2 p-3"><div class="day-summary-stat day-summary-stat--total"><div class="ds-value ds-value--total">${ssrInterpolate(__props.stats.today_count)}</div><div class="ds-label">${ssrInterpolate(__props.t.summary_total)}</div></div><div class="day-summary-stat day-summary-stat--attended"><div class="ds-value ds-value--attended">${ssrInterpolate(__props.stats.attended_today)}</div><div class="ds-label">${ssrInterpolate(__props.t.summary_attended)}</div></div><div class="day-summary-stat day-summary-stat--pending"><div class="ds-value ds-value--pending">${ssrInterpolate(__props.stats.pending_today)}</div><div class="ds-label">${ssrInterpolate(__props.t.summary_pending)}</div></div><div class="day-summary-stat day-summary-stat--cancelled"><div class="ds-value ds-value--cancelled">${ssrInterpolate(__props.stats.cancelled_today)}</div><div class="ds-label">${ssrInterpolate(__props.t.summary_cancelled)}</div></div></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Dashboard/DaySummary.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
