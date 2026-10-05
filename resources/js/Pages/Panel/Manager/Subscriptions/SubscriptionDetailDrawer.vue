<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import BillingStateBadge from '@/Components/Panel/BillingStateBadge.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { choice } from '@/utils/billingPeriods.js';
import SubscriptionHistory from './SubscriptionHistory.vue';
import ReasonField from '@/Components/Panel/ReasonField.vue';
import { useSubscriptionPresenter } from './useSubscriptionPresenter.js';

const props = defineProps({
    open: { type: Boolean, required: true },
    subscriptionId: { type: String, default: null },
    billingCycles: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    canManagePlans: { type: Boolean, default: false },
    // Incrementado pela página depois de salvar uma ação: recarrega os dados.
    refreshKey: { type: Number, default: 0 },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'extend', 'change', 'newFor', 'cancel', 'block', 'updated']);

const { money, date, dateTime } = useLocaleFormat();
const { tx } = useTrans(() => props.t);
const { tx: txCharge } = useTrans(() => props.t.charge_notice ?? {});
const { tx: txRecurrence } = useTrans(() => props.t.recurrence_lost ?? {});
const { amountText, monthlyText, accessText, dunningText, extendDisabledReason, changeDisabledReason } =
    useSubscriptionPresenter(
        () => props.t,
        () => props.billingCycles,
    );

const loading = ref(false);
const subscription = ref(null);
const activeTab = ref('overview');

const invoices = ref([]);
const retries = ref([]);
const invoicesLoading = ref(false);
const retriesLoading = ref(false);
const invoicesLoaded = ref(false);
const retriesLoaded = ref(false);

async function getJson(url) {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });

    return (await res.json()).data;
}

async function loadDetail(id, { keepTab = false } = {}) {
    loading.value = !keepTab;
    if (!keepTab) {
        subscription.value = null;
        activeTab.value = 'overview';
        undo.value = { open: false, reason: '', busy: false, error: '', done: '' };
        charge.value = { id: null, busy: false, error: '', done: '' };
        ack.value = { busy: false, error: '' };
    }
    invoices.value = [];
    retries.value = [];
    invoicesLoaded.value = false;
    retriesLoaded.value = false;

    try {
        subscription.value = await getJson(route('manager.subscriptions.show', id));
        if (activeTab.value === 'invoices') loadInvoices();
        if (activeTab.value === 'retries') loadRetries();
    } finally {
        loading.value = false;
    }
}

async function loadInvoices() {
    if (invoicesLoaded.value) return;
    invoicesLoading.value = true;
    try {
        invoices.value = (await getJson(route('manager.subscriptions.invoices', props.subscriptionId))) ?? [];
        invoicesLoaded.value = true;
    } finally {
        invoicesLoading.value = false;
    }
}

async function loadRetries() {
    if (retriesLoaded.value) return;
    retriesLoading.value = true;
    try {
        retries.value = (await getJson(route('manager.subscriptions.retries', props.subscriptionId))) ?? [];
        retriesLoaded.value = true;
    } finally {
        retriesLoading.value = false;
    }
}

function switchTab(tab) {
    activeTab.value = tab;
    if (tab === 'invoices') loadInvoices();
    if (tab === 'retries') loadRetries();
}

watch(
    () => props.open,
    (val) => {
        if (val && props.subscriptionId) loadDetail(props.subscriptionId);
        if (!val) subscription.value = null;
    },
);

watch(
    () => props.refreshKey,
    () => {
        if (props.open && props.subscriptionId) loadDetail(props.subscriptionId, { keepTab: true });
    },
);

// ── Mudança de plano agendada: desfazer antes da data (com justificativa) ────
const undo = ref({ open: false, reason: '', busy: false, error: '', done: '' });
const undoReasonField = ref(null);

function scheduledText(change) {
    return tx('scheduled_change_value', {
        plan: change.plan_name ?? '',
        cycle: change.cycle_label ?? '',
        date: date(change.effective_at),
        amount: money(change.amount),
    });
}

