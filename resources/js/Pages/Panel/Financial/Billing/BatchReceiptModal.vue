<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import ReceiptFields from './ReceiptFields.vue';
import { firstError, validationErrors } from './billingHelpers.js';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * "Registrar recebimento do lote": ao abrir, a prévia do servidor (quantas
 * guias pagáveis e o total a receber; as bloqueadas ficam de fora); confirma
 * com data, forma e observação. Cada guia é paga pelo valor a receber, com um
 * lançamento de caixa por guia — valores diferentes por guia: aba Guias.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    batch: { type: Object, default: null },
    paymentMethods: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const FIELDS = ['paid_at', 'payment_method', 'notes'];

const { tx } = useTrans(() => props.t);
const { money } = useLocaleFormat();

const preview = ref(null);
const loading = ref(false);
const form = ref({ paid_at: '', payment_method: '', notes: '' });
const fieldErrors = ref({});
const generalError = ref('');
const processing = ref(false);
const result = ref(null);
const rootRef = ref(null);
const resultRef = ref(null);

const canConfirm = computed(
    () =>
        Boolean(preview.value) &&
        preview.value.count > 0 &&
        !preview.value.over_limit &&
        !loading.value &&
        !processing.value &&
        !result.value,
);

async function loadPreview() {
    loading.value = true;

    try {
        const { data } = await window.axios.get(props.batch.receipt_preview_url);
        preview.value = data;
    } catch (error) {
        const errors = validationErrors(error);
        generalError.value = (errors && firstError(errors)) || props.t.batch_receipt_failed;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open || !props.batch) return;

        preview.value = null;
        form.value = { paid_at: props.today, payment_method: props.paymentMethods[0]?.value ?? '', notes: '' };
        fieldErrors.value = {};
        generalError.value = '';
        result.value = null;

        if (props.batch.receipt_preview_url) loadPreview();
    },
    { immediate: true },
);

function requestClose() {
    if (!processing.value) emit('close');
}

async function submit() {
    if (!canConfirm.value) return;

    processing.value = true;
    fieldErrors.value = {};
    generalError.value = '';

    try {
        const { data } = await window.axios.post(props.batch.receipt_url, { ...form.value });

        result.value = data;
        emit('saved', data);
        nextTick(() => resultRef.value?.focus?.());
    } catch (error) {
        const errors = validationErrors(error);

        if (errors) {
            fieldErrors.value = Object.fromEntries(Object.entries(errors).filter(([key]) => FIELDS.includes(key)));
            generalError.value = firstError(
                Object.fromEntries(Object.entries(errors).filter(([key]) => !FIELDS.includes(key))),
            );
        } else {
            generalError.value = props.t.bulk_receipt_failed;
        }
    } finally {
        processing.value = false;
    }
}

useDialogKeyboard(() => props.open, { onEscape: requestClose, focusRef: rootRef });
</script>

<template>
    <CenteredModal :open="open" size="md" :close-label="t.btn_close" @close="requestClose">
        <template #header>
            <h2 class="h5 mb-0 fw-semibold">
                <i class="ti ti-cash me-2 text-success" aria-hidden="true"></i
                >{{ tx('batch_receipt_title', { code: batch?.code ?? '' }) }}
            </h2>
        </template>

        <form v-if="batch" ref="rootRef" novalidate data-test="batch-receipt-form" @submit.prevent="submit">
            <p class="small text-muted">{{ t.batch_receipt_intro }}</p>

            <div
                class="border rounded p-2 mb-3 small bg-body-tertiary"
                aria-live="polite"
                data-test="batch-receipt-preview"
            >
                <span v-if="loading" class="d-inline-flex align-items-center gap-2 text-muted">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span
                    >{{ t.batch_receipt_loading }}
                </span>
                <template v-else-if="preview">
                    <p class="fw-semibold mb-0" data-test="batch-receipt-summary">
                        {{ tx('batch_receipt_preview', { count: preview.count, total: money(preview.total) }) }}
                    </p>
                    <p v-if="preview.blocked > 0" class="text-muted mb-0 mt-1" data-test="batch-receipt-blocked">
                        {{ tx('batch_receipt_blocked', { count: preview.blocked }) }}
                    </p>
                </template>
            </div>

            <div
                v-if="preview?.over_limit"
                class="alert alert-warning small py-2"
                role="alert"
                data-test="batch-receipt-over-limit"
            >
                {{ tx('batch_receipt_over_limit', { count: preview.count, max: preview.max }) }}
            </div>

            <div v-if="generalError" class="alert alert-danger small py-2" role="alert" data-test="batch-receipt-error">
                {{ generalError }}
            </div>

            <ReceiptFields
                v-model:paid-at="form.paid_at"
                v-model:payment-method="form.payment_method"
                v-model:notes="form.notes"
                id-prefix="billing-batch-receipt"
                :payment-methods="paymentMethods"
                :today="today"
                :errors="fieldErrors"
                :disabled="processing || Boolean(result)"
                :t="t"
            />

            <div role="status" aria-live="polite" class="mt-3">
                <section
                    v-if="result"
                    ref="resultRef"
                    tabindex="-1"
                    class="border-top pt-3"
                    aria-labelledby="billing-batch-receipt-result-title"
                    data-test="batch-receipt-result"
                >
                    <h3 id="billing-batch-receipt-result-title" class="h6 fw-semibold">
                        {{ t.bulk_receipt_result_title }}
                    </h3>
                    <div class="alert alert-success small py-2 mb-2" data-test="batch-receipt-message">
                        {{ result.message }}
                    </div>
                    <p class="small mb-0">{{ tx('bulk_receipt_paid_total', { total: money(result.total_paid) }) }}</p>
                </section>
            </div>
        </form>

        <template #footer>
            <button
                type="button"
                class="btn btn-light"
                :disabled="processing"
                data-test="batch-receipt-close"
                @click="requestClose"
            >
                {{ result ? t.btn_close : t.btn_cancel }}
            </button>
            <button
                v-if="!result"
                type="button"
                class="btn btn-success"
                data-test="batch-receipt-confirm"
                :disabled="!canConfirm"
                @click="submit"
            >
                <span v-if="processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ processing ? t.processing : t.batch_receipt_confirm }}
            </button>
        </template>
    </CenteredModal>
</template>
