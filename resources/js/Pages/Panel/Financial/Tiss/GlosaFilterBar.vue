<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import PeriodFilter from '@/Components/Panel/PeriodFilter.vue';

/**
 * Busca + filtros na mesma barra (padrão Pacientes/Estoque): busca por nº da
 * guia, código ou motivo (debounce de 400 ms), status da aba, convênio/
 * operadora e — fora da aba Pendentes — o período (data de identificação).
 * Tudo aplicado na hora: emite `change` com o trecho alterado.
 */
const props = defineProps({
    filters:       { type: Object,  default: () => ({}) },
    operators:     { type: Array,   default: () => [] },
    statusOptions: { type: Array,   default: () => [] },
    tab:           { type: String,  default: 'pending' },
    today:         { type: String,  default: '' },
    filtering:     { type: Boolean, default: false },
    t:             { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['change', 'clear']);

const SEARCH_DEBOUNCE_MS = 400;

const search     = ref(props.filters.search ?? '');
const status     = ref(props.filters.status ?? '');
const operatorId = ref(props.filters.operator_id ?? '');

let searchTimer = null;

// O servidor devolve os filtros normalizados (status fora da aba → vazio etc.).
// A busca só é sobrescrita se não houver digitação pendente.
watch(() => props.filters, (f) => {
    status.value     = f?.status ?? '';
    operatorId.value = f?.operator_id ?? '';
    if (!searchTimer) search.value = f?.search ?? '';
});

function onSearch(value) {
    search.value = value;
    clearTimeout(searchTimer);

    // Limpar (botão × ou apagar tudo) aplica na hora.
    if (value.trim() === '') {
        searchTimer = null;
        emit('change', { search: '' });

        return;
    }

    searchTimer = setTimeout(() => {
        searchTimer = null;
        emit('change', { search: search.value.trim() });
    }, SEARCH_DEBOUNCE_MS);
}

onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
    <div class="glosa-toolbar d-flex flex-wrap align-items-center gap-2 mb-3" role="group" :aria-label="t.filters_label" data-test="glosa-filters">
        <SearchInput
            :model-value="search"
            :placeholder="t.search_placeholder"
            :clear-label="t.search_clear"
            max-width="300px"
            wrapper-class=""
            data-test="glosa-search"
            @update:model-value="onSearch"
        />

        <div>
            <label for="glosas-filter-status" class="visually-hidden">{{ t.filter_status }}</label>
            <select
                id="glosas-filter-status"
                v-model="status"
                class="form-select form-select-sm glosa-toolbar__select"
                @change="emit('change', { status, due: '' })"
            >
                <option value="">{{ t.filter_status_all }}</option>
                <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </div>

        <div>
            <label for="glosas-filter-operator" class="visually-hidden">{{ t.filter_operator }}</label>
            <select
                id="glosas-filter-operator"
                v-model="operatorId"
                class="form-select form-select-sm glosa-toolbar__select"
                @change="emit('change', { operator_id: operatorId })"
            >
                <option value="">{{ t.filter_operator_all }}</option>
                <option v-for="operator in operators" :key="operator.id" :value="operator.id">{{ operator.name }}</option>
            </select>
        </div>

        <PeriodFilter
            v-if="tab !== 'pending'"
            :from="filters.from ?? ''"
            :to="filters.to ?? ''"
            :today="today"
            :labels="t.shared?.period"
            compact
            @change="({ from, to }) => emit('change', { from, to })"
        />
        <span v-else class="small text-muted d-inline-flex align-items-center gap-1" data-test="period-any">
            <i class="ti ti-calendar-off" aria-hidden="true"></i>{{ t.period_any }}
        </span>

        <div class="d-flex align-items-center gap-2 ms-auto">
            <span class="small text-muted d-inline-flex align-items-center gap-1" role="status" aria-live="polite" data-test="glosa-filtering">
                <template v-if="filtering">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>{{ t.filtering }}
                </template>
            </span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-test="glosa-clear-filters" :disabled="filtering" @click="emit('clear')">
                <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ t.filter_clear }}
            </button>
        </div>

        <p v-if="tab !== 'pending'" class="w-100 small text-muted mb-0" data-test="period-hint">{{ t.period_hint }}</p>
    </div>
</template>

<style scoped>
.glosa-toolbar__select {
    min-width: 11rem;
    max-width: 15rem;
}

@media (max-width: 575.98px) {
    .glosa-toolbar__select {
        max-width: none;
        width: 100%;
    }
}
</style>
