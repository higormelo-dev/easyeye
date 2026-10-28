<script setup>
import { computed, ref, watch } from 'vue';
import { choice } from '@/utils/billingPeriods.js';

/**
 * Dias de trial de toda empresa nova. Não há período de graça: acabou o
 * trial, o acesso é bloqueado até a empresa contratar um plano.
 */
const props = defineProps({
    trialDays: { type: Number, required: true },
    t: { type: Object, default: () => ({}) },
});

const days = ref(props.trialDays);
const saved = ref(props.trialDays);
const saving = ref(false);
const error = ref('');

watch(
    () => props.trialDays,
    (value) => {
        days.value = value;
        saved.value = value;
    },
);

const valid = computed(() => Number.isInteger(days.value) && days.value >= 1 && days.value <= 365);
const dirty = computed(() => days.value !== saved.value);

async function save() {
    if (!valid.value || !dirty.value || saving.value) return;

    saving.value = true;
    error.value = '';

    try {
        const { data } = await window.axios.put(
            route('manager.plans.trial-settings'),
            { trial_days: days.value },
            { headers: { Accept: 'application/json' } },
        );
        saved.value = data?.data?.trial_days ?? days.value;
        window.showSuccessToast?.(data?.message ?? props.t.trial_saved);
    } catch (err) {
        const response = err?.response?.data ?? {};
        error.value = response.errors?.trial_days?.[0] ?? response.message ?? props.t.trial_save_failed;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <section class="card mt-4" aria-labelledby="trial-settings-title" data-test="trial-settings">
        <div class="card-header py-2">
            <h2 id="trial-settings-title" class="h6 fw-semibold mb-0">
                <i class="ti ti-clock-play me-1 text-primary" aria-hidden="true"></i>{{ t.trial_title }}
            </h2>
        </div>
        <div class="card-body py-3">
            <form class="d-flex flex-wrap align-items-end gap-3" novalidate @submit.prevent="save">
                <div>
                    <label for="trial-days" class="form-label small mb-1">{{ t.trial_days }}</label>
                    <div class="input-group input-group-sm trial-days-input">
                        <input
                            id="trial-days"
                            v-model.number="days"
                            type="number"
                            min="1"
                            max="365"
                            step="1"
                            class="form-control"
                            :class="{ 'is-invalid': error || !valid }"
                            aria-describedby="trial-days-hint"
                        />
                        <span class="input-group-text">{{ choice(t.trial_days_unit, days || 0) }}</span>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" :disabled="!valid || !dirty || saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ t.trial_save }}
                </button>
            </form>
            <div v-if="error" class="invalid-feedback d-block" role="alert">{{ error }}</div>
            <p id="trial-days-hint" class="form-text mb-0 mt-2">{{ t.trial_hint }}</p>
        </div>
    </section>
</template>

<style scoped>
.trial-days-input {
    width: 10rem;
}
</style>
