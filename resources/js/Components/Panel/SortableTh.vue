<script setup>
import { computed } from 'vue';

/**
 * SortableTh — Cabeçalho de coluna ordenável com ícone.
 *
 * Props:
 *   colKey      – chave da coluna (string) enviada no evento 'sort'
 *   currentSort – coluna atualmente ordenada
 *   currentDir  – direção atual: 'asc' | 'desc'
 *   title       – (opcional) dica traduzida do botão, ex.: "Ordenar por Nome"
 *   class/style – repassados ao <th> (ex.: "text-center", { width: '70px' })
 *
 * Emits:
 *   sort({ sort, direction }) – quando o usuário aciona o cabeçalho
 *
 * Slot default: texto/conteúdo do cabeçalho
 *
 * Acessível: o gatilho é um <button> (Tab/Enter/Espaço) dentro do <th>, que
 * expõe `aria-sort`; visualmente idêntico ao cabeçalho clicável anterior.
 */
const props = defineProps({
    colKey:      { type: String, required: true },
    currentSort: { type: String, default: '' },
    currentDir:  { type: String, default: 'asc' },
    title:       { type: String, default: undefined },
});

const emit = defineEmits(['sort']);

const isCurrent = computed(() => props.currentSort === props.colKey);

const icon = computed(() => {
    if (!isCurrent.value) return 'ti ti-arrows-sort text-muted';
    return props.currentDir === 'asc' ? 'ti ti-sort-ascending' : 'ti ti-sort-descending';
});

const ariaSort = computed(() => {
    if (!isCurrent.value) return 'none';
    return props.currentDir === 'asc' ? 'ascending' : 'descending';
});

function handleClick() {
    const dir = isCurrent.value && props.currentDir === 'asc' ? 'desc' : 'asc';
    emit('sort', { sort: props.colKey, direction: dir });
}
</script>

<template>
    <th :aria-sort="ariaSort">
        <button type="button" class="sortable-th-btn" :title="title" @click="handleClick">
            <slot />
            <i :class="icon" class="ms-1 fs-11" aria-hidden="true"></i>
        </button>
    </th>
</template>

<style scoped>
.sortable-th-btn {
    background: none;
    border: 0;
    padding: 0;
    color: inherit;
    font: inherit;
    text-align: inherit;
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
}
.sortable-th-btn:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
    border-radius: 2px;
}
</style>
