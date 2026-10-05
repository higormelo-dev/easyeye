<script setup>
import { ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

const props = defineProps({
    open: { type: Boolean, required: true },
    planId: { type: String, default: null },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['close', 'edit']);

const { money, dateTime } = useLocaleFormat();
const { tx } = useTrans(() => props.t);

const loading = ref(false);
const plan = ref(null);

async function loadDetail(id) {
    loading.value = true;
    plan.value = null;
    try {
        const res = await fetch(route('manager.plans.show', id), { headers: { Accept: 'application/json' } });
        plan.value = (await res.json()).data;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (val) => {
        if (val && props.planId) loadDetail(props.planId);
        if (!val) plan.value = null;
    },
);

function featureValueLabel(feature) {
    if (feature.is_boolean) {
        const ok = feature.value === '1' || feature.value === 1;
        return ok ? props.t.feature_included : props.t.feature_not_included;
    }
    const v = parseInt(feature.value);
    if (v === 0) return feature.zero_means_none ? props.t.feature_not_included : props.t.feature_unlimited;

    return String(v);
}

function featureBadgeClass(feature) {
    if (feature.is_boolean) {
        return feature.value === '1' || feature.value === 1 ? 'bg-success' : 'bg-secondary';
    }
    if (parseInt(feature.value) === 0) return feature.zero_means_none ? 'bg-secondary' : 'bg-info text-dark';

    return 'bg-secondary';
}

const subscriberRows = ['trial', 'gateway', 'complimentary'];
</script>

<template>
    <OffcanvasPanel :open="open" :width="460" :loading="loading" :loading-label="t.loading" @close="$emit('close')">
        <!-- Header -->
        <template #header>
            <div>
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-box me-2 text-info" aria-hidden="true"></i>
                    {{ plan?.name ?? t.loading }}
                </h5>
                <div v-if="plan" class="mt-1 d-flex gap-2 align-items-center flex-wrap">
                    <span v-if="plan.active" class="badge bg-success">{{ t.status_active }}</span>
                    <span v-else class="badge bg-secondary">{{ t.status_inactive }}</span>
                    <span v-if="plan.is_featured" class="badge badge-soft-warning rounded fs-12">
                        <i class="ti ti-star me-1" aria-hidden="true"></i>{{ t.featured_badge }}
                    </span>
                </div>
            </div>
            <button v-if="plan" class="btn btn-sm btn-outline-primary ms-2" @click="$emit('edit', plan.id)">
                <i class="ti ti-edit me-1" aria-hidden="true"></i> {{ t.detail_btn_edit }}
            </button>
        </template>

        <!-- Body -->
        <template v-if="plan">
            <!-- Preços por ciclo -->
            <section class="pdd-section" aria-labelledby="pdd-prices">
                <h6 id="pdd-prices" class="pdd-section__title">
                    <i class="ti ti-receipt me-1" aria-hidden="true"></i> {{ t.section_prices }}
                </h6>
                <ul class="list-unstyled mb-0 pdd-prices">
                    <li v-if="!plan.prices?.length" class="small text-warning-emphasis">
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.no_sellable_cycle }}
                    </li>
                    <li v-for="price in plan.prices" :key="price.cycle" :data-cycle="price.cycle">
                        <div class="d-flex justify-content-between align-items-baseline gap-2">
                            <span class="fw-medium">
                                {{ price.label }}
                                <span
                                    v-if="price.cycle === plan.billing_cycle"
                                    class="badge badge-soft-info ms-1 fs-11"
                                    >{{ t.pricing_default }}</span
                                >
                            </span>
                            <span class="fw-semibold">{{ money(price.price) }}{{ price.period_label }}</span>
                        </div>
                        <div v-if="price.months > 1" class="small text-muted text-end">
                            {{ tx('pricing_monthly_equivalent', { price: money(price.monthly_equivalent) }) }}
                            <span v-if="price.savings_percent > 0" class="text-success">
                                · {{ tx('pricing_savings', { percent: price.savings_percent }) }}
                            </span>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Assinantes -->
            <section class="pdd-section" aria-labelledby="pdd-subscribers">
                <h6 id="pdd-subscribers" class="pdd-section__title">
                    <i class="ti ti-building-hospital me-1" aria-hidden="true"></i> {{ t.section_subscribers }}
                </h6>
                <p v-if="!plan.subscribers.total" class="text-muted small mb-2">{{ t.subscribers_empty }}</p>
                <div v-else class="pdd-table mb-2">
                    <div class="pdd-row">
                        <span class="pdd-label">{{ t.subscribers_total }}</span>
                        <span class="pdd-value fw-semibold text-body">{{ plan.subscribers.total }}</span>
                    </div>
                    <template v-for="key in subscriberRows" :key="key">
                        <div v-if="plan.subscribers[key]" class="pdd-row">
                            <span class="pdd-label fw-normal">{{ t[`subscribers_${key}`] }}</span>
                            <span class="pdd-value">{{ plan.subscribers[key] }}</span>
                        </div>
                    </template>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <Link :href="route('manager.subscriptions.index', { plan: plan.id })" class="btn btn-sm btn-light">
                        <i class="ti ti-list-details me-1" aria-hidden="true"></i>{{ t.action_view_subscriptions }}
                    </Link>
                    <Link
                        v-if="plan.active"
                        :href="route('manager.subscriptions.index', { new: 1, new_plan: plan.id })"
                        class="btn btn-sm btn-outline-primary"
                    >
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.action_new_subscription }}
                    </Link>
                </div>
            </section>

            <!-- Dados -->
            <section class="pdd-section" aria-labelledby="pdd-data">
                <h6 id="pdd-data" class="pdd-section__title">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i> {{ t.tab_data }}
                </h6>
                <div class="pdd-table">
                    <div class="pdd-row">
                        <span class="pdd-label">{{ t.detail_sort_order }}</span>
                        <span class="pdd-value">{{ plan.sort_order }}</span>
                    </div>
                    <div class="pdd-row">
                        <span class="pdd-label">{{ t.detail_featured }}</span>
                        <span class="pdd-value">{{ plan.is_featured ? t.detail_yes : t.detail_no }}</span>
                    </div>
                    <div v-if="plan.description" class="pdd-row">
                        <span class="pdd-label">{{ t.detail_description }}</span>
                        <span class="pdd-value">{{ plan.description }}</span>
                    </div>
                    <div class="pdd-row">
                        <span class="pdd-label">{{ t.detail_created_at }}</span>
                        <span class="pdd-value">{{ dateTime(plan.created_at) }}</span>
                    </div>
                </div>
            </section>

            <!-- Features -->
            <section v-if="plan.features_display?.length" class="pdd-section" aria-labelledby="pdd-features">
                <h6 id="pdd-features" class="pdd-section__title">
                    <i class="ti ti-adjustments me-1" aria-hidden="true"></i> {{ t.section_features }}
                </h6>
                <div class="pdd-table">
                    <div v-for="f in plan.features_display" :key="f.key" class="pdd-row">
                        <span class="pdd-label">{{ f.label }}</span>
                        <span class="pdd-value">
                            <span :class="`badge ${featureBadgeClass(f)}`">{{ featureValueLabel(f) }}</span>
                        </span>
                    </div>
                </div>
            </section>

            <div v-else class="text-muted small text-center py-3">
                <i class="ti ti-adjustments-off d-block mb-1 fs-4" aria-hidden="true"></i>
                {{ t.empty_features }}
            </div>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.pdd-section {
    margin-bottom: 1.5rem;
}
.pdd-section__title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.pdd-prices li {
    padding: 0.4rem 0;
    border-bottom: 1px dashed var(--bs-border-color);
    font-size: 0.875rem;
}
.pdd-prices li:last-child {
    border-bottom: 0;
}
.pdd-table {
    display: grid;
    gap: 0.375rem;
}
.pdd-row {
    display: grid;
    grid-template-columns: 170px 1fr;
    gap: 0.5rem;
    font-size: 0.875rem;
    align-items: baseline;
}
.pdd-label {
    font-weight: 600;
    color: var(--bs-body-color);
}
.pdd-value {
    color: var(--bs-secondary-color);
    word-break: break-word;
}
</style>
