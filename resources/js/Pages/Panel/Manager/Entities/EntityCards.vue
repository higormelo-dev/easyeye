<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import LoadingSpinner   from '@/Components/Panel/LoadingSpinner.vue';
import StatusBadge      from '@/Components/Panel/StatusBadge.vue';
import CardsPagination  from '@/Components/Panel/CardsPagination.vue';

const props = defineProps({
    cardsUrl:      { type: String, required: true },
    initialSearch: { type: String, default: '' },
    t:             { type: Object, default: () => ({}) },
});

const emit    = defineEmits(['view', 'edit', 'delete', 'toggleActive']);
const items   = ref([]);
const meta    = ref({ current_page: 1, last_page: 1 });
const loading = ref(false);

async function fetchCards(p = 1) {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: p, search: props.initialSearch });
        const json   = await fetch(`${props.cardsUrl}?${params}`).then(r => r.json());
        items.value = json.data;
        meta.value  = json.meta;
    } finally {
        loading.value = false;
    }
}

watch(() => props.initialSearch, () => fetchCards(1));

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
        <div v-if="items.length === 0" class="text-center text-muted py-5">
            <i class="ti ti-building fs-1 mb-2 d-block"></i>
            <p>{{ t.empty_list }}</p>
        </div>

        <!-- Cards grid -->
        <div v-else class="row g-3">
            <div v-for="e in items" :key="e.id" class="col-sm-6 col-xl-4">
                <div class="card card-body h-100" :class="{ 'border-danger opacity-75': e.deleted }">
                    <div class="d-flex align-items-start gap-3">
                        <div
                            class="avatar-sm rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center flex-shrink-0"
                            style="width:44px;height:44px;"
                        >
                            <i class="ti ti-building text-primary fs-18"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <h6 class="mb-1 fw-semibold lh-sm text-truncate">{{ e.name }}</h6>
                            <div class="mb-1">
                                <StatusBadge
                                    :active="e.active"
                                    :deleted="e.deleted"
                                    :label-active="t.status_active"
                                    :label-inactive="t.status_inactive"
                                    :label-deleted="t.status_deleted"
                                />
                            </div>
                            <address class="small text-muted mb-2">
                                <div><strong>{{ t.card_code }}</strong> {{ e.code }}</div>
                                <div v-if="e.city || e.state">
                                    <strong>{{ t.card_location }}</strong>
                                    {{ e.city || '—' }}{{ e.state ? ` / ${e.state}` : '' }}
                                </div>
                            </address>
                            <div class="d-flex gap-2 mb-2">
                                <span
                                    class="badge badge-soft-secondary rounded fs-12"
                                    :class="{ 'opacity-50': e.entity_users_count === 0 }"
                                    :title="`${e.entity_users_count} ${t.action_users}`"
                                >
                                    <i class="ti ti-users me-1"></i>{{ e.entity_users_count }}
                                </span>
                                <span
                                    class="badge badge-soft-secondary rounded fs-12"
                                    :class="{ 'opacity-50': e.entity_user_integrators_count === 0 }"
                                    :title="`${e.entity_user_integrators_count} ${t.action_user_integrators}`"
                                >
                                    <i class="ti ti-user-cog me-1"></i>{{ e.entity_user_integrators_count }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <hr class="my-2">

                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex gap-1">
                            <a
                                v-if="e.entity_users_count > 0"
                                :href="e.users_url"
                                class="btn btn-xs btn-outline-secondary"
                                :title="t.action_users"
                            ><i class="ti ti-users"></i></a>
                            <a
                                v-if="e.entity_user_integrators_count > 0"
                                :href="e.user_integrators_url"
                                class="btn btn-xs btn-outline-secondary"
                                :title="t.action_user_integrators"
                            ><i class="ti ti-user-cog"></i></a>
                        </div>
                        <div v-if="e.mode === 'full'" class="d-flex gap-1">
                            <button class="btn btn-xs btn-outline-secondary" :title="t.action_view" @click="$emit('view', e.id)">
                                <i class="ti ti-eye"></i>
                            </button>
                            <button class="btn btn-xs btn-outline-secondary" :title="t.action_edit" @click="$emit('edit', e.id)">
                                <i class="ti ti-edit"></i>
                            </button>
                            <div class="dropdown">
                                <button class="btn btn-xs btn-outline-secondary" data-bs-toggle="dropdown">
                                    <i class="ti ti-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end p-2">
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
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <CardsPagination :meta="meta" @change="fetchCards" />
    </template>
</template>
