<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import SortableTh from '@/Components/Panel/SortableTh.vue';

const props = defineProps({
    users:   { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t:       { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'edit', 'delete', 'restore', 'toggle-active']);

const currentSort = computed(() => props.filters.sort      ?? 'created_at');
const currentDir  = computed(() => props.filters.direction ?? 'desc');

function onSort(payload) {
    emit('sort', payload);
}

const showing = computed(() => {
    if (!props.users.from) return '';
    return (props.t.showing ?? 'Exibindo :from–:to de :total usuários')
        .replace(':from',  props.users.from)
        .replace(':to',    props.users.to)
        .replace(':total', props.users.total);
});
</script>

<template>
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <SortableTh col-key="created_at" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_created_at }}
                    </SortableTh>
                    <SortableTh col-key="name" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_name }}
                    </SortableTh>
                    <SortableTh col-key="email" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_email }}
                    </SortableTh>
                    <SortableTh col-key="rule" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_role }}
                    </SortableTh>
                    <th class="text-center">{{ t.col_status }}</th>
                    <th class="text-end">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <!-- Empty state -->
                <tr v-if="users.data.length === 0">
                    <td colspan="6" class="text-center text-muted py-5">
                        <i class="ti ti-users fs-1 d-block mb-2 opacity-40"></i>
                        {{ t.empty }}
                    </td>
                </tr>

                <tr
                    v-for="u in users.data"
                    :key="u.id"
                    :class="{ 'table-secondary opacity-75': u.deleted }"
                >
                    <!-- Cadastro -->
                    <td class="text-muted small">{{ u.created_at }}</td>

                    <!-- Nome + avatar -->
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <img
                                :src="u.photo_url"
                                :alt="u.name"
                                class="rounded-circle flex-shrink-0"
                                style="width:28px;height:28px;object-fit:cover;"
                            >
                            <span class="fw-medium">{{ u.name }}</span>
                            <span v-if="u.is_owner" class="badge badge-soft-warning rounded fs-11 ms-1">
                                <i class="ti ti-crown me-1"></i>{{ t.badge_owner }}
                            </span>
                            <i v-if="u.deleted" class="ti ti-trash text-danger ms-1 fs-12"></i>
                        </div>
                    </td>

                    <!-- E-mail -->
                    <td class="text-muted small">{{ u.email }}</td>

                    <!-- Perfil -->
                    <td>
                        <span class="badge badge-soft-secondary rounded fs-12">{{ u.rule_label }}</span>
                    </td>

                    <!-- Status -->
                    <td class="text-center">
                        <span v-if="u.deleted" class="badge badge-soft-secondary rounded fs-13">
                            {{ t.status_deleted }}
                        </span>
                        <span
                            v-else-if="u.active"
                            class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
                        >{{ t.status_active }}</span>
                        <span
                            v-else
                            class="badge badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium"
                        >{{ t.status_inactive }}</span>
                    </td>

                    <!-- Ações -->
                    <td class="text-end">
                        <!-- Restore mode -->
                        <template v-if="u.mode === 'restore'">
                            <button
                                class="btn btn-sm btn-outline-secondary"
                                :title="t.btn_restore"
                                @click="$emit('restore', u.id)"
                            >
                                <i class="ti ti-recycle"></i>
                            </button>
                        </template>

                        <!-- Full mode -->
                        <template v-else-if="u.mode === 'full'">
                            <div class="d-flex align-items-center justify-content-end gap-1">
                                <button
                                    class="btn btn-sm btn-outline-secondary"
                                    :title="t.btn_edit"
                                    @click="$emit('edit', u.id)"
                                >
                                    <i class="ti ti-edit"></i>
                                </button>
                                <div v-if="!u.is_owner && !u.is_self" class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                        <i class="ti ti-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end p-2">
                                        <li>
                                            <button
                                                class="dropdown-item rounded-1"
                                                @click="$emit('toggle-active', u.id, u.active)"
                                            >
                                                <i :class="`ti me-1 ${u.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                                {{ u.active ? t.btn_deactivate : t.btn_activate }}
                                            </button>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <button
                                                class="dropdown-item rounded-1 text-danger"
                                                @click="$emit('delete', u.id)"
                                            >
                                                <i class="ti ti-trash me-1"></i>{{ t.btn_delete }}
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
        v-if="users.last_page > 1"
        class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2"
    >
        <p class="text-muted small mb-0">{{ showing }}</p>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ disabled: users.current_page === 1 }">
                    <Link class="page-link" :href="users.prev_page_url ?? '#'" preserve-scroll preserve-state>
                        <i class="ti ti-arrow-left"></i>
                    </Link>
                </li>
                <template v-for="link in users.links.slice(1, -1)" :key="link.label">
                    <li class="page-item" :class="{ active: link.active, disabled: !link.url }">
                        <Link class="page-link" :href="link.url ?? '#'" preserve-scroll preserve-state v-html="link.label" />
                    </li>
                </template>
                <li class="page-item" :class="{ disabled: users.current_page === users.last_page }">
                    <Link class="page-link" :href="users.next_page_url ?? '#'" preserve-scroll preserve-state>
                        <i class="ti ti-arrow-right"></i>
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
