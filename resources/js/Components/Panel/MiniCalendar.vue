<script setup>
import { ref, computed, watch } from 'vue';

/**
 * Calendário mensal compacto (barra lateral da Agenda).
 *
 * Vagas por dia (opcional, prop `availability`): número colorido e marcador com
 * FORMA própria por estado (não só cor) + aria-label/tooltip "N livres de M".
 * Estados: available | few | full | off | blocked (past/none = sem marcador).
 * A legenda usa os mesmos marcadores. `month-change` avisa o mês exibido
 * (o pai busca as vagas dele).
 */
const props = defineProps({
    modelValue: { type: String, default: '' }, // YYYY-MM-DD
    locale: { type: String, default: 'pt-BR' },
    // { 'YYYY-MM-DD': { state, free, capacity } }
    availability: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    // Textos (lang schedules): avail_* e legenda.
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue', 'month-change']);

const MARKED_STATES = ['available', 'few', 'full', 'off', 'blocked'];
const noWorkSchedule = computed(() => {
    const days = Object.values(props.availability ?? {});
    return days.length > 0 && days.every((day) => day.state === 'none');
});

function todayIso() {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

const today = todayIso();

function parseDate(str) {
    if (!str) return new Date();
    return new Date(str + 'T12:00:00');
}

const viewYear = ref(parseDate(props.modelValue).getFullYear());
const viewMonth = ref(parseDate(props.modelValue).getMonth()); // 0-based

const monthKey = computed(() => `${viewYear.value}-${String(viewMonth.value + 1).padStart(2, '0')}`);

watch(
    () => props.modelValue,
    (v) => {
        if (!v) return;
        const d = parseDate(v);
        viewYear.value = d.getFullYear();
        viewMonth.value = d.getMonth();
    },
);

watch(monthKey, (key) => emit('month-change', key), { immediate: true });

function prevMonth() {
    if (viewMonth.value === 0) {
        viewMonth.value = 11;
        viewYear.value--;
    } else viewMonth.value--;
}
function nextMonth() {
    if (viewMonth.value === 11) {
        viewMonth.value = 0;
        viewYear.value++;
    } else viewMonth.value++;
}

const monthLabel = computed(() =>
    new Date(viewYear.value, viewMonth.value, 1)
        .toLocaleDateString(props.locale, { month: 'long' })
        .replace(/^\w/, (c) => c.toUpperCase()),
);

// Abbreviations Dom Seg Ter Qua Qui Sex Sáb anchored on 2023-01-01 (Sunday)
const dayAbbrevs = computed(() =>
    Array.from({ length: 7 }, (_, i) =>
        new Date(2023, 0, 1 + i)
            .toLocaleDateString(props.locale, { weekday: 'short' })
            .replace(/\.$/, '')
            .substring(0, 3)
            .replace(/^\w/, (c) => c.toUpperCase()),
    ),
);

// Grid: leading empty cells + day cells
const cells = computed(() => {
    const first = new Date(viewYear.value, viewMonth.value, 1);
    const daysInMonth = new Date(viewYear.value, viewMonth.value + 1, 0).getDate();
    const startDow = first.getDay(); // 0=Sun

    const grid = [];
    for (let i = 0; i < startDow; i++) grid.push(null);
    for (let d = 1; d <= daysInMonth; d++) {
        const month = String(viewMonth.value + 1).padStart(2, '0');
        const day = String(d).padStart(2, '0');
        grid.push(`${viewYear.value}-${month}-${day}`);
    }
    // Pad to full weeks
    while (grid.length % 7 !== 0) grid.push(null);
    return grid;
});

const fmt = (text, params) =>
    Object.entries(params).reduce((acc, [key, value]) => acc.replace(`:${key}`, String(value)), text ?? '');

function stateOf(cell) {
    const state = cell && props.availability?.[cell]?.state;
    return MARKED_STATES.includes(state) ? state : null;
}

/** "15 de outubro: 3 livres de 20 (poucas vagas)" — leitor de tela e tooltip. */
function cellLabel(cell) {
    const date = parseDate(cell).toLocaleDateString(props.locale, { day: 'numeric', month: 'long' });
    const info = props.availability?.[cell];
    const state = stateOf(cell);
    if (!state) return date;

    const stateText = props.t[`avail_${state}`] ?? state;
    if (state === 'off' || state === 'blocked') return `${date}: ${stateText}`;

    const counts = fmt(props.t.avail_free_of ?? ':free livres de :capacity', {
        free: info.free,
        capacity: info.capacity,
    });

    return `${date}: ${counts} (${stateText})`;
}

const legend = computed(() =>
    MARKED_STATES.map((state) => ({ state, label: props.t[`avail_${state}`] ?? state })).filter(
        () => props.availability && !noWorkSchedule.value,
    ),
);

function select(dateStr) {
    if (!dateStr) return;
    emit('update:modelValue', dateStr);
}
</script>

<template>
    <div class="mini-cal" :aria-busy="loading ? 'true' : 'false'">
        <!-- Header -->
        <div class="mini-cal-header">
            <button
                type="button"
                class="mini-cal-nav"
                :aria-label="t.avail_prev_month ?? 'Mês anterior'"
                :title="t.avail_prev_month ?? 'Mês anterior'"
                @click="prevMonth"
            >
                &#8249;
            </button>
            <span class="mini-cal-title" aria-live="polite">{{ monthLabel }} {{ viewYear }}</span>
            <button
                type="button"
                class="mini-cal-nav"
                :aria-label="t.avail_next_month ?? 'Próximo mês'"
                :title="t.avail_next_month ?? 'Próximo mês'"
                @click="nextMonth"
            >
                &#8250;
            </button>
        </div>

        <!-- Day abbreviation row -->
        <div class="mini-cal-grid">
            <div v-for="abbr in dayAbbrevs" :key="abbr" class="mini-cal-dow" aria-hidden="true">{{ abbr }}</div>

            <!-- Day cells -->
            <template v-for="(cell, i) in cells" :key="i">
                <button
                    v-if="cell"
                    type="button"
                    class="mini-cal-cell"
                    :class="{
                        'is-today': cell === today,
                        'is-selected': cell === modelValue && cell !== today,
                        [`avail-${stateOf(cell)}`]: !!stateOf(cell),
                    }"
                    :data-date="cell"
                    :data-avail="stateOf(cell) ?? undefined"
                    :aria-label="cellLabel(cell)"
                    :aria-current="cell === modelValue ? 'date' : undefined"
                    :title="stateOf(cell) ? cellLabel(cell) : undefined"
                    @click="select(cell)"
                >
                    <span class="mini-cal-num">{{ Number(cell.substring(8)) }}</span>
                    <span v-if="stateOf(cell)" class="mini-cal-mark" aria-hidden="true"></span>
                </button>
                <div v-else class="mini-cal-cell is-empty" aria-hidden="true"></div>
            </template>
        </div>

        <!-- Legenda das vagas (mesmos marcadores) -->
        <ul v-if="legend.length" class="mini-cal-legend" data-testid="availability-legend">
            <li v-for="item in legend" :key="item.state" :class="`avail-${item.state}`">
                <span class="mini-cal-mark" aria-hidden="true"></span>{{ item.label }}
            </li>
        </ul>
        <p v-else-if="noWorkSchedule" class="mini-cal-note" role="status" data-testid="availability-no-schedule">
            {{
                t.avail_no_schedule ??
                'Sem escala de atendimento cadastrada. Configure a escala para visualizar as vagas.'
            }}
        </p>
    </div>
</template>

<style scoped>
.mini-cal {
    user-select: none;
    font-size: 0.8rem;
}

.mini-cal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.5rem;
}

.mini-cal-title {
    font-weight: 700;
    font-size: 0.82rem;
    text-transform: capitalize;
}

.mini-cal-nav {
    background: none;
    border: none;
    padding: 0 0.5rem;
    font-size: 1.2rem;
    line-height: 1;
    cursor: pointer;
    color: var(--bs-secondary-color, #555);
}
.mini-cal-nav:hover {
    color: var(--bs-body-color, #000);
}

.mini-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
}

.mini-cal-dow {
    text-align: center;
    font-size: 0.68rem;
    font-weight: 700;
    color: var(--bs-secondary-color, #888);
    padding: 0.15rem 0 0.3rem;
}

.mini-cal-cell {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1px;
    aspect-ratio: 1;
    border: 0;
    border-radius: 50%;
    background: transparent;
    padding: 0;
    cursor: pointer;
    font-size: 0.78rem;
    color: var(--bs-body-color, #333);
    transition: background 0.15s;
}
.mini-cal-cell:not(.is-empty):hover {
    background: var(--bs-tertiary-bg, #e9ecef);
}
.mini-cal-cell:focus-visible {
    outline: 2px solid var(--bs-primary, #0d6efd);
    outline-offset: 1px;
}
.mini-cal-cell.is-empty {
    cursor: default;
}
.mini-cal-cell.is-today {
    box-shadow: inset 0 0 0 2px var(--bs-primary, #0d6efd);
    font-weight: 700;
}
.mini-cal-cell.is-selected {
    background: var(--bs-tertiary-bg, #e9ecef);
    box-shadow: inset 0 0 0 1px var(--bs-secondary-color, #6c757d);
    font-weight: 600;
}
.mini-cal-cell.is-selected:hover {
    background: var(--bs-secondary-bg, #dee2e6);
}

.mini-cal-num {
    line-height: 1;
}

/* ── Marcadores de vagas: forma diferente por estado (não só cor) ───────── */
.avail-available {
    --mini-cal-state-color: #198754;
}
.avail-few {
    --mini-cal-state-color: #b45309;
}
.avail-full {
    --mini-cal-state-color: #dc3545;
}
.avail-off {
    --mini-cal-state-color: #6b7280;
}
.avail-blocked {
    --mini-cal-state-color: #4b5563;
}
.mini-cal-cell[data-avail] .mini-cal-num {
    color: var(--mini-cal-state-color);
    font-weight: 600;
}
.mini-cal-mark {
    display: inline-block;
    width: 6px;
    height: 6px;
    flex-shrink: 0;
}
/* com vagas: bolinha cheia */
.avail-available .mini-cal-mark {
    border-radius: 50%;
    background: var(--mini-cal-state-color);
}
/* poucas vagas: triângulo */
.avail-few .mini-cal-mark {
    width: 0;
    height: 0;
    border-left: 4px solid transparent;
    border-right: 4px solid transparent;
    border-bottom: 6px solid var(--mini-cal-state-color);
}
/* sem vagas: anel */
.avail-full .mini-cal-mark {
    border-radius: 50%;
    border: 1.5px solid var(--mini-cal-state-color);
    background: transparent;
}
/* sem expediente: traço */
.avail-off .mini-cal-mark {
    width: 7px;
    height: 2px;
    border-radius: 1px;
    background: var(--mini-cal-state-color);
}
/* bloqueado: quadrado */
.avail-blocked .mini-cal-mark {
    width: 6px;
    height: 6px;
    background: var(--mini-cal-state-color);
    border-radius: 1px;
}

.mini-cal-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem 0.75rem;
    list-style: none;
    margin: 0.6rem 0 0;
    padding: 0;
    font-size: 0.7rem;
    color: var(--bs-secondary-color, #6c757d);
}
.mini-cal-legend li {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
.mini-cal-note {
    margin: 0.6rem 0 0;
    font-size: 0.72rem;
    color: var(--bs-secondary-color, #6c757d);
}

[data-bs-theme='dark'] .avail-available {
    --mini-cal-state-color: #4ade80;
}
[data-bs-theme='dark'] .avail-few {
    --mini-cal-state-color: #fbbf24;
}
[data-bs-theme='dark'] .avail-full {
    --mini-cal-state-color: #f87171;
}
[data-bs-theme='dark'] .avail-off {
    --mini-cal-state-color: #9ca3af;
}
[data-bs-theme='dark'] .avail-blocked {
    --mini-cal-state-color: #cbd5e1;
}
</style>
