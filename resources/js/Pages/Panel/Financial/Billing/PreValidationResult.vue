<script setup>
/**
 * Resultado da pré-validação TISS (motor anti-glosa): resumo, erros (barram a
 * entrada no lote TISS) e avisos. Mesmo formato de
 * TissGuidePreValidateController e da resposta de "Corrigir pendência"
 * (BillingClaimActionsController::fixPending). Usado por PendingResultModal e
 * FixPendingModal.
 */
defineProps({
    result: { type: Object, default: null },
    t:      { type: Object, default: () => ({}) },
});
</script>

<template>
    <div v-if="result" data-test="prevalidation-result">
        <p v-if="result.summary" class="small text-muted">{{ result.summary }}</p>

        <div v-if="result.errors?.length" class="mb-3" data-test="prevalidation-errors">
            <h6 class="text-danger-emphasis small fw-semibold">{{ t.errors_label }}</h6>
            <div v-for="(e, i) in result.errors" :key="`err-${i}`" class="alert alert-danger small py-2 mb-2">
                {{ e.message }}
                <div v-if="e.suggestion" class="mt-1 opacity-75">{{ e.suggestion }}</div>
            </div>
        </div>

        <div v-if="result.warnings?.length" data-test="prevalidation-warnings">
            <h6 class="text-warning-emphasis small fw-semibold">{{ t.warnings_label }}</h6>
            <div v-for="(w, i) in result.warnings" :key="`warn-${i}`" class="alert alert-warning small py-2 mb-2">
                {{ w.message }}
                <div v-if="w.suggestion" class="mt-1 opacity-75">{{ w.suggestion }}</div>
            </div>
        </div>

        <p v-if="result.passes && !result.errors?.length && !result.warnings?.length" class="text-success-emphasis small mb-0">
            <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ t.pending_no_issues }}
        </p>
    </div>
</template>
