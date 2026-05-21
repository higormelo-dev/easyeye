<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Chart, BarElement, BarController, CategoryScale, LinearScale, Tooltip } from 'chart.js';

Chart.register(BarElement, BarController, CategoryScale, LinearScale, Tooltip);

const props = defineProps({
    growthTrend: { type: Object, required: true },
    t:           { type: Object, required: true },
});

const canvas  = ref(null);
let chartInst = null;

function isDark() {
    return document.documentElement.getAttribute('data-bs-theme') === 'dark';
}

function buildChart() {
    if (!canvas.value) return;
    if (chartInst) { chartInst.destroy(); chartInst = null; }

    const dark = isDark();
    const barColor  = dark ? 'rgba(93,166,255,0.75)' : 'rgba(25,118,210,0.75)';
    const lineColor = dark ? '#5da6ff' : '#1976d2';
    const gridColor = dark ? '#22334c' : '#e2e8f0';
    const textColor = dark ? '#9fb0cc' : '#64748b';

    chartInst = new Chart(canvas.value, {
        type: 'bar',
        data: {
            labels: props.growthTrend.labels,
            datasets: [{
                label: props.t.chart_new_entities,
                data: props.growthTrend.values,
                backgroundColor: barColor,
                borderColor: lineColor,
                borderWidth: 1,
                borderRadius: 5,
                borderSkipped: false,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: dark ? '#111b30' : '#ffffff',
                    titleColor: dark ? '#e4ebfa' : '#1e293b',
                    bodyColor: dark ? '#9fb0cc' : '#64748b',
                    borderColor: gridColor,
                    borderWidth: 1,
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: textColor, font: { size: 11 } },
                },
                y: {
                    grid: { color: gridColor },
                    ticks: { color: textColor, font: { size: 11 }, stepSize: 1, precision: 0 },
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

watch(() => props.growthTrend, buildChart, { deep: true });
</script>

<template>
    <div class="card mgr-chart-card h-100">
        <div class="card-header">
            <i class="ti ti-building-plus me-2"></i>{{ t.chart_growth }}
        </div>
        <div class="card-body">
            <canvas ref="canvas" style="height:240px;max-height:240px;"></canvas>
        </div>
    </div>
</template>
