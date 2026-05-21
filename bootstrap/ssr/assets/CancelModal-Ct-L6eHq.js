import { ssrRenderTeleport, ssrInterpolate, ssrRenderAttr, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { ref, watch, nextTick, useSSRContext } from "vue";
const _sfc_main = {
  __name: "CancelModal",
  __ssrInlineRender: true,
  props: {
    item: { type: Object, default: null },
    t: { type: Object, required: true }
  },
  emits: ["close", "cancelled"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const notes = ref("");
    const saving = ref(false);
    const errorMsg = ref("");
    let bsModal = null;
    watch(() => props.item, async (val) => {
      if (val) {
        notes.value = "";
        errorMsg.value = "";
        await nextTick();
        if (!bsModal) {
          bsModal = new bootstrap.Modal(document.getElementById("cancelScheduleModal"));
        }
        bsModal.show();
      } else {
        bsModal == null ? void 0 : bsModal.hide();
      }
    });
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        _push2(`<div id="cancelScheduleModal" class="modal fade" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-ban me-2 text-danger"></i>${ssrInterpolate(__props.t.cancel_title)}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">`);
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
        _push2(`<label class="form-label fw-semibold">${ssrInterpolate(__props.t.show_cancel_reason)}</label><textarea class="form-control" rows="3"${ssrRenderAttr("placeholder", __props.t.form_notes_ph)}>${ssrInterpolate(notes.value)}</textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${ssrInterpolate(__props.t.reschedule_cancel)}</button><button type="button" class="btn btn-danger"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>`);
        if (saving.value) {
          _push2(`<span class="spinner-border spinner-border-sm me-1"></span>`);
        } else {
          _push2(`<!---->`);
        }
        _push2(` ${ssrInterpolate(saving.value ? __props.t.saving : __props.t.cancel_title)}</button></div></div></div></div>`);
      }, "body", false, _parent);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/CancelModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
