<script setup>
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout     from '@/Layouts/AppLayout.vue';
import PageHeader    from '@/Components/Panel/PageHeader.vue';
import PeriodFilter  from '@/Components/Panel/PeriodFilter.vue';
import KpiCard       from '@/Components/Panel/KpiCard.vue';
import TrendBarChart from './TrendBarChart.vue';
import DonutChart    from './DonutChart.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Dashboard gerencial (BI). Somente leitura: ClinicBiService calcula tudo.
 *
 * Contrato do backend (não renomear — PDF/Blade antigos usam as mesmas chaves):
 * summary.kpis.{income, expense, balance, total_billed, total_paid,
 * total_glosa, ticket_medio, receipt_rate, attended, noshow, cancelled,
 * total_schedules, attendance_rate, occupancy_rate, new_patients},
 * summary.by_covenant_chart[{label, value}] e trend[{period, month, income,
 * expense}]. Aqui só se DERIVA para exibir (saldo do mês, fatia "pendentes" da
 * agenda = total − atendidos − faltas − cancelados); nenhuma fórmula de KPI
 * muda. Textos: lang/{locale}/financial_bi.php (prop `t`, com `t.shared` do
 * PeriodFilter); moeda/números/datas no idioma do usuário (useLocaleFormat).
 */
const props = defineProps({
    breadcrumbs:   { type: Array,  default: () => [] },
    entity:        { type: Object, required: true },
    filters:       { type: Object, required: true },   // { from, to } já normalizados no servidor
    summary:       { type: Object, required: true },
    trend:         { type: Array,  default: () => [] },
    generated_at:  { type: String, default: '' },      // ISO 8601 do cálculo mais antigo da tela
    cache_minutes: { type: Number, default: 10 },
    today:         { type: String, default: '' },      // Y-m-d do servidor (atalhos do período)
    routes:        { type: Object, default: () => ({}) },
    t:             { type: Object, default: () => ({}) },
});

const { tx } = useTrans(() => props.t);
const { locale, money, signedMoney, number } = useLocaleFormat();

const pageTitle = computed(() => tx('title'));
const k         = computed(() => props.summary?.kpis ?? {});

// ── Formatação ──────────────────────────────────────────────────────────────
/** Percentual que já vem em 0–100 (ex.: 80.5 → "80,5%"). */
function percent(value) {
    const numeric = Number(value);
    if (value === null || value === undefined || value === '' || Number.isNaN(numeric)) return '—';

    return new Intl.NumberFormat(locale.value, { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 })
        .format(numeric / 100);
}

/** "2026-09" → "set. de 2026" (pt-BR) / "Sep 2026" (en); sem ISO volta o `period` (m/Y). */
function monthLabel(row) {
    const match = /^(\d{4})-(\d{2})$/.exec(String(row?.month ?? ''));
    if (!match) return row?.period ?? '—';

    return new Intl.DateTimeFormat(locale.value, { month: 'short', year: 'numeric' })
        .format(new Date(Number(match[1]), Number(match[2]) - 1, 1));
}

const updatedLabel = computed(() => {
    const parsed = new Date(props.generated_at);
    if (!props.generated_at || Number.isNaN(parsed.getTime())) return '';

    const time = new Intl.DateTimeFormat(locale.value, { timeStyle: 'short' }).format(parsed);

    return tx('updated_at', { time });
});

// ── Período (PeriodFilter só emite intervalo válido; vai para a URL) ────────
const from = ref(props.filters.from);
const to   = ref(props.filters.to);

function restorePeriod() {
    from.value = props.filters.from;
    to.value   = props.filters.to;
}

watch(() => [props.filters.from, props.filters.to], restorePeriod);

const loading   = ref(false);
const loadError = ref('');

function firstError(errors) {
    const first = Object.values(errors ?? {})[0];

    return (Array.isArray(first) ? first[0] : first) || tx('load_error');
}

function visit(params) {
    loadError.value = '';

    router.get(props.routes.index ?? route('panel.financial.bi.index'), params, {
        preserveState:   true,
        preserveScroll:  true,
        replace:         true,
        onStart:         () => { loading.value = true; },
        onFinish:        () => { loading.value = false; },
        onError:         (errors) => { loadError.value = firstError(errors); restorePeriod(); },
        // Erro HTTP/rede: aviso na própria tela (em vez de sumir ou abrir o modal de erro)
        // e o filtro volta para o período que está sendo exibido.
        onHttpException: () => { loadError.value = tx('load_error'); restorePeriod(); return false; },
        onNetworkError:  () => { loadError.value = tx('load_error'); restorePeriod(); return false; },
    });
}

