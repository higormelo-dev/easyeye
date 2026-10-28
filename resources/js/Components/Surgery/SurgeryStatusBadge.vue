<script setup>
/**
 * Selo de estado do caso cirúrgico. O texto sempre vem junto da cor (nunca só
 * cor), com `text-bg-*` do Bootstrap para manter o contraste nos dois temas.
 */
defineProps({
    /** { value, label, tone } — SurgicalCaseView::status() */
    status: { type: Object, required: true },
});
</script>

<template>
    <span class="badge" :class="`text-bg-${status.tone}`" :data-status="status.value">{{ status.label }}</span>
</template>
