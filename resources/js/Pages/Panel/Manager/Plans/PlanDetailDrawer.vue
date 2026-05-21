<script setup>
import { ref, watch } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:   { type: Boolean, required: true },
    planId: { type: String,  default: null },
    t:      { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close', 'edit']);
const loading = ref(false);
const plan    = ref(null);

async function loadDetail(id) {
    loading.value = true; plan.value = null;
    try {
        const res  = await fetch(route('manager.plans.show', id));
        const json = await res.json();
        plan.value = json.data;
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (val) => {
    if (val && props.planId) loadDetail(props.planId);
    if (!val) plan.value = null;
});

function featureValueLabel(feature) {
    if (feature.is_boolean) {
        const ok = feature.value === '1' || feature.value === 1;
        return ok ? (props.t.feature_included ?? 'Incluído') : (props.t.feature_not_included ?? 'Não incluído');
    }
    const v = parseInt(feature.value);
    return v === 0 ? (props.t.feature_unlimited ?? 'Ilimitado') : String(v);
}

function featureBadgeClass(feature) {
    if (feature.is_boolean) {
        return feature.value === '1' || feature.value === 1 ? 'bg-success' : 'bg-secondary';
    }
    return parseInt(feature.value) === 0 ? 'bg-info text-dark' : 'bg-secondary';
}
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="440"
        :loading="loading"
        :loading-label="t.loading"
        @close="$emit('close')"
    >
        <!-- Header -->
        <template #header>
            <div>
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-box me-2 text-info"></i>
                    {{ plan?.name ?? t.loading }}
                </h5>
                <div v-if="plan" class="mt-1 d-flex gap-2 align-items-center">
                    <span v-if="plan.active" class="badge bg-success">{{ t.status_active }}</span>
                    <span v-else class="badge bg-secondary">{{ t.status_inactive }}</span>
                    <span class="badge badge-soft-info rounded fs-12">{{ plan.billing_label }}</span>
                </div>
            </div>
            <button v-if="plan" class="btn btn-sm btn-outline-primary ms-2" @click="$emit('edit', plan.id)">
                <i class="ti ti-edit me-1"></i> {{ t.detail_btn_edit }}
            </button>
        </template>

        <!-- Body -->
        <template v-if="plan">

            <!-- Section: Precificação -->
            <div class="pdd-section">
                <div class="pdd-section__title"><i class="ti ti-currency-dollar me-1"></i> {{ t.section_pricing }}</div>
                <div class="pdd-table">
                    <div class="pdd-row"><span class="pdd-label">{{ t.detail_price }}</span><span class="pdd-value fw-semibold fs-5 text-body">{{ plan.price_formatted }}</span></div>
                    <div class="pdd-row"><span class="pdd-label">{{ t.detail_cycle }}</span><span class="pdd-value">{{ plan.billing_label }}</span></div>
                    <div class="pdd-row"><span class="pdd-label">{{ t.detail_sort_order }}</span><span class="pdd-value">{{ plan.sort_order }}</span></div>
                    <div v-if="plan.description" class="pdd-row"><span class="pdd-label">{{ t.detail_description }}</span><span class="pdd-value">{{ plan.description }}</span></div>
                    <div class="pdd-row"><span class="pdd-label">{{ t.detail_created_at }}</span><span class="pdd-value">{{ plan.created_at }}</span></div>
                </div>
            </div>

            <!-- Section: Features -->
            <div v-if="plan.features_display && plan.features_display.length" class="pdd-section">
                <div class="pdd-section__title"><i class="ti ti-adjustments me-1"></i> {{ t.section_features }}</div>
                <div class="pdd-table">
                    <div v-for="f in plan.features_display" :key="f.key" class="pdd-row">
                        <span class="pdd-label">{{ f.label }}</span>
                        <span class="pdd-value">
                            <span :class="`badge ${featureBadgeClass(f)}`">{{ featureValueLabel(f) }}</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- No features -->
            <div v-else class="text-muted small text-center py-3">
                <i class="ti ti-adjustments-off d-block mb-1 fs-4"></i>
                {{ t.empty_features }}
            </div>

        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.pdd-section { margin-bottom: 1.5rem; }
.pdd-section__title {
    font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
    color: var(--bs-secondary-color); margin-bottom: .5rem; padding-bottom: .25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.pdd-table { display: grid; gap: .375rem; }
.pdd-row { display: grid; grid-template-columns: 160px 1fr; gap: .5rem; font-size: .875rem; align-items: baseline; }
.pdd-label { font-weight: 600; color: var(--bs-body-color); }
.pdd-value { color: var(--bs-secondary-color); word-break: break-word; }
</style>
