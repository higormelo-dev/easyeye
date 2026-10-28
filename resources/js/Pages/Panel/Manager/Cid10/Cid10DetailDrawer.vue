<script setup>
import { computed } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { usageLocked } from './cid10Presenter.js';

/**
 * Detalhes do código CID-10 — mesmo drawer de Manager → Medicamentos. Os
 * dados já vêm na linha do catálogo (sem requisição extra): texto exibido ×
 * oficial, classificação e uso agregado pelas clínicas (só contagens).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    code: { type: Object, default: null },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['close', 'edit', 'delete']);

const { number } = useLocaleFormat();

const c = computed(() => props.code);
const locked = computed(() => (c.value ? usageLocked(c.value) : false));

const editedLine = computed(() => {
    if (!c.value?.is_edited || !c.value.edited_at) return '';

    return c.value.edited_by
        ? (props.t.detail_edited_by ?? '').replace(':name', c.value.edited_by).replace(':date', c.value.edited_at)
        : (props.t.detail_edited_at ?? '').replace(':date', c.value.edited_at);
});

const classification = computed(() =>
    c.value
        ? [
              [props.t.detail_chapter, c.value.chapter ? `${c.value.chapter} – ${c.value.chapter_name ?? ''}` : null],
              [props.t.detail_group, c.value.group_name],
              [props.t.detail_category, c.value.category],
              [props.t.detail_source, c.value.is_custom ? props.t.source_custom : props.t.source_datasus],
              [props.t.detail_created_at, c.value.created_at],
          ].filter(([, value]) => value)
        : [],
);

const usageRows = computed(() =>
    c.value
        ? [
              ['records', props.t.detail_records, c.value.usage.records],
              ['exams', props.t.detail_exams, c.value.usage.exams],
              ['clinics', props.t.detail_clinics, c.value.usage.clinics],
              ['links', props.t.detail_links, c.value.usage.links],
          ]
        : [],
);
</script>

<template>
    <OffcanvasPanel :open="open" :width="460" :close-label="t.close" @close="$emit('close')">
        <template #header>
            <div class="min-w-0">
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-stethoscope me-2 text-info" aria-hidden="true"></i>
                    {{ (t.detail_title ?? '').replace(':code', c?.code ?? '') }}
                </h5>
                <div v-if="c" class="mt-1 d-flex gap-2 align-items-center flex-wrap">
                    <span v-if="c.is_custom" class="badge rounded fs-12 badge-soft-purple" :title="t.custom_hint">{{
                        t.badge_custom
                    }}</span>
                    <span v-else class="badge rounded fs-12 badge-soft-success">{{ t.source_datasus }}</span>
                    <span v-if="c.is_edited" class="badge rounded fs-12 badge-soft-warning">{{ t.badge_edited }}</span>
                    <span v-if="c.chapter" class="badge rounded fs-12 badge-soft-secondary">{{ c.chapter }}</span>
                </div>
            </div>
        </template>

        <template v-if="c" #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ t.close }}</button>
            <button
                type="button"
                class="btn btn-outline-danger"
                :disabled="locked"
                :title="
                    locked
                        ? (t.delete_blocked ?? '').replace(':count', number(c.usage.total + c.usage.links))
                        : undefined
                "
                data-test="drawer-delete"
                @click="$emit('delete', c)"
            >
                <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ t.delete }}
            </button>
            <button type="button" class="btn btn-primary" data-test="drawer-edit" @click="$emit('edit', c)">
                <i class="ti ti-edit me-1" aria-hidden="true"></i>{{ t.edit }}
            </button>
        </template>

        <template v-if="c">
            <p v-if="c.is_custom" class="small text-warning-emphasis mb-3" data-test="custom-warning">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.custom_hint }}
            </p>

            <div class="cdd-section">
                <div class="cdd-section__title">
                    <i class="ti ti-file-description me-1" aria-hidden="true"></i> {{ t.section_description }}
                </div>
                <div class="cdd-table">
                    <div class="cdd-row">
                        <span class="cdd-label">{{ t.detail_shown }}</span
                        ><span class="cdd-value" data-test="shown">{{ c.description }}</span>
                    </div>
                    <div class="cdd-row">
                        <span class="cdd-label">{{ t.detail_official }}</span
                        ><span class="cdd-value" data-test="official">{{
                            c.official_description ?? t.detail_official_none
                        }}</span>
                    </div>
                </div>
                <p v-if="editedLine" class="small text-muted mt-2 mb-0">
                    <i class="ti ti-history me-1" aria-hidden="true"></i>{{ editedLine }}
                </p>
            </div>

            <div class="cdd-section">
                <div class="cdd-section__title">
                    <i class="ti ti-sitemap me-1" aria-hidden="true"></i> {{ t.section_classification }}
                </div>
                <div class="cdd-table">
                    <div v-for="[label, value] in classification" :key="label" class="cdd-row">
                        <span class="cdd-label">{{ label }}</span
                        ><span class="cdd-value">{{ value }}</span>
                    </div>
                </div>
            </div>

            <div class="cdd-section">
                <div class="cdd-section__title">
                    <i class="ti ti-building-hospital me-1" aria-hidden="true"></i> {{ t.section_usage }}
                </div>
                <div class="cdd-table">
                    <div v-for="[key, label, value] in usageRows" :key="key" class="cdd-row">
                        <span class="cdd-label" :title="key === 'links' ? t.detail_links_hint : undefined">{{
                            label
                        }}</span
                        ><span class="cdd-value" :data-test="`usage-${key}`">{{ number(value) }}</span>
                    </div>
                </div>
                <p class="small text-muted mt-2 mb-0">
                    <i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ t.detail_usage_note }}
                </p>
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
    grid-template-columns: 150px 1fr;
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
