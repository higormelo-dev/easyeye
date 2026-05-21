<script setup>
import { computed } from 'vue';

/**
 * SortableTh — Cabeçalho de coluna clicável com ícone de ordenação.
 *
 * Props:
 *   colKey      – chave da coluna (string) enviada no evento 'sort'
 *   currentSort – coluna atualmente ordenada
 *   currentDir  – direção atual: 'asc' | 'desc'
 *   class       – classes adicionais para o <th> (ex: "text-center", "text-end")
 *   style       – style inline para o <th> (ex: { width: '70px' })
 *
 * Emits:
 *   sort({ sort, direction }) – quando o usuário clica no cabeçalho
 *
 * Slot default: texto/conteúdo do cabeçalho
 */
const props = defineProps({
    colKey:      { type: String, required: true },
    currentSort: { type: String, default: '' },
    currentDir:  { type: String, default: 'asc' },
});

const emit = defineEmits(['sort']);

const icon = computed(() => {
    if (props.currentSort !== props.colKey) return 'ti ti-arrows-sort text-muted';
    return props.currentDir === 'asc' ? 'ti ti-sort-ascending' : 'ti ti-sort-descending';
});

function handleClick() {
    const dir = props.currentSort === props.colKey && props.currentDir === 'asc' ? 'desc' : 'asc';
    emit('sort', { sort: props.colKey, direction: dir });
}
</script>

<template>
    <th class="cursor-pointer user-select-none" @click="handleClick">
        <slot />
        <i :class="icon" class="ms-1 fs-11"></i>
    </th>
</template>

<style scoped>
.cursor-pointer { cursor: pointer; }
</style>
