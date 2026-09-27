<script setup>
import { computed } from 'vue';
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';
import ColumnOrderMenu  from '@/Components/Panel/ColumnOrderMenu.vue';
import SortableTh       from '@/Components/Panel/SortableTh.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import { useColumnOrder }  from '@/composables/useColumnOrder.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans }        from '@/composables/useTrans.js';

/**
 * Tabela de produtos de estoque no padrão de Patients/PatientTable:
 * cabeçalhos ordenáveis (whitelist de ProductsController::SORTABLE), menu
 * "Colunas" (ordem persistida no navegador), Status/Ações fixos no fim e
 * valores formatados no idioma do usuário (useLocaleFormat).
 *
 * Ações: atalho para as movimentações do produto + menu editar/ativar/
 * desativar/excluir — as mesmas que a rota do módulo (permission:
 * stock.manage + feature) já permite a quem vê esta tela.
 */
const props = defineProps({
    items:             { type: Object, required: true },   // paginator Laravel
    filters:           { type: Object, default: () => ({}) },
    t:                 { type: Object, default: () => ({}) },
    movementsIndexUrl: { type: String, default: '' },
});

const emit = defineEmits(['sort', 'edit', 'toggleActive', 'delete']);

const { tx } = useTrans(() => props.t);
const { money, quantity, date } = useLocaleFormat();

const rows = computed(() => props.items?.data ?? []);

// ── Ordenação (padrão = nome A→Z, igual ao backend) ─────────────────────────
const currentSort = computed(() => props.filters.sort ?? 'name');
const currentDir  = computed(() => props.filters.direction ?? 'asc');

// ── Ordem de colunas personalizável ─────────────────────────────────────────
// sortKey = chave aceita por ProductsController::SORTABLE (null = não ordena).
const COLUMN_DEFS = computed(() => [
    { key: 'codigo',    label: props.t.col_code ?? 'Código',             sortKey: 'code' },
    { key: 'nome',      label: props.t.col_name ?? 'Nome',               sortKey: 'name' },
    { key: 'categoria', label: props.t.col_category ?? 'Categoria',      sortKey: null },
    { key: 'unidade',   label: props.t.col_unit ?? 'Unidade',            sortKey: null },
    { key: 'saldo',     label: props.t.col_qty_on_hand ?? 'Saldo',       sortKey: 'qty_on_hand', numeric: true },
    { key: 'custo',     label: props.t.col_cost_avg ?? 'Custo médio',    sortKey: 'cost_avg', numeric: true },
    { key: 'preco',     label: props.t.col_sale_price ?? 'Preço',        sortKey: 'sale_price', numeric: true },
]);
// Mesma ordem que a tela sempre teve.
const DEFAULT_COLUMN_ORDER = ['codigo', 'nome', 'categoria', 'unidade', 'saldo', 'custo', 'preco'];

const { order: columnOrder, moveTo: moveColumn, reset: resetColumnOrder } = useColumnOrder(
    'stock_products_columns_order',
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

// ── Atalhos ─────────────────────────────────────────────────────────────────
function movementsUrl(product) {
    if (!props.movementsIndexUrl) return null;

    return `${props.movementsIndexUrl}?${new URLSearchParams({ entity_product_id: product.id })}`;
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
                            :class="{ 'text-end': col.numeric }"
                            :col-key="col.sortKey"
                            :current-sort="currentSort"
                            :current-dir="currentDir"
                            :title="tx('sort_by', { column: col.label })"
                            @sort="emit('sort', $event)"
                        >{{ col.label }}</SortableTh>
                        <th v-else :class="{ 'text-end': col.numeric }">{{ col.label }}</th>
                    </template>
                    <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length + 2" class="text-center text-muted py-5">
                        <i class="ti ti-package-off fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty_list ?? 'Nenhum produto encontrado.' }}
                    </td>
                </tr>
                <tr v-for="p in rows" :key="p.id">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'codigo'">
                            <code class="text-muted small">{{ p.code ?? '—' }}</code>
                        </td>

                        <td v-else-if="col.key === 'nome'">
                            <div class="d-flex align-items-center flex-wrap gap-1">
                                <span class="fw-medium">{{ p.name }}</span>
                                <span
                                    v-if="p.is_opm"
                                    class="badge badge-soft-info rounded fs-11"
                                    :title="t.badge_opm_title"
                                >{{ t.badge_opm ?? 'OPM' }}</span>
                                <span
                                    v-if="p.has_expiring_lot"
                                    class="badge badge-soft-warning text-warning rounded fs-11"
                                    :title="tx('expiring_lot_title', { date: date(p.nearest_expiry) })"
                                >
                                    <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                                    {{ t.badge_expiring_lot ?? 'Lote vencendo' }}
                                </span>
                            </div>
                        </td>

                        <td v-else-if="col.key === 'categoria'" class="small">{{ p.category_name ?? '—' }}</td>

                        <td v-else-if="col.key === 'unidade'" class="text-muted small">{{ p.unit_label ?? '—' }}</td>

                        <td v-else-if="col.key === 'saldo'" class="text-end">
                            <span :class="{ 'text-danger fw-semibold': p.below_minimum }">{{ quantity(p.qty_on_hand) }}</span>
                            <i
                                v-if="p.below_minimum"
                                class="ti ti-alert-triangle text-danger ms-1"
                                role="img"
                                :title="t.below_minimum ?? 'Abaixo do mínimo'"
                                :aria-label="t.below_minimum ?? 'Abaixo do mínimo'"
                            ></i>
                        </td>

                        <td v-else-if="col.key === 'custo'" class="text-end">{{ money(p.cost_avg) }}</td>

                        <td v-else-if="col.key === 'preco'" class="text-end">{{ money(p.sale_price) }}</td>
                    </template>

                    <td class="text-center">
                        <span
                            v-if="p.active"
                            class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                        >{{ t.status_active ?? 'Ativo' }}</span>
                        <span
                            v-else
                            class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                        >{{ t.status_inactive ?? 'Inativo' }}</span>
                    </td>

                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton
                                v-if="movementsUrl(p)"
                                icon="ti ti-transfer-in"
                                :title="t.action_movements ?? 'Movimentações do produto'"
                                variant="info"
                                :inertia-href="movementsUrl(p)"
                            />
                            <ActionDropdown
                                :title="t.more_actions ?? 'Mais ações'"
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                            >
                                <li>
                                    <button type="button" class="dropdown-item rounded-1" @click="emit('edit', p)">
                                        <i class="ti ti-edit me-1" aria-hidden="true"></i> {{ t.action_edit ?? 'Editar' }}
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item rounded-1" @click="emit('toggleActive', p)">
                                        <i :class="`ti me-1 ${p.active ? 'ti-lock-open' : 'ti-lock'}`" aria-hidden="true"></i>
                                        {{ p.active ? (t.action_deactivate ?? 'Desativar') : (t.action_activate ?? 'Ativar') }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button type="button" class="dropdown-item rounded-1 text-danger" @click="emit('delete', p)">
                                        <i class="ti ti-trash me-1" aria-hidden="true"></i> {{ t.action_delete ?? 'Excluir' }}
                                    </button>
                                </li>
                            </ActionDropdown>
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
