<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import {
    Chart,
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Legend,
    Tooltip,
} from 'chart.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans.js';
import {
    chartChrome,
    observeTheme,
    prefersReducedMotion,
    toneColor,
    tooltipTheme,
} from '@/Pages/Panel/Financial/Bi/chartTheme.js';

Chart.register(
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Legend,
    Tooltip,
);

/**
 * Custo de IA (barras, R$) e execuções (linha, eixo à direita) por dia ou
 * mês. Mesmo padrão do BI financeiro: cores do tema (refeito ao trocar
 * claro/escuro), canvas com role="img" + aria-label com o resumo, e "Ver
 * dados" abre a tabela com os mesmos números.
 */
const props = defineProps({
    /** [{ key: 'YYYY-MM-DD' | 'YYYY-MM', runs, failed, cost_brl }] */
    points: { type: Array, default: () => [] },
    granularity: { type: String, default: 'day' },
    t: { type: Object, default: () => ({}) },
    height: { type: Number, default: 260 },
});

const { tx } = useTrans(() => props.t);
const { locale, money, number } = useLocaleFormat();

const tableId = `ai-usage-trend-${useId()}`;
const canvas = ref(null);
const showData = ref(false);

let chart = null;
let stopObservingTheme = () => {};

function label(key) {
    const [year, month, day] = String(key).split('-').map(Number);
    const value = new Date(year, (month || 1) - 1, day || 1);
    const options =
        props.granularity === 'month' ? { month: 'short', year: '2-digit' } : { day: '2-digit', month: '2-digit' };

    return new Intl.DateTimeFormat(locale.value, options).format(value);
}

const rows = computed(() => props.points.map((point) => ({ ...point, label: label(point.key) })));

const totals = computed(() => ({
    cost: rows.value.reduce((sum, row) => sum + (Number(row.cost_brl) || 0), 0),
    runs: rows.value.reduce((sum, row) => sum + (Number(row.runs) || 0), 0),
}));

const ariaLabel = computed(() =>
    tx('aria', {
        from: rows.value[0]?.label ?? '',
        to: rows.value.at(-1)?.label ?? '',
        cost: money(totals.value.cost),
        runs: number(totals.value.runs),
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
    const runsColor = toneColor(el, 'info');
    const compact = new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency: 'BRL',
        notation: 'compact',
        maximumFractionDigits: 1,
    });

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'bottom', labels: { color: chrome.body, usePointStyle: true, boxWidth: 8 } },
            tooltip: {
                ...tooltipTheme(chrome),
                callbacks: {
                    label: (ctx) =>
                        ` ${ctx.dataset.label}: ${ctx.dataset.key === 'cost' ? money(ctx.parsed.y) : number(ctx.parsed.y)}`,
                },
            },
        },
        scales: {
            x: { grid: { display: false }, border: { color: chrome.grid }, ticks: { color: chrome.text } },
            y: {
                beginAtZero: true,
                grid: { color: chrome.grid },
                border: { display: false },
                ticks: { color: chrome.text, callback: (value) => compact.format(value) },
            },
            y1: {
                beginAtZero: true,
                position: 'right',
                grid: { display: false },
                border: { display: false },
                ticks: { color: chrome.text, precision: 0 },
            },
        },
    };

    if (prefersReducedMotion()) options.animation = false;

    chart = new Chart(el, {
        type: 'bar',
        data: {
            labels: rows.value.map((row) => row.label),
            datasets: [
                {
                    type: 'bar',
                    key: 'cost',
                    label: tx('cost'),
                    order: 2,
                    yAxisID: 'y',
                    data: rows.value.map((row) => Number(row.cost_brl) || 0),
                    backgroundColor: toneColor(el, 'primary'),
                    borderRadius: 4,
                    maxBarThickness: 28,
                },
                {
                    type: 'line',
                    key: 'runs',
                    label: tx('runs'),
                    order: 1,
                    yAxisID: 'y1',
                    data: rows.value.map((row) => Number(row.runs) || 0),
                    borderColor: runsColor,
                    backgroundColor: runsColor,
                    borderWidth: 2,
                    tension: 0.3,
                    pointBackgroundColor: chrome.surface,
                    pointBorderColor: runsColor,
                    pointBorderWidth: 2,
                    pointRadius: rows.value.length > 40 ? 0 : 3,
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

watch([() => props.points, () => props.granularity, () => props.t, locale], buildChart, { deep: true });
</script>

<template>
    <div class="ai-usage-trend">
        <div class="position-relative" :style="{ height: `${height}px` }">
            <canvas ref="canvas" role="img" :aria-label="ariaLabel" data-test="usage-chart"></canvas>
            <div
                v-if="totals.runs === 0"
                class="position-absolute top-50 start-50 translate-middle text-muted small text-center"
                data-test="usage-chart-empty"
            >
                {{ tx('empty') }}
            </div>
        </div>

        <button
            type="button"
            class="btn btn-link btn-sm px-0 mt-2"
            :aria-expanded="showData ? 'true' : 'false'"
            :aria-controls="tableId"
            data-test="usage-toggle-data"
            @click="showData = !showData"
        >
            <i class="ti me-1" :class="showData ? 'ti-chevron-up' : 'ti-table'" aria-hidden="true"></i
            >{{ showData ? tx('hide_data') : tx('see_data') }}
        </button>

        <div v-show="showData" :id="tableId" class="table-responsive mt-2" data-test="usage-data">
            <table class="table table-sm table-hover align-middle mb-0">
                <caption class="visually-hidden">
                    {{
                        granularity === 'month' ? tx('title_month') : tx('title_day')
                    }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">{{ tx('col_period') }}</th>
                        <th scope="col" class="text-end">{{ tx('runs') }}</th>
                        <th scope="col" class="text-end">{{ tx('failed') }}</th>
                        <th scope="col" class="text-end">{{ tx('cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key" data-test="usage-row">
                        <th scope="row" class="fw-medium">{{ row.label }}</th>
                        <td class="text-end au-num">{{ number(row.runs) }}</td>
                        <td class="text-end au-num">{{ number(row.failed) }}</td>
                        <td class="text-end au-num">{{ money(row.cost_brl) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
.au-num {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
</style>
