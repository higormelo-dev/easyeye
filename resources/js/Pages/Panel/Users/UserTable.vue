<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useColumnOrder } from '@/composables/useColumnOrder.js';
import UserActions from './UserActions.vue';
import { useUserFormat } from './useUserFormat.js';

/**
 * Tabela de usuários no padrão de Patients/PatientTable: cabeçalhos
 * ordenáveis (whitelist de UsersController::SORTABLE), menu "Colunas"
 * (ordem persistida no navegador), Status/Ações fixos no fim e paginação
 * acessível (TablePagination, sem v-html).
 */
const props = defineProps({
    users: { type: Object, required: true }, // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
    emptyText: { type: String, default: '' },
});

const emit = defineEmits(['sort', 'edit', 'delete', 'restore', 'toggleActive']);

const { tx, date, extraRolesLabel } = useUserFormat(() => props.t);

const rows = computed(() => props.users?.data ?? []);

// ── Ordenação (padrão = cadastro mais recente primeiro, igual ao backend) ───
const currentSort = computed(() => props.filters.sort ?? 'created_at');
const currentDir = computed(() => props.filters.direction ?? 'desc');

// ── Ordem de colunas personalizável ─────────────────────────────────────────
// sortKey = chave aceita por UsersController::SORTABLE.
const COLUMN_DEFS = computed(() => [
    { key: 'nome', label: props.t.col_name ?? 'Nome', sortKey: 'name' },
    { key: 'email', label: props.t.col_email ?? 'E-mail', sortKey: 'email' },
    { key: 'perfil', label: props.t.col_role ?? 'Perfil', sortKey: 'rule' },
    { key: 'cadastro', label: props.t.col_created_at ?? 'Cadastro', sortKey: 'created_at' },
]);
const DEFAULT_COLUMN_ORDER = ['nome', 'email', 'perfil', 'cadastro'];

const {
    order: columnOrder,
    moveTo: moveColumn,
    reset: resetColumnOrder,
} = useColumnOrder('access_users_columns_order', DEFAULT_COLUMN_ORDER);

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
                    <th class="text-center">{{ t.col_status ?? 'Status' }}</th>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td :colspan="orderedColumns.length + 2" class="text-center text-muted py-5">
                        <i class="ti ti-users fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ emptyText }}
                    </td>
                </tr>
                <tr v-for="u in rows" :key="u.id" :class="{ 'user-row-deleted': u.deleted }">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'nome'">
                            <div class="d-flex align-items-center gap-2">
                                <img
                                    :src="u.photo_url"
                                    :alt="u.name"
                                    class="rounded-circle flex-shrink-0"
                                    width="30"
                                    height="30"
                                    loading="lazy"
                                    style="object-fit: cover"
                                />
                                <span class="fw-medium">{{ u.name }}</span>
                                <span v-if="u.is_owner" class="badge badge-soft-warning rounded fs-11">
                                    <i class="ti ti-crown me-1" aria-hidden="true"></i
                                    >{{ t.badge_owner ?? 'Proprietário' }}
                                </span>
                                <span v-if="u.is_self" class="badge badge-soft-primary rounded fs-11">{{
                                    t.badge_self ?? 'Você'
                                }}</span>
                            </div>
                        </td>

                        <td v-else-if="col.key === 'email'" class="text-muted small">{{ u.email }}</td>

                        <td v-else-if="col.key === 'perfil'">
                            <span class="badge badge-soft-secondary rounded fs-12">{{ u.rule_label }}</span>
                            <div v-if="extraRolesLabel(u)" class="text-muted small mt-1">{{ extraRolesLabel(u) }}</div>
                        </td>

                        <td v-else-if="col.key === 'cadastro'" class="text-muted small">{{ date(u.created_at) }}</td>
                    </template>

                    <td class="text-center">
                        <span
                            v-if="u.deleted"
                            class="badge badge-soft-secondary rounded text-body-secondary border fs-13 fw-medium"
                            ><i class="ti ti-trash me-1" aria-hidden="true"></i
                            >{{ t.status_deleted ?? 'Excluído' }}</span
                        >
                        <span
                            v-else-if="u.active"
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
                        <UserActions
                            :user="u"
                            :t="t"
                            @edit="emit('edit', $event)"
                            @delete="emit('delete', $event)"
                            @restore="emit('restore', $event)"
                            @toggle-active="emit('toggleActive', $event)"
                        />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <TablePagination
        :data="users"
        :showing-from="t.pagination_showing"
        :showing-of="t.pagination_of"
        :showing-suffix="t.pagination_suffix"
        :aria-label="t.pagination_label"
        :previous-label="t.pagination_previous"
        :next-label="t.pagination_next"
    />
</template>

<style scoped>
/* Removido: fundo discreto em vez de opacity (que apagava também o texto e
   derrubava o contraste). */
.user-row-deleted > td {
    background: var(--bs-tertiary-bg);
    color: var(--bs-secondary-color);
}
</style>
