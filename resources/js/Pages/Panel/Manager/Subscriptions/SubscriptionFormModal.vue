<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

const props = defineProps({
    open:           { type: Boolean, required: true },
    subscriptionId: { type: String,  default: null },
    plans:          { type: Array,   default: () => [] },
    statuses:       { type: Array,   default: () => [] },
    t:              { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const loading    = ref(false);
const saving     = ref(false);
const errors     = ref({});
const form       = ref({ plan_id: '', status: '', starts_at: '', ends_at: '', trial_ends_at: '' });

function resetForm() {
    form.value   = { plan_id: '', status: '', starts_at: '', ends_at: '', trial_ends_at: '' };
    errors.value = {};
}

async function loadData(id) {
    loading.value = true;
    try {
        const res  = await fetch(route('manager.subscriptions.show', id));
        const json = await res.json();
        const d    = json.data;
        form.value = {
            plan_id:       d.plan_id       ?? '',
            status:        d.status        ?? '',
            starts_at:     d.starts_at_raw ?? '',
            ends_at:       d.ends_at_raw   ?? '',
            trial_ends_at: d.trial_ends_at_raw ?? '',
        };
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (val) => {
    if (val) { resetForm(); if (props.subscriptionId) loadData(props.subscriptionId); }
});

async function submit() {
    saving.value = true;
    errors.value = {};
    try {
        const res = await fetch(route('manager.subscriptions.update', props.subscriptionId), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
            body: JSON.stringify(form.value),
        });

        const json = await res.json();

        if (!res.ok) {
            errors.value = json.errors ?? {};
            return;
        }

        emit('saved');
        emit('close');
        router.reload({ only: ['subscriptions', 'total'] });
    } finally {
        saving.value = false;
    }
}

function firstError(field) {
    const e = errors.value[field];
    return Array.isArray(e) ? e[0] : (e ?? '');
}
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="480"
        :loading="loading"
        :loading-label="t.loading"
        @close="$emit('close')"
    >
        <!-- Header -->
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="fas fa-file-contract me-2 text-primary"></i>
                {{ t.form_edit_title }}
            </h5>
        </template>

        <!-- Body -->
        <form @submit.prevent="submit">
            <div class="row g-3">

                <!-- Plano -->
                <div class="col-md-6">
                    <label class="form-label">{{ t.form_field_plan }}</label>
                    <SearchSelect
                        v-model="form.plan_id"
                        :options="plans"
                        :placeholder="t.form_select_plan"
                        :invalid="!!firstError('plan_id')"
                    />
                    <div v-if="firstError('plan_id')" class="invalid-feedback d-block">{{ firstError('plan_id') }}</div>
                </div>

                <!-- Status -->
                <div class="col-md-6">
                    <label class="form-label">{{ t.form_field_status }}</label>
                    <SearchSelect
                        v-model="form.status"
                        :options="statuses"
                        :value-key="'value'"
                        :label-key="'label'"
                        :clearable="false"
                        :invalid="!!firstError('status')"
                    />
                    <div v-if="firstError('status')" class="invalid-feedback d-block">{{ firstError('status') }}</div>
                </div>

                <!-- Início -->
                <div class="col-md-4">
                    <label class="form-label">{{ t.form_field_starts }}</label>
                    <input
                        v-model="form.starts_at"
                        type="date"
                        class="form-control"
                        :class="{ 'is-invalid': firstError('starts_at') }"
                    >
                    <div v-if="firstError('starts_at')" class="invalid-feedback">{{ firstError('starts_at') }}</div>
                </div>

                <!-- Vencimento -->
                <div class="col-md-4">
                    <label class="form-label">{{ t.form_field_ends }}</label>
                    <input v-model="form.ends_at" type="date" class="form-control">
                </div>

                <!-- Trial até -->
                <div class="col-md-4">
                    <label class="form-label">{{ t.form_field_trial }}</label>
                    <input v-model="form.trial_ends_at" type="date" class="form-control">
                </div>

            </div>
        </form>

        <!-- Footer -->
        <template #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ t.btn_cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="saving" @click="submit">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                {{ t.btn_save_changes }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
