<script setup>
import { computed } from 'vue';
import AddClaimsModal from './AddClaimsModal.vue';
import AttachToBatchModal from './AttachToBatchModal.vue';
import BatchReceiptModal from './BatchReceiptModal.vue';
import BulkReceiptModal from './BulkReceiptModal.vue';

/**
 * Modais das ações em lote do Faturamento (um aberto por vez), dirigidos pelo
 * Index via `operation`:
 *  - { kind: 'bulk_receipt', claims }  — recebimento das guias selecionadas;
 *  - { kind: 'batch_receipt', batch }  — recebimento do lote;
 *  - { kind: 'add_claims' | 'reprocess', batch } — anexar guias ao lote;
 *  - { kind: 'attach_claim', claim }   — incluir a guia num lote.
 * `done` avisa que o servidor gravou (o modal continua aberto com o resultado).
 */
const props = defineProps({
    operation:      { type: Object, default: null },
    paymentMethods: { type: Array,  default: () => [] },
    today:          { type: String, default: '' },
    bulkReceiptUrl: { type: String, default: '' },
    bulkMaxClaims:  { type: Number, default: 200 },
    t:              { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'done']);

const kind = computed(() => props.operation?.kind ?? null);
</script>

<template>
    <BulkReceiptModal
        :open="kind === 'bulk_receipt'"
        :claims="operation?.claims ?? []"
        :payment-methods="paymentMethods"
        :today="today"
        :url="bulkReceiptUrl"
        :max="bulkMaxClaims"
        :t="t"
        @close="emit('close')"
        @saved="emit('done', 'bulk_receipt')"
    />

    <BatchReceiptModal
        :open="kind === 'batch_receipt'"
        :batch="operation?.batch ?? null"
        :payment-methods="paymentMethods"
        :today="today"
        :t="t"
        @close="emit('close')"
        @saved="emit('done', 'batch_receipt')"
    />

    <AddClaimsModal
        :open="kind === 'add_claims' || kind === 'reprocess'"
        :batch="operation?.batch ?? null"
        :mode="kind === 'reprocess' ? 'reprocess' : 'add'"
        :t="t"
        @close="emit('close')"
        @saved="emit('done', kind)"
    />

    <AttachToBatchModal
        :open="kind === 'attach_claim'"
        :claim="operation?.claim ?? null"
        :t="t"
        @close="emit('close')"
        @saved="emit('done', 'attach_claim')"
    />
</template>
