<script setup>
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Status de item/fechamento de repasse (pendente, fechado, pago, cancelado):
 * ícone + texto + cor badge-soft — a cor nunca é o único sinal.
 */
const props = defineProps({
    status: { type: String, required: true },
    t: { type: Object, default: () => ({}) },
});

const { statusLabel, statusBadge, statusIcon } = useDoctorPayoutFormat(() => props.t);
</script>

<template>
    <span class="badge rounded fs-11 fw-medium text-nowrap" :class="statusBadge(status)" :data-status="status">
        <i :class="statusIcon(status)" class="me-1" aria-hidden="true"></i>{{ statusLabel(status) }}
    </span>
</template>
