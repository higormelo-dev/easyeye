<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Resumo do dia (da clínica, ou "Meu dia" do médico).
 *
 * Andamento = atendidos sobre o que AINDA CONTA: total − faltas/cancelados
 * ("3 de 14 atendidos"). Faltas e cancelamentos aparecem à parte — antes
 * entravam como "concluído" e o card dizia "30% concluído" com 0 atendidos.
 */
const props = defineProps({
    stats: { type: Object, required: true },
    // Consultas de hoje (mesma lista da agenda) — base da quebra por turno.
    items: { type: Array, default: () => [] },
    isRefreshing: { type: Boolean, default: false },
    // Resumo só das consultas do médico logado ("Meu dia").
    mine: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const { number } = useLocaleFormat();
const tx = (key, params = {}) =>
    Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), props.t[key] ?? '');

const total = computed(() => Number(props.stats.today_count ?? 0));
const attended = computed(() => Number(props.stats.attended_today ?? 0));
const missed = computed(() => Number(props.stats.cancelled_today ?? 0));
const waiting = computed(() => Math.min(Number(props.stats.waiting_now ?? 0), Number(props.stats.pending_today ?? 0)));
const expected = computed(() => Math.max(0, total.value - missed.value));
const toCome = computed(() => Math.max(0, expected.value - attended.value - waiting.value));

const tiles = computed(() => [
    { key: 'total', value: total.value, label: props.t.summary_total },
    { key: 'attended', value: attended.value, label: props.t.summary_attended },
    { key: 'pending', value: Number(props.stats.pending_today ?? 0), label: props.t.summary_pending },
    { key: 'cancelled', value: missed.value, label: props.t.summary_cancelled },
]);

const pct = (value) => (expected.value > 0 ? (value / expected.value) * 100 : 0);
const donePct = computed(() => Math.round(pct(attended.value)));

// Barra do andamento: atendidos, na clínica agora (chegaram e aguardam), a chegar.
const segments = computed(() =>
    [
        { key: 'attended', value: attended.value, label: props.t.summary_attended },
        { key: 'waiting', value: waiting.value, label: props.t.summary_in_clinic },
        { key: 'upcoming', value: toCome.value, label: props.t.summary_to_come },
    ].map((segment) => ({ ...segment, width: pct(segment.value) })),
);

const progressText = computed(() =>
    tx('summary_progress', { attended: number(attended.value), expected: number(expected.value) }),
);
const progressAria = computed(
    () => `${props.t.summary_progress_label}: ${progressText.value} (${number(donePct.value)}%)`,
);

// ── Quebra por turno (mesmos limites das abas da agenda) ────────────────────
const SHIFTS = ['morning', 'afternoon', 'evening'];

function shiftOfHour(hour) {
    if (hour < 13) return 'morning';
    if (hour < 18) return 'afternoon';
    return 'evening';
}

const nowHour = ref(new Date().getHours());
let clock = null;
onMounted(() => {
    clock = setInterval(() => {
        nowHour.value = new Date().getHours();
    }, 60_000);
});
onBeforeUnmount(() => clearInterval(clock));

// A lista da agenda tem teto no servidor: se veio cortada, a quebra por turno
// ficaria errada — então só aparece com a lista completa.
const shiftRows = computed(() => {
    if (!props.items.length || props.items.length < total.value) return [];

    const current = shiftOfHour(nowHour.value);

    return SHIFTS.map((key) => {
        const rows = props.items.filter((item) => (item.shift ?? shiftOfHour(item.hour ?? 0)) === key);
        const count = (group) => rows.filter((item) => item.group === group).length;

        return {
            key,
            label: props.t[`shift_${key}`] ?? key,
            total: rows.length,
            attended: count('attended'),
            pending: count('pending'),
            isCurrent: key === current,
        };
    }).filter((row) => row.total > 0 || row.isCurrent);
});
</script>

