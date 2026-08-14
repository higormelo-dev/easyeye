<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import { useColumnOrder } from '@/composables/useColumnOrder.js';
import { formatPatientCode } from '@/utils/formatPatientCode.js';

const props = defineProps({
    patients: { type: Object, required: true },
    filters:  { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'edit', 'view', 'delete', 'toggleActive', 'restore']);

const currentSort = computed(() => props.filters.sort ?? 'created_at');
const currentDir  = computed(() => props.filters.direction ?? 'desc');

function sort(col) {
    const dir = currentSort.value === col && currentDir.value === 'asc' ? 'desc' : 'asc';
    emit('sort', { sort: col, direction: dir });
}

function sortIcon(col) {
    if (currentSort.value !== col) return 'ti ti-arrows-sort text-muted';
    return currentDir.value === 'asc' ? 'ti ti-sort-ascending' : 'ti ti-sort-descending';
}

const sortableColClass = 'cursor-pointer user-select-none';

// ── Ordem de colunas personalizável ──────────────────────────────────────────
// Status/Ações ficam fixas (fora do reorder) — convenção de tabela admin:
// ações sempre por último, status logo antes. Só as colunas "de conteúdo" do
// paciente são reordenáveis. Ordem padrão pedida: Nome, Telefone, Cadastro,
// Código — Gênero entra ao final por não ter sido especificada.
const COLUMN_DEFS = [
    { key: 'nome',     label: 'Nome',     sortKey: 'full_name' },
    { key: 'telefone', label: 'Telefone', sortKey: 'cellphone' },
    { key: 'cadastro', label: 'Cadastro', sortKey: 'created_at' },
    { key: 'codigo',   label: 'Código',   sortKey: 'code' },
    { key: 'genero',   label: 'Gênero',   sortKey: null },
];
const DEFAULT_COLUMN_ORDER = ['nome', 'telefone', 'cadastro', 'codigo', 'genero'];

const { order: columnOrder, moveTo: moveColumn, reset: resetColumnOrder } = useColumnOrder(
    'pat_table_columns_order',
    DEFAULT_COLUMN_ORDER,
);

const orderedColumns = computed(() => (
    columnOrder.value
        .map((key) => COLUMN_DEFS.find((c) => c.key === key))
        .filter(Boolean)
));
</script>

<template>
    <!-- Toolbar: personalizar colunas -->
    <div class="d-flex justify-content-end mb-2">
        <ActionDropdown
            title="Personalizar colunas"
            align="right"
            :min-width="230"
            btn-class="bg-white border shadow-sm rounded px-2 py-1 d-flex align-items-center gap-1 fs-13 text-muted"
        >
            <template #trigger>
                <i class="ti ti-adjustments"></i>
                <span class="d-none d-sm-inline">Colunas</span>
            </template>

            <ColumnOrderMenu
                :columns="orderedColumns"
                @move="moveColumn"
                @reset="resetColumnOrder"
            />
        </ActionDropdown>
    </div>

    <!-- Table -->
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <template v-for="col in orderedColumns" :key="col.key">
                        <th
                            :class="col.sortKey ? sortableColClass : ''"
                            @click="col.sortKey && sort(col.sortKey)"
                        >
                            {{ col.label }}
                            <i v-if="col.sortKey" :class="sortIcon(col.sortKey)" class="ms-1 fs-11"></i>
                        </th>
                    </template>
                    <th class="text-center">Status</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="patients.data.length === 0">
                    <td colspan="7" class="text-center text-muted py-5">
                        <i class="ti ti-user-off fs-1 d-block mb-2"></i>
                        Nenhum paciente encontrado.
                    </td>
                </tr>
                <tr v-for="p in patients.data" :key="p.id" :class="{ 'table-secondary opacity-75': p.deleted }">
                    <template v-for="col in orderedColumns" :key="col.key">
                        <td v-if="col.key === 'cadastro'" class="text-muted small">{{ p.created_at }}</td>

                        <td v-else-if="col.key === 'codigo'">
                            <code class="text-muted small" :title="p.code">{{ formatPatientCode(p.code) }}</code>
                        </td>

                        <td v-else-if="col.key === 'nome'">
                            <div class="d-flex align-items-center gap-2">
                                <img
                                    :src="p.photo_url"
                                    :alt="p.full_name"
                                    class="rounded-circle"
                                    style="width:30px;height:30px;object-fit:cover;"
                                >
                                <span class="fw-medium">{{ p.full_name }}</span>
                                <i v-if="p.deleted" class="ti ti-trash text-danger ms-1" title="Excluído"></i>
                            </div>
                        </td>

                        <td v-else-if="col.key === 'genero'" class="text-muted small">{{ p.gender_label ?? '—' }}</td>

                        <td v-else-if="col.key === 'telefone'" class="small">
                            <i v-if="p.whatsapp" class="fab fa-whatsapp text-success me-1"></i>
                            {{ p.cellphone ?? '—' }}
                        </td>
                    </template>
                    <td class="text-center">
                        <span
                            v-if="p.deleted"
                            class="badge badge-soft-secondary rounded fs-13 fw-medium"
                        >Excluído</span>
                        <span
                            v-else-if="p.active"
                            class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                        >Sim</span>
                        <span
                            v-else
                            class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                        >Não</span>
                    </td>
                    <td class="text-end">
                        <!-- RESTORE -->
                        <template v-if="p.mode === 'restore'">
                            <ActionIconGroup align="end">
                                <ActionIconButton
                                    icon="ti ti-recycle"
                                    title="Restaurar"
                                    @click="$emit('restore', p.id)"
                                />
                            </ActionIconGroup>
                        </template>

                        <!-- VIEW ONLY -->
                        <template v-else-if="p.mode === 'view_only'">
                            <ActionIconGroup align="end">
                                <ActionIconButton
                                    icon="ti ti-eye"
                                    title="Visualizar"
                                    @click="$emit('view', p.id)"
                                />
                            </ActionIconGroup>
                        </template>

                        <!-- FULL ACTIONS -->
                        <template v-else-if="p.mode === 'full'">
                            <ActionIconGroup align="end" gap="tight">
                                <ActionIconButton
                                    icon="ti ti-eye"
                                    title="Visualizar"
                                    @click="$emit('view', p.id)"
                                />
                                <ActionIconButton
                                    icon="ti ti-stethoscope"
                                    title="Prontuário"
                                    variant="info"
                                    :href="p.medical_records_url"
                                />
                                <ActionDropdown
                                    btn-class="ee-action-icon ee-action-icon--default"
                                    icon="ti ti-dots-vertical"
                                >
                                    <li>
                                        <button
                                            class="dropdown-item rounded-1"
                                            @click="$emit('edit', p.id)"
                                        >
                                            <i class="ti ti-edit me-1"></i> Editar
                                        </button>
                                    </li>
                                    <li>
                                        <button
                                            class="dropdown-item rounded-1"
                                            @click="$emit('toggleActive', p.id, p.active)"
                                        >
                                            <i :class="`ti me-1 ${p.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                            {{ p.active ? 'Desativar' : 'Ativar' }}
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button
                                            class="dropdown-item rounded-1 text-danger"
                                            @click="$emit('delete', p.id)"
                                        >
                                            <i class="ti ti-trash me-1"></i> Excluir
                                        </button>
                                    </li>
                                </ActionDropdown>
                            </ActionIconGroup>
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div
        v-if="patients.last_page > 1"
        class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2"
    >
        <p class="text-muted small mb-0">
            Exibindo {{ patients.from }}–{{ patients.to }} de {{ patients.total }} pacientes
        </p>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ disabled: patients.current_page === 1 }">
                    <Link
                        class="page-link"
                        :href="patients.prev_page_url ?? '#'"
                        preserve-scroll
                        preserve-state
                    >
                        <i class="ti ti-arrow-left"></i>
                    </Link>
                </li>
                <template v-for="link in patients.links.slice(1, -1)" :key="link.label">
                    <li class="page-item" :class="{ active: link.active, disabled: !link.url }">
                        <Link
                            class="page-link"
                            :href="link.url ?? '#'"
                            preserve-scroll
                            preserve-state
                            v-html="link.label"
                        />
                    </li>
                </template>
                <li class="page-item" :class="{ disabled: patients.current_page === patients.last_page }">
                    <Link
                        class="page-link"
                        :href="patients.next_page_url ?? '#'"
                        preserve-scroll
                        preserve-state
                    >
                        <i class="ti ti-arrow-right"></i>
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>

<style scoped>
.cursor-pointer { cursor: pointer; }
</style>
