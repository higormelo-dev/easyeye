<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    open:         { type: Boolean, required: true },
    subscription: { type: Object,  default: null },
    plans:        { type: Array,   default: () => [] },
    trialDays:    { type: Number,  default: 14 },
    t:            { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const saving = ref(false);
const error  = ref('');
const form   = ref({ plan_id: '', days: '' });

watch(() => props.open, (val) => {
    if (val) { form.value = { plan_id: '', days: '' }; error.value = ''; }
});

async function submit() {
    saving.value = true;
    error.value  = '';
    try {
        const res = await fetch(route('panel.manager.subscriptions.trial'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                entity_id: props.subscription?.entity_id,
                plan_id:   form.value.plan_id || undefined,
                days:      form.value.days    ? Number(form.value.days) : undefined,
            }),
        });

        const json = await res.json();

        if (!res.ok) {
            error.value = json.message ?? 'Erro ao iniciar trial.';
            return;
        }

        emit('saved', json.message ?? props.t.trial_success);
        emit('close');
        router.reload({ only: ['subscriptions', 'total'] });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="modal fade show d-block"
            tabindex="-1"
            style="background:rgba(0,0,0,.4)"
            @click.self="$emit('close')"
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-clock-play me-2 text-info"></i>{{ t.trial_title }}
                        </h5>
                        <button type="button" class="btn-close" @click="$emit('close')"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Empresa -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">{{ t.trial_company }}</label>
                            <p class="form-control-plaintext fw-bold mb-0">{{ subscription?.entity_name ?? '—' }}</p>
                        </div>

                        <!-- Plano opcional -->
                        <div class="mb-3">
                            <label class="form-label">{{ t.trial_plan }}</label>
                            <select v-model="form.plan_id" class="form-select">
                                <option value="">{{ t.trial_plan_none }}</option>
                                <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                        </div>

                        <!-- Dias -->
                        <div class="mb-3">
                            <label class="form-label">{{ t.trial_days }}</label>
                            <input
                                v-model="form.days"
                                type="number"
                                min="1"
                                max="365"
                                class="form-control"
                                :placeholder="trialDays"
                            >
                            <div class="form-text">
                                {{ t.trial_days_hint?.replace(':days', trialDays) }}
                            </div>
                        </div>

                        <!-- Erro -->
                        <div v-if="error" class="alert alert-danger py-2 small mb-0">
                            <i class="ti ti-alert-circle me-1"></i>{{ error }}
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="$emit('close')">{{ t.btn_cancel }}</button>
                        <button type="button" class="btn btn-info text-white" :disabled="saving" @click="submit">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ t.trial_btn }}
                        </button>
                    </div>

                </div>
            </div>
        </div>
    </Teleport>
</template>
