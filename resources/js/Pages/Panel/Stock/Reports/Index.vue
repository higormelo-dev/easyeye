<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout   from '@/Layouts/AppLayout.vue';
import PageHeader  from '@/Components/Panel/PageHeader.vue';
import ReportTable from './ReportTable.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Relatórios de estoque (App\Services\Stock\StockReportService) no layout de
 * Panel/Patients/Index: cabeçalho com "Exportar CSV", período na barra de
 * filtros, abas e tabelas no padrão PatientTable (Colunas, ordenação por
 * cabeçalho, estado vazio). Tudo chega pronto do servidor; período e
 * ordenação recarregam via Inertia levando a aba ativa (`report` + sort/
 * direction) e, em `sorts`, as outras abas fora da ordem padrão — ordenar
 * uma aba ou mudar o período não desfaz a ordenação das demais. Textos vêm de
 * lang/{locale}/stock_reports.php (prop `t`); moeda/números/datas no idioma
 * do usuário (useLocaleFormat).
 */
const props = defineProps({
    breadcrumbs:            { type: Array,  default: () => [] },
    filters:                { type: Object, required: true },   // { from, to, report, sort, direction, sorts }
    valuedInventory:        { type: Object, required: true },   // { items, total_value }
    turnover:               { type: Array,  default: () => [] },
    consumptionByProcedure: { type: Array,  default: () => [] },
    purchasesBySupplier:    { type: Array,  default: () => [] },
    sortable:               { type: Object, default: () => ({}) }, // { <report>: [chaves da whitelist] }
    routes:                 { type: Object, required: true },
    t:                      { type: Object, default: () => ({}) },
});

const { tx } = useTrans(() => props.t);
const { locale, money, number, quantity, date } = useLocaleFormat();

const pageTitle = computed(() => props.t.page_title ?? 'Relatórios de estoque');

// ── Abas (troca local; abre na aba cuja ordenação veio na URL) ──────────────
const REPORTS = ['inventory', 'turnover', 'consumption', 'purchases'];

const activeTab = ref(REPORTS.includes(props.filters.report) ? props.filters.report : REPORTS[0]);

const tabs = computed(() => REPORTS.map((key) => ({ key, label: props.t[`tab_${key}`] ?? key })));

const activeTabLabel = computed(() => tabs.value.find((tab) => tab.key === activeTab.value)?.label ?? '');

/** Setas/Home/End entre as abas (padrão ARIA de tabs). */
async function onTabKeydown(event, index) {
    const moves = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: REPORTS.length - 1 };
    if (!(event.key in moves)) return;

    event.preventDefault();
    const next = (moves[event.key] + REPORTS.length) % REPORTS.length;
    activeTab.value = REPORTS[next];
    await nextTick();
    document.getElementById(`stock-report-tab-${REPORTS[next]}`)?.focus();
}

// ── Período ─────────────────────────────────────────────────────────────────
const from = ref(props.filters.from);
const to   = ref(props.filters.to);

// O servidor normaliza (data inválida → padrão, período invertido → trocado).
watch(() => [props.filters.from, props.filters.to], ([newFrom, newTo]) => {
    from.value = newFrom;
    to.value   = newTo;
});

// Campo apagado/incompleto marca só ele; início depois do fim marca os dois.
const periodIncomplete = computed(() => !from.value || !to.value);
const periodInvalid    = computed(() => !periodIncomplete.value && from.value > to.value);
const fromInvalid      = computed(() => !from.value || periodInvalid.value);
const toInvalid        = computed(() => !to.value || periodInvalid.value);
const periodError      = computed(() => {
    if (periodIncomplete.value) return props.t.period_required ?? '';

    return periodInvalid.value ? (props.t.period_invalid ?? '') : '';
});

function visit(params, options = {}) {
    router.get(props.routes.index, params, { preserveState: true, preserveScroll: true, ...options });
}

function applyPeriod() {
    // Período incompleto ou invertido: espera o usuário corrigir (o aviso fica visível).
    if (periodIncomplete.value || periodInvalid.value) return;

    visit(reportQuery({ from: from.value, to: to.value }, activeTab.value, sortOf(activeTab.value)), { replace: true });
}

// ── Ordenação (whitelist por relatório no controller) ───────────────────────
const DEFAULT_DIRECTION = 'desc';

function sortOf(report) {
    return props.filters.sorts?.[report] ?? { sort: '', direction: DEFAULT_DIRECTION };
}

