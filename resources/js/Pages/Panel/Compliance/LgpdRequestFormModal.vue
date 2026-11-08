<script setup>
import { computed, nextTick, ref, useId } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * Registrar pedido de titular recebido fora do sistema (LGPD art. 18). A data
 * de recebimento define o prazo (art. 19, II). Pede só o necessário para
 * responder e localizar os dados (art. 6º, III). Textos: `t` de lgpd_requests.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    types: { type: Array, default: () => [] },
    today: { type: String, required: true },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const uid = useId();
const fid = (field) => `lgpd-form-${uid}-${field}`;
// Ajuda e erro ligados juntos ao campo (MASTER.md §5).
const describedBy = (field, errors, hint = true) =>
    [hint ? fid(`${field}-hint`) : null, errors[field] ? fid(`${field}-error`) : null].filter(Boolean).join(' ') ||
    undefined;
const formRef = ref(null);

// Após envio inválido, foco no primeiro campo com erro (MASTER.md §5).
function focusFirstError() {
    nextTick(() => formRef.value?.querySelector('[aria-invalid="true"]')?.focus());
}

const form = useForm({
    requester_name: '',
    requester_email: '',
    requester_document: '',
    request_type: 'access',
    requested_at: props.today,
    patient_id: null,
    description: '',
});
const patientSearchUrl = computed(() => route('panel.patients.search', { q: '__Q__', include_inactive: 1 }));

function reset() {
    form.reset();
    form.clearErrors();
    form.requested_at = props.today;
}

function done() {
    reset();
    emit('close');
}

// Cancelar, X, Escape e fundo passam pela mesma proteção de alterações (MASTER.md §4).
function close() {
    if (form.isDirty && !window.confirm(props.t.form?.discard_confirm ?? '')) return;
    done();
}

function submit() {
    form.post(route('panel.reports.compliance.lgpd-requests.store'), {
        preserveScroll: true,
        onSuccess: done,
        onError: focusFirstError,
    });
}
</script>

