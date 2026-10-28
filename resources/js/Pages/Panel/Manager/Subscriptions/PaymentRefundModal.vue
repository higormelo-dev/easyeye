<script setup>
import { computed, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import ReasonField from '@/Components/Panel/ReasonField.vue';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { useSubscriptionRequest } from './useSubscriptionRequest.js';

/**
 * Estornar um pagamento pago (Assinaturas → detalhe → faturas): total ou
 * parcial (com o valor, quando o gateway aceita), com a justificativa
 * obrigatória (auditoria). O estorno é pedido ao gateway e fica
 * "solicitado" até ele confirmar (RefundService).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    payment: { type: Object, default: null }, // linha do pagamento (invoices())
    invoice: { type: Object, default: null },
    t: { type: Object, default: () => ({}) }, // trans('manager_subscriptions')
});

const emit = defineEmits(['close', 'refunded']);

const { money } = useLocaleFormat();
const rt = computed(() => props.t.refund ?? {});
const { tx } = useTrans(() => rt.value);
const { saving, errors, message, send, reset } = useSubscriptionRequest(() => props.t.request_failed);

const mode = ref('full');
const amount = ref(null);
const reason = ref('');
const reasonField = ref(null);

const remaining = computed(() => Number(props.payment?.refund?.remaining ?? 0));
const allowsPartial = computed(() => !!props.payment?.refund?.partial);
const currency = computed(() => props.payment?.currency || 'BRL');

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        reset();
        mode.value = 'full';
        amount.value = null;
        reason.value = '';
    },
);

const amountValid = computed(
    () => mode.value === 'full' || (Number(amount.value) > 0 && Number(amount.value) <= remaining.value + 0.004),
);
const canSubmit = computed(() => !saving.value && amountValid.value && !!reasonField.value?.valid);

async function submit() {
    if (!canSubmit.value || !props.payment?.refund_url) return;

    const data = await send('post', props.payment.refund_url, {
        mode: mode.value,
        amount: mode.value === 'partial' ? Number(amount.value) : null,
        reason: reason.value,
    });
    if (!data) return;

    emit('refunded', data.message ?? '');
    emit('close');
}

function close() {
    if (!saving.value) emit('close');
}
</script>

<template>
    <CenteredModal :open="open" size="md" @close="close">
        <template #header>
            <div class="min-w-0">
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-receipt-refund me-2 text-danger" aria-hidden="true"></i>{{ rt.title }}
                </h5>
            </div>
        </template>

        <form v-if="payment" class="d-grid gap-3" novalidate data-test="refund-form" @submit.prevent="submit">
            <p class="small mb-0">
                {{
                    tx('message', {
                        amount: money(payment.amount, currency),
                        gateway: String(payment.gateway_code ?? '').toUpperCase(),
                        reference: invoice?.reference ?? '',
                    })
                }}
            </p>

            <fieldset class="d-grid gap-2">
                <legend class="visually-hidden">{{ rt.title }}</legend>
                <div class="form-check">
                    <input
                        id="refund-mode-full"
                        v-model="mode"
                        class="form-check-input"
                        type="radio"
                        value="full"
                        data-test="refund-mode-full"
                    />
                    <label class="form-check-label" for="refund-mode-full">
                        {{ tx('mode_full', { amount: money(remaining, currency) }) }}
                    </label>
                </div>
                <div class="form-check">
                    <input
                        id="refund-mode-partial"
                        v-model="mode"
                        class="form-check-input"
                        type="radio"
                        value="partial"
                        :disabled="!allowsPartial"
                        :title="allowsPartial ? undefined : rt.partial_unavailable"
                        data-test="refund-mode-partial"
                    />
                    <label class="form-check-label" for="refund-mode-partial">{{ rt.mode_partial }}</label>
                </div>
            </fieldset>

            <div v-if="mode === 'partial'">
                <label for="refund-amount" class="form-label fw-medium">{{ rt.amount }}</label>
                <MoneyInput
                    id="refund-amount"
                    v-model="amount"
                    :currency="currency"
                    :invalid="!!errors.amount || (amount !== null && !amountValid)"
                    aria-describedby="refund-amount-hint"
                    data-test="refund-amount"
                />
                <div id="refund-amount-hint" class="form-text">
                    {{ tx('amount_hint', { max: money(remaining, currency) }) }}
                </div>
                <div v-if="errors.amount" class="invalid-feedback d-block">{{ errors.amount }}</div>
            </div>

            <ReasonField
                ref="reasonField"
                v-model="reason"
                :label="t.field_reason"
                :hint="t.reason_hint"
                :placeholder="t.reason_placeholder"
                :error="errors.reason"
            />

            <div
                v-if="message"
                class="alert alert-danger small d-flex gap-2 mb-0"
                role="alert"
                data-test="refund-error"
            >
                <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i><span>{{ message }}</span>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="saving" @click="close">{{ t.btn_cancel }}</button>
            <button
                type="button"
                class="btn btn-danger"
                :disabled="!canSubmit"
                data-test="refund-submit"
                @click="submit"
            >
                <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ rt.confirm }}
            </button>
        </template>
    </CenteredModal>
</template>
