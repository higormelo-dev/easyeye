<script setup>
import { computed, ref } from 'vue';
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';
import ColumnOrderMenu  from '@/Components/Panel/ColumnOrderMenu.vue';
import SortableTh       from '@/Components/Panel/SortableTh.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import { useColumnOrder } from '@/composables/useColumnOrder.js';
import { useTrans } from '@/composables/useTrans.js';
import { useCountFormat } from './useCountFormat.js';

/**
 * Tabela da contagem de estoque no padrão de Patients/PatientTable:
 * cabeçalhos ordenáveis (só as chaves de StockCountsController::SORTABLE),
 * menu "Colunas" (ordem persistida no navegador). Contado (campo), Diferença
 * (status) e Ações ficam fixos no fim.
 *
 * O valor digitado mora no pai (Index) — aqui só exibe e emite `count`.
 */
const props = defineProps({
    products: { type: Object, required: true },   // paginator Laravel
    counted:  { type: Object, default: () => ({}) }, // { [productId]: texto digitado }
    deltas:   { type: Object, default: () => ({}) }, // { [productId]: contado − sistema | null }
    filters:  { type: Object, default: () => ({}) },
    t:        { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'count']);

const { tx } = useTrans(() => props.t);
const { quantity, unitLabel, differenceBadge } = useCountFormat(() => props.t);

const rows = computed(() => props.products?.data ?? []);

// Atalho abre em nova aba (a contagem digitada mora nesta): o título avisa.
const movementsTitle = computed(() => (
    `${props.t.action_movements ?? 'Ver movimentações do produto'} (${props.t.opens_new_tab ?? 'abre em nova aba'})`
));

// ── Ordenação (padrão = o de sempre: nome A→Z) ───────────────────────────────
const currentSort = computed(() => props.filters.sort ?? 'name');
const currentDir  = computed(() => props.filters.direction ?? 'asc');

// ── Ordem de colunas personalizável ──────────────────────────────────────────
const COLUMN_DEFS = computed(() => [
    { key: 'product',     label: props.t.col_product ?? 'Produto',              sortKey: 'name' },
    { key: 'code',        label: props.t.col_code ?? 'Código',                  sortKey: 'code' },
    { key: 'category',    label: props.t.col_category ?? 'Categoria',           sortKey: 'category' },
    { key: 'qty_on_hand', label: props.t.col_qty_on_hand ?? 'Saldo do sistema', sortKey: 'qty_on_hand', align: 'text-end' },
]);
const DEFAULT_COLUMN_ORDER = ['product', 'code', 'category', 'qty_on_hand'];

const { order: columnOrder, moveTo: moveColumn, reset: resetColumnOrder } = useColumnOrder(
    'stock_counts_columns_order',
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

const FIXED_COLUMNS = 3; // Contado, Diferença, Ações

// Contagem é digitação em sequência: Enter leva ao próximo campo "Contado"
// (o atalho da linha continua na ordem do Tab, acessível por teclado).
const tableEl = ref(null);

function focusNextCount(event) {
    const inputs = [...(tableEl.value?.querySelectorAll('[data-count-input]') ?? [])];
    const next = inputs[inputs.indexOf(event.currentTarget) + 1];
    if (!next) return;

    next.focus();
    next.select();
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
        <table ref="tableEl" class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <SortableTh
                        v-for="col in orderedColumns"
                        :key="col.key"
                        :col-key="col.sortKey"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        :title="tx('sort_by', { column: col.label })"
                        :class="col.align"
                        @sort="emit('sort', $event)"
                    >{{ col.label }}</SortableTh>
                    <th>{{ t.col_counted ?? 'Contado' }}</th>
                    <th class="text-center">{{ t.col_difference ?? 'Diferença' }}</th>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length + FIXED_COLUMNS" class="text-center text-muted py-5">
                        <i class="ti ti-clipboard-off fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty_list ?? 'Nenhum produto ativo encontrado para contar.' }}
                    </td>
                </tr>
                <tr v-for="p in rows" :key="p.id">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'product'">
                            <span class="fw-medium">{{ p.name }}</span>
                            <span
                                v-if="p.requires_lot"
                                class="badge badge-soft-info rounded text-info border border-info fs-11 ms-1"
                            >{{ t.requires_lot ?? 'Exige lote' }}</span>
                        </td>

                        <td v-else-if="col.key === 'code'">
                            <code class="text-muted small">{{ p.code }}</code>
                        </td>

                        <td v-else-if="col.key === 'category'" class="small text-muted">{{ p.category_name ?? '—' }}</td>

                        <td v-else-if="col.key === 'qty_on_hand'" class="text-end">
                            {{ quantity(p.qty_on_hand) }}
                            <span class="text-muted small">{{ unitLabel(p) }}</span>
                        </td>
                    </template>

                    <td>
                        <input
                            type="number"
                            step="0.001"
                            min="0"
                            inputmode="decimal"
                            class="form-control form-control-sm count-input"
                            placeholder="—"
                            data-count-input
                            :value="counted[p.id] ?? ''"
                            :aria-label="tx('counted_label', { product: p.name })"
                            @input="emit('count', p.id, $event.target.value)"
                            @keydown.enter.prevent="focusNextCount"
                        >
                    </td>

                    <td class="text-center">
                        <span v-if="differenceBadge(deltas[p.id])" :class="differenceBadge(deltas[p.id]).class">
                            {{ differenceBadge(deltas[p.id]).text }}
                        </span>
                        <span v-else class="text-muted">—</span>
                    </td>

                    <td class="text-end">
                        <ActionIconGroup align="end">
                            <ActionIconButton
                                icon="ti ti-transfer-in"
                                variant="info"
                                :title="movementsTitle"
                                :href="p.movements_url"
                                target="_blank"
                            />
                        </ActionIconGroup>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <TablePagination
        :data="products"
        :showing-from="t.pagination_showing"
        :showing-of="t.pagination_of"
        :showing-suffix="t.pagination_suffix"
        :aria-label="t.pagination_label"
        :previous-label="t.pagination_previous"
        :next-label="t.pagination_next"
    />
</template>

<style scoped>
.count-input {
    min-width: 110px;
    max-width: 160px;
}
</style>
