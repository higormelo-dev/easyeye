<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import StatusBadge from '@/Components/Panel/StatusBadge.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

const props = defineProps({
    plans: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['sort', 'view', 'edit', 'delete', 'toggleActive']);

const { money } = useLocaleFormat();

const currentSort = computed(() => props.filters.sort ?? 'sort_order');
const currentDir = computed(() => props.filters.direction ?? 'asc');
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
                        style="width: 70px"
                        @sort="$emit('sort', $event)"
                        >{{ t.col_order }}</SortableTh
                    >
                    <SortableTh
                        col-key="name"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_name }}
                    </SortableTh>
                    <SortableTh
                        col-key="price"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_prices }}
                    </SortableTh>
                    <th class="text-center">{{ t.col_subscribers }}</th>
                    <SortableTh
                        col-key="active"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="text-center"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_status }}
                    </SortableTh>
                    <th class="text-end">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <!-- Empty state -->
                <tr v-if="plans.data.length === 0">
                    <td colspan="6" class="text-center text-muted py-5">
                        <i class="ti ti-box fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty_list }}
                    </td>
                </tr>

                <tr v-for="p in plans.data" :key="p.id">
                    <td class="text-center">
                        <span class="badge badge-soft-secondary rounded fs-12">{{ p.sort_order }}</span>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-medium" style="font-size: 0.875rem">{{ p.name }}</span>
                            <span v-if="p.is_featured" class="badge badge-soft-warning rounded fs-11">
                                <i class="ti ti-star me-1" aria-hidden="true"></i>{{ t.featured_badge }}
                            </span>
                        </div>
                        <div
                            v-if="p.description"
                            class="text-muted text-truncate"
                            style="font-size: 0.75rem; max-width: 280px"
                        >
                            {{ p.description }}
                        </div>
                    </td>
                    <td>
                        <ul class="plan-prices list-unstyled mb-0">
                            <li
                                v-if="!p.prices?.length"
                                class="small text-warning-emphasis"
                                data-test="no-sellable-cycle"
                            >
                                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.no_sellable_cycle }}
                            </li>
                            <li v-for="price in p.prices" :key="price.cycle" :data-cycle="price.cycle">
                                <span class="text-muted">{{ price.label }}&nbsp;</span>
                                <span class="fw-semibold">{{ money(price.price) }}</span>
                                <span v-if="price.savings_percent > 0" class="badge badge-soft-success ms-1 fs-11"
                                    >−{{ price.savings_percent }}%</span
                                >
                            </li>
                        </ul>
                    </td>
                    <td class="text-center">
                        <Link
                            :href="route('manager.subscriptions.index', { plan: p.id })"
                            class="btn btn-sm btn-light fw-semibold"
                            :title="t.subscribers_link_title"
                            :aria-label="`${t.subscribers_link_title}: ${p.name}`"
                        >
                            <i class="ti ti-building-hospital me-1" aria-hidden="true"></i>{{ p.subscribers }}
                        </Link>
                    </td>
                    <td class="text-center">
                        <StatusBadge
                            :active="p.active"
                            :label-active="t.status_active"
                            :label-inactive="t.status_inactive"
                        />
                    </td>
                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton icon="ti ti-eye" :title="t.action_view" @click="$emit('view', p.id)" />
                            <ActionDropdown
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                            >
                                <li>
                                    <button class="dropdown-item rounded-1" @click="$emit('edit', p.id)">
                                        <i class="ti ti-edit me-1" aria-hidden="true"></i> {{ t.action_edit }}
                                    </button>
                                </li>
                                <li>
                                    <Link
                                        :href="route('manager.subscriptions.index', { plan: p.id })"
                                        class="dropdown-item rounded-1"
                                    >
                                        <i class="ti ti-list-details me-1" aria-hidden="true"></i>
                                        {{ t.action_view_subscriptions }}
                                    </Link>
                                </li>
                                <li v-if="p.active">
                                    <Link
                                        :href="route('manager.subscriptions.index', { new: 1, new_plan: p.id })"
                                        class="dropdown-item rounded-1"
                                    >
                                        <i class="ti ti-plus me-1" aria-hidden="true"></i>
                                        {{ t.action_new_subscription }}
                                    </Link>
                                </li>
                                <li>
                                    <button
                                        class="dropdown-item rounded-1"
                                        @click="$emit('toggleActive', p.id, p.active)"
                                    >
                                        <i
                                            :class="`ti me-1 ${p.active ? 'ti-lock-open' : 'ti-lock'}`"
                                            aria-hidden="true"
                                        ></i>
                                        {{ p.active ? t.action_deactivate : t.action_activate }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider" /></li>
                                <li>
                                    <button class="dropdown-item rounded-1 text-danger" @click="$emit('delete', p.id)">
                                        <i class="ti ti-trash me-1" aria-hidden="true"></i> {{ t.action_delete }}
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
    <TablePagination
        :data="plans"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
    />
</template>

<style scoped>
.plan-prices li {
    font-size: 0.8125rem;
    line-height: 1.6;
    white-space: nowrap;
}
</style>
