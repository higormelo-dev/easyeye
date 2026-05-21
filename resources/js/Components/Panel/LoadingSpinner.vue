<script setup>
/**
 * LoadingSpinner — Spinner centralizado para estados de carregamento.
 *
 * Props:
 *   label   – texto acessível (visually-hidden)
 *   color   – cor Bootstrap (default "primary")
 *   padding – classe de padding vertical (default "py-5")
 */
defineProps({
    label:   { type: String, default: 'Carregando...' },
    color:   { type: String, default: 'primary' },
    padding: { type: String, default: 'py-5' },
});
</script>

<template>
    <div class="text-center" :class="padding">
        <div :class="`spinner-border text-${color}`" role="status">
            <span class="visually-hidden">{{ label }}</span>
        </div>
    </div>
</template>
