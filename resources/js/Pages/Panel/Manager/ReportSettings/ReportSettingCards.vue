<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import LoadingSpinner  from '@/Components/Panel/LoadingSpinner.vue';
import CardsPagination from '@/Components/Panel/CardsPagination.vue';
import ActionDropdown  from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';

const props = defineProps({
    cardsUrl:      { type: String, required: true },
    initialSearch: { type: String, default: '' },
    initialStatus: { type: String, default: '' },
    initialCategory: { type: String, default: '' },
    t:             { type: Object, default: () => ({}) },
});

const emit = defineEmits(['edit', 'preview', 'publish', 'archive', 'delete']);

const records = ref([]);
const meta    = ref({ current_page: 1, last_page: 1 });
const loading = ref(false);

async function fetchCards(page = 1) {
    loading.value = true;
    try {
        const params = new URLSearchParams({
            page,
            search:      props.initialSearch,
            status:      props.initialStatus,
            category_id: props.initialCategory,
        });
        const json = await fetch(`${props.cardsUrl}?${params}`).then(r => r.json());
        records.value = json.data;
        meta.value    = json.meta;
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.initialSearch, props.initialStatus, props.initialCategory],
    () => fetchCards(1),
);

let removeSuccessListener;
onMounted(() => {
    fetchCards(1);
    removeSuccessListener = router.on('success', () => fetchCards(meta.value.current_page));
});
onUnmounted(() => removeSuccessListener?.());

defineExpose({ fetchCards });
</script>

<template>
    <LoadingSpinner v-if="loading" :label="t.loading" />

    <template v-else>
        <div v-if="records.length === 0" class="text-center text-muted py-5">
            <i class="ti ti-file-off fa-3x mb-3 d-block opacity-25 fs-1"></i>
            <p>{{ t.empty_list }}</p>
        </div>

        <div v-else class="row g-3">
            <div v-for="r in records" :key="r.id" class="col-sm-6 col-xl-4">
                <div class="card card-body h-100">

                    <!-- Header -->
                    <div class="d-flex align-items-start gap-3">
                        <div
                            class="rounded d-flex align-items-center justify-content-center flex-shrink-0 bg-primary-subtle"
                            style="width:44px;height:44px;"
                        >
                            <i class="ti ti-file-text fs-18 text-primary"></i>
                        </div>

                        <div class="flex-grow-1 min-w-0">
                            <h6 class="mb-1 fw-semibold text-truncate">{{ r.title }}</h6>

                            <div class="mb-2 d-flex flex-wrap gap-1">
                                <span class="badge" :class="r.status_badge">{{ r.status_label }}</span>
                                <span v-if="r.category" class="badge badge-soft-secondary rounded fs-12">
                                    {{ r.category }}
                                </span>
                            </div>

                            <div class="small text-muted">
                                <div v-if="r.paper_size"><strong>Papel:</strong> {{ r.paper_size }}</div>
                                <div>
                                    <strong>v{{ r.version }}</strong>
                                    <span v-if="r.adopted_count" class="ms-2 text-muted">
                                        · {{ r.adopted_count }} {{ t.col_adoptions?.toLowerCase() }}
                                    </span>
                                </div>
                            </div>

                            <!-- Feature badges -->
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <span
                                    v-if="r.show_header"
                                    class="badge badge-soft-primary rounded text-primary border border-primary fs-11"
                                >
                                    <i class="ti ti-layout-navbar me-1"></i>{{ t.tab_header }}
                                </span>
                                <span
                                    v-if="r.show_signature"
                                    class="badge badge-soft-info rounded text-info border border-info fs-11"
                                >
                                    <i class="ti ti-signature me-1"></i>{{ t.tab_signature }}
                                </span>
                                <span
                                    v-if="r.show_footer"
                                    class="badge badge-soft-secondary rounded fs-11"
                                >
                                    <i class="ti ti-layout-bottombar me-1"></i>{{ t.tab_footer }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <hr class="my-2">

                    <!-- Actions -->
                    <ActionIconGroup align="end" gap="tight">
                        <ActionIconButton
                            icon="ti ti-file-search"
                            :title="t.action_preview"
                            @click="$emit('preview', r)"
                        />
                        <ActionIconButton
                            icon="ti ti-edit"
                            :title="t.action_edit"
                            @click="$emit('edit', r.id)"
                        />
                        <ActionDropdown
                            :min-width="160"
                            btn-class="ee-action-icon ee-action-icon--default"
                            icon="ti ti-dots-vertical"
                        >
                            <li v-if="r.status === 'draft' || r.status === 'archived'">
                                <button
                                    class="dropdown-item rounded-1 text-success"
                                    @click="$emit('publish', r)"
                                >
                                    <i class="ti ti-send me-1"></i> {{ t.action_publish }}
                                </button>
                            </li>
                            <li v-if="r.status === 'published'">
                                <button
                                    class="dropdown-item rounded-1 text-warning"
                                    @click="$emit('archive', r)"
                                >
                                    <i class="ti ti-archive me-1"></i> {{ t.action_archive }}
                                </button>
                            </li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li>
                                <button
                                    class="dropdown-item rounded-1 text-danger"
                                    @click="$emit('delete', r)"
                                >
                                    <i class="ti ti-trash me-1"></i> {{ t.action_delete }}
                                </button>
                            </li>
                        </ActionDropdown>
                    </ActionIconGroup>
                </div>
            </div>
        </div>

        <CardsPagination :meta="meta" @change="fetchCards" />
    </template>
</template>
