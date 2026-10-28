<script setup>
import { computed } from 'vue';
import { clinicalTexts, tc } from '@/Support/clinicalSafety.js';

/**
 * Selo curto de alergia para LISTAS (card da Agenda, fila do pronto
 * atendimento) — Fase 2 / A1. Texto sempre presente (não só cor):
 * "ALERGIA: Dipirona, Penicilina", "Nega alergias" ou "Sem registro de
 * alergias". `allergy` = { state, items, severe } (PatientClinicalSafetyService::compactFor).
 */
const props = defineProps({
    allergy: { type: Object, default: null },
    t: { type: Object, default: null },
});

const texts = clinicalTexts(props.t);
const tt = (path, fallback) => tc(texts, path, fallback);

const state = computed(() => props.allergy?.state ?? null);
// Gravidade marcada na própria alergia: "Dipirona (grave), Penicilina".
const items = computed(() =>
    (props.allergy?.items ?? [])
        .map((item) =>
            typeof item === 'string'
                ? item
                : item.severe
                  ? `${item.substance} (${tt('banner.severe', 'grave')})`
                  : item.substance,
        )
        .join(', '),
);
const label = computed(() => {
    if (state.value === 'has') return `${tt('banner.allergy_alert', 'ALERGIA')}: ${items.value}`;
    return state.value === 'denied'
        ? tt('banner.denied', 'Nega alergias')
        : tt('banner.no_record', 'Sem registro de alergias');
});
</script>

<template>
    <span
        v-if="state"
        class="badge cs-allergy-badge"
        :class="`cs-allergy-badge--${state}`"
        :title="label"
        :data-testid="`allergy-badge-${state}`"
    >
        <i
            class="ti me-1"
            :class="state === 'has' ? 'ti-alert-triangle' : state === 'denied' ? 'ti-circle-check' : 'ti-help-circle'"
            aria-hidden="true"
        ></i
        >{{ label }}
    </span>
</template>

<style scoped>
.cs-allergy-badge {
    max-width: 100%;
    white-space: normal;
    text-align: left;
    font-weight: 600;
    border: 1px solid transparent;
}
.cs-allergy-badge--has {
    background: var(--bs-danger, #dc3545);
    color: #fff;
}
/* Em LISTA, "nega" e "sem registro" ficam discretos (contorno, texto) — o
   vermelho da alergia é o que precisa saltar aos olhos. */
.cs-allergy-badge--denied,
.cs-allergy-badge--unknown {
    background: transparent;
    color: var(--bs-secondary-color, #6c757d);
    border-color: var(--bs-border-color, #dee2e6);
    font-weight: 500;
}
.cs-allergy-badge--unknown {
    border-style: dashed;
}
</style>