function applyPeriod({ from: newFrom, to: newTo }) {
    if (newFrom === props.filters.from && newTo === props.filters.to) return;

    visit({ from: newFrom, to: newTo });
}

/** Descarta o cache da clínica (servidor) e recalcula o período aplicado. */
function refresh() {
    visit({ from: props.filters.from, to: props.filters.to, refresh: 1 });
}

// ── Indicadores em 3 faixas (KpiCard) ───────────────────────────────────────
const balance  = computed(() => Number(k.value.balance ?? 0));
const hasGlosa = computed(() => Number(k.value.total_glosa ?? 0) > 0);

const balanceTone = computed(() => {
    if (balance.value < 0) return 'danger';

    return balance.value > 0 ? 'success' : 'secondary';
});

const balanceSubtitle = computed(() => {
    if (balance.value < 0) return tx('balance_negative');

    return balance.value > 0 ? tx('balance_positive') : tx('balance_zero');
});

/**
 * Card de KPI: `key` = chave em summary.kpis (data-kpi / data-test), `text` =
 * prefixo dos textos no lang (`<text>`, `<text>_sub`, `<text>_hint`).
 */
function kpi({ key, text = key, value, tone, icon, subtitle = null }) {
    return {
        key,
        value,
        tone,
        icon:     `ti ${icon}`,
        label:    tx(text),
        subtitle: subtitle ?? tx(`${text}_sub`),
        hint:     tx(`${text}_hint`),
    };
}

const sections = computed(() => [
    {
        key: 'cash',
        actions: [
            { key: 'cash_flow', href: props.routes.cash_flow, label: tx('see_cash_flow'), icon: 'ti-report-money' },
        ],
        items: [
            kpi({ key: 'income', value: money(k.value.income ?? 0), tone: 'success', icon: 'ti-trending-up' }),
            kpi({ key: 'expense', value: money(k.value.expense ?? 0), tone: 'danger', icon: 'ti-trending-down' }),
            kpi({ key: 'balance', value: signedMoney(balance.value), tone: balanceTone.value, icon: 'ti-wallet', subtitle: balanceSubtitle.value }),
        ],
    },
    {
        key: 'billing',
        actions: [
            { key: 'billing',   href: props.routes.billing,   label: tx('open_billing'), icon: 'ti-file-invoice' },
            { key: 'glosas',    href: props.routes.glosas,    label: tx('see_glosas'),   icon: 'ti-alert-triangle' },
            { key: 'covenants', href: props.routes.covenants, label: tx('see_report'),   icon: 'ti-report' },
        ],
        items: [
            kpi({ key: 'total_billed', text: 'billed', value: money(k.value.total_billed ?? 0), tone: 'primary', icon: 'ti-file-invoice' }),
            kpi({ key: 'total_paid', text: 'received', value: money(k.value.total_paid ?? 0), tone: 'success', icon: 'ti-cash' }),
            // Vermelho só quando existe glosa (antes: alarme vermelho com R$ 0,00).
            kpi({ key: 'total_glosa', text: 'glosa', value: money(k.value.total_glosa ?? 0), tone: hasGlosa.value ? 'danger' : 'secondary', icon: 'ti-alert-triangle' }),
            kpi({ key: 'receipt_rate', value: percent(k.value.receipt_rate ?? 0), tone: 'info', icon: 'ti-receipt' }),
            kpi({ key: 'ticket_medio', text: 'avg_ticket', value: money(k.value.ticket_medio ?? 0), tone: 'primary', icon: 'ti-ticket' }),
        ],
    },
    {
        key: 'schedule',
        actions: [],
        items: [
            kpi({
                key: 'attended', value: number(k.value.attended ?? 0), tone: 'primary', icon: 'ti-user-check',
                subtitle: tx('attended_sub', { count: number(k.value.total_schedules ?? 0) }),
            }),
            kpi({
                key: 'attendance_rate', value: percent(k.value.attendance_rate ?? 0), tone: 'info', icon: 'ti-calendar-check',
                subtitle: tx('attendance_rate_sub', { count: number(k.value.noshow ?? 0) }),
            }),
            kpi({
                key: 'occupancy_rate', value: percent(k.value.occupancy_rate ?? 0), tone: 'warning', icon: 'ti-chart-pie',
                subtitle: tx('occupancy_rate_sub', { count: number(k.value.cancelled ?? 0) }),
            }),
            kpi({ key: 'new_patients', value: number(k.value.new_patients ?? 0), tone: 'success', icon: 'ti-user-plus' }),
        ],
    },
]);

