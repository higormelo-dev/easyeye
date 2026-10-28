<script setup>
import { computed, ref, watch } from 'vue';
import Cid10Picker from '@/Components/Panel/Cid10Picker.vue';

/**
 * Campo "CID (indicação clínica)" das guias: Cid10Picker (busca CID-10) em
 * modo único, com v-model de STRING (só o código, como o backend grava em
 * clinical_indication). Rótulo, dica e erro vão direto para o input do picker
 * (inputId/ariaLabelledby/ariaDescribedby/invalid); o grupo continua nomeado
 * pelo mesmo rótulo.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    searchUrl: { type: String, required: true },
    id: { type: String, required: true },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const selection = ref([]);

// Valor vindo de fora (reset do formulário): reflete no picker sem perder a
// descrição do item escolhido quando o código é o mesmo.
watch(
    () => props.modelValue,
    (code) => {
        const current = selection.value[0]?.code ?? '';
        if ((code ?? '') === current) return;

        selection.value = code ? [{ code, description: '' }] : [];
    },
    { immediate: true },
);

function onSelect(items) {
    selection.value = Array.isArray(items) ? items.slice(0, 1) : [];
    emit('update:modelValue', selection.value[0]?.code ?? '');
}

const describedBy = computed(
    () =>
        [props.hint ? `${props.id}-hint` : null, props.error ? `${props.id}-error` : null].filter(Boolean).join(' ') ||
        undefined,
);
</script>

<template>
    <div role="group" :aria-labelledby="`${id}-label`" :data-test="`${id}-field`">
        <label :id="`${id}-label`" :for="`${id}-input`" class="form-label d-block">{{ label }}</label>
        <div :class="{ 'cid-field--invalid': error }">
            <Cid10Picker
                :model-value="selection"
                :search-url="searchUrl"
                :multiple="false"
                :placeholder="placeholder || undefined"
                :input-id="`${id}-input`"
                :aria-labelledby="`${id}-label`"
                :aria-describedby="describedBy"
                :invalid="!!error"
                @update:model-value="onSelect"
            />
        </div>
        <div v-if="error" :id="`${id}-error`" class="invalid-feedback d-block" role="alert">{{ error }}</div>
        <small v-if="hint" :id="`${id}-hint`" class="form-text d-block">{{ hint }}</small>
    </div>
</template>

<style scoped>
.cid-field--invalid :deep(.form-control) {
    border-color: var(--bs-form-invalid-border-color);
}
</style>
