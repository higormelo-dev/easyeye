<script setup>
import { ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

/**
 * Pede um motivo (mínimo de caracteres) antes de uma ação que muda o estado do
 * caso: cancelar, suspender, retirar o pedido... O envio é do pai (`confirm`).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    label: { type: String, required: true },
    confirmText: { type: String, required: true },
    cancelText: { type: String, required: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    minLength: { type: Number, default: 3 },
    tone: { type: String, default: 'danger' },
});

const emit = defineEmits(['close', 'confirm']);

const reason = ref('');

watch(
    () => props.open,
    (open) => {
        if (open) reason.value = '';
    },
);

function submit() {
    if (reason.value.trim().length < props.minLength || props.busy) return;
    emit('confirm', reason.value.trim());
}
</script>

<template>
    <CenteredModal :open="open" size="md" @close="emit('close')">
        <template #header>{{ title }}</template>

        <form class="p-1" @submit.prevent="submit">
            <label class="form-label fw-medium" for="surgery-reason">{{ label }}</label>
            <textarea
                id="surgery-reason"
                v-model="reason"
                class="form-control"
                :class="{ 'is-invalid': error }"
                rows="4"
                maxlength="500"
                required
                :aria-describedby="error ? 'surgery-reason-error' : undefined"
            ></textarea>
            <div v-if="error" id="surgery-reason-error" class="invalid-feedback d-block">{{ error }}</div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="emit('close')">
                {{ cancelText }}
            </button>
            <button
                type="button"
                class="btn"
                :class="`btn-${tone}`"
                :disabled="busy || reason.trim().length < minLength"
                @click="submit"
            >
                <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ confirmText }}
            </button>
        </template>
    </CenteredModal>
</template>
