<script setup>
import { ref, watch } from 'vue';
import OffcanvasPanel    from '@/Components/Panel/OffcanvasPanel.vue';
import BillingStateBadge from '@/Components/Panel/BillingStateBadge.vue';

const props = defineProps({
    open:           { type: Boolean, required: true },
    subscriptionId: { type: String,  default: null },
    t:              { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'edit']);

const loading       = ref(false);
const subscription  = ref(null);
const activeTab     = ref('overview');

// Lazy-load invoices & retries
const invoices        = ref([]);
const retries         = ref([]);
const invoicesLoading = ref(false);
const retriesLoading  = ref(false);
const invoicesLoaded  = ref(false);
const retriesLoaded   = ref(false);

async function loadDetail(id) {
    loading.value = true;
    subscription.value = null;
    activeTab.value    = 'overview';
    invoices.value     = [];
    retries.value      = [];
    invoicesLoaded.value = false;
    retriesLoaded.value  = false;

    try {
        const res  = await fetch(route('panel.manager.subscriptions.show', id));
        const json = await res.json();
        subscription.value = json.data;
    } finally {
        loading.value = false;
    }
}

async function loadInvoices() {
    if (invoicesLoaded.value) return;
    invoicesLoading.value = true;
    try {
        const res  = await fetch(route('panel.manager.subscriptions.invoices', props.subscriptionId));
        const json = await res.json();
        invoices.value      = json.data ?? [];
        invoicesLoaded.value = true;
    } finally {
        invoicesLoading.value = false;
    }
}

async function loadRetries() {
    if (retriesLoaded.value) return;
    retriesLoading.value = true;
    try {
        const res  = await fetch(route('panel.manager.subscriptions.retries', props.subscriptionId));
        const json = await res.json();
        retries.value      = json.data ?? [];
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

watch(() => props.open, (val) => {
    if (val && props.subscriptionId) loadDetail(props.subscriptionId);
    if (!val) subscription.value = null;
});
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="520"
        :loading="loading"
        :loading-label="t.loading"
        @close="$emit('close')"
    >
        <!-- Header -->
        <template #header>
            <div class="flex-grow-1 min-w-0">
                <h5 class="mb-0 fw-semibold text-truncate">
                    <i class="fas fa-file-contract me-2 text-primary"></i>
                    {{ subscription?.entity_name ?? t.loading }}
                </h5>
                <div v-if="subscription" class="mt-1 d-flex flex-wrap gap-1 align-items-center">
                    <span class="badge" :class="subscription.status_badge">{{ subscription.status_label }}</span>
                    <BillingStateBadge
                        v-if="subscription.billing_state"
                        :badge="subscription.billing_state_badge"
                        :label="subscription.billing_state_label"
                    />
                </div>
            </div>
            <button v-if="subscription" class="btn btn-sm btn-outline-primary flex-shrink-0 ms-2" @click="$emit('edit', subscription.id)">
                <i class="ti ti-edit me-1"></i> {{ t.detail_btn_edit }}
            </button>
        </template>

        <!-- Tabs -->
        <template #tabs>
            <ul class="nav nav-tabs border-0">
                <li class="nav-item">
                    <button
                        class="nav-link"
                        :class="{ active: activeTab === 'overview' }"
                        @click="switchTab('overview')"
                    >
                        <i class="ti ti-info-circle me-1"></i>{{ t.tab_overview }}
                    </button>
                </li>
                <li class="nav-item">
                    <button
                        class="nav-link"
                        :class="{ active: activeTab === 'invoices' }"
                        @click="switchTab('invoices')"
                    >
                        <i class="ti ti-receipt me-1"></i>{{ t.tab_invoices }}
                    </button>
                </li>
                <li class="nav-item">
                    <button
                        class="nav-link"
                        :class="{ active: activeTab === 'retries' }"
                        @click="switchTab('retries')"
                    >
                        <i class="ti ti-refresh-alert me-1"></i>{{ t.tab_retries }}
                    </button>
                </li>
            </ul>
        </template>

        <!-- Body -->
        <template v-if="subscription">

            <!-- ── Tab: Visão Geral ──────────────────────────────────────────── -->
            <div v-show="activeTab === 'overview'">

                <!-- Empresa / Plano -->
                <div class="sdd-section">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr>
                                <th class="ps-0" style="width:45%;">{{ t.detail_entity }}</th>
                                <td>{{ subscription.entity_name }}</td>
                            </tr>
                            <tr>
                                <th class="ps-0">{{ t.detail_plan }}</th>
                                <td>{{ subscription.plan_name }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Status -->
                <div class="sdd-section">
                    <div class="sdd-section__title">{{ t.section_subscription_status }}</div>
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr>
                                <th class="ps-0" style="width:45%;">{{ t.detail_status }}</th>
                                <td>
                                    <span class="badge" :class="subscription.status_badge">{{ subscription.status_label }}</span>
                                </td>
                            </tr>
                            <tr>
                                <th class="ps-0">{{ t.detail_billing_state }}</th>
                                <td>
                                    <BillingStateBadge :badge="subscription.billing_state_badge" :label="subscription.billing_state_label" :state="subscription.billing_state" />
                                </td>
                            </tr>
                            <tr v-if="subscription.last_billing_error">
                                <th class="ps-0">{{ t.detail_billing_error }}</th>
                                <td>
                                    <div class="alert alert-danger py-1 px-2 small mb-0">
                                        <i class="ti ti-alert-circle me-1"></i>{{ subscription.last_billing_error }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Gateway -->
                <div class="sdd-section">
                    <div class="sdd-section__title">{{ t.section_gateway }}</div>
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr>
                                <th class="ps-0" style="width:45%;">{{ t.detail_gateway }}</th>
                                <td>
                                    <span v-if="subscription.gateway" class="badge badge-soft-primary text-uppercase">{{ subscription.gateway }}</span>
                                    <span v-else class="text-muted small">{{ t.detail_gateway_empty }}</span>
                                </td>
                            </tr>
                            <tr v-if="subscription.gateway_customer_id">
                                <th class="ps-0">{{ t.detail_gateway_customer_id }}</th>
                                <td><code class="small">{{ subscription.gateway_customer_id }}</code></td>
                            </tr>
                            <tr v-if="subscription.gateway_subscription_id">
                                <th class="ps-0">{{ t.detail_gateway_subscription_id }}</th>
                                <td><code class="small">{{ subscription.gateway_subscription_id }}</code></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Datas -->
                <div class="sdd-section">
                    <div class="sdd-section__title">{{ t.section_dates }}</div>
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr>
                                <th class="ps-0" style="width:45%;">{{ t.detail_starts_at }}</th>
                                <td>{{ subscription.starts_at ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th class="ps-0">{{ t.detail_ends_at }}</th>
                                <td>
                                    <span v-if="subscription.ends_at">{{ subscription.ends_at }}</span>
                                    <span v-else class="text-muted">{{ t.detail_ends_at_lifetime }}</span>
                                </td>
                            </tr>
                            <tr v-if="subscription.next_billing_at">
                                <th class="ps-0">{{ t.detail_next_billing }}</th>
                                <td>
                                    <span class="fw-semibold">{{ subscription.next_billing_at }}</span>
                                    <span v-if="subscription.next_billing_overdue" class="badge badge-soft-danger ms-1">
                                        {{ t.detail_next_billing_overdue }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="subscription.last_payment_at">
                                <th class="ps-0">{{ t.detail_last_payment }}</th>
                                <td>{{ subscription.last_payment_at }}</td>
                            </tr>
                            <tr v-if="subscription.trial_ends_at">
                                <th class="ps-0">{{ t.detail_trial_ends_at }}</th>
                                <td>{{ subscription.trial_ends_at }}</td>
                            </tr>
                            <tr v-if="subscription.grace_period_ends_at">
                                <th class="ps-0">{{ t.detail_grace_period_ends_at }}</th>
                                <td>{{ subscription.grace_period_ends_at }}</td>
                            </tr>
                            <tr v-if="subscription.cancelled_at">
                                <th class="ps-0">{{ t.detail_cancelled_at }}</th>
                                <td>{{ subscription.cancelled_at }}</td>
                            </tr>
                            <tr>
                                <th class="ps-0">{{ t.detail_created_at }}</th>
                                <td>{{ subscription.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Tab: Faturas ─────────────────────────────────────────────── -->
            <div v-show="activeTab === 'invoices'">
                <div v-if="invoicesLoading" class="text-center py-4">
                    <div class="spinner-border spinner-border-sm text-primary"></div>
                    <span class="ms-2 text-muted small">{{ t.loading }}</span>
                </div>

                <template v-else>
                    <div v-if="invoices.length === 0" class="text-center py-5 text-muted">
                        <i class="ti ti-receipt fs-2 d-block mb-2 opacity-50"></i>
                        {{ t.empty_invoices }}
                    </div>

                    <div v-for="inv in invoices" :key="inv.id" class="card mb-2">
                        <div class="card-header py-2 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="fw-semibold small">{{ inv.reference }}</span>
                                <span class="badge" :class="inv.status_badge">{{ inv.status_label }}</span>
                                <span v-if="inv.billing_reason" class="badge badge-soft-secondary">{{ inv.billing_reason }}</span>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <span class="fw-bold text-success">R$ {{ inv.amount }}</span>
                                <span v-if="inv.gateway_code" class="badge badge-soft-primary ms-1 text-uppercase">{{ inv.gateway_code }}</span>
                            </div>
                        </div>
                        <div class="card-body py-2 small text-muted">
                            <div class="d-flex flex-wrap gap-3">
                                <span v-if="inv.period_start">
                                    <i class="ti ti-calendar me-1"></i>{{ t.invoice_period }} {{ inv.period_start }} – {{ inv.period_end }}
                                </span>
                                <span v-if="inv.due_at">
                                    <i class="ti ti-clock me-1"></i>{{ t.invoice_due_at }} {{ inv.due_at }}
                                </span>
                                <span v-if="inv.paid_at" class="text-success">
                                    <i class="ti ti-check me-1"></i>{{ t.invoice_paid_at }} {{ inv.paid_at }}
                                </span>
                            </div>

                            <!-- Pagamentos -->
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
                                        <code v-if="pay.external_payment_id" class="small">{{ pay.external_payment_id }}</code>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-semibold">R$ {{ pay.amount }}</div>
                                        <div class="text-success small" v-if="pay.paid_at">
                                            <i class="ti ti-check me-1"></i>{{ pay.paid_at }}
                                        </div>
                                        <div class="text-danger small" v-if="pay.failed_at">
                                            <i class="ti ti-x me-1"></i>{{ pay.failed_at }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ── Tab: Retentativas ────────────────────────────────────────── -->
            <div v-show="activeTab === 'retries'">
                <div v-if="retriesLoading" class="text-center py-4">
                    <div class="spinner-border spinner-border-sm text-primary"></div>
                    <span class="ms-2 text-muted small">{{ t.loading }}</span>
                </div>

                <template v-else>
                    <div v-if="retries.length === 0" class="text-center py-5 text-muted">
                        <i class="ti ti-refresh-alert fs-2 d-block mb-2 opacity-50"></i>
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
                                    <td><span class="badge" :class="retry.status_badge">{{ retry.status }}</span></td>
                                    <td class="text-uppercase small">{{ retry.gateway_code ?? '—' }}</td>
                                    <td class="small">{{ retry.scheduled_for }}</td>
                                    <td class="small">{{ retry.executed_at ?? '—' }}</td>
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
.sdd-section { margin-bottom: 1.25rem; }
.sdd-section__title {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--bs-secondary-color);
    margin-bottom: .5rem;
    padding-bottom: .25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
</style>
