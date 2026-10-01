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
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Buscar...' },
    maxWidth: { type: String, default: '380px' },
    /** Rótulo acessível (e dica) do botão de limpar — opcional, traduzido pelo chamador. */
    clearLabel: { type: String, default: 'Limpar busca' },
    /** Classes do wrapper (padrão 'mb-3'); passe '' ao usar numa barra de filtros flex. */
    wrapperClass: { type: String, default: 'mb-3' },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div :class="wrapperClass">
        <div class="input-group input-group-sm" :style="{ maxWidth }">
            <span class="input-group-text bg-body">
                <i class="ti ti-search fs-12" aria-hidden="true"></i>
            </span>
            <input
                :value="modelValue"
                type="text"
                class="form-control border-start-0"
                :placeholder="placeholder"
                :aria-label="placeholder"
                @input="$emit('update:modelValue', $event.target.value)"
            />
            <button
                v-if="modelValue"
                class="btn btn-outline-secondary border-start-0"
                type="button"
                :title="clearLabel"
                :aria-label="clearLabel"
                @click="$emit('update:modelValue', '')"
            >
                <i class="ti ti-x fs-12" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</template>
