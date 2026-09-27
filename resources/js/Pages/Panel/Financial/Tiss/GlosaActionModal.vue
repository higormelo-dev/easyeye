<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Ações da glosa num modal com o resumo da glosa:
 *  - appeal:  abrir recurso (justificativa ≥ 10 caracteres; nº REC gerado no servidor);
 *  - submit:  "Marcar como enviado" — confirmação explicando que só REGISTRA o
 *             envio (não é envio eletrônico) e inicia o prazo de resposta;
 *  - resolve: decisão da operadora (Aceito exige valor > 0, com centavos, até
 *             o valor glosado; Rejeitado descarta o valor).
 * Regras de transição/409 continuam no servidor. Esc: a página chama
 * requestClose() (não fecha durante o envio).
 */
const props = defineProps({
    /** 'appeal' | 'submit' | 'resolve' | null (fechado) */
    kind:               { type: String, default: null },
    glosa:              { type: Object, default: null },
    appeal:             { type: Object, default: null },
    appealResponseDays: { type: Number, default: 60 },
    t:                  { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'done']);

const REASON_MIN = 10;
const REASON_MAX = 1000;

const { tx } = useTrans(() => props.t);
const { money, date } = useLocaleFormat();

function glosaStatusLabel(status) {
    return props.t?.glosa_status?.[status] ?? status;
}

/** Primeira mensagem de um bag de erros do Inertia (ou texto genérico). */
function firstError(errors) {
    const messages = Object.values(errors ?? {}).flat().filter(Boolean);

    return messages.length ? String(messages[0]) : props.t.action_error;
}

const modalError = ref(null);
const submitting = ref(false);

const reasonInput   = ref(null);
const submitConfirm = ref(null);
const decisionFirst = ref(null);
let returnFocusTo   = null;

const appealForm  = useForm({ reason: '' });
const resolveForm = useForm({ decision: '', accepted_amount: null, result_notes: '' });

const busy = computed(() => submitting.value || appealForm.processing || resolveForm.processing);

watch(() => props.kind, (kind, previous) => {
    if (!kind) {
        nextTick(() => returnFocusTo?.focus?.());

        return;
    }

    if (!previous && typeof document !== 'undefined') returnFocusTo = document.activeElement;

    modalError.value = null;

    if (kind === 'appeal') {
        appealForm.reset();
        appealForm.clearErrors();
    }
    if (kind === 'resolve') {
        resolveForm.reset();
        resolveForm.clearErrors();
    }

    nextTick(() => {
        const target = { appeal: reasonInput, submit: submitConfirm, resolve: decisionFirst }[kind];
        target?.value?.focus?.();
    });
});

/** Fecha; `force` ignora o bloqueio de "processando" (usado no sucesso). */
function requestClose(force = false) {
    if (busy.value && force !== true) return;

    emit('close');
}

function succeed() {
    emit('done', props.kind);
    requestClose(true);
}

defineExpose({ requestClose });

/* Recorrer */
const reasonLength = computed(() => appealForm.reason.trim().length);

function submitAppeal() {
    appealForm.clearErrors();
    modalError.value = null;

    if (reasonLength.value === 0) {
        appealForm.setError('reason', props.t.reason_required);
        return;
    }
    if (reasonLength.value < REASON_MIN) {
        appealForm.setError('reason', tx('reason_min', { min: REASON_MIN }));
        return;
    }

    appealForm.post(props.glosa.appeal_url, {
        preserveScroll: true,
        onSuccess: succeed,
        onError:   (errors) => { if (!errors.reason) modalError.value = firstError(errors); },
    });
}

/* Marcar como enviado (Aberto → Enviado): só registra o envio feito fora do sistema. */
function confirmSubmit() {
    modalError.value = null;

    router.post(props.appeal.submit_url, {}, {
        preserveScroll: true,
        onStart:   () => { submitting.value = true; },
        onFinish:  () => { submitting.value = false; },
        onSuccess: succeed,
        onError:   (errors) => { modalError.value = firstError(errors); },
    });
}

/* Decisão (Enviado → Aceito/Rejeitado) */
const resolveCeiling  = computed(() => Number(props.glosa?.amount ?? 0));
const requestedAmount = computed(() => Number(props.appeal?.requested_amount ?? 0) || resolveCeiling.value);

// Ao escolher "Aceito", sugere o valor solicitado; em "Rejeitado" o valor é descartado.
watch(() => resolveForm.decision, (decision) => {
    if (decision === 'accepted' && (resolveForm.accepted_amount === '' || resolveForm.accepted_amount === null)) {
        resolveForm.accepted_amount = Math.min(requestedAmount.value, resolveCeiling.value);
    }
    if (decision === 'rejected') {
        resolveForm.accepted_amount = null;
    }
});

/** Valor em centavos exatos (a coluna é numeric(14,2); 199.995 viraria 200,00 no banco). */
const hasAtMostCents = (value) => Math.round(value * 100) / 100 === value;

const resolvePreview = computed(() => {
    if (resolveForm.decision === 'rejected') return glosaStatusLabel('maintained');
    if (resolveForm.decision !== 'accepted') return null;

    const accepted = Number(resolveForm.accepted_amount);
    if (!(accepted > 0) || accepted > resolveCeiling.value || !hasAtMostCents(accepted)) return null;

    return glosaStatusLabel(accepted >= requestedAmount.value ? 'reversed' : 'partial_reversed');
});

function validateResolve() {
    if (!resolveForm.decision) {
        resolveForm.setError('decision', props.t.decision_required);
        return false;
    }
    if (resolveForm.decision !== 'accepted') return true;

    const raw      = resolveForm.accepted_amount;
    const accepted = Number(raw);
    if (raw === '' || raw === null || raw === undefined || Number.isNaN(accepted)) {
        resolveForm.setError('accepted_amount', props.t.accepted_amount_required);
        return false;
    }
    if (accepted <= 0) {
        resolveForm.setError('accepted_amount', props.t.accepted_amount_min);
        return false;
    }
    // Centavos: valor vindo de fora do campo (o MoneyInput já arredonda o digitado).
    if (!hasAtMostCents(accepted)) {
        resolveForm.setError('accepted_amount', props.t.accepted_amount_decimals);
        return false;
    }
    if (accepted > resolveCeiling.value) {
        resolveForm.setError('accepted_amount', tx('accepted_amount_max', { max: money(resolveCeiling.value) }));
        return false;
    }
    return true;
}

function submitResolve() {
    resolveForm.clearErrors();
    modalError.value = null;
    if (!validateResolve()) return;

    resolveForm
        .transform((data) => (data.decision === 'rejected'
            ? { decision: data.decision, result_notes: data.result_notes }
            : data))
        .post(props.appeal.resolve_url, {
            preserveScroll: true,
            onSuccess: succeed,
            onError:   (errors) => {
                const known = ['decision', 'accepted_amount', 'result_notes'];
                if (!known.some((key) => errors[key])) modalError.value = firstError(errors);
            },
        });
}
</script>

<template>
    <CenteredModal :open="kind !== null && glosa !== null" size="md" @close="requestClose()">
        <template #header>
            <h2 id="glosa-modal-title" class="h5 mb-0">
                <template v-if="kind === 'appeal'"><i class="ti ti-message-circle-up me-1 text-warning" aria-hidden="true"></i>{{ t.appeal_title }}</template>
                <template v-else-if="kind === 'submit'"><i class="ti ti-send me-1 text-info" aria-hidden="true"></i>{{ t.submit_confirm_title }}</template>
                <template v-else-if="kind === 'resolve'"><i class="ti ti-gavel me-1 text-primary" aria-hidden="true"></i>{{ t.resolve_title }}</template>
            </h2>
        </template>

        <div v-if="glosa">
            <div v-if="modalError" class="alert alert-danger small d-flex align-items-start gap-2" role="alert" data-test="modal-error">
                <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i><span>{{ modalError }}</span>
            </div>

            <dl class="row small mb-3 glosa-summary" data-test="modal-summary">
                <template v-if="appeal">
                    <dt class="col-5 text-muted fw-normal">{{ t.modal_appeal_label }}</dt>
                    <dd class="col-7 mb-1"><code>{{ appeal.appeal_number }}</code></dd>
                </template>
                <dt class="col-5 text-muted fw-normal">{{ t.modal_covenant_label }}</dt>
                <dd class="col-7 mb-1">{{ glosa.operator_name || t.no_covenant }}</dd>
                <dt class="col-5 text-muted fw-normal">{{ t.modal_guide_label }}</dt>
                <dd class="col-7 mb-1"><code>{{ glosa.guide_number || '—' }}</code></dd>
                <dt class="col-5 text-muted fw-normal">{{ t.modal_glosa_label }}</dt>
                <dd class="col-7 mb-1">{{ glosa.reason_code }} — {{ glosa.reason_text || '—' }}</dd>
                <dt class="col-5 text-muted fw-normal">{{ t.modal_value_label }}</dt>
                <dd class="col-7 mb-1 fw-semibold">{{ money(glosa.amount) }}</dd>
                <template v-if="appeal">
                    <dt class="col-5 text-muted fw-normal">{{ t.modal_requested }}</dt>
                    <dd class="col-7 mb-1">{{ money(appeal.requested_amount) }}</dd>
                </template>
                <template v-else-if="glosa.deadline">
                    <dt class="col-5 text-muted fw-normal">{{ t.modal_deadline_label }}</dt>
                    <dd class="col-7 mb-1">{{ date(glosa.deadline) }}</dd>
                </template>
            </dl>

            <!-- Recorrer -->
            <form v-if="kind === 'appeal'" id="glosa-appeal-form" novalidate @submit.prevent="submitAppeal">
                <label for="glosa-appeal-reason" class="form-label">
                    {{ t.justification_label }} <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <textarea
                    id="glosa-appeal-reason"
                    ref="reasonInput"
                    v-model="appealForm.reason"
                    rows="4"
                    :maxlength="REASON_MAX"
                    class="form-control"
                    :class="{ 'is-invalid': appealForm.errors.reason }"
                    :placeholder="t.justification_placeholder"
                    aria-required="true"
                    :aria-invalid="appealForm.errors.reason ? 'true' : 'false'"
                    :aria-describedby="appealForm.errors.reason ? 'glosa-appeal-reason-error glosa-appeal-reason-hint' : 'glosa-appeal-reason-hint'"
                ></textarea>
                <div v-if="appealForm.errors.reason" id="glosa-appeal-reason-error" class="invalid-feedback d-block" role="alert" data-test="reason-error">
                    {{ appealForm.errors.reason }}
                </div>
                <div id="glosa-appeal-reason-hint" class="d-flex justify-content-between mt-1">
                    <small class="text-muted">{{ tx('min_chars_audit_hint', { min: REASON_MIN }) }}</small>
                    <small :class="reasonLength >= REASON_MIN ? 'text-success' : 'text-muted'" data-test="reason-counter">
                        {{ tx('char_counter', { count: appealForm.reason.length, max: REASON_MAX }) }}
                    </small>
                </div>
                <small class="text-muted d-block mt-2">{{ t.appeal_number_hint }}</small>
            </form>

            <!-- Marcar como enviado -->
            <div v-else-if="kind === 'submit'" data-test="submit-confirm">
                <p class="mb-2">{{ t.submit_confirm_intro }}</p>
                <div class="alert alert-warning small d-flex align-items-start gap-2 mb-0">
                    <i class="ti ti-info-circle mt-1" aria-hidden="true"></i>
                    <span>{{ tx('submit_confirm_consequence', { days: appealResponseDays }) }}</span>
                </div>
            </div>

            <!-- Decisão -->
            <form v-else-if="kind === 'resolve'" id="glosa-resolve-form" novalidate @submit.prevent="submitResolve">
                <fieldset class="mb-3">
                    <legend class="form-label fs-6">{{ t.decision_label }} <span class="text-danger" aria-hidden="true">*</span></legend>
                    <div class="form-check">
                        <input
                            id="glosa-decision-accepted"
                            ref="decisionFirst"
                            v-model="resolveForm.decision"
                            class="form-check-input"
                            type="radio"
                            name="glosa-decision"
                            value="accepted"
                        >
                        <label class="form-check-label" for="glosa-decision-accepted">{{ t.decision_accepted }}</label>
                    </div>
                    <div class="form-check">
                        <input
                            id="glosa-decision-rejected"
                            v-model="resolveForm.decision"
                            class="form-check-input"
                            type="radio"
                            name="glosa-decision"
                            value="rejected"
                        >
                        <label class="form-check-label" for="glosa-decision-rejected">{{ t.decision_rejected }}</label>
                    </div>
                    <div v-if="resolveForm.errors.decision" class="invalid-feedback d-block" role="alert">{{ resolveForm.errors.decision }}</div>
                </fieldset>

                <div v-if="resolveForm.decision === 'accepted'" class="mb-3">
                    <label for="glosa-accepted-amount" class="form-label">
                        {{ t.accepted_amount_label }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <MoneyInput
                        id="glosa-accepted-amount"
                        v-model="resolveForm.accepted_amount"
                        size=""
                        required
                        aria-required="true"
                        :invalid="!!resolveForm.errors.accepted_amount"
                        aria-describedby="glosa-accepted-amount-help"
                    />
                    <div v-if="resolveForm.errors.accepted_amount" class="invalid-feedback d-block" role="alert" data-test="accepted-error">
                        {{ resolveForm.errors.accepted_amount }}
                    </div>
                    <small id="glosa-accepted-amount-help" class="text-muted">
                        {{ tx('accepted_amount_help', { max: money(resolveCeiling) }) }}
                    </small>
                </div>

                <div class="mb-3">
                    <label for="glosa-result-notes" class="form-label">{{ t.result_notes_label }}</label>
                    <textarea
                        id="glosa-result-notes"
                        v-model="resolveForm.result_notes"
                        rows="3"
                        :maxlength="REASON_MAX"
                        class="form-control"
                        :class="{ 'is-invalid': resolveForm.errors.result_notes }"
                    ></textarea>
                    <div v-if="resolveForm.errors.result_notes" class="invalid-feedback d-block">{{ resolveForm.errors.result_notes }}</div>
                </div>

                <div v-if="resolvePreview" class="alert alert-info small mb-0 py-2" role="status" data-test="resolve-preview">
                    <i class="ti ti-arrow-right me-1" aria-hidden="true"></i>{{ tx('resolve_preview', { status: resolvePreview }) }}
                </div>
            </form>
        </div>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="busy" @click="requestClose()">
                {{ t.cancel_btn }}
            </button>
            <button
                v-if="kind === 'appeal'"
                type="submit"
                form="glosa-appeal-form"
                class="btn btn-warning btn-sm"
                data-test="confirm-appeal"
                :disabled="appealForm.processing"
            >
                <span v-if="appealForm.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-send me-1" aria-hidden="true"></i>
                {{ appealForm.processing ? t.processing : t.submit_appeal }}
            </button>
            <button
                v-else-if="kind === 'submit'"
                ref="submitConfirm"
                type="button"
                class="btn btn-info btn-sm"
                data-test="confirm-submit"
                :disabled="submitting"
                @click="confirmSubmit"
            >
                <span v-if="submitting" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-check me-1" aria-hidden="true"></i>
                {{ submitting ? t.processing : t.submit_confirm_btn }}
            </button>
            <button
                v-else-if="kind === 'resolve'"
                type="submit"
                form="glosa-resolve-form"
                class="btn btn-primary btn-sm"
                data-test="confirm-resolve"
                :disabled="resolveForm.processing"
            >
                <span v-if="resolveForm.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-check me-1" aria-hidden="true"></i>
                {{ resolveForm.processing ? t.processing : t.resolve_submit_btn }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.glosa-summary dd {
    word-break: break-word;
}
</style>