async function cancelScheduled() {
    if (undo.value.busy || !(undoReasonField.value?.valid ?? false)) return;

    undo.value = { ...undo.value, busy: true, error: '' };
    try {
        const { data } = await window.axios.post(
            route('manager.subscriptions.scheduled-change.cancel', props.subscriptionId),
            { reason: undo.value.reason },
            { headers: { Accept: 'application/json' } },
        );
        undo.value = { open: false, reason: '', busy: false, error: '', done: data?.message ?? '' };
        await loadDetail(props.subscriptionId, { keepTab: true });
    } catch (e) {
        undo.value = { ...undo.value, busy: false, error: e?.response?.data?.message ?? props.t.request_failed };
    }
}

// ── Recorrência desativada pelo gateway: marcar o aviso como visto ─────────
const ack = ref({ busy: false, error: '' });

async function acknowledgeRecurrence() {
    if (ack.value.busy) return;

    ack.value = { busy: true, error: '' };
    try {
        await window.axios.post(
            route('manager.subscriptions.recurrence-alert.acknowledge', props.subscriptionId),
            {},
            { headers: { Accept: 'application/json' } },
        );
        ack.value = { busy: false, error: '' };
        await loadDetail(props.subscriptionId, { keepTab: true });
        emit('updated');
    } catch (e) {
        ack.value = { busy: false, error: e?.response?.data?.message ?? props.t.request_failed };
    }
}

// ── "Enviar cobrança à clínica" (e-mail + WhatsApp com o link do sistema) ──
const charge = ref({ id: null, busy: false, error: '', done: '' });

function channelsText(channels) {
    return (channels ?? []).map((c) => props.t.charge_notice?.channel?.[c] ?? c).join(', ');
}

function lastNoticeText(notice) {
    if (!notice?.at) return '';

    return notice.by
        ? txCharge('last_sent', {
              date: dateTime(notice.at),
              name: notice.by,
              channels: channelsText(notice.channels),
          })
        : txCharge('last_sent_system', { date: dateTime(notice.at), channels: channelsText(notice.channels) });
}

function openInvoiceText(invoice) {
    return txCharge('open_value', {
        reference: invoice.reference ?? '',
        amount: money(invoice.amount, invoice.currency || 'BRL'),
        date: date(invoice.due_at),
    });
}

async function sendCharge(invoiceId) {
    if (charge.value.busy || !invoiceId) return;

    charge.value = { id: invoiceId, busy: true, error: '', done: '' };
    try {
        const { data } = await window.axios.post(
            route('manager.subscriptions.invoices.send-charge', {
                subscription: props.subscriptionId,
                invoice: invoiceId,
            }),
            {},
            { headers: { Accept: 'application/json' } },
        );
        charge.value = { id: invoiceId, busy: false, error: '', done: data?.message ?? '' };
        invoicesLoaded.value = false;
        await loadDetail(props.subscriptionId, { keepTab: true });
    } catch (e) {
        charge.value = {
            id: invoiceId,
            busy: false,
            error: e?.response?.data?.message ?? props.t.request_failed,
            done: '',
        };
    }
}

const tabs = computed(() => [
    { key: 'overview', icon: 'ti-info-circle', label: props.t.tab_overview },
    { key: 'history', icon: 'ti-timeline', label: props.t.tab_history },
    { key: 'invoices', icon: 'ti-receipt', label: props.t.tab_invoices },
    { key: 'retries', icon: 'ti-refresh-alert', label: props.t.tab_retries },
]);
</script>

