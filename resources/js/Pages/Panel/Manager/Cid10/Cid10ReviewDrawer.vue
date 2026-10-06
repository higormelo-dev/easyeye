<script setup>
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * "Registros a revisar": prontuários e exames que usaram um código cuja
 * descrição antiga no catálogo apontava para outra doença (mesma apuração do
 * comando cid10:audit-records). Resumo por clínica + lista detalhada — sem
 * dado de paciente (clínica, código do registro PMR…/EXM…, CID, texto).
 */
defineProps({
    open: { type: Boolean, required: true },
    summary: { type: Object, default: () => ({ total: 0, clinics: [] }) },
    // null = ainda não carregada (vem sob demanda quando a gaveta abre).
    records: { type: Array, default: null },
    loading: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['close']);

const { number } = useLocaleFormat();
</script>

<template>
    <OffcanvasPanel :open="open" :width="720" :close-label="t.close" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-alert-triangle me-2 text-danger" aria-hidden="true"></i>{{ t.review_title }}
                <span class="badge bg-danger-subtle text-danger-emphasis ms-1">{{ number(summary.total) }}</span>
            </h5>
        </template>

        <template #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ t.close }}</button>
        </template>

        <p class="small text-muted">{{ t.review_intro }}</p>
        <p class="small text-muted"><i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ t.review_privacy }}</p>

        <div v-if="!summary.total" class="text-center text-muted py-4" data-test="review-empty">
            <i class="ti ti-circle-check fs-2 d-block mb-1 text-success" aria-hidden="true"></i>{{ t.review_empty }}
        </div>

        <template v-else>
            <h6 class="fw-semibold mt-3">{{ t.review_by_clinic }}</h6>
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0 small" data-test="review-clinics">
                    <thead class="table-light">
                        <tr>
                            <th>{{ t.review_col_clinic }}</th>
                            <th class="text-end">{{ t.review_col_records }}</th>
                            <th class="text-end">{{ t.review_col_exams }}</th>
                            <th class="text-end">{{ t.review_col_signed }}</th>
                            <th class="text-end">{{ t.review_col_total }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="clinic in summary.clinics" :key="clinic.entity">
                            <td>{{ clinic.entity }}</td>
                            <td class="text-end">{{ number(clinic.records) }}</td>
                            <td class="text-end">{{ number(clinic.exams) }}</td>
                            <td class="text-end">{{ number(clinic.signed) }}</td>
                            <td class="text-end fw-semibold">{{ number(clinic.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h6 class="fw-semibold">{{ t.review_details }}</h6>
            <div v-if="loading || records === null" class="text-muted small py-3" data-test="review-loading">
                <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ t.review_loading }}
            </div>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-0 small" data-test="review-records">
                    <thead class="table-light">
                        <tr>
                            <th>{{ t.review_col_clinic }}</th>
                            <th>{{ t.review_col_kind }}</th>
                            <th>{{ t.review_col_record }}</th>
                            <th>{{ t.review_col_cid }}</th>
                            <th>{{ t.review_col_text }}</th>
                            <th class="text-center">{{ t.review_col_signed }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in records" :key="`${row.code}-${row.cid}-${index}`">
                            <td>{{ row.entity }}</td>
                            <td class="text-nowrap">
                                {{ row.kind === 'exam' ? t.review_kind_exam : t.review_kind_record }}
                            </td>
                            <td class="text-nowrap fw-semibold">{{ row.code ?? '—' }}</td>
                            <td
                                class="text-nowrap"
                                :title="
                                    (t.review_old_vs_new ?? '')
                                        .replace(':old', row.old_text ?? '')
                                        .replace(':official', row.official ?? '')
                                "
                            >
                                {{ row.cid }}
                            </td>
                            <td>{{ row.text }}</td>
                            <td class="text-center">
                                <span v-if="row.signed" class="badge bg-success-subtle text-success-emphasis">{{
                                    t.yes
                                }}</span>
                                <span v-else class="text-muted">{{ t.no }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </OffcanvasPanel>
</template>
