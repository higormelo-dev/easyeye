<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Recebimento manual do repasse: parte de uma receita avulsa do Fluxo de
 * Caixa (paga, sem vínculo — `receipts`, carregadas sob demanda) dividida
 * entre os itens selecionados na apuração. O financeiro digita o valor de
 * cada item; "Sugerir" divide o saldo da receita proporcional ao que falta
 * receber de cada item (centavos, resto nos primeiros). O servidor revalida
 * tudo sob lock (receita elegível, soma ≤ saldo, item da clínica).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] }, // linhas selecionadas (key, date, patient_name, description, receipt, forecast, charged)
    receipts: { type: Array, default: null }, // manual_receipts (null = ainda não carregadas)
    loading: { type: Boolean, default: false },
    action: { type: String, required: true }, // routes.allocate
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'load']);

const { tx, money, date } = useDoctorPayoutFormat(() => props.t);

const uid = useId();
const ids = {
    title: `dp-allocate-title-${uid}`,
    receipt: `dp-allocate-receipt-${uid}`,
    notes: `dp-allocate-notes-${uid}`,
    total: `dp-allocate-total-${uid}`,
    error: `dp-allocate-error-${uid}`,
};

const form = useForm({ cash_entry_id: '', items: [], notes: '' });

const cancelButton = ref(null);

/** Um item por ato (a mesma linha pode aparecer como parcela e como fechado). */
const items = computed(() => {
    const seen = new Set();

    return props.rows.filter((row) => (seen.has(row.key) ? false : seen.add(row.key)));
});

const selectedReceipt = computed(
    () => (props.receipts ?? []).find((receipt) => receipt.id === form.cash_entry_id) ?? null,
);
const remaining = computed(() => Number(selectedReceipt.value?.remaining ?? 0));

const toCents = (value) => Math.round(Number(value ?? 0) * 100);
const totalCents = computed(() => form.items.reduce((sum, item) => sum + toCents(item.amount), 0));
const over = computed(() => selectedReceipt.value !== null && totalCents.value > toCents(remaining.value));
const canSubmit = computed(() => !!selectedReceipt.value && totalCents.value > 0 && !over.value && !form.processing);

/** Mensagens do servidor que não são de um campo visível (soma, item inválido…). */
const generalErrors = computed(() => [
    ...new Set(
        Object.entries(form.errors ?? {})
            .filter(([field]) => !['notes', 'cash_entry_id'].includes(field))
            .map(([, message]) => (Array.isArray(message) ? message[0] : message))
            .filter(Boolean),
    ),
]);

function rowLabel(row) {
    return [date(row.date), row.patient_name || row.patient_code, row.description].filter(Boolean).join(' · ');
}

/** Peso para a sugestão: o que falta receber; sem isso, a previsão/base. */
function weight(row) {
    const open = toCents(row.receipt?.open);
    if (open > 0) return open;

    return Math.max(0, toCents(row.forecast ?? row.charged));
}

function suggest() {
    const pool = toCents(remaining.value);
    const weights = form.items.map((item) => weight(items.value.find((row) => row.key === item.key) ?? {}));
    const sum = weights.reduce((a, b) => a + b, 0);
    const total = Math.min(pool, sum > 0 ? sum : pool);

    if (total <= 0) return;

    const shares =
        sum > 0
            ? weights.map((w) => Math.floor((total * w) / sum))
            : weights.map(() => Math.floor(total / weights.length));

    let rest = total - shares.reduce((a, b) => a + b, 0);
    for (let i = 0; rest > 0 && i < shares.length; i += 1, rest -= 1) shares[i] += 1;

    form.items = form.items.map((item, index) => ({ ...item, amount: shares[index] / 100 }));
}

function close() {
    if (form.processing) return;

    emit('close');
}

function submit() {
    if (!canSubmit.value) return;

    form.transform((data) => ({ ...data, items: data.items.filter((item) => toCents(item.amount) > 0) })).post(
        props.action,
        {
            preserveScroll: true,
            onSuccess: (page) => {
                if (page?.props?.flash?.error) return;

                emit('close', true);
            },
        },
    );
}

function onKeydown(event) {
    if (event.key === 'Escape') close();
}

