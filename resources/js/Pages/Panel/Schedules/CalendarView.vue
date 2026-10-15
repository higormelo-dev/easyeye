<script setup>
/**
 * CalendarView — calendário da Agenda com FullCalendar: dia e semana
 * (TimeGrid) e mês (DayGrid). Os dados vêm do servidor já limitados ao
 * período visível (filtro `range` da URL) e com os mesmos filtros/escopo da
 * lista; cores por situação, selo de encaixe e sessão da série.
 *
 * Props:
 *   scheduleItems  → linhas do Index (sort_time em ISO UTC)
 *   date           → "YYYY-MM-DD" da data filtrada pelo pai
 *   range          → 'day' | 'week' | 'month'
 *   t              → strings de tradução
 *
 * Emits:
 *   event-click(item)          → clicou em agendamento (abre o detalhe)
 *   slot-click({ datetime })   → clicou em horário vazio ("YYYY-MM-DDTHH:mm")
 *   range-change(range)        → trocou dia/semana/mês
 *   date-click(date)           → clicou no número de um dia (abre o dia)
 */
import { ref, reactive, watch } from 'vue';
import FullCalendar from '@fullcalendar/vue3';
import timeGridPlugin from '@fullcalendar/timegrid';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import ptBrLocale from '@fullcalendar/core/locales/pt-br';
import { sessionLabel } from '@/Support/sessionLabel.js';

const props = defineProps({
    scheduleItems: { type: Array, default: () => [] },
    date: { type: String, required: true },
    range: { type: String, default: 'day' },
    t: { type: Object, required: true },
});

const emit = defineEmits(['event-click', 'slot-click', 'range-change', 'date-click']);

const calRef = ref(null);

const VIEWS = { day: 'timeGridDay', week: 'timeGridWeek', month: 'dayGridMonth' };

const rangeOptions = [
    { key: 'day', icon: 'ti ti-calendar-event' },
    { key: 'week', icon: 'ti ti-calendar-week' },
    { key: 'month', icon: 'ti ti-calendar-month' },
];

// ── Bootstrap 5 badge → hex ───────────────────────────────────────────────────
const BADGE_HEX = {
    'bg-primary': '#0d6efd',
    'bg-secondary': '#6c757d',
    'bg-success': '#198754',
    'bg-info': '#0aa2c0',
    'bg-warning': '#b7791f',
    'bg-danger': '#dc3545',
    'bg-dark': '#212529',
    'bg-purple': '#7c3aed',
    'bg-orange': '#ea580c',
    'bg-teal': '#0e9384',
};

function sessionText(item) {
    return sessionLabel(item.session_number, item.session_total);
}

// ── Transforma scheduleItems em eventos FullCalendar ──────────────────────────
function toCalendarEvents(items) {
    return items.map((item) => {
        if (item.type === 'schedule') {
            const startMs = new Date(item.sort_time).getTime();
            return {
                id: item.id,
                title: item.name ?? '—',
                start: item.sort_time,
                end: new Date(startMs + 20 * 60_000).toISOString(),
                // item.badge vem com a classe completa ("bg-info text-dark");
                // só o 1º token é a cor — sem isso vários status saíam cinza.
                backgroundColor: BADGE_HEX[item.badge?.split(' ')[0]] ?? '#6c757d',
                borderColor: item.doctor_color ?? '#0d6efd',
                textColor: '#fff',
                classNames: [
                    item.is_fit_in ? 'fc-ev-fit-in' : null,
                    item.is_urgent_care ? 'fc-ev-urgent' : null,
                ].filter(Boolean),
                extendedProps: { ...item },
            };
        }

        if (item.type === 'block') {
            return {
                id: `blk-${item.id}`,
                title: item.title ?? '—',
                start: item.sort_time,
                end: item.ends_at_iso,
                backgroundColor: '#6b7280',
                borderColor: '#4b5563',
                textColor: '#fff',
                classNames: ['fc-ev-block'],
                extendedProps: { ...item },
            };
        }

        // ScheduleEvent (compromisso não-clínico)
        const dateStr = item.sort_time.substring(0, 10);
        return {
            id: `evt-${item.id}`,
            title: item.title ?? '—',
            start: item.sort_time,
            end: `${dateStr}T${item.ends_at_fmt}:00`,
            backgroundColor: item.color ?? '#6c757d',
            borderColor: item.color ?? '#6c757d',
            textColor: '#fff',
            extendedProps: { ...item },
        };
    });
}

