<script setup>
import { computed, useId } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * "Por dia": receitas, despesas, saldo do dia e saldo acumulado (calculados
 * no servidor, ordem cronológica), rodapé com os totais do período — os
 * mesmos do summary() (pagos + pendentes, sem cancelados). Saldos com sinal
 * explícito: negativo não depende só de cor.
 */
const props = defineProps({
    rows: { type: Array, default: () => [] }, // [{ day (ISO), income, expense, balance, cumulative }]
    summary: { type: Object, default: () => ({}) }, // { income, expense, balance }
    t: { type: Object, default: () => ({}) },
});

const { money, signedMoney, date } = useLocaleFormat();

const headingId = `cf-by-day-${useId()}`;

const c = computed(() => props.t.cashflow ?? {});
</script>

<template>
    <section class="card border-0 shadow-sm" :aria-labelledby="headingId" data-test="by-day">
        <div class="card-header bg-transparent">
            <h2 :id="headingId" class="fs-6 mb-0 fw-semibold">
                <i class="ti ti-calendar me-1 text-primary" aria-hidden="true"></i>{{ c.by_day }}
            </h2>
            <p class="small text-body-secondary mb-0">{{ c.by_day_hint }}</p>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 cf-day-table">
                <caption class="visually-hidden">
                    {{
                        c.by_day
                    }}
                </caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ c.col_day }}</th>
                        <th scope="col" class="text-end">{{ c.col_income }}</th>
                        <th scope="col" class="text-end">{{ c.col_expense }}</th>
                        <th scope="col" class="text-end d-none d-lg-table-cell">{{ c.col_day_balance }}</th>
                        <th scope="col" class="text-end">{{ c.col_cumulative }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td colspan="5" class="text-center text-body-secondary py-4" data-test="day-empty">
                            {{ c.no_day_data }}
                        </td>
                    </tr>
                    <tr v-for="row in rows" :key="row.day" data-test="day-row">
                        <th scope="row" class="fw-medium text-body text-nowrap">
                            <time :datetime="row.day">{{ date(row.day) }}</time>
                        </th>
                        <td class="text-end text-body text-nowrap" data-test="day-income">{{ money(row.income) }}</td>
                        <td class="text-end text-body text-nowrap" data-test="day-expense">{{ money(row.expense) }}</td>
                        <td class="text-end text-body text-nowrap d-none d-lg-table-cell" data-test="day-balance">
                            {{ signedMoney(row.balance) }}
                        </td>
                        <td class="text-end fw-semibold text-body text-nowrap" data-test="day-cumulative">
                            {{ signedMoney(row.cumulative) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length">
                    <tr data-test="day-totals">
                        <th scope="row">{{ c.footer_total }}</th>
                        <td class="text-end fw-semibold text-body text-nowrap" data-test="day-total-income">
                            {{ money(summary.income ?? 0) }}
                        </td>
                        <td class="text-end fw-semibold text-body text-nowrap" data-test="day-total-expense">
                            {{ money(summary.expense ?? 0) }}
                        </td>
                        <td
                            class="text-end fw-semibold text-body text-nowrap d-none d-lg-table-cell"
                            data-test="day-total-balance"
                        >
                            {{ signedMoney(summary.balance ?? 0) }}
                        </td>
                        <td class="text-end fw-bold text-body text-nowrap" data-test="day-total-cumulative">
                            {{ signedMoney(summary.balance ?? 0) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</template>

<style scoped>
.cf-day-table td {
    font-variant-numeric: tabular-nums;
}

.cf-day-table tfoot > tr > * {
    border-top: 2px solid var(--bs-border-color);
    padding: 12px 16px;
}
</style>
