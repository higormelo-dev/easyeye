<script setup>
import { computed, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import PaymentsList from './PaymentsList.vue';
import PayoutStatusBadge from './PayoutStatusBadge.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Pagamentos do fechamento (E5): total, já pago e saldo; a lista dos
 * pagamentos (estornar cada um — emite `reverse`) e, enquanto houver saldo
 * (canPay), o formulário de um pagamento — parcial ou o saldo (padrão).
 *
 * O envio leva o "já pago" que a tela mostra (expected_paid_cents): se outro
 * pagamento entrou no meio, o servidor recusa em vez de pagar duas vezes.
 * Total zerado: um pagamento de valor zero marca como pago, sem lançamento.
 */
const props = defineProps({
    payout: { type: Object, required: true }, // statement.payout
    payments: { type: Array, default: () => [] }, // statement.payments
    canPay: { type: Boolean, default: false },
    canReverse: { type: Boolean, default: false },
    paymentMethods: { type: Array, default: () => [] }, // [{ value, label }]
    today: { type: String, default: '' },
    payUrl: { type: String, default: '' },
    cashFlowUrl: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['reverse']);

const { money } = useDoctorPayoutFormat(() => props.t);

const uid = useId();
const ids = {
    title: `dp-payment-title-${uid}`,
    amount: `dp-payment-amount-${uid}`,
    date: `dp-payment-date-${uid}`,
    method: `dp-payment-method-${uid}`,
    notes: `dp-payment-notes-${uid}`,
    hint: `dp-payment-hint-${uid}`,
    error: `dp-payment-error-${uid}`,
};

const cents = (value) => Math.round(Number(value ?? 0) * 100);
const paidCents = computed(() => cents(props.payout.paid_amount));
const remaining = computed(() => Number(props.payout.remaining_amount ?? 0));
const zeroTotal = computed(() => cents(props.payout.total_amount) === 0);

const form = useForm({
    amount: remaining.value,
    paid_at: props.today,
    payment_method: props.paymentMethods[0]?.value ?? '',
    payment_notes: '',
});

// Novo saldo (pagamento registrado/estornado): o valor sugerido acompanha.
watch(remaining, (value) => {
    form.amount = value;
});

const hint = computed(() => (zeroTotal.value ? props.t.payment_zero_hint : props.t.payment_partial_hint));

const FIELD_IDS = { amount: ids.amount, paid_at: ids.date, payment_method: ids.method, payment_notes: ids.notes };

/** Dica (só no valor: explica parcelas/lançamento) + erro do campo, quando houver. */
function describedBy(field) {
    const parts = [
        field === 'amount' ? ids.hint : null,
        form.errors[field] ? `${FIELD_IDS[field]}-error` : null,
    ].filter(Boolean);

    return parts.length ? parts.join(' ') : undefined;
}

function useBalance() {
    form.amount = remaining.value;
}

function submit() {
    if (form.processing) return;

    form.transform((data) => ({
        ...data,
        amount: zeroTotal.value ? 0 : data.amount,
        expected_paid_cents: paidCents.value,
    })).post(props.payUrl, {
        preserveScroll: true,
        onSuccess: () => form.reset('payment_notes'),
    });
}
</script>

<template>
    <section class="card mb-0" :aria-labelledby="ids.title" data-test="payment-panel">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h3 :id="ids.title" class="h6 fw-bold mb-0">
                <i class="ti ti-cash-banknote me-1 text-primary" aria-hidden="true"></i>{{ t.payments_title }}
            </h3>
            <PayoutStatusBadge v-if="payout.status" :status="payout.status" :t="t" />
        </div>
        <div class="card-body d-grid gap-3">
            <dl class="row row-cols-1 row-cols-sm-3 g-2 small mb-0" data-test="payment-summary">
                <div class="col">
                    <dt class="text-muted fw-normal">{{ t.statement_net_total }}</dt>
                    <dd class="fw-semibold mb-0 payment-panel__value" data-test="payment-total">
                        {{ money(payout.total_amount) }}
                    </dd>
                </div>
                <div class="col">
                    <dt class="text-muted fw-normal">{{ t.payment_paid_total }}</dt>
                    <dd class="fw-semibold mb-0 text-success payment-panel__value" data-test="payment-paid">
                        {{ money(payout.paid_amount ?? 0) }}
                    </dd>
                </div>
                <div class="col">
                    <dt class="text-muted fw-normal">{{ t.payment_balance }}</dt>
                    <dd
                        class="fw-semibold mb-0 payment-panel__value"
                        :class="remaining > 0 ? 'text-warning-emphasis' : 'text-muted'"
                        data-test="payment-balance"
                    >
                        {{ money(remaining) }}
                    </dd>
                </div>
            </dl>

            <PaymentsList
                v-if="payments.length"
                :payments="payments"
                :payment-methods="paymentMethods"
                :can-reverse="canReverse"
                :cash-flow-url="cashFlowUrl"
                :t="t"
                @reverse="emit('reverse', $event)"
            />

            <!-- Registrar um pagamento (parcial ou o saldo) -->
            <form v-if="canPay" novalidate class="border-top pt-3" data-test="payment-form" @submit.prevent="submit">
                <p :id="ids.hint" class="small text-muted mb-3" data-test="payment-hint">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ hint }}
                </p>

                <div class="row g-3">
                    <div v-if="!zeroTotal" class="col-12 col-sm-6 col-lg-3">
                        <label :for="ids.amount" class="form-label small mb-1">{{ t.payment_amount }}</label>
                        <MoneyInput
                            :id="ids.amount"
                            v-model="form.amount"
                            :invalid="Boolean(form.errors.amount)"
                            :aria-describedby="describedBy('amount')"
                            data-test="payment-amount-input"
                        />
                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 mt-1"
                            data-test="payment-use-balance"
                            @click="useBalance"
                        >
                            {{ t.payment_use_balance }} ({{ money(remaining) }})
                        </button>
                        <div v-if="form.errors.amount" :id="`${ids.amount}-error`" class="invalid-feedback d-block">
                            {{ form.errors.amount }}
                        </div>
                    </div>
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
                        />
                        <div v-if="form.errors.paid_at" :id="`${ids.date}-error`" class="invalid-feedback d-block">
                            {{ form.errors.paid_at }}
                        </div>
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
                            <option v-for="method in paymentMethods" :key="method.value" :value="method.value">
                                {{ method.label }}
                            </option>
                        </select>
                        <div
                            v-if="form.errors.payment_method"
                            :id="`${ids.method}-error`"
                            class="invalid-feedback d-block"
                        >
                            {{ form.errors.payment_method }}
                        </div>
                    </div>
                    <div class="col-12 col-lg-3">
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
                        <div
                            v-if="form.errors.payment_notes"
                            :id="`${ids.notes}-error`"
                            class="invalid-feedback d-block"
                        >
                            {{ form.errors.payment_notes }}
                        </div>
                    </div>
                </div>

                <div
                    v-if="form.errors.status || form.errors.expected_paid_cents"
                    :id="ids.error"
                    class="alert alert-danger small d-flex gap-2 mt-3 mb-0"
                    role="alert"
                    data-test="payment-error"
                >
                    <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i
                    ><span>{{ form.errors.status || form.errors.expected_paid_cents }}</span>
                </div>

                <div class="d-flex justify-content-end mt-3">
                    <button
                        type="submit"
                        class="btn btn-success btn-sm"
                        :disabled="form.processing"
                        data-test="payment-submit"
                    >
                        <span
                            v-if="form.processing"
                            class="spinner-border spinner-border-sm me-1"
                            aria-hidden="true"
                        ></span>
                        <i v-else class="ti ti-check me-1" aria-hidden="true"></i>{{ t.payment_confirm }}
                        <span class="ms-1 payment-panel__value">({{ money(zeroTotal ? 0 : form.amount) }})</span>
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
