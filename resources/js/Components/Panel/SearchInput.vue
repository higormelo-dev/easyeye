<script setup>
/**
 * SearchInput — Campo de busca com botão de limpar.
 *
 * Props:
 *   modelValue  – valor atual (v-model)
 *   placeholder – placeholder do input
 *   maxWidth    – largura máxima (CSS, default "380px")
 *
 * Emits:
 *   update:modelValue – quando o valor muda (v-model compat)
 */
defineProps({
    modelValue:  { type: String, default: '' },
    placeholder: { type: String, default: 'Buscar...' },
    maxWidth:    { type: String, default: '380px' },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="mb-3">
        <div class="input-group input-group-sm" :style="{ maxWidth }">
            <span class="input-group-text bg-white">
                <i class="ti ti-search fs-12"></i>
            </span>
            <input
                :value="modelValue"
                type="text"
                class="form-control border-start-0"
                :placeholder="placeholder"
                @input="$emit('update:modelValue', $event.target.value)"
            >
            <button
                v-if="modelValue"
                class="btn btn-outline-secondary border-start-0"
                type="button"
                @click="$emit('update:modelValue', '')"
            >
                <i class="ti ti-x fs-12"></i>
            </button>
        </div>
    </div>
</template>
