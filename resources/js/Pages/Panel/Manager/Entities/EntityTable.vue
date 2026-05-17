<script setup>
import { computed } from 'vue';
import SortableTh      from '@/Components/Panel/SortableTh.vue';
import StatusBadge     from '@/Components/Panel/StatusBadge.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';

const props = defineProps({
    entities: { type: Object, required: true },
    filters:  { type: Object, default: () => ({}) },
    t:        { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'view', 'edit', 'delete', 'toggleActive']);

const currentSort = computed(() => props.filters.sort ?? 'created_at');
const currentDir  = computed(() => props.filters.direction ?? 'desc');

function onSort(payload) { emit('sort', payload); }
</script>

<template>
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <SortableTh col-key="created_at" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_registered_at }}
                    </SortableTh>
                    <SortableTh col-key="code" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_code }}
                    </SortableTh>
                    <SortableTh col-key="name" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_company }}
                    </SortableTh>
                    <SortableTh col-key="city" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_city_state }}
                    </SortableTh>
                    <th class="text-center">{{ t.col_users }}</th>
                    <SortableTh col-key="active" :current-sort="currentSort" :current-dir="currentDir" class="text-center" @sort="onSort">
                        {{ t.col_status }}
                    </SortableTh>
                    <th class="text-end">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <!-- Empty state -->
                <tr v-if="entities.data.length === 0">
                    <td colspan="7" class="text-center text-muted py-5">
                        <i class="ti ti-building fs-1 d-block mb-2"></i>
                        {{ t.empty_list }}
                    </td>
                </tr>

                <tr
                    v-for="e in entities.data"
                    :key="e.id"
                    :class="{ 'table-secondary opacity-75': e.deleted }"
                >
                    <td class="text-muted small">{{ e.created_at }}</td>

                    <td><code class="text-muted small">{{ e.code }}</code></td>

                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div
                                class="avatar-sm rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0"
                                style="width:32px;height:32px;"
                            >
                                <i class="ti ti-building text-primary fs-14"></i>
                            </div>
                            <div>
                                <div class="fw-medium lh-sm" style="font-size:.875rem;">{{ e.name }}</div>
                                <div v-if="e.email" class="text-muted" style="font-size:.75rem;">{{ e.email }}</div>
                            </div>
                        </div>
                    </td>

                    <td class="text-muted small">
                        <template v-if="e.city || e.state">
                            {{ e.city || '—' }}{{ e.state ? ` / ${e.state}` : '' }}
                        </template>
                        <span v-else>—</span>
                    </td>

                    <td class="text-center">
                        <span class="badge badge-soft-secondary rounded fs-12">{{ e.entity_users_count }}</span>
                    </td>

                    <td class="text-center">
                        <StatusBadge
                            :active="e.active"
                            :deleted="e.deleted"
                            :label-active="t.status_active"
                            :label-inactive="t.status_inactive"
                            :label-deleted="t.status_deleted"
                        />
                    </td>

                    <td class="text-end">
                        <!-- RESTORE -->
                        <template v-if="e.mode === 'restore'">
                            <button class="btn btn-sm btn-outline-secondary" :title="t.action_restore" disabled>
                                <i class="ti ti-recycle"></i>
                            </button>
                        </template>

                        <!-- FULL ACTIONS -->
                        <template v-else-if="e.mode === 'full'">
                            <div class="d-flex align-items-center justify-content-end gap-1">
                                <button
                                    class="btn btn-sm btn-outline-secondary"
                                    :title="t.action_view"
                                    @click="$emit('view', e.id)"
                                ><i class="ti ti-eye"></i></button>

                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                        <i class="ti ti-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:210px;">
                                        <li>
                                            <button class="dropdown-item rounded-1" @click="$emit('edit', e.id)">
                                                <i class="ti ti-edit me-1"></i> {{ t.action_edit }}
                                            </button>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>

                                        <li v-if="e.entity_users_count > 0">
                                            <a :href="e.users_url" class="dropdown-item rounded-1">
                                                <i class="ti ti-users me-1"></i> {{ t.action_users }}
                                                <span class="badge badge-soft-primary ms-1 fs-11">{{ e.entity_users_count }}</span>
                                            </a>
                                        </li>
                                        <li v-else>
                                            <span class="dropdown-item rounded-1 text-muted disabled">
                                                <i class="ti ti-users me-1"></i> {{ t.action_users }}
                                            </span>
                                        </li>

                                        <li v-if="e.entity_user_integrators_count > 0">
                                            <a :href="e.user_integrators_url" class="dropdown-item rounded-1">
                                                <i class="ti ti-user-cog me-1"></i> {{ t.action_user_integrators }}
                                                <span class="badge badge-soft-primary ms-1 fs-11">{{ e.entity_user_integrators_count }}</span>
                                            </a>
                                        </li>
                                        <li v-else>
                                            <span class="dropdown-item rounded-1 text-muted disabled">
                                                <i class="ti ti-user-cog me-1"></i> {{ t.action_user_integrators }}
                                            </span>
                                        </li>

                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <button class="dropdown-item rounded-1" @click="$emit('toggleActive', e.id, e.active)">
                                                <i :class="`ti me-1 ${e.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                                {{ e.active ? t.action_deactivate : t.action_activate }}
                                            </button>
                                        </li>
                                        <li>
                                            <button class="dropdown-item rounded-1 text-danger" @click="$emit('delete', e.id)">
                                                <i class="ti ti-trash me-1"></i> {{ t.action_delete }}
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
    <TablePagination
        :data="entities"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
    />
</template>
