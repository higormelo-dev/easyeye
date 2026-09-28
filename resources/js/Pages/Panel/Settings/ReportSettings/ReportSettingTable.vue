<script setup>
import { computed } from 'vue';
import ActionDropdown  from '@/Components/Panel/ActionDropdown.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import SortableTh      from '@/Components/Panel/SortableTh.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useColumnOrder }         from '@/composables/useColumnOrder.js';
import ReportSettingActions       from './ReportSettingActions.vue';
import { useReportSettingFormat } from './useReportSettingFormat.js';

/**
 * Tabela de modelos de documento no padrão de Patients/PatientTable:
 * cabeçalhos ordenáveis (whitelist de Setting\ReportSettingsController::
 * SORTABLE — Blocos/Origem não ordenam), menu "Colunas" (ordem persistida
 * no navegador), Status/Ações fixos no fim.
 */
const props = defineProps({
    items:     { type: Object, required: true },   // paginator Laravel
    filters:   { type: Object, default: () => ({}) },
    t:         { type: Object, default: () => ({}) },
    emptyText: { type: String, default: '' },
});

const emit = defineEmits(['sort', 'reimport', 'delete']);

const { tx, date, blocks } = useReportSettingFormat(() => props.t);

const rows = computed(() => props.items?.data ?? []);

// ── Ordenação (padrão = título A→Z, igual ao backend) ───────────────────────
const currentSort = computed(() => props.filters.sort ?? 'title');
const currentDir  = computed(() => props.filters.direction ?? 'asc');

// ── Ordem de colunas personalizável ─────────────────────────────────────────
// sortKey = chave aceita pelo backend; null = coluna sem ordenação.
const COLUMN_DEFS = computed(() => [
    { key: 'modelo',     label: props.t.col_title ?? 'Modelo',             sortKey: 'title' },
    { key: 'categoria',  label: props.t.col_category ?? 'Categoria',       sortKey: 'category' },
    { key: 'papel',      label: props.t.col_paper ?? 'Papel',              sortKey: 'paper_size' },
    { key: 'blocos',     label: props.t.col_blocks ?? 'Blocos',            sortKey: null },
    { key: 'origem',     label: props.t.col_origin ?? 'Origem',            sortKey: null },
    { key: 'atualizado', label: props.t.col_updated_at ?? 'Atualizado em', sortKey: 'updated_at' },
]);
const DEFAULT_COLUMN_ORDER = ['modelo', 'categoria', 'papel', 'blocos', 'origem', 'atualizado'];

const { order: columnOrder, moveTo: moveColumn, reset: resetColumnOrder } = useColumnOrder(
    'report_settings_columns_order',
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
                        >{{ col.label }}</SortableTh>
                        <th v-else>{{ col.label }}</th>
                    </template>
                    <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length + 2" class="text-center text-muted py-5">
                        <i class="ti ti-file-text fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ emptyText }}
                    </td>
                </tr>
                <tr v-for="item in rows" :key="item.id">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'modelo'" class="rs-title-cell">
                            <div class="fw-medium text-truncate">{{ item.title }}</div>
                            <div
                                class="small text-truncate"
                                :class="item.description ? 'text-muted' : 'text-body-secondary fst-italic'"
                            >{{ item.description || (t.no_description ?? 'Sem descrição') }}</div>
                        </td>

                        <td v-else-if="col.key === 'categoria'" class="small">{{ item.category || '—' }}</td>

                        <td v-else-if="col.key === 'papel'"><code class="small">{{ item.paper_size }}</code></td>

                        <td v-else-if="col.key === 'blocos'">
                            <span class="d-inline-flex gap-2">
                                <span
                                    v-for="block in blocks(item)"
                                    :key="block.key"
                                    :class="block.on ? 'text-success' : 'text-body-tertiary'"
                                    :title="block.text"
                                >
                                    <i :class="block.icon" aria-hidden="true"></i>
                                    <span class="visually-hidden">{{ block.text }}</span>
                                </span>
                            </span>
                        </td>

                        <td v-else-if="col.key === 'origem'">
                            <span v-if="item.is_adopted" class="badge badge-soft-info rounded fs-11">
                                <i class="ti ti-cloud-download me-1" aria-hidden="true"></i>{{ t.origin_adopted ?? 'Adotado' }}
                            </span>
                            <span v-else class="badge badge-soft-secondary rounded fs-11">{{ t.origin_own ?? 'Próprio' }}</span>
                            <span v-if="item.has_update" class="badge badge-soft-warning rounded fs-11 ms-1">
                                <i class="ti ti-arrow-up-circle me-1" aria-hidden="true"></i>{{ t.update_available ?? 'Atualização disponível' }}
                            </span>
                        </td>

                        <td v-else-if="col.key === 'atualizado'" class="text-muted small">{{ date(item.updated_at) }}</td>
                    </template>

                    <td class="text-center">
                        <span
                            v-if="item.active"
                            class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                        >{{ t.status_active ?? 'Ativo' }}</span>
                        <span
                            v-else
                            class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                        >{{ t.status_inactive ?? 'Inativo' }}</span>
                    </td>

                    <td class="text-end">
                        <ReportSettingActions
                            :item="item"
                            :t="t"
                            @reimport="emit('reimport', $event)"
                            @delete="emit('delete', $event)"
                        />
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
/* Título + descrição truncados: descrição longa não empurra as colunas. */
.rs-title-cell {
    max-width: 340px;
}
</style>
