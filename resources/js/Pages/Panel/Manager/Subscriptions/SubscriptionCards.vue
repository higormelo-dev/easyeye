<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import LoadingSpinner    from '@/Components/Panel/LoadingSpinner.vue';
import BillingStateBadge from '@/Components/Panel/BillingStateBadge.vue';
import CardsPagination   from '@/Components/Panel/CardsPagination.vue';

const props = defineProps({
    cardsUrl:      { type: String, required: true },
    initialSearch: { type: String, default: '' },
    t:             { type: Object, default: () => ({}) },
});

const emit = defineEmits(['view', 'edit', 'activate', 'trial', 'cancel', 'block']);

const subscriptions = ref([]);
const meta          = ref({ current_page: 1, last_page: 1 });
const loading       = ref(false);

async function fetchCards(page = 1) {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page, search: props.initialSearch });
        const json   = await fetch(`${props.cardsUrl}?${params}`).then(r => r.json());
        subscriptions.value = json.data;
        meta.value          = json.meta;
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

defineExpose({ fetchCards });
</script>

<template>
    <LoadingSpinner v-if="loading" :label="t.loading" />

    <template v-else>
        <!-- Empty -->
        <div v-if="subscriptions.length === 0" class="text-center text-muted py-5">
            <i class="fas fa-file-contract fa-3x mb-3 opacity-25"></i>
            <p>{{ t.empty_list }}</p>
        </div>

        <!-- Grid -->
        <div v-else class="row g-3">
            <div v-for="s in subscriptions" :key="s.id" class="col-sm-6 col-xl-4">
                <div
                    class="card card-body h-100 position-relative"
                    :class="s.needs_attention ? 'border-danger border-2' : ''"
                >
                    <!-- Atenção -->
                    <span v-if="s.needs_attention" class="position-absolute top-0 end-0 m-2">
                        <span class="badge badge-soft-danger">
                            <i class="ti ti-alert-triangle me-1"></i>{{ t.attention_badge }}
                        </span>
                    </span>

                    <div class="d-flex align-items-start gap-3">
                        <!-- Avatar -->
                        <div
                            class="avatar-sm rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                            :class="s.entity_active ? 'bg-success-subtle' : 'bg-danger-subtle'"
                            style="width:44px;height:44px;"
                        >
                            <i
                                class="fas fa-file-contract fs-16"
                                :class="s.entity_active ? 'text-success' : 'text-danger'"
                            ></i>
                        </div>

                        <div class="flex-grow-1 min-w-0">
                            <h6 class="mb-1 fw-semibold text-truncate">{{ s.entity_name }}</h6>

                            <!-- Badges -->
                            <div class="mb-2 d-flex flex-wrap gap-1">
                                <span class="badge" :class="s.status_badge">{{ s.status_label }}</span>
                                <BillingStateBadge
                                    v-if="s.billing_state"
                                    :badge="s.billing_state_badge"
                                    :label="s.billing_state_label"
                                />
                            </div>

                            <!-- Informações -->
                            <div class="small text-muted">
                                <div><strong>Plano:</strong> {{ s.plan_name }}</div>
                                <div v-if="s.gateway">
                                    <strong>Gateway:</strong>
                                    <span class="text-uppercase fw-semibold ms-1">{{ s.gateway }}</span>
                                </div>
                                <div><strong>Início:</strong> {{ s.starts_at ?? '—' }}</div>
                                <div><strong>Vencimento:</strong> {{ s.ends_at ?? '∞' }}</div>
                                <div v-if="s.next_billing_at">
                                    <strong>Próxima cobrança:</strong> {{ s.next_billing_at }}
                                </div>
                                <div v-if="s.trial_ends_at">
                                    <strong>Trial até:</strong> {{ s.trial_ends_at }}
                                </div>
                            </div>

                            <!-- Erro de cobrança -->
                            <div v-if="s.last_billing_error" class="alert alert-danger py-1 px-2 small mt-2 mb-0">
                                <i class="ti ti-alert-circle me-1"></i>{{ s.last_billing_error }}
                            </div>
                        </div>
                    </div>

                    <hr class="my-2">

                    <!-- Ações -->
                    <div class="d-flex justify-content-end gap-1">
                        <button
                            class="btn btn-xs btn-outline-secondary"
                            :title="t.action_view"
                            @click="$emit('view', s.id)"
                        ><i class="ti ti-eye"></i></button>

                        <button
                            class="btn btn-xs btn-outline-secondary"
                            :title="t.action_edit"
                            @click="$emit('edit', s.id)"
                        ><i class="ti ti-edit"></i></button>

                        <div class="dropdown">
                            <button class="btn btn-xs btn-outline-secondary" data-bs-toggle="dropdown">
                                <i class="ti ti-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:180px;">
                                <li>
                                    <button class="dropdown-item rounded-1 text-success" @click="$emit('activate', s)">
                                        <i class="ti ti-player-play me-1"></i> {{ t.action_activate }}
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item rounded-1 text-info" @click="$emit('trial', s)">
                                        <i class="ti ti-clock-play me-1"></i> {{ t.action_trial }}
                                    </button>
                                </li>
                                <li v-if="s.is_accessible">
                                    <button class="dropdown-item rounded-1 text-warning" @click="$emit('cancel', s)">
                                        <i class="ti ti-ban me-1"></i> {{ t.action_cancel }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li>
                                    <button class="dropdown-item rounded-1" @click="$emit('block', s)">
                                        <i :class="`ti me-1 ${s.entity_active ? 'ti-lock' : 'ti-lock-open'}`"></i>
                                        {{ s.entity_active ? t.action_block : t.action_unblock }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <CardsPagination :meta="meta" @change="fetchCards" />
    </template>
</template>
