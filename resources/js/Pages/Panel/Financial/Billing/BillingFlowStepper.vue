<script setup>
import { computed } from 'vue';

/**
 * Fluxo do faturamento em uma linha: Atendimento → Guia → Lote → Envio →
 * Recebimento/Glosa. Lista ordenada (o leitor de tela anuncia "1 de 5"); o
 * número e as setas são visuais (aria-hidden). Textos: t.flow_steps.
 */
const props = defineProps({
    t: { type: Object, default: () => ({}) },
});

const STEPS = [
    { key: 'attendance', icon: 'ti-stethoscope' },
    { key: 'claim',      icon: 'ti-file-invoice' },
    { key: 'batch',      icon: 'ti-package' },
    { key: 'submit',     icon: 'ti-send' },
    { key: 'settle',     icon: 'ti-cash' },
];

const steps = computed(() => STEPS.map((step, index) => ({
    ...step,
    number: index + 1,
    title:  props.t.flow_steps?.[step.key]?.title ?? step.key,
    hint:   props.t.flow_steps?.[step.key]?.hint ?? '',
})));
</script>

<template>
    <div class="billing-flow mb-3" data-test="billing-flow">
        <ol class="billing-flow__list list-unstyled d-flex flex-wrap align-items-stretch gap-2 mb-0" :aria-label="t.flow_label">
            <li v-for="step in steps" :key="step.key" class="billing-flow__step d-flex align-items-center gap-2" :data-test="`flow-step-${step.key}`">
                <span class="billing-flow__number" aria-hidden="true">{{ step.number }}</span>
                <span class="d-flex flex-column lh-sm">
                    <span class="fw-semibold small">
                        <i :class="['ti', step.icon, 'me-1 text-primary']" aria-hidden="true"></i>{{ step.title }}
                    </span>
                    <span v-if="step.hint" class="text-muted billing-flow__hint">{{ step.hint }}</span>
                </span>
                <i v-if="step.number < steps.length" class="ti ti-chevron-right text-muted billing-flow__arrow d-none d-md-inline" aria-hidden="true"></i>
            </li>
        </ol>
    </div>
</template>

<style scoped>
.billing-flow__step {
    flex: 1 1 10rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    background-color: var(--bs-body-bg);
}

.billing-flow__number {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 50%;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--bs-primary-text-emphasis);
    background-color: var(--bs-primary-bg-subtle);
}

.billing-flow__hint {
    font-size: 0.75rem;
}

.billing-flow__arrow {
    margin-left: auto;
}
</style>
