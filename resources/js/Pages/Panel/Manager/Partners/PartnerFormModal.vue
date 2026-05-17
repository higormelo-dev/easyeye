<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:         { type: Boolean, required: true },
    partnerId:    { type: String,  default: null },
    editDataUrl:  { type: String,  default: '' },
    updateUrl:    { type: String,  default: '' },
    partnerTypes: { type: Array,   default: () => [] },
    t:            { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const loading = ref(false);
const saving  = ref(false);
const errors  = ref({});

const defaultForm = () => ({
    name:            '',
    email:           '',
    type:            '',
    document:        '',
    commission_rate: '',
    notes:           '',
    status:          'active',
});

const form   = ref(defaultForm());
const isEdit = computed(() => !!props.partnerId);

const defaultRates = Object.fromEntries(
    props.partnerTypes.map(t => [t.value, t.default_rate])
);

function resetForm() {
    form.value   = defaultForm();
    errors.value = {};
}

async function loadData() {
    loading.value = true;
    try {
        const res  = await fetch(props.editDataUrl);
        const json = await res.json();
        const d    = json.data;
        form.value = {
            name:            d.name            ?? '',
            email:           d.email           ?? '',
            type:            d.type            ?? '',
            document:        d.document        ?? '',
            commission_rate: d.commission_rate ?? '',
            notes:           d.notes           ?? '',
            status:          d.status          ?? 'active',
        };
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (val) => {
    if (val) {
        resetForm();
        if (props.partnerId) loadData();
    }
});

function onTypeChange() {
    if (!isEdit.value && form.value.type && defaultRates[form.value.type]) {
        form.value.commission_rate = defaultRates[form.value.type];
    }
}

async function submit() {
    saving.value = true;
    errors.value = {};
    try {
        const url    = isEdit.value ? props.updateUrl : route('panel.manager.partners.store');
        const method = isEdit.value ? 'PUT' : 'POST';

        const res = await fetch(url, {
            method,
            headers: {
                'Content-Type':  'application/json',
                'Accept':        'application/json',
                'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(form.value),
        });

        const json = await res.json();

        if (!res.ok) {
            errors.value = json.errors ?? {};
            return;
        }

        showToast(json.message, 'success');
        emit('saved');
        emit('close');
        router.reload({ only: ['partners', 'kpis', 'recentCommissions'] });
    } finally {
        saving.value = false;
    }
}

function err(field) {
    const e = errors.value[field];
    return Array.isArray(e) ? e[0] : (e ?? '');
}

function showToast(msg, type = 'success') {
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error'   && window.showErrorToast)   return window.showErrorToast(msg);
}
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :loading="loading"
        :loading-label="t.saving"
        @close="$emit('close')"
    >
        <template #header>
            <div>
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-affiliate me-2 text-primary"></i>
                    {{ isEdit ? t.edit : t.new }}
                </h5>
            </div>
        </template>

        <div class="row g-3">

            <!-- Nome -->
            <div class="col-12 col-sm-6">
                <label class="form-label">{{ t.field_name }} <span class="text-danger">*</span></label>
                <input
                    v-model="form.name"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': err('name') }"
                    :placeholder="t.field_name_ph"
                    maxlength="255"
                >
                <div v-if="err('name')" class="invalid-feedback">{{ err('name') }}</div>
            </div>

            <!-- E-mail -->
            <div class="col-12 col-sm-6">
                <label class="form-label">{{ t.field_email }} <span class="text-danger">*</span></label>
                <input
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :class="{ 'is-invalid': err('email') }"
                    :placeholder="t.field_email_ph"
                    :disabled="isEdit"
                    maxlength="255"
                >
                <div v-if="err('email')" class="invalid-feedback">{{ err('email') }}</div>
                <div v-if="!isEdit" class="form-text small">{{ t.email_hint }}</div>
            </div>

            <!-- Tipo -->
            <div class="col-12 col-sm-4">
                <label class="form-label">{{ t.field_type }} <span class="text-danger">*</span></label>
                <select
                    v-model="form.type"
                    class="form-select"
                    :class="{ 'is-invalid': err('type') }"
                    @change="onTypeChange"
                >
                    <option value="">{{ t.field_select }}</option>
                    <option v-for="tp in partnerTypes" :key="tp.value" :value="tp.value">{{ tp.label }}</option>
                </select>
                <div v-if="err('type')" class="invalid-feedback">{{ err('type') }}</div>
            </div>

            <!-- Taxa de Comissão -->
            <div class="col-12 col-sm-4">
                <label class="form-label">{{ t.field_commission_rate }}</label>
                <div class="input-group">
                    <input
                        v-model="form.commission_rate"
                        type="number"
                        step="0.01" min="0" max="100"
                        class="form-control"
                        :class="{ 'is-invalid': err('commission_rate') }"
                        placeholder="0.00"
                    >
                    <span class="input-group-text">%</span>
                    <div v-if="err('commission_rate')" class="invalid-feedback">{{ err('commission_rate') }}</div>
                </div>
            </div>

            <!-- CNPJ/CPF -->
            <div class="col-12 col-sm-4">
                <label class="form-label">{{ t.field_document }}</label>
                <input
                    v-model="form.document"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': err('document') }"
                    :placeholder="t.field_document_ph"
                    maxlength="18"
                >
                <div v-if="err('document')" class="invalid-feedback">{{ err('document') }}</div>
            </div>

            <!-- Status (somente edit) -->
            <div v-if="isEdit" class="col-12 col-sm-4">
                <label class="form-label">{{ t.field_status }}</label>
                <select v-model="form.status" class="form-select">
                    <option value="active">{{ t.status_active }}</option>
                    <option value="inactive">{{ t.status_inactive }}</option>
                    <option value="suspended">{{ t.status_suspended }}</option>
                </select>
            </div>

            <!-- Observações -->
            <div class="col-12">
                <label class="form-label">{{ t.field_notes }}</label>
                <textarea
                    v-model="form.notes"
                    class="form-control"
                    :class="{ 'is-invalid': err('notes') }"
                    :placeholder="t.field_notes_ph"
                    rows="3"
                    maxlength="1000"
                ></textarea>
                <div v-if="err('notes')" class="invalid-feedback">{{ err('notes') }}</div>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-secondary" @click="$emit('close')">{{ t.cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="saving" @click="submit">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                {{ saving ? t.saving : (isEdit ? t.save : t.register) }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
