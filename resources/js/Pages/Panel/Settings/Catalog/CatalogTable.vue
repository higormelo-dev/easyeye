<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useColumnOrder } from '@/composables/useColumnOrder.js';
import { useTrans } from '@/composables/useTrans.js';
import CatalogCell from './CatalogCell.vue';

/**
 * Tabela do catálogo no mesmo padrão de Patients/PatientTable: cabeçalhos
 * ordenáveis com ícone, menu "Colunas" (ordem persistida por catálogo no
 * navegador), status e ações por `mode` (ActionPolicy) — registro padrão do
 * sistema (global) só visualiza; editar/excluir ficam para os da clínica.
 */
const props = defineProps({
    items: { type: Object, required: true }, // paginator Laravel
    columns: { type: Array, required: true },
    sortable: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
    storageKey: { type: String, default: 'catalog_view' },
});

const emit = defineEmits(['sort', 'view', 'edit', 'toggleActive', 'delete', 'restore']);

const { tx } = useTrans(() => props.t);

const rows = computed(() => props.items?.data ?? []);

// ── Ordenação ────────────────────────────────────────────────────────────────
const currentSort = computed(() => props.filters.sort ?? 'name');
const currentDir = computed(() => props.filters.dir ?? 'asc');

function isSortable(col) {
    return props.sortable.includes(col.key);
}

function sort(col) {
    const dir = currentSort.value === col.key && currentDir.value === 'asc' ? 'desc' : 'asc';
    emit('sort', { sort: col.key, dir });
}

function sortIcon(col) {
    if (currentSort.value !== col.key) return 'ti ti-arrows-sort text-muted';
    return currentDir.value === 'asc' ? 'ti ti-sort-ascending' : 'ti ti-sort-descending';
}

function ariaSort(col) {
    if (!isSortable(col)) return undefined;
    if (currentSort.value !== col.key) return 'none';
    return currentDir.value === 'asc' ? 'ascending' : 'descending';
}

// ── Ordem de colunas personalizável (por catálogo) ───────────────────────────
// Status/Ações ficam fixas no fim, como na tabela de pacientes.
const {
    order: columnOrder,
    moveTo: moveColumn,
    reset: resetColumnOrder,
} = useColumnOrder(
    `${props.storageKey.replace(/_view$/, '')}_columns_order`,
    props.columns.map((c) => c.key),
);

const orderedColumns = computed(() =>
    columnOrder.value.map((key) => props.columns.find((c) => c.key === key)).filter(Boolean),
);

const columnMenuLabels = computed(() => ({
    moveUp: props.t.columns_move_up,
    moveDown: props.t.columns_move_down,
    reset: props.t.columns_reset,
}));
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
                    <th v-for="col in orderedColumns" :key="col.key" :aria-sort="ariaSort(col)">
                        <button
                            v-if="isSortable(col)"
                            type="button"
                            class="catalog-sort-btn"
                            :title="tx('sort_by', { column: col.label })"
                            @click="sort(col)"
                        >
                            {{ col.label }}
                            <i :class="sortIcon(col)" class="ms-1 fs-11" aria-hidden="true"></i>
                        </button>
                        <template v-else>{{ col.label }}</template>
                    </th>
                    <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length + 2" class="text-center text-muted py-5">
                        <i class="ti ti-folder-off fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty_list ?? 'Nenhum registro.' }}
                    </td>
                </tr>
                <tr v-for="item in rows" :key="item.id" :class="{ 'table-secondary opacity-75': item.deleted }">
                    <td v-for="col in orderedColumns" :key="col.key">
                        <CatalogCell :item="item" :col="col" :t="t" />
                    </td>

                    <!-- Status -->
                    <td class="text-center">
                        <span v-if="item.deleted" class="badge badge-soft-secondary rounded fs-13 fw-medium">
                            {{ t.status_deleted ?? 'Removido' }}
                        </span>
                        <span
                            v-else-if="item.active"
                            class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                            >{{ t.status_active ?? 'Ativo' }}</span
                        >
                        <span
                            v-else
                            class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                        >
                            {{ t.status_inactive ?? 'Inativo' }}
                        </span>
                        <span
                            v-if="item.is_global"
                            class="badge badge-soft-info rounded ms-1 fs-11"
                            :title="t.status_global"
                            :aria-label="t.status_global"
                        >
                            <i class="ti ti-star" aria-hidden="true"></i>
                        </span>
                    </td>

                    <!-- Ações por mode (ActionPolicy) -->
                    <td class="text-end">
                        <ActionIconGroup v-if="item.mode === 'restore'" align="end">
                            <ActionIconButton
                                icon="ti ti-recycle"
                                :title="t.action_restore ?? 'Restaurar'"
                                @click="emit('restore', item)"
                            />
                        </ActionIconGroup>

                        <ActionIconGroup v-else-if="item.mode === 'view_only'" align="end">
                            <ActionIconButton
                                icon="ti ti-eye"
                                :title="t.action_view ?? 'Ver'"
                                @click="emit('view', item)"
                            />
                        </ActionIconGroup>

                        <ActionIconGroup v-else-if="item.mode === 'full'" align="end" gap="tight">
                            <ActionIconButton
                                icon="ti ti-eye"
                                :title="t.action_view ?? 'Ver'"
                                @click="emit('view', item)"
                            />
                            <ActionDropdown
                                :title="t.more_actions ?? 'Mais ações'"
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                            >
                                <li>
                                    <button class="dropdown-item rounded-1" @click="emit('edit', item)">
                                        <i class="ti ti-edit me-1"></i> {{ t.action_edit ?? 'Editar' }}
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item rounded-1" @click="emit('toggleActive', item)">
                                        <i :class="`ti me-1 ${item.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                        {{
                                            item.active
                                                ? (t.action_deactivate ?? 'Desativar')
                                                : (t.action_activate ?? 'Ativar')
                                        }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider" /></li>
                                <li>
                                    <button class="dropdown-item rounded-1 text-danger" @click="emit('delete', item)">
                                        <i class="ti ti-trash me-1"></i> {{ t.action_delete ?? 'Excluir' }}
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

<style scoped>
/* Cabeçalho ordenável: botão (acessível por teclado) com a aparência do <th>. */
.catalog-sort-btn {
    background: none;
    border: 0;
    padding: 0;
    color: inherit;
    font: inherit;
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
}
.catalog-sort-btn:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
    border-radius: 2px;
}
</style>
