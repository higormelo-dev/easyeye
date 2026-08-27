<script setup>
import { ref, computed, watch, nextTick } from 'vue';
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
    open:      { type: Boolean, required: true },
    title:     { type: String,  default: '' },
    message:   { type: String,  default: '' },
    confirmLabel: { type: String, default: '' },
    confirmVariant: { type: String, default: 'danger' },  // danger | warning | primary
    saving:    { type: Boolean, default: false },
    minLength: { type: Number,  default: 20 },
    maxLength: { type: Number,  default: 1000 },
});

const emit = defineEmits(['close', 'confirm']);

const page = usePage();
const t = computed(() => page.props.t_hardening ?? {});

const reason   = ref('');
const textarea = ref(null);

const length      = computed(() => reason.value.trim().length);
const isValid     = computed(() => length.value >= props.minLength);
const isTooLong   = computed(() => reason.value.length > props.maxLength);
const canSubmit   = computed(() => isValid.value && !isTooLong.value && !props.saving);

const counterClass = computed(() => {
    if (isTooLong.value) return 'text-danger';
    if (isValid.value)   return 'text-success';
    return 'text-muted';
});

const counterText = computed(() => {
    const tpl = t.value.modal_counter ?? ':current / :min mínimo';
    return tpl.replace(':current', length.value).replace(':min', props.minLength);
});

// Reset on open and focus textarea (UX: usuário sempre começa do zero)
watch(() => props.open, async (val) => {
    if (val) {
        reason.value = '';
        await nextTick();
        textarea.value?.focus();
    }
});

function close() {
    if (props.saving) return;
    emit('close');
}

function submit() {
    if (!canSubmit.value) return;
    emit('confirm', reason.value.trim());
}

const btnClass = computed(() => `btn btn-${props.confirmVariant} btn-sm`);
</script>

<template>
    <div
        v-if="open"
        class="modal d-block"
        tabindex="-1"
        style="background: rgba(0,0,0,.55);"
        @click.self="close"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="ti ti-shield-lock me-1 text-warning"></i>
                        {{ title || t.modal_title || 'Confirmar ação' }}
                    </h5>
                    <button type="button" class="btn-close" :disabled="saving" @click="close"></button>
                </div>

                <div class="modal-body">
                    <!-- Aviso LGPD/CFM -->
                    <div class="alert alert-warning small d-flex align-items-start mb-3">
                        <i class="ti ti-info-circle me-2 fs-5 mt-1"></i>
                        <div>
                            <strong v-if="message">{{ message }}</strong>
                            <p class="mb-0 mt-1">{{ t.modal_warning ?? 'Esta ação é registrada no log de auditoria e não pode ser desfeita silenciosamente.' }}</p>
                        </div>
                    </div>

                    <!-- Reason textarea -->
                    <label class="form-label fw-medium">
                        {{ t.modal_reason_label ?? 'Justificativa' }}
                        <span class="text-danger">*</span>
                    </label>
                    <textarea
                        ref="textarea"
                        v-model="reason"
                        class="form-control"
                        :class="{ 'is-invalid': isTooLong }"
                        rows="4"
                        :maxlength="maxLength + 50"
                        :placeholder="t.modal_reason_placeholder ?? 'Descreva o motivo...'"
                        :disabled="saving"
                    ></textarea>

                    <div class="d-flex justify-content-between mt-1">
                        <small class="text-muted">{{ t.modal_reason_hint }}</small>
                        <small :class="counterClass">{{ counterText }}</small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="saving"
                        @click="close"
                    >
                        {{ t.modal_cancel ?? 'Cancelar' }}
                    </button>
                    <button
                        type="button"
                        :class="btnClass"
                        :disabled="!canSubmit"
                        @click="submit"
                    >
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-check me-1"></i>
                        {{ confirmLabel || t.modal_confirm || 'Confirmar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
