import { ssrRenderTeleport, ssrInterpolate, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrRenderAttr, ssrRenderComponent } from "vue/server-renderer";
import { ref, watch, nextTick, useSSRContext } from "vue";
import { _ as _sfc_main$1 } from "./SlotPicker-Bgvng9-B.js";
const _sfc_main = {
  __name: "RescheduleModal",
  __ssrInlineRender: true,
  props: {
    item: { type: Object, default: null },
    doctors: { type: Array, default: () => [] },
    t: { type: Object, required: true }
  },
  emits: ["close", "rescheduled"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const dateTime = ref("");
    const doctorId = ref("");
    const saving = ref(false);
    const errorMsg = ref("");
    const modalOpen = ref(false);
    const slotPickerRef = ref(null);
    let bsModal = null;
    watch(() => props.item, async (val) => {
      var _a;
      if (val) {
        dateTime.value = "";
        doctorId.value = val.doctor_id ?? "";
        errorMsg.value = "";
        saving.value = false;
        modalOpen.value = true;
        await nextTick();
        (_a = slotPickerRef.value) == null ? void 0 : _a.reset();
        if (!bsModal) {
          bsModal = new bootstrap.Modal(document.getElementById("rescheduleModal"));
        }
        bsModal.show();
      } else {
        bsModal == null ? void 0 : bsModal.hide();
      }
    });
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        var _a;
        _push2(`<div id="rescheduleModal" class="modal fade" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-alt me-2 text-primary"></i>${ssrInterpolate(__props.t.reschedule_title)}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">`);
        if (errorMsg.value) {
          _push2(`<div class="alert alert-danger py-2 small">${ssrInterpolate(errorMsg.value)}</div>`);
        } else {
          _push2(`<!---->`);
        }
        if (__props.item) {
          _push2(`<div class="mb-3 text-muted small">${ssrInterpolate(__props.item.time)} — <strong>${ssrInterpolate(__props.item.name)}</strong></div>`);
        } else {
          _push2(`<!---->`);
        }
        if (__props.doctors.length > 1) {
          _push2(`<div class="mb-3"><label class="form-label fw-semibold">${ssrInterpolate(__props.t.reschedule_doctor)}</label><select class="form-select"><option value=""${ssrIncludeBooleanAttr(Array.isArray(doctorId.value) ? ssrLooseContain(doctorId.value, "") : ssrLooseEqual(doctorId.value, "")) ? " selected" : ""}>${ssrInterpolate(__props.t.form_select)}</option><!--[-->`);
          ssrRenderList(__props.doctors, (d) => {
            _push2(`<option${ssrRenderAttr("value", d.id)}${ssrIncludeBooleanAttr(Array.isArray(doctorId.value) ? ssrLooseContain(doctorId.value, d.id) : ssrLooseEqual(doctorId.value, d.id)) ? " selected" : ""}>${ssrInterpolate(d.name)}</option>`);
          });
          _push2(`<!--]--></select></div>`);
        } else {
          _push2(`<!---->`);
        }
        if (modalOpen.value) {
          _push2(ssrRenderComponent(_sfc_main$1, {
            ref_key: "slotPickerRef",
            ref: slotPickerRef,
            modelValue: dateTime.value,
            "onUpdate:modelValue": ($event) => dateTime.value = $event,
            "doctor-id": doctorId.value,
            "schedule-id": ((_a = __props.item) == null ? void 0 : _a.id) ?? null,
            t: __props.t
          }, null, _parent));
        } else {
          _push2(`<!---->`);
        }
        _push2(`</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${ssrInterpolate(__props.t.reschedule_cancel)}</button><button type="button" class="btn btn-primary"${ssrIncludeBooleanAttr(saving.value || !dateTime.value) ? " disabled" : ""}>`);
        if (saving.value) {
          _push2(`<span class="spinner-border spinner-border-sm me-1"></span>`);
        } else {
          _push2(`<!---->`);
        }
        _push2(` ${ssrInterpolate(saving.value ? __props.t.saving : __props.t.reschedule_save)}</button></div></div></div></div>`);
      }, "body", false, _parent);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/RescheduleModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