/**
 * Colunas por faixa (1 no celular, 2 no sm): 3 cards → 3 no lg; 4 → 4 no xl;
 * 5 → 3 + 2 no lg e 5 só no xxl (com a sidebar, 5 no xl aperta valores grandes).
 */
function rowColsClass(count) {
    const wide = { 3: 'row-cols-lg-3', 4: 'row-cols-xl-4', 5: 'row-cols-lg-3 row-cols-xxl-5' }[count] ?? `row-cols-lg-${count}`;

    return `row-cols-1 row-cols-sm-2 ${wide}`;
}

// ── Tendência mensal (saldo calculado aqui: income − expense) ───────────────
const trendRows = computed(() => props.trend.map((row) => {
    const income  = Number(row.income) || 0;
    const expense = Number(row.expense) || 0;

    return {
        key:     row.month ?? row.period,
        label:   monthLabel(row),
        income,
        expense,
        balance: Math.round((income - expense) * 100) / 100,
    };
}));

const trendEmpty = computed(() => trendRows.value.every((row) => row.income === 0 && row.expense === 0));

// ── Mix da agenda (rosca) — mesmos contadores dos KPIs de agenda ────────────
const scheduleTotal = computed(() => Number(k.value.total_schedules) || 0);

const scheduleSlices = computed(() => {
    const attended  = Number(k.value.attended) || 0;
    const noshow    = Number(k.value.noshow) || 0;
    const cancelled = Number(k.value.cancelled) || 0;

    return [
        { key: 'attended',  label: tx('chart_attended'),  value: attended,  tone: 'success' },
        { key: 'no_show',   label: tx('chart_no_show'),   value: noshow,    tone: 'danger' },
        { key: 'cancelled', label: tx('chart_cancelled'), value: cancelled, tone: 'secondary' },
        // Agendados, confirmados ou em atendimento: ainda sem desfecho.
        { key: 'pending',   label: tx('chart_pending'),   value: Math.max(0, scheduleTotal.value - attended - noshow - cancelled), tone: 'info' },
    ];
});

// ── Faturamento por convênio (top 6) ────────────────────────────────────────
const covenantRows = computed(() => {
    const rows = props.summary?.by_covenant_chart ?? [];
    const max  = Math.max(0, ...rows.map((row) => Number(row.value) || 0));

    return rows.map((row, index) => ({
        key:   `${row.label ?? ''}-${index}`,
        label: row.label ?? tx('no_covenant'),
        value: Number(row.value) || 0,
        width: max > 0 ? Math.round(((Number(row.value) || 0) / max) * 100) : 0,
    }));
});
</script>

