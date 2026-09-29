<script setup>
import { useId } from 'vue';
import { useForm } from '@inertiajs/vue3';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';

/**
 * Novo ajuste manual de um fechamento ainda não pago (acréscimo ou desconto:
 * adiantamento, imposto retido, bônus…). O valor vai sempre positivo; o
 * sinal vem do tipo (DoctorPayoutAdjustmentRequest::signedCents()).
 */
const props = defineProps({
    action: { type: String, required: true },   // routes.adjustments_store
    t:      { type: Object, default: () => ({}) },
});

const KINDS = ['credit', 'debit'];

const uid = useId();
const ids = {
    hint:        `dp-adj-hint-${uid}`,
    description: `dp-adj-description-${uid}`,
    kind:        `dp-adj-kind-${uid}`,
    amount:      `dp-adj-amount-${uid}`,
};

const form = useForm({ description: '', kind: 'credit', amount: null });

function kindLabel(kind) {
    return kind === 'debit' ? props.t.adjustment_kind_debit : props.t.adjustment_kind_credit;
}

function describedBy(field) {
    return [ids.hint, form.errors[field] ? `${ids[field]}-error` : null].filter(Boolean).join(' ');
}

function submit() {
    if (form.processing) return;

    form.post(props.action, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <form class="border-top pt-3 mt-3" novalidate data-test="adjustment-form" @submit.prevent="submit">
        <h4 class="small fw-bold mb-1">{{ t.adjustment_add }}</h4>
        <p :id="ids.hint" class="small text-muted mb-2">{{ t.adjustment_hint }}</p>

        <div class="row g-2 align-items-start">
            <div class="col-12 col-md-5">
                <label :for="ids.description" class="form-label small mb-1">{{ t.adjustment_description }}</label>
                <input
                    :id="ids.description"
                    v-model="form.description"
                    type="text"
                    maxlength="255"
                    class="form-control form-control-sm"
                    :class="{ 'is-invalid': form.errors.description }"
                    :aria-invalid="form.errors.description ? 'true' : undefined"
                    :aria-describedby="describedBy('description')"
                    autocomplete="off"
                    data-test="adjustment-description"
                >
                <div v-if="form.errors.description" :id="`${ids.description}-error`" class="invalid-feedback d-block">{{ form.errors.description }}</div>
            </div>
            <div class="col-6 col-md-3">
                <label :for="ids.kind" class="form-label small mb-1">{{ t.adjustment_kind }}</label>
                <select
                    :id="ids.kind"
                    v-model="form.kind"
                    class="form-select form-select-sm"
                    :class="{ 'is-invalid': form.errors.kind }"
                    :aria-invalid="form.errors.kind ? 'true' : undefined"
                    :aria-describedby="form.errors.kind ? `${ids.kind}-error` : undefined"
                    data-test="adjustment-kind"
                >
                    <option v-for="kind in KINDS" :key="kind" :value="kind">{{ kindLabel(kind) }}</option>
                </select>
                <div v-if="form.errors.kind" :id="`${ids.kind}-error`" class="invalid-feedback d-block">{{ form.errors.kind }}</div>
            </div>
            <div class="col-6 col-md-2">
                <label :for="ids.amount" class="form-label small mb-1">{{ t.adjustment_amount }}</label>
                <MoneyInput
                    :id="ids.amount"
                    v-model="form.amount"
                    :invalid="Boolean(form.errors.amount)"
                    :aria-describedby="form.errors.amount ? `${ids.amount}-error` : undefined"
                    data-test="adjustment-amount-input"
                />
                <div v-if="form.errors.amount" :id="`${ids.amount}-error`" class="invalid-feedback d-block">{{ form.errors.amount }}</div>
            </div>
            <div class="col-12 col-md-2 d-grid adjustment-form__submit">
                <button type="submit" class="btn btn-outline-primary btn-sm" :disabled="form.processing" data-test="adjustment-submit">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    <i v-else class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.adjustment_add }}
                </button>
            </div>
        </div>
    </form>
</template>

<style scoped>
/* Alinha o botão à linha dos campos (abaixo dos rótulos) a partir do md. */
@media (min-width: 768px) {
    .adjustment-form__submit {
        padding-top: 1.5rem;
    }
}
</style>
