<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import CovenantPlansSection from './CovenantPlansSection.vue';

/**
 * Detalhes do convênio — mesmo drawer de Manager → Medicamentos. Os dados
 * cadastrais vêm na linha do catálogo; o uso pelas clínicas é buscado ao
 * abrir (ajuda a decidir entre desativar e excluir).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    covenant: { type: Object, default: null },
    t: { type: Object, default: () => ({}) },
    tp: { type: Object, default: () => ({}) }, // covenant_plans
});

defineEmits(['close', 'edit', 'plansChanged']);

const { number } = useLocaleFormat();
const c = computed(() => props.covenant);

const registry = computed(() => {
    if (!c.value) return [];

    return [
        [props.t.detail_code, c.value.code],
        [props.t.field_company_name, c.value.company_name],
        [props.t.field_trade_name, c.value.trade_name],
        [props.t.field_cnpj, c.value.cnpj_formatted],
        [props.t.field_ans_registry, c.value.ans_registry],
        [props.t.field_modality, c.value.ans_modality],
        [props.t.col_location, [c.value.city, c.value.uf].filter(Boolean).join(' / ')],
        [props.t.detail_registered_at, c.value.ans_registered_at],
        [props.t.detail_cancelled_at, c.value.ans_cancelled_at],
        [props.t.detail_cancellation_reason, c.value.ans_cancellation_reason],
        [props.t.detail_billing, c.value.table ? props.t.yes : props.t.no],
        [props.t.detail_synced_at, c.value.synced_at],
    ].filter(([, value]) => value);
});

// ── Uso pelas clínicas ───────────────────────────────────────────────────
const usage = ref(null);
const usageState = ref('idle'); // idle | loading | done | error
let usageRequest = 0;

async function loadUsage(id) {
    const request = ++usageRequest;
    usage.value = null;
    usageState.value = 'loading';

    try {
        const { data } = await axios.get(route('manager.covenants.usage', id));
        if (request !== usageRequest) return;
        usage.value = data.data;
        usageState.value = 'done';
    } catch {
        if (request !== usageRequest) return;
        usageState.value = 'error';
    }
}

// Só ao abrir ou trocar de convênio (ativar/editar a linha não rebusca).
watch(
    () => (props.open && props.covenant ? props.covenant.id : null),
    (id) => {
        if (id) loadUsage(id);
        else usageRequest++; // fechou: resposta atrasada é descartada
    },
    { immediate: true },
);

const usageRows = computed(() =>
    usage.value
        ? [
              [props.t.usage_clinics, usage.value.clinics],
              [props.t.usage_patients, usage.value.patients],
              [props.t.usage_schedules, usage.value.schedules],
              [props.t.usage_claims, usage.value.claims],
              [props.t.usage_prices, usage.value.prices],
          ]
        : [],
);
</script>

<template>
    <OffcanvasPanel :open="open" :width="440" :close-label="t.close" @close="$emit('close')">
        <!-- Header -->
        <template #header>
            <div class="min-w-0">
                <h5 class="mb-0 fw-semibold d-flex align-items-center gap-2">
                    <span
                        class="rounded-circle flex-shrink-0 border"
                        :style="{ width: '14px', height: '14px', background: c?.color || 'transparent' }"
                        aria-hidden="true"
                    ></span>
                    <span class="text-break">{{ c?.name }}</span>
                </h5>
                <div v-if="c" class="mt-1 d-flex gap-2 align-items-center flex-wrap">
                    <span v-if="c.active" class="badge bg-success">{{ t.status_active }}</span>
                    <span v-else class="badge bg-secondary">{{ t.status_inactive }}</span>
                    <span
                        class="badge rounded fs-12"
                        :class="c.source === 'ans' ? 'badge-soft-secondary' : 'badge-soft-primary'"
                        >{{ c.source_label }}</span
                    >
                    <span v-if="c.is_particular" class="badge badge-soft-info rounded fs-12">{{
                        t.particular_badge
                    }}</span>
                    <span v-if="c.ans_status === 'cancelled'" class="badge badge-soft-warning rounded fs-12">{{
                        t.cancelled_badge
                    }}</span>
                </div>
            </div>
        </template>

        <template v-if="c" #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ t.close }}</button>
            <button type="button" class="btn btn-primary" @click="$emit('edit', c)">
                <i class="ti ti-edit me-1"></i>{{ t.edit }}
            </button>
        </template>

        <!-- Body -->
        <template v-if="c">
            <div v-if="c.ans_status === 'cancelled'" class="alert alert-warning py-2 small">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.cancelled_hint }}
            </div>

            <div class="cdd-section">
                <div class="cdd-section__title">
                    <i class="ti ti-file-description me-1"></i> {{ t.section_registry }}
                </div>
                <div class="cdd-table">
                    <div v-for="[label, value] in registry" :key="label" class="cdd-row">
                        <span class="cdd-label">{{ label }}</span
                        ><span class="cdd-value">{{ value }}</span>
                    </div>
                </div>
            </div>

            <div class="cdd-section" aria-live="polite">
                <div class="cdd-section__title"><i class="ti ti-chart-bar me-1"></i> {{ t.section_usage }}</div>
                <div v-if="usageState === 'loading'" class="text-center py-3">
                    <span class="spinner-border spinner-border-sm text-muted" role="status"></span>
                </div>
                <div v-else-if="usageState === 'error'" class="text-danger small">{{ t.usage_failed }}</div>
                <template v-else-if="usageState === 'done'">
                    <div class="cdd-table">
                        <div v-for="[label, value] in usageRows" :key="label" class="cdd-row">
                            <span class="cdd-label">{{ label }}</span
                            ><span class="cdd-value">{{ number(value ?? 0) }}</span>
                        </div>
                    </div>
                    <p v-if="!c.is_particular" class="text-muted small mt-2 mb-0">{{ t.usage_deactivate_hint }}</p>
                </template>
            </div>

            <!-- PARTICULAR não tem planos. -->
            <div v-if="!c.is_particular" class="cdd-section">
                <div class="cdd-section__title"><i class="ti ti-list-details me-1"></i> {{ t.section_plans }}</div>
                <CovenantPlansSection :covenant="c" :t="t" :tp="tp" @changed="$emit('plansChanged')" />
            </div>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.cdd-section {
    margin-bottom: 1.5rem;
}
.cdd-section__title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.cdd-table {
    display: grid;
    gap: 0.375rem;
}
.cdd-row {
    display: grid;
    grid-template-columns: 170px 1fr;
    gap: 0.5rem;
    font-size: 0.875rem;
    align-items: baseline;
}
.cdd-label {
    font-weight: 600;
    color: var(--bs-body-color);
}
.cdd-value {
    color: var(--bs-secondary-color);
    word-break: break-word;
}
@media (max-width: 575.98px) {
    .cdd-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
}
</style>