<template>
    <AppLayout :title="pageTitle" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3 page-financial-bi">
            <PageHeader :title="pageTitle" :subtitle="entity.name">
                <template #actions>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span v-if="updatedLabel" class="small text-body-secondary" data-test="updated-at">
                            <i class="ti ti-clock me-1" aria-hidden="true"></i>{{ updatedLabel }}
                        </span>
                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            data-test="refresh"
                            :title="tx('refresh_title', { minutes: cache_minutes })"
                            :disabled="loading"
                            @click="refresh"
                        >
                            <span v-if="loading" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                            <i v-else class="ti ti-refresh me-1" aria-hidden="true"></i>{{ tx('refresh') }}
                        </button>
                    </div>
                </template>
            </PageHeader>

            <!-- Período: atalhos + De/Até; aplica na URL (from/to) -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-test="period-bar">
                <PeriodFilter
                    v-model:from="from"
                    v-model:to="to"
                    :today="today"
                    :labels="t.shared?.period"
                    compact
                    :disabled="loading"
                    @change="applyPeriod"
                />
                <span class="small text-body-secondary" role="status" aria-live="polite" data-test="loading-status">
                    <template v-if="loading">
                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ tx('loading') }}
                    </template>
                </span>
            </div>

            <div v-if="loadError" class="alert alert-danger d-flex align-items-center gap-2" role="alert" data-test="load-error">
                <i class="ti ti-alert-circle" aria-hidden="true"></i>{{ loadError }}
            </div>

            <div :aria-busy="loading ? 'true' : 'false'">
                <!-- Indicadores: Caixa / Convênios e TISS / Agenda -->
                <section
                    v-for="section in sections"
                    :key="section.key"
                    class="mb-4"
                    :aria-labelledby="`bi-section-${section.key}`"
                    :data-section="section.key"
                >
                    <div class="d-flex align-items-end flex-wrap gap-2 mb-2">
                        <div class="me-auto">
                            <h2 :id="`bi-section-${section.key}`" class="fs-6 fw-semibold mb-0">{{ tx(`section_${section.key}`) }}</h2>
                            <p class="small text-body-secondary mb-0">{{ tx(`section_${section.key}_hint`) }}</p>
                        </div>
                        <template v-for="action in section.actions" :key="action.key">
                            <Link
                                v-if="action.href"
                                :href="action.href"
                                class="btn btn-outline-primary btn-sm"
                                :data-action="action.key"
                            >
                                <i class="ti me-1" :class="action.icon" aria-hidden="true"></i>{{ action.label }}
                            </Link>
                        </template>
                    </div>

                    <div class="row g-3" :class="rowColsClass(section.items.length)">
                        <div v-for="item in section.items" :key="item.key" class="col" :data-kpi="item.key">
                            <KpiCard
                                :label="item.label"
                                :value="item.value"
                                :icon="item.icon"
                                :tone="item.tone"
                                :hint="item.hint"
                                :subtitle="item.subtitle"
                                :loading="loading"
                                :test-id="item.key"
                            />
                        </div>
                    </div>
                </section>

                <div class="row g-3 mb-3">
                    <!-- Tendência mensal: receita × despesa + saldo -->
                    <div class="col-xl-8">
                        <section class="card border-0 shadow-sm h-100" aria-labelledby="bi-trend-title" data-test="trend">
                            <div class="card-header bg-transparent border-bottom">
                                <h2 id="bi-trend-title" class="fs-6 mb-0 fw-semibold">
                                    <i class="ti ti-chart-bar me-1 text-primary" aria-hidden="true"></i>{{ tx('monthly_trend') }}
                                </h2>
                                <p class="small text-body-secondary mb-0">{{ tx('monthly_trend_hint') }}</p>
                            </div>
                            <div class="card-body">
                                <p v-if="trendRows.length === 0 || trendEmpty" class="text-center text-body-secondary py-4 mb-0" data-test="trend-empty">
                                    {{ tx('no_trend_data') }}
                                </p>
                                <TrendBarChart v-else :rows="trendRows" :t="t" />
                            </div>
                        </section>
                    </div>

                    <!-- Mix da agenda -->
                    <div class="col-xl-4">
                        <section class="card border-0 shadow-sm h-100" aria-labelledby="bi-schedule-mix-title" data-test="schedule-mix">
                            <div class="card-header bg-transparent border-bottom">
                                <h2 id="bi-schedule-mix-title" class="fs-6 mb-0 fw-semibold">
                                    <i class="ti ti-chart-donut me-1 text-primary" aria-hidden="true"></i>{{ tx('schedule_mix') }}
                                </h2>
                                <p class="small text-body-secondary mb-0">{{ tx('schedule_mix_hint') }}</p>
                            </div>
                            <div class="card-body">
                                <p v-if="scheduleTotal === 0" class="text-center text-body-secondary py-4 mb-0" data-test="schedule-empty">
                                    {{ tx('no_schedules') }}
                                </p>
                                <DonutChart v-else :slices="scheduleSlices" :total-label="tx('schedule_total')" :t="t" />
                            </div>
                        </section>
                    </div>
                </div>

                <!-- Faturamento por convênio (top 6) -->
                <section class="card border-0 shadow-sm" aria-labelledby="bi-covenant-title" data-test="by-covenant">
                    <div class="card-header bg-transparent border-bottom">
                        <h2 id="bi-covenant-title" class="fs-6 mb-0 fw-semibold">
                            <i class="ti ti-building-hospital me-1 text-primary" aria-hidden="true"></i>{{ tx('billing_by_covenant') }}
                        </h2>
                        <p class="small text-body-secondary mb-0">{{ tx('billing_by_covenant_hint') }}</p>
                    </div>
                    <div class="card-body">
                        <p v-if="covenantRows.length === 0" class="text-center text-body-secondary py-4 mb-0">
                            {{ tx('no_claims') }}
                        </p>
                        <ul v-else class="list-unstyled mb-0 row row-cols-1 row-cols-lg-2 g-3">
                            <li v-for="row in covenantRows" :key="row.key" class="col" data-test="covenant-row">
                                <div class="d-flex justify-content-between gap-2 small">
                                    <span class="fw-medium text-truncate">{{ row.label }}</span>
                                    <span class="text-body fw-semibold text-nowrap bi-num">{{ money(row.value) }}</span>
                                </div>
                                <div class="progress mt-1 bi-progress" aria-hidden="true">
                                    <div class="progress-bar bg-primary" :style="{ width: `${row.width}%` }"></div>
                                </div>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.bi-num {
    font-variant-numeric: tabular-nums;
}

.bi-progress {
    height: 6px;
}
</style>
