<script setup>
import { ref, watch } from 'vue';
import PeriodFilter from '@/Components/Panel/PeriodFilter.vue';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Barra de filtros do Faturamento (aplicação automática, sem "Filtrar"):
 * período (data do atendimento, com atalhos), convênio, status das guias (só
 * na aba Guias) e o lote escolhido pelo link do código (chip removível).
 * Emite `change` com o trecho alterado; o Index junta com o resto e visita.
 */
const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    covenants: { type: Array, default: () => [] },
    /** Convênio do filtro que não está em `covenants` (inativo/excluído). */
    filteredCovenant: { type: Object, default: null },
    claimStatuses: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    showStatus: { type: Boolean, default: false },
    filtering: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['change', 'clear']);

const { tx } = useTrans(() => props.t);

const covenantId = ref(props.filters.covenant_id ?? '');
const claimStatus = ref(props.filters.claim_status ?? '');

// O servidor devolve os filtros normalizados (id inválido → sem filtro etc.).
watch(
    () => props.filters,
    (f) => {
        covenantId.value = f?.covenant_id ?? '';
        claimStatus.value = f?.claim_status ?? '';
    },
);

function onPeriod({ from, to }) {
    emit('change', { from, to });
}
</script>

<template>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div
                class="d-flex flex-wrap align-items-end gap-2"
                role="group"
                :aria-label="t.filters_label"
                data-test="filters"
            >
                <PeriodFilter
                    :from="filters.from ?? ''"
                    :to="filters.to ?? ''"
                    :today="today"
                    :labels="t.shared?.period"
                    compact
                    @change="onPeriod"
                />

                <div>
                    <label for="billing-filter-covenant" class="visually-hidden">{{ t.filter_covenant }}</label>
                    <select
                        id="billing-filter-covenant"
                        v-model="covenantId"
                        class="form-select form-select-sm billing-filter__select"
                        @change="emit('change', { covenant_id: covenantId })"
                    >
                        <option value="">{{ t.filter_covenant_all }}</option>
                        <option v-for="c in covenants" :key="c.id" :value="c.id">{{ c.name }}</option>
                        <option v-if="filteredCovenant" :key="filteredCovenant.id" :value="filteredCovenant.id">
                            {{ filteredCovenant.name }}
                        </option>
                    </select>
                </div>

                <div v-if="showStatus">
                    <label for="billing-filter-status" class="visually-hidden">{{ t.filter_claim_status }}</label>
                    <select
                        id="billing-filter-status"
                        v-model="claimStatus"
                        class="form-select form-select-sm billing-filter__select"
                        @change="emit('change', { claim_status: claimStatus })"
                    >
                        <option value="">{{ t.filter_status_all }}</option>
                        <option v-for="s in claimStatuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>

                <span
                    v-if="filters.batch_code"
                    class="badge badge-soft-primary d-inline-flex align-items-center gap-2 fs-6 fw-normal py-2 align-self-center"
                    data-test="batch-chip"
                >
                    <span
                        ><i class="ti ti-package me-1" aria-hidden="true"></i
                        >{{ tx('filter_batch', { code: filters.batch_code }) }}</span
                    >
                    <button
                        type="button"
                        class="btn-close billing-filter__chip-close"
                        :aria-label="tx('filter_batch_clear', { code: filters.batch_code })"
                        data-test="batch-chip-clear"
                        @click="emit('change', { batch_id: '' })"
                    ></button>
                </span>

                <div class="d-flex align-items-center gap-2 ms-auto">
                    <span
                        class="small text-muted d-inline-flex align-items-center gap-1"
                        role="status"
                        aria-live="polite"
                        data-test="filtering"
                    >
                        <template v-if="filtering">
                            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>{{ t.filtering }}
                        </template>
                    </span>
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-test="clear-filters"
                        :disabled="filtering"
                        @click="emit('clear')"
                    >
                        <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ t.filter_clear }}
                    </button>
                </div>
            </div>
            <p class="small text-muted mb-0 mt-2">{{ t.filter_hint }}</p>
        </div>
    </div>
</template>

<style scoped>
.billing-filter__select {
    min-width: 12rem;
    max-width: 16rem;
}

.billing-filter__chip-close {
    width: 0.625rem;
    height: 0.625rem;
    padding: 0.25rem;
    background-size: 0.625rem;
}

@media (max-width: 575.98px) {
    .billing-filter__select {
        max-width: none;
        width: 100%;
    }
}
</style>
