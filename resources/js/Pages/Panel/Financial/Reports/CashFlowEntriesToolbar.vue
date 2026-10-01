<script setup>
import { computed } from 'vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import { usePlural } from './useReportPage.js';

/**
 * Barra da lista de lançamentos: busca (descrição ou código FLC) e filtros de
 * tipo, status e categoria na mesma linha — aplicação automática
 * (useEntryFilters). Categorias = as que têm lançamento no período,
 * agrupadas por tipo. O aviso deixa claro que busca e filtros valem só para
 * a lista (indicadores, tabelas e exportação usam o período inteiro).
 */
const props = defineProps({
    categories: { type: Array, default: () => [] }, // [{ id, name, type }]
    hasFilters: { type: Boolean, default: false }, // filtros APLICADOS (servidor)
    total: { type: Number, default: 0 }, // lançamentos encontrados com os filtros
    loading: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['clear']);

const search = defineModel('search', { type: String, default: '' });
const type = defineModel('type', { type: String, default: '' });
const status = defineModel('status', { type: String, default: '' });
const category = defineModel('category', { type: String, default: '' });

const { plural } = usePlural();

const c = computed(() => props.t.cashflow ?? {});

const TYPES = ['income', 'expense'];
const STATUSES = ['paid', 'pending'];

const typeOptions = computed(() => TYPES.map((value) => ({ value, label: props.t.entry_type?.[value] ?? value })));
const statusOptions = computed(() =>
    STATUSES.map((value) => ({ value, label: props.t.entry_status?.[value] ?? value })),
);

/** Receitas e despesas em grupos (nomes podem se repetir entre tipos). */
const categoryGroups = computed(() =>
    [
        { type: 'income', label: c.value.col_income },
        { type: 'expense', label: c.value.col_expense },
    ]
        .map((group) => ({ ...group, options: props.categories.filter((category) => category.type === group.type) }))
        .filter((group) => group.options.length > 0),
);

// Categoria de tipo desconhecido (dado legado) não some da lista.
const ungroupedCategories = computed(() => props.categories.filter((category) => !TYPES.includes(category.type)));

const statusText = computed(() => {
    if (props.loading) return c.value.list_loading ?? '';

    return props.hasFilters ? plural(c.value, 'filtered_count', props.total) : '';
});
</script>

<template>
    <div class="mt-2" data-test="entries-toolbar">
        <div class="d-flex flex-wrap align-items-center gap-2" role="search" :aria-label="c.filters_label">
            <SearchInput
                v-model="search"
                :placeholder="c.search_placeholder"
                :clear-label="c.search_clear"
                max-width="280px"
                wrapper-class="cf-entries-toolbar__search"
            />
            <select
                v-model="type"
                class="form-select form-select-sm cf-entries-toolbar__select"
                :aria-label="c.filter_type"
                data-test="filter-type"
            >
                <option value="">{{ c.filter_type_all }}</option>
                <option v-for="option in typeOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
            <select
                v-model="status"
                class="form-select form-select-sm cf-entries-toolbar__select"
                :aria-label="c.filter_status"
                data-test="filter-status"
            >
                <option value="">{{ c.filter_status_all }}</option>
                <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
            <select
                v-model="category"
                class="form-select form-select-sm cf-entries-toolbar__select"
                :aria-label="c.filter_category"
                data-test="filter-category"
            >
                <option value="">{{ c.filter_category_all }}</option>
                <optgroup v-for="group in categoryGroups" :key="group.type" :label="group.label">
                    <option v-for="option in group.options" :key="option.id" :value="option.id">
                        {{ option.name }}
                    </option>
                </optgroup>
                <option v-for="option in ungroupedCategories" :key="option.id" :value="option.id">
                    {{ option.name }}
                </option>
            </select>
            <button
                v-if="hasFilters"
                type="button"
                class="btn btn-link btn-sm text-decoration-none px-1"
                data-test="filters-clear"
                @click="emit('clear')"
            >
                <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ c.filters_clear }}
            </button>
            <span class="small text-body-secondary" role="status" aria-live="polite" data-test="entries-status">
                <span v-if="loading" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                >{{ statusText }}
            </span>
        </div>
        <p class="small text-body-secondary mb-0 mt-2" data-test="filters-scope-hint">
            <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ c.filters_scope_hint }}
        </p>
    </div>
</template>

<style scoped>
.cf-entries-toolbar__select {
    width: auto;
    max-width: 220px;
}

@media (max-width: 575.98px) {
    .cf-entries-toolbar__search,
    .cf-entries-toolbar__select {
        flex: 1 1 100%;
        max-width: none;
    }
}
</style>
