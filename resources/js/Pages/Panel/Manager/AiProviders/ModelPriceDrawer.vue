<script setup>
import { computed } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { usePriceFormat } from './usePriceFormat';

/**
 * Detalhes de um modelo do catálogo (dados já vêm na linha — sem requisição
 * extra): preços, origem, trava, conferência com o catálogo e situação no
 * provedor.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    price: { type: Object, default: null },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['close', 'edit']);

const { usd } = usePriceFormat();
const r = computed(() => props.price);

const SOURCES = {
    seed: ['source_seed', 'Padrão'],
    manual: ['source_manual', 'Manual'],
    sync: ['source_sync', 'Sincronizado'],
};

const tr = (key, fallback) => props.t[key] ?? fallback;

const prices = computed(() =>
    r.value
        ? [
              [tr('col_input', 'Entrada'), usd(r.value.input_usd_per_million)],
              [tr('col_output', 'Saída'), usd(r.value.output_usd_per_million)],
              [
                  tr('field_reasoning', 'Raciocínio'),
                  r.value.reasoning_usd_per_million !== null
                      ? usd(r.value.reasoning_usd_per_million)
                      : tr('field_reasoning_same', 'Igual à saída'),
              ],
              [
                  tr('field_tool_call', 'Por chamada de ferramenta'),
                  r.value.tool_call_usd !== null ? usd(r.value.tool_call_usd) : null,
              ],
          ].filter(([, value]) => value)
        : [],
);

const catalog = computed(() => {
    if (!r.value) return [];
    const [sourceKey, sourceFallback] = SOURCES[r.value.source] ?? SOURCES.seed;

    return [
        [tr('field_source', 'Origem'), tr(sourceKey, sourceFallback)],
        [tr('field_locked', 'Preço travado'), r.value.price_locked ? tr('yes', 'Sim') : tr('no', 'Não')],
        [tr('field_synced_at', 'Conferido com o catálogo'), r.value.synced_at],
        [tr('field_effective_from', 'Vigente desde'), r.value.effective_from],
        [tr('field_unlisted_at', 'Não oferecido desde'), r.value.unlisted_at],
    ].filter(([, value]) => value);
});
</script>

<template>
    <OffcanvasPanel :open="open" :width="460" :close-label="t.close" @close="$emit('close')">
        <template #header>
            <div class="min-w-0">
                <h5 class="mb-0 fw-semibold text-break">
                    <i class="ti ti-cpu me-2 text-primary" aria-hidden="true"></i>{{ r?.model }}
                </h5>
                <div v-if="r" class="mt-1 d-flex gap-2 align-items-center flex-wrap">
                    <span class="text-muted small">{{ r.provider_label }}</span>
                    <span v-if="r.active" class="badge bg-success">{{ tr('status_active', 'Ativo') }}</span>
                    <span v-else class="badge bg-secondary">{{ tr('status_inactive', 'Inativo') }}</span>
                    <span v-if="r.in_use" class="badge badge-soft-primary rounded fs-12">{{
                        tr('in_use', 'Em uso')
                    }}</span>
                    <span v-if="r.price_locked" class="badge badge-soft-secondary rounded fs-12"
                        ><i class="ti ti-lock me-1" aria-hidden="true"></i>{{ tr('locked', 'Travado') }}</span
                    >
                </div>
            </div>
        </template>

        <template v-if="r" #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ tr('close', 'Fechar') }}</button>
            <button type="button" class="btn btn-primary" data-price-drawer-edit @click="$emit('edit', r)">
                <i class="ti ti-edit me-1" aria-hidden="true"></i>{{ tr('action_edit_price', 'Editar preço') }}
            </button>
        </template>

        <template v-if="r">
            <p v-if="r.unlisted_at" class="alert alert-warning py-2 small">
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                >{{ tr('model_unlisted', '').replace(':date', r.unlisted_at) }}
            </p>
            <div class="mpd-section">
                <div class="mpd-section__title">
                    <i class="ti ti-coin me-1"></i> {{ tr('section_prices', 'Preço (USD por 1 milhão de tokens)') }}
                </div>
                <div class="mpd-table">
                    <div v-for="[label, value] in prices" :key="label" class="mpd-row">
                        <span class="mpd-label">{{ label }}</span
                        ><span class="mpd-value">{{ value }}</span>
                    </div>
                </div>
            </div>
            <div class="mpd-section mb-0">
                <div class="mpd-section__title">
                    <i class="ti ti-refresh me-1"></i> {{ tr('section_catalog', 'Catálogo') }}
                </div>
                <div class="mpd-table">
                    <div v-for="[label, value] in catalog" :key="label" class="mpd-row">
                        <span class="mpd-label">{{ label }}</span
                        ><span class="mpd-value">{{ value }}</span>
                    </div>
                </div>
                <p v-if="r.price_locked" class="form-text mb-0 mt-2">{{ t.locked_hint }}</p>
            </div>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.mpd-section {
    margin-bottom: 1.5rem;
}
.mpd-section__title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.5rem;
    padding-bottom: 0.25rem;
    border-bottom: 1px solid var(--bs-border-color);
}
.mpd-table {
    display: grid;
    gap: 0.375rem;
}
.mpd-row {
    display: grid;
    grid-template-columns: 190px 1fr;
    gap: 0.5rem;
    font-size: 0.875rem;
    align-items: baseline;
}
.mpd-label {
    font-weight: 600;
    color: var(--bs-body-color);
}
.mpd-value {
    color: var(--bs-secondary-color);
    word-break: break-word;
}
@media (max-width: 575.98px) {
    .mpd-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
}
</style>
