<script setup>
import { computed } from 'vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Busca local (código ou nome) + filtros Todos / Com preço / Sem preço da
 * Tabela de Preços. Filtros são botões com aria-pressed e a contagem de cada um.
 * Slot `actions`: ações da grade à direita (ex.: menu "Ajustar preços").
 */
const props = defineProps({
    search: { type: String, default: '' },
    /** 'all' | 'priced' | 'unpriced' */
    filter: { type: String, default: 'all' },
    /** { all, priced, unpriced } */
    counts: { type: Object, default: () => ({ all: 0, priced: 0, unpriced: 0 }) },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:search', 'update:filter']);

const { tx } = useTrans(() => props.t);
const { number } = useLocaleFormat();

const chips = computed(() => [
    { key: 'all', label: tx('filter_all') },
    { key: 'priced', label: tx('filter_priced') },
    { key: 'unpriced', label: tx('filter_unpriced') },
]);
</script>

<template>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-test="prices-toolbar">
        <SearchInput
            :model-value="search"
            :placeholder="tx('search_placeholder')"
            :clear-label="tx('search_clear')"
            max-width="320px"
            wrapper-class="pp-search"
            @update:model-value="emit('update:search', $event)"
        />
        <div class="d-flex flex-wrap gap-1" role="group" :aria-label="tx('filter_label')" data-test="price-filter">
            <button
                v-for="chip in chips"
                :key="chip.key"
                type="button"
                class="btn btn-sm rounded-pill pp-chip"
                :class="filter === chip.key ? 'btn-primary' : 'btn-outline-secondary'"
                :aria-pressed="filter === chip.key ? 'true' : 'false'"
                :data-chip="chip.key"
                @click="emit('update:filter', chip.key)"
            >
                {{ chip.label }} <span class="pp-chip__count">{{ number(counts[chip.key] ?? 0) }}</span>
            </button>
        </div>
        <div v-if="$slots.actions" class="ms-auto">
            <slot name="actions" />
        </div>
    </div>
</template>

<style scoped>
.pp-search {
    flex: 1 1 16rem;
    max-width: 20rem;
}

.pp-chip__count {
    font-variant-numeric: tabular-nums;
    opacity: 0.8;
}

.pp-chip:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
}
</style>