/** Ordem padrão de uma aba = a 1ª chave da whitelist, decrescente (StockReportsController::SORTABLE). */
function isDefaultSort(report) {
    const { sort, direction } = sortOf(report);

    return sort === (props.sortable?.[report]?.[0] ?? '') && direction === DEFAULT_DIRECTION;
}

/**
 * Query de uma visita: período + aba ativa (`report`) com a ordenação dela
 * e, em `sorts`, as outras abas fora do padrão (o servidor valida cada uma
 * pela whitelist). Recarregar a página abre na aba `report`.
 */
function reportQuery(period, report, { sort, direction }) {
    const others = REPORTS
        .filter((key) => key !== report && !isDefaultSort(key))
        .map((key) => [key, { sort: sortOf(key).sort, direction: sortOf(key).direction }]);

    return {
        ...period,
        report,
        sort,
        direction,
        ...(others.length ? { sorts: Object.fromEntries(others) } : {}),
    };
}

function onSort(report, sort) {
    visit(reportQuery({ from: props.filters.from, to: props.filters.to }, report, sort));
}

function columnsFor(report, defs) {
    const allowed = props.sortable?.[report] ?? [];

    return defs.map((col) => ({ ...col, sortable: allowed.includes(col.key) }));
}

// ── Exportação CSV (link puro: o navegador baixa o anexo) ───────────────────
// Usa o período APLICADO (filters), o mesmo dos números na tela.
const exportUrl = computed(() => {
    const params = new URLSearchParams({ report: activeTab.value, from: props.filters.from, to: props.filters.to });

    return `${props.routes.export}?${params.toString()}`;
});

// ── Formatação no idioma do usuário ─────────────────────────────────────────
// Quantidade (até 3 casas, sem zeros à direita), moeda e data vêm de
// useLocaleFormat; só o percentual 0–100 é próprio deste relatório.
function isBlank(value) {
    return value === null || value === undefined || value === '' || Number.isNaN(Number(value));
}

/** Percentual que já vem em 0–100 (ex.: 80.5 → "80,5%"). */
function percent(value) {
    if (isBlank(value)) return '—';

    return new Intl.NumberFormat(locale.value, { style: 'percent', maximumFractionDigits: 1 }).format(Number(value) / 100);
}

const ABC_BADGE = {
    A: 'badge-soft-success text-success border-success',
    B: 'badge-soft-warning text-warning border-warning',
    C: 'badge-soft-secondary text-secondary border-secondary',
};

// ── Colunas de cada relatório ───────────────────────────────────────────────
const inventoryColumns = computed(() => columnsFor('inventory', [
    { key: 'name',           label: props.t.col_product ?? 'Produto' },
    { key: 'category_name',  label: props.t.col_category ?? 'Categoria', cellClass: 'small' },
    { key: 'qty_on_hand',    label: props.t.col_qty_on_hand ?? 'Saldo', align: 'end' },
    { key: 'cost_avg',       label: props.t.col_cost_avg ?? 'Custo médio', align: 'end' },
    { key: 'total_value',    label: props.t.col_total_value ?? 'Valor total', align: 'end', cellClass: 'fw-semibold' },
    { key: 'cumulative_pct', label: props.t.col_cumulative_pct ?? '% acumulado', align: 'end', cellClass: 'small' },
    { key: 'abc_class',      label: props.t.col_abc_class ?? 'Classe', align: 'center', fixed: true },
]));

const turnoverColumns = computed(() => columnsFor('turnover', [
    { key: 'name',           label: props.t.col_product ?? 'Produto' },
    { key: 'qty_out',        label: props.t.col_qty_out ?? 'Saída no período', align: 'end' },
    { key: 'qty_on_hand',    label: props.t.col_current_qty ?? 'Saldo atual', align: 'end' },
    { key: 'turnover_ratio', label: props.t.col_turnover_ratio ?? 'Giro', align: 'end' },
]));

const consumptionColumns = computed(() => columnsFor('consumption', [
    { key: 'procedure_name', label: props.t.col_procedure ?? 'Procedimento', cellClass: 'fw-medium' },
    { key: 'doctor_name',    label: props.t.col_doctor ?? 'Médico' },
    { key: 'executed_at',    label: props.t.col_executed_at ?? 'Executado em', cellClass: 'text-muted small' },
    { key: 'items',          label: props.t.col_materials ?? 'Materiais' },
    { key: 'total_cost',     label: props.t.col_total_cost ?? 'Custo total', align: 'end', cellClass: 'fw-semibold' },
]));

