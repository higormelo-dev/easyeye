<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import ReceiptFields from './ReceiptFields.vue';
import { validationErrors } from './billingHelpers.js';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * "Registrar recebimento" das guias selecionadas na aba Guias: data, forma,
 * observação e o valor de cada guia (padrão = a receber = valor − glosa;
 * maior que zero e até o valor da guia). O servidor grava tudo numa
 * transação (um lançamento de caixa por guia) ou nada: a recusa volta com o
 * motivo de cada guia, mostrado na linha e num aviso. Guia já paga é
 * ignorada e aparece no resultado (aviso ao vivo, com o foco).
 */
const props = defineProps({
    open:           { type: Boolean, default: false },
    /** Linhas da aba Guias marcadas (de qualquer página). */
    claims:         { type: Array,   default: () => [] },
    paymentMethods: { type: Array,   default: () => [] },
    today:          { type: String,  default: '' },
    url:            { type: String,  default: '' },
    /** Teto de guias por requisição (servidor). */
    max:            { type: Number,  default: 200 },
    t:              { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const FIELDS = ['paid_at', 'payment_method', 'notes'];

const { tx } = useTrans(() => props.t);
const { money } = useLocaleFormat();

const rows          = ref([]);
const amounts       = ref({});
const form          = ref({ paid_at: '', payment_method: '', notes: '' });
const fieldErrors   = ref({});
const rowErrors     = ref({});
const generalErrors = ref([]);
const processing    = ref(false);
const result        = ref(null);
const rootRef       = ref(null);
const resultRef     = ref(null);

const total     = computed(() => rows.value.reduce((sum, row) => sum + Number(amounts.value[row.id] ?? 0), 0));
const overLimit = computed(() => rows.value.length > props.max);

// A lista é copiada ao abrir: a seleção do Index é limpa depois de gravar e
// o resultado continua mostrando as guias do pedido.
watch(() => props.open, (open) => {
    if (!open) return;

    rows.value    = props.claims.map((claim) => ({ ...claim }));
    amounts.value = Object.fromEntries(rows.value.map((row) => [row.id, Number(row.receivable_amount) > 0 ? Number(row.receivable_amount) : null]));
    form.value    = { paid_at: props.today, payment_method: props.paymentMethods[0]?.value ?? '', notes: '' };

    fieldErrors.value   = {};
    rowErrors.value     = {};
    generalErrors.value = [];
    result.value        = null;
}, { immediate: true });

function amountId(row) {
    return `billing-bulk-amount-${row.id}`;
}

function requestClose() {
    if (!processing.value) emit('close');
}

/** Maior que zero e até o valor da guia (mesma regra do servidor). */
function validateRows() {
    const errors = {};

    rows.value.forEach((row) => {
        const amount = Number(amounts.value[row.id]);

        if (!(amount > 0) || amount > Number(row.amount)) {
            errors[row.id] = tx('bulk_receipt_amount_invalid', { max: money(row.amount) });
        }
    });

    rowErrors.value = errors;

    return Object.keys(errors).length === 0;
}

function focusFirstRowError() {
    nextTick(() => {
        const first = rows.value.find((row) => rowErrors.value[row.id]);
        if (first) document.getElementById(amountId(first))?.focus?.();
    });
}

/** 422: campo → campo; `items.N.*` e `claims.<id>` → linha; o resto → aviso geral. */
function applyServerErrors(errors) {
    const fields  = {};
    const byRow   = {};
    const general = [];

    Object.entries(errors).forEach(([key, message]) => {
        const item  = key.match(/^items\.(\d+)\./);
        const claim = key.match(/^claims\.(.+)$/);
        const rowId = item ? rows.value[Number(item[1])]?.id : claim?.[1];

        if (FIELDS.includes(key)) fields[key] = message;
        else if (rowId) byRow[rowId] = message;
        else general.push(message);
    });

    fieldErrors.value   = fields;
    rowErrors.value     = byRow;
    generalErrors.value = [...general, ...Object.values(byRow)];
}

async function submit() {
    if (processing.value || result.value || overLimit.value || rows.value.length === 0) return;

    fieldErrors.value   = {};
    generalErrors.value = [];

    if (!validateRows()) {
        focusFirstRowError();

        return;
    }

    processing.value = true;

    try {
        const { data } = await window.axios.post(props.url, {
            ...form.value,
            items: rows.value.map((row) => ({ claim_id: row.id, paid_amount: amounts.value[row.id] })),
        });

        result.value = data;
        emit('saved', data);
        nextTick(() => resultRef.value?.focus?.());
    } catch (error) {
        const errors = validationErrors(error);

        if (errors) applyServerErrors(errors);
        else generalErrors.value = [props.t.bulk_receipt_failed];
    } finally {
        processing.value = false;
    }
}

useDialogKeyboard(() => props.open, { onEscape: requestClose, focusRef: rootRef });
</script>

<template>
    <CenteredModal :open="open" size="xl" :close-label="t.btn_close" @close="requestClose">
        <template #header>
            <h2 class="h5 mb-0 fw-semibold">
                <i class="ti ti-cash me-2 text-success" aria-hidden="true"></i>{{ tx('bulk_receipt_title', { count: rows.length }) }}
            </h2>
        </template>

        <form ref="rootRef" novalidate data-test="bulk-receipt-form" @submit.prevent="submit">
            <p class="small text-muted">{{ t.bulk_receipt_intro }}</p>

            <div v-if="overLimit" class="alert alert-warning small py-2" role="alert" data-test="bulk-over-limit">
                {{ tx('bulk_receipt_over_limit', { max }) }}
            </div>

            <div v-if="generalErrors.length" class="alert alert-danger small py-2" role="alert" data-test="bulk-errors">
                <p class="fw-semibold mb-1">{{ t.bulk_receipt_errors_title }}</p>
                <ul class="mb-0 ps-3">
                    <li v-for="message in generalErrors" :key="message">{{ message }}</li>
                </ul>
            </div>

            <ReceiptFields
                v-model:paid-at="form.paid_at"
                v-model:payment-method="form.payment_method"
                v-model:notes="form.notes"
                id-prefix="billing-bulk"
                :payment-methods="paymentMethods"
                :today="today"
                :errors="fieldErrors"
                :disabled="processing || Boolean(result)"
                :t="t"
            />

            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0" data-test="bulk-table">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">{{ t.bulk_receipt_col_guide }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ t.bulk_receipt_col_patient }}</th>
                            <th scope="col" class="text-end d-none d-sm-table-cell">{{ t.bulk_receipt_col_amount }}</th>
                            <th scope="col" class="text-end">{{ t.bulk_receipt_col_receivable }}</th>
                            <th scope="col" class="text-end billing-bulk__amount">{{ t.bulk_receipt_col_paid }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" data-test="bulk-row">
                            <td class="fw-semibold text-nowrap">{{ row.code }}</td>
                            <td class="d-none d-md-table-cell small">{{ row.patient_name || '—' }}</td>
                            <td class="text-end text-nowrap d-none d-sm-table-cell">{{ money(row.amount) }}</td>
                            <td class="text-end text-nowrap">{{ money(row.receivable_amount) }}</td>
                            <td class="text-end">
                                <label :for="amountId(row)" class="visually-hidden">{{ tx('bulk_receipt_amount_label', { code: row.code }) }}</label>
                                <MoneyInput
                                    :id="amountId(row)"
                                    v-model="amounts[row.id]"
                                    :invalid="Boolean(rowErrors[row.id])"
                                    :disabled="processing || Boolean(result)"
                                    :aria-describedby="rowErrors[row.id] ? `${amountId(row)}-error` : undefined"
                                    data-test="bulk-amount"
                                />
                                <div v-if="rowErrors[row.id]" :id="`${amountId(row)}-error`" class="small text-danger-emphasis text-start mt-1" data-test="bulk-row-error">
                                    {{ rowErrors[row.id] }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end align-items-baseline gap-2 border-top pt-2 mt-1">
                <span class="small text-muted">{{ t.bulk_receipt_total }}</span>
                <strong class="text-nowrap" data-test="bulk-total">{{ money(total) }}</strong>
            </div>

            <p class="small text-muted mt-3 mb-0">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.receipt_cash_hint }}
            </p>

            <!-- Resultado ao vivo (leitor de tela anuncia; o foco vai para cá ao gravar). -->
            <div role="status" aria-live="polite" class="mt-3">
                <section
                    v-if="result"
                    ref="resultRef"
                    tabindex="-1"
                    class="border-top pt-3"
                    aria-labelledby="billing-bulk-result-title"
                    data-test="bulk-result"
                >
                    <h3 id="billing-bulk-result-title" class="h6 fw-semibold">{{ t.bulk_receipt_result_title }}</h3>
                    <div :class="['alert small py-2 mb-2', result.paid?.length ? 'alert-success' : 'alert-info']" data-test="bulk-message">
                        {{ result.message }}
                    </div>
                    <p v-if="result.paid?.length" class="small mb-2" data-test="bulk-paid-total">
                        {{ tx('bulk_receipt_paid_total', { total: money(result.total_paid) }) }}
                    </p>
                    <div v-if="result.skipped?.length" data-test="bulk-skipped">
                        <p class="small fw-semibold mb-1">{{ t.bulk_receipt_skipped_title }}</p>
                        <ul class="small mb-0 ps-3">
                            <li v-for="item in result.skipped" :key="item.claim_id">{{ item.message }}</li>
                        </ul>
                    </div>
                </section>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="processing" data-test="bulk-close" @click="requestClose">
                {{ result ? t.btn_close : t.btn_cancel }}
            </button>
            <button
                v-if="!result"
                type="button"
                class="btn btn-success"
                data-test="bulk-confirm"
                :disabled="processing || overLimit || rows.length === 0"
                @click="submit"
            >
                <span v-if="processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ processing ? t.processing : tx('bulk_receipt_confirm', { count: rows.length }) }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.billing-bulk__amount {
    min-width: 10rem;
}
</style>
