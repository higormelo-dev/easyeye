<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import DoctorMissingAlert from '@/Components/Panel/DoctorMissingAlert.vue';
import UrgentCareFormModal from './UrgentCareFormModal.vue';
import AllergyBadge from '@/Components/Panel/ClinicalSafety/AllergyBadge.vue';
import { useDashboardPolling } from '@/composables/useDashboardPolling.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Pronto atendimento — fila de demanda espontânea do dia
 * (UrgentCareController / UrgentCareService).
 *
 * Ordem: prioridade (imediata → prioritária por lei → normal), depois a
 * chegada. Espera ao vivo (relógio local a cada 15 s) e a fila atualiza a
 * cada 30 s (mesmo polling da Agenda). O servidor decide o que cada perfil vê:
 * médico só os itens dele + os sem médico; "Atender" só para médico com
 * cadastro ativo — cria o atendimento de urgência e abre o prontuário.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    queue: { type: Array, default: () => [] },
    closed: { type: Array, default: () => [] },
    doctors: { type: Array, default: () => [] },
    covenants: { type: Array, default: () => [] },
    reasons: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    legalPriorityAge: { type: Number, default: 60 },
    canAttend: { type: Boolean, default: false },
    currentDoctorId: { type: String, default: null },
    isDoctor: { type: Boolean, default: false },
    doctorMissing: { type: Boolean, default: false },
    doctorMissingText: { type: String, default: '' },
    callPanelEnabled: { type: Boolean, default: false },
    canRegisterPatient: { type: Boolean, default: false },
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const { number } = useLocaleFormat();

const { isRefreshing, lastUpdated, refresh } = useDashboardPolling(['queue', 'closed'], 30_000);

function tx(key, params = {}) {
    return Object.entries(params).reduce(
        (text, [name, value]) => text.replace(`:${name}`, String(value)),
        props.t[key] ?? key,
    );
}

// ── Espera ao vivo ────────────────────────────────────────────────────────
const now = ref(Date.now());
let clock = null;
onMounted(() => {
    clock = setInterval(() => {
        now.value = Date.now();
    }, 15_000);
});
onBeforeUnmount(() => clearInterval(clock));

function waitMinutes(row) {
    const arrived = Date.parse(row.arrived_at ?? '');
    if (Number.isNaN(arrived)) return 0;
    return Math.max(0, Math.floor((now.value - arrived) / 60_000));
}

function durationLabel(minutes) {
    if (minutes < 60) return tx('minutes_short', { m: number(minutes) });
    return tx('hours_short', { h: number(Math.floor(minutes / 60)), m: number(minutes % 60) });
}

const summary = computed(() => ({
    queued: props.queue.length,
    immediate: props.queue.filter((row) => row.priority_key === 'immediate').length,
    longest: props.queue.reduce((max, row) => Math.max(max, waitMinutes(row)), 0),
}));

const updatedLabel = computed(() =>
    tx('updated_at', {
        time: lastUpdated.value.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }),
    }),
);

const PRIORITY_ICON = { immediate: 'ti ti-alert-triangle', legal: 'ti ti-accessible', normal: 'ti ti-clock' };

// ── Formulário (adicionar / editar) ───────────────────────────────────────
const formOpen = ref(false);
const editing = ref(null);

function openAdd() {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(row) {
    editing.value = row;
    formOpen.value = true;
}

function onSaved() {
    formOpen.value = false;
    editing.value = null;
}

const queuedPatientIds = computed(() =>
    props.queue.filter((row) => !editing.value || row.id !== editing.value.id).map((row) => row.patient_id),
);

// ── Ações da fila ─────────────────────────────────────────────────────────
const busyId = ref(null);

function post(name, row, data = {}, extra = {}) {
    if (busyId.value) return;
    busyId.value = row.id;
    router.post(route(name, row.id), data, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            busyId.value = null;
        },
        ...extra,
    });
}

function call(row) {
    post('panel.urgent-care.call', row);
}

function attend(row) {
    post('panel.urgent-care.attend', row, {}, { preserveState: false });
}

// Desistiu / removido — com motivo.
const closing = ref(null);
const closeReason = ref('');
const closeError = ref('');

function openClose(row, status) {
    closing.value = { row, status };
    closeReason.value = '';
    closeError.value = '';
}

