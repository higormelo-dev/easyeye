<script setup>
import { computed, useId } from 'vue';
import { clinicalTexts, tc } from '@/Support/clinicalSafety.js';

/**
 * Alergias no CADASTRO RÁPIDO de paciente (lista de espera e pronto
 * atendimento — Fase 2 / G2): substâncias em texto curto (vírgula ou ponto e
 * vírgula) OU "nega alergias". Nenhum dos dois = "sem registro de alergias".
 * O servidor grava como "informada pelo paciente" e recusa os dois juntos.
 */
const props = defineProps({
    allergies: { type: String, default: '' },
    denied: { type: Boolean, default: false },
    // Erros do servidor: { allergies: [..], allergies_denied: [..] }
    errors: { type: Object, default: () => ({}) },
    t: { type: Object, default: null },
});

const emit = defineEmits(['update:allergies', 'update:denied']);

const texts = clinicalTexts(props.t);
const tt = (path, fallback) => tc(texts, path, fallback);
const uid = useId();

const hasText = computed(() => props.allergies.trim() !== '');
const textError = computed(() => props.errors?.allergies?.[0] ?? '');
const deniedError = computed(() => props.errors?.allergies_denied?.[0] ?? '');
</script>

<template>
    <div class="cs-quick" role="group" :aria-labelledby="`${uid}-label`" data-testid="quick-allergies">
        <label :id="`${uid}-label`" class="cs-quick__label" :for="`${uid}-text`">
            <i class="ti ti-alert-triangle text-danger me-1" aria-hidden="true"></i
            >{{ tt('allergies.quick_label', 'Alergias (opcional)') }}
        </label>
        <input
            :id="`${uid}-text`"
            :value="allergies"
            type="text"
            maxlength="500"
            autocomplete="off"
            class="form-control form-control-sm"
            :class="{ 'is-invalid': textError }"
            :disabled="denied"
            :placeholder="tt('allergies.quick_ph', 'Ex.: dipirona, penicilina, látex')"
            :aria-describedby="`${uid}-hint`"
            :aria-invalid="textError ? 'true' : 'false'"
            data-testid="quick-allergies-text"
            @input="emit('update:allergies', $event.target.value)"
        />
        <div v-if="textError" class="invalid-feedback d-block" role="alert">{{ textError }}</div>
        <div class="form-check mt-1 mb-0">
            <input
                :id="`${uid}-deny`"
                class="form-check-input"
                type="checkbox"
                :checked="denied"
                :disabled="hasText"
                :title="
                    hasText
                        ? tt('allergies.quick_deny_locked', 'Apague as alergias digitadas para marcar nega alergias.')
                        : ''
                "
                data-testid="quick-allergies-deny"
                @change="emit('update:denied', $event.target.checked)"
            />
            <label class="form-check-label small" :for="`${uid}-deny`">{{
                tt('allergies.quick_deny', 'Paciente nega alergias')
            }}</label>
        </div>
        <div v-if="deniedError" class="invalid-feedback d-block" role="alert">{{ deniedError }}</div>
        <small :id="`${uid}-hint`" class="form-text d-block cs-quick__hint">{{
            tt(
                'allergies.quick_hint',
                'Separe por vírgula ou ponto e vírgula. Entram como informadas pelo paciente; o médico confirma no atendimento. Em branco = sem registro de alergias.',
            )
        }}</small>
    </div>
</template>

<style scoped>
.cs-quick {
    min-width: 0;
}
.cs-quick__label {
    display: block;
    margin-bottom: 0.25rem;
    font-size: 0.8125rem;
    font-weight: 600;
    line-height: 1.5;
    color: var(--bs-body-color);
}
.cs-quick__hint {
    color: var(--bs-secondary-color);
}
[data-bs-theme='dark'] .cs-quick__label {
    color: var(--bs-emphasis-color);
}
</style>
