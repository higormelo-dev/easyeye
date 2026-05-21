import { ssrRenderTeleport, ssrInterpolate, ssrRenderAttr, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { ref, watch, nextTick, useSSRContext } from "vue";
const _sfc_main = {
  __name: "BulkRescheduleModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    selectedIds: { type: Array, default: () => [] },
    t: { type: Object, required: true }
  },
  emits: ["close", "done"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const date = ref("");
    const time = ref("");
    const saving = ref(false);
    const errorMsg = ref("");
    let bsModal = null;
    watch(() => props.open, async (val) => {
      if (val) {
        date.value = "";
        time.value = "";
        errorMsg.value = "";
        saving.value = false;
        await nextTick();
        if (!bsModal) bsModal = new bootstrap.Modal(document.getElementById("bulkRescheduleModal"));
        bsModal.show();
      } else {
        bsModal == null ? void 0 : bsModal.hide();
      }
    });
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        _push2(`<div id="bulkRescheduleModal" class="modal fade" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-alt me-2 text-primary"></i>${ssrInterpolate(__props.t.bulk_change_date_title)}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">`);
        if (errorMsg.value) {
          _push2(`<div class="alert alert-danger py-2 small">${ssrInterpolate(errorMsg.value)}</div>`);
        } else {
          _push2(`<!---->`);
        }
        _push2(`<p class="text-muted small mb-3"><i class="fas fa-info-circle me-1"></i> ${ssrInterpolate(__props.selectedIds.length)} ${ssrInterpolate(__props.t.selected_count)}</p><div class="mb-3"><label class="form-label fw-semibold">${ssrInterpolate(__props.t.reschedule_datetime.split(" ")[0])} — Data</label><input${ssrRenderAttr("value", date.value)} type="date" class="form-control" required></div><div class="mb-3"><label class="form-label fw-semibold">Hora <small class="text-muted fw-normal">(opcional)</small></label><input${ssrRenderAttr("value", time.value)} type="time" class="form-control" step="60"><div class="form-text text-muted small">${ssrInterpolate(__props.t.bulk_change_date_hint)}</div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${ssrInterpolate(__props.t.reschedule_cancel)}</button><button type="button" class="btn btn-primary"${ssrIncludeBooleanAttr(saving.value || !date.value || __props.selectedIds.length === 0) ? " disabled" : ""}>`);
        if (saving.value) {
          _push2(`<span class="spinner-border spinner-border-sm me-1"></span>`);
        } else {
          _push2(`<!---->`);
        }
        _push2(` ${ssrInterpolate(saving.value ? __props.t.saving : __props.t.btn_bulk_change_date)}</button></div></div></div></div>`);
      }, "body", false, _parent);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/BulkRescheduleModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