function confirmClose() {
    if (!closing.value || closeReason.value.trim().length < 3) {
        closeError.value = props.t.field_close_reason ?? '';
        return;
    }
    const { row, status } = closing.value;
    post(
        'panel.urgent-care.close',
        row,
        { status, reason: closeReason.value.trim() },
        {
            onSuccess: () => {
                closing.value = null;
            },
            onError: (errors) => {
                closeError.value = errors.reason ?? errors.status ?? '';
            },
        },
    );
}

const STATUS_LEFT = 4;
const STATUS_REMOVED = 5;

// Só quem atendeu reabre o prontuário por aqui (a Agenda dele também tem).
function canOpenRecord(row) {
    return (
        props.canAttend &&
        row.status_key === 'in_care' &&
        Boolean(row.schedule_id) &&
        row.attended_by_id === props.currentDoctorId
    );
}

function recordUrl(row) {
    return route('panel.schedules.attend', row.schedule_id);
}
</script>

<template>
    <Head :title="t.title" />

    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3 uc-page">
            <PageHeader :title="t.title" :subtitle="t.subtitle">
                <template #actions>
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="isRefreshing"
                        :title="updatedLabel"
                        @click="refresh()"
                    >
                        <i class="ti ti-refresh" :class="{ 'uc-spin': isRefreshing }" aria-hidden="true"></i>
                        <span class="visually-hidden">{{ t.refresh }}</span>
                    </button>
                    <button
                        v-if="!doctorMissing"
                        type="button"
                        class="btn btn-primary btn-sm"
                        data-testid="uc-add"
                        @click="openAdd"
                    >
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.add }}
                    </button>
                </template>
            </PageHeader>

            <DoctorMissingAlert v-if="doctorMissing" :text="doctorMissingText" />

            <template v-else>
                <!-- Resumo -->
                <!-- Anuncia só a mudança do tamanho da fila (a espera anda a cada 15 s). -->
                <span class="visually-hidden" aria-live="polite">{{
                    tx('live_region', { count: summary.queued })
                }}</span>
                <div class="uc-summary mb-3">
                    <div class="uc-stat">
                        <span class="uc-stat__value" data-testid="uc-summary-queued">{{ number(summary.queued) }}</span>
                        <span class="uc-stat__label">{{ t.summary_queued }}</span>
                    </div>
                    <div class="uc-stat" :class="{ 'uc-stat--alert': summary.immediate > 0 }">
                        <span class="uc-stat__value" data-testid="uc-summary-immediate">{{
                            number(summary.immediate)
                        }}</span>
                        <span class="uc-stat__label">{{ t.summary_immediate }}</span>
                    </div>
                    <div class="uc-stat">
                        <span class="uc-stat__value">{{ summary.queued ? durationLabel(summary.longest) : '—' }}</span>
                        <span class="uc-stat__label">{{ t.summary_longest }}</span>
                    </div>
                    <span class="uc-updated small text-muted ms-auto">{{ updatedLabel }}</span>
                </div>

                <!-- Fila -->
                <section class="card uc-card mb-3" :aria-label="t.queue_title">
                    <div class="card-header d-flex align-items-center gap-2">
                        <h2 class="h6 mb-0">{{ t.queue_title }}</h2>
                    </div>

                    <div v-if="!queue.length" class="card-body text-center py-5" data-testid="uc-empty">
                        <i class="ti ti-armchair fs-1 text-muted d-block mb-2" aria-hidden="true"></i>
                        <p class="fw-semibold mb-1">{{ t.queue_empty }}</p>
                        <p class="text-muted small mb-0">{{ t.queue_empty_hint }}</p>
                    </div>

                    <ol v-else class="uc-queue list-unstyled mb-0" :aria-label="t.queue_label">
                        <li
                            v-for="row in queue"
                            :key="row.id"
                            class="uc-item"
                            :class="[`uc-item--${row.priority_key}`, { 'is-called': row.status_key === 'called' }]"
                            data-testid="uc-row"
                            :data-priority="row.priority_key"
                        >
                            <div class="uc-pos" :aria-label="tx('position', { n: row.position })">
                                {{ row.position }}
                            </div>

                            <div class="uc-main">
                                <div class="uc-line1">
                                    <span class="badge uc-priority" :class="`uc-priority--${row.priority_key}`">
                                        <i :class="PRIORITY_ICON[row.priority_key]" class="me-1" aria-hidden="true"></i
                                        >{{ row.priority_label }}
                                    </span>
                                    <strong class="uc-name">{{ row.patient_name }}</strong>
                                    <span v-if="row.age !== null" class="text-muted small">{{
                                        tx('age_years', { age: row.age })
                                    }}</span>
                                    <span
                                        v-if="row.status_key === 'called'"
                                        class="badge uc-called"
                                        data-testid="uc-called"
                                    >
                                        <i class="ti ti-speakerphone me-1" aria-hidden="true"></i
                                        >{{ tx('called_at', { time: row.called_time }) }}
                                        <template v-if="row.call_count > 1">
                                            · {{ tx('called_times', { count: row.call_count }) }}</template
                                        >
                                    </span>
                                </div>
                                <!-- Alergia do paciente (Fase 2): texto, não só cor. -->
                                <div v-if="row.allergy" class="uc-allergy mt-1">
                                    <AllergyBadge :allergy="row.allergy" />
                                </div>
                                <div class="uc-line2 small">
                                    <span class="fw-medium">{{ row.reason_label }}</span>
                                    <span v-if="row.reason_details" class="text-muted">
                                        — {{ row.reason_details }}</span
                                    >
                                </div>
                                <div class="uc-line3 small text-muted">
                                    <span
                                        ><i class="ti ti-stethoscope me-1" aria-hidden="true"></i
                                        ><span class="visually-hidden">{{ t.doctor_label }}: </span
                                        >{{ row.preferred_doctor ?? t.no_doctor }}</span
                                    >
                                    <span
                                        ><i class="ti ti-id me-1" aria-hidden="true"></i
                                        ><span class="visually-hidden">{{ t.covenant_label }}: </span
                                        >{{ row.covenant ?? t.no_covenant }}</span
                                    >
                                    <span v-if="row.phone"
                                        ><i class="ti ti-phone me-1" aria-hidden="true"></i
                                        ><span class="visually-hidden">{{ t.phone_label }}: </span>{{ row.phone }}</span
                                    >
                                </div>
                            </div>

                            <div class="uc-wait">
                                <span class="uc-wait__value" data-testid="uc-wait">{{
                                    durationLabel(waitMinutes(row))
                                }}</span>
                                <span class="uc-wait__label">{{ tx('arrived_at', { time: row.arrived_time }) }}</span>
                            </div>

                            <div class="uc-actions">
                                <button
                                    v-if="callPanelEnabled"
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    :disabled="busyId !== null"
                                    :title="t.call_hint"
                                    data-testid="uc-call"
                                    @click="call(row)"
                                >
                                    <i class="ti ti-speakerphone me-1" aria-hidden="true"></i
                                    >{{ row.status_key === 'called' ? t.action_call_again : t.action_call }}
                                </button>
                                <button
                                    v-if="canAttend"
                                    type="button"
                                    class="btn btn-sm btn-success"
                                    :disabled="busyId !== null"
                                    :title="t.attend_hint"
                                    data-testid="uc-attend"
                                    @click="attend(row)"
                                >
                                    <i class="ti ti-player-play me-1" aria-hidden="true"></i>{{ t.action_attend }}
                                </button>
                                <div class="dropdown">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-light"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                        :aria-label="`${t.action_more}: ${row.patient_name}`"
                                        :title="t.action_more"
                                        data-tooltip="off"
                                    >
                                        <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item"
                                                data-testid="uc-edit"
                                                @click="openEdit(row)"
                                            >
                                                <i class="ti ti-pencil me-2" aria-hidden="true"></i>{{ t.action_edit }}
                                            </button>
                                        </li>
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item"
                                                data-testid="uc-left"
                                                @click="openClose(row, STATUS_LEFT)"
                                            >
                                                <i class="ti ti-door-exit me-2" aria-hidden="true"></i
                                                >{{ t.action_left }}
                                            </button>
                                        </li>
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item text-danger"
                                                data-testid="uc-remove"
                                                @click="openClose(row, STATUS_REMOVED)"
                                            >
                                                <i class="ti ti-trash me-2" aria-hidden="true"></i>{{ t.action_remove }}
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </li>
                    </ol>
                    <p
                        v-if="queue.length && !canAttend && !isDoctor"
                        class="small text-muted px-3 py-2 mb-0 border-top"
                    >
                        <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.attend_staff_hint }}
                    </p>
                </section>

                <!-- Encerrados hoje -->
                <details class="card uc-card" data-testid="uc-closed">
                    <summary class="card-header d-flex align-items-center gap-2">
                        <span class="h6 mb-0">{{ t.closed_title }}</span>
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">{{
                            number(closed.length)
                        }}</span>
                    </summary>
                    <div v-if="!closed.length" class="card-body small text-muted">{{ t.closed_empty }}</div>
                    <ul v-else class="list-group list-group-flush">
                        <li
                            v-for="row in closed"
                            :key="row.id"
                            class="list-group-item d-flex flex-wrap align-items-center gap-2"
                            data-testid="uc-closed-row"
                        >
                            <span class="badge uc-status" :class="`uc-status--${row.status_key}`">{{
                                row.status_label
                            }}</span>
                            <span class="fw-medium">{{ row.patient_name }}</span>
                            <span class="small text-muted">{{ row.reason_label }}</span>
                            <span v-if="row.status_key === 'in_care'" class="small text-muted">
                                {{ tx('attended_at', { time: row.attended_time, doctor: row.attended_by ?? '—' }) }}
                            </span>
                            <span v-else class="small text-muted">
                                {{ tx('closed_at', { time: row.closed_time, reason: row.closed_reason ?? '—' }) }}
                            </span>
                            <Link
                                v-if="canOpenRecord(row)"
                                :href="recordUrl(row)"
                                class="btn btn-sm btn-outline-primary ms-auto"
                                data-testid="uc-open-record"
                            >
                                <i class="ti ti-clipboard-text me-1" aria-hidden="true"></i>{{ t.action_open_record }}
                            </Link>
                        </li>
                    </ul>
                </details>
            </template>
        </div>

        <UrgentCareFormModal
            :open="formOpen"
            :entry="editing"
            :reasons="reasons"
            :priorities="priorities"
            :doctors="doctors"
            :covenants="covenants"
            :legal-priority-age="legalPriorityAge"
            :can-register-patient="canRegisterPatient"
            :queued-patient-ids="queuedPatientIds"
            :urls="urls"
            :t="t"
            @close="formOpen = false"
            @saved="onSaved"
        />

        <CenteredModal :open="closing !== null" size="sm" @close="closing = null">
            <template #header>
                <h5 class="mb-0">
                    {{ closing?.status === STATUS_LEFT ? t.close_left_title : t.close_removed_title }}
                </h5>
            </template>
            <p v-if="closing" class="fw-semibold mb-2">{{ closing.row.patient_name }}</p>
            <label class="form-label small mb-1" for="uc-close-reason">{{ t.field_close_reason }} *</label>
            <textarea
                id="uc-close-reason"
                v-model="closeReason"
                class="form-control"
                :class="{ 'is-invalid': closeError }"
                rows="3"
                maxlength="500"
                :placeholder="t.close_reason_ph"
                data-testid="uc-close-reason"
            ></textarea>
            <div v-if="closeError" class="invalid-feedback">{{ closeError }}</div>
            <template #footer>
                <button type="button" class="btn btn-light" @click="closing = null">{{ t.cancel }}</button>
                <button
                    type="button"
                    class="btn"
                    :class="closing?.status === STATUS_LEFT ? 'btn-warning' : 'btn-danger'"
                    :disabled="busyId !== null || closeReason.trim().length < 3"
                    data-testid="uc-close-confirm"
                    @click="confirmClose"
                >
                    {{ t.close_confirm }}
                </button>
            </template>
        </CenteredModal>
    </AppLayout>
