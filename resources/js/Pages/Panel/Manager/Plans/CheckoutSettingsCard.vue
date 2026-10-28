<script setup>
import { computed, ref, watch } from 'vue';
import { choice } from '@/utils/billingPeriods.js';

/**
 * Máximo de parcelas sem juros do checkout transparente (cartão, ciclo
 * anual; o EasyEye absorve a taxa). O gateway pode aceitar menos (Stripe: só
 * à vista) e a parcela mínima vale — o checkout usa o menor dos tetos.
 */
const props = defineProps({
    maxInstallments: { type: Number, required: true },
    t: { type: Object, default: () => ({}) },
});

const OPTIONS = Array.from({ length: 12 }, (_, i) => i + 1);

const value = ref(props.maxInstallments);
const saved = ref(props.maxInstallments);
const saving = ref(false);
const error = ref('');

watch(
    () => props.maxInstallments,
    (next) => {
        value.value = next;
        saved.value = next;
    },
);

const valid = computed(() => Number.isInteger(value.value) && value.value >= 1 && value.value <= 12);
const dirty = computed(() => value.value !== saved.value);

async function save() {
    if (!valid.value || !dirty.value || saving.value) return;

    saving.value = true;
    error.value = '';

    try {
        const { data } = await window.axios.put(
            route('manager.plans.checkout-settings'),
            { max_installments: value.value },
            { headers: { Accept: 'application/json' } },
        );
        saved.value = data?.data?.max_installments ?? value.value;
        window.showSuccessToast?.(data?.message ?? props.t.checkout_saved);
    } catch (err) {
        const response = err?.response?.data ?? {};
        error.value = response.errors?.max_installments?.[0] ?? response.message ?? props.t.checkout_save_failed;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <section class="card mt-4" aria-labelledby="checkout-settings-title" data-test="checkout-settings">
        <div class="card-header py-2">
            <h2 id="checkout-settings-title" class="h6 fw-semibold mb-0">
                <i class="ti ti-credit-card me-1 text-primary" aria-hidden="true"></i>{{ t.checkout_title }}
            </h2>
        </div>
        <div class="card-body py-3">
            <form class="d-flex flex-wrap align-items-end gap-3" novalidate @submit.prevent="save">
                <div>
                    <label for="checkout-max-installments" class="form-label small mb-1">{{
                        t.checkout_max_installments
                    }}</label>
                    <select
                        id="checkout-max-installments"
                        v-model.number="value"
                        class="form-select form-select-sm checkout-installments-input"
                        :class="{ 'is-invalid': error }"
                        aria-describedby="checkout-installments-hint"
                        data-test="checkout-max-installments"
                    >
                        <option v-for="option in OPTIONS" :key="option" :value="option">
                            {{ option }} {{ choice(t.checkout_installments_unit, option) }}
                        </option>
                    </select>
                </div>
                <button
                    type="submit"
                    class="btn btn-primary btn-sm"
                    :disabled="!valid || !dirty || saving"
                    data-test="checkout-settings-save"
                >
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ t.checkout_save }}
                </button>
            </form>
            <div v-if="error" class="invalid-feedback d-block" role="alert">{{ error }}</div>
            <p id="checkout-installments-hint" class="form-text mb-0 mt-2">{{ t.checkout_installments_hint }}</p>
        </div>
    </section>
</template>

<style scoped>
.checkout-installments-input {
    width: 12rem;
}
</style>
