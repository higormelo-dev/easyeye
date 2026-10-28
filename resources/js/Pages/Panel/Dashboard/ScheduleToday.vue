<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { situationBadgeStyle as badgeStyle } from './situationBadge.js';

const props = defineProps({
    items: { type: Array, default: () => [] },
    // Total de consultas de hoje (stats.today_count). A lista vem limitada
    // pelo servidor; quando corta, avisa quantas estão sendo mostradas.
    total: { type: Number, default: 0 },
    isRefreshing: { type: Boolean, default: false },
    // Botão "ver agenda" só para quem pode abrir a agenda (regra da rota).
    canOpenSchedule: { type: Boolean, default: false },
    // Agenda do médico: título "Minha agenda" e sem a coluna "Médico".
    mine: { type: Boolean, default: false },
    // Ao lado da coluna lateral do painel a altura vem de fora (o card ocupa
    // a célula — .db-agenda-cell): a lista rola por dentro, com o cabeçalho
    // da tabela fixo. Sem limite de altura (celular), aparece inteira.
    fillHeight: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const truncatedText = computed(() => {
    if (props.total <= props.items.length || !props.t.schedule_showing) return '';

    return props.t.schedule_showing
        .replace(':shown', String(props.items.length))
        .replace(':total', String(props.total));
});

const activeCount = computed(() => props.items.filter((i) => i.is_active).length);

// ── Turnos (abas) ───────────────────────────────────────────────────────────
// Mesmos limites da Agenda (manhã < 13h, tarde 13–18h, noite ≥ 18h —
// PanelDashboardController::shiftOf); `bout` é o filtro de turno da Agenda,
// para "Ver agenda completa" abrir no mesmo turno da aba.
const SHIFTS = [
    { key: 'morning', bout: 2, icon: 'ti ti-sunrise' },
    { key: 'afternoon', bout: 3, icon: 'ti ti-sun' },
    { key: 'evening', bout: 4, icon: 'ti ti-moon' },
];

function shiftOfHour(hour) {
    if (hour < 13) return 'morning';
    if (hour < 18) return 'afternoon';
    return 'evening';
}

// Relógio local (mesmo fuso da clínica no uso normal), conferido a cada minuto:
// a aba acompanha o turno sozinha. Clique do usuário vale até o turno virar.
const nowHour = ref(new Date().getHours());
let clock = null;
onMounted(() => {
    clock = setInterval(() => {
        nowHour.value = new Date().getHours();
    }, 60_000);
});
onBeforeUnmount(() => clearInterval(clock));

const currentShift = computed(() => shiftOfHour(nowHour.value));
const pickedShift = ref(null);
watch(currentShift, () => {
    pickedShift.value = null;
});

const itemShift = (item) => item.shift ?? shiftOfHour(item.hour ?? 0);

const shiftTabs = computed(() =>
    SHIFTS.map((shift) => {
        const rows = props.items.filter((item) => itemShift(item) === shift.key);
        return {
            ...shift,
            label: props.t[`shift_${shift.key}`] ?? shift.key,
            count: rows.length,
            rows,
            isCurrent: shift.key === currentShift.value,
        };
    }).filter((tab) => tab.key !== 'evening' || tab.count > 0 || tab.isCurrent),
);

const activeShift = computed(() => {
    const keys = shiftTabs.value.map((tab) => tab.key);
    const wanted = pickedShift.value ?? currentShift.value;
    return keys.includes(wanted) ? wanted : keys[0];
});

const activeTab = computed(() => shiftTabs.value.find((tab) => tab.key === activeShift.value));

// Horários da aba agrupados por hora (08h, 09h…); a hora atual fica destacada.
const hourGroups = computed(() => {
    const groups = [];
    for (const item of activeTab.value?.rows ?? []) {
        const hour = item.hour ?? null;
        let group = groups[groups.length - 1];
        if (!group || group.hour !== hour) {
            group = { hour, label: item.hour_label ?? item.time, rows: [] };
            groups.push(group);
        }
        group.rows.push(item);
    }
    return groups;
});

const isNowGroup = (group) => activeTab.value?.isCurrent && group.hour === nowHour.value;

function selectShift(key) {
    pickedShift.value = key;
}

// Setas ←/→ entre abas (padrão WAI-ARIA de tabs).
function onTabKeydown(event, index) {
    const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (!step) return;
    event.preventDefault();
    const tabs = shiftTabs.value;
    const next = tabs[(index + step + tabs.length) % tabs.length];
    selectShift(next.key);
    event.currentTarget.parentElement?.parentElement?.querySelector(`[data-shift="${next.key}"]`)?.focus();
}

const scheduleUrl = computed(() =>
    route('panel.schedules.index', activeTab.value ? { bout: activeTab.value.bout } : undefined),
);

// Dados vindos do servidor: na agenda do médico só as consultas dele, sem o
// nome do médico (PanelDashboardController::buildScheduleToday).
const title = computed(() => (props.mine ? props.t.section_my_schedule_today : props.t.section_schedule_today));

// Linha de apoio sob o nome: tipo de consulta · convênio · "chegou às 09:12".
function detailOf(item) {
    const arrived =
        item.arrived && item.arrived_time ? (props.t.arrived_at ?? '').replace(':time', item.arrived_time) : '';

    return [item.visit, item.covenant, arrived].filter(Boolean).join(' · ');
}
const emptyText = computed(() => (props.mine ? props.t.empty_my_schedules : props.t.empty_schedules));
</script>

<template>
    <section class="card db-card schedule-today" :class="{ 'schedule-today--capped': fillHeight }" :aria-label="title">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-calendar-event" aria-hidden="true"></i>
                {{ title }}
                <span v-if="activeCount > 0" class="badge db-badge-live" :title="t.live_hint">
                    {{ activeCount }} {{ t.live_label }}
                </span>
            </h3>
            <div class="d-flex align-items-center gap-2">
                <i v-if="isRefreshing" class="ti ti-loader-2 db-spin text-muted small" aria-hidden="true"></i>
                <a v-if="canOpenSchedule" :href="scheduleUrl" class="db-link">
                    {{ t.btn_see_schedule }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </div>

        <div v-if="items.length === 0" class="db-empty">
            <i class="ti ti-calendar-off" aria-hidden="true"></i>
            <span>{{ emptyText }}</span>
        </div>

        <template v-else>
            <ul class="nav nav-tabs schedule-shifts px-3" role="tablist" :aria-label="t.shifts_label">
                <li v-for="(tab, index) in shiftTabs" :key="tab.key" class="nav-item" role="presentation">
                    <button
                        :id="`schedule-shift-tab-${tab.key}`"
                        type="button"
                        role="tab"
                        class="nav-link d-flex align-items-center gap-1"
                        :class="{ active: tab.key === activeShift }"
                        :data-shift="tab.key"
                        :aria-selected="tab.key === activeShift ? 'true' : 'false'"
                        :aria-controls="`schedule-shift-panel-${tab.key}`"
                        :tabindex="tab.key === activeShift ? 0 : -1"
                        @click="selectShift(tab.key)"
                        @keydown="onTabKeydown($event, index)"
                    >
                        <i :class="tab.icon" aria-hidden="true"></i>
                        {{ tab.label }}
                        <span class="badge rounded-pill schedule-shift-count">{{ tab.count }}</span>
                        <span v-if="tab.isCurrent" class="schedule-shift-now" :title="t.shift_now">
                            <span class="visually-hidden">{{ t.shift_now }}</span>
                        </span>
                    </button>
                </li>
            </ul>

            <div
                :id="`schedule-shift-panel-${activeShift}`"
                role="tabpanel"
                :aria-labelledby="`schedule-shift-tab-${activeShift}`"
                tabindex="0"
                class="schedule-panel"
            >
                <div v-if="!hourGroups.length" class="db-empty db-empty--inline">
                    <i class="ti ti-calendar-off" aria-hidden="true"></i>
                    <span>{{ t.shift_empty }}</span>
                </div>

                <!-- Situação logo depois do horário: o selo não fica flutuando
                     longe do nome nas telas largas (1920px). -->
                <table v-else class="schedule-table table table-hover mb-0" :class="{ 'schedule-table--mine': mine }">
                    <colgroup>
                        <col class="col-time" />
                        <col class="col-situation" />
                        <col class="col-patient" />
                        <col v-if="!mine" class="col-doctor" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">{{ t.col_time }}</th>
                            <th scope="col" class="d-none d-sm-table-cell">{{ t.col_situation }}</th>
                            <th scope="col">{{ t.col_name }}</th>
                            <th v-if="!mine" scope="col" class="d-none d-md-table-cell">{{ t.col_doctor }}</th>
                        </tr>
                    </thead>
                    <tbody v-for="group in hourGroups" :key="group.hour">
                        <tr class="schedule-hour-row" :class="{ 'schedule-hour-row--now': isNowGroup(group) }">
                            <th :colspan="mine ? 3 : 4" scope="rowgroup">
                                <i class="ti ti-clock me-1" aria-hidden="true"></i>{{ group.label }}
                                <span class="schedule-hour-count">· {{ group.rows.length }}</span>
                                <span v-if="isNowGroup(group)" class="badge db-badge-now ms-2">{{ t.shift_now }}</span>
                            </th>
                        </tr>
                        <tr
                            v-for="item in group.rows"
                            :key="item.id"
                            :class="item.is_active ? 'schedule-row--active' : ''"
                        >
                            <td class="schedule-time">{{ item.time }}</td>
                            <td class="d-none d-sm-table-cell">
                                <span class="badge rounded-pill schedule-badge" :style="badgeStyle(item.badge)">
                                    <i :class="`fa ${item.icon} me-1`" aria-hidden="true"></i>
                                    {{ item.label }}
                                </span>
                            </td>
                            <td class="schedule-patient">
                                <div class="d-flex align-items-center gap-2 min-w-0">
                                    <span
                                        v-if="item.arrived"
                                        class="ti ti-circle-check text-success flex-shrink-0"
                                        :title="t.arrived"
                                        role="img"
                                        :aria-label="t.arrived"
                                    ></span>
                                    <span class="schedule-name">{{ item.name }}</span>
                                </div>
                                <div v-if="detailOf(item)" class="schedule-detail">{{ detailOf(item) }}</div>
                                <!-- Celular: a situação vem embaixo do nome (sem coluna própria). -->
                                <span
                                    class="badge rounded-pill schedule-badge schedule-badge--inline d-sm-none"
                                    :style="badgeStyle(item.badge)"
                                >
                                    <i :class="`fa ${item.icon} me-1`" aria-hidden="true"></i>
                                    {{ item.label }}
                                </span>
                            </td>
                            <td v-if="!mine" class="schedule-doctor d-none d-md-table-cell">
                                {{ item.doctor }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p v-if="truncatedText" class="db-card-note" role="note">
            {{ truncatedText }}
        </p>
    </section>
</template>

<style scoped>
/* Coluna: cabeçalho e abas fixos; a lista ocupa o resto e rola (com teto). */
.schedule-today {
    display: flex;
    flex-direction: column;
}

.schedule-today > :not(.schedule-panel) {
    flex: none;
}

.schedule-panel {
    flex: 1 1 auto;
    min-height: 0;
}

.schedule-today--capped .schedule-panel {
    overflow-y: auto;
    scrollbar-width: thin;
}

.schedule-today--capped .schedule-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
}

/* Rolagem só na horizontal (celular/idioma com rótulos longos). As abas do
   Bootstrap descem 1px (margin-bottom negativa) sobre a borda da lista — com
   overflow isso virava 1px de rolagem VERTICAL (barra ao lado das abas).
   A linha de baixo vira sombra interna e as abas não descem mais. */
.schedule-shifts {
    gap: 0.25rem;
    flex-wrap: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
    padding-top: 0.5rem;
    border-bottom: 0;
    box-shadow: inset 0 calc(-1 * var(--bs-nav-tabs-border-width)) 0 var(--bs-nav-tabs-border-color);
}

.schedule-shifts .nav-link {
    margin-bottom: 0;
    font-size: 0.85rem;
    white-space: nowrap;
}

.schedule-shift-count {
    background: var(--bs-secondary-bg);
    color: var(--bs-body-color);
    font-size: 0.7rem;
}

.schedule-shifts .nav-link.active .schedule-shift-count {
    background: var(--bs-primary);
    color: #fff;
}

.schedule-shift-now {
    display: inline-block;
    width: 0.45rem;
    height: 0.45rem;
    border-radius: 50%;
    background: var(--bs-success);
}

/* Larguras fixas: horário e situação estreitos; paciente leva o resto e o
   médico fica numa coluna própria (some no celular). */
.schedule-table {
    table-layout: fixed;
}

.schedule-table .col-time {
    width: 5.5rem;
}

.schedule-table .col-situation {
    width: 10.5rem;
}

.schedule-table .col-doctor {
    width: 30%;
}

.schedule-time {
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.02em;
}

.schedule-badge {
    font-size: 0.72rem;
    padding: 0.3rem 0.55rem;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
}

.schedule-badge .fa {
    font-size: 0.65rem;
}

.schedule-name {
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.schedule-detail {
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.schedule-doctor {
    font-size: 0.8125rem;
    color: var(--bs-secondary-color);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.schedule-hour-row th {
    color: var(--bs-secondary-color);
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0.02em;
    padding-top: 0.35rem;
    padding-bottom: 0.35rem;
}

/* Células do card são transparentes (dashboard.css) — o fundo vai na linha. */
.schedule-table tbody tr.schedule-hour-row,
.schedule-table tbody tr.schedule-hour-row:hover {
    background: var(--bs-tertiary-bg);
}

.schedule-table tbody tr.schedule-hour-row--now,
.schedule-table tbody tr.schedule-hour-row--now:hover {
    background: rgba(var(--bs-primary-rgb), 0.1);
}

.schedule-hour-row--now th {
    color: var(--bs-primary);
}

[data-bs-theme='dark'] .page-dashboard .schedule-table tbody tr.schedule-hour-row,
[data-bs-theme='dark'] .page-dashboard .schedule-table tbody tr.schedule-hour-row:hover {
    background: #132035;
}

[data-bs-theme='dark'] .page-dashboard .schedule-table tbody tr.schedule-hour-row--now,
[data-bs-theme='dark'] .page-dashboard .schedule-table tbody tr.schedule-hour-row--now:hover {
    background: rgba(96, 165, 250, 0.14);
}

[data-bs-theme='dark'] .schedule-hour-row--now th {
    color: #93c5fd;
}

.schedule-hour-count {
    font-weight: 400;
}

/* Tablet: sem a coluna do médico (a última) — a <col> dela não reserva mais
   largura no layout fixo. */
@media (max-width: 767.98px) {
    .schedule-table .col-doctor {
        width: 0;
    }
}

/* Celular: horário + paciente; a situação desce para baixo do nome. Sem a
   célula da situação (no meio da linha), o layout volta a ser automático —
   com larguras fixas por coluna o paciente cairia na coluna da situação. */
@media (max-width: 575.98px) {
    .schedule-table {
        table-layout: auto;
    }

    .schedule-table col {
        width: auto !important;
    }

    .schedule-time {
        width: 1%;
        white-space: nowrap;
    }

    .schedule-name,
    .schedule-detail {
        white-space: normal;
    }

    .schedule-badge--inline {
        margin-top: 0.3rem;
    }
}
</style>
