<script setup>
import { computed } from 'vue';
import Multiselect from '@vueform/multiselect';

// Select com busca, nativo Vue 3 (sem jQuery, SSR-safe).
// Drop-in para <select> simples: v-model + :options (array de objetos).
const props = defineProps({
    modelValue:  { type: [String, Number, Boolean, null], default: null },
    options:     { type: Array,   default: () => [] },
    valueKey:    { type: String,  default: 'id' },
    labelKey:    { type: String,  default: 'name' },
    placeholder: { type: String,  default: 'Selecione...' },
    disabled:    { type: Boolean, default: false },
    clearable:   { type: Boolean, default: true },
    searchable:  { type: Boolean, default: true },
    invalid:     { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change']);

// Normaliza '' (valor vazio do form) <-> null (interno do multiselect).
const value = computed({
    get: () => (props.modelValue === '' ? null : props.modelValue),
    set: (v) => {
        const out = v ?? '';
        emit('update:modelValue', out);
        emit('change', out); // drop-in para handlers @change (recebe o VALOR, não o evento)
    },
});
</script>

<template>
    <Multiselect
        v-model="value"
        class="search-select"
        :class="{ 'is-invalid': invalid }"
        :options="options"
        :value-prop="valueKey"
        :label="labelKey"
        :track-by="labelKey"
        :searchable="searchable"
        :can-clear="clearable"
        :can-deselect="clearable"
        :close-on-select="true"
        :placeholder="placeholder"
        :disabled="disabled"
        no-options-text="Nenhuma opção"
        no-results-text="Nada encontrado"
    />
</template>

<style src="@vueform/multiselect/themes/default.css"></style>

<style>
/* Alinha o multiselect ao visual do .form-select (Bootstrap 5). */
.search-select.multiselect {
    --ms-radius: .375rem;
    --ms-border-color: var(--bs-border-color, #dee2e6);
    --ms-ring-width: .25rem;
    --ms-ring-color: rgba(13, 110, 253, .25);
    --ms-py: .375rem;
    --ms-px: .75rem;
    --ms-font-size: 1rem;
    --ms-line-height: 1.5;
    --ms-option-font-size: 1rem;
    --ms-dropdown-border-color: var(--bs-border-color, #dee2e6);
    --ms-dropdown-radius: .375rem;
    min-height: calc(1.5em + .75rem + 2px);
}

.search-select.multiselect.is-active {
    --ms-border-color: #86b7fe;
    box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25);
}

.search-select.multiselect.is-invalid {
    --ms-border-color: #dc3545;
    --ms-ring-color: rgba(220, 53, 69, .25);
}
</style>
