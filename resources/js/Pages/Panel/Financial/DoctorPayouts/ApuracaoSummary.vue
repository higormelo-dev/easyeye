<script setup>
import { computed, useId } from 'vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Resumo da apuração por tipo (consultas, exames, procedimentos): quantidade,
 * valor cobrado e repasse, com linha de total. Somas em centavos — sem o
 * "0,1 + 0,2" do ponto flutuante.
 */
const props = defineProps({
    summary: { type: Array,  default: () => [] },   // [{ service_type, count, charged, payout }]
    t:       { type: Object, default: () => ({}) },
});

const { money, number, serviceTypePlural, serviceTypeIcon } = useDoctorPayoutFormat(() => props.t);

const titleId = `dp-summary-title-${useId()}`;

const cents = (value) => Math.round(Number(value ?? 0) * 100);

const totals = computed(() => {
    const sum = (props.summary ?? []).reduce((acc, row) => ({
        count:   acc.count + Number(row.count ?? 0),
        charged: acc.charged + cents(row.charged),
        payout:  acc.payout + cents(row.payout),
    }), { count: 0, charged: 0, payout: 0 });

    return { count: sum.count, charged: sum.charged / 100, payout: sum.payout / 100 };
});
</script>

<template>
    <section class="card mb-0" :aria-labelledby="titleId" data-test="summary">
        <div class="card-header">
            <h2 :id="titleId" class="h6 fw-bold mb-0">{{ t.summary_title }}</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden">{{ t.summary_title }}</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ t.summary_type }}</th>
                        <th scope="col" class="text-end">{{ t.summary_count }}</th>
                        <th scope="col" class="text-end">{{ t.summary_charged }}</th>
                        <th scope="col" class="text-end">{{ t.summary_payout }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in summary" :key="row.service_type" data-test="summary-row" :data-type="row.service_type">
                        <th scope="row" class="fw-normal text-nowrap">
                            <i :class="serviceTypeIcon(row.service_type)" class="me-1 text-muted" aria-hidden="true"></i>{{ serviceTypePlural(row.service_type) }}
                        </th>
                        <td class="text-end summary__value">{{ number(row.count) }}</td>
                        <td class="text-end text-nowrap summary__value">{{ money(row.charged) }}</td>
                        <td class="text-end text-nowrap fw-medium summary__value">{{ money(row.payout) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="fw-bold" data-test="summary-total">
                        <th scope="row">{{ t.summary_total }}</th>
                        <td class="text-end summary__value">{{ number(totals.count) }}</td>
                        <td class="text-end text-nowrap summary__value">{{ money(totals.charged) }}</td>
                        <td class="text-end text-nowrap summary__value">{{ money(totals.payout) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</template>

<style scoped>
.summary__value {
    font-variant-numeric: tabular-nums;
}
</style>
