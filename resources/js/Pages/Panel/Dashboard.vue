<script setup>
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DashboardHeader from './Dashboard/DashboardHeader.vue';
import Activation from './Dashboard/Activation.vue';
import KpiCards from './Dashboard/KpiCards.vue';
import ModuleShortcuts from './Dashboard/ModuleShortcuts.vue';
import ScheduleToday from './Dashboard/ScheduleToday.vue';
import DaySummary from './Dashboard/DaySummary.vue';
import RecentPatients from './Dashboard/RecentPatients.vue';
import StockAlerts from './Dashboard/StockAlerts.vue';
import NextPatient from './Dashboard/NextPatient.vue';
import DoctorPending from './Dashboard/DoctorPending.vue';
import WaitingRoom from './Dashboard/WaitingRoom.vue';
import DoctorsToday from './Dashboard/DoctorsToday.vue';
import ConfirmationsPanel from './Dashboard/ConfirmationsPanel.vue';
import WaitList from './Dashboard/WaitList.vue';
import BirthdaysToday from './Dashboard/BirthdaysToday.vue';
import TrendsSection from './Dashboard/TrendsSection.vue';
import FinanceToday from './Dashboard/FinanceToday.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import { useDashboardPolling } from '@/composables/useDashboardPolling.js';
import { useUserPreferences } from '@/composables/useUserPreferences.js';
import {
    groupSections,
    moveVisibleSection,
    normalizeHiddenSections,
    normalizeSectionOrder,
} from './Dashboard/sectionOrder.js';

/**
 * Dashboard v2 — "painel por função": cada perfil abre no posto de trabalho
 * dele (PanelDashboardController decide as seções e os dados; o que não é do
 * perfil nem chega). Operação de hoje no polling de 30 s; números de gestão
 * (`insights`) só na abertura e no "Atualizar".
 */
