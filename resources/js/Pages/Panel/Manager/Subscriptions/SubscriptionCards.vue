<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import LoadingSpinner from '@/Components/Panel/LoadingSpinner.vue';
import BillingStateBadge from '@/Components/Panel/BillingStateBadge.vue';
import CardsPagination from '@/Components/Panel/CardsPagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useSubscriptionPresenter } from './useSubscriptionPresenter.js';

const props = defineProps({
    cardsUrl: { type: String, required: true },
    // Mesmos filtros da tabela (busca, situação, plano, modalidade, vigentes/histórico).
    filters: { type: Object, default: () => ({}) },
    billingCycles: { type: Array, default: () => [] },
    hasFilters: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['view', 'extend', 'change', 'newFor', 'cancel', 'block']);

const { date } = useLocaleFormat();
const { amountText, monthlyText, accessText, dunningText, extendDisabledReason } = useSubscriptionPresenter(
    () => props.t,
    () => props.billingCycles,
);

const subscriptions = ref([]);
const meta = ref({ current_page: 1, last_page: 1 });
const loading = ref(false);

async function fetchCards(page = 1) {
    loading.value = true;
    try {
        const query = Object.fromEntries(
            Object.entries({ ...props.filters, page }).filter(([, v]) => v !== '' && v !== null && v !== undefined),
        );
        const json = await fetch(`${props.cardsUrl}?${new URLSearchParams(query)}`, {
            headers: { Accept: 'application/json' },
        }).then((r) => r.json());
        subscriptions.value = json.data;
        meta.value = json.meta;
    } finally {
        loading.value = false;
    }
}

watch(
    () => JSON.stringify(props.filters),
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
        <div v-if="subscriptions.length === 0" class="text-center text-muted py-5">
            <i class="ti ti-file-invoice fs-1 d-block mb-2 opacity-25" aria-hidden="true"></i>
            <p>{{ hasFilters ? t.empty_filtered : t.empty_list }}</p>
        </div>

        <div v-else class="row g-3">
            <div v-for="s in subscriptions" :key="s.id" class="col-sm-6 col-xl-4">
                <article
                    class="card h-100"
                    :class="{ 'border-warning': s.needs_attention }"
                    :data-modality="s.modality"
                >
                    <div class="card-body d-flex flex-column gap-2">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <h6 class="mb-0 fw-semibold text-truncate" :title="s.entity_name">
                                    {{ s.entity_name }}
                                </h6>
                                <div class="small text-muted">{{ s.plan_name }}</div>
                            </div>
                            <span class="badge flex-shrink-0" :class="s.status_badge">{{ s.status_label }}</span>
                        </div>

                        <div class="d-flex flex-wrap gap-1">
                            <span class="badge badge-soft-primary">{{ t.modality?.[s.modality] }}</span>
                            <span v-if="!s.is_current" class="badge badge-soft-secondary">{{
                                t.historical_badge
                            }}</span>
                            <span v-if="!s.entity_active" class="badge badge-soft-danger">{{ t.blocked_badge }}</span>
                            <span
                                v-if="s.needs_reconciliation"
                                class="badge badge-soft-info"
                                :title="t.needs_review_hint"
                                data-test="sub-needs-review"
                                >{{ t.needs_review_badge }}</span
                            >
                            <span
                                v-if="s.recurrence_alert"
                                class="badge badge-soft-warning"
                                :title="t.recurrence_alert_hint"
                                data-test="sub-recurrence-alert"
                                >{{ t.recurrence_alert_badge }}</span
                            >
                            <BillingStateBadge
                                v-if="s.billing_state"
                                :badge="s.billing_state_badge"
                                :label="s.billing_state_label"
                                :state="s.billing_state"
                            />
                        </div>

                        <dl class="sub-card-grid small mb-0">
                            <dt>{{ t.col_amount }}</dt>
                            <dd>
                                {{ amountText(s) }}
                                <span v-if="monthlyText(s)" class="text-muted d-block">{{ monthlyText(s) }}</span>
                            </dd>
                            <dt>{{ t.detail_access_until }}</dt>
                            <dd>
                                {{ s.open_ended ? t.period_no_end : date(s.access_ends_at) }}
                                <span
                                    v-if="accessText(s)"
                                    class="d-block"
                                    :class="s.days_left < 0 ? 'text-danger-emphasis' : 'text-muted'"
                                    >{{ accessText(s) }}</span
                                >
                                <span
                                    v-if="dunningText(s)"
                                    class="d-block text-danger-emphasis"
                                    data-test="sub-dunning"
                                    >{{ dunningText(s) }}</span
                                >
                            </dd>
                        </dl>

                        <div class="mt-auto pt-2 border-top">
                            <ActionIconGroup align="end" gap="tight">
                                <ActionIconButton
                                    icon="ti ti-eye"
                                    :title="t.action_manage"
                                    @click="$emit('view', s.id)"
                                />
                                <ActionIconButton
                                    icon="ti ti-calendar-plus"
                                    variant="primary"
                                    :title="s.can_extend ? t.action_extend : extendDisabledReason(s)"
                                    :disabled="!s.can_extend"
                                    @click="$emit('extend', s)"
                                />
                                <ActionDropdown
                                    :min-width="230"
                                    btn-class="ee-action-icon ee-action-icon--default"
                                    icon="ti ti-dots-vertical"
                                >
                                    <li>
                                        <button
                                            class="dropdown-item rounded-1"
                                            :disabled="!s.can_change_terms"
                                            @click="$emit('change', s)"
                                        >
                                            <i class="ti ti-adjustments-dollar me-1" aria-hidden="true"></i>
                                            {{ t.action_change }}
                                        </button>
                                    </li>
                                    <li>
                                        <button class="dropdown-item rounded-1" @click="$emit('newFor', s)">
                                            <i class="ti ti-file-plus me-1" aria-hidden="true"></i>
                                            {{ t.action_new_for_company }}
                                        </button>
                                    </li>
                                    <li v-if="s.is_current && (s.is_accessible || s.needs_reconciliation)">
                                        <button
                                            class="dropdown-item rounded-1 text-warning"
                                            @click="$emit('cancel', s)"
                                        >
                                            <i class="ti ti-ban me-1" aria-hidden="true"></i> {{ t.action_cancel }}
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider my-1" /></li>
                                    <li>
                                        <button class="dropdown-item rounded-1" @click="$emit('block', s)">
                                            <i
                                                :class="`ti me-1 ${s.entity_active ? 'ti-lock' : 'ti-lock-open'}`"
                                                aria-hidden="true"
                                            ></i>
                                            {{ s.entity_active ? t.action_block : t.action_unblock }}
                                        </button>
                                    </li>
                                </ActionDropdown>
                            </ActionIconGroup>
                        </div>
                    </div>
                </article>
            </div>
        </div>

        <CardsPagination :meta="meta" @change="fetchCards" />
    </template>
</template>

<style scoped>
.sub-card-grid {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.25rem 0.75rem;
}
.sub-card-grid dt {
    font-weight: 600;
}
.sub-card-grid dd {
    margin: 0;
    text-align: right;
}
</style>
