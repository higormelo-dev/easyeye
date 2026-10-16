<script setup>
import { computed, watch } from 'vue';
import { useDictation } from '@/composables/useDictation.js';
import { fill } from '@/Support/clinicalDocuments.js';

/**
 * Botão "Ditar" do laudo (Fase 5). Grava, e o servidor transcreve no provedor
 * aprovado pela política LGPD — ver Support/dictation.js. Só emite o texto: quem
 * usa decide onde inserir. `busy` sobe enquanto grava/transcreve para o pai
 * travar a emissão (o texto que ainda vai chegar não estaria no que foi enviado).
 */
const props = defineProps({
    url: { type: String, required: true },
    maxSeconds: { type: Number, default: 120 },
    disabled: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['text', 'error', 'busy']);

const tt = (key, fallback = '') => props.t?.[key] ?? fallback;

const dictation = useDictation({
    url: props.url,
    maxSeconds: props.maxSeconds,
    tt,
    onText: (text) => emit('text', text),
    onError: (message) => emit('error', message),
});

watch(dictation.busy, (value) => emit('busy', value), { immediate: true });

const clock = computed(() => {
    const seconds = dictation.elapsed.value;

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
});

const label = computed(() => {
    if (dictation.status.value === 'starting') return tt('dictation_starting', 'Preparando…');
    if (dictation.sending.value) return tt('dictation_sending', 'Transcrevendo…');
    if (dictation.recording.value) return `${tt('dictation_stop', 'Parar')} · ${clock.value}`;

    return tt('dictation_start', 'Ditar');
});

const liveText = computed(() => {
    if (dictation.status.value === 'starting') return tt('dictation_starting', 'Preparando…');
    if (dictation.sending.value) return tt('dictation_sending', 'Transcrevendo…');
    if (dictation.recording.value) return fill(tt('dictation_recording', 'Gravando… :time'), { time: clock.value });

    return '';
});

// Desabilitar no meio da gravação (ex.: o pai começou a emitir) descarta o áudio.
watch(
    () => props.disabled,
    (value) => {
        if (value && dictation.busy.value) dictation.cancel();
    },
);

defineExpose({ cancel: dictation.cancel });
</script>

<template>
    <span v-if="dictation.supported.value" class="d-inline-flex align-items-center gap-1">
        <button
            type="button"
            class="btn btn-sm py-0 px-2"
            :class="dictation.recording.value ? 'btn-danger' : 'btn-outline-secondary'"
            style="font-size: 0.72rem"
            :disabled="disabled || dictation.sending.value || dictation.status.value === 'starting'"
            :aria-pressed="dictation.recording.value"
            :title="tt('dictation_start_hint', 'Dite o laudo; o texto entra onde está o cursor.')"
            data-testid="dictation-button"
            @click="dictation.toggle()"
        >
            <span
                v-if="dictation.sending.value"
                class="spinner-border spinner-border-sm me-1"
                style="width: 0.65rem; height: 0.65rem"
            ></span>
            <i
                v-else
                :class="dictation.recording.value ? 'ti ti-player-stop-filled me-1' : 'ti ti-microphone me-1'"
            ></i>
            {{ label }}
        </button>
        <button
            v-if="dictation.recording.value"
            type="button"
            class="btn btn-outline-secondary btn-sm py-0 px-1"
            style="font-size: 0.72rem"
            :title="tt('dictation_cancel', 'Cancelar ditado')"
            :aria-label="tt('dictation_cancel', 'Cancelar ditado')"
            data-testid="dictation-cancel"
            @click="dictation.cancel()"
        >
            <i class="ti ti-x"></i>
        </button>
        <span class="visually-hidden" role="status" aria-live="polite">{{ liveText }}</span>
    </span>
</template>
