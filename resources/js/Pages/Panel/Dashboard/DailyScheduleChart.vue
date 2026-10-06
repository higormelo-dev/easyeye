<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { Chart, BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip } from 'chart.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import {
    chartChrome,
    observeTheme,
    prefersReducedMotion,
    toneColor,
    tooltipTheme,
} from '@/Pages/Panel/Financial/Bi/chartTheme.js';
import { parseDate } from './trend.js';

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Legend, Tooltip);

/**
 * Consultas atendidas × faltas por dia (últimos 30 dias) — barras empilhadas.
 * Mesmo padrão dos gráficos do BI: cores das variáveis do tema (refeito ao
 * trocar claro/escuro), sem animação com "reduzir movimento", destroy() ao
 * desmontar e resumo textual (aria-label + "Ver dados" com a tabela).
 */
const props = defineProps({
    /** [{ date: 'YYYY-MM-DD', attended, noshow, cancelled, total }] */
    days: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
    height: { type: Number, default: 240 },
});

const { locale, number } = useLocaleFormat();
const tx = (key, params = {}) =>
    Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), props.t[key] ?? '');

const tableId = `db-daily-data-${useId()}`;
const canvas = ref(null);
const showData = ref(false);

let chart = null;
let stopObservingTheme = () => {};

const label = (date, options) => {
    const parsed = parseDate(date);

    return parsed ? new Intl.DateTimeFormat(locale.value, options).format(parsed) : date;
};

const rows = computed(() =>
    props.days.map((day) => ({
        ...day,
        short: label(day.date, { day: '2-digit', month: '2-digit' }),
        long: label(day.date, { weekday: 'short', day: 'numeric', month: 'short' }),
    })),
);

const totals = computed(() => {
    const attended = props.days.reduce((sum, d) => sum + (Number(d.attended) || 0), 0);
    const noshow = props.days.reduce((sum, d) => sum + (Number(d.noshow) || 0), 0);
    const base = attended + noshow;

    return { attended, noshow, rate: base > 0 ? (noshow / base) * 100 : 0 };
});

const summary = computed(() =>
    tx('daily_chart_aria', {
        days: number(props.days.length),
        attended: number(totals.value.attended),
        noshow: number(totals.value.noshow),
        rate: number(totals.value.rate, 1),
    }),
);

function destroyChart() {
    chart?.destroy();
    chart = null;
}

function buildChart() {
    if (!canvas.value) return;
    destroyChart();

    const el = canvas.value;
    const chrome = chartChrome(el);

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'bottom', labels: { color: chrome.body, usePointStyle: true, boxWidth: 8 } },
            tooltip: {
                ...tooltipTheme(chrome),
                callbacks: {
                    title: (items) => rows.value[items[0]?.dataIndex]?.long ?? '',
                    label: (ctx) => ` ${ctx.dataset.label}: ${number(ctx.parsed.y)}`,
                },
            },
        },
        scales: {
            x: {
                stacked: true,
                grid: { display: false },
                border: { color: chrome.grid },
                ticks: { color: chrome.text, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 },
            },
            y: {
                stacked: true,
                beginAtZero: true,
                grid: { color: chrome.grid },
                border: { display: false },
                ticks: { color: chrome.text, precision: 0 },
            },
        },
    };

    if (prefersReducedMotion()) options.animation = false;

    chart = new Chart(el, {
        type: 'bar',
        data: {
            labels: rows.value.map((row) => row.short),
            datasets: [
                {
                    key: 'attended',
                    label: props.t.daily_attended,
                    data: props.days.map((d) => Number(d.attended) || 0),
                    backgroundColor: toneColor(el, 'success'),
                    borderRadius: 3,
                    maxBarThickness: 22,
                },
                {
                    key: 'noshow',
                    label: props.t.daily_noshow,
                    data: props.days.map((d) => Number(d.noshow) || 0),
                    backgroundColor: toneColor(el, 'danger'),
                    borderRadius: 3,
                    maxBarThickness: 22,
                },
            ],
        },
        options,
    });
}

onMounted(() => {
    buildChart();
    stopObservingTheme = observeTheme(buildChart);
});

onBeforeUnmount(() => {
    stopObservingTheme();
    destroyChart();
});

watch([() => props.days, () => props.t, locale], buildChart, { deep: true });
</script>

<template>
    <div class="db-chart">
        <div class="db-chart__totals">
            <span><strong>{{ number(totals.attended) }}</strong> {{ t.daily_attended }}</span>
            <span><strong>{{ number(totals.noshow) }}</strong> {{ t.daily_noshow }}</span>
            <span>{{ tx('daily_rate', { rate: number(totals.rate, 1) }) }}</span>
        </div>
        <div class="position-relative" :style="{ height: `${height}px` }">
            <canvas ref="canvas" role="img" :aria-label="summary" data-test="daily-chart"></canvas>
        </div>

        <button
            type="button"
            class="btn btn-link btn-sm px-0 mt-1 db-data-toggle"
            :aria-expanded="showData ? 'true' : 'false'"
            :aria-controls="tableId"
            data-test="daily-toggle-data"
            @click="showData = !showData"
        >
            <i class="ti me-1" :class="showData ? 'ti-chevron-up' : 'ti-table'" aria-hidden="true"></i
            >{{ showData ? t.hide_data : t.see_data }}
        </button>

        <div v-show="showData" :id="tableId" class="table-responsive mt-2 db-chart__table" data-test="daily-data">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden">{{ t.trend_daily_title }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ t.col_day }}</th>
                        <th scope="col" class="text-end">{{ t.daily_attended }}</th>
                        <th scope="col" class="text-end">{{ t.daily_noshow }}</th>
                        <th scope="col" class="text-end">{{ t.daily_cancelled }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.date">
                        <th scope="row" class="fw-medium">{{ row.long }}</th>
                        <td class="text-end">{{ number(row.attended) }}</td>
                        <td class="text-end">{{ number(row.noshow) }}</td>
                        <td class="text-end">{{ number(row.cancelled) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
