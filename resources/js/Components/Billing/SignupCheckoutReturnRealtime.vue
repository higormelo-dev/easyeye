<script setup>
import { useBillingRealtime } from '@/composables/useBillingRealtime.js';

const props = defineProps({
    realtime: { type: Object, required: true },
});
const emit = defineEmits(['paid', 'resync']);

// Este componente só é carregado depois de `echo.js` pelo pai. Assim, o
// bundle do site configura o Echo antes de `useBillingRealtime` assinar o
// canal privado, sem levar Echo/Reverb ao SSR.
useBillingRealtime(() => props.realtime, {
    active: () => true,
    onPaid: (payload) => emit('paid', payload),
    onResync: () => emit('resync'),
});
</script>

<template><span class="visually-hidden" aria-hidden="true"></span></template>
