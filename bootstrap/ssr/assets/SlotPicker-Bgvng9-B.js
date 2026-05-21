import { ref, computed, watch, onMounted, onUnmounted, nextTick, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrIncludeBooleanAttr, ssrRenderAttr, ssrInterpolate, ssrRenderList, ssrRenderStyle, ssrRenderClass } from "vue/server-renderer";
const _sfc_main = {
  __name: "SlotPicker",
  __ssrInlineRender: true,
  props: {
    modelValue: { type: String, default: "" },
    doctorId: { type: String, default: "" },
    scheduleId: { type: String, default: null },
    t: { type: Object, required: true }
  },
  emits: ["update:modelValue", "slot-selected"],
  setup(__props, { expose: __expose, emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const selectedDate = ref((/* @__PURE__ */ new Date()).toISOString().substring(0, 10));
    const slots = ref([]);
    const slotsLoading = ref(false);
    const hasSchedule = ref(false);
    const slotInterval = ref(15);
    const showManualTime = ref(false);
    const csrf = () => {
      var _a;
      return ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "";
    };
    function generateFallback(date, interval) {
      const result = [];
      let min = 7 * 60;
      while (min < 19 * 60) {
        const h = String(Math.floor(min / 60)).padStart(2, "0");
        const m = String(min % 60).padStart(2, "0");
        const time = `${h}:${m}`;
        result.push({ time, datetime: `${date}T${time}`, available: true });
        min += interval;
      }
      return result;
    }
    async function fetchSlots() {
      if (!props.doctorId || !selectedDate.value) {
        slots.value = [];
        return;
      }
      slotsLoading.value = true;
      try {
        const qs = new URLSearchParams({ doctor_id: props.doctorId, date: selectedDate.value });
        if (props.scheduleId) qs.set("schedule_id", props.scheduleId);
        const res = await fetch(`/panel/schedules/slots?${qs}`, {
          headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf() }
        });
        if (!res.ok) throw new Error("slots-fetch-error");
        const json = await res.json();
        slotInterval.value = json.interval ?? 15;
        hasSchedule.value = json.has_schedule ?? false;
        slots.value = json.has_schedule ? json.slots ?? [] : generateFallback(selectedDate.value, json.interval ?? 15);
        if (props.modelValue && !slots.value.some((s) => s.datetime === props.modelValue)) {
          emit("update:modelValue", "");
        }
      } catch {
        slots.value = [];
      } finally {
        slotsLoading.value = false;
      }
    }
    function clearSlot() {
      emit("update:modelValue", "");
    }
    function slotBtnClass(slot) {
      if (props.modelValue === slot.datetime) return "btn-primary";
      if (!slot.available) return "btn-secondary opacity-50";
      return "btn-outline-primary";
    }
    const selectedLabel = computed(() => {
      if (!props.modelValue) return "";
      try {
        const locale = window.sessionLocale ?? "pt-BR";
        const raw = props.modelValue.length === 16 ? props.modelValue + ":00" : props.modelValue;
        const dt = new Date(raw);
        const d = dt.toLocaleDateString(locale, { weekday: "short", day: "2-digit", month: "short" });
        const h = dt.toLocaleTimeString(locale, { hour: "2-digit", minute: "2-digit" });
        return `${d} · ${h}`;
      } catch {
        return props.modelValue;
      }
    });
    watch(
      () => props.doctorId,
      (newId, oldId) => {
        if (newId === oldId) return;
        clearSlot();
        fetchSlots();
      }
    );
    watch(selectedDate, () => {
      clearSlot();
      fetchSlots();
    });
    watch(
      () => props.modelValue,
      (val) => {
        if (val && val.length >= 10) {
          const date = val.substring(0, 10);
          if (date !== selectedDate.value) {
            selectedDate.value = date;
          }
        }
      },
      { immediate: false }
    );
    let pollTimer = null;
    onMounted(() => {
      fetchSlots();
      pollTimer = setInterval(() => {
        if (props.doctorId && selectedDate.value) fetchSlots();
      }, 15e3);
    });
    onUnmounted(() => {
      if (pollTimer) clearInterval(pollTimer);
    });
    __expose({
      /** Reinicia para novo agendamento. */
      reset(date) {
        selectedDate.value = date || (/* @__PURE__ */ new Date()).toISOString().substring(0, 10);
        slots.value = [];
        showManualTime.value = false;
        nextTick(fetchSlots);
      },
      /** Navega para uma data específica sem limpar o valor selecionado (modo edição). */
      setDate(date) {
        if (date && date !== selectedDate.value) {
          selectedDate.value = date;
        }
      }
    });
    return (_ctx, _push, _parent, _attrs) => {
      var _a;
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "slot-picker" }, _attrs))}><div class="input-group input-group-sm mb-2"><button type="button" class="btn btn-outline-secondary"${ssrIncludeBooleanAttr(!selectedDate.value) ? " disabled" : ""}><i class="fas fa-chevron-left"></i></button><input${ssrRenderAttr("value", selectedDate.value)} type="date" class="form-control text-center fw-semibold"><button type="button" class="btn btn-outline-secondary"${ssrIncludeBooleanAttr(!selectedDate.value) ? " disabled" : ""}><i class="fas fa-chevron-right"></i></button></div>`);
      if (!__props.doctorId) {
        _push(`<p class="text-muted small mb-1"><i class="fas fa-user-md me-1"></i>${ssrInterpolate(__props.t.form_select_doctor_first)}</p>`);
      } else if (!selectedDate.value) {
        _push(`<p class="text-muted small mb-1"><i class="fas fa-calendar me-1"></i>${ssrInterpolate(__props.t.form_select_date_first)}</p>`);
      } else if (slotsLoading.value) {
        _push(`<div class="py-1 d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-primary"></span><span class="text-muted small">${ssrInterpolate(__props.t.form_loading_slots)}</span></div>`);
      } else if (slots.value.length > 0) {
        _push(`<!--[--><div class="d-flex flex-wrap gap-1 mt-1"><!--[-->`);
        ssrRenderList(slots.value, (slot) => {
          _push(`<button type="button" style="${ssrRenderStyle({ "font-size": ".78rem", "min-width": "52px" })}" class="${ssrRenderClass([slotBtnClass(slot), "btn btn-sm px-2 py-1"])}"${ssrIncludeBooleanAttr(!slot.available && __props.modelValue !== slot.datetime) ? " disabled" : ""}${ssrRenderAttr("title", !slot.available && __props.modelValue !== slot.datetime ? __props.t.form_slot_occupied : slot.time)}>${ssrInterpolate(slot.time)}</button>`);
        });
        _push(`<!--]--></div><small class="text-muted mt-2 d-block"><i class="fas fa-info-circle me-1"></i> ${ssrInterpolate(((_a = __props.t.form_interval_hint) == null ? void 0 : _a.replace(":min", slotInterval.value)) ?? `Intervalo de ${slotInterval.value} min`)} `);
        if (!hasSchedule.value) {
          _push(`<span> — ${ssrInterpolate(__props.t.form_no_scale)}</span>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</small><!--]-->`);
      } else {
        _push(`<p class="text-muted small mb-1"><i class="fas fa-clock me-1"></i>${ssrInterpolate(__props.t.form_no_slots)}</p>`);
      }
      if (__props.modelValue && !showManualTime.value) {
        _push(`<div class="mt-2 alert alert-primary py-1 px-2 d-flex align-items-center gap-2 small mb-1 rounded"><i class="fas fa-check-circle flex-shrink-0"></i><span class="fw-semibold">${ssrInterpolate(selectedLabel.value)}</span><button type="button" class="btn-close ms-auto" style="${ssrRenderStyle({ "font-size": ".6rem" })}" aria-label="Limpar horário"></button></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`<div class="mt-2"><button type="button" class="btn btn-link btn-sm p-0 text-muted text-decoration-none" style="${ssrRenderStyle({ "font-size": ".78rem" })}"><i class="fas fa-pencil-alt me-1"></i>${ssrInterpolate(__props.t.form_manual_override)}</button>`);
      if (showManualTime.value) {
        _push(`<div class="mt-1"><input${ssrRenderAttr("value", __props.modelValue)} type="datetime-local" class="form-control form-control-sm"></div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/SlotPicker.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
