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
import { chartChrome, observeTheme, prefersReducedMotion, toneColor, tooltipTheme } from './chartTheme.js';

Chart.register(BarController, BarElement, LineController, LineElement, PointElement, CategoryScale, LinearScale, Legend, Tooltip);

/**
 * Receita × despesa por mês (barras) e saldo (linha) do BI financeiro.
 *
 * - Cores do tema (--bs-success/--bs-danger/--bs-primary), refeito ao trocar
 *   claro/escuro; chart.destroy() ao desmontar (padrão do MrrTrendChart).
 * - Acessível: canvas com role="img" + aria-label com o resumo do período e
 *   "Ver dados" abre a tabela com os mesmos números.
 */
const props = defineProps({
    /** [{ key, label, income, expense, balance }] — `label` já no idioma do usuário. */
    rows:   { type: Array,  default: () => [] },
    t:      { type: Object, default: () => ({}) },
    height: { type: Number, default: 260 },
});

const { tx } = useTrans(() => props.t);
const { locale, money, signedMoney } = useLocaleFormat();

const tableId  = `bi-trend-data-${useId()}`;
const canvas   = ref(null);
const showData = ref(false);

let chart = null;
let stopObservingTheme = () => {};

const round2 = (value) => Math.round(value * 100) / 100;

const totals = computed(() => {
    const income  = props.rows.reduce((sum, row) => sum + (Number(row.income) || 0), 0);
    const expense = props.rows.reduce((sum, row) => sum + (Number(row.expense) || 0), 0);

    return { income: round2(income), expense: round2(expense), balance: round2(income - expense) };
});

const ariaLabel = computed(() => tx('trend_chart_aria', {
    from:    props.rows[0]?.label ?? '',
    to:      props.rows.at(-1)?.label ?? '',
    income:  money(totals.value.income),
    expense: money(totals.value.expense),
    balance: signedMoney(totals.value.balance),
}));

function formatValue(key, value) {
    return key === 'balance' ? signedMoney(value) : money(value);
}

function destroyChart() {
    chart?.destroy();
    chart = null;
}

function buildChart() {
    if (!canvas.value) return;
    destroyChart();

    const el      = canvas.value;
    const chrome  = chartChrome(el);
    const primary = toneColor(el, 'primary');
    const compact = new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 });
    const byIndex = (a, b) => a.datasetIndex - b.datasetIndex;

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            // `order` desenha a linha por cima das barras; legenda e tooltip seguem a ordem natural.
            legend: { position: 'bottom', labels: { color: chrome.body, usePointStyle: true, boxWidth: 8, sort: byIndex } },
            tooltip: {
                ...tooltipTheme(chrome),
                itemSort: byIndex,
                callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${formatValue(ctx.dataset.key, ctx.parsed.y)}` },
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
        },
    };

    if (prefersReducedMotion()) options.animation = false;

    chart = new Chart(el, {
        type: 'bar',
        data: {
            labels: props.rows.map((row) => row.label),
            datasets: [
                {
                    type: 'bar', key: 'income', label: tx('col_income'), order: 2,
                    data: props.rows.map((row) => Number(row.income) || 0),
                    backgroundColor: toneColor(el, 'success'), borderRadius: 4, maxBarThickness: 28,
                },
                {
                    type: 'bar', key: 'expense', label: tx('col_expense'), order: 2,
                    data: props.rows.map((row) => Number(row.expense) || 0),
                    backgroundColor: toneColor(el, 'danger'), borderRadius: 4, maxBarThickness: 28,
                },
                {
                    type: 'line', key: 'balance', label: tx('col_balance'), order: 1,
                    data: props.rows.map((row) => Number(row.balance) || 0),
                    borderColor: primary, backgroundColor: primary, borderWidth: 2, tension: 0.3,
                    pointBackgroundColor: chrome.surface, pointBorderColor: primary, pointBorderWidth: 2, pointRadius: 3,
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

watch([() => props.rows, () => props.t, locale], buildChart, { deep: true });
</script>

<template>
    <div class="bi-trend-chart">
        <div class="position-relative" :style="{ height: `${height}px` }">
            <canvas ref="canvas" role="img" :aria-label="ariaLabel" data-test="trend-chart"></canvas>
        </div>

        <button
            type="button"
            class="btn btn-link btn-sm px-0 mt-2 bi-data-toggle"
            :aria-expanded="showData ? 'true' : 'false'"
            :aria-controls="tableId"
            data-test="trend-toggle-data"
            @click="showData = !showData"
        >
            <i class="ti me-1" :class="showData ? 'ti-chevron-up' : 'ti-table'" aria-hidden="true"></i>{{ showData ? tx('hide_data') : tx('see_data') }}
        </button>

        <div v-show="showData" :id="tableId" class="table-responsive mt-2" data-test="trend-data">
            <table class="table table-sm table-hover align-middle mb-0">
                <caption class="visually-hidden">{{ tx('monthly_trend') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ tx('col_month') }}</th>
                        <th scope="col" class="text-end">{{ tx('col_income') }}</th>
                        <th scope="col" class="text-end">{{ tx('col_expense') }}</th>
                        <th scope="col" class="text-end">{{ tx('col_balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key" data-test="trend-row">
                        <th scope="row" class="fw-medium text-capitalize">{{ row.label }}</th>
                        <td class="text-end bi-num">{{ money(row.income) }}</td>
                        <td class="text-end bi-num">{{ money(row.expense) }}</td>
                        <td class="text-end bi-num fw-semibold text-body">{{ signedMoney(row.balance) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
.bi-num {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.bi-data-toggle:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
}
</style>