</template>

<style scoped>
.uc-summary {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.uc-stat {
    display: inline-flex;
    align-items: baseline;
    gap: 0.4rem;
    padding: 0.4rem 0.8rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.5rem;
    background: var(--bs-body-bg);
}

.uc-stat__value {
    font-weight: 700;
    font-size: 1.1rem;
    font-variant-numeric: tabular-nums;
}

.uc-stat__label {
    font-size: 0.8rem;
    color: var(--bs-secondary-color);
}

.uc-stat--alert {
    border-color: #dc2626;
    border-left-width: 4px;
}

.uc-stat--alert .uc-stat__value {
    color: #b91c1c;
}

.uc-queue {
    margin: 0;
}

.uc-item {
    --uc-color: #6c757d;
    display: grid;
    grid-template-columns: 2.25rem minmax(0, 1fr) auto auto;
    gap: 0.75rem;
    align-items: center;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--bs-border-color);
    border-left: 4px solid var(--uc-color);
}

.uc-item:last-child {
    border-bottom: 0;
}

.uc-item--immediate {
    --uc-color: #dc2626;
    background: rgba(220, 38, 38, 0.04);
}

.uc-item--legal {
    --uc-color: #b45309;
}

.uc-pos {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    display: grid;
    place-items: center;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    background: var(--bs-tertiary-bg);
    color: var(--bs-body-color);
}