watch(
    () => props.open,
    async (isOpen) => {
        if (!isOpen) {
            document.removeEventListener('keydown', onKeydown);

            return;
        }

        form.reset();
        form.clearErrors();
        form.items = items.value.map((row) => ({ key: row.key, amount: null }));

        if (props.receipts === null) emit('load');

        document.addEventListener('keydown', onKeydown);

        await nextTick();
        cancelButton.value?.focus();
    },
);

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="close">
        <template #header>
            <h5 :id="ids.title" class="modal-title mb-0">
                <i class="ti ti-cash-banknote me-1 text-primary" aria-hidden="true"></i>{{ t.allocate_title }}
            </h5>
        </template>

        <form data-test="allocate-form" :aria-labelledby="ids.title" @submit.prevent="submit">
            <p class="small text-muted">{{ t.allocate_intro }}</p>

            <div class="mb-3">
                <label :for="ids.receipt" class="form-label small mb-1">{{ t.allocate_receipt }}</label>
                <select
                    :id="ids.receipt"
                    v-model="form.cash_entry_id"
                    class="form-select form-select-sm"
                    :class="{ 'is-invalid': form.errors.cash_entry_id }"
                    :disabled="loading || form.processing"
                    :aria-describedby="form.errors.cash_entry_id ? `${ids.receipt}-error` : undefined"
                    data-test="allocate-receipt"
                >
                    <option value="">{{ t.allocate_receipt_placeholder }}</option>
                    <option v-for="receipt in receipts ?? []" :key="receipt.id" :value="receipt.id">
                        {{
                            tx('allocate_receipt_option', {
                                date: date(receipt.date),
                                description: receipt.description,
                                remaining: money(receipt.remaining),
                            })
                        }}
                    </option>
                </select>
                <div v-if="form.errors.cash_entry_id" :id="`${ids.receipt}-error`" class="invalid-feedback">
                    {{ form.errors.cash_entry_id }}
                </div>
                <p
                    v-if="!loading && receipts !== null && receipts.length === 0"
                    class="small text-muted mt-1 mb-0"
                    data-test="allocate-no-receipts"
                >
                    {{ t.allocate_no_receipts }}
                </p>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small text-muted">{{ tx('allocate_selected', { count: items.length }) }}</span>
                <button
                    type="button"
                    class="btn btn-link btn-sm text-decoration-none px-1"
                    :disabled="!selectedReceipt || form.processing"
                    data-test="allocate-suggest"
                    @click="suggest"
                >
                    <i class="ti ti-wand me-1" aria-hidden="true"></i>{{ t.allocate_suggest }}
                </button>
            </div>

            <ul class="list-unstyled d-grid gap-2 mb-3" data-test="allocate-items">
                <li
                    v-for="(item, index) in form.items"
                    :key="item.key"
                    class="d-flex flex-wrap align-items-center gap-2"
                    data-test="allocate-item"
                >
                    <label
                        :for="`${ids.title}-amount-${index}`"
                        class="small flex-grow-1 text-truncate allocate-receipt__label"
                    >
                        {{ rowLabel(items.find((row) => row.key === item.key) ?? {}) }}
                    </label>
                    <div class="allocate-receipt__amount">
                        <MoneyInput
                            :id="`${ids.title}-amount-${index}`"
                            v-model="item.amount"
                            :invalid="
                                Boolean(form.errors[`items.${index}.amount`] || form.errors[`items.${index}.key`])
                            "
                            :disabled="form.processing"
                            data-test="allocate-amount"
                        />
                    </div>
                </li>
            </ul>

            <p
                :id="ids.total"
                class="small fw-medium mb-3"
                :class="over ? 'text-danger' : 'text-body'"
                role="status"
                data-test="allocate-total"
            >
                {{ tx('allocate_total', { total: money(totalCents / 100), remaining: money(remaining) }) }}
                <span v-if="over" class="d-block">{{ t.allocate_over }}</span>
            </p>

            <div class="mb-2">
                <label :for="ids.notes" class="form-label small mb-1">{{ t.allocate_notes }}</label>
                <textarea
                    :id="ids.notes"
                    v-model="form.notes"
                    class="form-control form-control-sm"
                    rows="2"
                    maxlength="1000"
                    :disabled="form.processing"
                ></textarea>
            </div>

            <div
                v-if="generalErrors.length"
                :id="ids.error"
                class="alert alert-danger small py-2 mb-0"
                role="alert"
                data-test="allocate-errors"
            >
                <p v-for="message in generalErrors" :key="message" class="mb-0">{{ message }}</p>
            </div>
        </form>

        <template #footer>
            <button
                ref="cancelButton"
                type="button"
                class="btn btn-light btn-sm"
                :disabled="form.processing"
                @click="close"
            >
                {{ t.allocate_cancel }}
            </button>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="!canSubmit"
                :aria-describedby="over ? ids.total : undefined"
                data-test="allocate-confirm"
                @click="submit"
            >
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                >{{ t.allocate_confirm }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.allocate-receipt__label {
    min-width: 12rem;
}

.allocate-receipt__amount {
    width: 10rem;
}

@media (max-width: 575.98px) {
    .allocate-receipt__amount {
        width: 100%;
    }
}
</style>
