<script setup>
import { computed, ref, watchEffect } from 'vue';
import SearchInput     from '@/Components/Panel/SearchInput.vue';
import SortableTh      from '@/Components/Panel/SortableTh.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { isPaginator, pageRows } from './billingHelpers.js';

/**
 * Aba "A faturar": atendimentos (situação Atendido) sem guia ativa. Seleção
 * em massa para o lote e guia individual por linha. Paginada no servidor
 * (eligible_page), com busca pelo paciente e ordenação pela whitelist do
 * servidor. "Selecionar todos" marca a página; a seleção atravessa as páginas
 * (o Index guarda os marcados) e o contador mostra o total.
 */
const props = defineProps({
    /** Paginator do Laravel ({ data, links, total... }); array simples também serve. */
    schedules:   { type: [Object, Array], default: () => ({ data: [] }) },
    selectedIds: { type: Array,  default: () => [] },
    search:      { type: String, default: '' },
    sort:        { type: String, default: 'date' },
    direction:   { type: String, default: 'asc' },
    t:           { type: Object, default: () => ({}) },
});

const emit = defineEmits(['toggle', 'toggle-all', 'bill', 'new-batch', 'search', 'sort']);

const { tx } = useTrans(() => props.t);
const { dateTime, money } = useLocaleFormat();

const selectAllRef = ref(null);

const rows     = computed(() => pageRows(props.schedules));
const hasPages = computed(() => isPaginator(props.schedules) && props.schedules.last_page > 1);

const allSelected = computed(() => (
    rows.value.length > 0 && rows.value.every((s) => props.selectedIds.includes(s.id))
));
const someSelected = computed(() => (
    !allSelected.value && rows.value.some((s) => props.selectedIds.includes(s.id))
));

// Estado "parcial" do checkbox do cabeçalho (só existe via JS).
watchEffect(() => {
    if (selectAllRef.value) selectAllRef.value.indeterminate = someSelected.value;
});

function isSelected(id) {
    return props.selectedIds.includes(id);
}
</script>

<template>
    <div class="card mb-0">
        <div class="card-header bg-transparent border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
            <SearchInput
                :model-value="search"
                :placeholder="t.search_eligible"
                :clear-label="t.search_clear"
                max-width="320px"
                wrapper-class=""
                data-test="eligible-search"
                @update:model-value="emit('search', $event)"
            />
            <div class="d-flex align-items-center flex-wrap gap-2 ms-auto">
                <span class="small text-muted" aria-live="polite">
                    {{ selectedIds.length ? tx('eligible_selected', { count: selectedIds.length }) : '' }}
                </span>
                <button type="button" class="btn btn-primary btn-sm" data-test="new-batch" @click="emit('new-batch')">
                    <i class="ti ti-package me-1" aria-hidden="true"></i>
                    {{ selectedIds.length ? tx('btn_bill_selected', { count: selectedIds.length }) : t.btn_new_batch }}
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="billing-eligible__check">
                            <input
                                ref="selectAllRef"
                                type="checkbox"
                                class="form-check-input"
                                data-test="select-all"
                                :checked="allSelected"
                                :disabled="rows.length === 0"
                                :aria-label="t.eligible_select_all"
                                @change="emit('toggle-all')"
                            >
                        </th>
                        <SortableTh col-key="date" scope="col" :current-sort="sort" :current-dir="direction" :title="tx('sort_by', { column: t.col_date })" @sort="emit('sort', $event)">{{ t.col_date }}</SortableTh>
                        <SortableTh col-key="patient" scope="col" :current-sort="sort" :current-dir="direction" :title="tx('sort_by', { column: t.col_patient })" @sort="emit('sort', $event)">{{ t.col_patient }}</SortableTh>
                        <th scope="col" class="d-none d-lg-table-cell">{{ t.col_doctor }}</th>
                        <SortableTh col-key="covenant" scope="col" :current-sort="sort" :current-dir="direction" :title="tx('sort_by', { column: t.col_covenant })" @sort="emit('sort', $event)">{{ t.col_covenant }}</SortableTh>
                        <th scope="col" class="d-none d-lg-table-cell">{{ t.col_visit_type }}</th>
                        <th scope="col" class="text-end d-none d-lg-table-cell">{{ t.col_suggested_price }}</th>
                        <th scope="col" class="text-end"><span class="visually-hidden">{{ t.col_actions }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="ti ti-clipboard-off fs-1 d-block mb-2" aria-hidden="true"></i>
                            <p v-if="search" class="fw-medium mb-0" data-test="eligible-no-results">{{ tx('search_no_results', { term: search }) }}</p>
                            <template v-else>
                                <p class="fw-medium mb-1">{{ t.no_eligible }}</p>
                                <p class="small mb-0">{{ t.no_eligible_hint }}</p>
                            </template>
                        </td>
                    </tr>
                    <tr v-for="s in rows" :key="s.id" :class="{ 'table-active': isSelected(s.id) }" data-test="eligible-row">
                        <td>
                            <input
                                type="checkbox"
                                class="form-check-input"
                                :checked="isSelected(s.id)"
                                :aria-label="tx('eligible_select_row', { patient: s.patient_name || '—', date: dateTime(s.date_time) })"
                                @change="emit('toggle', s.id)"
                            >
                        </td>
                        <td class="text-nowrap small text-muted">{{ dateTime(s.date_time) }}</td>
                        <td class="fw-medium">{{ s.patient_name || '—' }}</td>
                        <td class="d-none d-lg-table-cell text-muted">{{ s.doctor_name || '—' }}</td>
                        <td>{{ s.covenant_name || t.no_covenant }}</td>
                        <td class="d-none d-lg-table-cell small text-muted">{{ s.visit_type || '—' }}</td>
                        <td class="d-none d-lg-table-cell text-end text-nowrap small" data-test="eligible-price">
                            {{ s.suggested_price === null || s.suggested_price === undefined ? '—' : money(s.suggested_price) }}
                        </td>
                        <td class="text-end">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary text-nowrap"
                                :aria-label="tx('btn_bill_label', { patient: s.patient_name || '—' })"
                                @click="emit('bill', s)"
                            >
                                <i class="ti ti-receipt me-1" aria-hidden="true"></i>{{ t.btn_bill }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="hasPages" class="px-3 pb-3" data-test="eligible-pagination">
            <TablePagination
                :data="schedules"
                :showing-from="t.pagination_showing"
                :showing-of="t.pagination_of"
                :showing-suffix="t.pagination_suffix?.eligible"
                :aria-label="tx('pagination_label', { tab: t.tab_eligible })"
                :previous-label="t.pagination_previous"
                :next-label="t.pagination_next"
            />
        </div>
    </div>
</template>

<style scoped>
.billing-eligible__check {
    width: 36px;
}
</style>
