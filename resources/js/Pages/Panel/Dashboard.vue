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
import StockAlerts      from './Dashboard/StockAlerts.vue';
import LiveStatusBar    from '@/Components/Panel/LiveStatusBar.vue';
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ColumnOrderMenu  from '@/Components/Panel/ColumnOrderMenu.vue';
import { useDashboardPolling } from '@/composables/useDashboardPolling.js';
import { useUserPreferences }  from '@/composables/useUserPreferences.js';
import { normalizeSectionOrder, moveVisibleSection } from './Dashboard/sectionOrder.js';

const props = defineProps({
    stats:           { type: Object, required: true },
    scheduleToday:   { type: Array,  default: () => [] },
    recentPatients:  { type: Array,  default: () => [] },
    activation:      { type: Array,  default: () => [] },
    activationScore: { type: Number, default: 0 },
    // GAP fechado (revisão pós-Fase 4 do estoque) — null quando a clínica
    // não usa o módulo OU não tem nada crítico agora (ver
    // PanelDashboardController::buildStockAlerts()).
    stockAlerts:     { type: Object, default: null },
    // Telas que o usuário pode abrir pelos atalhos (mesmas regras das rotas —
    // PanelDashboardController::buildAccess()); sem a permissão, o atalho
    // não vira link para um 403.
    access:          { type: Object, default: () => ({}) },
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
// sem exigir reload manual da página). 'stockAlerts' incluso — saldo pode
// cair abaixo do mínimo durante o expediente (consumo em procedimento).
const { isRefreshing, lastUpdated, refresh } = useDashboardPolling(
    ['stats', 'scheduleToday', 'recentPatients', 'activation', 'activationScore', 'stockAlerts'],
    30_000,
);

const breadcrumbs = [];

// ── Personalização: ordem das seções (item MELHORIA "mais humano") ──────────
// LiveStatusBar/WelcomeBanner/Activation ficam fixos (avisos/contexto, não
// "conteúdo" reordenável). Agenda de hoje + Resumo do dia contam como UMA
// seção — são desenhadas lado a lado de propósito, não faz sentido separar.
// 'stock' está sempre na ordem salva, mas só aparece (no painel e na lista de
// reordenar) quando o Dashboard tem alerta de estoque — que pode surgir ou
// sumir no meio do expediente (polling). A ordem escolhida pelo usuário não
// volta ao padrão por isso (ver sectionOrder.js).
const SECTION_DEFS = [
    { key: 'kpis',      label: props.t.section_kpis ?? 'Indicadores' },
    { key: 'shortcuts', label: props.t.section_shortcuts ?? 'Atalhos' },
    { key: 'agenda',    label: props.t.section_agenda ?? 'Agenda de hoje' },
    { key: 'patients',  label: props.t.section_patients ?? 'Pacientes recentes' },
    { key: 'stock',     label: props.t.section_stock ?? 'Alertas de estoque' },
];
const DEFAULT_SECTION_ORDER = SECTION_DEFS.map((s) => s.key);

// Rótulos traduzidos do menu de ordenar (mostrar/ocultar/mover/restaurar).
const orderLabels = computed(() => ({
    show:     props.t.order_show,
    hide:     props.t.order_hide,
    moveUp:   props.t.order_move_up,
    moveDown: props.t.order_move_down,
    reset:    props.t.order_reset,
}));

const { getPreference, savePreference } = useUserPreferences();

const sectionOrder = ref(normalizeSectionOrder(getPreference('dashboard_widget_order'), DEFAULT_SECTION_ORDER));

const orderedSections = computed(() => (
    sectionOrder.value
        .filter((key) => key !== 'stock' || props.stockAlerts)
        .map((key) => SECTION_DEFS.find((s) => s.key === key))
        .filter(Boolean)
));

function moveSection(fromIndex, toIndex) {
    const visible = orderedSections.value.map((s) => s.key);
    const next = moveVisibleSection(sectionOrder.value, visible, fromIndex, toIndex);
    if (!next) return;

    sectionOrder.value = next;
    savePreference('dashboard_widget_order', next);
}

function resetSectionOrder() {
    sectionOrder.value = [...DEFAULT_SECTION_ORDER];
    savePreference('dashboard_widget_order', sectionOrder.value);
}
</script>

<template>
    <AppLayout :title="t.page_title ?? 'Dashboard'" :breadcrumbs="breadcrumbs">
        <div class="page-dashboard">

            <!-- ── Personalizar (item MELHORIA "mais humano") — discreto, canto -->
            <!-- data-tour: âncoras do tour guiado (lang/*/tour.php → pages.panel.dashboard) -->
            <div class="d-flex justify-content-end mb-2">
                <div data-tour="dashboard-customize">
                    <ActionDropdown
                        :title="t.customize_title ?? 'Personalizar o painel'"
                        align="right"
                        :min-width="230"
                        btn-class="bg-white border shadow-sm rounded px-2 py-1 d-flex align-items-center gap-1 fs-13 text-muted"
                    >
                        <template #trigger>
                            <i class="ti ti-layout-dashboard" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">{{ t.customize ?? 'Personalizar' }}</span>
                        </template>

                        <ColumnOrderMenu
                            :title="t.sections_order ?? 'Ordem das seções'"
                            :columns="orderedSections"
                            :labels="orderLabels"
                            @move="moveSection"
                            @reset="resetSectionOrder"
                        />
                    </ActionDropdown>
                </div>
            </div>

            <!-- ── Live status bar ── -->
            <LiveStatusBar
                data-tour="dashboard-live"
                :is-refreshing="isRefreshing"
                :last-updated="lastUpdated"
                :t="t"
                @refresh="refresh"
            />

            <!-- ── Welcome Banner ── -->
            <WelcomeBanner data-tour="dashboard-welcome" :access="access" :t="t" />

            <!-- ── Activation progress (only when etapas obrigatórias pendentes) ── -->
            <Activation
                v-if="!activationComplete"
                data-tour="dashboard-activation"
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
                    :access="access"
                    :is-refreshing="isRefreshing"
                    :t="t"
                />

                <ModuleShortcuts
                    v-else-if="section.key === 'shortcuts'"
                    :access="access"
                    :order-labels="orderLabels"
                    :t="t"
                />

                <div v-else-if="section.key === 'agenda'" class="row g-3 mb-4">
                    <div class="col-lg-8">
                        <ScheduleToday
                            data-tour="dashboard-schedule-today"
                            :items="scheduleToday"
                            :total="stats.today_count ?? scheduleToday.length"
                            :is-refreshing="isRefreshing"
                            :can-open-schedule="!!access.schedules"
                            :t="t"
                        />
                    </div>
                    <div class="col-lg-4">
                        <DaySummary
                            data-tour="dashboard-day-summary"
                            :stats="stats"
                            :is-refreshing="isRefreshing"
                            :t="t"
                        />
                    </div>
                </div>

                <RecentPatients
                    v-else-if="section.key === 'patients'"
                    data-tour="dashboard-recent-patients"
                    :patients="recentPatients"
                    :can-open-patients="!!access.patients"
                    :t="t"
                />

                <!-- stockAlerts pode virar null via polling (ex.: recompra
                     resolveu o alerta durante o expediente) — guarda os
                     dois lados, não só a existência da seção. -->
                <StockAlerts
                    v-else-if="section.key === 'stock' && stockAlerts"
                    data-tour="dashboard-stock-alerts"
                    :alerts="stockAlerts"
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
