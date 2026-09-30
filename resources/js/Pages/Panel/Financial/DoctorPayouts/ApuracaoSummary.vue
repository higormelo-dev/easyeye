<script setup>
import { computed, useId } from 'vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Resumo da apuração por tipo (consultas, exames, procedimentos): quantidade
 * de atendimentos, base e repasse, com linha de total. Conta só o que tem
 * recebimento (a liberar, fechado, pago) — a previsão fica fora. Os ajustes
 * manuais dos fechamentos entram no total do repasse, para bater com "Já
 * pago" + "Fechado a pagar" + "A liberar". Clicar no tipo emite `open-type`
 * (a página filtra a lista pelas mesmas linhas). Somas em centavos — sem o
 * "0,1 + 0,2" do ponto flutuante.
 */
const props = defineProps({
    summary:     { type: Array,  default: () => [] },   // [{ service_type, count, charged, payout }]
    adjustments: { type: Number, default: 0 },          // soma dos ajustes dos fechamentos listados
    t:           { type: Object, default: () => ({}) },
});

const emit = defineEmits(['open-type']);

const { tx, money, number, serviceTypePlural, serviceTypeIcon } = useDoctorPayoutFormat(() => props.t);

const uid     = useId();
const titleId = `dp-summary-title-${uid}`;
const hintId  = `dp-summary-hint-${uid}`;

const cents = (value) => Math.round(Number(value ?? 0) * 100);

const hasAdjustments = computed(() => cents(props.adjustments) !== 0);

const totals = computed(() => {
    const sum = (props.summary ?? []).reduce((acc, row) => ({
        count:   acc.count + Number(row.count ?? 0),
        charged: acc.charged + cents(row.charged),
        payout:  acc.payout + cents(row.payout),
    }), { count: 0, charged: 0, payout: 0 });

    return { count: sum.count, charged: sum.charged / 100, payout: (sum.payout + cents(props.adjustments)) / 100 };
});
</script>

<template>
    <section class="card mb-0" :aria-labelledby="titleId" :aria-describedby="hintId" data-test="summary">
        <div class="card-header">
            <h2 :id="titleId" class="h6 fw-bold mb-0">{{ t.summary_title }}</h2>
            <p :id="hintId" class="small text-muted mb-0 mt-1" data-test="summary-hint">{{ t.summary_hint }}</p>
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
                            <button
                                type="button"
                                class="btn btn-link btn-sm p-0 text-decoration-none text-reset summary__type"
                                :aria-label="tx('summary_open_type', { type: serviceTypePlural(row.service_type) })"
                                data-test="summary-open-type"
                                @click="emit('open-type', row.service_type)"
                            >
                                <i :class="serviceTypeIcon(row.service_type)" class="me-1 text-muted" aria-hidden="true"></i>{{ serviceTypePlural(row.service_type) }}
                            </button>
                        </th>
                        <td class="text-end summary__value">{{ number(row.count) }}</td>
                        <td class="text-end text-nowrap summary__value">{{ money(row.charged) }}</td>
                        <td class="text-end text-nowrap fw-medium summary__value">{{ money(row.payout) }}</td>
                    </tr>
                    <tr v-if="hasAdjustments" data-test="summary-adjustments">
                        <th scope="row" class="fw-normal text-nowrap">
                            <i class="ti ti-adjustments me-1 text-muted" aria-hidden="true"></i>{{ t.summary_adjustments }}
                        </th>
                        <td class="text-end text-muted">{{ t.none }}</td>
                        <td class="text-end text-muted">{{ t.none }}</td>
                        <td class="text-end text-nowrap fw-medium summary__value">{{ money(adjustments) }}</td>
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

.summary__type:hover,
.summary__type:focus-visible {
    text-decoration: underline !important;
}
</style>
