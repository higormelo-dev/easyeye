<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useColumnOrder } from '@/composables/useColumnOrder.js';
import { useRoleFormat } from './useRoleFormat.js';

/**
 * Tabela de perfis customizados no padrão de Patients/PatientTable:
 * cabeçalhos ordenáveis (whitelist de RolesController::SORTABLE), menu
 * "Colunas" (ordem persistida no navegador), Ações fixas no fim.
 */
const props = defineProps({
    roles: { type: Object, required: true }, // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
    emptyText: { type: String, default: '' },
});

const emit = defineEmits(['sort', 'edit', 'delete']);

const { tx, date, permissionsLabel, usersLabel, permissionGroups } = useRoleFormat(() => props.t);

const rows = computed(() => props.roles?.data ?? []);

// ── Ordenação (padrão = nome A→Z, igual ao backend) ─────────────────────────
const currentSort = computed(() => props.filters.sort ?? 'name');
const currentDir = computed(() => props.filters.direction ?? 'asc');

// ── Ordem de colunas personalizável ─────────────────────────────────────────
// sortKey = chave aceita por RolesController::SORTABLE.
const COLUMN_DEFS = computed(() => [
    { key: 'nome', label: props.t.col_name ?? 'Perfil', sortKey: 'name' },
    { key: 'permissoes', label: props.t.col_permissions ?? 'Permissões', sortKey: 'permissions_count' },
    { key: 'usuarios', label: props.t.col_users ?? 'Usuários', sortKey: 'users_count' },
    { key: 'cadastro', label: props.t.col_created_at ?? 'Cadastro', sortKey: 'created_at' },
]);
const DEFAULT_COLUMN_ORDER = ['nome', 'permissoes', 'usuarios', 'cadastro'];

const {
    order: columnOrder,
    moveTo: moveColumn,
    reset: resetColumnOrder,
} = useColumnOrder('access_roles_columns_order', DEFAULT_COLUMN_ORDER);

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
                    <SortableTh
                        v-for="col in orderedColumns"
                        :key="col.key"
                        :col-key="col.sortKey"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        :title="tx('sort_by', { column: col.label })"
                        @sort="emit('sort', $event)"
                        >{{ col.label }}</SortableTh
                    >
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length + 1" class="text-center text-muted py-5">
                        <i class="ti ti-shield-off fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ emptyText }}
                    </td>
                </tr>
                <tr v-for="role in rows" :key="role.id">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'nome'" class="role-name-cell">
                            <div class="d-flex align-items-center gap-2">
                                <span class="role-avatar flex-shrink-0" aria-hidden="true">
                                    <i class="ti ti-shield-lock"></i>
                                </span>
                                <div class="min-w-0">
                                    <div class="fw-medium text-truncate">{{ role.name }}</div>
                                    <div
                                        class="small text-truncate"
                                        :class="role.description ? 'text-muted' : 'text-body-secondary fst-italic'"
                                    >
                                        {{ role.description || (t.no_description ?? 'Sem descrição') }}
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td v-else-if="col.key === 'permissoes'">
                            <span class="badge badge-soft-info rounded fs-12 fw-medium">
                                <i class="ti ti-key me-1" aria-hidden="true"></i>{{ permissionsLabel(role) }}
                            </span>
                            <div v-if="permissionGroups(role)" class="text-muted small mt-1">
                                {{ permissionGroups(role) }}
                            </div>
                        </td>

                        <td v-else-if="col.key === 'usuarios'" class="small">
                            <i class="ti ti-users me-1 text-muted" aria-hidden="true"></i>{{ usersLabel(role) }}
                        </td>

                        <td v-else-if="col.key === 'cadastro'" class="text-muted small">{{ date(role.created_at) }}</td>
                    </template>

                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton
                                icon="ti ti-edit"
                                :title="t.action_edit ?? 'Editar'"
                                @click="emit('edit', role)"
                            />
                            <ActionIconButton
                                icon="ti ti-trash"
                                :title="t.action_delete ?? 'Excluir'"
                                variant="danger"
                                @click="emit('delete', role)"
                            />
                        </ActionIconGroup>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <TablePagination
        :data="roles"
        :showing-from="t.pagination_showing"
        :showing-of="t.pagination_of"
        :showing-suffix="t.pagination_suffix"
        :aria-label="t.pagination_label"
        :previous-label="t.pagination_previous"
        :next-label="t.pagination_next"
    />
</template>

<style scoped>
/* Nome + descrição truncados: a descrição longa não empurra as colunas. */
.role-name-cell {
    max-width: 360px;
}
.min-w-0 {
    min-width: 0;
}
.role-avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--bs-primary);
    background: var(--bs-primary-bg-subtle);
}
</style>
