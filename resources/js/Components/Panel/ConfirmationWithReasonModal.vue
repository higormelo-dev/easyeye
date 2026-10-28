<script setup>
import { ref, computed, watch, nextTick, useId } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Modal genérico de confirmação para ações destrutivas / de alto impacto.
 *
 * Hardening LGPD/CFM:
 *  - Exige justificativa textual mínima de 20 caracteres.
 *  - Contador em tempo real (verde quando atinge o mínimo).
 *  - Botão de confirmar permanece desabilitado até o mínimo + ausência de saving.
 *  - Banner de aviso explicando que a ação vai para o audit trail.
 *
 * Uso: emite 'confirm' com a reason quando submit. O chamador é responsável
 * por executar a ação (fetch) e fechar via prop `open=false`.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, default: '' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: '' },
    confirmVariant: { type: String, default: 'danger' }, // danger | warning | primary
    saving: { type: Boolean, default: false },
    minLength: { type: Number, default: 20 },
    maxLength: { type: Number, default: 1000 },
    /** Erro do servidor para mostrar dentro do modal (role=alert), opcional. */
    error: { type: String, default: '' },
});

const emit = defineEmits(['close', 'confirm']);

const page = usePage();
const t = computed(() => page.props.t_hardening ?? {});

const reason = ref('');
const textarea = ref(null);

const length = computed(() => reason.value.trim().length);
const isValid = computed(() => length.value >= props.minLength);
const isTooLong = computed(() => reason.value.length > props.maxLength);
const canSubmit = computed(() => isValid.value && !isTooLong.value && !props.saving);

const counterClass = computed(() => {
    if (isTooLong.value) return 'text-danger';
    if (isValid.value) return 'text-success';
    return 'text-muted';
});

const counterText = computed(() => {
    const tpl = t.value.modal_counter ?? ':current / :min mínimo';
    return tpl.replace(':current', length.value).replace(':min', props.minLength);
});

// Reset on open and focus textarea (UX: usuário sempre começa do zero).
// immediate: modal que já nasce aberto também começa limpo e focado.
// Ao fechar, o foco volta para quem abriu (se ficou perdido no <body>).
let returnFocusTo = null;

watch(
    () => props.open,
    async (val, wasOpen) => {
        if (val) {
            if (!wasOpen && typeof document !== 'undefined') {
                returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            }
            reason.value = '';
            await nextTick();
            textarea.value?.focus();

            return;
        }

        if (wasOpen) {
            const target = returnFocusTo;
            returnFocusTo = null;
            await nextTick();

            const active = document.activeElement;
            if (target?.isConnected && (!active || active === document.body)) target.focus({ preventScroll: true });
        }
    },
    { immediate: true },
);

function close() {
    if (props.saving) return;
    emit('close');
}

function submit() {
    if (!canSubmit.value) return;
    emit('confirm', reason.value.trim());
}

const btnClass = computed(() => `btn btn-${props.confirmVariant} btn-sm`);

// Acessibilidade: diálogo nomeado pelo título, campo ligado ao rótulo, dica e contador.
const uid = useId();
const ids = {
    title: `reason-modal-title-${uid}`,
    message: `reason-modal-message-${uid}`,
    reason: `reason-modal-reason-${uid}`,
    hint: `reason-modal-hint-${uid}`,
    counter: `reason-modal-counter-${uid}`,
    error: `reason-modal-error-${uid}`,
};

const describedBy = computed(() => [ids.hint, ids.counter, props.error ? ids.error : null].filter(Boolean).join(' '));
</script>

<template>
    <div
        v-if="open"
        class="modal d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="ids.title"
        :aria-describedby="ids.message"
        style="background: rgba(0, 0, 0, 0.55)"
        @click.self="close"
        @keydown.esc.stop="close"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 :id="ids.title" class="modal-title">
                        <i class="ti ti-shield-lock me-1 text-warning" aria-hidden="true"></i>
                        {{ title || t.modal_title || 'Confirmar ação' }}
                    </h5>
                    <button
                        type="button"
                        class="btn-close"
                        :disabled="saving"
                        :aria-label="t.modal_close ?? t.modal_cancel ?? 'Fechar'"
                        @click="close"
                    ></button>
                </div>

                <div class="modal-body">
                    <!-- Aviso LGPD/CFM -->
                    <div :id="ids.message" class="alert alert-warning small d-flex align-items-start mb-3">
                        <i class="ti ti-info-circle me-2 fs-5 mt-1" aria-hidden="true"></i>
                        <div>
                            <strong v-if="message">{{ message }}</strong>
                            <p class="mb-0 mt-1">
                                {{
                                    t.modal_warning ??
                                    'Esta ação é registrada no log de auditoria e não pode ser desfeita silenciosamente.'
                                }}
                            </p>
                        </div>
                    </div>

                    <!-- Reason textarea -->
                    <label :for="ids.reason" class="form-label fw-medium">
                        {{ t.modal_reason_label ?? 'Justificativa' }}
                        <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <textarea
                        :id="ids.reason"
                        ref="textarea"
                        v-model="reason"
                        class="form-control"
                        :class="{ 'is-invalid': isTooLong }"
                        rows="4"
                        :maxlength="maxLength + 50"
                        :placeholder="t.modal_reason_placeholder ?? 'Descreva o motivo...'"
                        :disabled="saving"
                        aria-required="true"
                        :aria-invalid="isTooLong ? 'true' : 'false'"
                        :aria-describedby="describedBy"
                    ></textarea>

                    <div class="d-flex justify-content-between mt-1">
                        <small :id="ids.hint" class="text-muted">{{ t.modal_reason_hint }}</small>
                        <small :id="ids.counter" :class="counterClass" aria-live="polite">{{ counterText }}</small>
                    </div>

                    <div
                        v-if="error"
                        :id="ids.error"
                        class="alert alert-danger small d-flex gap-2 mt-3 mb-0"
                        role="alert"
                    >
                        <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i>
                        <span>{{ error }}</span>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="close">
                        {{ t.modal_cancel ?? 'Cancelar' }}
                    </button>
                    <button type="button" :class="btnClass" :disabled="!canSubmit" @click="submit">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-check me-1" aria-hidden="true"></i>
                        {{ confirmLabel || t.modal_confirm || 'Confirmar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
