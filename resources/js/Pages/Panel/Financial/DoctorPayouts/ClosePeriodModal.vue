<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Confirmação do fechamento de repasse do período: resumo da prévia (itens,
 * valor cobrado, repasse), alertas para conferir e observações. O POST leva a
 * prévia conferida (`expected_count` / `expected_charged_cents` / `expected_payout_cents`): o servidor
 * recalcula sob lock e recusa se a apuração mudou. Sucesso = redirect do
 * servidor para o demonstrativo; erros aparecem aqui dentro.
 */
const props = defineProps({
    open:     { type: Boolean, default: false },
    preview:  { type: Object,  required: true },   // close_preview
    doctorId: { type: String,  default: '' },
    doctor:   { type: Object,  default: null },    // selected_doctor
    action:   { type: String,  required: true },   // routes.close
    t:        { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close']);

const {
    money, number, periodText, doctorLabel, warningLabel, warningHint, warningIcon, warningTone,
} = useDoctorPayoutFormat(() => props.t);

const uid = useId();
const ids = {
    title: `dp-close-title-${uid}`,
    notes: `dp-close-notes-${uid}`,
    error: `dp-close-error-${uid}`,
};

const form = useForm({
    doctor_id:             '',
    period_start:          '',
    period_end:            '',
    expected_count:         0,
    expected_charged_cents: 0,
    expected_payout_cents:  0,
    notes:                  '',
});

const cancelButton = ref(null);

const warnings = computed(() => Object.entries(props.preview?.warnings ?? {})
    .filter(([, count]) => Number(count) > 0)
    .map(([code, count]) => ({ code, count: Number(count) })));

/** Erros que não são do campo de observações (período, médico, prévia mudou…). */
const generalErrors = computed(() => {
    const messages = Object.entries(form.errors ?? {})
        .filter(([field]) => field !== 'notes')
        .map(([, message]) => (Array.isArray(message) ? message[0] : message))
        .filter(Boolean);

    return [...new Set(messages)];
});

function fillFromPreview() {
    form.doctor_id             = props.doctorId;
    form.period_start          = props.preview?.period_start ?? '';
    form.period_end            = props.preview?.period_end ?? '';
    form.expected_count         = Number(props.preview?.count ?? 0);
    form.expected_charged_cents = Number(props.preview?.charged_cents ?? 0);
    form.expected_payout_cents  = Number(props.preview?.payout_cents ?? 0);
}

function close() {
    if (form.processing) return;

    emit('close');
}

function submit() {
    if (form.processing) return;

    form.post(props.action, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}

// ── Teclado: Esc fecha; foco inicial em "Cancelar" (ação de alto impacto) ───
function onKeydown(event) {
    if (event.key === 'Escape') close();
}

watch(() => props.open, async (isOpen) => {
    if (!isOpen) {
        document.removeEventListener('keydown', onKeydown);

        return;
    }

    form.clearErrors();
    fillFromPreview();
    document.addEventListener('keydown', onKeydown);

    await nextTick();
    cancelButton.value?.focus();
});

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <CenteredModal :open="open" size="md" @close="close">
        <template #header>
            <h5 :id="ids.title" class="modal-title mb-0">
                <i class="ti ti-lock me-1 text-primary" aria-hidden="true"></i>{{ t.close_title }}
            </h5>
        </template>

        <div data-test="close-summary">
            <p class="small mb-3">{{ t.close_intro }}</p>

            <dl class="row small mb-0">
                <dt class="col-5 fw-medium">{{ t.filter_doctor }}</dt>
                <dd class="col-7 mb-1 text-break" data-test="close-doctor">{{ doctorLabel(doctor) || t.none }}</dd>
                <dt class="col-5 fw-medium">{{ t.filter_period }}</dt>
                <dd class="col-7 mb-1" data-test="close-period">{{ periodText(preview.period_start, preview.period_end) }}</dd>
                <dt class="col-5 fw-medium">{{ t.close_items }}</dt>
                <dd class="col-7 mb-1" data-test="close-count">{{ number(preview.count ?? 0) }}</dd>
                <dt class="col-5 fw-medium">{{ t.close_charged }}</dt>
                <dd class="col-7 mb-1" data-test="close-charged">{{ money(Number(preview.charged_cents ?? 0) / 100) }}</dd>
                <dt class="col-5 fw-medium">{{ t.close_payout }}</dt>
                <dd class="col-7 mb-1 fw-bold" data-test="close-payout">{{ money(Number(preview.payout_cents ?? 0) / 100) }}</dd>
            </dl>

            <div v-if="warnings.length" class="alert alert-warning small mt-3 mb-0" role="status" data-test="close-warnings">
                <p class="fw-semibold mb-2">
                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.close_warnings }}
                </p>
                <ul class="list-unstyled mb-0 d-grid gap-2">
                    <li v-for="warning in warnings" :key="warning.code" :data-warning="warning.code">
                        <span class="d-flex align-items-center gap-2">
                            <i :class="[warningIcon(warning.code), `text-${warningTone(warning.code)}`]" aria-hidden="true"></i>
                            <span class="fw-medium">{{ warningLabel(warning.code) }}</span>
                            <span class="badge badge-soft-secondary border rounded ms-auto">{{ number(warning.count) }}</span>
                        </span>
                        <span class="d-block text-body-secondary mt-1">{{ warningHint(warning.code) }}</span>
                    </li>
                </ul>
            </div>

            <div class="mt-3">
                <label :for="ids.notes" class="form-label small fw-medium">{{ t.close_notes }}</label>
                <textarea
                    :id="ids.notes"
                    v-model="form.notes"
                    rows="2"
                    maxlength="2000"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.notes }"
                    :aria-invalid="form.errors.notes ? 'true' : undefined"
                    :aria-describedby="form.errors.notes ? `${ids.notes}-hint ${ids.notes}-error` : `${ids.notes}-hint`"
                    :disabled="form.processing"
                    data-test="close-notes"
                ></textarea>
                <div v-if="form.errors.notes" :id="`${ids.notes}-error`" class="invalid-feedback d-block">{{ form.errors.notes }}</div>
                <div :id="`${ids.notes}-hint`" class="form-text small" data-test="close-notes-hint">{{ t.close_notes_hint }}</div>
            </div>

            <div
                v-if="generalErrors.length"
                :id="ids.error"
                class="alert alert-danger small d-flex gap-2 mt-3 mb-0"
                role="alert"
                data-test="close-error"
            >
                <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i>
                <div>
                    <p v-for="message in generalErrors" :key="message" class="mb-0">{{ message }}</p>
                </div>
            </div>
        </div>

        <template #footer>
            <button ref="cancelButton" type="button" class="btn btn-outline-secondary btn-sm" :disabled="form.processing" @click="close">
                {{ t.close_cancel }}
            </button>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="form.processing"
                :aria-busy="form.processing ? 'true' : 'false'"
                :aria-describedby="generalErrors.length ? ids.error : undefined"
                data-test="close-confirm"
                @click="submit"
            >
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-lock me-1" aria-hidden="true"></i>
                {{ t.close_confirm }}
            </button>
        </template>
    </CenteredModal>
</template>
