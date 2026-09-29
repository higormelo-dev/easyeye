<script setup>
import { computed } from 'vue';
import KpiCard from '@/Components/Panel/KpiCard.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Indicadores da apuração: produção do período (atos, valor cobrado, repasse)
 * e situação do repasse (pago, fechado a pagar, pendente, sem regra). "Sem
 * regra" > 0 fica em destaque e leva às regras — esses itens bloqueiam o
 * fechamento.
 */
const props = defineProps({
    kpis:     { type: Object,  default: () => ({}) },
    rulesUrl: { type: String,  default: '' },
    loading:  { type: Boolean, default: false },
    t:        { type: Object,  default: () => ({}) },
});

const { tx, money, number } = useDoctorPayoutFormat(() => props.t);

const noRule = computed(() => Number(props.kpis?.no_rule ?? 0));

const production = computed(() => {
    const count = number(props.kpis?.production_count ?? 0);

    return [
        { key: 'production', label: props.t.kpi_production, value: count, icon: 'ti ti-list-numbers', tone: 'primary', hint: tx('kpi_production_hint', { count }) },
        { key: 'charged', label: props.t.kpi_charged, value: money(props.kpis?.charged ?? 0), icon: 'ti ti-receipt', tone: 'info' },
        { key: 'payout_total', label: props.t.kpi_payout_total, value: money(props.kpis?.payout_total ?? 0), icon: 'ti ti-user-dollar', tone: 'primary' },
    ];
});

const situation = computed(() => [
    { key: 'paid', label: props.t.kpi_paid, value: money(props.kpis?.paid ?? 0), icon: 'ti ti-circle-check', tone: 'success' },
    { key: 'to_pay', label: props.t.kpi_to_pay, value: money(props.kpis?.to_pay ?? 0), icon: 'ti ti-lock', tone: 'info' },
    { key: 'pending', label: props.t.kpi_pending, value: money(props.kpis?.pending ?? 0), icon: 'ti ti-clock', tone: 'warning' },
    {
        key:   'no_rule',
        label: props.t.kpi_no_rule,
        value: number(noRule.value),
        icon:  'ti ti-alert-octagon',
        tone:  noRule.value > 0 ? 'danger' : 'secondary',
        href:  noRule.value > 0 ? props.rulesUrl : '',
        hint:  noRule.value > 0 ? (props.t.go_to_rules ?? '') : '',
        alert: noRule.value > 0,
    },
]);
</script>

<template>
    <div data-test="kpis">
        <div class="row g-2 row-cols-1 row-cols-sm-3 mb-2">
            <div v-for="kpi in production" :key="kpi.key" class="col" :data-kpi="kpi.key">
                <KpiCard
                    :label="kpi.label"
                    :value="kpi.value"
                    :icon="kpi.icon"
                    :tone="kpi.tone"
                    :hint="kpi.hint ?? ''"
                    :loading="loading"
                    :test-id="kpi.key"
                />
            </div>
        </div>
        <div class="row g-2 row-cols-2 row-cols-lg-4">
            <div v-for="kpi in situation" :key="kpi.key" class="col" :data-kpi="kpi.key">
                <KpiCard
                    :label="kpi.label"
                    :value="kpi.value"
                    :icon="kpi.icon"
                    :tone="kpi.tone"
                    :hint="kpi.hint ?? ''"
                    :href="kpi.href ?? ''"
                    :loading="loading"
                    :test-id="kpi.key"
                    :class="{ 'bg-danger-subtle': kpi.alert }"
                />
            </div>
        </div>
    </div>
</template>
