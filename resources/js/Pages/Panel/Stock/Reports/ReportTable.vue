<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import { useColumnOrder } from '@/composables/useColumnOrder.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Tabela de um relatório de estoque no padrão de Patients/PatientTable: menu
 * "Colunas" (ordem persistida no navegador), cabeçalhos ordenáveis via
 * SortableTh (só as chaves da whitelist de StockReportsController) e estado
 * vazio com ícone. Colunas `fixed` (ex.: Classe ABC) ficam sempre no fim,
 * como Status na tabela de pacientes. Relatório não tem ações por linha.
 *
 * Célula customizada: slot `cell-<key>` com `{ row }`; sem slot, mostra o
 * valor cru ou '—'. O resumo do relatório (ex.: valor total) vai no slot
 * `summary`, na mesma linha do botão "Colunas".
 */
const props = defineProps({
    rows: { type: Array, default: () => [] },
    // [{ key, label, sortable?, align?: 'end'|'center', fixed?, cellClass? }]
    columns: { type: Array, required: true },
    sort: { type: String, default: '' },
    direction: { type: String, default: 'desc' },
    storageKey: { type: String, required: true },
    rowKey: { type: String, default: 'id' },
    emptyIcon: { type: String, default: 'ti ti-report-off' },
    emptyText: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort']);

const { tx } = useTrans(() => props.t);

// ── Ordem de colunas personalizável ──────────────────────────────────────────
const movableColumns = computed(() => props.columns.filter((c) => !c.fixed));
const fixedColumns = computed(() => props.columns.filter((c) => c.fixed));

const {
    order: columnOrder,
    moveTo: moveColumn,
    reset: resetColumnOrder,
} = useColumnOrder(
    props.storageKey,
    props.columns.filter((c) => !c.fixed).map((c) => c.key),
);

const orderedMovable = computed(() =>
    columnOrder.value.map((key) => movableColumns.value.find((c) => c.key === key)).filter(Boolean),
);

const orderedColumns = computed(() => [...orderedMovable.value, ...fixedColumns.value]);

const columnMenuLabels = computed(() => ({
    moveUp: props.t.columns_move_up,
    moveDown: props.t.columns_move_down,
    reset: props.t.columns_reset,
}));

const ALIGN_CLASS = { end: 'text-end', center: 'text-center' };

function alignClass(col) {
    return ALIGN_CLASS[col.align] ?? '';
}

function keyOf(row, index) {
    return row?.[props.rowKey] ?? index;
}
</script>

<template>
    <!-- Toolbar: resumo do relatório + personalizar colunas -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <div class="fs-13">
            <slot name="summary" />
        </div>
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
                :columns="orderedMovable"
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
                            v-if="col.sortable"
                            :col-key="col.key"
                            :current-sort="sort"
                            :current-dir="direction"
                            :title="tx('sort_by', { column: col.label })"
                            :class="alignClass(col)"
                            @sort="emit('sort', $event)"
                            >{{ col.label }}</SortableTh
                        >
                        <th v-else :class="alignClass(col)">{{ col.label }}</th>
                    </template>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length" class="text-center text-muted py-5">
                        <i :class="emptyIcon" class="fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ emptyText }}
                    </td>
                </tr>
                <tr v-for="(row, index) in rows" :key="keyOf(row, index)">
                    <td v-for="col in orderedColumns" :key="col.key" :class="[alignClass(col), col.cellClass]">
                        <slot :name="`cell-${col.key}`" :row="row">{{ row[col.key] ?? '—' }}</slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
