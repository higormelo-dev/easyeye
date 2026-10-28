<script setup>
import { ref } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import PreValidationResult from './PreValidationResult.vue';
import { useDialogKeyboard } from './useDialogKeyboard.js';

/** Resultado da pré-validação TISS (motor anti-glosa) de uma guia. */
const props = defineProps({
    open: { type: Boolean, default: false },
    result: { type: Object, default: null },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const closeRef = ref(null);

useDialogKeyboard(() => props.open, { onEscape: () => emit('close'), focusRef: closeRef });
</script>

<template>
    <OffcanvasPanel :open="open" :width="480" @close="emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-checklist me-2 text-primary" aria-hidden="true"></i>{{ t.pending_result_title }}
            </h5>
        </template>

        <PreValidationResult :result="result" :t="t" />

        <template #footer>
            <button ref="closeRef" type="button" class="btn btn-light" @click="emit('close')">{{ t.btn_close }}</button>
        </template>
    </OffcanvasPanel>
</template>
