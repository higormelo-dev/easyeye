<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { clinicalTexts, tc, matchLabel } from '@/Support/clinicalSafety.js';

/**
 * Receita que bate com alergia ativa (Fase 2 / A4): mostra a alergia, a
 * reação e o que bateu na receita; seguir EXIGE motivo (mín. 5 caracteres —
 * mesma regra do servidor, que grava o motivo no documento e na auditoria).
 * Aviso, não bloqueio. Fica por cima do modal do receituário.
 */
const MIN_REASON = 5;

const props = defineProps({
    open: { type: Boolean, default: false },
    conflicts: { type: Array, default: () => [] },
    t: { type: Object, default: null },
});

const emit = defineEmits(['confirm', 'cancel']);

const texts = clinicalTexts(props.t);
const tt = (path, fallback) => tc(texts, path, fallback);
const uid = useId();

const reason = ref('');
const textarea = ref(null);
const valid = computed(() => reason.value.trim().length >= MIN_REASON);

// Uma linha por alergia (o mesmo item pode bater por mais de um motivo).
const grouped = computed(() => {
    const map = new Map();
    for (const c of props.conflicts) {
        const key = c.allergy_id ?? c.substance;
        if (!map.has(key)) map.set(key, { ...c, hits: [] });
        map.get(key).hits.push(c);
    }
    return [...map.values()];
});

watch(
    () => props.open,
    (open) => {
        if (open) {
            reason.value = '';
            nextTick(() => textarea.value?.focus());
        }
    },
);

function confirm() {
    if (!valid.value) return;
    emit('confirm', reason.value.trim());
}

function hitText(hit) {
    const why = matchLabel(hit.match, texts);
    return hit.class ? `${hit.matched} (${why}: ${hit.class})` : `${hit.matched} (${why})`;
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="modal fade show d-block cs-override"
            tabindex="-1"
            role="alertdialog"
            aria-modal="true"
            :aria-labelledby="`${uid}-title`"
            :aria-describedby="`${uid}-intro`"
            data-testid="allergy-override-modal"
            @keydown.esc="emit('cancel')"
        >
            <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header py-2 cs-override__header">
                        <h6 :id="`${uid}-title`" class="modal-title">
                            <i class="ti ti-alert-triangle me-2" aria-hidden="true"></i
                            >{{ tt('prescription.modal_title', 'Alergia do paciente na receita') }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close"
                            :aria-label="tt('prescription.choose_other', 'Voltar')"
                            @click="emit('cancel')"
                        ></button>
                    </div>
                    <div class="modal-body">
                        <p :id="`${uid}-intro`" class="mb-2">
                            {{
                                tt(
                                    'prescription.modal_intro',
                                    'O que você está prescrevendo bate com alergia registrada:',
                                )
                            }}
                        </p>
                        <ul class="list-unstyled mb-3" data-testid="allergy-override-conflicts">
                            <li v-for="c in grouped" :key="c.allergy_id ?? c.substance" class="cs-override__item">
                                <div>
                                    <strong>{{ c.substance }}</strong>
                                    <span v-if="c.severity_label" class="badge text-bg-danger ms-1">{{
                                        c.severity_label
                                    }}</span>
                                    <span v-if="c.status_label" class="small text-body-secondary ms-1"
                                        >({{ c.status_label }})</span
                                    >
                                </div>
                                <div v-if="c.reaction" class="small">
                                    {{ tt('prescription.reaction', 'Reação') }}: {{ c.reaction }}
                                </div>
                                <div class="small">
                                    {{ tt('prescription.matched', 'Na receita') }}:
                                    {{ c.hits.map(hitText).join('; ') }}
                                </div>
                            </li>
                        </ul>
                        <label class="form-label small fw-semibold" :for="`${uid}-reason`"
                            >{{ tt('prescription.reason', 'Motivo para prescrever mesmo assim') }}
                            <span class="text-danger" aria-hidden="true">*</span></label
                        >
                        <textarea
                            :id="`${uid}-reason`"
                            ref="textarea"
                            v-model="reason"
                            class="form-control form-control-sm"
                            rows="2"
                            maxlength="1000"
                            required
                            :aria-invalid="reason.length > 0 && !valid ? 'true' : 'false'"
                            :placeholder="tt('prescription.reason_ph', 'Ex.: alergia descartada em teste')"
                            data-testid="allergy-override-reason"
                        ></textarea>
                        <small class="d-block mt-1 text-body-secondary">
                            <i class="ti ti-shield-check me-1" aria-hidden="true"></i
                            >{{
                                tt('prescription.audit_note', 'O motivo fica registrado no documento e na auditoria.')
                            }}
                        </small>
                    </div>
                    <div class="modal-footer py-2 d-flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn btn-sm btn-primary"
                            data-testid="allergy-override-cancel"
                            @click="emit('cancel')"
                        >
                            {{ tt('prescription.choose_other', 'Voltar e escolher outro') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-danger"
                            :disabled="!valid"
                            data-testid="allergy-override-confirm"
                            @click="confirm"
                        >
                            {{ tt('prescription.proceed', 'Prescrever mesmo assim') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="open" class="modal-backdrop fade show cs-override__backdrop"></div>
    </Teleport>
</template>

<style scoped>
.cs-override {
    z-index: 1096;
}
.cs-override__backdrop {
    z-index: 1095;
}
.cs-override__header {
    background: var(--bs-danger-bg-subtle, #f8d7da);
    color: var(--bs-danger-text-emphasis, #58151c);
}
.cs-override__item {
    padding: 0.5rem 0.65rem;
    border: 2px solid var(--bs-danger, #dc3545);
    border-left-width: 6px;
    border-radius: 0.4rem;
    margin-bottom: 0.4rem;
    background: var(--bs-danger-bg-subtle, #f8d7da);
    color: var(--bs-danger-text-emphasis, #58151c);
}
</style>
