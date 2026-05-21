import { ssrRenderList, ssrRenderClass, ssrInterpolate } from "vue/server-renderer";
import { computed, useSSRContext } from "vue";
const _sfc_main = {
  __name: "KpiCards",
  __ssrInlineRender: true,
  props: {
    stats: { type: Object, required: true },
    isDoctor: { type: Boolean, default: false },
    isRefreshing: { type: Boolean, default: false },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const row1 = computed(() => [
      {
        key: "patients",
        icon: "ti ti-users",
        value: props.stats.total_patients,
        label: props.t.kpi_patients,
        variant: "patients"
      },
      {
        key: "today",
        icon: "ti ti-calendar-check",
        value: props.stats.today_count,
        label: props.t.kpi_today,
        variant: "today"
      },
      {
        key: "doctors",
        icon: "ti ti-stethoscope",
        value: props.stats.total_doctors,
        label: props.t.kpi_doctors,
        variant: "doctors"
      },
      {
        key: "surgeries",
        icon: "ti ti-scalpel",
        value: null,
        label: props.t.kpi_surgeries,
        variant: "surgeries",
        soon: true
      }
    ]);
    const row2 = computed(() => {
      const base = [
        {
          key: "exams",
          icon: "ti ti-eye",
          value: null,
          label: props.t.kpi_exams_pending,
          variant: "exams",
          soon: true
        }
      ];
      if (!props.isDoctor) {
        base.push(
          {
            key: "guides",
            icon: "ti ti-file-invoice",
            value: null,
            label: props.t.kpi_guides_waiting,
            variant: "guides",
            soon: true
          },
          {
            key: "receivable",
            icon: "ti ti-currency-dollar",
            value: null,
            label: props.t.kpi_receivable,
            variant: "receivable",
            soon: true
          },
          {
            key: "satisfaction",
            icon: "ti ti-star",
            value: null,
            label: props.t.kpi_satisfaction,
            variant: "satisfaction",
            soon: true
          }
        );
      }
      return base;
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[--><div class="row g-3 mb-3"><!--[-->`);
      ssrRenderList(row1.value, (kpi) => {
        _push(`<div class="col-6 col-md-3"><div class="${ssrRenderClass([
          "card stat-card h-100",
          `stat-card--${kpi.variant}`,
          kpi.soon ? "stat-card-mock" : "",
          __props.isRefreshing && !kpi.soon ? "stat-card--refreshing" : ""
        ])}"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="${ssrRenderClass(`stat-icon stat-icon--${kpi.variant}`)}"><i class="${ssrRenderClass(kpi.icon)}"></i></div><div><div class="${ssrRenderClass(["stat-value", kpi.soon ? "stat-value-mock" : ""])}">`);
        if (kpi.soon) {
          _push(`<!--[-->—<!--]-->`);
        } else if (__props.isRefreshing) {
          _push(`<span class="stat-skeleton"></span>`);
        } else {
          _push(`<!--[-->${ssrInterpolate(kpi.value)}<!--]-->`);
        }
        _push(`</div><div class="stat-label">${ssrInterpolate(kpi.label)}</div>`);
        if (kpi.soon) {
          _push(`<span class="stat-badge-soon">${ssrInterpolate(__props.t.kpi_coming_soon)}</span>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div></div></div></div>`);
      });
      _push(`<!--]--></div><div class="row g-3 mb-4"><!--[-->`);
      ssrRenderList(row2.value, (kpi) => {
        _push(`<div class="col-6 col-md-3"><div class="${ssrRenderClass(["card stat-card h-100", `stat-card--${kpi.variant}`, kpi.soon ? "stat-card-mock" : ""])}"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="${ssrRenderClass(`stat-icon stat-icon--${kpi.variant}`)}"><i class="${ssrRenderClass(kpi.icon)}"></i></div><div><div class="${ssrRenderClass(["stat-value", kpi.soon ? "stat-value-mock" : ""])}">${ssrInterpolate(kpi.soon ? "—" : kpi.value)}</div><div class="stat-label">${ssrInterpolate(kpi.label)}</div>`);
        if (kpi.soon) {
          _push(`<span class="stat-badge-soon">${ssrInterpolate(__props.t.kpi_coming_soon)}</span>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div></div></div></div>`);
      });
      _push(`<!--]--></div><!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Dashboard/KpiCards.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
