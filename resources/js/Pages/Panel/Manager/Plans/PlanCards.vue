<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LoadingSpinner from '@/Components/Panel/LoadingSpinner.vue';
import StatusBadge from '@/Components/Panel/StatusBadge.vue';
import CardsPagination from '@/Components/Panel/CardsPagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

const props = defineProps({
    cardsUrl: { type: String, required: true },
    initialSearch: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['view', 'edit', 'delete', 'toggleActive']);
const { money } = useLocaleFormat();
const plans = ref([]);
const meta = ref({ current_page: 1, last_page: 1 });
const loading = ref(false);

async function fetchCards(p = 1) {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: p, search: props.initialSearch });
        const json = await fetch(`${props.cardsUrl}?${params}`, { headers: { Accept: 'application/json' } }).then((r) =>
            r.json(),
        );
        plans.value = json.data;
        meta.value = json.meta;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.initialSearch,
    () => fetchCards(1),
);

let removeSuccessListener;
onMounted(() => {
    fetchCards(1);
    removeSuccessListener = router.on('success', () => fetchCards(meta.value.current_page));
});
onUnmounted(() => removeSuccessListener?.());
</script>

<template>
    <!-- Loading -->
    <LoadingSpinner v-if="loading" :label="t.loading" />

    <template v-else>
        <!-- Empty state -->
        <div v-if="plans.length === 0" class="text-center text-muted py-5">
            <i class="ti ti-box fs-1 mb-2 d-block"></i>
            <p>{{ t.empty_list }}</p>
        </div>

        <!-- Cards grid -->
        <div v-else class="row g-3">
            <div v-for="p in plans" :key="p.id" class="col-sm-6 col-xl-4">
                <div class="card card-body h-100">
                    <div class="d-flex align-items-start gap-3">
                        <div
                            class="avatar-sm rounded-circle bg-info-subtle d-flex align-items-center justify-content-center flex-shrink-0"
                            style="width: 44px; height: 44px"
                        >
                            <i class="ti ti-box text-info fs-18"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <h6 class="mb-0 fw-semibold lh-sm">{{ p.name }}</h6>
                                <span v-if="p.is_featured" class="badge badge-soft-warning rounded fs-11">{{
                                    t.featured_badge
                                }}</span>
                                <StatusBadge
                                    :active="p.active"
                                    :label-active="t.status_active"
                                    :label-inactive="t.status_inactive"
                                />
                            </div>
                            <div class="text-muted small mb-2">
                                <ul class="list-unstyled mb-0">
                                    <li v-if="!p.prices?.length" class="text-warning-emphasis">
                                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                        >{{ t.no_sellable_cycle }}
                                    </li>
                                    <li v-for="price in p.prices" :key="price.cycle" :data-cycle="price.cycle">
                                        <span>{{ price.label }}&nbsp;</span>
                                        <strong class="fw-semibold text-body">{{ money(price.price) }}</strong>
                                        <span
                                            v-if="price.savings_percent > 0"
                                            class="badge badge-soft-success ms-1 fs-11"
                                            >−{{ price.savings_percent }}%</span
                                        >
                                    </li>
                                </ul>
                                <div v-if="p.description" class="mt-1 text-muted" style="font-size: 0.8rem">
                                    {{ p.description }}
                                </div>
                                <Link
                                    :href="route('manager.subscriptions.index', { plan: p.id })"
                                    class="d-inline-flex align-items-center mt-2 small"
                                    :title="t.subscribers_link_title"
                                >
                                    <i class="ti ti-building-hospital me-1" aria-hidden="true"></i>
                                    {{ t.col_subscribers }}: {{ p.subscribers }}
                                </Link>
                            </div>
                        </div>
                    </div>

                    <hr class="my-2" />

                    <ActionIconGroup align="end" gap="tight">
                        <ActionIconButton icon="ti ti-eye" :title="t.action_view" @click="$emit('view', p.id)" />
                        <ActionIconButton icon="ti ti-edit" :title="t.action_edit" @click="$emit('edit', p.id)" />
                        <ActionDropdown btn-class="ee-action-icon ee-action-icon--default" icon="ti ti-dots-vertical">
                            <li v-if="p.active">
                                <Link
                                    :href="route('manager.subscriptions.index', { new: 1, new_plan: p.id })"
                                    class="dropdown-item rounded-1"
                                >
                                    <i class="ti ti-plus me-1" aria-hidden="true"></i> {{ t.action_new_subscription }}
                                </Link>
                            </li>
                            <li>
                                <button class="dropdown-item rounded-1" @click="$emit('toggleActive', p.id, p.active)">
                                    <i :class="`ti me-1 ${p.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                    {{ p.active ? t.action_deactivate : t.action_activate }}
                                </button>
                            </li>
                            <li>
                                <button class="dropdown-item rounded-1 text-danger" @click="$emit('delete', p.id)">
                                    <i class="ti ti-trash me-1"></i> {{ t.action_delete }}
                                </button>
                            </li>
                        </ActionDropdown>
                    </ActionIconGroup>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <CardsPagination :meta="meta" @change="fetchCards" />
    </template>
</template>
