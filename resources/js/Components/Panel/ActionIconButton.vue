<script setup>
/**
 * Botão de ação compacto, somente ícone, padrão "flat icon button" do design system.
 *
 * Substitui o uso de `btn btn-sm btn-outline-secondary` espalhado pelas listagens.
 * Mantém área de toque acessível (32x32px), tooltip via `title`, e variantes
 * que se mapeiam às cores do design system em resources/css/system/_variables.scss.
 *
 * Suporta:
 *  - <button> (default): emite `click` ao usuário.
 *  - <a> via prop `href`: link nativo, respeita `target`.
 *  - <Link> Inertia via prop `inertiaHref`: navegação SPA preservando state.
 */
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    icon:        { type: String, required: true },
    title:       { type: String, default: '' },
    variant:     { type: String, default: 'default' }, // default | info | success | danger | warning | primary
    disabled:    { type: Boolean, default: false },
    href:        { type: String, default: null },       // link nativo (não-SPA)
    target:      { type: String, default: null },
    inertiaHref: { type: String, default: null },       // Inertia Link
    inertiaMethod: { type: String, default: 'get' },
    as:          { type: String, default: null },       // override: button | a | link
});

const emit = defineEmits(['click']);

const tag = computed(() => {
    if (props.as) return props.as;
    if (props.inertiaHref) return 'link';
    if (props.href) return 'a';
    return 'button';
});

const classes = computed(() => [
    'ee-action-icon',
    `ee-action-icon--${props.variant}`,
    props.disabled && 'ee-action-icon--disabled',
]);

function handleClick(event) {
    if (props.disabled) {
        event.preventDefault();
        event.stopPropagation();
        return;
    }
    emit('click', event);
}
</script>

<template>
    <Link
        v-if="tag === 'link'"
        :href="inertiaHref"
        :method="inertiaMethod"
        :class="classes"
        :title="title"
        :aria-label="title"
        :aria-disabled="disabled ? 'true' : null"
        @click="handleClick"
    >
        <i :class="icon" aria-hidden="true"></i>
    </Link>

    <a
        v-else-if="tag === 'a'"
        :href="disabled ? null : href"
        :target="target"
        :class="classes"
        :title="title"
        :aria-label="title"
        :aria-disabled="disabled ? 'true' : null"
        @click="handleClick"
    >
        <i :class="icon" aria-hidden="true"></i>
    </a>

    <button
        v-else
        type="button"
        :class="classes"
        :title="title"
        :aria-label="title"
        :disabled="disabled"
        @click="handleClick"
    >
        <i :class="icon" aria-hidden="true"></i>
    </button>
</template>

<style>
/* Classes globais (sem scoped) para que componentes irmãos como ActionDropdown
   possam reaproveitar via prop btn-class, mantendo visual consistente em toda
   a action group. Prefixo `ee-action-icon` evita colisão com outros estilos. */
.ee-action-icon {
    /* Tamanho/área de toque acessível mas compacto: 32x32 com ícone 16px. */
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 8px;
    border: 1px solid var(--gray-100);
    background: var(--white);
    color: var(--gray-700);
    font-size: 15px;
    line-height: 1;
    cursor: pointer;
    transition: background-color .15s ease, color .15s ease, border-color .15s ease, transform .12s ease;
    text-decoration: none;
}

.ee-action-icon:hover:not(.ee-action-icon--disabled) {
    background: var(--primary-transparent);
    color: var(--primary);
    border-color: var(--primary-transparent);
}

.ee-action-icon:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

.ee-action-icon:active:not(.ee-action-icon--disabled) {
    transform: translateY(1px);
}

.ee-action-icon--disabled {
    opacity: .45;
    cursor: not-allowed;
    pointer-events: none;
}

/* Variantes seguem semântica do design system. Apenas pintam o ícone — fundo só
   muda no hover, mantendo aparência flat consistente em listagens. */
.ee-action-icon--info:hover:not(.ee-action-icon--disabled) {
    background: var(--info-transparent);
    color: var(--info);
}

.ee-action-icon--success {
    color: var(--success);
}
.ee-action-icon--success:hover:not(.ee-action-icon--disabled) {
    background: rgba(39, 174, 96, .08);
    color: var(--success);
}

.ee-action-icon--danger {
    color: var(--danger);
}
.ee-action-icon--danger:hover:not(.ee-action-icon--disabled) {
    background: var(--danger-transparent);
    color: var(--danger);
}

.ee-action-icon--warning {
    color: var(--warning);
}
.ee-action-icon--warning:hover:not(.ee-action-icon--disabled) {
    background: rgba(226, 185, 59, .12);
    color: var(--warning-hover);
}

.ee-action-icon--primary {
    color: var(--primary);
}
.ee-action-icon--primary:hover:not(.ee-action-icon--disabled) {
    background: var(--primary-transparent);
    color: var(--primary-hover);
}
</style>
