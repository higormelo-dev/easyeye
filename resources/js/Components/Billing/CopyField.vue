<script setup>
import { onBeforeUnmount, ref, useId } from 'vue';

/**
 * Código para copiar (Pix copia e cola, linha digitável do boleto): campo só
 * leitura com rótulo + botão "Copiar". O resultado é anunciado ao leitor de
 * tela (aria-live) e, se a área de transferência falhar, o texto fica
 * selecionado para copiar à mão.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    copyLabel: { type: String, default: 'Copiar' },
    copiedLabel: { type: String, default: 'Copiado!' },
    failedLabel: { type: String, default: '' },
    multiline: { type: Boolean, default: false },
});

const id = `ee-copy-${useId()}`;
const field = ref(null);
const feedback = ref('');
const state = ref('idle'); // idle | copied | failed
let timer = null;

function selectText() {
    field.value?.focus();
    field.value?.select?.();
}

async function copy() {
    clearTimeout(timer);
    try {
        if (!navigator?.clipboard?.writeText) throw new Error('clipboard');
        await navigator.clipboard.writeText(props.value);
        state.value = 'copied';
        feedback.value = props.copiedLabel;
    } catch {
        selectText();
        state.value = 'failed';
        feedback.value = props.failedLabel;
    }
    timer = setTimeout(() => {
        state.value = 'idle';
        feedback.value = '';
    }, 4000);
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="ee-copy-field">
        <label :for="id" class="form-label small fw-semibold mb-1">{{ label }}</label>
        <div class="input-group">
            <textarea
                v-if="multiline"
                :id="id"
                ref="field"
                class="form-control font-monospace small ee-copy-field__value"
                rows="3"
                readonly
                :value="value"
                @focus="$event.target.select()"
            ></textarea>
            <input
                v-else
                :id="id"
                ref="field"
                type="text"
                class="form-control font-monospace"
                readonly
                :value="value"
                @focus="$event.target.select()"
            />
            <button
                type="button"
                class="btn"
                :class="state === 'copied' ? 'btn-success' : 'btn-outline-primary'"
                data-test="copy-button"
                @click="copy"
            >
                <i :class="['ti me-1', state === 'copied' ? 'ti-check' : 'ti-copy']" aria-hidden="true"></i
                >{{ state === 'copied' ? copiedLabel : copyLabel }}
            </button>
        </div>
        <p class="small mb-0 mt-1" :class="state === 'failed' ? 'text-danger' : 'text-success'" aria-live="polite">
            {{ feedback }}
        </p>
    </div>
</template>

<style scoped>
.ee-copy-field__value {
    resize: none;
    word-break: break-all;
}
</style>