.uc-main {
    min-width: 0;
    display: grid;
    gap: 0.2rem;
}

.uc-line1 {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.4rem;
}

.uc-name {
    overflow-wrap: anywhere;
}

.uc-line3 {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem 0.9rem;
}

.uc-priority {
    font-weight: 600;
}

.uc-priority--immediate {
    background: #dc2626;
    color: #fff;
}

.uc-priority--legal {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
}

.uc-priority--normal {
    background: var(--bs-secondary-bg);
    color: var(--bs-body-color);
    border: 1px solid var(--bs-border-color);
}

.uc-called {
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #93c5fd;
}

.uc-wait {
    display: grid;
    justify-items: end;
    text-align: end;
}

.uc-wait__value {
    font-weight: 700;
    font-size: 1rem;
    font-variant-numeric: tabular-nums;
}

.uc-wait__label {
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
    white-space: nowrap;
}

.uc-actions {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.uc-card summary {
    cursor: pointer;
    list-style: none;
}

.uc-card summary::-webkit-details-marker {
    display: none;
}

.uc-card summary::before {
    content: '▸';
    color: var(--bs-secondary-color);
}

.uc-card[open] summary::before {
    content: '▾';
}

.uc-status--in_care {
    background: #dcfce7;
    color: #166534;
}

.uc-status--left {
    background: #fef3c7;
    color: #92400e;
}

.uc-status--removed {
    background: var(--bs-secondary-bg);
    color: var(--bs-body-color);
}

.uc-spin {
    display: inline-block;
    animation: uc-spin 1s linear infinite;
}

@keyframes uc-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .uc-spin {
        animation: none;
    }
}

