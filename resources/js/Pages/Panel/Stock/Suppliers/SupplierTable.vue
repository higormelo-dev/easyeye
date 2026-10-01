<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useColumnOrder } from '@/composables/useColumnOrder.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Tabela de fornecedores no padrão de Patients/PatientTable: cabeçalhos
 * ordenáveis (só as chaves de SuppliersController::SORTABLE), menu "Colunas"
 * (ordem persistida no navegador), Status/Ações fixas no fim.
 *
 * Documento/telefone saem como o backend mandou (document_display/
 * phone_display, via BrazilianFormat) — nunca re-mascarados aqui, para não
 * mutilar valor legado em texto livre.
 */
const props = defineProps({
    items: { type: Object, required: true }, // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
    purchaseOrdersUrl: { type: String, default: '' },
});

const emit = defineEmits(['sort', 'edit', 'delete']);

const { tx } = useTrans(() => props.t);

const rows = computed(() => props.items?.data ?? []);

// ── Ordenação ────────────────────────────────────────────────────────────────
const currentSort = computed(() => props.filters.sort ?? 'name');
const currentDir = computed(() => props.filters.direction ?? 'asc');

// ── Ordem de colunas personalizável ──────────────────────────────────────────
// sortKey = chave aceita pela whitelist do backend; null = não ordenável.
const COLUMN_DEFS = computed(() => [
    { key: 'nome', label: props.t.col_name ?? 'Nome', sortKey: 'name' },
    { key: 'telefone', label: props.t.col_phone ?? 'Telefone', sortKey: 'phone' },
    { key: 'documento', label: props.t.col_document ?? 'Documento', sortKey: 'document' },
    { key: 'contato', label: props.t.col_contact ?? 'Contato', sortKey: 'contact_name' },
    { key: 'email', label: props.t.col_email ?? 'E-mail', sortKey: 'email' },
    { key: 'codigo', label: props.t.col_code ?? 'Código', sortKey: 'code' },
]);
const DEFAULT_COLUMN_ORDER = ['nome', 'telefone', 'documento', 'contato', 'email', 'codigo'];

const {
    order: columnOrder,
    moveTo: moveColumn,
    reset: resetColumnOrder,
} = useColumnOrder('stock_suppliers_columns_order', DEFAULT_COLUMN_ORDER);

const orderedColumns = computed(() =>
    columnOrder.value.map((key) => COLUMN_DEFS.value.find((c) => c.key === key)).filter(Boolean),
);

const columnMenuLabels = computed(() => ({
    moveUp: props.t.columns_move_up,
    moveDown: props.t.columns_move_down,
    reset: props.t.columns_reset,
}));

/** Atalho para os pedidos de compra já filtrados por este fornecedor. */
function purchaseOrdersHref(supplier) {
    if (!props.purchaseOrdersUrl) return null;

    return `${props.purchaseOrdersUrl}?supplier_id=${encodeURIComponent(supplier.id)}`;
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
                            @sort="emit('sort', $event)"
                            >{{ col.label }}</SortableTh
                        >
                        <th v-else>{{ col.label }}</th>
                    </template>
                    <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length + 2" class="text-center text-muted py-5">
                        <i class="ti ti-truck-off fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty_list ?? 'Nenhum fornecedor encontrado.' }}
                    </td>
                </tr>
                <tr v-for="s in rows" :key="s.id">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'nome'" data-col="nome">
                            <span class="fw-medium">{{ s.name }}</span>
                        </td>

                        <td v-else-if="col.key === 'telefone'" data-col="telefone" class="small">
                            {{ s.phone_display ?? '—' }}
                        </td>

                        <td v-else-if="col.key === 'documento'" data-col="documento" class="small">
                            {{ s.document_display ?? '—' }}
                        </td>

                        <td v-else-if="col.key === 'contato'" data-col="contato" class="small">
                            {{ s.contact_name ?? '—' }}
                        </td>

                        <td v-else-if="col.key === 'email'" data-col="email" class="text-muted small">
                            {{ s.email ?? '—' }}
                        </td>

                        <td v-else-if="col.key === 'codigo'" data-col="codigo">
                            <code class="text-muted small">{{ s.code }}</code>
                        </td>
                    </template>

                    <td class="text-center">
                        <span
                            v-if="s.active"
                            class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                            >{{ t.status_active ?? 'Ativo' }}</span
                        >
                        <span
                            v-else
                            class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                            >{{ t.status_inactive ?? 'Inativo' }}</span
                        >
                    </td>

                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton
                                v-if="purchaseOrdersHref(s)"
                                icon="ti ti-shopping-cart"
                                :title="t.action_purchase_orders ?? 'Pedidos de compra deste fornecedor'"
                                variant="info"
                                :inertia-href="purchaseOrdersHref(s)"
                            />
                            <ActionDropdown
                                :title="t.more_actions ?? 'Mais ações'"
                                btn-class="ee-action-icon ee-action-icon--default"
                            >
                                <li>
                                    <button type="button" class="dropdown-item rounded-1" @click="emit('edit', s)">
                                        <i class="ti ti-edit me-1" aria-hidden="true"></i>
                                        {{ t.action_edit ?? 'Editar' }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider" /></li>
                                <li>
                                    <button
                                        type="button"
                                        class="dropdown-item rounded-1 text-danger"
                                        @click="emit('delete', s)"
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
