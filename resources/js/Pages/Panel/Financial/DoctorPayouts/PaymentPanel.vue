<script setup>
import { computed, useId } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PayoutStatusBadge from './PayoutStatusBadge.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Pagamento do fechamento: pago → data, valor, forma, observações e atalho
 * para a despesa no Fluxo de Caixa; fechado (canPay) → registrar pagamento.
 * O valor é sempre o total líquido do fechamento (não vem do formulário).
 */
const props = defineProps({
    payout:         { type: Object,  required: true },   // statement.payout
    canPay:         { type: Boolean, default: false },
    paymentMethods: { type: Array,   default: () => [] }, // [{ value, label }]
    today:          { type: String,  default: '' },
    payUrl:         { type: String,  default: '' },
    cashFlowUrl:    { type: String,  default: null },
    t:              { type: Object,  default: () => ({}) },
});

const { tx, money, date } = useDoctorPayoutFormat(() => props.t);

const uid = useId();
const ids = {
    title:  `dp-payment-title-${uid}`,
    date:   `dp-payment-date-${uid}`,
    method: `dp-payment-method-${uid}`,
    notes:  `dp-payment-notes-${uid}`,
    hint:   `dp-payment-hint-${uid}`,
    error:  `dp-payment-error-${uid}`,
};

const isPaid = computed(() => props.payout.status === 'paid');

const form = useForm({
    paid_at:        props.today,
    payment_method: props.paymentMethods[0]?.value ?? '',
    payment_notes:  '',
});

const methodLabel = computed(() => {
    const value = props.payout.payment_method;

    return props.paymentMethods.find((method) => method.value === value)?.label
        ?? props.t.payment_methods?.[value]
        ?? props.t.none;
});

/** Total zerado: marca como pago sem lançar despesa no caixa. */
const payHint = computed(() => (Number(props.payout.total_amount ?? 0) === 0 ? props.t.payment_zero_hint : props.t.payment_cash_hint));

const FIELD_IDS = { paid_at: ids.date, payment_method: ids.method, payment_notes: ids.notes };

/** Dica (só na data: explica o lançamento no caixa) + erro do campo, quando houver. */
function describedBy(field) {
    const parts = [
        field === 'paid_at' ? ids.hint : null,
        form.errors[field] ? `${FIELD_IDS[field]}-error` : null,
    ].filter(Boolean);

    return parts.length ? parts.join(' ') : undefined;
}

function submit() {
    if (form.processing) return;

    form.post(props.payUrl, { preserveScroll: true });
}
</script>

<template>
    <section class="card mb-0" :aria-labelledby="ids.title" data-test="payment-panel">
        <div class="card-header">
            <h3 :id="ids.title" class="h6 fw-bold mb-0">
                <i class="ti ti-cash-banknote me-1 text-primary" aria-hidden="true"></i>{{ t.payment_title }}
            </h3>
        </div>
        <div class="card-body">
            <!-- Pago -->
            <template v-if="isPaid">
                <p class="d-flex flex-wrap align-items-center gap-2 mb-3" data-test="payment-paid">
                    <PayoutStatusBadge status="paid" :t="t" />
                    <span class="fw-medium">{{ tx('payment_paid_on', { date: date(payout.paid_at) }) }}</span>
                </p>
                <dl class="row row-cols-1 row-cols-sm-3 g-2 small mb-0">
                    <div class="col">
                        <dt class="text-muted fw-normal">{{ t.payment_amount }}</dt>
                        <dd class="fw-semibold mb-0" data-test="payment-amount">{{ money(payout.paid_amount) }}</dd>
                    </div>
                    <div class="col">
                        <dt class="text-muted fw-normal">{{ t.payment_method }}</dt>
                        <dd class="mb-0" data-test="payment-method">{{ methodLabel }}</dd>
                    </div>
                    <div v-if="cashFlowUrl" class="col">
                        <dt class="text-muted fw-normal">{{ t.payment_cash_entry }}</dt>
                        <dd class="mb-0">
                            <Link :href="cashFlowUrl" data-test="payment-cash-flow">
                                <i class="ti ti-cash-register me-1" aria-hidden="true"></i>{{ t.payment_cash_entry }}
                            </Link>
                        </dd>
                    </div>
                    <div v-if="payout.payment_notes" class="col-12">
                        <dt class="text-muted fw-normal">{{ t.payment_notes }}</dt>
                        <dd class="mb-0 text-break">{{ payout.payment_notes }}</dd>
                    </div>
                </dl>
            </template>

            <!-- Registrar pagamento -->
            <form v-else-if="canPay" novalidate data-test="payment-form" @submit.prevent="submit">
                <p :id="ids.hint" class="small text-muted mb-3" data-test="payment-hint">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ payHint }}
                </p>

                <div class="row g-3">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label :for="ids.date" class="form-label small mb-1">{{ t.payment_date }}</label>
                        <input
                            :id="ids.date"
                            v-model="form.paid_at"
                            type="date"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': form.errors.paid_at }"
                            :max="today || undefined"
                            required
                            :aria-invalid="form.errors.paid_at ? 'true' : undefined"
                            :aria-describedby="describedBy('paid_at')"
                            data-test="payment-date"
                        >
                        <div v-if="form.errors.paid_at" :id="`${ids.date}-error`" class="invalid-feedback d-block">{{ form.errors.paid_at }}</div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label :for="ids.method" class="form-label small mb-1">{{ t.payment_method }}</label>
                        <select
                            :id="ids.method"
                            v-model="form.payment_method"
                            class="form-select form-select-sm"
                            :class="{ 'is-invalid': form.errors.payment_method }"
                            required
                            :aria-invalid="form.errors.payment_method ? 'true' : undefined"
                            :aria-describedby="describedBy('payment_method')"
                            data-test="payment-method-select"
                        >
                            <option v-for="method in paymentMethods" :key="method.value" :value="method.value">{{ method.label }}</option>
                        </select>
                        <div v-if="form.errors.payment_method" :id="`${ids.method}-error`" class="invalid-feedback d-block">{{ form.errors.payment_method }}</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label :for="ids.notes" class="form-label small mb-1">{{ t.payment_notes }}</label>
                        <textarea
                            :id="ids.notes"
                            v-model="form.payment_notes"
                            rows="1"
                            maxlength="1000"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': form.errors.payment_notes }"
                            :aria-describedby="describedBy('payment_notes')"
                            data-test="payment-notes"
                        ></textarea>
                        <div v-if="form.errors.payment_notes" :id="`${ids.notes}-error`" class="invalid-feedback d-block">{{ form.errors.payment_notes }}</div>
                    </div>
                </div>

                <div v-if="form.errors.status" :id="ids.error" class="alert alert-danger small d-flex gap-2 mt-3 mb-0" role="alert" data-test="payment-error">
                    <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i><span>{{ form.errors.status }}</span>
                </div>

                <div class="d-flex justify-content-end mt-3">
                    <button type="submit" class="btn btn-success btn-sm" :disabled="form.processing" data-test="payment-submit">
                        <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-check me-1" aria-hidden="true"></i>{{ t.payment_confirm }}
                        <span class="ms-1 payment-panel__value">({{ money(payout.total_amount) }})</span>
                    </button>
                </div>
            </form>
        </div>
    </section>
</template>

<style scoped>
.payment-panel__value {
    font-variant-numeric: tabular-nums;
}
</style>
