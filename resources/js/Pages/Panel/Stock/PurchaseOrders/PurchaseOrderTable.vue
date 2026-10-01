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
 * Tabela de pedidos de compra no padrão de Patients/PatientTable: cabeçalhos
 * ordenáveis (só as chaves de PurchaseOrdersController::SORTABLE), menu
 * "Colunas" (ordem no navegador), Status/Ações fixas no fim.
 *
 * Ações seguem EXATAMENTE a máquina de estados do pedido
 * (App\Enums\PurchaseOrderStatus) — mesma regra da tela anterior:
 *   PDF: sempre · Editar/Enviar/Excluir: rascunho · Receber: enviado ou
 *   recebido parcialmente · Cancelar: qualquer status não terminal.
 */
const props = defineProps({
    items: { type: Object, required: true }, // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
    pdfUrlTemplate: { type: String, default: '' }, // rota com __ID__
});

const emit = defineEmits(['sort', 'edit', 'send', 'receive', 'cancel', 'delete']);

const { tx } = useTrans(() => props.t);
const { money, date } = useLocaleFormat();

const rows = computed(() => props.items?.data ?? []);

// ── Ordenação ────────────────────────────────────────────────────────────────
const currentSort = computed(() => props.filters.sort ?? 'order_date');
const currentDir = computed(() => props.filters.direction ?? 'desc');

// ── Ordem de colunas personalizável ──────────────────────────────────────────
const COLUMN_DEFS = computed(() => [
    { key: 'codigo', label: props.t.col_code ?? 'Código', sortKey: 'code' },
    { key: 'fornecedor', label: props.t.col_supplier ?? 'Fornecedor', sortKey: 'supplier_name' },
    { key: 'data', label: props.t.col_order_date ?? 'Data', sortKey: 'order_date' },
    { key: 'previsao', label: props.t.col_expected_delivery ?? 'Previsão de entrega', sortKey: null },
    { key: 'total', label: props.t.col_total ?? 'Total', sortKey: 'total_amount', end: true },
]);
const DEFAULT_COLUMN_ORDER = ['codigo', 'fornecedor', 'data', 'previsao', 'total'];

const {
    order: columnOrder,
    moveTo: moveColumn,
    reset: resetColumnOrder,
} = useColumnOrder('stock_purchase_orders_columns_order', DEFAULT_COLUMN_ORDER);

const orderedColumns = computed(() =>
    columnOrder.value.map((key) => COLUMN_DEFS.value.find((c) => c.key === key)).filter(Boolean),
);

const columnMenuLabels = computed(() => ({
    moveUp: props.t.columns_move_up,
    moveDown: props.t.columns_move_down,
    reset: props.t.columns_reset,
}));

// ── Status e ações por status ────────────────────────────────────────────────
const STATUS_BADGE = {
    draft: 'badge-soft-secondary text-secondary border border-secondary',
    sent: 'badge-soft-info text-info border border-info',
    partially_received: 'badge-soft-warning text-warning border border-warning',
    received: 'badge-soft-success text-success border border-success',
    cancelled: 'badge-soft-danger text-danger border border-danger',
};

/** Rótulo traduzido pelo backend (PurchaseOrderStatus::label()). */
function statusLabel(po) {
    return po.status_label ?? po.status;
}

const canEdit = (po) => Boolean(po.is_editable);
const canSend = (po) => po.status === 'draft';
const canReceive = (po) => ['sent', 'partially_received'].includes(po.status);
const canCancel = (po) => !['received', 'cancelled'].includes(po.status);
const canDelete = (po) => po.status === 'draft';
const hasMenu = (po) => canEdit(po) || canCancel(po) || canDelete(po);

function pdfUrl(po) {
    return props.pdfUrlTemplate ? props.pdfUrlTemplate.replace('__ID__', po.id) : null;
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
                            :class="{ 'text-end': col.end }"
                            :col-key="col.sortKey"
                            :current-sort="currentSort"
                            :current-dir="currentDir"
                            :title="tx('sort_by', { column: col.label })"
                            @sort="emit('sort', $event)"
                            >{{ col.label }}</SortableTh
                        >
                        <th v-else :class="{ 'text-end': col.end }">{{ col.label }}</th>
                    </template>
                    <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length + 2" class="text-center text-muted py-5">
                        <i class="ti ti-shopping-cart-off fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty_list ?? 'Nenhum pedido de compra encontrado.' }}
                    </td>
                </tr>
                <tr v-for="po in rows" :key="po.id">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'codigo'" data-col="codigo">
                            <code class="text-muted small">{{ po.code }}</code>
                        </td>

                        <td v-else-if="col.key === 'fornecedor'" data-col="fornecedor">
                            <span class="fw-medium">{{ po.supplier_name ?? '—' }}</span>
                        </td>

                        <td v-else-if="col.key === 'data'" data-col="data" class="small">{{ date(po.order_date) }}</td>

                        <td v-else-if="col.key === 'previsao'" data-col="previsao" class="text-muted small">
                            {{ date(po.expected_delivery_date) }}
                        </td>

                        <td v-else-if="col.key === 'total'" data-col="total" class="text-end">
                            {{ money(po.total_amount) }}
                        </td>
                    </template>

                    <td class="text-center">
                        <span
                            class="badge rounded fs-13 fw-medium"
                            :class="STATUS_BADGE[po.status] ?? 'badge-soft-secondary'"
                            >{{ statusLabel(po) }}</span
                        >
                    </td>

                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton
                                v-if="pdfUrl(po)"
                                icon="ti ti-file-download"
                                :title="t.action_pdf ?? 'Baixar PDF'"
                                :href="pdfUrl(po)"
                            />
                            <ActionIconButton
                                v-if="canSend(po)"
                                icon="ti ti-send"
                                :title="t.action_send ?? 'Enviar ao fornecedor'"
                                variant="info"
                                @click="emit('send', po)"
                            />
                            <ActionIconButton
                                v-if="canReceive(po)"
                                icon="ti ti-package-import"
                                :title="t.action_receive ?? 'Receber'"
                                variant="success"
                                @click="emit('receive', po)"
                            />
                            <ActionDropdown
                                v-if="hasMenu(po)"
                                :title="t.more_actions ?? 'Mais ações'"
                                btn-class="ee-action-icon ee-action-icon--default"
                            >
                                <li v-if="canEdit(po)">
                                    <button type="button" class="dropdown-item rounded-1" @click="emit('edit', po)">
                                        <i class="ti ti-edit me-1" aria-hidden="true"></i>
                                        {{ t.action_edit ?? 'Editar' }}
                                    </button>
                                </li>
                                <li v-if="canEdit(po) && (canCancel(po) || canDelete(po))">
                                    <hr class="dropdown-divider" />
                                </li>
                                <li v-if="canCancel(po)">
                                    <button
                                        type="button"
                                        class="dropdown-item rounded-1 text-danger"
                                        @click="emit('cancel', po)"
                                    >
                                        <i class="ti ti-x me-1" aria-hidden="true"></i>
                                        {{ t.action_cancel ?? 'Cancelar pedido' }}
                                    </button>
                                </li>
                                <li v-if="canDelete(po)">
                                    <button
                                        type="button"
                                        class="dropdown-item rounded-1 text-danger"
                                        @click="emit('delete', po)"
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
