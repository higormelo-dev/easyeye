<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useColumnOrder } from '@/composables/useColumnOrder.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Tabela de médicos no mesmo padrão de Patients/PatientTable: cabeçalhos
 * ordenáveis, menu "Colunas" (ordem persistida no navegador), Telefone com
 * WhatsApp, status e ações por `mode` (ActionPolicy).
 */
const props = defineProps({
    doctors: { type: Object, required: true }, // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'view', 'edit', 'delete', 'toggleActive']);

const { tx } = useTrans(() => props.t);

// ── Ordenação ────────────────────────────────────────────────────────────────
const currentSort = computed(() => props.filters.sort ?? 'created_at');
const currentDir = computed(() => props.filters.direction ?? 'desc');

function sort(sortKey) {
    const direction = currentSort.value === sortKey && currentDir.value === 'asc' ? 'desc' : 'asc';
    emit('sort', { sort: sortKey, direction });
}

function sortIcon(sortKey) {
    if (currentSort.value !== sortKey) return 'ti ti-arrows-sort text-muted';
    return currentDir.value === 'asc' ? 'ti ti-sort-ascending' : 'ti ti-sort-descending';
}

function ariaSort(col) {
    if (!col.sortKey) return undefined;
    if (currentSort.value !== col.sortKey) return 'none';
    return currentDir.value === 'asc' ? 'ascending' : 'descending';
}

// ── Ordem de colunas personalizável ──────────────────────────────────────────
// Status/Ações ficam fixas no fim (mesma convenção da tabela de pacientes).
// sortKey = chave aceita pela whitelist de DoctorsController::SORTABLE.
const COLUMN_DEFS = computed(() => [
    { key: 'nome', label: props.t.col_name ?? 'Nome', sortKey: 'full_name' },
    { key: 'telefone', label: props.t.col_phone ?? 'Telefone', sortKey: 'cellphone' },
    { key: 'crm', label: props.t.col_record ?? 'CRM', sortKey: 'record' },
    { key: 'email', label: props.t.col_email ?? 'E-mail', sortKey: 'email' },
    { key: 'cadastro', label: props.t.col_created_at ?? 'Cadastro', sortKey: 'created_at' },
    { key: 'codigo', label: props.t.col_code ?? 'Código', sortKey: 'code' },
]);
const DEFAULT_COLUMN_ORDER = ['nome', 'telefone', 'crm', 'email', 'cadastro', 'codigo'];

const {
    order: columnOrder,
    moveTo: moveColumn,
    reset: resetColumnOrder,
} = useColumnOrder('doc_table_columns_order', DEFAULT_COLUMN_ORDER);

const orderedColumns = computed(() =>
    columnOrder.value.map((key) => COLUMN_DEFS.value.find((c) => c.key === key)).filter(Boolean),
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
                            type="button"
                            class="doctor-sort-btn"
                            :title="tx('sort_by', { column: col.label })"
                            @click="sort(col.sortKey)"
                        >
                            {{ col.label }}
                            <i :class="sortIcon(col.sortKey)" class="ms-1 fs-11" aria-hidden="true"></i>
                        </button>
                    </th>
                    <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="doctors.data.length === 0">
                    <td :colspan="orderedColumns.length + 2" class="text-center text-muted py-5">
                        <i class="ti ti-stethoscope fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty_list ?? 'Nenhum médico encontrado.' }}
                    </td>
                </tr>
                <tr v-for="d in doctors.data" :key="d.id" :class="{ 'table-secondary opacity-75': d.deleted }">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'nome'">
                            <div class="d-flex align-items-center gap-2">
                                <span
                                    v-if="d.color"
                                    class="rounded-circle d-inline-block border flex-shrink-0"
                                    :style="{ background: d.color, width: '12px', height: '12px' }"
                                    aria-hidden="true"
                                ></span>
                                <img
                                    :src="d.photo_url"
                                    :alt="d.full_name"
                                    class="rounded-circle"
                                    style="width: 30px; height: 30px; object-fit: cover"
                                />
                                <div>
                                    <div class="fw-medium">{{ d.full_name }}</div>
                                    <div v-if="d.record_specialty" class="text-muted small">
                                        {{ d.record_specialty }}
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td v-else-if="col.key === 'telefone'" class="small">
                            <i
                                v-if="d.whatsapp"
                                class="fab fa-whatsapp text-success me-1"
                                role="img"
                                :title="t.whatsapp ?? 'WhatsApp'"
                                :aria-label="t.whatsapp ?? 'WhatsApp'"
                            ></i>
                            {{ d.cellphone ?? '—' }}
                        </td>

                        <td v-else-if="col.key === 'crm'" class="small">{{ d.record ?? '—' }}</td>

                        <td v-else-if="col.key === 'email'" class="text-muted small">{{ d.email }}</td>

                        <td v-else-if="col.key === 'cadastro'" class="text-muted small">{{ d.created_at }}</td>

                        <td v-else-if="col.key === 'codigo'">
                            <code class="text-muted small">{{ d.code }}</code>
                        </td>
                    </template>

                    <td class="text-center">
                        <span
                            v-if="d.active"
                            class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                            >{{ t.status_active ?? 'Ativo' }}</span
                        >
                        <span
                            v-else
                            class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                            >{{ t.status_inactive ?? 'Inativo' }}</span
                        >
                    </td>

                    <!-- Ações por mode (ActionPolicy), como em pacientes -->
                    <td class="text-end">
                        <ActionIconGroup v-if="d.mode === 'view_only'" align="end">
                            <ActionIconButton
                                icon="ti ti-eye"
                                :title="t.action_view ?? 'Visualizar'"
                                @click="emit('view', d.id)"
                            />
                        </ActionIconGroup>

                        <ActionIconGroup v-else-if="d.mode === 'full'" align="end" gap="tight">
                            <ActionIconButton
                                icon="ti ti-eye"
                                :title="t.action_view ?? 'Visualizar'"
                                @click="emit('view', d.id)"
                            />
                            <ActionIconButton
                                icon="ti ti-calendar-time"
                                :title="t.action_work_schedule ?? 'Horários de atendimento'"
                                variant="info"
                                :href="d.work_schedule_url"
                            />
                            <ActionDropdown
                                :title="t.more_actions ?? 'Mais ações'"
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                            >
                                <li>
                                    <button class="dropdown-item rounded-1" @click="emit('edit', d.id)">
                                        <i class="ti ti-edit me-1"></i> {{ t.action_edit ?? 'Editar' }}
                                    </button>
                                </li>
                                <li>
                                    <button
                                        class="dropdown-item rounded-1"
                                        @click="emit('toggleActive', d.id, d.active)"
                                    >
                                        <i :class="`ti me-1 ${d.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                        {{
                                            d.active
                                                ? (t.action_deactivate ?? 'Desativar')
                                                : (t.action_activate ?? 'Ativar')
                                        }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider" /></li>
                                <li>
                                    <button class="dropdown-item rounded-1 text-danger" @click="emit('delete', d.id)">
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
        :data="doctors"
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
.doctor-sort-btn {
    background: none;
    border: 0;
    padding: 0;
    color: inherit;
    font: inherit;
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
}
.doctor-sort-btn:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
    border-radius: 2px;
}
</style>
