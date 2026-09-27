<script setup>
import PreValidationResult from './PreValidationResult.vue';

/**
 * Resultado por guia de "Adicionar guias", "Incluir em lote" e "Reprocessar
 * pendentes" (resposta de BillingBulkActionsController): o resumo do
 * servidor e, por guia, se entrou no lote e a pré-validação (erros que a
 * deixaram de fora; avisos que não barram). Quem usa põe dentro de um aviso
 * ao vivo e move o foco para cá.
 */
defineProps({
    /** { message, attached_count, pending_count, results: [{ claim_id, code, patient_name, attached, validation }] } */
    result: { type: Object, default: null },
    t:      { type: Object, default: () => ({}) },
});

function hasIssues(item) {
    return Boolean(item?.validation?.errors?.length || item?.validation?.warnings?.length);
}

function messageClass(result) {
    if (!result.pending_count) return 'alert-success';

    return result.attached_count ? 'alert-warning' : 'alert-danger';
}
</script>

<template>
    <div v-if="result" data-test="attach-result">
        <div :class="['alert small py-2', messageClass(result)]" data-test="attach-message">{{ result.message }}</div>

        <ul class="list-unstyled mb-0" :aria-label="t.attach_result_title">
            <li v-for="item in result.results" :key="item.claim_id" class="border rounded p-2 mb-2" data-test="attach-result-item">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="fw-semibold">{{ item.code }}</span>
                    <span class="small text-muted">{{ item.patient_name || '—' }}</span>
                    <span
                        :class="['badge ms-auto', item.attached ? 'badge-soft-success' : 'badge-soft-warning']"
                        data-test="attach-result-status"
                    >
                        <i :class="['ti me-1', item.attached ? 'ti-circle-check' : 'ti-alert-triangle']" aria-hidden="true"></i>{{ item.attached ? t.attach_result_attached : t.attach_result_pending }}
                    </span>
                </div>
                <PreValidationResult v-if="hasIssues(item)" class="mt-2" :result="item.validation" :t="t" />
                <p v-else class="small text-success-emphasis mb-0 mt-1">{{ t.attach_result_clean }}</p>
            </li>
        </ul>
    </div>
</template>
