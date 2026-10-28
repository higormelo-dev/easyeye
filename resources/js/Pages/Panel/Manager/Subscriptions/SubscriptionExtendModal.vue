<script setup>
import { computed, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import ReasonField from '@/Components/Panel/ReasonField.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { choice, extendedEnd } from '@/utils/billingPeriods.js';
import { useSubscriptionRequest } from './useSubscriptionRequest.js';

/**
 * Adicionar período (estilo "renovar"): soma dias/meses/anos ao término
 * atual — ou a partir de hoje se já venceu — e mostra o novo término antes
 * de salvar.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    subscription: { type: Object, default: null },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const { date } = useLocaleFormat();
const { tx } = useTrans(() => props.t);
const { saving, errors, message, send, reset } = useSubscriptionRequest(() => props.t.request_failed);

const QUICK = [
    { unit: 'days', quantity: 7 },
    { unit: 'days', quantity: 15 },
    { unit: 'months', quantity: 1 },
    { unit: 'months', quantity: 3 },
    { unit: 'months', quantity: 6 },
    { unit: 'years', quantity: 1 },
];
const UNITS = ['days', 'months', 'years'];

const unit = ref('months');
const quantity = ref(1);
const reason = ref('');
const reasonField = ref(null);

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        reset();
        unit.value = 'months';
        quantity.value = 1;
        reason.value = '';
    },
);

const isTrial = computed(() => props.subscription?.modality === 'trial');
const currentEnd = computed(() => props.subscription?.access_ends_at ?? null);
const alreadyEnded = computed(() => !currentEnd.value || new Date(currentEnd.value) <= new Date());
const isReactivation = computed(() => props.subscription?.status === 'expired' && !isTrial.value);

const newEnd = computed(() => extendedEnd(currentEnd.value, unit.value, quantity.value));

function quickLabel(option) {
    return choice(props.t[`period_chip_${option.unit}`], option.quantity, { n: option.quantity });
}

function pick(option) {
    unit.value = option.unit;
    quantity.value = option.quantity;
}

const canSubmit = computed(() => !saving.value && quantity.value >= 1 && !!reasonField.value?.valid);

async function submit() {
    if (!canSubmit.value) return;

    const data = await send('post', route('manager.subscriptions.extend', props.subscription.id), {
        unit: unit.value,
        quantity: quantity.value,
        reason: reason.value,
    });
    if (!data) return;

    emit('saved', data.message);
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
                    <i class="ti ti-calendar-plus me-2 text-primary" aria-hidden="true"></i>{{ t.extend_title }}
                </h5>
                <div v-if="subscription" class="small text-muted text-truncate">
                    {{ subscription.entity_name }} · {{ subscription.plan_name }}
                </div>
            </div>
        </template>

        <form v-if="subscription" class="d-grid gap-3" novalidate @submit.prevent="submit">
            <!-- Término atual -->
            <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2">
                <span class="text-muted small">{{ isTrial ? t.extend_trial_end : t.extend_current_end }}</span>
                <strong>{{ currentEnd ? date(currentEnd) : '—' }}</strong>
            </div>
            <p class="small text-muted mb-0">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                {{ isReactivation ? t.extend_reactivate : alreadyEnded ? t.extend_from_today : t.extend_from_end }}
            </p>

            <!-- Períodos rápidos -->
            <div class="d-flex flex-wrap gap-2" role="group" :aria-label="t.extend_title">
                <button
                    v-for="option in QUICK"
                    :key="`${option.unit}-${option.quantity}`"
                    type="button"
                    class="btn btn-sm"
                    :class="
                        unit === option.unit && quantity === option.quantity ? 'btn-primary' : 'btn-outline-secondary'
                    "
                    :aria-pressed="unit === option.unit && quantity === option.quantity"
                    @click="pick(option)"
                >
                    {{ quickLabel(option) }}
                </button>
            </div>

            <!-- Outro período -->
            <fieldset class="row g-2 align-items-end">
                <legend class="form-label small mb-1">{{ t.extend_custom }}</legend>
                <div class="col-5">
                    <label for="extend-qty" class="visually-hidden">{{ t.extend_quantity }}</label>
                    <input
                        id="extend-qty"
                        v-model.number="quantity"
                        type="number"
                        min="1"
                        class="form-control"
                        :class="{ 'is-invalid': errors.quantity }"
                    />
                </div>
                <div class="col-7">
                    <label for="extend-unit" class="visually-hidden">{{ t.extend_unit }}</label>
                    <select id="extend-unit" v-model="unit" class="form-select" :class="{ 'is-invalid': errors.unit }">
                        <option v-for="u in UNITS" :key="u" :value="u">{{ t[`unit_${u}`] }}</option>
                    </select>
                </div>
                <div v-if="errors.quantity || errors.unit" class="col-12 invalid-feedback d-block">
                    {{ errors.quantity || errors.unit }}
                </div>
            </fieldset>

            <!-- Prévia -->
            <div class="extend-preview" aria-live="polite">
                <span class="text-muted small">{{ t.extend_new_end }}</span>
                <strong class="fs-5">{{ date(newEnd.toISOString()) }}</strong>
            </div>

            <div v-if="subscription.has_gateway_recurrence" class="alert alert-warning small mb-0">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                {{ tx('extend_gateway_warning', { gateway: String(subscription.gateway ?? '').toUpperCase() }) }}
            </div>

            <ReasonField
                ref="reasonField"
                v-model="reason"
                :label="t.field_reason"
                :hint="t.reason_hint"
                :placeholder="t.reason_placeholder"
                :error="errors.reason"
            />

            <div v-if="message" class="alert alert-danger small d-flex gap-2 mb-0" role="alert">
                <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i><span>{{ message }}</span>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="saving" @click="close">{{ t.btn_cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="!canSubmit" @click="submit">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ t.btn_extend }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.extend-preview {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 0.5rem;
    padding: 0.75rem;
    border-radius: var(--bs-border-radius);
    background: var(--bs-tertiary-bg);
}
</style>
