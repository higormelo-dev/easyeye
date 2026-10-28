<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { currencySymbol, generalErrors } from './billingHelpers.js';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * "Registrar recebimento" de uma guia (substitui o window.confirm do antigo
 * "Pagar", que lançava sempre o valor cheio). Valor padrão = valor da guia −
 * glosa (mesma regra do servidor), data padrão = hoje; o lançamento de caixa
 * é criado automaticamente pelo BillingService com esse valor e data.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    claim: { type: Object, default: null },
    paymentMethods: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const FIELDS = ['paid_amount', 'paid_at', 'payment_method', 'notes'];

const { tx } = useTrans(() => props.t);
const { money, locale } = useLocaleFormat();

const form = useForm({
    paid_amount: '',
    paid_at: '',
    payment_method: '',
    notes: '',
});

const rootRef = ref(null);
const symbol = computed(() => currencySymbol(locale.value));
const expected = computed(() => Number(props.claim?.receivable_amount ?? 0));
const otherErrors = computed(() => generalErrors(form.errors, FIELDS));

const partialDifference = computed(() => {
    const paid = Number(form.paid_amount);
    if (!(paid > 0) || paid >= expected.value) return null;

    return expected.value - paid;
});

// Acima do esperado numa guia glosada = glosa revertida (ex.: recurso aceito):
// o servidor abate essa parte da glosa (Recebido + Glosado não passa do valor).
const glosaReversed = computed(() => {
    const paid = Number(form.paid_amount);
    const glosa = Number(props.claim?.glosa_amount ?? 0);
    if (!(glosa > 0) || !(paid > expected.value)) return null;

    return Math.min(paid - expected.value, glosa);
});

watch(
    () => props.open,
    (open) => {
        if (!open || !props.claim) return;

        form.defaults({
            paid_amount: expected.value > 0 ? expected.value : '',
            paid_at: props.today,
            payment_method: props.paymentMethods[0]?.value ?? '',
            notes: '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function requestClose() {
    if (!form.processing) emit('close');
}

function submit() {
    if (!props.claim || form.processing) return;

    form.post(props.claim.mark_paid_url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('saved', props.claim),
    });
}

useDialogKeyboard(() => props.open, { onEscape: requestClose, focusRef: rootRef });
</script>

<template>
    <CenteredModal :open="open" size="md" @close="requestClose">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-cash me-2 text-success" aria-hidden="true"></i>{{ t.receipt_title }}
            </h5>
        </template>

        <form v-if="claim" ref="rootRef" novalidate @submit.prevent="submit">
            <div class="border rounded p-2 mb-3 small bg-body-tertiary" data-test="receipt-summary">
                <div class="d-flex justify-content-between flex-wrap gap-1">
                    <span class="fw-semibold">{{ claim.code }}</span>
                    <span class="text-muted">{{ claim.covenant_name || t.no_covenant }}</span>
                </div>
                <div class="text-muted mb-2">{{ claim.patient_name || '—' }}</div>
                <dl class="row mb-0">
                    <dt class="col-6 fw-normal text-muted">{{ t.receipt_claim_amount }}</dt>
                    <dd class="col-6 text-end mb-1">{{ money(claim.amount) }}</dd>
                    <template v-if="Number(claim.glosa_amount) > 0">
                        <dt class="col-6 fw-normal text-muted">{{ t.receipt_glosa_amount }}</dt>
                        <dd class="col-6 text-end mb-1 text-danger-emphasis">− {{ money(claim.glosa_amount) }}</dd>
                    </template>
                    <dt class="col-6">{{ t.receipt_expected }}</dt>
                    <dd class="col-6 text-end fw-semibold mb-0" data-test="receipt-expected">{{ money(expected) }}</dd>
                </dl>
            </div>

            <div
                v-for="message in otherErrors"
                :key="message"
                class="alert alert-danger small py-2"
                role="alert"
                data-test="receipt-error"
            >
                {{ message }}
            </div>

            <div class="row g-3">
                <div class="col-12 col-sm-6">
                    <label for="billing-receipt-amount" class="form-label">
                        {{ t.receipt_paid_amount }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <div class="input-group has-validation">
                        <span class="input-group-text">{{ symbol }}</span>
                        <input
                            id="billing-receipt-amount"
                            v-model="form.paid_amount"
                            type="number"
                            inputmode="decimal"
                            step="0.01"
                            min="0.01"
                            :max="claim.amount"
                            required
                            aria-required="true"
                            class="form-control text-end"
                            :class="{ 'is-invalid': form.errors.paid_amount }"
                            :aria-invalid="form.errors.paid_amount ? 'true' : 'false'"
                            aria-describedby="billing-receipt-amount-hint"
                        />
                        <div v-if="form.errors.paid_amount" class="invalid-feedback">{{ form.errors.paid_amount }}</div>
                    </div>
                    <small id="billing-receipt-amount-hint" class="form-text">{{ t.receipt_paid_amount_hint }}</small>
                    <small
                        v-if="partialDifference !== null"
                        class="d-block text-warning-emphasis mt-1"
                        data-test="receipt-partial"
                    >
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                        >{{ tx('receipt_partial_hint', { difference: money(partialDifference) }) }}
                    </small>
                    <small
                        v-if="glosaReversed !== null"
                        class="d-block text-info-emphasis mt-1"
                        data-test="receipt-glosa-reversed"
                    >
                        <i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i
                        >{{ tx('receipt_glosa_reversed_hint', { amount: money(glosaReversed) }) }}
                    </small>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="billing-receipt-date" class="form-label">
                        {{ t.receipt_paid_at }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input
                        id="billing-receipt-date"
                        v-model="form.paid_at"
                        type="date"
                        :max="today || null"
                        required
                        aria-required="true"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.paid_at }"
                        :aria-invalid="form.errors.paid_at ? 'true' : 'false'"
                    />
                    <div v-if="form.errors.paid_at" class="invalid-feedback">{{ form.errors.paid_at }}</div>
                </div>
                <div class="col-12">
                    <label for="billing-receipt-method" class="form-label">{{ t.receipt_payment_method }}</label>
                    <select
                        id="billing-receipt-method"
                        v-model="form.payment_method"
                        class="form-select"
                        :class="{ 'is-invalid': form.errors.payment_method }"
                    >
                        <option v-for="m in paymentMethods" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                    <div v-if="form.errors.payment_method" class="invalid-feedback">
                        {{ form.errors.payment_method }}
                    </div>
                </div>
                <div class="col-12">
                    <label for="billing-receipt-notes" class="form-label">{{ t.receipt_notes }}</label>
                    <textarea
                        id="billing-receipt-notes"
                        v-model="form.notes"
                        rows="2"
                        maxlength="1000"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.notes }"
                    ></textarea>
                    <div v-if="form.errors.notes" class="invalid-feedback">{{ form.errors.notes }}</div>
                </div>
            </div>

            <p class="small text-muted mt-3 mb-0">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.receipt_cash_hint }}
            </p>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="requestClose">
                {{ t.btn_cancel }}
            </button>
            <button
                type="button"
                class="btn btn-success"
                data-test="confirm-receipt"
                :disabled="form.processing"
                @click="submit"
            >
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ form.processing ? t.processing : t.btn_confirm_receipt }}
            </button>
        </template>
    </CenteredModal>
</template>
