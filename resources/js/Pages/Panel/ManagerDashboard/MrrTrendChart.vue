<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Chart, LineElement, PointElement, LineController, CategoryScale, LinearScale, Filler, Tooltip } from 'chart.js';

Chart.register(LineElement, PointElement, LineController, CategoryScale, LinearScale, Filler, Tooltip);

const props = defineProps({
    mrrTrend:      { type: Object, required: true },
    financialKpis: { type: Object, required: true },
    t:             { type: Object, required: true },
});

const canvas  = ref(null);
let chartInst = null;

function isDark() {
    return document.documentElement.getAttribute('data-bs-theme') === 'dark';
}

function palette() {
    return isDark()
        ? { line: '#5da6ff', bg: 'rgba(93,166,255,0.12)', grid: '#22334c', text: '#9fb0cc', point: '#111b30' }
        : { line: '#1976d2', bg: 'rgba(25,118,210,0.10)', grid: '#e2e8f0', text: '#64748b', point: '#ffffff' };
}

function buildChart() {
    if (!canvas.value) return;
    if (chartInst) { chartInst.destroy(); chartInst = null; }

    const p = palette();

    chartInst = new Chart(canvas.value, {
        type: 'line',
        data: {
            labels: props.mrrTrend.labels,
            datasets: [{
                label: props.t.chart_mrr_label,
                data: props.mrrTrend.values,
                borderColor: p.line,
                backgroundColor: p.bg,
                borderWidth: 2.5,
                pointBackgroundColor: p.line,
                pointBorderColor: p.point,
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: isDark() ? '#111b30' : '#ffffff',
                    titleColor: isDark() ? '#e4ebfa' : '#1e293b',
                    bodyColor: isDark() ? '#9fb0cc' : '#64748b',
                    borderColor: p.grid,
                    borderWidth: 1,
                    callbacks: {
                        label: ctx => ' R$ ' + ctx.parsed.y.toLocaleString('pt-BR', { minimumFractionDigits: 0 }),
                    },
                },
            },
            scales: {
                x: {
                    grid: { color: p.grid },
                    ticks: { color: p.text, font: { size: 11 } },
                },
                y: {
                    grid: { color: p.grid },
                    ticks: {
                        color: p.text,
                        font: { size: 11 },
                        callback: v => v >= 1000 ? 'R$ ' + (v / 1000).toFixed(0) + 'k' : 'R$ ' + v,
                    },
                    beginAtZero: true,
                },
            },
        },
    });
}

let themeObserver = null;

onMounted(() => {
    buildChart();
    themeObserver = new MutationObserver(() => buildChart());
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
});

onUnmounted(() => {
    chartInst?.destroy();
    themeObserver?.disconnect();
});

watch(() => props.mrrTrend, buildChart, { deep: true });
</script>

<template>
    <div class="card mgr-chart-card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="ti ti-currency-real me-2"></i>{{ t.chart_mrr_trend }}</span>
            <span class="badge badge-soft-success fw-medium border py-1 px-2 border-success fs-13">
                R$ {{ Number(financialKpis.mrr).toLocaleString('pt-BR', { minimumFractionDigits: 0 }) }} / mês
            </span>
        </div>
        <div class="card-body">
            <canvas ref="canvas" style="height:240px;max-height:240px;"></canvas>
        </div>
    </div>
</template>