<template>
    <CenteredModal :open="open" size="md" density="default" @close="close">
        <template #header>
            <h2 class="h5 mb-0">{{ t.form?.title }}</h2>
        </template>

        <form :id="fid('create')" ref="formRef" novalidate @submit.prevent="submit">
            <div class="mb-3">
                <label :for="fid('requester_name')" class="form-label">{{ t.form?.requester_name }}</label>
                <input
                    :id="fid('requester_name')"
                    v-model="form.requester_name"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.requester_name }"
                    required
                    aria-required="true"
                    :aria-invalid="!!form.errors.requester_name"
                    :aria-describedby="describedBy('requester_name', form.errors, false)"
                    maxlength="255"
                    autocomplete="off"
                />
                <div v-if="form.errors.requester_name" :id="fid('requester_name-error')" class="invalid-feedback">
                    {{ form.errors.requester_name }}
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label :for="fid('requester_email')" class="form-label">{{ t.form?.requester_email }}</label>
                    <input
                        :id="fid('requester_email')"
                        v-model="form.requester_email"
                        type="email"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.requester_email }"
                        required
                        aria-required="true"
                        :aria-invalid="!!form.errors.requester_email"
                        :aria-describedby="describedBy('requester_email', form.errors, false)"
                        maxlength="255"
                        autocomplete="off"
                    />
                    <div v-if="form.errors.requester_email" :id="fid('requester_email-error')" class="invalid-feedback">
                        {{ form.errors.requester_email }}
                    </div>
                </div>
                <div class="col-md-5">
                    <label :for="fid('requester_document')" class="form-label">
                        {{ t.form?.requester_document }}
                        <span class="text-muted fw-normal">{{ t.form?.optional }}</span>
                    </label>
                    <input
                        :id="fid('requester_document')"
                        v-model="form.requester_document"
                        type="text"
                        inputmode="numeric"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.requester_document }"
                        :aria-invalid="!!form.errors.requester_document"
                        :aria-describedby="describedBy('requester_document', form.errors)"
                        maxlength="14"
                        autocomplete="off"
                    />
                    <div :id="fid('requester_document-hint')" class="form-text">
                        {{ t.form?.requester_document_hint }}
                    </div>
                    <div
                        v-if="form.errors.requester_document"
                        :id="fid('requester_document-error')"
                        class="invalid-feedback d-block"
                    >
                        {{ form.errors.requester_document }}
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label :for="fid('request_type')" class="form-label">{{ t.form?.request_type }}</label>
                    <select
                        :id="fid('request_type')"
                        v-model="form.request_type"
                        class="form-select"
                        :class="{ 'is-invalid': form.errors.request_type }"
                        required
                        aria-required="true"
                        :aria-invalid="!!form.errors.request_type"
                        :aria-describedby="describedBy('request_type', form.errors, false)"
                    >
                        <option v-for="type in types" :key="type" :value="type">
                            {{ t.types?.[type] ?? type }}
                        </option>
                    </select>
                    <div v-if="form.errors.request_type" :id="fid('request_type-error')" class="invalid-feedback">
                        {{ form.errors.request_type }}
                    </div>
                </div>
                <div class="col-md-5">
                    <label :for="fid('requested_at')" class="form-label">{{ t.form?.requested_at }}</label>
                    <input
                        :id="fid('requested_at')"
                        v-model="form.requested_at"
                        type="date"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.requested_at }"
                        :max="today"
                        required
                        aria-required="true"
                        :aria-invalid="!!form.errors.requested_at"
                        :aria-describedby="describedBy('requested_at', form.errors)"
                    />
                    <div :id="fid('requested_at-hint')" class="form-text">{{ t.form?.requested_at_hint }}</div>
                    <div
                        v-if="form.errors.requested_at"
                        :id="fid('requested_at-error')"
                        class="invalid-feedback d-block"
                    >
                        {{ form.errors.requested_at }}
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label :id="fid('patient-label')" :for="fid('patient')" class="form-label">
                    {{ t.form?.patient }} <span class="text-muted fw-normal">{{ t.form?.optional }}</span>
                </label>
                <SearchSelect
                    :id="fid('patient')"
                    v-model="form.patient_id"
                    :aria-labelledby="fid('patient-label')"
                    :aria-describedby="describedBy('patient_id', form.errors)"
                    :remote-search-url="patientSearchUrl"
                    value-key="id"
                    label-key="full_name"
                    :placeholder="t.form?.patient_placeholder"
                    :invalid="!!form.errors.patient_id"
                />
                <div :id="fid('patient_id-hint')" class="form-text">{{ t.form?.patient_hint }}</div>
                <div v-if="form.errors.patient_id" :id="fid('patient_id-error')" class="invalid-feedback d-block">
                    {{ form.errors.patient_id }}
                </div>
            </div>

            <div class="mb-0">
                <label :for="fid('description')" class="form-label">{{ t.form?.description }}</label>
                <textarea
                    :id="fid('description')"
                    v-model="form.description"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.description }"
                    rows="4"
                    maxlength="5000"
                    required
                    aria-required="true"
                    :aria-invalid="!!form.errors.description"
                    :aria-describedby="describedBy('description', form.errors)"
                ></textarea>
                <div :id="fid('description-hint')" class="form-text">{{ t.form?.description_hint }}</div>
                <div v-if="form.errors.description" :id="fid('description-error')" class="invalid-feedback d-block">
                    {{ form.errors.description }}
                </div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" @click="close">
                {{ t.form?.cancel }}
            </button>
            <button
                type="submit"
                class="btn btn-primary"
                :form="fid('create')"
                :disabled="form.processing"
                data-test="lgpd-create-submit"
            >
                {{ t.form?.save }}
            </button>
        </template>
    </CenteredModal>
</template>
