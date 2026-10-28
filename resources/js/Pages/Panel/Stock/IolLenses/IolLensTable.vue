<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useColumnOrder } from '@/composables/useColumnOrder.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Tabela de lentes IOL (catarata) no padrão de Patients/PatientTable:
 * cabeçalhos ordenáveis (whitelist de IolLensesController::SORTABLE), menu
 * "Colunas" (ordem persistida no navegador), Status/Ações fixos no fim,
 * foto da lente ao lado do modelo e valores no idioma do usuário.
 *
 * Ações: atalho para as movimentações do produto de estoque vinculado +
 * menu editar/ativar/desativar/excluir (rota do módulo: permission:
 * stock.manage + feature).
 */
const props = defineProps({
    items: { type: Object, required: true }, // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
    movementsIndexUrl: { type: String, default: '' },
});

const emit = defineEmits(['sort', 'edit', 'toggleActive', 'delete']);

const { tx } = useTrans(() => props.t);
const { money, number, quantity } = useLocaleFormat();

const rows = computed(() => props.items?.data ?? []);

// ── Ordenação (padrão = fabricante A→Z, igual ao backend) ──────────────────
const currentSort = computed(() => props.filters.sort ?? 'manufacturer');
const currentDir = computed(() => props.filters.direction ?? 'asc');

// ── Ordem de colunas personalizável ─────────────────────────────────────────
// sortKey = chave aceita por IolLensesController::SORTABLE.
const COLUMN_DEFS = computed(() => [
    { key: 'modelo', label: props.t.col_model ?? 'Modelo', sortKey: 'model_name' },
    { key: 'fabricante', label: props.t.col_manufacturer ?? 'Fabricante', sortKey: 'manufacturer' },
    { key: 'tipo', label: props.t.col_category ?? 'Tipo', sortKey: 'category' },
    { key: 'dioptrias', label: props.t.col_diopters ?? 'Dioptrias', sortKey: 'diopter_min' },
    { key: 'valor', label: props.t.col_price ?? 'Valor', sortKey: 'price', numeric: true },
    { key: 'estoque', label: props.t.col_stock ?? 'Estoque', sortKey: 'qty_on_hand', numeric: true },
]);
const DEFAULT_COLUMN_ORDER = ['modelo', 'fabricante', 'tipo', 'dioptrias', 'valor', 'estoque'];

const {
    order: columnOrder,
    moveTo: moveColumn,
    reset: resetColumnOrder,
} = useColumnOrder('stock_iollenses_columns_order', DEFAULT_COLUMN_ORDER);

const orderedColumns = computed(() =>
    columnOrder.value.map((key) => COLUMN_DEFS.value.find((c) => c.key === key)).filter(Boolean),
);

const columnMenuLabels = computed(() => ({
    moveUp: props.t.columns_move_up,
    moveDown: props.t.columns_move_down,
    reset: props.t.columns_reset,
}));

// ── Formatação ──────────────────────────────────────────────────────────────
function isBlank(value) {
    return value === null || value === undefined || value === '' || Number.isNaN(Number(value));
}

/** Dioptria com sinal explícito (+/−), informação clínica relevante. */
function diopter(value) {
    const n = Number(value);

    return `${n > 0 ? '+' : ''}${number(n, 1)}`;
}

function diopterRange(lens) {
    if (isBlank(lens.diopter_min) || isBlank(lens.diopter_max)) return '—';

    return tx('diopter_range', { min: diopter(lens.diopter_min), max: diopter(lens.diopter_max) });
}

function stockLabel(lens) {
    if (!lens.stock) return '—';

    return [quantity(lens.stock.qty_on_hand), lens.stock.unit_label].filter(Boolean).join(' ');
}

