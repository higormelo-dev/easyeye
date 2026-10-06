<script setup>
import { computed } from 'vue';
import TrendBarChart from '@/Pages/Panel/Financial/Bi/TrendBarChart.vue';
import DailyScheduleChart from './DailyScheduleChart.vue';
import CovenantBilling from './CovenantBilling.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Tendências (gestão), fora do polling:
 *  - administração: consultas × faltas por dia (30 dias) e, com acesso ao
 *    financeiro, receita × despesa dos últimos 6 meses;
 *  - financeiro: receita × despesa (6 meses) e faturado × recebido do mês por
 *    convênio.
 * O gráfico de 6 meses é o mesmo do BI (TrendBarChart) — mesmos números.
 */
const props = defineProps({
    profile: { type: String, default: 'admin' },
    insights: { type: Object, default: null },
    canOpenBi: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const { locale } = useLocaleFormat();

/** "2026-09" → "set. de 26" (curto: o gráfico divide a linha com outro card). */
function monthLabel(row) {
    const match = /^(\d{4})-(\d{2})$/.exec(String(row?.month ?? ''));
    if (!match) return row?.period ?? '—';

    return new Intl.DateTimeFormat(locale.value, { month: 'short', year: '2-digit' }).format(
        new Date(Number(match[1]), Number(match[2]) - 1, 1),
    );
}

const trendRows = computed(() =>
    (props.insights?.trend?.series ?? []).map((row) => ({
        key: row.month ?? row.period,
        label: monthLabel(row),
        income: Number(row.income) || 0,
        expense: Number(row.expense) || 0,
        balance: Math.round(((Number(row.income) || 0) - (Number(row.expense) || 0)) * 100) / 100,
    })),
);

const days = computed(() => props.insights?.daily?.days ?? []);
const covenants = computed(() => props.insights?.month?.covenants ?? []);
const monthTotals = computed(() => ({
    billed: props.insights?.month?.current?.billed ?? 0,
    paid: props.insights?.month?.current?.paid ?? 0,
}));

const hasFinance = computed(() => !!props.insights?.finance && trendRows.value.length > 0);
const isAdmin = computed(() => props.profile === 'admin');

// Textos do gráfico do BI (TrendBarChart lê estas chaves).
const chartT = computed(() => props.t.fin_chart ?? {});
const biUrl = computed(() => (props.canOpenBi ? route('panel.financial.bi.index') : null));
</script>

<template>
    <section class="db-section" data-tour="dashboard-trends" :aria-label="t.section_trends">
        <div class="db-split db-split--trends" :class="{ 'db-split--single': isAdmin && !hasFinance }">
            <article v-if="isAdmin" class="card db-card">
                <div class="db-card-header">
                    <h3 class="db-card-title">
                        <i class="ti ti-chart-bar" aria-hidden="true"></i>
                        {{ t.trend_daily_title }}
                    </h3>
                    <span class="db-card-meta">{{ t.trend_daily_sub }}</span>
                </div>
                <div class="db-card-body">
                    <div v-if="loading" class="db-chart-skeleton" aria-hidden="true"></div>
                    <DailyScheduleChart v-else :days="days" :t="t" />
                </div>
            </article>

            <article v-if="hasFinance" class="card db-card">
                <div class="db-card-header">
                    <h3 class="db-card-title">
                        <i class="ti ti-trending-up" aria-hidden="true"></i>
                        {{ t.trend_finance_title }}
                    </h3>
                    <a v-if="biUrl" :href="biUrl" class="db-link">
                        {{ t.action_open_bi }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
                <div class="db-card-body">
                    <div v-if="loading" class="db-chart-skeleton" aria-hidden="true"></div>
                    <TrendBarChart v-else :rows="trendRows" :t="chartT" :height="isAdmin ? 240 : 260" />
                </div>
            </article>

            <article v-if="!isAdmin" class="card db-card">
                <div class="db-card-header">
                    <h3 class="db-card-title">
                        <i class="ti ti-building-bank" aria-hidden="true"></i>
                        {{ t.covenants_title }}
                    </h3>
                    <span class="db-card-meta">{{ t.covenants_sub }}</span>
                </div>
                <div class="db-card-body">
                    <div v-if="loading" class="db-chart-skeleton" aria-hidden="true"></div>
                    <CovenantBilling v-else :rows="covenants" :totals="monthTotals" :t="t" />
                </div>
            </article>
        </div>
    </section>
</template>
