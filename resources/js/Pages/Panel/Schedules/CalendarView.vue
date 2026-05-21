<script setup>
/**
 * CalendarView — grade diária com FullCalendar TimeGrid.
 *
 * Props:
 *   scheduleItems  → mesmos dados do Index (sort_time em ISO UTC)
 *   date           → "YYYY-MM-DD" da data filtrada pelo pai
 *   t              → strings de tradução
 *
 * Emits:
 *   event-click(item)          → clicou em agendamento existente
 *   slot-click({ datetime })   → clicou em célula vazia ("YYYY-MM-DDTHH:mm")
 */
import { ref, reactive, watch } from 'vue';
import FullCalendar from '@fullcalendar/vue3';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import ptBrLocale from '@fullcalendar/core/locales/pt-br';

const props = defineProps({
    scheduleItems: { type: Array,  default: () => [] },
    date:          { type: String, required: true },
    t:             { type: Object, required: true },
});

const emit = defineEmits(['event-click', 'slot-click']);

const calRef = ref(null);

// ── Bootstrap 5 badge → hex ───────────────────────────────────────────────────
const BADGE_HEX = {
    'bg-primary':   '#0d6efd',
    'bg-secondary': '#6c757d',
    'bg-success':   '#198754',
    'bg-info':      '#0dcaf0',
    'bg-warning':   '#ffc107',
    'bg-danger':    '#dc3545',
    'bg-dark':      '#212529',
};

// ── Transforma scheduleItems em eventos FullCalendar ──────────────────────────
function toCalendarEvents(items) {
    return items.map(item => {
        if (item.type === 'schedule') {
            const startMs = new Date(item.sort_time).getTime();
            return {
                id:              item.id,
                title:           item.name ?? '—',
                start:           item.sort_time,
                end:             new Date(startMs + 20 * 60_000).toISOString(),
                backgroundColor: BADGE_HEX[item.badge] ?? '#6c757d',
                borderColor:     item.doctor_color ?? '#0d6efd',
                textColor:       '#fff',
                extendedProps:   { ...item },
            };
        }

        // ScheduleEvent (compromisso não-clínico)
        const dateStr = item.sort_time.substring(0, 10);
        return {
            id:              `evt-${item.id}`,
            title:           item.title ?? '—',
            start:           item.sort_time,
            end:             `${dateStr}T${item.ends_at_fmt}:00`,
            backgroundColor: item.color ?? '#6c757d',
            borderColor:     item.color ?? '#6c757d',
            textColor:       '#fff',
            extendedProps:   { ...item },
        };
    });
}

// ── Renderização customizada (sem innerHTML — segura contra XSS) ───────────────
function renderEventContent(arg) {
    const item = arg.event.extendedProps;
    const isSchedule = item.type === 'schedule';

    const wrap = document.createElement('div');
    wrap.className = 'fc-ev-wrap';

    const timeEl = document.createElement('span');
    timeEl.className = 'fc-ev-time';
    timeEl.textContent = arg.timeText;
    wrap.appendChild(timeEl);

    const titleEl = document.createElement('div');
    titleEl.className = 'fc-ev-title';
    titleEl.textContent = isSchedule ? (item.name ?? '—') : (item.title ?? '—');
    wrap.appendChild(titleEl);

    if (isSchedule && item.label) {
        const labelEl = document.createElement('div');
        labelEl.className = 'fc-ev-label';
        labelEl.textContent = item.label;
        wrap.appendChild(labelEl);
    }

    if (isSchedule && item.doctor_name) {
        const docEl = document.createElement('div');
        docEl.className = 'fc-ev-doctor';
        docEl.textContent = item.doctor_name;
        wrap.appendChild(docEl);
    }

    return { domNodes: [wrap] };
}

// ── Locale ────────────────────────────────────────────────────────────────────
function resolveLocale() {
    const loc = (window.sessionLocale ?? 'pt-BR').toLowerCase();
    return loc.startsWith('pt') ? ptBrLocale : 'en';
}

// ── Opções FullCalendar — objeto reactive para atualizações incrementais ───────
const options = reactive({
    plugins:          [timeGridPlugin, interactionPlugin],
    initialView:      'timeGridDay',
    initialDate:      props.date,
    locale:           resolveLocale(),
    headerToolbar:    false,
    allDaySlot:       false,
    slotMinTime:      '07:00:00',
    slotMaxTime:      '20:30:00',
    slotDuration:     '00:15:00',
    slotLabelInterval:'01:00:00',
    slotLabelFormat:  { hour: '2-digit', minute: '2-digit', hour12: false },
    expandRows:       true,
    height:           'auto',
    nowIndicator:     true,
    eventDisplay:     'block',
    eventContent:     renderEventContent,
    events:           [],

    eventClick(info) {
        emit('event-click', info.event.extendedProps);
    },

    // dateClick dispara somente em células vazias, não em eventos
    dateClick(info) {
        const raw = info.dateStr;
        const dt  = raw.length >= 16 ? raw.substring(0, 16) : raw + 'T00:00';
        emit('slot-click', { datetime: dt });
    },
});

// ── Sincroniza eventos quando scheduleItems muda (polling / reload Inertia) ────
watch(
    () => props.scheduleItems,
    (items) => { options.events = toCalendarEvents(items); },
    { immediate: true },
);

// ── Sincroniza data quando o pai navega (prev / next / hoje) ──────────────────
watch(
    () => props.date,
    (newDate) => { calRef.value?.getApi().gotoDate(newDate); },
);
</script>

<template>
    <div class="calendar-view-wrap">
        <p class="text-muted small mb-2">
            <i class="fas fa-info-circle me-1"></i>{{ t.cal_empty_slot }}
        </p>
        <FullCalendar ref="calRef" :options="options" />
    </div>
</template>

<style scoped>
.calendar-view-wrap {
    font-size: .85rem;
}

/* ── Evento customizado ──────────────────────────────────────────────────────── */
:deep(.fc-ev-wrap) {
    padding: 2px 4px;
    display: flex;
    flex-direction: column;
    gap: 1px;
    height: 100%;
    overflow: hidden;
}

:deep(.fc-ev-time) {
    font-size: .68rem;
    opacity: .9;
    font-weight: 600;
    display: block;
}

:deep(.fc-ev-title) {
    font-weight: 600;
    font-size: .78rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

:deep(.fc-ev-label) {
    font-size: .65rem;
    opacity: .85;
}

:deep(.fc-ev-doctor) {
    font-size: .65rem;
    opacity: .75;
    font-style: italic;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ── Grid ────────────────────────────────────────────────────────────────────── */
:deep(.fc-timegrid-slot) {
    height: 2.2rem;
}

:deep(.fc-event) {
    cursor: pointer;
    border-left-width: 3px !important;
}

:deep(.fc-timegrid-slot-label) {
    font-size: .72rem;
    color: #6c757d;
}

:deep(.fc-scrollgrid) {
    border-color: #dee2e6;
}

:deep(.fc-timegrid-now-indicator-line) {
    border-color: #dc3545;
}
</style>
