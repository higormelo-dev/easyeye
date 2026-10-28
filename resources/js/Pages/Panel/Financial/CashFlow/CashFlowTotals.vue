<script setup>
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans';

/**
 * Rodapé com os totais de TODO o conjunto filtrado (todas as páginas, sem
 * cancelados) — vem do overview() do servidor, não da soma da página atual.
 */
const props = defineProps({
    overview: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

const { signedMoney, number } = useLocaleFormat();
const { tx } = useTrans(() => props.t);
</script>

<template>
    <div class="cash-flow-totals d-flex flex-wrap align-items-center gap-2 gap-md-4 small" data-test="totals">
        <span class="text-muted me-md-auto">{{
            tx('footer_label', { count: number(overview.entries_count ?? 0) })
        }}</span>
        <dl class="d-flex flex-wrap gap-2 gap-md-4 mb-0">
            <div class="d-flex gap-1">
                <dt class="fw-normal text-muted">{{ t.footer_income }}</dt>
                <dd class="mb-0 fw-semibold text-body text-nowrap" data-test="total-income">
                    {{ signedMoney(overview.income_total ?? 0) }}
                </dd>
            </div>
            <div class="d-flex gap-1">
                <dt class="fw-normal text-muted">{{ t.footer_expense }}</dt>
                <dd class="mb-0 fw-semibold text-body text-nowrap" data-test="total-expense">
                    {{ signedMoney(-Math.abs(Number(overview.expense_total ?? 0))) }}
                </dd>
            </div>
            <div class="d-flex gap-1">
                <dt class="fw-normal text-muted">{{ t.footer_balance }}</dt>
                <dd class="mb-0 fw-bold text-body text-nowrap" data-test="total-balance">
                    {{ signedMoney(overview.projected_balance ?? 0) }}
                </dd>
            </div>
        </dl>
    </div>
</template>

<style scoped>
.cash-flow-totals dd {
    font-variant-numeric: tabular-nums;
}
</style>
