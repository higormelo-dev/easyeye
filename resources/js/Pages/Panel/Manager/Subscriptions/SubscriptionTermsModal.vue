<script setup>
import { computed, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import ReasonField from '@/Components/Panel/ReasonField.vue';
import { useTrans } from '@/composables/useTrans.js';
import { addMonthsNoOverflow, toDateInput } from '@/utils/billingPeriods.js';
import { useSubscriptionRequest } from './useSubscriptionRequest.js';

/**
 * Alterar a assinatura vigente: plano e período, como cortesia (sem
 * cobrança). Se ela era cobrada pelo gateway, a cobrança automática é
 * cancelada lá ao salvar; cobrar de novo é criar uma nova assinatura.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    subscription: { type: Object, default: null },
    plans: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved', 'createNew']);

const { tx } = useTrans(() => props.t);
const { saving, errors, message, send, reset } = useSubscriptionRequest(() => props.t.request_failed);

const form = ref({});
const reasonField = ref(null);

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen || !props.subscription) return;

        const s = props.subscription;
        const today = new Date();
        // Vencida (inclusive na graça) ou trial: o novo período começa hoje;
        // senão mantém as datas atuais.
        const ended = !!s.ends_at && new Date(s.ends_at) <= today;
        const startsFresh = s.modality === 'trial' || !s.has_access || ended;

        reset();
        form.value = {
            plan_id: s.plan_id,
            starts_at: toDateInput(startsFresh ? today : (s.starts_at ?? today)),
            ends_at: !startsFresh && s.ends_at ? toDateInput(s.ends_at) : toDateInput(addMonthsNoOverflow(today, 1)),
            reason: '',
        };
    },
);

const planOptions = computed(() =>
    props.plans
        .filter((p) => p.active || p.id === form.value.plan_id)
        .map((p) => ({ id: p.id, name: p.active ? p.name : `${p.name} ${props.t.plan_inactive_suffix}` })),
);

const endsInPast = computed(() => !!form.value.ends_at && form.value.ends_at < toDateInput(new Date()));

const canSubmit = computed(() => !saving.value && !!form.value.plan_id && !!reasonField.value?.valid);

function payload() {
    const f = form.value;

    return { plan_id: f.plan_id, mode: 'complimentary', starts_at: f.starts_at, ends_at: f.ends_at, reason: f.reason };
}

async function submit() {
    if (!canSubmit.value) return;

    const data = await send('put', route('manager.subscriptions.update', props.subscription.id), payload());
    if (!data) return;

    emit('saved', data.message);
    emit('close');
}

function close() {
    if (!saving.value) emit('close');
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="close">
        <template #header>
            <div class="min-w-0">
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-adjustments-dollar me-2 text-primary" aria-hidden="true"></i>{{ t.terms_title }}
                </h5>
                <div v-if="subscription" class="small text-muted text-truncate">{{ subscription.entity_name }}</div>
            </div>
        </template>

        <form v-if="subscription" class="d-grid gap-3" novalidate @submit.prevent="submit">
            <p class="text-muted small mb-0">{{ t.terms_intro }}</p>

            <div v-if="subscription.modality === 'trial'" class="alert alert-info small mb-0">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.terms_trial_note }}
            </div>
            <div v-if="subscription.has_gateway_recurrence" class="alert alert-warning small mb-0">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                {{ tx('terms_gateway_warning', { gateway: String(subscription.gateway ?? '').toUpperCase() }) }}
            </div>

            <!-- Plano -->
            <div>
                <label class="form-label fw-medium">{{ t.field_plan }}</label>
                <SearchSelect
                    v-model="form.plan_id"
                    :options="planOptions"
                    :placeholder="t.select_placeholder"
                    :clearable="false"
                    :invalid="!!errors.plan_id"
                />
                <div v-if="errors.plan_id" class="invalid-feedback d-block">{{ errors.plan_id }}</div>
            </div>

            <!-- Modalidade: alterar sempre deixa a assinatura como cortesia -->
            <div data-test="terms-modality">
                <span class="form-label fw-medium d-block">{{ t.field_mode }}</span>
                <div class="terms-modality">
                    <span class="fw-semibold small">
                        <i class="ti ti-gift me-1 text-primary" aria-hidden="true"></i>{{ t.modality?.complimentary }}
                    </span>
                    <span class="small text-muted">{{ t.modality_hint?.complimentary }}</span>
                </div>
                <div class="form-text">
                    {{ t.terms_gateway_hint }}
                    <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="$emit('createNew')">
                        {{ t.action_new_for_company }}
                    </button>
                </div>
            </div>

            <!-- Período -->
            <div class="row g-3">
                <div class="col-sm-6">
                    <label for="terms-starts" class="form-label">{{ t.field_starts_at }}</label>
                    <input
                        id="terms-starts"
                        v-model="form.starts_at"
                        type="date"
                        class="form-control"
                        :class="{ 'is-invalid': errors.starts_at }"
                    />
                    <div v-if="errors.starts_at" class="invalid-feedback">{{ errors.starts_at }}</div>
                </div>
                <div class="col-sm-6">
                    <label for="terms-ends" class="form-label">{{ t.field_ends_at }}</label>
                    <input
                        id="terms-ends"
                        v-model="form.ends_at"
                        type="date"
                        :min="form.starts_at"
                        class="form-control"
                        :class="{ 'is-invalid': errors.ends_at }"
                    />
                    <div v-if="errors.ends_at" class="invalid-feedback">{{ errors.ends_at }}</div>
                </div>
            </div>

            <div v-if="endsInPast" class="alert alert-danger small mb-0">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.terms_past_end_warning }}
            </div>

            <ReasonField
                ref="reasonField"
                v-model="form.reason"
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
                {{ t.btn_save_terms }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.terms-modality {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--bs-primary);
    border-radius: var(--bs-border-radius);
    background: var(--bs-primary-bg-subtle);
}
</style>
