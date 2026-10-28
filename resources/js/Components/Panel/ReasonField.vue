<script setup>
import { computed, useId } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Justificativa de ação do manager (auditoria LGPD/CFM) dentro de um
 * formulário — mesmo contador e mesmas regras do ConfirmationWithReasonModal
 * (mínimo de 20 caracteres), para formulários que têm outros campos.
 *
 * v-model: texto. `valid` (exposto) diz se pode enviar.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: '' },
    hint: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    required: { type: Boolean, default: true },
    minLength: { type: Number, default: 20 },
    maxLength: { type: Number, default: 1000 },
    error: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const page = usePage();
const t = computed(() => page.props.t_hardening ?? {});

const uid = useId();
const ids = { field: `reason-field-${uid}`, hint: `reason-hint-${uid}`, counter: `reason-counter-${uid}` };

const length = computed(() => (props.modelValue ?? '').trim().length);
const tooShort = computed(() => (props.required || length.value > 0) && length.value < props.minLength);
const tooLong = computed(() => (props.modelValue ?? '').length > props.maxLength);
const valid = computed(() => !tooShort.value && !tooLong.value);

const counterText = computed(() =>
    (t.value.modal_counter ?? ':current / :min mínimo')
        .replace(':current', String(length.value))
        .replace(':min', String(props.minLength)),
);

const counterClass = computed(() => {
    if (tooLong.value) return 'text-danger';
    if (length.value >= props.minLength) return 'text-success';

    return 'text-muted';
});

defineExpose({ valid });
</script>

<template>
    <div>
        <label :for="ids.field" class="form-label fw-medium">
            {{ label || t.modal_reason_label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
        </label>
        <textarea
            :id="ids.field"
            class="form-control"
            :class="{ 'is-invalid': !!error || tooLong }"
            rows="3"
            :maxlength="maxLength + 50"
            :value="modelValue"
            :placeholder="placeholder || t.modal_reason_placeholder"
            :disabled="disabled"
            :aria-required="required ? 'true' : 'false'"
            :aria-invalid="error || tooLong ? 'true' : 'false'"
            :aria-describedby="`${ids.hint} ${ids.counter}`"
            @input="$emit('update:modelValue', $event.target.value)"
        ></textarea>
        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
        <div class="d-flex justify-content-between gap-2 mt-1">
            <small :id="ids.hint" class="text-muted">{{ hint || t.modal_reason_hint }}</small>
            <small :id="ids.counter" class="text-nowrap" :class="counterClass" aria-live="polite">{{
                counterText
            }}</small>
        </div>
    </div>
</template>