<template>
    <section class="card db-card day-summary" :aria-label="mine ? t.section_my_day_summary : t.section_day_summary">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-chart-donut-3" aria-hidden="true"></i>
                {{ mine ? t.section_my_day_summary : t.section_day_summary }}
            </h3>
            <i v-if="isRefreshing" class="ti ti-loader-2 db-spin text-muted small" aria-hidden="true"></i>
        </div>

        <div class="db-card-body">
            <div class="ds-grid">
                <div
                    v-for="tile in tiles"
                    :key="tile.key"
                    class="day-summary-stat"
                    :class="`day-summary-stat--${tile.key}`"
                    :data-summary="tile.key"
                >
                    <div class="ds-value" :class="`ds-value--${tile.key}`">{{ number(tile.value) }}</div>
                    <div class="ds-label">{{ tile.label }}</div>
                </div>
            </div>

            <template v-if="expected > 0">
                <div class="d-flex justify-content-between align-items-baseline mt-3 mb-1 gap-2">
                    <span class="ds-section-title">{{ t.summary_progress_label }}</span>
                    <span class="ds-progress-text" data-summary="progress">
                        {{ progressText }} <span class="text-muted">· {{ number(donePct) }}%</span>
                    </span>
                </div>
                <div class="progress ds-progress" role="img" :aria-label="progressAria">
                    <div
                        v-for="segment in segments"
                        :key="segment.key"
                        class="progress-bar"
                        :class="`ds-progress--${segment.key}`"
                        :style="{ width: `${segment.width}%` }"
                        :title="`${segment.label}: ${number(segment.value)}`"
                    ></div>
                </div>
                <ul class="ds-legend" aria-hidden="true">
                    <li v-for="segment in segments" :key="segment.key" :class="`ds-legend--${segment.key}`">
                        {{ segment.label }} <strong>{{ number(segment.value) }}</strong>
                    </li>
                </ul>
                <p v-if="missed > 0" class="ds-note" data-summary="missed-note">
                    {{ tx(missed === 1 ? 'summary_missed_note_one' : 'summary_missed_note_other', { count: number(missed) }) }}
                </p>
            </template>
            <p v-else-if="total > 0" class="ds-note mt-3" data-summary="all-missed">{{ t.summary_all_missed }}</p>

            <template v-if="shiftRows.length">
                <div class="ds-section-title mt-3 mb-1">{{ t.summary_by_shift }}</div>
                <table class="table table-sm ds-shifts mb-0">
                    <thead class="visually-hidden">
                        <tr>
                            <th scope="col">{{ t.shifts_label }}</th>
                            <th scope="col">{{ t.summary_total }}</th>
                            <th scope="col">{{ t.summary_attended }}</th>
                            <th scope="col">{{ t.summary_pending }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in shiftRows"
                            :key="row.key"
                            :class="{ 'ds-shift--now': row.isCurrent }"
                            :data-shift="row.key"
                        >
                            <th scope="row" class="fw-medium">
                                {{ row.label }}
                                <span v-if="row.isCurrent" class="badge db-badge-now ms-1">{{ t.shift_now }}</span>
                            </th>
                            <td class="text-end fw-semibold">{{ number(row.total) }}</td>
                            <td class="text-end ds-value--attended" :title="t.summary_attended">
                                <i class="ti ti-check" aria-hidden="true"></i> {{ number(row.attended) }}
                            </td>
                            <td class="text-end ds-value--pending" :title="t.summary_pending">
                                <i class="ti ti-clock" aria-hidden="true"></i> {{ number(row.pending) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </div>
    </section>
</template>

<style scoped>
.ds-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem;
}

.ds-grid .day-summary-stat {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.25rem;
    padding: 0.5rem 0.75rem;
}

.ds-grid .ds-label {
    font-size: 0.75rem;
    line-height: 1.2;
}

.ds-section-title {
    display: block;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--bs-secondary-color);
}

.ds-progress-text {
    font-size: 0.8125rem;
    font-weight: 600;
    text-align: right;
}

.ds-progress {
    height: 0.5rem;
    background: var(--bs-secondary-bg);
}

.ds-progress--attended {
    background-color: #2e7d32;
}

.ds-progress--waiting {
    background-color: #f59e0b;
}

.ds-progress--upcoming {
    background-color: #93c5fd;
}

.ds-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem 0.875rem;
    list-style: none;
    margin: 0.5rem 0 0;
    padding: 0;
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
}

.ds-legend li::before {
    content: '';
    display: inline-block;
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    margin-right: 0.35rem;
    vertical-align: 0.05rem;
}

.ds-legend strong {
    color: var(--bs-body-color);
    font-weight: 600;
}

.ds-legend--attended::before {
    background: #2e7d32;
}

.ds-legend--waiting::before {
    background: #f59e0b;
}

.ds-legend--upcoming::before {
    background: #93c5fd;
}

.ds-note {
    margin: 0.5rem 0 0;
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
}

/* Fundo pela variável do Bootstrap 5.3 (célula pinta --bs-table-bg). */
.ds-shifts {
    --bs-table-bg: transparent;
}

.ds-shifts th,
.ds-shifts td {
    font-size: 0.8125rem;
    white-space: nowrap;
}

.ds-shifts tr.ds-shift--now th,
.ds-shifts tr.ds-shift--now td {
    --bs-table-bg: rgba(var(--bs-primary-rgb), 0.08);
}

/* O template pinta toda tabela no escuro (preclinic-style.css) — aqui segue o card. */
[data-bs-theme='dark'] .ds-shifts,
[data-bs-theme='dark'] .ds-shifts th,
[data-bs-theme='dark'] .ds-shifts td {
    background-color: transparent;
}

[data-bs-theme='dark'] .ds-shifts tr.ds-shift--now th,
[data-bs-theme='dark'] .ds-shifts tr.ds-shift--now td {
    background-color: rgba(96, 165, 250, 0.14);
}

[data-bs-theme='dark'] .ds-progress--upcoming,
[data-bs-theme='dark'] .ds-legend--upcoming::before {
    background-color: #3b5b8a;
}
</style>