const purchasesColumns = computed(() => columnsFor('purchases', [
    { key: 'supplier_name', label: props.t.col_supplier ?? 'Fornecedor', cellClass: 'fw-medium' },
    { key: 'orders_count',  label: props.t.col_orders_count ?? 'Pedidos com recebimento', align: 'end' },
    { key: 'total_spent',   label: props.t.col_total_spent ?? 'Total gasto', align: 'end', cellClass: 'fw-semibold' },
]));
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="page-stock-reports">

            <PageHeader :title="pageTitle">
                <template #actions>
                    <div class="d-flex align-items-center gap-2">
                        <a
                            :href="exportUrl"
                            class="btn btn-outline-secondary fs-13 btn-md"
                            :title="tx('export_title', { report: activeTabLabel })"
                        >
                            <i class="ti ti-download me-1" aria-hidden="true"></i> {{ t.btn_export ?? 'Exportar CSV' }}
                        </a>
                    </div>
                </template>
            </PageHeader>

            <!-- Filtros: período dos relatórios de giro/consumo/compras -->
            <div class="d-flex align-items-center flex-wrap gap-2 mb-3" role="group" aria-labelledby="stock-reports-period-label">
                <span id="stock-reports-period-label" class="small text-muted">{{ t.period_label ?? 'Período:' }}</span>
                <input
                    id="stock-reports-from"
                    v-model="from"
                    type="date"
                    class="form-control form-control-sm stock-reports-date"
                    :class="{ 'is-invalid': fromInvalid }"
                    :aria-label="t.period_from ?? 'Data inicial'"
                    :aria-invalid="fromInvalid ? 'true' : undefined"
                    :aria-describedby="fromInvalid ? 'stock-reports-period-error' : undefined"
                    @change="applyPeriod"
                >
                <span class="text-muted small" aria-hidden="true">{{ t.period_until ?? 'até' }}</span>
                <input
                    id="stock-reports-to"
                    v-model="to"
                    type="date"
                    class="form-control form-control-sm stock-reports-date"
                    :class="{ 'is-invalid': toInvalid }"
                    :aria-label="t.period_to ?? 'Data final'"
                    :aria-invalid="toInvalid ? 'true' : undefined"
                    :aria-describedby="toInvalid ? 'stock-reports-period-error' : undefined"
                    @change="applyPeriod"
                >
                <small v-if="fromInvalid || toInvalid" id="stock-reports-period-error" class="text-danger w-100" role="alert">
                    {{ periodError }}
                </small>
            </div>

            <!-- Abas -->
            <ul class="nav nav-tabs mb-3" role="tablist" :aria-label="t.tabs_label ?? pageTitle">
                <li v-for="(tab, index) in tabs" :key="tab.key" class="nav-item" role="presentation">
                    <button
                        :id="`stock-report-tab-${tab.key}`"
                        type="button"
                        role="tab"
                        class="nav-link"
                        :class="{ active: activeTab === tab.key }"
                        :aria-selected="activeTab === tab.key ? 'true' : 'false'"
                        :aria-controls="`stock-report-panel-${tab.key}`"
                        :tabindex="activeTab === tab.key ? 0 : -1"
                        @click="activeTab = tab.key"
                        @keydown="onTabKeydown($event, index)"
                    >{{ tab.label }}</button>
                </li>
            </ul>

            <!-- Posição valorizada + Curva ABC -->
            <section
                v-show="activeTab === 'inventory'"
                id="stock-report-panel-inventory"
                role="tabpanel"
                aria-labelledby="stock-report-tab-inventory"
            >
                <ReportTable
                    :rows="valuedInventory.items ?? []"
                    :columns="inventoryColumns"
                    :sort="sortOf('inventory').sort"
                    :direction="sortOf('inventory').direction"
                    storage-key="stock_reports_inventory_columns_order"
                    empty-icon="ti ti-package-off"
                    :empty-text="t.empty_inventory"
                    :t="t"
                    @sort="onSort('inventory', $event)"
                >
                    <template #summary>
                        <span class="text-muted">{{ t.inventory_total ?? 'Valor total em estoque:' }}</span>
                        <strong class="ms-1">{{ money(valuedInventory.total_value) }}</strong>
                    </template>
                    <template #cell-name="{ row }">
                        <span class="fw-medium">{{ row.name }}</span>
                        <span v-if="row.code" class="text-muted small ms-1">({{ row.code }})</span>
                    </template>
                    <template #cell-category_name="{ row }">{{ row.category_name ?? '—' }}</template>
                    <template #cell-qty_on_hand="{ row }">
                        {{ quantity(row.qty_on_hand) }}
                        <span v-if="row.unit" class="text-muted small">{{ row.unit }}</span>
                    </template>
                    <template #cell-cost_avg="{ row }">{{ money(row.cost_avg) }}</template>
                    <template #cell-total_value="{ row }">{{ money(row.total_value) }}</template>
                    <template #cell-cumulative_pct="{ row }">{{ percent(row.cumulative_pct) }}</template>
                    <template #cell-abc_class="{ row }">
                        <span
                            v-if="row.abc_class"
                            class="badge rounded border fs-13 fw-medium"
                            :class="ABC_BADGE[row.abc_class]"
                            :title="tx('abc_class_title', { class: row.abc_class })"
                        >{{ row.abc_class }}</span>
                        <span v-else class="text-muted">—</span>
                    </template>
                </ReportTable>
                <small class="text-muted d-block mt-2">{{ t.note_abc }}</small>
            </section>

            <!-- Giro -->
            <section
                v-show="activeTab === 'turnover'"
                id="stock-report-panel-turnover"
                role="tabpanel"
                aria-labelledby="stock-report-tab-turnover"
            >
                <ReportTable
                    :rows="turnover"
                    :columns="turnoverColumns"
                    :sort="sortOf('turnover').sort"
                    :direction="sortOf('turnover').direction"
                    storage-key="stock_reports_turnover_columns_order"
                    empty-icon="ti ti-chart-bar-off"
                    :empty-text="t.empty_turnover"
                    :t="t"
                    @sort="onSort('turnover', $event)"
                >
                    <template #cell-name="{ row }">
                        <span class="fw-medium">{{ row.name }}</span>
                        <span v-if="row.code" class="text-muted small ms-1">({{ row.code }})</span>
                    </template>
                    <template #cell-qty_out="{ row }">{{ quantity(row.qty_out) }}</template>
                    <template #cell-qty_on_hand="{ row }">{{ quantity(row.qty_on_hand) }}</template>
                    <template #cell-turnover_ratio="{ row }">{{ number(row.turnover_ratio, 2) }}</template>
                </ReportTable>
                <small class="text-muted d-block mt-2">{{ t.note_turnover }}</small>
            </section>

            <!-- Consumo por procedimento -->
            <section
                v-show="activeTab === 'consumption'"
                id="stock-report-panel-consumption"
                role="tabpanel"
                aria-labelledby="stock-report-tab-consumption"
            >
                <ReportTable
                    :rows="consumptionByProcedure"
                    :columns="consumptionColumns"
                    :sort="sortOf('consumption').sort"
                    :direction="sortOf('consumption').direction"
                    storage-key="stock_reports_consumption_columns_order"
                    empty-icon="ti ti-first-aid-kit-off"
                    :empty-text="t.empty_consumption"
                    :t="t"
                    @sort="onSort('consumption', $event)"
                >
                    <template #cell-executed_at="{ row }">{{ date(row.executed_at) }}</template>
                    <template #cell-items="{ row }">
                        <ul class="list-unstyled small text-muted mb-0">
                            <li v-for="(item, i) in row.items" :key="i">
                                {{ item.product_name ?? '—' }}: {{ quantity(item.quantity) }} × {{ money(item.unit_cost) }} = {{ money(item.total_cost) }}
                            </li>
                        </ul>
                    </template>
                    <template #cell-total_cost="{ row }">{{ money(row.total_cost) }}</template>
                </ReportTable>
            </section>

            <!-- Compras por fornecedor -->
            <section
                v-show="activeTab === 'purchases'"
                id="stock-report-panel-purchases"
                role="tabpanel"
                aria-labelledby="stock-report-tab-purchases"
            >
                <ReportTable
                    :rows="purchasesBySupplier"
                    :columns="purchasesColumns"
                    :sort="sortOf('purchases').sort"
                    :direction="sortOf('purchases').direction"
                    storage-key="stock_reports_purchases_columns_order"
                    empty-icon="ti ti-receipt-off"
                    :empty-text="t.empty_purchases"
                    :t="t"
                    @sort="onSort('purchases', $event)"
                >
                    <template #cell-orders_count="{ row }">{{ number(row.orders_count) }}</template>
                    <template #cell-total_spent="{ row }">{{ money(row.total_spent) }}</template>
                </ReportTable>
                <small class="text-muted d-block mt-2">{{ t.note_purchases }}</small>
            </section>

        </div>
    </AppLayout>
</template>

<style scoped>
.stock-reports-date {
    max-width: 160px;
}
</style>