function movementsUrl(lens) {
    const productId = lens.stock?.id ?? lens.entity_product_id;
    if (!props.movementsIndexUrl || !productId) return null;

    return `${props.movementsIndexUrl}?${new URLSearchParams({ entity_product_id: productId })}`;
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
                    <SortableTh
                        v-for="col in orderedColumns"
                        :key="col.key"
                        :class="{ 'text-end': col.numeric }"
                        :col-key="col.sortKey"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        :title="tx('sort_by', { column: col.label })"
                        @sort="emit('sort', $event)"
                        >{{ col.label }}</SortableTh
                    >
                    <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length + 2" class="text-center text-muted py-5">
                        <i class="ti ti-eye-off fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty_list ?? 'Nenhuma lente encontrada.' }}
                    </td>
                </tr>
                <tr v-for="lens in rows" :key="lens.id">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'modelo'">
                            <div class="d-flex align-items-center gap-2">
                                <img
                                    v-if="lens.image_url"
                                    :src="lens.image_url"
                                    :alt="lens.model_name ?? ''"
                                    class="rounded flex-shrink-0"
                                    width="30"
                                    height="30"
                                    loading="lazy"
                                    style="object-fit: cover"
                                />
                                <span
                                    v-else
                                    class="rounded bg-body-tertiary border d-inline-flex align-items-center justify-content-center flex-shrink-0 text-body-secondary"
                                    style="width: 30px; height: 30px"
                                    aria-hidden="true"
                                    ><i class="ti ti-eye"></i
                                ></span>
                                <span class="fw-medium">{{ lens.model_name }}</span>
                            </div>
                        </td>

                        <td v-else-if="col.key === 'fabricante'" class="small">{{ lens.manufacturer ?? '—' }}</td>

                        <td v-else-if="col.key === 'tipo'" class="text-muted small">{{ lens.category || '—' }}</td>

                        <td v-else-if="col.key === 'dioptrias'" class="small">{{ diopterRange(lens) }}</td>

                        <td v-else-if="col.key === 'valor'" class="text-end">
                            {{ isBlank(lens.price) ? (t.not_informed ?? 'Não informado') : money(lens.price) }}
                        </td>

                        <td v-else-if="col.key === 'estoque'" class="text-end">{{ stockLabel(lens) }}</td>
                    </template>

                    <td class="text-center">
                        <span
                            v-if="lens.active"
                            class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                            >{{ t.status_active ?? 'Ativa' }}</span
                        >
                        <span
                            v-else
                            class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                            >{{ t.status_inactive ?? 'Inativa' }}</span
                        >
                    </td>

                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton
                                v-if="movementsUrl(lens)"
                                icon="ti ti-transfer-in"
                                :title="t.action_movements ?? 'Movimentações da lente'"
                                variant="info"
                                :inertia-href="movementsUrl(lens)"
                            />
                            <ActionDropdown
                                :title="t.more_actions ?? 'Mais ações'"
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                            >
                                <li>
                                    <button type="button" class="dropdown-item rounded-1" @click="emit('edit', lens)">
                                        <i class="ti ti-edit me-1" aria-hidden="true"></i>
                                        {{ t.action_edit ?? 'Editar' }}
                                    </button>
                                </li>
                                <li>
                                    <button
                                        type="button"
                                        class="dropdown-item rounded-1"
                                        @click="emit('toggleActive', lens)"
                                    >
                                        <i
                                            :class="`ti me-1 ${lens.active ? 'ti-lock-open' : 'ti-lock'}`"
                                            aria-hidden="true"
                                        ></i>
                                        {{
                                            lens.active
                                                ? (t.action_deactivate ?? 'Desativar')
                                                : (t.action_activate ?? 'Ativar')
                                        }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider" /></li>
                                <li>
                                    <button
                                        type="button"
                                        class="dropdown-item rounded-1 text-danger"
                                        @click="emit('delete', lens)"
                                    >
                                        <i class="ti ti-trash me-1" aria-hidden="true"></i>
                                        {{ t.action_delete ?? 'Excluir' }}
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
