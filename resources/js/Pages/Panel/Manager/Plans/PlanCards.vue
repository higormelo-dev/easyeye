<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import LoadingSpinner  from '@/Components/Panel/LoadingSpinner.vue';
import StatusBadge     from '@/Components/Panel/StatusBadge.vue';
import CardsPagination from '@/Components/Panel/CardsPagination.vue';

const props = defineProps({
    cardsUrl:      { type: String, required: true },
    initialSearch: { type: String, default: '' },
    t:             { type: Object, default: () => ({}) },
});

const emit   = defineEmits(['view', 'edit', 'delete', 'toggleActive']);
const plans  = ref([]);
const meta   = ref({ current_page: 1, last_page: 1 });
const loading = ref(false);

async function fetchCards(p = 1) {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: p, search: props.initialSearch });
        const json   = await fetch(`${props.cardsUrl}?${params}`).then(r => r.json());
        plans.value = json.data;
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
                            style="width:44px;height:44px;"
                        >
                            <i class="ti ti-box text-info fs-18"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <h6 class="mb-0 fw-semibold lh-sm">{{ p.name }}</h6>
                                <StatusBadge
                                    :active="p.active"
                                    :label-active="t.status_active"
                                    :label-inactive="t.status_inactive"
                                />
                            </div>
                            <div class="text-muted small mb-2">
                                <div>
                                    <strong class="fw-semibold text-body">{{ p.price }}</strong>
                                    <span class="ms-1 badge badge-soft-info rounded fs-11">{{ p.billing_cycle }}</span>
                                </div>
                                <div v-if="p.description" class="mt-1 text-muted" style="font-size:.8rem;">
                                    {{ p.description }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-2">

                    <div class="d-flex justify-content-end gap-1">
                        <button class="btn btn-xs btn-outline-secondary" :title="t.action_view" @click="$emit('view', p.id)">
                            <i class="ti ti-eye"></i>
                        </button>
                        <button class="btn btn-xs btn-outline-secondary" :title="t.action_edit" @click="$emit('edit', p.id)">
                            <i class="ti ti-edit"></i>
                        </button>
                        <div class="dropdown">
                            <button class="btn btn-xs btn-outline-secondary" data-bs-toggle="dropdown">
                                <i class="ti ti-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end p-2">
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
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <CardsPagination :meta="meta" @change="fetchCards" />
    </template>
</template>
