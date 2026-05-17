<script setup>
import { computed } from 'vue';
import SortableTh      from '@/Components/Panel/SortableTh.vue';
import StatusBadge     from '@/Components/Panel/StatusBadge.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';

const props = defineProps({
    plans:   { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t:       { type: Object, default: () => ({}) },
});

const emit = defineEmits(['sort', 'view', 'edit', 'delete', 'toggleActive']);

const currentSort = computed(() => props.filters.sort ?? 'sort_order');
const currentDir  = computed(() => props.filters.direction ?? 'asc');

function onSort(payload) { emit('sort', payload); }
</script>

<template>
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <SortableTh
                        col-key="sort_order"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        style="width:70px;"
                        @sort="onSort"
                    >{{ t.col_order }}</SortableTh>
                    <SortableTh col-key="name" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_name }}
                    </SortableTh>
                    <SortableTh col-key="price" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_price }}
                    </SortableTh>
                    <SortableTh col-key="billing_cycle" :current-sort="currentSort" :current-dir="currentDir" @sort="onSort">
                        {{ t.col_cycle }}
                    </SortableTh>
                    <SortableTh col-key="active" :current-sort="currentSort" :current-dir="currentDir" class="text-center" @sort="onSort">
                        {{ t.col_status }}
                    </SortableTh>
                    <th class="text-end">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <!-- Empty state -->
                <tr v-if="plans.data.length === 0">
                    <td colspan="6" class="text-center text-muted py-5">
                        <i class="ti ti-box fs-1 d-block mb-2"></i>
                        {{ t.empty_list }}
                    </td>
                </tr>

                <tr v-for="p in plans.data" :key="p.id">
                    <td class="text-center">
                        <span class="badge badge-soft-secondary rounded fs-12">{{ p.sort_order }}</span>
                    </td>
                    <td>
                        <div class="fw-medium" style="font-size:.875rem;">{{ p.name }}</div>
                        <div
                            v-if="p.description"
                            class="text-muted text-truncate"
                            style="font-size:.75rem;max-width:280px;"
                        >{{ p.description }}</div>
                    </td>
                    <td class="fw-semibold">{{ p.price }}</td>
                    <td>
                        <span class="badge badge-soft-info rounded fs-12">{{ p.billing_label }}</span>
                    </td>
                    <td class="text-center">
                        <StatusBadge
                            :active="p.active"
                            :label-active="t.status_active"
                            :label-inactive="t.status_inactive"
                        />
                    </td>
                    <td class="text-end">
                        <div class="d-flex align-items-center justify-content-end gap-1">
                            <button
                                class="btn btn-sm btn-outline-secondary"
                                :title="t.action_view"
                                @click="$emit('view', p.id)"
                            ><i class="ti ti-eye"></i></button>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                    <i class="ti ti-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end p-2">
                                    <li>
                                        <button class="dropdown-item rounded-1" @click="$emit('edit', p.id)">
                                            <i class="ti ti-edit me-1"></i> {{ t.action_edit }}
                                        </button>
                                    </li>
                                    <li>
                                        <button class="dropdown-item rounded-1" @click="$emit('toggleActive', p.id, p.active)">
                                            <i :class="`ti me-1 ${p.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                            {{ p.active ? t.action_deactivate : t.action_activate }}
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button class="dropdown-item rounded-1 text-danger" @click="$emit('delete', p.id)">
                                            <i class="ti ti-trash me-1"></i> {{ t.action_delete }}
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <TablePagination
        :data="plans"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
    />
</template>