// ── Renderização customizada (sem innerHTML — segura contra XSS) ───────────────
function el(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function renderEventContent(arg) {
    const item = arg.event.extendedProps;
    const isSchedule = item.type === 'schedule';
    const compact = arg.view.type === 'dayGridMonth';

    const wrap = el('div', compact ? 'fc-ev-wrap fc-ev-wrap--compact' : 'fc-ev-wrap');

    // Hora e nome na MESMA linha: no bloco de 20 min da semana o nome
    // (o que a recepção procura) não pode cair para fora.
    const titleRow = el('div', 'fc-ev-title');
    const start = isSchedule ? item.time : (arg.timeText ?? '').split(/\s*[-–]\s*/)[0];
    if (start) titleRow.appendChild(el('span', 'fc-ev-time', start));
    if (item.type === 'block') titleRow.appendChild(el('span', 'fc-ev-tag', props.t.block_badge ?? 'Bloqueio'));
    titleRow.appendChild(document.createTextNode(isSchedule ? (item.name ?? '—') : (item.title ?? '—')));
    wrap.appendChild(titleRow);

    if (isSchedule) {
        const tags = el('div', 'fc-ev-tags');
        if (item.is_fit_in) tags.appendChild(el('span', 'fc-ev-tag fc-ev-tag--fit', props.t.fit_in_badge ?? 'Encaixe'));
        if (item.is_urgent_care) {
            tags.appendChild(
                el('span', 'fc-ev-tag fc-ev-tag--urgent', props.t.urgent_care_badge ?? 'Pronto atendimento'),
            );
        }
        const session = sessionText(item);
        if (session) tags.appendChild(el('span', 'fc-ev-tag', session));
        if (!compact && item.label) tags.appendChild(el('span', 'fc-ev-label', item.label));
        if (tags.childNodes.length) wrap.appendChild(tags);

        // Médico só na visão do dia (na semana o bloco de 20 min não comporta).
        if (arg.view.type === 'timeGridDay' && item.doctor_name) {
            wrap.appendChild(el('div', 'fc-ev-doctor', item.doctor_name));
        }
    }

    return { domNodes: [wrap] };
}

// Leitor de tela: o bloco do evento ganha um rótulo completo.
function eventDidMount(arg) {
    const item = arg.event.extendedProps;
    if (item.type !== 'schedule') return;
    const parts = [item.time, item.name, item.label, item.doctor_name];
    if (item.is_fit_in) parts.push(props.t.fit_in_badge ?? 'Encaixe');
    if (item.is_urgent_care) parts.push(props.t.urgent_care_badge ?? 'Pronto atendimento');
    const session = sessionText(item);
    if (session) parts.push(`${props.t.session_short ?? 'Sessão'} ${session}`);
    arg.el.setAttribute('aria-label', parts.filter(Boolean).join(' · '));
    arg.el.setAttribute('title', parts.filter(Boolean).join(' · '));
}

// ── Locale ────────────────────────────────────────────────────────────────────
function resolveLocale() {
    const loc = (window.sessionLocale ?? 'pt-BR').toLowerCase();
    return loc.startsWith('pt') ? ptBrLocale : 'en';
}

// ── Opções FullCalendar — objeto reactive para atualizações incrementais ───────
const options = reactive({
    plugins: [timeGridPlugin, dayGridPlugin, interactionPlugin],
    initialView: VIEWS[props.range] ?? 'timeGridDay',
    initialDate: props.date,
    locale: resolveLocale(),
    firstDay: 0, // domingo, como o calendário lateral e o período do servidor
    headerToolbar: false,
    allDaySlot: false,
    slotMinTime: '07:00:00',
    slotMaxTime: '20:30:00',
    slotDuration: '00:15:00',
    slotLabelInterval: '01:00:00',
    slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    expandRows: true,
    height: 'auto',
    nowIndicator: true,
    eventDisplay: 'block',
    dayMaxEvents: 3,
    showNonCurrentDates: false,
    fixedWeekCount: false,
    navLinks: true,
    navLinkDayClick(date) {
        const iso = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        emit('date-click', iso);
    },
    eventContent: renderEventContent,
    eventDidMount,
    events: [],

    eventClick(info) {
        emit('event-click', info.event.extendedProps);
    },

    // dateClick dispara somente em células vazias, não em eventos
    dateClick(info) {
        if (info.view.type === 'dayGridMonth') {
            emit('date-click', info.dateStr.substring(0, 10));
            return;
        }
        const raw = info.dateStr;
        const dt = raw.length >= 16 ? raw.substring(0, 16) : raw + 'T00:00';
        emit('slot-click', { datetime: dt });
    },
});

// ── Sincroniza eventos quando scheduleItems muda (polling / reload Inertia) ────
watch(
    () => props.scheduleItems,
    (items) => {
        options.events = toCalendarEvents(items);
    },
    { immediate: true },
);

// ── Sincroniza data quando o pai navega (prev / next / hoje) ──────────────────
watch(
    () => props.date,
    (newDate) => {
        calRef.value?.getApi().gotoDate(newDate);
    },
);

watch(
    () => props.range,
    (range) => {
        calRef.value?.getApi().changeView(VIEWS[range] ?? 'timeGridDay', props.date);
    },
);
</script>

<template>
    <div class="calendar-view-wrap">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">
            <p class="text-muted small mb-0"><i class="fas fa-info-circle me-1"></i>{{ t.cal_empty_slot }}</p>
            <div class="btn-group btn-group-sm" role="group" :aria-label="t.cal_view_label ?? 'Visualização'">
                <button
                    v-for="option in rangeOptions"
                    :key="option.key"
                    type="button"
                    class="btn"
                    :class="range === option.key ? 'btn-primary' : 'btn-outline-secondary'"
                    :aria-pressed="range === option.key ? 'true' : 'false'"
                    :data-range="option.key"
                    @click="emit('range-change', option.key)"
                >
                    <i :class="option.icon" class="me-1" aria-hidden="true"></i
                    >{{ t[`cal_${option.key}`] ?? option.key }}
                </button>
            </div>
        </div>
        <FullCalendar ref="calRef" :options="options" />
    </div>
</template>

<style scoped>
.calendar-view-wrap {
    font-size: 0.85rem;
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

:deep(.fc-ev-wrap--compact) {
    flex-direction: row;
    align-items: center;
    gap: 4px;
    padding: 0 3px;
}

:deep(.fc-ev-time) {
    font-size: 0.68rem;
    opacity: 0.9;
    font-weight: 700;
    margin-right: 4px;
}

:deep(.fc-ev-title) {
    font-weight: 600;
    font-size: 0.78rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

:deep(.fc-ev-tags) {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 3px;
}

:deep(.fc-ev-tag) {
    display: inline-block;
    font-size: 0.6rem;
    font-weight: 700;
    line-height: 1.3;
    padding: 0 4px;
    margin-right: 3px;
    border-radius: 3px;
    background: rgba(255, 255, 255, 0.25);
    border: 1px solid rgba(255, 255, 255, 0.55);
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

:deep(.fc-ev-tag--fit) {
    background: #fff;
    color: #5b21b6;
    border-color: #fff;
}

:deep(.fc-ev-tag--urgent) {
    background: #fff;
    color: #b91c1c;
    border-color: #fff;
}

:deep(.fc-ev-label) {
    font-size: 0.65rem;
    opacity: 0.9;
}

:deep(.fc-ev-doctor) {
    font-size: 0.65rem;
    opacity: 0.8;
    font-style: italic;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Encaixe: borda tracejada, além do selo (não depender só de cor). */
:deep(.fc-ev-fit-in) {
    border-style: dashed !important;
}

:deep(.fc-ev-block) {
    background-image: repeating-linear-gradient(
        45deg,
        rgba(255, 255, 255, 0.12) 0,
        rgba(255, 255, 255, 0.12) 6px,
        transparent 6px,
        transparent 12px
    );
}

/* ── Grid ────────────────────────────────────────────────────────────────────── */
:deep(.fc-timegrid-slot) {
    height: 2.2rem;
}

:deep(.fc-event) {
    cursor: pointer;
    border-left-width: 3px !important;
    /* O template (preclinic-style) dá margem/padding grandes e centraliza o
       texto do evento — no bloco curto da semana o nome sumia e, no mês,
       a altura medida pelo FullCalendar não batia (cortava o "+N mais"). */
    margin: 1px 2px !important;
    padding: 0 !important;
    text-align: left !important;
}

:deep(.fc-daygrid-event) {
    margin-top: 1px !important;
}

:deep(.fc-daygrid-more-link) {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--bs-primary);
}

:deep(.fc-timegrid-slot-label) {
    font-size: 0.72rem;
    color: var(--bs-secondary-color, #6c757d);
}

:deep(.fc-scrollgrid) {
    border-color: var(--bs-border-color, #dee2e6);
}

:deep(.fc-timegrid-now-indicator-line) {
    border-color: #dc3545;
}

:deep(.fc-daygrid-day-number),
:deep(.fc-col-header-cell-cushion) {
    color: var(--bs-body-color);
    text-decoration: none;
}

[data-bs-theme='dark'] .calendar-view-wrap :deep(.fc-theme-standard td),
[data-bs-theme='dark'] .calendar-view-wrap :deep(.fc-theme-standard th),
[data-bs-theme='dark'] .calendar-view-wrap :deep(.fc-scrollgrid) {
    border-color: #374151;
}

[data-bs-theme='dark'] .calendar-view-wrap :deep(.fc-day-today) {
    background: rgba(96, 165, 250, 0.1);
}
</style>
