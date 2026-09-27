<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * KpiCard — indicador (rótulo, valor, subtítulo) no padrão das telas do painel.
 *
 * - Valor sempre em text-body (contraste AA nos dois temas); a cor semântica
 *   (`tone`) fica só no ícone e na borda.
 * - `hint` explica a definição do número (ex.: "inclui pendentes"): aparece como
 *   dica e é lido pelo leitor de tela.
 * - Três formas: estático (div), link (`href`, Inertia) ou botão de filtro
 *   (`toggle`: aria-pressed = `active`, emite `click`).
 */
const props = defineProps({
    label:    { type: String,  required: true },
    value:    { type: [String, Number], default: '—' },
    icon:     { type: String,  default: '' },
    /** success | danger | warning | info | primary | secondary */
    tone:     { type: String,  default: 'secondary' },
    hint:     { type: String,  default: '' },
    subtitle: { type: String,  default: '' },
    href:     { type: String,  default: '' },
    toggle:   { type: Boolean, default: false },
    active:   { type: Boolean, default: false },
    loading:  { type: Boolean, default: false },
    /** Sufixo do data-test do valor (`kpi-<testId>`). */
    testId:   { type: String,  default: '' },
});

const emit = defineEmits(['click']);

const TONES = ['success', 'danger', 'warning', 'info', 'primary', 'secondary'];
const tone  = computed(() => (TONES.includes(props.tone) ? props.tone : 'secondary'));

const tag = computed(() => {
    if (props.href) return Link;
    if (props.toggle) return 'button';

    return 'div';
});

const attrs = computed(() => {
    if (props.href) return { href: props.href };
    if (props.toggle) return { type: 'button', 'aria-pressed': props.active ? 'true' : 'false' };

    return {};
});

const interactive = computed(() => !!props.href || props.toggle);

function onClick(event) {
    if (props.toggle) emit('click', event);
}
</script>

<template>
    <component
        :is="tag"
        v-bind="attrs"
        class="card kpi-card border-0 shadow-sm border-start border-3 h-100 w-100 text-start text-decoration-none"
        :class="[`border-${tone}`, { 'kpi-card--interactive': interactive, 'kpi-card--active': active }]"
        :title="hint || undefined"
        @click="onClick"
    >
        <div class="card-body py-3">
            <span class="small text-muted d-flex align-items-center gap-1">
                <i v-if="icon" :class="[icon, `text-${tone}`]" aria-hidden="true"></i>
                <span class="kpi-card__label">{{ label }}</span>
                <i v-if="hint" class="ti ti-info-circle ms-auto kpi-card__hint-icon" aria-hidden="true"></i>
            </span>

            <span v-if="loading" class="placeholder-glow d-block mt-1" aria-hidden="true">
                <span class="placeholder col-8"></span>
            </span>
            <span v-else class="d-block fw-bold fs-5 text-body text-nowrap kpi-card__value" :data-test="testId ? `kpi-${testId}` : undefined">
                {{ value }}
            </span>

            <span v-if="subtitle" class="d-block small text-muted">{{ subtitle }}</span>
            <span v-if="hint" class="visually-hidden">{{ hint }}</span>

            <slot />
        </div>
    </component>
</template>

<style scoped>
.kpi-card__label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.kpi-card__value {
    font-variant-numeric: tabular-nums;
}

.kpi-card__hint-icon {
    opacity: 0.6;
}

.kpi-card--interactive {
    cursor: pointer;
    transition: transform var(--ee-duration-fast, 150ms) ease, box-shadow var(--ee-duration-fast, 150ms) ease;
}

.kpi-card--interactive:hover {
    transform: translateY(-1px);
    box-shadow: var(--bs-box-shadow) !important;
}

.kpi-card--interactive:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
}

.kpi-card--active {
    background-color: var(--bs-tertiary-bg);
    box-shadow: inset 0 0 0 1px var(--bs-border-color), var(--bs-box-shadow-sm) !important;
}

@media (prefers-reduced-motion: reduce) {
    .kpi-card--interactive {
        transition: none;
    }

    .kpi-card--interactive:hover {
        transform: none;
    }
}
</style>
