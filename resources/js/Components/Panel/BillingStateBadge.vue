<script setup>
/**
 * BillingStateBadge — Badge para estado de cobrança de assinatura.
 *
 * Props:
 *   badge – classe CSS pré-computada pelo backend (ex: 'badge-soft-success')
 *   label – rótulo legível (ex: 'Pago')
 *   state – valor bruto do estado (usado como fallback para label)
 *
 * Uso mínimo: <BillingStateBadge :badge="row.billing_state_badge" :label="row.billing_state_label" />
 */
defineProps({
    badge: { type: String, default: 'badge-soft-secondary' },
    label: { type: String, default: null },
    state: { type: String, default: null },
});
</script>

<template>
    <span v-if="label || state" :class="`badge ${badge}`">
        {{ label ?? state }}
    </span>
    <span v-else class="text-muted small">—</span>
</template>
