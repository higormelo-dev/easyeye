<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

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
</script>

<template>
    <!-- Table -->
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th :class="sortableColClass" @click="sort('created_at')">
                        Cadastro <i :class="sortIcon('created_at')" class="ms-1 fs-11"></i>
                    </th>
                    <th :class="sortableColClass" @click="sort('code')">
                        Código <i :class="sortIcon('code')" class="ms-1 fs-11"></i>
                    </th>
                    <th :class="sortableColClass" @click="sort('full_name')">
                        Nome <i :class="sortIcon('full_name')" class="ms-1 fs-11"></i>
                    </th>
                    <th>Gênero</th>
                    <th :class="sortableColClass" @click="sort('cellphone')">
                        Telefone <i :class="sortIcon('cellphone')" class="ms-1 fs-11"></i>
                    </th>
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
                    <td class="text-muted small">{{ p.created_at }}</td>
                    <td><code class="text-muted small">{{ p.code }}</code></td>
                    <td>
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
                    <td class="text-muted small">{{ p.gender_label ?? '—' }}</td>
                    <td class="small">
                        <i v-if="p.whatsapp" class="fab fa-whatsapp text-success me-1"></i>
                        {{ p.cellphone ?? '—' }}
                    </td>
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
                            <button
                                class="btn btn-sm btn-outline-secondary"
                                title="Restaurar"
                                @click="$emit('restore', p.id)"
                            >
                                <i class="ti ti-recycle"></i>
                            </button>
                        </template>

                        <!-- VIEW ONLY -->
                        <template v-else-if="p.mode === 'view_only'">
                            <button
                                class="btn btn-sm btn-outline-secondary"
                                title="Visualizar"
                                @click="$emit('view', p.id)"
                            >
                                <i class="ti ti-eye"></i>
                            </button>
                        </template>

                        <!-- FULL ACTIONS -->
                        <template v-else-if="p.mode === 'full'">
                            <div class="d-flex align-items-center justify-content-end gap-1">
                                <button
                                    class="btn btn-sm btn-outline-secondary"
                                    title="Visualizar"
                                    @click="$emit('view', p.id)"
                                >
                                    <i class="ti ti-eye"></i>
                                </button>
                                <a
                                    :href="p.medical_records_url"
                                    class="btn btn-sm btn-outline-info"
                                    title="Prontuário"
                                >
                                    <i class="ti ti-stethoscope"></i>
                                </a>
                                <div class="dropdown">
                                    <button
                                        class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="dropdown"
                                    >
                                        <i class="ti ti-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end p-2">
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
                                    </ul>
                                </div>
                            </div>
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
