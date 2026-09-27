<script setup>
import { computed } from 'vue';
import AppLayout    from '@/Layouts/AppLayout.vue';
import PageHeader   from '@/Components/Panel/PageHeader.vue';
import PeriodFilter from '@/Components/Panel/PeriodFilter.vue';
import KpiCard      from '@/Components/Panel/KpiCard.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans';
import CashFlowCategoryTable  from './CashFlowCategoryTable.vue';
import CashFlowDayTable       from './CashFlowDayTable.vue';
import CashFlowEntriesTable   from './CashFlowEntriesTable.vue';
import CashFlowEntriesToolbar from './CashFlowEntriesToolbar.vue';
import ReportExportMenu       from './ReportExportMenu.vue';
import { useReportPage } from './useReportPage.js';
import { useEntryFilters } from './useEntryFilters.js';

/**
 * Relatório de fluxo de caixa (FinancialReportsController::cashFlow).
 *
 * - Período pelo PeriodFilter, aplicado na URL (from/to normalizados no
 *   servidor, com teto de dias: acima dele o servidor recorta e a tela avisa).
 * - KPIs realizado × previsto = CashFlowService::overview() (a mesma definição
 *   da tela de Fluxo de caixa). `summary` (income/expense/balance/pending)
 *   continua no payload — o PDF usa — e alimenta os rodapés.
 * - Por categoria (receitas e despesas separadas, % do tipo) e por dia (saldo
 *   do dia e acumulado): agregados em SQL sobre o período inteiro.
 * - Lançamentos paginados no servidor, com busca, filtros e ordenação só da
 *   lista (useEntryFilters). Exportação: período inteiro.
 *   Textos: lang financial_reports (`t`) e financial_shared (`t.shared`);
 *   moeda/datas no idioma do usuário.
 */
const props = defineProps({
    breadcrumbs:    { type: Array,  default: () => [] },
    filters:        { type: Object, required: true },     // { from, to, search, type, status, category_id, sort, direction } normalizados no servidor
    period_capped:  { type: Object, default: null },      // { requested_from, requested_to, max_days } quando o período foi recortado
    today:          { type: String, default: '' },        // Y-m-d no fuso da clínica (atalhos do período)
    summary:        { type: Object, default: () => ({}) }, // { income, expense, balance, pending }
    overview:       { type: Object, default: () => ({}) }, // { received, receivable, paid, payable, realized_balance, projected_balance, entries_count, ... }
    byCategory:     { type: Array,  default: () => [] },   // [{ key, category_id, category, type, total, share }]
    byDay:          { type: Array,  default: () => [] },   // [{ day, income, expense, balance, cumulative }]
    categories:     { type: Array,  default: () => [] },   // opções do filtro: [{ id, name, type }]
    entries:        { type: Object, default: () => ({ data: [], links: [], total: 0, last_page: 1 }) }, // paginator Laravel
    routes:         { type: Object, default: () => ({}) },
    export_formats: { type: Array,  default: () => ['csv', 'xlsx', 'pdf'] },
    t:              { type: Object, default: () => ({}) },
});

const { money, signedMoney, number, date } = useLocaleFormat();

const {
    search: entrySearch,
    type: entryType,
    status: entryStatus,
    category: entryCategory,
    loading: listLoading,
    error: listError,
    hasFilters,
    params: listParams,
    sortBy,
    clear: clearFilters,
    cancelPending,
} = useEntryFilters(props);

// Trocar o período mantém busca/filtros/ordem da lista (e volta à 1ª página).
const { from, to, loading, loadError, applyPeriod, exportOptions, exportTitle } = useReportPage(
    props,
    'panel.financial.reports.cash-flow',
    { params: listParams, beforeVisit: cancelPending },
);

const c         = computed(() => props.t.cashflow ?? {});
const pageTitle = computed(() => c.value.title ?? '');
const { tx }    = useTrans(() => props.t.cashflow ?? {});

// Total do cabeçalho = lançamentos do período (sem cancelados), como os KPIs.
const periodTotal = computed(() => Number(props.overview?.entries_count ?? props.entries?.total ?? 0));

const periodCappedText = computed(() => {
    const capped = props.period_capped;
    if (!capped) return '';

    return tx('period_capped', {
        requested_from: date(capped.requested_from),
        requested_to:   date(capped.requested_to),
        days:           number(capped.max_days),
        from:           date(props.filters.from),
        to:             date(props.filters.to),
    });
});

// ── KPIs: realizado × previsto (overview do servidor) ───────────────────────
function balanceTone(value) {
    const number = Number(value ?? 0);
    if (number < 0) return 'danger';

    return number > 0 ? 'success' : 'secondary';
}

function balanceSubtitle(value) {
    const number = Number(value ?? 0);
    if (number < 0) return c.value.balance_negative;

    return number > 0 ? c.value.balance_positive : c.value.balance_zero;
}

