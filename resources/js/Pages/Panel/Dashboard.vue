<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout        from '@/Layouts/AppLayout.vue';
import WelcomeBanner    from './Dashboard/WelcomeBanner.vue';
import Activation       from './Dashboard/Activation.vue';
import KpiCards         from './Dashboard/KpiCards.vue';
import ModuleShortcuts  from './Dashboard/ModuleShortcuts.vue';
import ScheduleToday    from './Dashboard/ScheduleToday.vue';
import DaySummary       from './Dashboard/DaySummary.vue';
import RecentPatients   from './Dashboard/RecentPatients.vue';
import LiveStatusBar    from '@/Components/Panel/LiveStatusBar.vue';
import { useDashboardPolling } from '@/composables/useDashboardPolling.js';

const props = defineProps({
    stats:           { type: Object, required: true },
    scheduleToday:   { type: Array,  default: () => [] },
    recentPatients:  { type: Array,  default: () => [] },
    activation:      { type: Array,  default: () => [] },
    activationScore: { type: Number, default: 0 },
    t:               { type: Object, default: () => ({}) },
});

const page   = usePage();
const entity = computed(() => page.props.auth?.entity ?? {});
const rule   = computed(() => entity.value.rule ?? '');
const isDoctor = computed(() => rule.value === 'doctor');

// BUGFIX: o card "Configure sua clínica" ficava travado pra sempre em
// clínicas que nunca convidam um 2º usuário (dono solo) ou nunca conectam
// um integrador de API (feature opcional) — activationScore nunca batia
// 100 pra elas mesmo com a clínica 100% operacional. Card some quando as
// etapas OBRIGATÓRIAS (activation[].required) estiverem concluídas; as
// opcionais continuam contando ponto no score, só não travam mais o card.
const activationComplete = computed(() => (
    props.activation.every((step) => !step.required || step.done)
));

// Polling: atualiza dados clínicos a cada 30s via partial reload Inertia
// ('activation'/'activationScore' inclusos pra o card "Configure sua
// clínica" sumir sozinho assim que a última etapa obrigatória é concluída,
// sem exigir reload manual da página).
const { isRefreshing, lastUpdated, refresh } = useDashboardPolling(
    ['stats', 'scheduleToday', 'recentPatients', 'activation', 'activationScore'],
    30_000,
);

const breadcrumbs = [];
</script>

<template>
    <AppLayout title="Dashboard" :breadcrumbs="breadcrumbs">
        <div class="page-dashboard">

            <!-- ── Live status bar ── -->
            <LiveStatusBar
                :is-refreshing="isRefreshing"
                :last-updated="lastUpdated"
                :t="t"
                @refresh="refresh"
            />

            <!-- ── Welcome Banner ── -->
            <WelcomeBanner :t="t" />

            <!-- ── Activation progress (only when etapas obrigatórias pendentes) ── -->
            <Activation
                v-if="!activationComplete"
                :activation="activation"
                :activation-score="activationScore"
                :t="t"
            />

            <!-- ── KPI Cards ── -->
            <KpiCards
                :stats="stats"
                :is-doctor="isDoctor"
                :rule="rule"
                :is-refreshing="isRefreshing"
                :t="t"
            />

            <!-- ── Module Shortcuts ── -->
            <ModuleShortcuts :rule="rule" :t="t" />

            <!-- ── Schedule Today + Day Summary ── -->
            <div class="row g-3 mb-4">
                <div class="col-lg-8">
                    <ScheduleToday
                        :items="scheduleToday"
                        :is-refreshing="isRefreshing"
                        :t="t"
                    />
                </div>
                <div class="col-lg-4">
                    <DaySummary
                        :stats="stats"
                        :is-refreshing="isRefreshing"
                        :t="t"
                    />
                </div>
            </div>

            <!-- ── Recent Patients ── -->
            <RecentPatients
                :patients="recentPatients"
                :t="t"
            />

        </div>
    </AppLayout>
</template>

<style>
@import '../../../css/dashboard.css';

/* ── KPI card refresh skeleton ────────────────────────────────────────── */
.stat-skeleton {
    display: inline-block;
    width: 3.5rem;
    height: 1.75rem;
    border-radius: .375rem;
    background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
    background-size: 200% 100%;
    animation: dbShimmer 1.2s ease-in-out infinite;
}

@keyframes dbShimmer {
    from { background-position: 200% 0; }
    to   { background-position: -200% 0; }
}

/* ── Active schedule row highlight ───────────────────────────────────── */
.schedule-row--active {
    border-left: 3px solid #1976d2;
}

:root[data-bs-theme=dark] .stat-skeleton {
    background: linear-gradient(90deg, #1e2d42 25%, #253651 50%, #1e2d42 75%);
    background-size: 200% 100%;
}

:root[data-bs-theme=dark] .schedule-row--active {
    border-left-color: #60a5fa;
}
</style>
