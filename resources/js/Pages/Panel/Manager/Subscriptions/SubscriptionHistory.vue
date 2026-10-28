<script setup>
import { computed, ref, watch } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { choice } from '@/utils/billingPeriods.js';

/**
 * Linha do tempo da empresa: cada assinatura que ela teve (período, plano,
 * modalidade) e cada alteração feita — antes → depois, quem, quando e por quê.
 */
const props = defineProps({
    subscriptionId: { type: String, default: null },
    billingCycles: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    // Muda quando uma ação é salva (recarrega o histórico).
    refreshKey: { type: Number, default: 0 },
    t: { type: Object, default: () => ({}) },
});

const { money, date, dateTime } = useLocaleFormat();
const { tx } = useTrans(() => props.t);

const loading = ref(false);
const failed = ref(false);
const history = ref({ subscriptions: [], events: [] });

async function load() {
    if (!props.subscriptionId) return;

    loading.value = true;
    failed.value = false;
    try {
        const res = await fetch(route('manager.subscriptions.history', props.subscriptionId), {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error(String(res.status));
        history.value = (await res.json()).data ?? { subscriptions: [], events: [] };
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(() => [props.subscriptionId, props.refreshKey], load, { immediate: true });

const EVENT_ICONS = {
    subscription_created: 'ti-file-plus text-primary',
    period_extended: 'ti-calendar-plus text-success',
    terms_changed: 'ti-adjustments-dollar text-info',
    activation: 'ti-credit-card text-success',
    cancelled: 'ti-ban text-danger',
    expired: 'ti-clock-off text-warning',
    upgrade: 'ti-trending-up text-success',
    upgrade_requested: 'ti-receipt text-primary',
    downgrade: 'ti-trending-down text-info',
    downgrade_scheduled: 'ti-calendar-event text-info',
    downgrade_cancelled: 'ti-arrow-back-up text-muted',
    gateway_recurrence_lost: 'ti-repeat-off text-warning',
    charge_notice_sent: 'ti-send text-primary',
};

const cycleLabels = computed(() => ({
    ...Object.fromEntries(props.billingCycles.map((c) => [c.value, c.label])),
}));
const statusLabels = computed(() => Object.fromEntries(props.statuses.map((s) => [s.value, s.label])));

function modalityOf(snapshot) {
    if (!snapshot) return null;
    if (!snapshot.billing_mode) return 'trial';

    return snapshot.billing_mode;
}

function format(field, value, snapshot) {
    if (value === null || value === undefined || value === '') {
        return field === 'ends_at' && snapshot && snapshot.status !== 'trial' ? props.t.history_no_end : '—';
    }

    switch (field) {
        case 'amount':
            return money(value);
        case 'starts_at':
        case 'ends_at':
        case 'trial_ends_at':
            return date(value);
        case 'billing_cycle':
            return cycleLabels.value[value] ?? value;
        case 'status':
            // Contratação aguardando o 1º pagamento não é "Em atraso".
            if (value === AWAITING_FIRST_PAYMENT) return props.t.status_awaiting_first_payment ?? value;

            return statusLabels.value[value] ?? value;
        case 'modality':
            return props.t.modality?.[value] ?? value;
        default:
            return value;
    }
}

const FIELDS = ['plan', 'modality', 'billing_cycle', 'amount', 'status', 'starts_at', 'ends_at', 'trial_ends_at'];

// Situação derivada do snapshot (marcada pelo backend), não um status do banco.
const AWAITING_FIRST_PAYMENT = 'awaiting_first_payment';

function fieldValue(snapshot, field) {
    if (!snapshot) return null;
    if (field === 'plan') return snapshot.plan_name;
    if (field === 'modality') return modalityOf(snapshot);
    if (field === 'status' && snapshot.awaiting_first_payment) return AWAITING_FIRST_PAYMENT;

    return snapshot[field] ?? null;
}

/** Campos que mudaram (antes → depois); criação lista as condições novas. */
function changes(event) {
    const before = event.previous;
    const after = event.new;
    if (!after) return [];

    if (event.type === 'subscription_created') {
        return ['plan', 'modality', 'billing_cycle', 'amount', after.status === 'trial' ? 'trial_ends_at' : 'ends_at']
            .filter((field) => fieldValue(after, field) !== null || field === 'ends_at')
            .map((field) => ({ field, to: format(field, fieldValue(after, field), after) }));
    }

    return FIELDS.filter((field) => fieldValue(before, field) !== fieldValue(after, field)).map((field) => ({
        field,
        from: format(field, fieldValue(before, field), before),
        to: format(field, fieldValue(after, field), after),
    }));
}

function eventTitle(event) {
    return props.t.history_event?.[event.type] ?? props.t.history_event?.other ?? event.type;
}

function extensionLabel(extension) {
    if (!extension) return '';

    return tx('history_extension', {
        quantity: extension.quantity,
        unit: choice(props.t.history_unit?.[extension.unit] ?? extension.unit, extension.quantity),
    });
}

// Sem autor gravado: o que veio do manager (registros antigos) não é "pelo sistema".
function actorLabel(event) {
    if (event.actor) return tx('history_by', { name: event.actor });

    return event.source === 'manager' ? props.t.history_by_manager : props.t.history_by_system;
}
</script>

<template>
    <div class="sub-history">
        <div v-if="loading" class="text-center py-4">
            <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
            <span class="ms-2 text-muted small">{{ t.loading }}</span>
        </div>

        <div v-else-if="failed" class="alert alert-danger small" role="alert">{{ t.request_failed }}</div>

        <template v-else>
            <!-- Assinaturas da empresa -->
            <section class="mb-4" aria-labelledby="history-subscriptions">
                <h6 id="history-subscriptions" class="sub-history__title">{{ t.history_subscriptions }}</h6>
                <ol class="list-unstyled mb-0 d-grid gap-2">
                    <li
                        v-for="item in history.subscriptions"
                        :key="item.id"
                        class="sub-history__contract"
                        :class="{ 'sub-history__contract--current': item.is_current }"
                    >
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <div>
                                <div class="fw-semibold">{{ item.plan_name }}</div>
                                <div class="small text-muted">
                                    {{ t.modality?.[item.modality] }}
                                    <template v-if="item.billing_cycle">
                                        · {{ cycleLabels[item.billing_cycle] }}
                                    </template>
                                    <template v-if="item.amount !== null"> · {{ money(item.amount) }}</template>
                                </div>
                            </div>
                            <div class="d-flex gap-1 flex-wrap">
                                <span v-if="item.is_current" class="badge badge-soft-primary">{{
                                    t.current_badge
                                }}</span>
                                <span class="badge badge-soft-secondary" data-test="history-status">{{
                                    item.status_label ?? statusLabels[item.status] ?? item.status
                                }}</span>
                            </div>
                        </div>
                        <div class="small mt-1">
                            <i class="ti ti-calendar me-1 text-muted" aria-hidden="true"></i>
                            {{ date(item.starts_at) }} →
                            {{ item.ends_at ? date(item.ends_at) : t.history_no_end }}
                        </div>
                    </li>
                </ol>
            </section>

            <!-- Alterações -->
            <section aria-labelledby="history-events">
                <h6 id="history-events" class="sub-history__title">{{ t.history_events }}</h6>
                <p v-if="!history.events.length" class="text-muted small">{{ t.empty_history }}</p>
                <ol v-else class="sub-history__timeline list-unstyled mb-0">
                    <li
                        v-for="event in history.events"
                        :key="event.id"
                        class="sub-history__event"
                        :data-type="event.type"
                    >
                        <span class="sub-history__dot" aria-hidden="true">
                            <i :class="['ti', EVENT_ICONS[event.type] ?? 'ti-point text-muted']"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="d-flex flex-wrap align-items-baseline gap-2">
                                <strong>{{ eventTitle(event) }}</strong>
                                <span v-if="event.extension" class="badge badge-soft-success">{{
                                    extensionLabel(event.extension)
                                }}</span>
                            </div>
                            <div class="small text-muted">
                                <time :datetime="event.at">{{ dateTime(event.at) }}</time> · {{ actorLabel(event) }}
                            </div>

                            <ul v-if="changes(event).length" class="sub-history__changes list-unstyled small mb-0 mt-1">
                                <li v-for="change in changes(event)" :key="change.field">
                                    <span class="text-muted">{{ t.history_field?.[change.field] }}:</span>
                                    <template v-if="change.from !== undefined">
                                        <span class="text-decoration-line-through text-muted ms-1">{{
                                            change.from
                                        }}</span>
                                        <i class="ti ti-arrow-right mx-1" aria-hidden="true"></i>
                                    </template>
                                    <span class="fw-medium" :class="{ 'ms-1': change.from === undefined }">{{
                                        change.to
                                    }}</span>
                                </li>
                            </ul>

                            <div v-if="event.gateway_cancelled" class="small text-warning-emphasis mt-1">
                                <i class="ti ti-credit-card-off me-1" aria-hidden="true"></i
                                >{{ t.history_gateway_cancelled }}
                            </div>

                            <blockquote v-if="event.reason" class="sub-history__reason small mb-0 mt-1">
                                <span class="visually-hidden">{{ t.history_reason }}: </span>{{ event.reason }}
                            </blockquote>
                        </div>
                    </li>
                </ol>
            </section>
        </template>
    </div>
</template>

<style scoped>
.sub-history__title {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.sub-history__contract {
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    font-size: 0.875rem;
}
.sub-history__contract--current {
    border-color: var(--bs-primary);
    box-shadow: inset 3px 0 0 var(--bs-primary);
}
.sub-history__timeline {
    position: relative;
    display: grid;
    gap: 1rem;
}
.sub-history__timeline::before {
    content: '';
    position: absolute;
    top: 0.5rem;
    bottom: 0.5rem;
    left: 0.75rem;
    border-left: 2px solid var(--bs-border-color);
}
.sub-history__event {
    position: relative;
    display: grid;
    grid-template-columns: 1.5rem 1fr;
    gap: 0.75rem;
    font-size: 0.875rem;
}
.sub-history__dot {
    position: relative;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 50%;
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
}
.sub-history__reason {
    padding: 0.375rem 0.625rem;
    border-left: 3px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
    border-radius: 0 var(--bs-border-radius-sm) var(--bs-border-radius-sm) 0;
    white-space: pre-line;
}
</style>