const kpiGroups = computed(() => {
    const o = props.overview ?? {};

    return [
        {
            key:  'realized',
            kpis: [
                { key: 'received', icon: 'ti ti-arrow-down-left', tone: 'success', value: money(o.received ?? 0) },
                { key: 'paid', icon: 'ti ti-arrow-up-right', tone: 'danger', value: money(o.paid ?? 0) },
                {
                    key:      'realized_balance',
                    icon:     'ti ti-scale',
                    tone:     balanceTone(o.realized_balance),
                    value:    signedMoney(o.realized_balance ?? 0),
                    subtitle: balanceSubtitle(o.realized_balance),
                },
            ],
        },
        {
            key:  'projected',
            kpis: [
                { key: 'receivable', icon: 'ti ti-clock', tone: 'info', value: money(o.receivable ?? 0) },
                { key: 'payable', icon: 'ti ti-clock-pause', tone: 'warning', value: money(o.payable ?? 0) },
                {
                    key:      'projected_balance',
                    icon:     'ti ti-trending-up',
                    tone:     balanceTone(o.projected_balance),
                    value:    signedMoney(o.projected_balance ?? 0),
                    subtitle: balanceSubtitle(o.projected_balance),
                },
            ],
        },
    ].map((group) => ({
        ...group,
        title: c.value[`group_${group.key}`],
        hint:  c.value[`group_${group.key}_hint`],
        kpis:  group.kpis.map((kpi) => ({ ...kpi, label: c.value[`kpi_${kpi.key}`] ?? kpi.key, hint: c.value[`kpi_${kpi.key}_hint`] ?? '' })),
    }));
});

// ── Por categoria: receitas e despesas separadas ────────────────────────────
const incomeCategories  = computed(() => props.byCategory.filter((row) => row.type === 'income'));
const expenseCategories = computed(() => props.byCategory.filter((row) => row.type === 'expense'));
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="pageTitle" :total="periodTotal" :total-label="c.total_label">
                <template #actions>
                    <ReportExportMenu :options="exportOptions" :title="exportTitle" :label="t.export" />
                </template>
            </PageHeader>

            <!-- Período: atalhos + De/Até, aplicado na URL -->
            <div class="d-flex flex-wrap align-items-end gap-2 mb-3" data-test="period-bar">
                <PeriodFilter
                    v-model:from="from"
                    v-model:to="to"
                    :today="today"
                    :labels="t.shared?.period"
                    compact
                    :disabled="loading"
                    @change="applyPeriod"
                />
                <span class="small text-body-secondary align-self-center" role="status" aria-live="polite" data-test="loading-status">
                    <template v-if="loading">
                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ t.loading }}
                    </template>
                </span>
            </div>

            <div v-if="loadError" class="alert alert-danger d-flex align-items-center gap-2" role="alert" data-test="load-error">
                <i class="ti ti-alert-circle" aria-hidden="true"></i>{{ loadError }}
            </div>

            <!-- Período acima do teto: o servidor recortou (tela e exportação) -->
            <div v-if="period_capped" class="alert alert-warning d-flex align-items-start gap-2" role="status" data-test="period-capped">
                <i class="ti ti-alert-triangle mt-1" aria-hidden="true"></i><span>{{ periodCappedText }}</span>
            </div>

            <div :aria-busy="loading ? 'true' : 'false'">
                <!-- KPIs: realizado × previsto -->
                <section class="mb-2" :aria-label="c.kpis_label" data-test="kpis">
                    <div class="row g-3">
                        <section
                            v-for="group in kpiGroups"
                            :key="group.key"
                            class="col-12 col-xl-6"
                            :aria-labelledby="`cf-kpi-group-${group.key}`"
                            :data-group="group.key"
                        >
                            <h2 :id="`cf-kpi-group-${group.key}`" class="fs-6 fw-semibold mb-0">{{ group.title }}</h2>
                            <p class="small text-body-secondary mb-2" data-test="kpi-group-hint">{{ group.hint }}</p>
                            <div class="row g-2 row-cols-1 row-cols-sm-3">
                                <div v-for="kpi in group.kpis" :key="kpi.key" class="col" :data-kpi="kpi.key">
                                    <KpiCard
                                        :label="kpi.label"
                                        :value="kpi.value"
                                        :icon="kpi.icon"
                                        :tone="kpi.tone"
                                        :hint="kpi.hint"
                                        :subtitle="kpi.subtitle ?? ''"
                                        :loading="loading"
                                        :test-id="kpi.key"
                                    />
                                </div>
                            </div>
                        </section>
                    </div>
                </section>
                <p class="small text-body-secondary mb-3" data-test="kpi-scope-note">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ c.kpi_scope_note }}
                </p>

                <!-- Por categoria -->
                <div class="row g-3 mb-3">
                    <div class="col-lg-6">
                        <CashFlowCategoryTable type="income" :rows="incomeCategories" :total="Number(summary.income ?? 0)" :t="t" />
                    </div>
                    <div class="col-lg-6">
                        <CashFlowCategoryTable type="expense" :rows="expenseCategories" :total="Number(summary.expense ?? 0)" :t="t" />
                    </div>
                </div>

                <!-- Por dia -->
                <CashFlowDayTable class="mb-3" :rows="byDay" :summary="summary" :t="t" />

                <!-- Lançamentos: paginados no servidor; busca/filtros/ordem só da lista -->
                <CashFlowEntriesTable
                    :entries="entries"
                    :filters="filters"
                    :filtered="hasFilters"
                    :loading="listLoading"
                    :t="t"
                    @sort="sortBy"
                >
                    <template #toolbar>
                        <CashFlowEntriesToolbar
                            v-model:search="entrySearch"
                            v-model:type="entryType"
                            v-model:status="entryStatus"
                            v-model:category="entryCategory"
                            :categories="categories"
                            :has-filters="hasFilters"
                            :total="Number(entries.total ?? 0)"
                            :loading="listLoading"
                            :t="t"
                            @clear="clearFilters"
                        />
                    </template>
                    <template #alert>
                        <div v-if="listError" class="alert alert-danger d-flex align-items-center gap-2 mx-3 mt-3 mb-0" role="alert" data-test="list-error">
                            <i class="ti ti-alert-circle" aria-hidden="true"></i>{{ t.load_error }}
                        </div>
                    </template>
                </CashFlowEntriesTable>
            </div>
        </div>
    </AppLayout>
</template>
