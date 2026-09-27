<script setup>
import { computed } from 'vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import { useTrans } from '@/composables/useTrans';
import CashEntryRowActions from './CashEntryRowActions.vue';
import CashFlowTotals from './CashFlowTotals.vue';
import { useCashEntryFormat } from './useCashEntryFormat.js';

/**
 * Tabela do Fluxo de Caixa (md+). Cabeçalhos ordenáveis só nas chaves da
 * whitelist do servidor (CashFlowController::SORTABLE); colunas secundárias
 * somem em telas menores (abaixo de md a página usa os cards).
 */
const props = defineProps({
    rows:     { type: Array,  default: () => [] },
    overview: { type: Object, default: () => ({}) },
    filters:  { type: Object, default: () => ({}) },
    busyId:   { type: String, default: null },
    t:        { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'edit', 'delete']);

const { tx } = useTrans(() => props.t);
const {
    date, typeLabel, statusLabel, originLabel, typeBadge, statusBadge, originIcon, entryAmount,
} = useCashEntryFormat(() => props.t);

const currentSort = computed(() => props.filters.sort || 'entry_date');
const currentDir  = computed(() => props.filters.direction || 'desc');

const sortTitle = (column) => tx('sort_by', { column });
</script>

<template>
    <div class="card cash-flow-table mb-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">{{ t.table_caption }}</caption>
                <thead class="table-light">
                    <tr>
                        <SortableTh
                            col-key="code"
                            class="d-none d-lg-table-cell"
                            :current-sort="currentSort"
                            :current-dir="currentDir"
                            :title="sortTitle(t.col_code)"
                            @sort="emit('sort', $event)"
                        >{{ t.col_code }}</SortableTh>
                        <SortableTh
                            col-key="entry_date"
                            :current-sort="currentSort"
                            :current-dir="currentDir"
                            :title="sortTitle(t.col_date)"
                            @sort="emit('sort', $event)"
                        >{{ t.col_date }}</SortableTh>
                        <SortableTh
                            col-key="description"
                            :current-sort="currentSort"
                            :current-dir="currentDir"
                            :title="sortTitle(t.col_description)"
                            @sort="emit('sort', $event)"
                        >{{ t.col_description }}</SortableTh>
                        <th scope="col" class="d-none d-lg-table-cell">{{ t.col_patient }}</th>
                        <th scope="col" class="d-none d-xl-table-cell">{{ t.col_category }}</th>
                        <th scope="col" class="d-none d-lg-table-cell">{{ t.col_payment_method }}</th>
                        <th scope="col" class="d-none d-xl-table-cell">{{ t.col_origin }}</th>
                        <SortableTh
                            col-key="type"
                            class="text-center"
                            :current-sort="currentSort"
                            :current-dir="currentDir"
                            :title="sortTitle(t.col_type)"
                            @sort="emit('sort', $event)"
                        >{{ t.col_type }}</SortableTh>
                        <SortableTh
                            col-key="status"
                            class="text-center"
                            :current-sort="currentSort"
                            :current-dir="currentDir"
                            :title="sortTitle(t.col_status)"
                            @sort="emit('sort', $event)"
                        >{{ t.col_status }}</SortableTh>
                        <SortableTh
                            col-key="amount"
                            class="text-end"
                            :current-sort="currentSort"
                            :current-dir="currentDir"
                            :title="sortTitle(t.col_value)"
                            @sort="emit('sort', $event)"
                        >{{ t.col_value }}</SortableTh>
                        <th scope="col" class="text-end">{{ t.col_actions }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in rows"
                        :key="entry.id"
                        :aria-busy="busyId === entry.id ? 'true' : 'false'"
                        :class="{ 'opacity-50': busyId === entry.id }"
                        :data-test="`row-${entry.id}`"
                    >
                        <td class="d-none d-lg-table-cell text-nowrap small text-muted" data-test="code">{{ entry.code || '—' }}</td>
                        <td class="text-nowrap small">{{ date(entry.entry_date) }}</td>
                        <td class="fw-medium cash-flow-table__truncate cash-flow-table__description" :title="entry.description">{{ entry.description }}</td>
                        <td class="d-none d-lg-table-cell small cash-flow-table__truncate" :title="entry.patient_name || undefined" data-test="patient">
                            {{ entry.patient_name || '—' }}
                        </td>
                        <td class="d-none d-xl-table-cell small text-muted">{{ entry.category_name || '—' }}</td>
                        <td class="d-none d-lg-table-cell small text-nowrap" data-test="payment-method">{{ entry.payment_method_label || '—' }}</td>
                        <td class="d-none d-xl-table-cell text-nowrap" data-test="origin">
                            <span class="badge rounded badge-soft-secondary border fs-11 fw-medium">
                                <i :class="originIcon(entry.origin)" class="me-1" aria-hidden="true"></i>{{ originLabel(entry.origin) }}
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge rounded fs-11 fw-medium" :class="typeBadge(entry.type)">{{ typeLabel(entry.type) }}</span>
                        </td>
                        <td class="text-center">
                            <span class="badge rounded fs-11 fw-medium" :class="statusBadge(entry.status)">{{ statusLabel(entry.status) }}</span>
                        </td>
                        <td class="text-end fw-bold text-nowrap text-body cash-flow-table__amount" data-test="amount">
                            <i
                                class="ti me-1"
                                :class="entry.type === 'expense' ? 'ti-arrow-up-right text-danger' : 'ti-arrow-down-left text-success'"
                                aria-hidden="true"
                            ></i>{{ entryAmount(entry) }}
                        </td>
                        <td class="text-end text-nowrap">
                            <CashEntryRowActions
                                :entry="entry"
                                :busy="busyId === entry.id"
                                :t="t"
                                @edit="emit('edit', $event)"
                                @delete="emit('delete', $event)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-body-tertiary">
            <CashFlowTotals :overview="overview" :t="t" />
        </div>
    </div>
</template>

<style scoped>
/* Texto longo não estica a linha (tablet do balcão): trunca com title. */
.cash-flow-table__truncate {
    max-width: 14rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cash-flow-table__description {
    max-width: 20rem;
}

.cash-flow-table__amount {
    font-variant-numeric: tabular-nums;
}
</style>
