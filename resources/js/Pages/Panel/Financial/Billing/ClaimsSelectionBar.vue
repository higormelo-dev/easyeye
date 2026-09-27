<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Barra fixa da aba Guias: "N selecionadas · R$ total" (total a receber das
 * marcadas, de qualquer página) e "Registrar recebimento" em lote. O resumo
 * também vai para um aviso ao vivo (leitor de tela ouve cada marcação).
 */
const props = defineProps({
    count:      { type: Number,  default: 0 },
    total:      { type: Number,  default: 0 },
    /** Há marcadas fora da página atual. */
    otherPages: { type: Boolean, default: false },
    t:          { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['receive', 'clear']);

const { tx } = useTrans(() => props.t);
const { money } = useLocaleFormat();

const summary = computed(() => tx('selection_summary', { count: props.count, total: money(props.total) }));
</script>

<template>
    <div>
        <p class="visually-hidden" role="status" aria-live="polite" data-test="selection-live">{{ count > 0 ? summary : '' }}</p>

        <div
            v-if="count > 0"
            class="billing-selection-bar card border shadow mt-3 mb-0"
            role="region"
            :aria-label="t.selection_bar_label"
            data-test="claims-selection-bar"
        >
            <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center gap-2">
                <i class="ti ti-checks text-success" aria-hidden="true"></i>
                <span class="fw-semibold" data-test="selection-summary">{{ summary }}</span>
                <small v-if="otherPages" class="text-muted" data-test="selection-other-pages">{{ t.selection_other_pages }}</small>
                <div class="d-flex flex-wrap gap-2 ms-auto">
                    <button type="button" class="btn btn-light btn-sm" data-test="selection-clear" @click="emit('clear')">
                        {{ t.btn_clear_selection }}
                    </button>
                    <button type="button" class="btn btn-success btn-sm" data-test="selection-receive" @click="emit('receive')">
                        <i class="ti ti-cash me-1" aria-hidden="true"></i>{{ t.btn_bulk_receive }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Fica visível no rodapé da tela enquanto a lista rola (a aba Guias pode ter 50 linhas). */
.billing-selection-bar {
    position: sticky;
    bottom: .75rem;
    z-index: 1020;
}
</style>
