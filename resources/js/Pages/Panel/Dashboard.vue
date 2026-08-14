<script setup>
import { computed, ref } from 'vue';
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
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ColumnOrderMenu  from '@/Components/Panel/ColumnOrderMenu.vue';
import { useDashboardPolling } from '@/composables/useDashboardPolling.js';
import { useUserPreferences }  from '@/composables/useUserPreferences.js';

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

// ── Personalização: ordem das seções (item MELHORIA "mais humano") ──────────
// LiveStatusBar/WelcomeBanner/Activation ficam fixos (avisos/contexto, não
// "conteúdo" reordenável). Agenda de hoje + Resumo do dia contam como UMA
// seção — são desenhadas lado a lado de propósito, não faz sentido separar.
const SECTION_DEFS = [
    { key: 'kpis',      label: props.t.section_kpis ?? 'Indicadores' },
    { key: 'shortcuts', label: props.t.section_shortcuts ?? 'Atalhos' },
    { key: 'agenda',    label: props.t.section_agenda ?? 'Agenda de hoje' },
    { key: 'patients',  label: props.t.section_patients ?? 'Pacientes recentes' },
];
const DEFAULT_SECTION_ORDER = ['kpis', 'shortcuts', 'agenda', 'patients'];

const { getPreference, savePreference } = useUserPreferences();

function loadValidSectionOrder() {
    const stored = getPreference('dashboard_widget_order');
    const isValid = Array.isArray(stored)
        && stored.length === DEFAULT_SECTION_ORDER.length
        && DEFAULT_SECTION_ORDER.every((k) => stored.includes(k));

    return isValid ? [...stored] : [...DEFAULT_SECTION_ORDER];
}

const sectionOrder = ref(loadValidSectionOrder());

const orderedSections = computed(() => (
    sectionOrder.value.map((key) => SECTION_DEFS.find((s) => s.key === key)).filter(Boolean)
));

function moveSection(fromIndex, toIndex) {
    if (fromIndex === toIndex) return;
    if (fromIndex < 0 || toIndex < 0) return;
    if (fromIndex >= sectionOrder.value.length || toIndex >= sectionOrder.value.length) return;

    const next = [...sectionOrder.value];
    const [moved] = next.splice(fromIndex, 1);
    next.splice(toIndex, 0, moved);
    sectionOrder.value = next;
    savePreference('dashboard_widget_order', next);
}

function resetSectionOrder() {
    sectionOrder.value = [...DEFAULT_SECTION_ORDER];
    savePreference('dashboard_widget_order', sectionOrder.value);
}
</script>

<template>
    <AppLayout title="Dashboard" :breadcrumbs="breadcrumbs">
        <div class="page-dashboard">

            <!-- ── Personalizar (item MELHORIA "mais humano") — discreto, canto -->
            <div class="d-flex justify-content-end mb-2">
                <ActionDropdown
                    title="Personalizar Dashboard"
                    align="right"
                    :min-width="230"
                    btn-class="bg-white border shadow-sm rounded px-2 py-1 d-flex align-items-center gap-1 fs-13 text-muted"
                >
                    <template #trigger>
                        <i class="ti ti-layout-dashboard"></i>
                        <span class="d-none d-sm-inline">Personalizar</span>
                    </template>

                    <ColumnOrderMenu
                        title="Ordem das seções"
                        :columns="orderedSections"
                        @move="moveSection"
                        @reset="resetSectionOrder"
                    />
                </ActionDropdown>
            </div>

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

            <!-- ── Seções reordenáveis — ordem vem da preferência do usuário ── -->
            <template v-for="section in orderedSections" :key="section.key">
                <KpiCards
                    v-if="section.key === 'kpis'"
                    :stats="stats"
                    :is-doctor="isDoctor"
                    :rule="rule"
                    :is-refreshing="isRefreshing"
                    :t="t"
                />

                <ModuleShortcuts
                    v-else-if="section.key === 'shortcuts'"
                    :rule="rule"
                    :t="t"
                />

                <div v-else-if="section.key === 'agenda'" class="row g-3 mb-4">
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

                <RecentPatients
                    v-else-if="section.key === 'patients'"
                    :patients="recentPatients"
                    :t="t"
                />
            </template>

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
