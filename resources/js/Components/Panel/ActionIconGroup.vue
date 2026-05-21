<script setup>
/**
 * Agrupa múltiplos <ActionIconButton> com espaçamento e alinhamento consistentes.
 *
 * - `gap="tight"`: ícones colados (mesma "ilha"), ideal para 2 ações afins (ver+editar).
 * - `gap="normal"` (default): cada botão fica visualmente separado.
 *
 * `align` controla a horizontal: start (default em cards), end (típico em última coluna de tabela), center.
 *
 * Não toma decisões sobre que ícones mostrar — apenas estiliza o container.
 */
defineProps({
    gap:   { type: String, default: 'normal' }, // tight | normal | wide
    align: { type: String, default: 'start' },  // start | center | end
    wrap:  { type: Boolean, default: false },
});
</script>

<template>
    <div :class="['ee-action-group', `ee-action-group--gap-${gap}`, `ee-action-group--align-${align}`, wrap && 'ee-action-group--wrap']">
        <slot />
    </div>
</template>

<style scoped>
.ee-action-group {
    display: inline-flex;
    align-items: center;
    flex-wrap: nowrap;
}

.ee-action-group--wrap {
    flex-wrap: wrap;
    row-gap: 4px;
}

.ee-action-group--gap-tight  { gap: 2px; }
.ee-action-group--gap-normal { gap: 6px; }
.ee-action-group--gap-wide   { gap: 12px; }

.ee-action-group--align-start  { justify-content: flex-start; }
.ee-action-group--align-center { justify-content: center; }
.ee-action-group--align-end    { justify-content: flex-end; }
</style>
