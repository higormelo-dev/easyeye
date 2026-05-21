<script setup>
/**
 * StatusBadge — Badge de status Ativo / Inativo / Excluído.
 *
 * Props:
 *   active   – boolean — se o registro está ativo
 *   deleted  – boolean — se o registro está excluído (soft-delete)
 *   labelActive   – texto para ativo
 *   labelInactive – texto para inativo
 *   labelDeleted  – texto para excluído (só exibido se deleted=true)
 *
 * O badge "Excluído" tem precedência sobre "Inativo".
 */
defineProps({
    active:        { type: Boolean, default: true },
    deleted:       { type: Boolean, default: false },
    labelActive:   { type: String,  default: 'Ativo' },
    labelInactive: { type: String,  default: 'Inativo' },
    labelDeleted:  { type: String,  default: 'Excluído' },
});
</script>

<template>
    <span
        v-if="deleted"
        class="badge badge-soft-danger rounded fs-13"
    >{{ labelDeleted }}</span>
    <span
        v-else-if="active"
        class="badge badge-soft-success rounded text-success border border-success fs-13 fw-medium"
    >{{ labelActive }}</span>
    <span
        v-else
        class="badge badge-soft-secondary rounded fs-13 fw-medium"
    >{{ labelInactive }}</span>
</template>