<template>
    <OffcanvasPanel :open="open" :width="560" :loading="loading" :loading-label="t.loading" @close="$emit('close')">
        <!-- Header -->
        <template #header>
            <div class="flex-grow-1 min-w-0">
                <h5 class="mb-0 fw-semibold text-truncate">
                    <i class="ti ti-file-invoice me-2 text-primary" aria-hidden="true"></i>
                    {{ subscription?.entity_name ?? t.loading }}
                </h5>
                <div v-if="subscription" class="mt-1 d-flex flex-wrap gap-1 align-items-center">
                    <span class="badge" :class="subscription.status_badge">{{ subscription.status_label }}</span>
                    <span class="badge badge-soft-primary">{{ t.modality?.[subscription.modality] }}</span>
                    <span v-if="!subscription.is_current" class="badge badge-soft-secondary">{{
                        t.historical_badge
                    }}</span>
                    <span v-if="!subscription.entity_active" class="badge badge-soft-danger">{{
                        t.blocked_badge
                    }}</span>
                    <span
                        v-if="subscription.needs_reconciliation"
                        class="badge badge-soft-info"
                        :title="t.needs_review_hint"
                        data-test="sdd-needs-review"
                        >{{ t.needs_review_badge }}</span
                    >
                    <span
                        v-if="subscription.recurrence_alert"
                        class="badge badge-soft-warning"
                        :title="t.recurrence_alert_hint"
                        data-test="sdd-recurrence-alert"
                        >{{ t.recurrence_alert_badge }}</span
                    >
                    <BillingStateBadge
                        v-if="subscription.billing_state"
                        :badge="subscription.billing_state_badge"
                        :label="subscription.billing_state_label"
                        :state="subscription.billing_state"
                    />
                </div>
            </div>
        </template>

        <!-- Tabs -->
        <template #tabs>
            <ul class="nav nav-tabs border-0" role="tablist">
                <li v-for="tab in tabs" :key="tab.key" class="nav-item" role="presentation">
                    <button
                        type="button"
                        class="nav-link"
                        role="tab"
                        :aria-selected="activeTab === tab.key"
                        :class="{ active: activeTab === tab.key }"
                        @click="switchTab(tab.key)"
                    >
                        <i :class="['ti me-1', tab.icon]" aria-hidden="true"></i>{{ tab.label }}
                    </button>
                </li>
            </ul>
        </template>

        <!-- Body -->
        <template v-if="subscription">
            <!-- ── Visão geral ─────────────────────────────────────────────── -->
            <div v-show="activeTab === 'overview'">
                <!-- O gateway desativou a recorrência: a cobrança passou para o sistema. -->
                <section
                    v-if="subscription.recurrence_lost"
                    class="alert alert-warning text-warning-emphasis small mb-3"
                    role="alert"
                    aria-labelledby="sdd-recurrence-lost"
                    data-test="sdd-recurrence-lost"
                >
                    <h6 id="sdd-recurrence-lost" class="fw-semibold mb-1">
                        <i class="ti ti-repeat-off me-1" aria-hidden="true"></i>{{ t.recurrence_lost?.title }}
                    </h6>
                    <p class="mb-1">
                        {{
                            txRecurrence('body', {
                                gateway: (subscription.recurrence_lost.gateway ?? '').toUpperCase(),
                                date: dateTime(subscription.recurrence_lost.at),
                            })
                        }}
                    </p>
                    <ul class="mb-2 ps-3">
                        <li v-if="subscription.recurrence_lost.access_until">
                            {{
                                txRecurrence('access_until', { date: date(subscription.recurrence_lost.access_until) })
                            }}
                        </li>
                        <li v-if="subscription.recurrence_lost.next_billing_at">
                            {{ txRecurrence('next', { date: date(subscription.recurrence_lost.next_billing_at) }) }}
                        </li>
                        <li v-if="subscription.recurrence_lost.gateway_event">
                            {{ txRecurrence('event', { event: subscription.recurrence_lost.gateway_event }) }}
                        </li>
                    </ul>
                    <p v-if="ack.error" class="text-danger mb-2" role="alert">{{ ack.error }}</p>
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        :disabled="ack.busy"
                        data-test="sdd-recurrence-ack"
                        @click="acknowledgeRecurrence"
                    >
                        <span v-if="ack.busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-eye-check me-1" aria-hidden="true"></i
                        >{{ t.recurrence_lost?.acknowledge }}
                    </button>
                </section>

                <!-- Fatura em aberto (upgrade primeiro): enviar a cobrança à clínica. -->
                <section
                    v-if="subscription.open_invoice"
                    class="alert alert-light border small mb-3"
                    aria-labelledby="sdd-open-invoice"
                    data-test="sdd-open-invoice"
                >
                    <h6 id="sdd-open-invoice" class="fw-semibold mb-1">
                        <i class="ti ti-receipt me-1 text-primary" aria-hidden="true"></i
                        >{{
                            subscription.open_invoice.plan_change
                                ? t.charge_notice?.open_plan_change
                                : t.charge_notice?.open_title
                        }}
                    </h6>
                    <div>{{ openInvoiceText(subscription.open_invoice) }}</div>
                    <div v-if="subscription.open_invoice.last_notice" class="text-body-secondary">
                        {{ lastNoticeText(subscription.open_invoice.last_notice) }}
                    </div>
                    <p
                        v-if="charge.id === subscription.open_invoice.id && charge.done"
                        class="text-success mb-0 mt-1"
                        role="status"
                        data-test="sdd-charge-sent"
                    >
                        {{ charge.done }}
                    </p>
                    <p
                        v-if="charge.id === subscription.open_invoice.id && charge.error"
                        class="text-danger mb-0 mt-1"
                        role="alert"
                        data-test="sdd-charge-error"
                    >
                        {{ charge.error }}
                    </p>
                    <button
                        type="button"
                        class="btn btn-sm btn-primary mt-2"
                        :disabled="charge.busy"
                        :title="t.charge_notice?.button_hint"
                        data-test="sdd-send-charge"
                        @click="sendCharge(subscription.open_invoice.id)"
                    >
                        <span
                            v-if="charge.busy && charge.id === subscription.open_invoice.id"
                            class="spinner-border spinner-border-sm me-1"
                            aria-hidden="true"
                        ></span>
                        <i v-else class="ti ti-send me-1" aria-hidden="true"></i>{{ t.charge_notice?.button }}
                    </button>
                </section>

                <section class="sdd-summary mb-3" aria-labelledby="sdd-current">
                    <h6 id="sdd-current" class="sdd-section__title">{{ t.section_current }}</h6>
                    <dl class="sdd-grid mb-0">
                        <dt>{{ t.detail_plan }}</dt>
                        <dd>
                            <Link
                                v-if="canManagePlans"
                                :href="route('manager.plans.index', { search: subscription.plan_name })"
                                :title="t.action_view_plan"
                                >{{ subscription.plan_name }}</Link
                            >
                            <template v-else>{{ subscription.plan_name }}</template>
                        </dd>

                        <dt>{{ t.detail_modality }}</dt>
                        <dd>{{ t.modality?.[subscription.modality] }}</dd>

                        <template v-if="subscription.billing_cycle_label">
                            <dt>{{ t.detail_cycle }}</dt>
                            <dd>{{ subscription.billing_cycle_label }}</dd>
                        </template>

                        <dt>{{ t.detail_amount }}</dt>
                        <dd>
                            {{ amountText(subscription) }}
                            <small v-if="monthlyText(subscription)" class="text-muted d-block">{{
                                monthlyText(subscription)
                            }}</small>
                        </dd>

                        <dt>{{ t.detail_access_until }}</dt>
                        <dd>
                            {{ subscription.open_ended ? t.period_no_end : date(subscription.access_ends_at) }}
                            <small
                                v-if="accessText(subscription)"
                                class="d-block"
                                :class="subscription.days_left < 0 ? 'text-danger' : 'text-muted'"
                                >{{ accessText(subscription) }}</small
                            >
                            <small
                                v-if="dunningText(subscription)"
                                class="d-block text-danger"
                                data-test="sdd-dunning"
                                >{{ dunningText(subscription) }}</small
                            >
                        </dd>

                        <dt>{{ t.col_period }}</dt>
                        <dd>
                            {{ date(subscription.starts_at) }} →
                            {{ subscription.open_ended ? t.period_no_end : date(subscription.access_ends_at) }}
                        </dd>
                    </dl>
                </section>

                <!-- Mudança de plano agendada (downgrade) — desfazer antes da data -->
                <p v-if="undo.done" class="alert alert-success small py-2" role="status">{{ undo.done }}</p>
                <section
                    v-if="subscription.scheduled_change"
                    class="alert alert-info text-info-emphasis small mb-3"
                    aria-labelledby="sdd-scheduled"
                    data-test="sdd-scheduled-change"
                >
                    <h6 id="sdd-scheduled" class="fw-semibold mb-1">
                        <i class="ti ti-calendar-event me-1" aria-hidden="true"></i>{{ t.scheduled_change_title }}
                    </h6>
                    <div>{{ scheduledText(subscription.scheduled_change) }}</div>
                    <div class="text-body-secondary">
                        {{
                            tx('scheduled_change_source', {
                                source:
                                    t.scheduled_change_source_label?.[subscription.scheduled_change.source] ??
                                    subscription.scheduled_change.source,
                            })
                        }}
                        <template v-if="subscription.scheduled_change.justification">
                            · “{{ subscription.scheduled_change.justification }}”</template
                        >
                    </div>
                    <template v-if="subscription.scheduled_change.can_cancel">
                        <button
                            v-if="!undo.open"
                            type="button"
                            class="btn btn-sm btn-outline-secondary mt-2"
                            data-test="sdd-scheduled-undo"
                            @click="undo = { ...undo, open: true, error: '', done: '' }"
                        >
                            <i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>{{ t.scheduled_change_cancel }}
                        </button>
                        <div v-else class="mt-2 bg-body p-2 rounded border">
                            <p class="mb-2 fw-medium">{{ t.scheduled_change_cancel_title }}</p>
                            <p class="text-muted mb-2">{{ t.scheduled_change_cancel_hint }}</p>
                            <ReasonField
                                ref="undoReasonField"
                                v-model="undo.reason"
                                :label="t.field_reason"
                                :hint="t.reason_hint"
                                :placeholder="t.reason_placeholder"
                                :error="undo.error"
                            />
                            <div class="d-flex gap-2 mt-2">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-light"
                                    :disabled="undo.busy"
                                    @click="undo = { ...undo, open: false, reason: '', error: '' }"
                                >
                                    {{ t.btn_cancel }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-primary"
                                    :disabled="undo.busy || !(undoReasonField?.valid ?? false)"
                                    data-test="sdd-scheduled-undo-confirm"
                                    @click="cancelScheduled"
                                >
                                    <span
                                        v-if="undo.busy"
                                        class="spinner-border spinner-border-sm me-1"
                                        aria-hidden="true"
                                    ></span
                                    >{{ t.scheduled_change_cancel }}
                                </button>
                            </div>
                        </div>
                    </template>
                </section>

                <!-- Ações -->
                <section class="mb-4" aria-labelledby="sdd-actions">
                    <h6 id="sdd-actions" class="sdd-section__title">{{ t.section_actions }}</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <span :title="extendDisabledReason(subscription) || undefined">
                            <button
                                type="button"
                                class="btn btn-sm btn-primary"
                                :disabled="!subscription.can_extend"
                                @click="$emit('extend', subscription)"
                            >
                                <i class="ti ti-calendar-plus me-1" aria-hidden="true"></i>{{ t.action_extend }}
                            </button>
                        </span>
                        <span :title="changeDisabledReason(subscription) || undefined">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                :disabled="!subscription.can_change_terms"
                                @click="$emit('change', subscription)"
                            >
                                <i class="ti ti-adjustments-dollar me-1" aria-hidden="true"></i>{{ t.action_change }}
                            </button>
                        </span>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            @click="$emit('newFor', subscription)"
                        >
                            <i class="ti ti-file-plus me-1" aria-hidden="true"></i>{{ t.action_new_for_company }}
                        </button>
                        <button
                            v-if="
                                subscription.is_current &&
                                (subscription.is_accessible || subscription.needs_reconciliation)
                            "
                            type="button"
                            class="btn btn-sm btn-outline-warning"
                            @click="$emit('cancel', subscription)"
                        >
                            <i class="ti ti-ban me-1" aria-hidden="true"></i>{{ t.action_cancel }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-danger"
                            @click="$emit('block', subscription)"
                        >
                            <i
                                :class="['ti me-1', subscription.entity_active ? 'ti-lock' : 'ti-lock-open']"
                                aria-hidden="true"
                            ></i>
                            {{ subscription.entity_active ? t.action_block : t.action_unblock }}
                        </button>
                    </div>
                </section>

                <!-- Régua de cobrança: avisos já enviados -->
                <section
                    v-if="subscription.modality === 'gateway'"
                    class="mb-3"
                    aria-labelledby="sdd-dunning"
                    data-test="sdd-dunning-steps"
                >
                    <h6 id="sdd-dunning" class="sdd-section__title">{{ t.section_dunning }}</h6>
                    <p v-if="!subscription.dunning_steps?.length" class="small text-muted mb-0">
                        {{ t.dunning_none }}
                    </p>
                    <ul v-else class="list-unstyled small mb-0">
                        <li
                            v-for="step in subscription.dunning_steps"
                            :key="`${step.step}-${step.due_on}`"
                            class="mb-1"
                        >
                            <span class="fw-medium">{{ step.label }}</span>
                            <span class="text-muted">
                                · {{ tx('dunning_due_on', { date: date(step.due_on) }) }} · {{ dateTime(step.at) }} ·
                                {{ choice(t.dunning_sent_to, step.recipients_count, { count: step.recipients_count }) }}
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- Gateway -->
                <section v-if="subscription.gateway" class="mb-3" aria-labelledby="sdd-gateway">
                    <h6 id="sdd-gateway" class="sdd-section__title">{{ t.section_gateway }}</h6>
                    <dl class="sdd-grid mb-0">
                        <dt>{{ t.detail_gateway }}</dt>
                        <dd>
                            <span class="badge badge-soft-primary text-uppercase">{{ subscription.gateway }}</span>
                        </dd>
                        <template v-if="subscription.gateway_customer_id">
                            <dt>{{ t.detail_gateway_customer_id }}</dt>
                            <dd>
                                <code class="small">{{ subscription.gateway_customer_id }}</code>
                            </dd>
                        </template>
                        <template v-if="subscription.gateway_subscription_id">
                            <dt>{{ t.detail_gateway_subscription_id }}</dt>
                            <dd>
                                <code class="small">{{ subscription.gateway_subscription_id }}</code>
                            </dd>
                        </template>
                        <template v-if="subscription.last_billing_error">
                            <dt>{{ t.detail_billing_error }}</dt>
                            <dd>
                                <div class="alert alert-danger py-1 px-2 small mb-0">
                                    {{ subscription.last_billing_error }}
                                </div>
                            </dd>
                        </template>
                    </dl>
                </section>

                <!-- Datas -->
                <section aria-labelledby="sdd-dates">
                    <h6 id="sdd-dates" class="sdd-section__title">{{ t.section_dates }}</h6>
                    <dl class="sdd-grid mb-0">
                        <template v-if="subscription.trial_ends_at">
                            <dt>{{ t.detail_trial_ends_at }}</dt>
                            <dd>{{ date(subscription.trial_ends_at) }}</dd>
                        </template>
                        <template v-if="subscription.last_payment_at">
                            <dt>{{ t.detail_last_payment }}</dt>
                            <dd>{{ dateTime(subscription.last_payment_at) }}</dd>
                        </template>
                        <template v-if="subscription.cancelled_at">
                            <dt>{{ t.detail_cancelled_at }}</dt>
                            <dd>{{ dateTime(subscription.cancelled_at) }}</dd>
                        </template>
                        <dt>{{ t.detail_created_at }}</dt>
                        <dd>{{ dateTime(subscription.created_at) }}</dd>
                    </dl>
                </section>
            </div>

            <!-- ── Histórico ──────────────────────────────────────────────── -->
            <div v-if="activeTab === 'history'">
                <SubscriptionHistory
                    :subscription-id="subscription.id"
                    :billing-cycles="billingCycles"
                    :statuses="statuses"
                    :refresh-key="refreshKey"
                    :t="t"
                />
            </div>

            <!-- ── Faturas ────────────────────────────────────────────────── -->
            <div v-show="activeTab === 'invoices'">
                <div v-if="invoicesLoading" class="text-center py-4">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <span class="ms-2 text-muted small">{{ t.loading }}</span>
                </div>

                <template v-else>
                    <div v-if="invoices.length === 0" class="text-center py-5 text-muted">
                        <i class="ti ti-receipt fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>
                        {{ t.empty_invoices }}
                    </div>

                    <div v-for="inv in invoices" :key="inv.id" class="card mb-2">
                        <div class="card-header py-2 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold small">{{ inv.reference }}</span>
                                <span class="badge" :class="inv.status_badge">{{ inv.status_label }}</span>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <span class="fw-bold text-success">{{ money(inv.amount, inv.currency || 'BRL') }}</span>
                                <span v-if="inv.gateway_code" class="badge badge-soft-primary ms-1 text-uppercase">{{
                                    inv.gateway_code
                                }}</span>
                            </div>
                        </div>
                        <div class="card-body py-2 small text-muted">
                            <div class="d-flex flex-wrap gap-3">
                                <span v-if="inv.period_start">
                                    <i class="ti ti-calendar me-1" aria-hidden="true"></i>{{ t.invoice_period }}
                                    {{ date(inv.period_start) }} – {{ date(inv.period_end) }}
                                </span>
                                <span v-if="inv.due_at">
                                    <i class="ti ti-clock me-1" aria-hidden="true"></i>{{ t.invoice_due_at }}
                                    {{ date(inv.due_at) }}
                                </span>
                                <span v-if="inv.paid_at" class="text-success">
                                    <i class="ti ti-check me-1" aria-hidden="true"></i>{{ t.invoice_paid_at }}
                                    {{ dateTime(inv.paid_at) }}
                                </span>
                            </div>

                            <div
                                v-if="inv.can_send_charge || inv.last_charge_notice"
                                class="mt-2 d-flex flex-wrap align-items-center gap-2"
                                data-test="sdd-invoice-charge"
                            >
                                <button
                                    v-if="inv.can_send_charge"
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    :disabled="charge.busy"
                                    :title="t.charge_notice?.button_hint"
                                    data-test="sdd-invoice-send-charge"
                                    @click="sendCharge(inv.id)"
                                >
                                    <span
                                        v-if="charge.busy && charge.id === inv.id"
                                        class="spinner-border spinner-border-sm me-1"
                                        aria-hidden="true"
                                    ></span>
                                    <i v-else class="ti ti-send me-1" aria-hidden="true"></i
                                    >{{ t.charge_notice?.button }}
                                </button>
                                <span v-if="inv.last_charge_notice">{{ lastNoticeText(inv.last_charge_notice) }}</span>
                                <span v-if="charge.id === inv.id && charge.done" class="text-success" role="status">{{
                                    charge.done
                                }}</span>
                                <span v-if="charge.id === inv.id && charge.error" class="text-danger" role="alert">{{
                                    charge.error
                                }}</span>
                            </div>

                            <div v-if="inv.payments && inv.payments.length > 0" class="mt-2 border-top pt-2">
                                <div class="fw-semibold text-body mb-1">{{ t.invoice_payments }}</div>
                                <div
                                    v-for="pay in inv.payments"
                                    :key="pay.id"
                                    class="d-flex align-items-center justify-content-between py-1 border-bottom"
                                >
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="badge" :class="pay.status_badge">{{ pay.status }}</span>
                                        <span class="text-uppercase">{{ pay.gateway_code }}</span>
                                        <code v-if="pay.external_payment_id" class="small">{{
                                            pay.external_payment_id
                                        }}</code>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-semibold">{{ money(pay.amount, pay.currency || 'BRL') }}</div>
                                        <div v-if="pay.paid_at" class="text-success small">
                                            <i class="ti ti-check me-1" aria-hidden="true"></i
                                            >{{ dateTime(pay.paid_at) }}
                                        </div>
                                        <div v-if="pay.failed_at" class="text-danger small">
                                            <i class="ti ti-x me-1" aria-hidden="true"></i>{{ dateTime(pay.failed_at) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ── Retentativas ───────────────────────────────────────────── -->
            <div v-show="activeTab === 'retries'">
                <div v-if="retriesLoading" class="text-center py-4">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <span class="ms-2 text-muted small">{{ t.loading }}</span>
                </div>

                <template v-else>
                    <div v-if="retries.length === 0" class="text-center py-5 text-muted">
                        <i class="ti ti-refresh-alert fs-2 d-block mb-2 opacity-50" aria-hidden="true"></i>
                        {{ t.empty_retries }}
                    </div>

                    <div v-else class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>{{ t.retry_col_attempt }}</th>
                                    <th>{{ t.retry_col_status }}</th>
                                    <th>{{ t.retry_col_gateway }}</th>
                                    <th>{{ t.retry_col_scheduled }}</th>
                                    <th>{{ t.retry_col_executed }}</th>
                                    <th>{{ t.retry_col_result }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="retry in retries" :key="retry.id">
                                    <td class="text-center fw-bold">#{{ retry.attempt_number }}</td>
                                    <td>
                                        <span class="badge" :class="retry.status_badge">{{ retry.status }}</span>
                                    </td>
                                    <td class="text-uppercase small">{{ retry.gateway_code ?? '—' }}</td>
                                    <td class="small">{{ dateTime(retry.scheduled_for) }}</td>
                                    <td class="small">{{ dateTime(retry.executed_at) }}</td>
                                    <td class="small text-muted">{{ retry.result_message ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.sdd-section__title {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.sdd-grid {
    display: grid;
    grid-template-columns: minmax(120px, 40%) 1fr;
    gap: 0.375rem 0.75rem;
    font-size: 0.875rem;
}
.sdd-grid dt {
    font-weight: 600;
}
.sdd-grid dd {
    margin: 0;
    color: var(--bs-secondary-color);
    word-break: break-word;
}
</style>