const props = defineProps({
    // Perfil na clínica (admin, secretary, financial, user, doctor) e as seções
    // que ele pode ver, na ordem padrão do posto de trabalho.
    profile: { type: String, default: 'admin' },
    sections: { type: Array, default: () => ['kpis', 'trends', 'agenda', 'shortcuts', 'patients', 'stock'] },
    // Usuário médico sem cadastro de médico: painel vazio + aviso.
    doctorMissing: { type: Boolean, default: false },
    stats: { type: Object, required: true },
    scheduleToday: { type: Array, default: () => [] },
    recentPatients: { type: Array, default: () => [] },
    // Só do médico: próximo paciente, laudos de IA a revisar (null sem IA na
    // clínica) e prontuários sem assinatura.
    nextPatient: { type: Object, default: null },
    aiWaiting: { type: Object, default: null },
    unsignedRecords: { type: Object, default: null },
    // Recepção (secretária): sala de espera + confirmações, lista de espera, aniversariantes.
    reception: { type: Object, default: null },
    waitlist: { type: Object, default: null },
    birthdays: { type: Object, default: null },
    // Administração: atendimentos por médico hoje.
    doctorsToday: { type: Object, default: null },
    // Financeiro: caixa de hoje.
    cashToday: { type: Object, default: null },
    // Gestão: mês × mesmo período do mês anterior, tendências, a receber, glosas.
    insights: { type: Object, default: null },
    activation: { type: Array, default: () => [] },
    activationScore: { type: Number, default: 0 },
    // null quando a clínica não usa o estoque OU não tem nada crítico agora
    // (PanelDashboardController::buildStockAlerts()).
    stockAlerts: { type: Object, default: null },
    // Telas que o usuário pode abrir (mesmas regras das rotas —
    // PanelDashboardController::buildAccess()): sem a permissão, nada vira
    // link para um 403.
    access: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

const isDoctor = computed(() => props.profile === 'doctor');
// Médico sem cadastro de médico: a Agenda não sabe quem ele é (mostraria a
// de todos) — o Dashboard não oferece o atalho até o cadastro ser concluído.
const shortcutAccess = computed(() => (props.doctorMissing ? { ...props.access, schedules: false } : props.access));
const showSoonShortcuts = computed(() => ['admin', 'secretary'].includes(props.profile));
const canOpenSchedule = computed(() => !!props.access.schedules && !props.doctorMissing);

// Card "Configure sua clínica" some quando as etapas OBRIGATÓRIAS estão
// concluídas; as opcionais só contam ponto no score.
const activationComplete = computed(() => props.activation.every((step) => !step.required || step.done));

// ── Polling: só a operação de hoje (30 s). Números de gestão (`insights`) ──
// ficam fora: vêm na abertura e no "Atualizar", que pede ao servidor para
// descartar o cache deles (header X-Dashboard-Refresh).
const { isRefreshing, isFullRefresh, lastUpdated, refresh } = useDashboardPolling(
    [
        'stats',
        'scheduleToday',
        'nextPatient',
        'recentPatients',
        'aiWaiting',
        'unsignedRecords',
        'activation',
        'activationScore',
        'stockAlerts',
        'reception',
        'waitlist',
        'birthdays',
        'doctorsToday',
        'cashToday',
    ],
    30_000,
    { manual: ['insights'], headers: { 'X-Dashboard-Refresh': '1' } },
);

const breadcrumbs = [];

// ── Personalização: ordem e visibilidade das seções (por perfil) ────────────
// Header, aviso e "Configure sua clínica" ficam fixos (contexto, não conteúdo
// reordenável). `size: 'compact'` → seções vizinhas dividem a mesma linha.
const SECTION_DEFS = {
    next: { label: 'section_next', size: 'full' },
    kpis: { label: 'section_kpis', size: 'full' },
    finance: { label: 'section_finance', size: 'full' },
    trends: { label: 'section_trends', size: 'full' },
    agenda: { label: 'section_agenda', size: 'full' },
    confirmations: { label: 'section_confirmations', size: 'full' },
    pending: { label: 'section_pending', size: 'compact' },
    waitlist: { label: 'section_waitlist', size: 'compact' },
    birthdays: { label: 'section_birthdays', size: 'compact' },
    patients: { label: 'section_patients', size: 'compact' },
    stock: { label: 'section_stock', size: 'compact' },
    shortcuts: { label: 'section_shortcuts', size: 'full' },
};

const profileKeys = computed(() => props.sections.filter((key) => SECTION_DEFS[key]));

const orderLabels = computed(() => ({
    show: props.t.order_show,
    hide: props.t.order_hide,
    moveUp: props.t.order_move_up,
    moveDown: props.t.order_move_down,
    reset: props.t.order_reset,
}));

const { getPreference, savePreference } = useUserPreferences();

const sectionOrder = ref(normalizeSectionOrder(getPreference('dashboard_widget_order'), profileKeys.value));
const hiddenSections = ref(normalizeHiddenSections(getPreference('dashboard_hidden_sections'), profileKeys.value));

// Disponíveis agora (estoque só com alerta) — é a lista do menu "Personalizar".
const availableSections = computed(() =>
    sectionOrder.value
        .filter((key) => profileKeys.value.includes(key))
        .filter((key) => key !== 'stock' || props.stockAlerts)
        .map((key) => ({
            key,
            size: SECTION_DEFS[key].size,
            label: props.t[SECTION_DEFS[key].label] ?? key,
            hidden: hiddenSections.value.includes(key),
        })),
);

const visibleSections = computed(() => availableSections.value.filter((section) => !section.hidden));

// Médico: "Minhas pendências" logo depois da agenda (ordem padrão) vai para
// a coluna lateral, embaixo de "Meu dia" — usa o espaço ao lado da agenda.
// Movida ou oculta no "Personalizar", vale a escolha do usuário.
const pendingBesideAgenda = computed(() => {
    if (!isDoctor.value) return false;
    const keys = visibleSections.value.map((section) => section.key);
    const agenda = keys.indexOf('agenda');

    return agenda >= 0 && keys[agenda + 1] === 'pending';
});

const blocks = computed(() =>
    groupSections(visibleSections.value.filter((section) => !(pendingBesideAgenda.value && section.key === 'pending'))),
);

// Seções compactas sem nenhum item (lista de espera, aniversariantes,
// pacientes recentes) não ocupam card na grade: viram uma faixa fina de
// status. A grade fica só com os cards que têm conteúdo, na mesma altura —
// antes um card vazio baixinho ao lado de um alto deixava espaço em branco.
function isEmptySection(key) {
    if (key === 'waitlist') return Number(props.waitlist?.count ?? 0) === 0;
    if (key === 'birthdays') return Number(props.birthdays?.count ?? 0) === 0;
    if (key === 'patients') return props.recentPatients.length === 0;

    return false;
}

const blockViews = computed(() =>
    blocks.value.map((block) =>
        block.type === 'compact'
            ? {
                  ...block,
                  filled: block.sections.filter((section) => !isEmptySection(section.key)),
                  empty: block.sections.filter((section) => isEmptySection(section.key)),
              }
            : block,
    ),
);

function moveSection(fromIndex, toIndex) {
    const visible = availableSections.value.map((s) => s.key);
    const next = moveVisibleSection(sectionOrder.value, visible, fromIndex, toIndex);
    if (!next) return;

    sectionOrder.value = next;
    savePreference('dashboard_widget_order', next);
}

function toggleSection(key) {
    hiddenSections.value = hiddenSections.value.includes(key)
        ? hiddenSections.value.filter((hidden) => hidden !== key)
        : [...hiddenSections.value, key];
    savePreference('dashboard_hidden_sections', hiddenSections.value);
}

function resetSections() {
    sectionOrder.value = [...profileKeys.value];
    hiddenSections.value = [];
    savePreference('dashboard_widget_order', sectionOrder.value);
    savePreference('dashboard_hidden_sections', []);
}

function onRefresh() {
    refresh({ full: true });
}
</script>

<template>
    <AppLayout :title="t.page_title ?? 'Dashboard'" :breadcrumbs="breadcrumbs">
        <div class="page-dashboard" :data-profile="profile">
            <DashboardHeader
                :profile="profile"
                :access="access"
                :next-patient="nextPatient"
                :next-visible="visibleSections.some((section) => section.key === 'next')"
                :doctor-missing="doctorMissing"
                :is-refreshing="isRefreshing"
                :last-updated="lastUpdated"
                :t="t"
                @refresh="onRefresh"
            >
                <template #tools>
                    <!-- data-tour: âncoras do tour guiado (lang/*/tour.php → pages.panel.dashboard) -->
                    <div data-tour="dashboard-customize">
                        <ActionDropdown
                            :title="t.customize_title ?? 'Personalizar o painel'"
                            align="right"
                            :min-width="240"
                            btn-class="db-icon-btn db-icon-btn--labelled"
                        >
                            <template #trigger>
                                <i class="ti ti-layout-dashboard" aria-hidden="true"></i>
                                <span class="d-none d-sm-inline">{{ t.customize ?? 'Personalizar' }}</span>
                            </template>

                            <ColumnOrderMenu
                                :title="t.sections_order ?? 'Seções do painel'"
                                :columns="availableSections"
                                :labels="orderLabels"
                                toggleable
                                @move="moveSection"
                                @toggle="toggleSection"
                                @reset="resetSections"
                            />
                        </ActionDropdown>
                    </div>
                </template>
            </DashboardHeader>

            <!-- ── Médico sem cadastro de médico: nada da clínica é enviado ── -->
            <div
                v-if="doctorMissing"
                class="alert alert-warning doctor-missing-alert d-flex gap-2 align-items-start"
                role="alert"
            >
                <i class="ti ti-alert-triangle fs-4 flex-shrink-0" aria-hidden="true"></i>
                <div>
                    <strong class="d-block">{{ t.doctor_missing_title }}</strong>
                    {{ t.doctor_missing_text }}
                </div>
            </div>

            <!-- ── Configure sua clínica (só com etapas obrigatórias pendentes) ── -->
            <Activation
                v-if="!activationComplete"
                data-tour="dashboard-activation"
                :activation="activation"
                :activation-score="activationScore"
                :t="t"
            />

            <!-- ── Seções na ordem do usuário; compactas vizinhas lado a lado ── -->
            <template v-for="block in blockViews" :key="block.key">
                <!-- Compactas vazias: uma faixa só, sem card ocupando espaço. -->
                <section
                    v-if="block.type === 'compact' && block.empty.length"
                    class="card db-card db-empty-strip"
                    :aria-label="t.empty_strip_label"
                >
                    <ul class="db-empty-strip__list">
                        <template v-for="section in block.empty" :key="section.key">
                            <li
                                v-if="section.key === 'waitlist'"
                                class="db-empty-strip__item"
                                data-tour="dashboard-waitlist"
                                data-section="waitlist"
                            >
                                <i class="ti ti-list-numbers" aria-hidden="true"></i>
                                <strong>{{ t.waitlist_title }}</strong>
                                <span>{{ t.waitlist_empty }}</span>
                                <a v-if="canOpenSchedule && waitlist?.url" :href="waitlist.url" class="db-link">
                                    {{ t.waitlist_open }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                                </a>
                            </li>
                            <li
                                v-else-if="section.key === 'birthdays'"
                                class="db-empty-strip__item"
                                data-tour="dashboard-birthdays"
                                data-section="birthdays"
                            >
                                <i class="ti ti-cake" aria-hidden="true"></i>
                                <strong>{{ t.birthdays_title }}</strong>
                                <span>{{ t.birthdays_empty }}</span>
                            </li>
                            <li
                                v-else-if="section.key === 'patients'"
                                class="db-empty-strip__item"
                                data-tour="dashboard-recent-patients"
                                data-section="patients"
                            >
                                <i class="ti ti-users" aria-hidden="true"></i>
                                <strong>{{
                                    isDoctor ? t.section_my_recent_patients : t.section_recent_patients
                                }}</strong>
                                <span>{{ isDoctor ? t.empty_my_patients : t.empty_patients }}</span>
                            </li>
                        </template>
                    </ul>
                </section>

                <div
                    v-if="block.type === 'compact' && block.filled.length"
                    class="db-compact-row"
                    :data-count="block.filled.length"
                >
                    <template v-for="section in block.filled" :key="section.key">
                        <DoctorPending
                            v-if="section.key === 'pending'"
                            :ai-waiting="aiWaiting"
                            :unsigned-records="unsignedRecords"
                            :t="t"
                        />

                        <RecentPatients
                            v-else-if="section.key === 'patients'"
                            data-tour="dashboard-recent-patients"
                            data-section="patients"
                            :patients="recentPatients"
                            :can-open-patients="!!access.patients"
                            :mine="isDoctor"
                            :t="t"
                        />

                        <WaitList
                            v-else-if="section.key === 'waitlist'"
                            data-section="waitlist"
                            :waitlist="waitlist"
                            :can-open-schedule="canOpenSchedule"
                            :t="t"
                        />

                        <BirthdaysToday
                            v-else-if="section.key === 'birthdays'"
                            data-section="birthdays"
                            :birthdays="birthdays"
                            :can-open-patients="!!access.patients"
                            :t="t"
                        />

                        <!-- stockAlerts pode virar null via polling (recompra resolveu o alerta). -->
                        <StockAlerts
                            v-else-if="section.key === 'stock' && stockAlerts"
                            data-tour="dashboard-stock-alerts"
                            data-section="stock"
                            :alerts="stockAlerts"
                            :t="t"
                        />
                    </template>
                </div>

                <template v-if="block.type !== 'compact'">
                    <NextPatient
                        v-if="block.key === 'next' && !doctorMissing"
                        data-tour="dashboard-next-patient"
                        data-section="next"
                        :patient="nextPatient"
                        :is-refreshing="isRefreshing"
                        :t="t"
                    />

                    <KpiCards
                        v-else-if="block.key === 'kpis'"
                        data-section="kpis"
                        :stats="stats"
                        :profile="profile"
                        :ai-waiting="aiWaiting"
                        :reception="reception"
                        :waitlist="waitlist"
                        :insights="insights"
                        :access="access"
                        :is-refreshing="isRefreshing"
                        :loading-insights="isFullRefresh"
                        :t="t"
                    />

                    <FinanceToday
                        v-else-if="block.key === 'finance'"
                        data-section="finance"
                        :cash="cashToday"
                        :receivables="insights?.receivables ?? null"
                        :glosas="insights?.glosas ?? null"
                        :loading="isFullRefresh"
                        :t="t"
                    />

                    <TrendsSection
                        v-else-if="block.key === 'trends'"
                        data-section="trends"
                        :profile="profile"
                        :insights="insights"
                        :can-open-bi="!!access.financial"
                        :loading="isFullRefresh"
                        :t="t"
                    />

                    <section
                        v-else-if="block.key === 'agenda'"
                        class="db-section"
                        data-section="agenda"
                        :aria-label="t.section_agenda"
                    >
                        <!-- Agenda e coluna lateral alinhadas no topo e na base: a
                             agenda ocupa a altura da linha (definida pela coluna,
                             com mínimo) e rola por dentro — ver .db-agenda-cell. -->
                        <div class="db-split db-split--agenda">
                            <div class="db-agenda-cell">
                                <ScheduleToday
                                    data-tour="dashboard-schedule-today"
                                    :items="scheduleToday"
                                    :total="stats.today_count ?? scheduleToday.length"
                                    :is-refreshing="isRefreshing"
                                    :can-open-schedule="canOpenSchedule"
                                    :mine="isDoctor"
                                    fill-height
                                    :t="t"
                                />
                            </div>
                            <div class="db-side">
                                <WaitingRoom
                                    v-if="profile === 'secretary' && reception"
                                    :room="reception.waiting_room"
                                    :t="t"
                                />
                                <DaySummary
                                    data-tour="dashboard-day-summary"
                                    :stats="stats"
                                    :items="scheduleToday"
                                    :is-refreshing="isRefreshing"
                                    :mine="isDoctor"
                                    :t="t"
                                />
                                <DoctorsToday
                                    v-if="profile === 'admin' && doctorsToday"
                                    :doctors="doctorsToday"
                                    :t="t"
                                />
                                <DoctorPending
                                    v-if="pendingBesideAgenda"
                                    :ai-waiting="aiWaiting"
                                    :unsigned-records="unsignedRecords"
                                    :t="t"
                                />
                            </div>
                        </div>
                    </section>

                    <ConfirmationsPanel
                        v-else-if="block.key === 'confirmations' && reception"
                        data-section="confirmations"
                        :reception="reception"
                        :can-open-schedule="canOpenSchedule"
                        :t="t"
                    />

                    <ModuleShortcuts
                        v-else-if="block.key === 'shortcuts'"
                        data-section="shortcuts"
                        :access="shortcutAccess"
                        :show-soon="showSoonShortcuts"
                        :profile="profile"
                        :order-labels="orderLabels"
                        :t="t"
                    />
                </template>
            </template>
        </div>
    </AppLayout>
</template>

<style>
@import '../../../css/dashboard.css';
</style>
