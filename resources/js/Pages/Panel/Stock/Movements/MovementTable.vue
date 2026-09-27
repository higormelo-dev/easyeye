<script setup>
import { computed } from 'vue';
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';
import ColumnOrderMenu  from '@/Components/Panel/ColumnOrderMenu.vue';
import SortableTh       from '@/Components/Panel/SortableTh.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import { useColumnOrder } from '@/composables/useColumnOrder.js';
import { useTrans } from '@/composables/useTrans.js';
import { useMovementFormat } from './useMovementFormat.js';

/**
 * Tabela do extrato de estoque no padrão de Patients/PatientTable: cabeçalhos
 * ordenáveis (só as chaves de StockMovementsController::SORTABLE), menu
 * "Colunas" (ordem persistida no navegador), Tipo (badge) e Ações fixos no fim.
 *
 * Ledger imutável: não há editar/excluir — a única ação é o atalho para o
 * extrato do produto da linha (filtro da própria tela).
 */
const props = defineProps({
    items:   { type: Object, required: true },   // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    t:       { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'filterProduct']);

const { tx } = useTrans(() => props.t);
const { money, quantity, signedQuantity, dateTime, typeLabel, typeBadgeClass, directionIcon } = useMovementFormat();

const rows = computed(() => props.items?.data ?? []);

// ── Ordenação (padrão = o de sempre: data mais recente primeiro) ─────────────
const currentSort = computed(() => props.filters.sort ?? 'occurred_at');
const currentDir  = computed(() => props.filters.direction ?? 'desc');

// ── Ordem de colunas personalizável ──────────────────────────────────────────
const COLUMN_DEFS = computed(() => [
    { key: 'occurred_at',   label: props.t.col_occurred_at ?? 'Data',         sortKey: 'occurred_at' },
    { key: 'product',       label: props.t.col_product ?? 'Produto',          sortKey: 'product' },
    { key: 'lot',           label: props.t.col_lot ?? 'Lote' },
    { key: 'quantity',      label: props.t.col_quantity ?? 'Quantidade',      sortKey: 'quantity',      align: 'text-end' },
    { key: 'unit_cost',     label: props.t.col_unit_cost ?? 'Custo unit.',    sortKey: 'unit_cost',     align: 'text-end' },
    { key: 'balance_after', label: props.t.col_balance_after ?? 'Saldo após', sortKey: 'balance_after', align: 'text-end' },
    { key: 'note',          label: props.t.col_note ?? 'Observação' },
    { key: 'created_by',    label: props.t.col_created_by ?? 'Por' },
]);
const DEFAULT_COLUMN_ORDER = ['occurred_at', 'product', 'lot', 'quantity', 'unit_cost', 'balance_after', 'note', 'created_by'];

const { order: columnOrder, moveTo: moveColumn, reset: resetColumnOrder } = useColumnOrder(
    'stock_movements_columns_order',
    DEFAULT_COLUMN_ORDER,
);

const orderedColumns = computed(() => (
    columnOrder.value
        .map((key) => COLUMN_DEFS.value.find((c) => c.key === key))
        .filter(Boolean)
));

const columnMenuLabels = computed(() => ({
    moveUp:   props.t.columns_move_up,
    moveDown: props.t.columns_move_down,
    reset:    props.t.columns_reset,
}));

function isCurrentProduct(movement) {
    return String(props.filters.entity_product_id ?? '') === String(movement.entity_product_id);
}
</script>

<template>
    <!-- Toolbar: personalizar colunas -->
    <div class="d-flex justify-content-end mb-2">
        <ActionDropdown
            :title="t.columns_customize ?? 'Personalizar colunas'"
            align="right"
            :min-width="230"
            btn-class="bg-body border shadow-sm rounded px-2 py-1 d-flex align-items-center gap-1 fs-13 text-muted"
        >
            <template #trigger>
                <i class="ti ti-adjustments" aria-hidden="true"></i>
                <span class="d-none d-sm-inline">{{ t.columns_label ?? 'Colunas' }}</span>
            </template>

            <ColumnOrderMenu
                :columns="orderedColumns"
                :title="t.columns_order_title ?? 'Ordem das colunas'"
                :labels="columnMenuLabels"
                @move="moveColumn"
                @reset="resetColumnOrder"
            />
        </ActionDropdown>
    </div>

    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <template v-for="col in orderedColumns" :key="col.key">
                        <SortableTh
                            v-if="col.sortKey"
                            :col-key="col.sortKey"
                            :current-sort="currentSort"
                            :current-dir="currentDir"
                            :title="tx('sort_by', { column: col.label })"
                            :class="col.align"
                            @sort="emit('sort', $event)"
                        >{{ col.label }}</SortableTh>
                        <th v-else :class="col.align">{{ col.label }}</th>
                    </template>
                    <th class="text-center">{{ t.col_type ?? 'Tipo' }}</th>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length + 2" class="text-center text-muted py-5">
                        <i class="ti ti-transfer-in fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty_list ?? 'Nenhuma movimentação encontrada.' }}
                    </td>
                </tr>
                <tr v-for="m in rows" :key="m.id">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'occurred_at'" class="text-muted small">{{ dateTime(m.occurred_at_iso) }}</td>

                        <td v-else-if="col.key === 'product'">
                            <div class="fw-medium">{{ m.product_name ?? '—' }}</div>
                            <div v-if="m.product_code" class="text-muted small">{{ m.product_code }}</div>
                        </td>

                        <td v-else-if="col.key === 'lot'" class="small text-muted">{{ m.lot_number ?? '—' }}</td>

                        <td
                            v-else-if="col.key === 'quantity'"
                            class="text-end fw-medium"
                            :class="m.direction === 1 ? 'text-success' : 'text-danger'"
                        >{{ signedQuantity(m) }}</td>

                        <td v-else-if="col.key === 'unit_cost'" class="text-end">{{ money(m.unit_cost) }}</td>

                        <td v-else-if="col.key === 'balance_after'" class="text-end fw-semibold">{{ quantity(m.balance_after) }}</td>

                        <td v-else-if="col.key === 'note'" class="small text-muted">
                            <span v-if="m.note" class="movement-note d-inline-block text-truncate align-middle" :title="m.note">{{ m.note }}</span>
                            <template v-else>—</template>
                        </td>

                        <td v-else-if="col.key === 'created_by'" class="small text-muted">{{ m.created_by_name ?? '—' }}</td>
                    </template>

                    <td class="text-center">
                        <span :class="typeBadgeClass(m.type)">
                            <i :class="directionIcon(m)" class="me-1" aria-hidden="true"></i>{{ typeLabel(m) }}
                        </span>
                    </td>

                    <td class="text-end">
                        <ActionIconGroup align="end">
                            <ActionIconButton
                                icon="ti ti-list-search"
                                variant="info"
                                :title="t.action_filter_product ?? 'Ver extrato deste produto'"
                                :disabled="isCurrentProduct(m)"
                                @click="emit('filterProduct', m.entity_product_id)"
                            />
                        </ActionIconGroup>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <TablePagination
        :data="items"
        :showing-from="t.pagination_showing"
        :showing-of="t.pagination_of"
        :showing-suffix="t.pagination_suffix"
        :aria-label="t.pagination_label"
        :previous-label="t.pagination_previous"
        :next-label="t.pagination_next"
    />
</template>

<style scoped>
/* Observação longa não estica a tabela (table-nowrap): corta e mostra inteira no title. */
.movement-note {
    max-width: 240px;
}
</style>
