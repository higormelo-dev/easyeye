<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    cardsUrl:      { type: String, required: true },
    initialSearch: { type: String, default: '' },
    t:             { type: Object, default: () => ({}) },
});

const emit = defineEmits(['edit', 'delete', 'restore', 'toggle-active']);

const users   = ref([]);
const meta    = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(false);

async function fetchCards(page = 1) {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page, search: props.initialSearch });
        const json   = await fetch(`${props.cardsUrl}?${params}`).then(r => r.json());
        users.value = json.data;
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
    <div v-if="loading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status"></div>
    </div>

    <template v-else>
        <!-- Empty state -->
        <div v-if="users.length === 0" class="text-center text-muted py-5">
            <i class="ti ti-users fs-1 mb-2 d-block opacity-40"></i>
            <p class="small mb-0">{{ t.empty }}</p>
        </div>

        <!-- Cards grid -->
        <div v-else class="row g-3">
            <div v-for="u in users" :key="u.id" class="col-sm-6 col-md-4 col-xl-3">
                <div class="card card-body h-100" :class="{ 'opacity-75': u.deleted }">

                    <!-- Avatar + name -->
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <img
                            :src="u.photo_url"
                            :alt="u.full_name"
                            class="rounded-circle flex-shrink-0"
                            style="width:44px;height:44px;object-fit:cover;"
                        >
                        <div class="min-w-0">
                            <h6 class="mb-0 fw-semibold lh-sm text-truncate">{{ u.full_name }}</h6>
                            <span class="badge badge-soft-secondary rounded fs-12">{{ u.rule_label }}</span>
                            <span v-if="u.is_owner" class="badge badge-soft-warning rounded fs-11 ms-1">
                                <i class="ti ti-crown me-1"></i>{{ t.badge_owner }}
                            </span>
                        </div>
                    </div>

                    <!-- Email -->
                    <div class="small text-muted mb-2 text-truncate">{{ u.email }}</div>

                    <!-- Status badge -->
                    <div class="mb-2">
                        <span v-if="u.deleted" class="badge badge-soft-secondary">{{ t.status_deleted }}</span>
                        <span v-else-if="u.active" class="badge badge-soft-success text-success border border-success">
                            {{ t.status_active }}
                        </span>
                        <span v-else class="badge badge-soft-danger text-danger border border-danger">
                            {{ t.status_inactive }}
                        </span>
                    </div>

                    <hr class="my-2">

                    <!-- Actions -->
                    <div class="d-flex justify-content-end gap-1">
                        <!-- Restore -->
                        <template v-if="u.mode === 'restore'">
                            <button
                                class="btn btn-xs btn-outline-secondary"
                                :title="t.btn_restore"
                                @click="$emit('restore', u.id)"
                            >
                                <i class="ti ti-recycle"></i>
                            </button>
                        </template>

                        <!-- Full -->
                        <template v-else-if="u.mode === 'full'">
                            <button
                                class="btn btn-xs btn-outline-secondary"
                                :title="t.btn_edit"
                                @click="$emit('edit', u.id)"
                            >
                                <i class="ti ti-edit"></i>
                            </button>
                            <template v-if="!u.is_owner && !u.is_self">
                                <button
                                    class="btn btn-xs btn-outline-secondary"
                                    :title="u.active ? t.btn_deactivate : t.btn_activate"
                                    @click="$emit('toggle-active', u.id, u.active)"
                                >
                                    <i :class="`ti ${u.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                </button>
                                <button
                                    class="btn btn-xs btn-outline-danger"
                                    :title="t.btn_delete"
                                    @click="$emit('delete', u.id)"
                                >
                                    <i class="ti ti-trash"></i>
                                </button>
                            </template>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <nav v-if="meta.last_page > 1" class="d-flex justify-content-center mt-3">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ disabled: meta.current_page === 1 }">
                    <button class="page-link" @click="fetchCards(meta.current_page - 1)">
                        <i class="ti ti-arrow-left"></i>
                    </button>
                </li>
                <template v-for="p in meta.last_page" :key="p">
                    <li class="page-item" :class="{ active: p === meta.current_page }">
                        <button class="page-link" @click="fetchCards(p)">{{ p }}</button>
                    </li>
                </template>
                <li class="page-item" :class="{ disabled: meta.current_page === meta.last_page }">
                    <button class="page-link" @click="fetchCards(meta.current_page + 1)">
                        <i class="ti ti-arrow-right"></i>
                    </button>
                </li>
            </ul>
        </nav>
    </template>
</template>
