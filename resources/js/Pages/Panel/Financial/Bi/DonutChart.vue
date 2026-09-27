<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { Chart, DoughnutController, ArcElement, Tooltip } from 'chart.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans.js';
import { chartChrome, observeTheme, prefersReducedMotion, toneColor, tooltipTheme } from './chartTheme.js';

Chart.register(DoughnutController, ArcElement, Tooltip);

/**
 * Rosca com a composição de um total (no BI: situação dos agendamentos).
 *
 * - Cada fatia tem um `tone` do Bootstrap (success, danger…): a cor do canvas
 *   vem de --bs-<tone> e a legenda HTML usa a mesma classe bg-<tone> — os dois
 *   acompanham o tema claro/escuro.
 * - Acessível: canvas role="img" + aria-label com todas as fatias, legenda em
 *   texto e "Ver dados" com a tabela (quantidade e participação).
 */
const props = defineProps({
    /** [{ key, label, value, tone }] */
    slices:     { type: Array,  default: () => [] },
    /** Texto abaixo do total, no centro (ex.: "agendamentos"). */
    totalLabel: { type: String, default: '' },
    t:          { type: Object, default: () => ({}) },
    height:     { type: Number, default: 200 },
});

const { tx } = useTrans(() => props.t);
const { locale, number } = useLocaleFormat();

const tableId  = `bi-donut-data-${useId()}`;
const canvas   = ref(null);
const showData = ref(false);

let chart = null;
let stopObservingTheme = () => {};

const values = computed(() => props.slices.map((slice) => Math.max(0, Number(slice.value) || 0)));
const total  = computed(() => values.value.reduce((sum, value) => sum + value, 0));

function share(value) {
    return total.value > 0 ? value / total.value : 0;
}

function percent(fraction) {
    return new Intl.NumberFormat(locale.value, { style: 'percent', maximumFractionDigits: 1 }).format(fraction);
}

const rows = computed(() => props.slices.map((slice, index) => ({
    key:   slice.key,
    label: slice.label,
    tone:  slice.tone,
    value: values.value[index],
    share: percent(share(values.value[index])),
})));

const ariaLabel = computed(() => tx('schedule_chart_aria', {
    total: number(total.value),
    items: rows.value.map((row) => `${row.label}: ${number(row.value)} (${row.share})`).join('; '),
}));

function destroyChart() {
    chart?.destroy();
    chart = null;
}

function buildChart() {
    if (!canvas.value) return;
    destroyChart();

    const el     = canvas.value;
    const chrome = chartChrome(el);

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: { display: false }, // legenda em HTML (texto real, acessível)
            tooltip: {
                ...tooltipTheme(chrome),
                callbacks: { label: (ctx) => ` ${ctx.label}: ${number(ctx.parsed)} (${percent(share(ctx.parsed))})` },
            },
        },
    };

    if (prefersReducedMotion()) options.animation = false;

    chart = new Chart(el, {
        type: 'doughnut',
        data: {
            labels: props.slices.map((slice) => slice.label),
            datasets: [{
                data:            values.value,
                backgroundColor: props.slices.map((slice) => toneColor(el, slice.tone)),
                borderColor:     chrome.surface,
                borderWidth:     2,
                hoverOffset:     4,
            }],
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

watch([() => props.slices, () => props.t, locale], buildChart, { deep: true });
</script>

<template>
    <div class="bi-donut-chart">
        <div class="position-relative mx-auto bi-donut" :style="{ height: `${height}px` }">
            <canvas ref="canvas" role="img" :aria-label="ariaLabel" data-test="donut-chart"></canvas>
            <div class="bi-donut__center" aria-hidden="true">
                <span class="d-block fs-4 fw-bold text-body lh-1 bi-num">{{ number(total) }}</span>
                <span v-if="totalLabel" class="d-block small text-body-secondary mt-1">{{ totalLabel }}</span>
            </div>
        </div>

        <ul class="list-unstyled mb-0 mt-3 small" data-test="donut-legend">
            <li v-for="row in rows" :key="row.key" class="d-flex align-items-center gap-2 py-1" :data-slice="row.key">
                <span class="bi-swatch rounded-circle flex-shrink-0" :class="`bg-${row.tone}`" aria-hidden="true"></span>
                <span class="me-auto">{{ row.label }}</span>
                <span class="fw-semibold text-body bi-num">{{ number(row.value) }}</span>
            </li>
        </ul>

        <button
            type="button"
            class="btn btn-link btn-sm px-0 mt-2 bi-data-toggle"
            :aria-expanded="showData ? 'true' : 'false'"
            :aria-controls="tableId"
            data-test="donut-toggle-data"
            @click="showData = !showData"
        >
            <i class="ti me-1" :class="showData ? 'ti-chevron-up' : 'ti-table'" aria-hidden="true"></i>{{ showData ? tx('hide_data') : tx('see_data') }}
        </button>

        <div v-show="showData" :id="tableId" class="table-responsive mt-2" data-test="donut-data">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden">{{ tx('schedule_mix') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ tx('col_status') }}</th>
                        <th scope="col" class="text-end">{{ tx('col_quantity') }}</th>
                        <th scope="col" class="text-end">{{ tx('col_share') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key" data-test="donut-row">
                        <th scope="row" class="fw-medium">{{ row.label }}</th>
                        <td class="text-end bi-num">{{ number(row.value) }}</td>
                        <td class="text-end bi-num">{{ row.share }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">{{ tx('col_total') }}</th>
                        <td class="text-end bi-num fw-semibold">{{ number(total) }}</td>
                        <td class="text-end bi-num">{{ percent(total > 0 ? 1 : 0) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>

<style scoped>
.bi-donut {
    max-width: 240px;
}

.bi-donut__center {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    pointer-events: none;
}

.bi-swatch {
    display: inline-block;
    width: 0.625rem;
    height: 0.625rem;
}

.bi-num {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.bi-data-toggle:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
}
</style>
