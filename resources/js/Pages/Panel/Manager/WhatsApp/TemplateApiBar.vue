<script setup>
/**
 * Barra da aba Modelos na conta comum da Gupshup: ler o estado dos modelos, criar os que faltam
 * (um por chamada, com intervalo) e o aviso do resultado. A lógica fica em useTemplateApi.js.
 */
defineProps({
    text: { type: Object, required: true },
    loading: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    canCreate: { type: Boolean, default: false },
    missingCount: { type: Number, default: 0 },
    progress: { type: Object, default: null },
    notice: { type: Object, default: null },
});

const emit = defineEmits(['check', 'create-missing']);
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-test="wa-api-bar">
            <button
                type="button"
                class="btn btn-outline-secondary d-inline-flex align-items-center gap-1"
                data-test="wa-check"
                :disabled="busy"
                @click="emit('check')"
            >
                <span v-if="loading" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <i v-else class="ti ti-refresh" aria-hidden="true"></i>
                {{ loading ? text.checking : text.check }}
            </button>
            <button
                type="button"
                class="btn btn-primary d-inline-flex align-items-center gap-1"
                data-test="wa-create-missing"
                :disabled="!canCreate || missingCount === 0"
                @click="emit('create-missing')"
            >
                <i class="ti ti-cloud-upload" aria-hidden="true"></i>
                {{ text.create_missing.replace(':count', missingCount) }}
            </button>
            <span v-if="progress" class="small text-muted" role="status" data-test="wa-progress">{{
                text.progress.replace(':current', progress.current).replace(':total', progress.total)
            }}</span>
            <span class="small text-muted ms-lg-auto">{{ text.hint }}</span>
        </div>

        <div
            v-if="notice"
            :class="['alert', 'small', 'py-2', `alert-${notice.type}`]"
            role="status"
            aria-live="polite"
            data-test="wa-notice"
        >
            {{ notice.text }}
        </div>
    </div>
</template>
