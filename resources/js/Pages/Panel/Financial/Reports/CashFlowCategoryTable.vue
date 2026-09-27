<script setup>
import { computed, useId } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { usePercent } from './useReportPage.js';

/**
 * "Por categoria" de UM tipo (receitas ou despesas): total da categoria e
 * participação no total do tipo — barra decorativa (aria-hidden) sempre com o
 * percentual também em texto. Linhas já agrupadas por category_id no servidor.
 */
const props = defineProps({
    type:  { type: String, required: true },        // 'income' | 'expense'
    rows:  { type: Array,  default: () => [] },     // [{ key, category_id, category, type, total, share }]
    total: { type: Number, default: 0 },            // total do tipo (summary.income / summary.expense)
    t:     { type: Object, default: () => ({}) },
});

const { money } = useLocaleFormat();
const { percent } = usePercent();

const headingId = `cf-category-${props.type}-${useId()}`;

const META = {
    income:  { icon: 'ti ti-arrow-down-left text-success', bar: 'bg-success' },
    expense: { icon: 'ti ti-arrow-up-right text-danger', bar: 'bg-danger' },
};

const c     = computed(() => props.t.cashflow ?? {});
const meta  = computed(() => META[props.type] ?? META.income);
const title = computed(() => c.value[`by_category_${props.type}`] ?? '');
const empty = computed(() => c.value[`no_category_${props.type}`] ?? '');

/** Largura da barra limitada a 0–100%. */
function barWidth(share) {
    const value = Number(share) || 0;

    return `${Math.min(100, Math.max(0, value))}%`;
}
</script>

<template>
    <section class="card border-0 shadow-sm h-100" :aria-labelledby="headingId" :data-test="`category-${type}`">
        <div class="card-header bg-transparent d-flex align-items-center gap-2">
            <i :class="meta.icon" aria-hidden="true"></i>
            <h2 :id="headingId" class="fs-6 mb-0 fw-semibold">{{ title }}</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 cf-category-table">
                <caption class="visually-hidden">{{ title }}</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ c.col_category }}</th>
                        <th scope="col" class="text-end">{{ c.col_total }}</th>
                        <th scope="col" class="cf-category-table__share">{{ c.col_share }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td colspan="3" class="text-center text-body-secondary py-4" data-test="category-empty">{{ empty }}</td>
                    </tr>
                    <tr v-for="row in rows" :key="row.key" data-test="category-row">
                        <th scope="row" class="fw-medium text-body cf-category-table__name">{{ row.category }}</th>
                        <td class="text-end text-body text-nowrap" data-test="category-total">{{ money(row.total) }}</td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1 cf-category-table__bar" aria-hidden="true" data-test="category-bar">
                                    <div class="progress-bar" :class="meta.bar" :style="{ width: barWidth(row.share) }"></div>
                                </div>
                                <span class="small text-body text-nowrap cf-category-table__percent" data-test="category-share">{{ percent(row.share) }}</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length">
                    <tr>
                        <th scope="row">{{ c.col_total }}</th>
                        <td class="text-end fw-semibold text-body text-nowrap" data-test="category-footer">{{ money(total) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</template>

<style scoped>
.cf-category-table__share {
    width: 38%;
}

.cf-category-table__name {
    overflow-wrap: anywhere;
}

.cf-category-table__bar {
    height: 0.375rem;
    min-width: 3rem;
}

.cf-category-table__percent,
.cf-category-table td {
    font-variant-numeric: tabular-nums;
}

.cf-category-table__percent {
    min-width: 3.5rem;
    text-align: end;
}

.cf-category-table tfoot > tr > * {
    border-top: 2px solid var(--bs-border-color);
    padding: 12px 16px;
}
</style>
