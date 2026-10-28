<script setup>
import { useTrans } from '@/composables/useTrans.js';

/**
 * Barra fixa (sticky no rodapé) da Tabela de Preços: quantas linhas mudaram
 * desde o último salvamento + botão Salvar sempre à mão, mesmo com a grade
 * rolada. O status é uma região aria-live (lida sem roubar o foco).
 */
const props = defineProps({
    dirtyCount: { type: Number, default: 0 },
    saving: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** Deixa espaço à direita para o botão flutuante do Assistente de IA. */
    avoidFab: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['save']);

const { tx } = useTrans(() => props.t);
</script>

<template>
    <div
        class="pp-savebar mt-3"
        :class="{ 'pp-savebar--dirty': dirtyCount > 0, 'pp-savebar--fab': avoidFab }"
        role="region"
        :aria-label="tx('savebar_label')"
        data-test="savebar"
    >
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span
                class="me-auto small d-inline-flex align-items-center gap-1"
                role="status"
                aria-live="polite"
                data-test="dirty-count"
            >
                <template v-if="dirtyCount > 0">
                    <i class="ti ti-pencil text-warning" aria-hidden="true"></i>
                    <strong>{{ tx('unsaved', { count: dirtyCount }) }}</strong>
                </template>
                <template v-else>
                    <i class="ti ti-circle-check text-success" aria-hidden="true"></i>
                    <span class="text-body-secondary">{{ tx('no_changes') }}</span>
                </template>
            </span>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                data-test="save"
                :disabled="disabled || saving"
                @click="$emit('save')"
            >
                <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-device-floppy me-1" aria-hidden="true"></i
                >{{ saving ? tx('saving') : tx('save') }}
            </button>
        </div>
    </div>
</template>

<style scoped>
/* Fixa no rodapé da janela enquanto a grade está na tela (abaixo dos modais: z-index 1020). */
.pp-savebar {
    position: sticky;
    bottom: 0.75rem;
    z-index: 1020;
    padding: 0.625rem 1rem;
    background-color: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius-lg);
    box-shadow: var(--bs-box-shadow);
}

.pp-savebar--dirty {
    border-color: var(--bs-warning-border-subtle);
    background-color: var(--bs-warning-bg-subtle);
}

/* Botão flutuante do Assistente de IA (right: 20px, ~56px de largura). */
.pp-savebar--fab {
    padding-right: 5.5rem;
}
</style>
