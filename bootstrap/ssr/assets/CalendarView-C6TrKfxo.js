import { ref, reactive, watch, mergeProps, unref, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderComponent } from "vue/server-renderer";
import FullCalendar from "@fullcalendar/vue3";
import timeGridPlugin from "@fullcalendar/timegrid";
import interactionPlugin from "@fullcalendar/interaction";
import ptBrLocale from "@fullcalendar/core/locales/pt-br";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "CalendarView",
  __ssrInlineRender: true,
  props: {
    scheduleItems: { type: Array, default: () => [] },
    date: { type: String, required: true },
    t: { type: Object, required: true }
  },
  emits: ["event-click", "slot-click"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const calRef = ref(null);
    const BADGE_HEX = {
      "bg-primary": "#0d6efd",
      "bg-secondary": "#6c757d",
      "bg-success": "#198754",
      "bg-info": "#0dcaf0",
      "bg-warning": "#ffc107",
      "bg-danger": "#dc3545",
      "bg-dark": "#212529"
    };
    function toCalendarEvents(items) {
      return items.map((item) => {
        if (item.type === "schedule") {
          const startMs = new Date(item.sort_time).getTime();
          return {
            id: item.id,
            title: item.name ?? "—",
            start: item.sort_time,
            end: new Date(startMs + 20 * 6e4).toISOString(),
            backgroundColor: BADGE_HEX[item.badge] ?? "#6c757d",
            borderColor: item.doctor_color ?? "#0d6efd",
            textColor: "#fff",
            extendedProps: { ...item }
          };
        }
        const dateStr = item.sort_time.substring(0, 10);
        return {
          id: `evt-${item.id}`,
          title: item.title ?? "—",
          start: item.sort_time,
          end: `${dateStr}T${item.ends_at_fmt}:00`,
          backgroundColor: item.color ?? "#6c757d",
          borderColor: item.color ?? "#6c757d",
          textColor: "#fff",
          extendedProps: { ...item }
        };
      });
    }
    function renderEventContent(arg) {
      const item = arg.event.extendedProps;
      const isSchedule = item.type === "schedule";
      const wrap = document.createElement("div");
      wrap.className = "fc-ev-wrap";
      const timeEl = document.createElement("span");
      timeEl.className = "fc-ev-time";
      timeEl.textContent = arg.timeText;
      wrap.appendChild(timeEl);
      const titleEl = document.createElement("div");
      titleEl.className = "fc-ev-title";
      titleEl.textContent = isSchedule ? item.name ?? "—" : item.title ?? "—";
      wrap.appendChild(titleEl);
      if (isSchedule && item.label) {
        const labelEl = document.createElement("div");
        labelEl.className = "fc-ev-label";
        labelEl.textContent = item.label;
        wrap.appendChild(labelEl);
      }
      if (isSchedule && item.doctor_name) {
        const docEl = document.createElement("div");
        docEl.className = "fc-ev-doctor";
        docEl.textContent = item.doctor_name;
        wrap.appendChild(docEl);
      }
      return { domNodes: [wrap] };
    }
    function resolveLocale() {
      const loc = (window.sessionLocale ?? "pt-BR").toLowerCase();
      return loc.startsWith("pt") ? ptBrLocale : "en";
    }
    const options = reactive({
      plugins: [timeGridPlugin, interactionPlugin],
      initialView: "timeGridDay",
      initialDate: props.date,
      locale: resolveLocale(),
      headerToolbar: false,
      allDaySlot: false,
      slotMinTime: "07:00:00",
      slotMaxTime: "20:30:00",
      slotDuration: "00:15:00",
      slotLabelInterval: "01:00:00",
      slotLabelFormat: { hour: "2-digit", minute: "2-digit", hour12: false },
      expandRows: true,
      height: "auto",
      nowIndicator: true,
      eventDisplay: "block",
      eventContent: renderEventContent,
      events: [],
      eventClick(info) {
        emit("event-click", info.event.extendedProps);
      },
      // dateClick dispara somente em células vazias, não em eventos
      dateClick(info) {
        const raw = info.dateStr;
        const dt = raw.length >= 16 ? raw.substring(0, 16) : raw + "T00:00";
        emit("slot-click", { datetime: dt });
      }
    });
    watch(
      () => props.scheduleItems,
      (items) => {
        options.events = toCalendarEvents(items);
      },
      { immediate: true }
    );
    watch(
      () => props.date,
      (newDate) => {
        var _a;
        (_a = calRef.value) == null ? void 0 : _a.getApi().gotoDate(newDate);
      }
    );
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "calendar-view-wrap" }, _attrs))} data-v-a393fdc8><p class="text-muted small mb-2" data-v-a393fdc8><i class="fas fa-info-circle me-1" data-v-a393fdc8></i>${ssrInterpolate(__props.t.cal_empty_slot)}</p>`);
      _push(ssrRenderComponent(unref(FullCalendar), {
        ref_key: "calRef",
        ref: calRef,
        options
      }, null, _parent));
      _push(`</div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/CalendarView.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const CalendarView = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-a393fdc8"]]);
export {
  CalendarView as default
};
