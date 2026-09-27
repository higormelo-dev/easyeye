<script setup>
/**
 * Campos comuns do recebimento em lote (guias selecionadas e lote inteiro):
 * data (não futura), forma (as mesmas do recebimento individual) e
 * observação. Erros do servidor por campo, ligados ao campo (aria).
 */
const paidAt        = defineModel('paidAt', { type: String, default: '' });
const paymentMethod = defineModel('paymentMethod', { type: String, default: '' });
const notes         = defineModel('notes', { type: String, default: '' });

defineProps({
    paymentMethods: { type: Array,   default: () => [] },
    today:          { type: String,  default: '' },
    /** { paid_at?, payment_method?, notes? } — mensagens já traduzidas. */
    errors:         { type: Object,  default: () => ({}) },
    /** Prefixo dos ids (dois modais podem estar na página). */
    idPrefix:       { type: String,  required: true },
    disabled:       { type: Boolean, default: false },
    t:              { type: Object,  default: () => ({}) },
});
</script>

<template>
    <div class="row g-3">
        <div class="col-12 col-sm-6">
            <label :for="`${idPrefix}-date`" class="form-label">
                {{ t.receipt_paid_at }} <span class="text-danger" aria-hidden="true">*</span>
            </label>
            <input
                :id="`${idPrefix}-date`"
                v-model="paidAt"
                type="date"
                :max="today || null"
                required
                aria-required="true"
                class="form-control"
                :class="{ 'is-invalid': errors.paid_at }"
                :aria-invalid="errors.paid_at ? 'true' : 'false'"
                :aria-describedby="errors.paid_at ? `${idPrefix}-date-error` : undefined"
                :disabled="disabled"
                data-test="receipt-date"
            >
            <div v-if="errors.paid_at" :id="`${idPrefix}-date-error`" class="invalid-feedback">{{ errors.paid_at }}</div>
        </div>
        <div class="col-12 col-sm-6">
            <label :for="`${idPrefix}-method`" class="form-label">{{ t.receipt_payment_method }}</label>
            <select
                :id="`${idPrefix}-method`"
                v-model="paymentMethod"
                class="form-select"
                :class="{ 'is-invalid': errors.payment_method }"
                :aria-invalid="errors.payment_method ? 'true' : 'false'"
                :aria-describedby="errors.payment_method ? `${idPrefix}-method-error` : undefined"
                :disabled="disabled"
                data-test="receipt-method"
            >
                <option v-for="m in paymentMethods" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
            <div v-if="errors.payment_method" :id="`${idPrefix}-method-error`" class="invalid-feedback">{{ errors.payment_method }}</div>
        </div>
        <div class="col-12">
            <label :for="`${idPrefix}-notes`" class="form-label">{{ t.receipt_notes }}</label>
            <textarea
                :id="`${idPrefix}-notes`"
                v-model="notes"
                rows="2"
                maxlength="1000"
                class="form-control"
                :class="{ 'is-invalid': errors.notes }"
                :aria-invalid="errors.notes ? 'true' : 'false'"
                :aria-describedby="errors.notes ? `${idPrefix}-notes-error` : undefined"
                :disabled="disabled"
                data-test="receipt-notes"
            ></textarea>
            <div v-if="errors.notes" :id="`${idPrefix}-notes-error`" class="invalid-feedback">{{ errors.notes }}</div>
        </div>
    </div>
</template>
