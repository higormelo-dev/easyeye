<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import CidField from './CidField.vue';
import PreValidationResult from './PreValidationResult.vue';
import { firstError } from './billingHelpers.js';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/**
 * "Corrigir pendência" de uma guia TISS que ficou fora do lote (erro na
 * pré-validação) ou individual: CID (indicação clínica), carteirinha e nº da
 * autorização, abertos com os valores atuais da guia (`claim.fix_pending`).
 * Salvar manda para o servidor (JSON), que grava, refaz a pré-validação e —
 * sem erro e com o lote em rascunho — põe a guia no lote TISS. O resultado
 * (mensagem + erros/avisos) aparece aqui mesmo, num aviso ao vivo; a lista é
 * recarregada pelo Index (`saved`). O servidor decide se a correção é
 * permitida: recusa vem como erro geral traduzido.
 */
const props = defineProps({
    open:           { type: Boolean, default: false },
    claim:          { type: Object,  default: null },
    cid10SearchUrl: { type: String,  default: '' },
    t:              { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const FIELDS = ['clinical_indication', 'beneficiary_card_number', 'authorization_number'];

const form = ref({ clinical_indication: '', beneficiary_card_number: '', authorization_number: '' });
const errors       = ref({});
const generalError = ref('');
const processing   = ref(false);
const result       = ref(null);
const rootRef      = ref(null);
const resultRef    = ref(null);

const fixData = computed(() => props.claim?.fix_pending ?? null);

watch(() => props.open, (open) => {
    if (!open || !props.claim) return;

    form.value = {
        clinical_indication:     fixData.value?.clinical_indication ?? '',
        beneficiary_card_number: fixData.value?.beneficiary_card_number ?? '',
        authorization_number:    fixData.value?.authorization_number ?? '',
    };
    errors.value       = {};
    generalError.value = '';
    result.value       = null;
}, { immediate: true });

function describedBy(field, hintId = null) {
    return [hintId, errors.value[field] ? `billing-fix-${field}-error` : null].filter(Boolean).join(' ') || undefined;
}

function requestClose() {
    if (!processing.value) emit('close');
}

/** 422 do Laravel: erro de campo vai para o campo; o resto (ex.: guia já no lote) vira aviso geral. */
function applyServerErrors(data) {
    const serverErrors = data?.errors ?? {};
    const fieldErrors  = {};

    FIELDS.forEach((field) => {
        const message = serverErrors[field];
        if (message) fieldErrors[field] = Array.isArray(message) ? message[0] : String(message);
    });

    const others = Object.fromEntries(Object.entries(serverErrors).filter(([key]) => !FIELDS.includes(key)));

    errors.value       = fieldErrors;
    generalError.value = firstError(others) || (Object.keys(fieldErrors).length ? '' : (data?.message || props.t.fix_failed));
}

async function submit() {
    if (!fixData.value?.url || processing.value) return;

    processing.value   = true;
    errors.value       = {};
    generalError.value = '';

    try {
        const { data } = await window.axios.post(fixData.value.url, { ...form.value });

        result.value = data;
        emit('saved', data);
        nextTick(() => resultRef.value?.focus?.());
    } catch (error) {
        if (error?.response?.status === 422) {
            applyServerErrors(error.response.data);
        } else {
            generalError.value = props.t.fix_failed;
        }
    } finally {
        processing.value = false;
    }
}

useDialogKeyboard(() => props.open, { onEscape: requestClose, focusRef: rootRef });
</script>

<template>
    <CenteredModal :open="open" size="md" :close-label="t.btn_close" @close="requestClose">
        <template #header>
            <h2 class="h5 mb-0 fw-semibold">
                <i class="ti ti-tool me-2 text-primary" aria-hidden="true"></i>{{ t.fix_title }}
            </h2>
        </template>

        <form v-if="claim" ref="rootRef" novalidate data-test="fix-form" @submit.prevent="submit">
            <dl class="row small border rounded p-2 mx-0 mb-3 bg-body-tertiary" data-test="fix-summary">
                <dt class="col-5 fw-normal text-muted">{{ t.fix_summary_guide }}</dt>
                <dd class="col-7 mb-1 fw-semibold">{{ claim.code }}</dd>
                <template v-if="claim.guide_number && claim.guide_number !== claim.code">
                    <dt class="col-5 fw-normal text-muted">{{ t.fix_summary_tiss_number }}</dt>
                    <dd class="col-7 mb-1">{{ claim.guide_number }}</dd>
                </template>
                <dt class="col-5 fw-normal text-muted">{{ t.fix_summary_patient }}</dt>
                <dd class="col-7 mb-0">{{ claim.patient_name || '—' }}</dd>
            </dl>

            <p class="small text-muted">{{ t.fix_intro }}</p>

            <div v-if="generalError" class="alert alert-danger small py-2" role="alert" data-test="fix-error">{{ generalError }}</div>

            <div class="row g-3">
                <div class="col-12">
                    <CidField
                        id="billing-fix-cid"
                        v-model="form.clinical_indication"
                        :search-url="cid10SearchUrl"
                        :label="t.clinical_indication"
                        :placeholder="t.cid_placeholder"
                        :hint="t.clinical_indication_hint"
                        :error="errors.clinical_indication || ''"
                    />
                </div>
                <div class="col-12 col-sm-6">
                    <label for="billing-fix-card" class="form-label">{{ t.fix_card_number }}</label>
                    <input
                        id="billing-fix-card"
                        v-model="form.beneficiary_card_number"
                        type="text"
                        maxlength="64"
                        autocomplete="off"
                        class="form-control"
                        :class="{ 'is-invalid': errors.beneficiary_card_number }"
                        :aria-invalid="errors.beneficiary_card_number ? 'true' : undefined"
                        :aria-describedby="describedBy('beneficiary_card_number', 'billing-fix-card-hint')"
                        data-test="fix-card"
                    >
                    <div v-if="errors.beneficiary_card_number" id="billing-fix-beneficiary_card_number-error" class="invalid-feedback">{{ errors.beneficiary_card_number }}</div>
                    <small id="billing-fix-card-hint" class="form-text d-block">{{ t.fix_card_number_hint }}</small>
                </div>
                <div class="col-12 col-sm-6">
                    <label for="billing-fix-auth" class="form-label">{{ t.fix_authorization }}</label>
                    <input
                        id="billing-fix-auth"
                        v-model="form.authorization_number"
                        type="text"
                        maxlength="64"
                        autocomplete="off"
                        class="form-control"
                        :class="{ 'is-invalid': errors.authorization_number }"
                        :aria-invalid="errors.authorization_number ? 'true' : undefined"
                        :aria-describedby="describedBy('authorization_number', 'billing-fix-auth-hint')"
                        data-test="fix-auth"
                    >
                    <div v-if="errors.authorization_number" id="billing-fix-authorization_number-error" class="invalid-feedback">{{ errors.authorization_number }}</div>
                    <small id="billing-fix-auth-hint" class="form-text d-block">{{ t.fix_authorization_hint }}</small>
                </div>
            </div>

            <p class="small text-muted mt-3 mb-0">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.fix_beneficiary_hint }}
            </p>

            <!-- Resultado ao vivo (leitor de tela anuncia; o foco vai para cá ao salvar). -->
            <div role="status" aria-live="polite" class="mt-3">
                <section
                    v-if="result"
                    ref="resultRef"
                    tabindex="-1"
                    class="border-top pt-3"
                    aria-labelledby="billing-fix-result-title"
                    data-test="fix-result"
                >
                    <h3 id="billing-fix-result-title" class="h6 fw-semibold">{{ t.fix_result_title }}</h3>
                    <div :class="['alert small py-2', result.attached ? 'alert-success' : (result.validation?.errors?.length ? 'alert-warning' : 'alert-info')]" data-test="fix-message">
                        {{ result.message }}
                    </div>
                    <PreValidationResult :result="result.validation" :t="t" />
                </section>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="processing" data-test="fix-close" @click="requestClose">{{ t.btn_close }}</button>
            <button type="button" class="btn btn-primary" :disabled="processing || !fixData" data-test="fix-save" @click="submit">
                <span v-if="processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ processing ? t.processing : t.btn_fix_save }}
            </button>
        </template>
    </CenteredModal>
</template>
