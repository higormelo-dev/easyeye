<script setup>
import { computed } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { cmedSituation } from './cmedSituation.js';

/**
 * Detalhes do medicamento — mesmo drawer de Manager → Planos. Os dados já
 * vêm na linha do catálogo (sem requisição extra).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    medicine: { type: Object, default: null },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['close', 'edit']);

const m = computed(() => props.medicine);
const situation = computed(() => (m.value ? cmedSituation(m.value, props.t) : null));

const registry = computed(() => {
    if (!m.value) return [];
    const isCmed = m.value.source === 'cmed';

    return [
        [props.t.field_active_ingredient, m.value.active_ingredient],
        [props.t.field_concentration, m.value.concentration],
        [props.t.detail_form, m.value.form],
        [props.t.col_presentation, m.value.presentation_detail],
        [props.t.detail_laboratory, m.value.laboratory],
        [props.t.detail_category, m.value.category],
        [props.t.detail_therapeutic, m.value.therapeutic_class],
        [props.t.detail_registration, m.value.anvisa_registration],
        [props.t.detail_ean, m.value.ean],
        [props.t.col_cmed_situation, situation.value?.label],
        [props.t.detail_synced_at, isCmed ? m.value.synced_at : null],
    ].filter(([, value]) => value);
});

const posology = computed(() =>
    m.value
        ? [
              [props.t.field_dosage, m.value.dosage],
              [props.t.field_frequency, m.value.frequency],
              [props.t.field_duration, m.value.duration],
              [props.t.field_instructions, m.value.instructions],
          ].filter(([, value]) => value)
        : [],
);
</script>

<template>
    <OffcanvasPanel :open="open" :width="440" :close-label="t.close" @close="$emit('close')">
        <!-- Header -->
        <template #header>
            <div class="min-w-0">
                <h5 class="mb-0 fw-semibold">
                    <i :class="`ti ${m?.is_ophthalmic ? 'ti-eye' : 'ti-pill'} me-2 text-info`"></i>
                    {{ m?.name }}
                    <span v-if="m?.concentration" class="fw-normal">{{ m.concentration }}</span>
                </h5>
                <div v-if="m" class="mt-1 d-flex gap-2 align-items-center flex-wrap">
                    <span v-if="m.active" class="badge bg-success">{{ t.status_active }}</span>
                    <span v-else class="badge bg-secondary">{{ t.status_inactive }}</span>
                    <span
                        class="badge rounded fs-12"
                        :class="m.source === 'cmed' ? 'badge-soft-secondary' : 'badge-soft-primary'"
                        >{{ m.source_label }}</span
                    >
                    <span v-if="m.is_ophthalmic" class="badge badge-soft-info rounded fs-12">{{ t.ophthalmic }}</span>
                    <span v-if="situation" class="badge rounded fs-12" :class="situation.cls" :title="situation.hint"
                        ><i :class="`ti ${situation.icon} me-1`" aria-hidden="true"></i>{{ situation.label }}</span
                    >
                </div>
            </div>
        </template>

        <!-- Ação no rodapé fixo: o slot #header empilha o que vem depois do
             título, e o botão ficava solto embaixo dos badges. -->
        <template v-if="m" #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ t.close }}</button>
            <button type="button" class="btn btn-primary" @click="$emit('edit', m)">
                <i class="ti ti-edit me-1"></i>{{ m.source === 'cmed' ? t.edit_posology : t.edit }}
            </button>
        </template>

        <!-- Body -->
        <template v-if="m">
            <p v-if="situation && situation.code !== 'marketed'" class="small text-muted mb-3">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ situation.hint }}
            </p>
            <div class="mdd-section">
                <div class="mdd-section__title">
                    <i class="ti ti-file-description me-1"></i> {{ t.section_registry }}
                </div>
                <div class="mdd-table">
                    <div v-for="[label, value] in registry" :key="label" class="mdd-row">
                        <span class="mdd-label">{{ label }}</span
                        ><span class="mdd-value">{{ value }}</span>
                    </div>
                </div>
            </div>

            <div class="mdd-section">
                <div class="mdd-section__title"><i class="ti ti-clipboard-text me-1"></i> {{ t.posology_title }}</div>
                <div v-if="posology.length" class="mdd-table">
                    <div v-for="[label, value] in posology" :key="label" class="mdd-row">
                        <span class="mdd-label">{{ label }}</span
                        ><span class="mdd-value">{{ value }}</span>
                    </div>
                </div>
                <div v-else class="text-muted small text-center py-3">
                    <i class="ti ti-clipboard-off d-block mb-1 fs-4"></i>
                    {{ t.posology_empty }}
                </div>
            </div>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.mdd-section {
    margin-bottom: 1.5rem;
}
.mdd-section__title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.mdd-table {
    display: grid;
    gap: 0.375rem;
}
.mdd-row {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 0.5rem;
    font-size: 0.875rem;
    align-items: baseline;
}
.mdd-label {
    font-weight: 600;
    color: var(--bs-body-color);
}
.mdd-value {
    color: var(--bs-secondary-color);
    word-break: break-word;
}
@media (max-width: 575.98px) {
    .mdd-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
}
</style>