/* Celular: posição + dados em cima; espera e ações embaixo. */
@media (max-width: 767.98px) {
    .uc-item {
        grid-template-columns: 2.25rem minmax(0, 1fr);
        gap: 0.5rem 0.75rem;
        padding: 0.75rem;
    }

    .uc-wait {
        grid-column: 2;
        justify-items: start;
        text-align: start;
        grid-auto-flow: column;
        gap: 0.5rem;
        align-items: baseline;
    }

    .uc-actions {
        grid-column: 1 / -1;
        flex-wrap: wrap;
    }

    .uc-actions .btn:not(.btn-light) {
        flex: 1 1 auto;
    }

    .uc-updated {
        width: 100%;
        margin-left: 0 !important;
    }
}

[data-bs-theme='dark'] .uc-item--immediate {
    --uc-color: #f87171;
    background: rgba(248, 113, 113, 0.08);
}

[data-bs-theme='dark'] .uc-item--legal {
    --uc-color: #fbbf24;
}

[data-bs-theme='dark'] .uc-priority--immediate {
    background: #b91c1c;
}

[data-bs-theme='dark'] .uc-priority--legal {
    background: rgba(251, 191, 36, 0.15);
    color: #fde68a;
    border-color: rgba(251, 191, 36, 0.45);
}

[data-bs-theme='dark'] .uc-called {
    background: rgba(59, 130, 246, 0.18);
    color: #bfdbfe;
    border-color: rgba(96, 165, 250, 0.45);
}

[data-bs-theme='dark'] .uc-stat--alert .uc-stat__value {
    color: #fca5a5;
}

[data-bs-theme='dark'] .uc-status--in_care {
    background: rgba(34, 197, 94, 0.18);
    color: #bbf7d0;
}

[data-bs-theme='dark'] .uc-status--left {
    background: rgba(251, 191, 36, 0.15);
    color: #fde68a;
}
</style>
