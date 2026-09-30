<script setup>
import { Link } from '@inertiajs/vue3';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Pagamentos de um fechamento (E5), na ordem em que foram feitos.
 *
 * - Clínica: forma, quem registrou, observações, atalho para a despesa no
 *   Fluxo de Caixa e, com `canReverse`, o botão de estornar cada pagamento
 *   válido (emite `reverse`). Estornado fica riscado, com quem/quando/motivo.
 * - Médico ("Meus repasses"): o servidor manda só os válidos, com data, valor
 *   e forma — a lista não mostra nada além disso.
 */
const props = defineProps({
    payments:       { type: Array,   default: () => [] },
    paymentMethods: { type: Array,   default: () => [] },   // [{ value, label }]
    canReverse:     { type: Boolean, default: false },
    cashFlowUrl:    { type: String,  default: '' },         // base; ?from=&to= do dia do pagamento
    t:              { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['reverse']);

const { tx, money, date, dateTime } = useDoctorPayoutFormat(() => props.t);

function methodLabel(value) {
    return props.paymentMethods.find((method) => method.value === value)?.label
        ?? props.t.payment_methods?.[value]
        ?? props.t.none;
}

const cashFlowHref = (payment) => `${props.cashFlowUrl}?from=${payment.paid_at}&to=${payment.paid_at}`;

const reverseLabel = (payment) => tx('payment_reverse_label', { date: date(payment.paid_at), amount: money(payment.amount) });
</script>

<template>
    <div data-test="payments-list">
        <p v-if="payments.length === 0" class="small text-muted mb-0" data-test="payments-empty">{{ t.payments_empty }}</p>

        <ul v-else class="list-unstyled d-grid gap-2 mb-0">
            <li
                v-for="payment in payments"
                :key="payment.id"
                class="payments-list__item border rounded px-3 py-2"
                :class="{ 'payments-list__item--reversed': payment.reversed_at }"
                data-test="payment-row"
                :data-reversed="payment.reversed_at ? 'true' : 'false'"
            >
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="fw-semibold payments-list__value" :class="{ 'text-decoration-line-through text-muted': payment.reversed_at }" data-test="payment-row-amount">
                        {{ money(payment.amount) }}
                    </span>
                    <span class="small">{{ date(payment.paid_at) }}</span>
                    <span class="small text-muted">· {{ methodLabel(payment.payment_method) }}</span>
                    <span v-if="payment.paid_by_name" class="small text-muted">· {{ tx('payment_by', { user: payment.paid_by_name }) }}</span>
                    <span v-if="payment.reversed_at" class="badge badge-soft-secondary border border-secondary fs-11" data-test="payment-row-reversed">
                        <i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>{{ t.payment_reversed_badge }}
                    </span>

                    <span class="ms-auto d-flex align-items-center gap-2">
                        <Link
                            v-if="cashFlowUrl && payment.has_cash_entry && !payment.reversed_at"
                            :href="cashFlowHref(payment)"
                            class="small"
                            data-test="payment-row-cash-flow"
                        >
                            <i class="ti ti-cash-register me-1" aria-hidden="true"></i>{{ t.payment_cash_entry }}
                        </Link>
                        <button
                            v-if="canReverse && !payment.reversed_at"
                            type="button"
                            class="btn btn-outline-danger btn-sm py-0"
                            :aria-label="reverseLabel(payment)"
                            :title="reverseLabel(payment)"
                            data-test="payment-row-reverse"
                            @click="emit('reverse', payment)"
                        >
                            <i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>{{ t.reverse_payment }}
                        </button>
                    </span>
                </div>

                <p v-if="payment.has_cash_entry === false && !payment.reversed_at && Number(payment.amount) === 0" class="small text-muted mb-0 mt-1">
                    {{ t.payment_no_cash_entry }}
                </p>
                <p v-if="payment.notes" class="small text-muted text-break mb-0 mt-1" data-test="payment-row-notes">{{ payment.notes }}</p>
                <p v-if="payment.reversed_at" class="small text-muted text-break mb-0 mt-1" data-test="payment-row-reversal">
                    {{ tx('payment_reversed_line', { date: dateTime(payment.reversed_at), user: payment.reversed_by_name || t.none, reason: payment.reversal_reason || t.none }) }}
                </p>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.payments-list__value {
    font-variant-numeric: tabular-nums;
}

.payments-list__item--reversed {
    background-color: var(--bs-tertiary-bg);
}
</style>
