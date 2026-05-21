import { ssrInterpolate } from "vue/server-renderer";
import { useSSRContext } from "vue";
const _sfc_main = {
  __name: "PrimaryKpis",
  __ssrInlineRender: true,
  props: {
    primaryKpis: { type: Object, required: true },
    subscriptionKpis: { type: Object, required: true },
    t: { type: Object, required: true }
  },
  setup(__props) {
    return (_ctx, _push, _parent, _attrs) => {
      var _a, _b, _c, _d, _e, _f;
      _push(`<!--[--><div class="row g-3 mb-3"><div class="col-6 col-md-3"><div class="card stat-card stat-card--entities h-100"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="stat-icon stat-icon--entities"><i class="ti ti-building"></i></div><div><div class="stat-value">${ssrInterpolate(__props.primaryKpis.totalEntities)}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_entities)}</div><div class="d-flex gap-2 mt-1"><span class="period-badge">${ssrInterpolate(__props.t.today)} <span class="period-value">+${ssrInterpolate(__props.primaryKpis.newEntitiesToday)}</span></span><span class="period-badge">${ssrInterpolate(__props.t.month)} <span class="period-value">+${ssrInterpolate(__props.primaryKpis.newEntitiesMonth)}</span></span></div></div></div></div></div><div class="col-6 col-md-3"><div class="card stat-card stat-card--active h-100"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="stat-icon stat-icon--active"><i class="ti ti-circle-check"></i></div><div><div class="stat-value">${ssrInterpolate(((_a = __props.subscriptionKpis.subscriptionCounts) == null ? void 0 : _a.active) ?? 0)}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_active_subs)}</div></div></div></div></div><div class="col-6 col-md-3"><div class="card stat-card stat-card--trial h-100"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="stat-icon stat-icon--trial"><i class="ti ti-clock-hour-4"></i></div><div><div class="stat-value">${ssrInterpolate(((_b = __props.subscriptionKpis.subscriptionCounts) == null ? void 0 : _b.trial) ?? 0)}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_trial)}</div></div></div></div></div><div class="col-6 col-md-3"><div class="card stat-card stat-card--mrr h-100"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="stat-icon stat-icon--mrr"><i class="ti ti-currency-real"></i></div><div><div class="stat-value"> R$ ${ssrInterpolate(Number(__props.subscriptionKpis.mrr).toLocaleString("pt-BR", { minimumFractionDigits: 0 }))}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_mrr)}</div></div></div></div></div></div><div class="row g-3 mb-4"><div class="col-6 col-md-3"><div class="card stat-card stat-card--patients h-100"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="stat-icon stat-icon--patients"><i class="ti ti-users"></i></div><div><div class="stat-value">${ssrInterpolate((_c = __props.primaryKpis.totalPatients) == null ? void 0 : _c.toLocaleString("pt-BR"))}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_patients)}</div></div></div></div></div><div class="col-6 col-md-3"><div class="card stat-card stat-card--doctors h-100"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="stat-icon stat-icon--doctors"><i class="ti ti-stethoscope"></i></div><div><div class="stat-value">${ssrInterpolate((_d = __props.primaryKpis.totalDoctors) == null ? void 0 : _d.toLocaleString("pt-BR"))}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_doctors)}</div></div></div></div></div><div class="col-6 col-md-3"><div class="card stat-card stat-card--records h-100"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="stat-icon stat-icon--records"><i class="ti ti-file-text"></i></div><div><div class="stat-value">${ssrInterpolate((_e = __props.primaryKpis.totalMedicalRecords) == null ? void 0 : _e.toLocaleString("pt-BR"))}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_records)}</div></div></div></div></div><div class="col-6 col-md-3"><div class="card stat-card stat-card--schedules h-100"><div class="card-body d-flex align-items-center gap-3 p-3"><div class="stat-icon stat-icon--schedules"><i class="ti ti-calendar-event"></i></div><div><div class="stat-value">${ssrInterpolate((_f = __props.primaryKpis.totalSchedules) == null ? void 0 : _f.toLocaleString("pt-BR"))}</div><div class="stat-label">${ssrInterpolate(__props.t.kpi_schedules)}</div><div class="d-flex gap-2 mt-1"><span class="period-badge">${ssrInterpolate(__props.t.today)} <span class="period-value">${ssrInterpolate(__props.primaryKpis.schedulesToday)}</span></span></div></div></div></div></div></div><!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/ManagerDashboard/PrimaryKpis.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
