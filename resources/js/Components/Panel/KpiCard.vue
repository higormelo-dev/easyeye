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
 * - Quatro formas: estático (div), link (`href`, Inertia), botão de filtro
 *   (`toggle`: aria-pressed = `active`, emite `click`) ou botão de ação
 *   (`action`: abre algo — sem aria-pressed —, emite `click`).
 * - `tinted` (opcional): faixa, ícone num círculo e, ativo, fundo e contorno
 *   na cor do `tone`, usando os tokens do tema (`--<cor>-rgb`, claro/escuro).
 *   Aceita também orange | purple | pink | teal | indigo | cyan, para telas
 *   com mais estados do que as 6 cores semânticas (ex.: resumo de Assinaturas
 *   do manager).
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '—' },
    icon: { type: String, default: '' },
    /** success | danger | warning | info | primary | secondary */
    tone: { type: String, default: 'secondary' },
    hint: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    href: { type: String, default: '' },
    toggle: { type: Boolean, default: false },
    action: { type: Boolean, default: false },
    active: { type: Boolean, default: false },
    tinted: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    /** Sufixo do data-test do valor (`kpi-<testId>`). */
    testId: { type: String, default: '' },
});

const emit = defineEmits(['click']);

const TONES = ['success', 'danger', 'warning', 'info', 'primary', 'secondary'];
const TINTED_TONES = [...TONES, 'orange', 'purple', 'pink', 'teal', 'indigo', 'cyan'];
const tone = computed(() => {
    const allowed = props.tinted ? TINTED_TONES : TONES;

    return allowed.includes(props.tone) ? props.tone : 'secondary';
});

const tag = computed(() => {
    if (props.href) return Link;
    if (props.toggle || props.action) return 'button';

    return 'div';
});

const attrs = computed(() => {
    if (props.href) return { href: props.href };
    if (props.toggle) return { type: 'button', 'aria-pressed': props.active ? 'true' : 'false' };
    if (props.action) return { type: 'button' };

    return {};
});

const interactive = computed(() => !!props.href || props.toggle || props.action);

function onClick(event) {
    if (props.toggle || props.action) emit('click', event);
}
</script>

<template>
    <component
        :is="tag"
        v-bind="attrs"
        class="card kpi-card border-0 shadow-sm border-start border-3 h-100 w-100 text-start text-decoration-none"
        :class="[
            tinted ? `kpi-card--tone-${tone}` : `border-${tone}`,
            {
                'kpi-card--interactive': interactive,
                'kpi-card--active': active && !tinted,
                'kpi-card--tinted': tinted,
                'kpi-card--tinted-active': tinted && active,
            },
        ]"
        :style="tinted ? { '--kpi-tone-rgb': `var(--${tone}-rgb)` } : undefined"
        :title="hint || undefined"
        @click="onClick"
    >
        <div class="card-body py-3">
            <span v-if="tinted" class="kpi-card__bubble" aria-hidden="true">
                <i v-if="icon" :class="icon"></i>
            </span>
            <span class="small text-muted d-flex align-items-center gap-1">
                <i v-if="icon && !tinted" :class="[icon, `text-${tone}`]" aria-hidden="true"></i>
                <span class="kpi-card__label">{{ label }}</span>
                <i
                    v-if="hint"
                    class="ti ti-info-circle kpi-card__hint-icon"
                    :class="{ 'ms-auto': !tinted }"
                    aria-hidden="true"
                ></i>
            </span>

            <span v-if="loading" class="placeholder-glow d-block mt-1" aria-hidden="true">
                <span class="placeholder col-8"></span>
            </span>
            <span
                v-else
                class="d-block fw-bold fs-5 text-body text-nowrap kpi-card__value"
                :data-test="testId ? `kpi-${testId}` : undefined"
            >
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
    transition:
        transform var(--ee-duration-fast, 150ms) ease,
        box-shadow var(--ee-duration-fast, 150ms) ease;
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
    box-shadow:
        inset 0 0 0 1px var(--bs-border-color),
        var(--bs-box-shadow-sm) !important;
}

.kpi-card--tinted {
    border-left-color: rgb(var(--kpi-tone-rgb)) !important;
    /* O tema dá margin-bottom a todo .card: numa grade com h-100 ela inflava
       a linha e sobrava espaço vazio embaixo do conteúdo. */
    margin-bottom: 0;
}

.kpi-card--tinted .card-body {
    padding: 0.75rem 0.875rem;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    grid-template-rows: auto auto;
    column-gap: 0.75rem;
    /* Topo: o número fica na mesma altura em todos os cards, mesmo quando o
       rótulo de um deles quebra em duas linhas. */
    align-content: start;
    align-items: start;
}

.kpi-card--tinted .card-body > :not(.kpi-card__bubble) {
    grid-column: 2;
}

.kpi-card--tinted .kpi-card__label {
    white-space: normal;
    line-height: 1.25;
    /* Quebra entre palavras (o tema força quebra dentro da palavra). */
    word-break: normal;
    overflow-wrap: normal;
}

.kpi-card--tinted .kpi-card__value {
    grid-row: 1;
    line-height: 1.2;
}

.kpi-card__bubble {
    grid-row: 1 / span 2;
    align-self: center;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    font-size: 1.125rem;
    background-color: rgba(var(--kpi-tone-rgb), 0.12);
    color: rgb(var(--kpi-tone-rgb));
}

/* Tema escuro: ícone mais claro para manter o contraste sobre o fundo escuro. */
[data-bs-theme='dark'] .kpi-card__bubble {
    background-color: rgba(var(--kpi-tone-rgb), 0.22);
    color: color-mix(in srgb, rgb(var(--kpi-tone-rgb)) 65%, white);
}

/* Ativo (tinted): fundo e contorno da cor do tone. */
.kpi-card--tinted-active {
    background-color: rgba(var(--kpi-tone-rgb), 0.08) !important;
    box-shadow:
        inset 0 0 0 1px rgba(var(--kpi-tone-rgb), 0.45),
        var(--bs-box-shadow-sm) !important;
}

[data-bs-theme='dark'] .kpi-card--tinted-active {
    background-color: rgba(var(--kpi-tone-rgb), 0.16) !important;
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
