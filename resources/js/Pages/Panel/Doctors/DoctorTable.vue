<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';

const props = defineProps({
    doctors: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'view', 'edit', 'delete', 'toggleActive']);

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

const th = 'cursor-pointer user-select-none';
</script>

<template>
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th :class="th" @click="sort('full_name')">
                        Nome <i :class="sortIcon('full_name')" class="ms-1 fs-11"></i>
                    </th>
                    <th :class="th" @click="sort('record')">
                        CRM <i :class="sortIcon('record')" class="ms-1 fs-11"></i>
                    </th>
                    <th :class="th" @click="sort('email')">
                        E-mail <i :class="sortIcon('email')" class="ms-1 fs-11"></i>
                    </th>
                    <th :class="th" @click="sort('code')">
                        Registro <i :class="sortIcon('code')" class="ms-1 fs-11"></i>
                    </th>
                    <th :class="th" @click="sort('created_at')">
                        Criação <i :class="sortIcon('created_at')" class="ms-1 fs-11"></i>
                    </th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="doctors.data.length === 0">
                    <td colspan="7" class="text-center text-muted py-5">
                        <i class="ti ti-stethoscope fs-1 d-block mb-2"></i>
                        Nenhum médico encontrado.
                    </td>
                </tr>
                <tr v-for="d in doctors.data" :key="d.id" :class="{ 'table-secondary opacity-75': d.deleted }">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span
                                v-if="d.color"
                                class="rounded-circle d-inline-block border"
                                :style="{ background: d.color, width: '12px', height: '12px', flexShrink: 0 }"
                            ></span>
                            <img
                                :src="d.photo_url" :alt="d.full_name"
                                class="rounded-circle"
                                style="width:28px;height:28px;object-fit:cover;"
                            >
                            <div>
                                <div class="fw-medium" style="font-size:.875rem;">{{ d.full_name }}</div>
                                <div class="text-muted" style="font-size:.75rem;">{{ d.record_specialty }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="small">{{ d.record }}</td>
                    <td class="text-muted small">{{ d.email }}</td>
                    <td><code class="text-muted small">{{ d.code }}</code></td>
                    <td class="text-muted small">{{ d.created_at }}</td>
                    <td class="text-center">
                        <span
                            v-if="d.active"
                            class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                        >Ativo</span>
                        <span
                            v-else
                            class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                        >Inativo</span>
                    </td>
                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton
                                icon="ti ti-eye"
                                title="Visualizar"
                                @click="$emit('view', d.id)"
                            />
                            <ActionIconButton
                                icon="ti ti-calendar-time"
                                title="Horários de atendimento"
                                variant="info"
                                :href="d.work_schedule_url"
                            />
                            <ActionDropdown
                                v-if="d.mode === 'full'"
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                            >
                                <li>
                                    <button class="dropdown-item rounded-1" @click="$emit('edit', d.id)">
                                        <i class="ti ti-edit me-1"></i> Editar
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item rounded-1" @click="$emit('toggleActive', d.id, d.active)">
                                        <i :class="`ti me-1 ${d.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                        {{ d.active ? 'Desativar' : 'Ativar' }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button class="dropdown-item rounded-1 text-danger" @click="$emit('delete', d.id)">
                                        <i class="ti ti-trash me-1"></i> Excluir
                                    </button>
                                </li>
                            </ActionDropdown>
                        </ActionIconGroup>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div v-if="doctors.last_page > 1" class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2">
        <p class="text-muted small mb-0">
            Exibindo {{ doctors.from }}–{{ doctors.to }} de {{ doctors.total }} médicos
        </p>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ disabled: doctors.current_page === 1 }">
                    <Link class="page-link" :href="doctors.prev_page_url ?? '#'" preserve-scroll preserve-state>
                        <i class="ti ti-arrow-left"></i>
                    </Link>
                </li>
                <template v-for="link in doctors.links.slice(1, -1)" :key="link.label">
                    <li class="page-item" :class="{ active: link.active, disabled: !link.url }">
                        <Link class="page-link" :href="link.url ?? '#'" preserve-scroll preserve-state v-html="link.label" />
                    </li>
                </template>
                <li class="page-item" :class="{ disabled: doctors.current_page === doctors.last_page }">
                    <Link class="page-link" :href="doctors.next_page_url ?? '#'" preserve-scroll preserve-state>
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
