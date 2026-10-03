<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import AiUsageTrendChart from './AiUsageTrendChart.vue';
import AiUsageBreakdown from './AiUsageBreakdown.vue';
import AiUsageRunDrawer from './AiUsageRunDrawer.vue';
import { runStatusClass } from './runStatus.js';

/**
 * Manager → Uso de IA: custo (R$/US$) e uso de IA de todas as clínicas e da
 * plataforma — indicadores com comparação ao período anterior, série no
 * tempo, rankings por ação/clínica/usuário/provedor (clique = filtro),
 * execuções com detalhe e exportação CSV. Só metadados (nunca conteúdo).
 */
const props = defineProps({
    filters: { type: Object, required: true },
    presets: { type: Array, default: () => [] },
    kpis: { type: Object, required: true },
    series: { type: Object, required: true },
    byWorkflow: { type: Array, default: () => [] },
    byEntity: { type: Object, default: () => ({ rows: [], total: 0 }) },
    byUser: { type: Object, default: () => ({ rows: [], total: 0 }) },
    byProvider: { type: Array, default: () => [] },
    runs: { type: Object, required: true },
    options: { type: Object, default: () => ({ entities: [], workflows: [], providers: [], statuses: [] }) },
    rate: { type: Object, required: true },
    topLimit: { type: Number, default: 15 },
    t: { type: Object, default: () => ({}) },
});

const { locale, money, number, date, dateTime } = useLocaleFormat();
const page = usePage();

/** ':nome' → valor (mesma regra do Laravel/useTrans), pra textos aninhados de `t`. */
function fill(text, params = {}) {
    return String(text ?? '').replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, (match, name) =>
        Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match,
    );
}

function usd(value) {
    return new Intl.NumberFormat(locale.value, {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    }).format(Number(value) || 0);
}

// ── Filtros (server-side, na URL) ───────────────────────────────────────
const loading = ref(false);
const customFrom = ref(props.filters.from);
const customTo = ref(props.filters.to);
const showCustom = ref(props.filters.preset === 'custom');

watch(
    () => props.filters,
    (filters) => {
        customFrom.value = filters.from;
        customTo.value = filters.to;
        showCustom.value = filters.preset === 'custom';
    },
);

const FILTER_KEYS = ['entity_id', 'user_id', 'workflow', 'provider', 'status'];

/** Parâmetros atuais (+ alterações), sem vazios nem padrões — URL limpa e compartilhável. */
function params(changes = {}) {
    const merged = {
        preset: props.filters.preset,
        from: props.filters.from,
        to: props.filters.to,
        sort: props.filters.sort,
        direction: props.filters.direction,
        ...Object.fromEntries(FILTER_KEYS.map((key) => [key, props.filters[key]])),
        ...changes,
    };

    if (merged.preset !== 'custom') {
        delete merged.from;
        delete merged.to;
    }
    if (merged.preset === 'this_month') delete merged.preset;
    if (merged.sort === 'created_at' && merged.direction === 'desc') {
        delete merged.sort;
        delete merged.direction;
    }

    return Object.fromEntries(
        Object.entries(merged).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    );
}

function visit(changes = {}) {
    router.get(route('manager.ai-usage.index'), params({ ...changes }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

function selectPreset(preset) {
    if (preset === 'custom') {
        showCustom.value = true;
        return;
    }
    showCustom.value = false;
    visit({ preset });
}

// Recorte recusado pelo servidor (ex.: mais de 2 anos) — mensagem já traduzida.
const periodError = computed(() => {
    const errors = page.props?.errors ?? {};

    return errors.to ?? errors.from ?? errors.preset ?? null;
});

function applyCustom() {
    if (!customFrom.value || !customTo.value) return;
    visit({ preset: 'custom', from: customFrom.value, to: customTo.value });
}

function setFilter(key, value) {
    visit({ [key]: value || null });
}

const hasFilters = computed(() => FILTER_KEYS.some((key) => props.filters[key]));

function clearFilters() {
    visit(Object.fromEntries(FILTER_KEYS.map((key) => [key, null])));
}

// Exportação usa o mesmo recorte; a ordenação é só da tabela.
const exportUrl = computed(() => {
    const query = params();
    delete query.sort;
    delete query.direction;

    return route('manager.ai-usage.export', query);
});

// ── Indicadores ─────────────────────────────────────────────────────────
const current = computed(() => props.kpis.current);
const delta = computed(() => props.kpis.delta ?? {});

/**
 * Variação com cor por significado: `upIsGood` true (receita, uso) → subir é
 * verde; false (custo, falha) → subir é vermelho. Texto sempre presente
 * (não depende só de cor).
 */
function deltaInfo(value, upIsGood, unit = '%') {
    if (value === null || value === undefined) return null;
    const up = value > 0;
    const formatted =
        unit === 'pp'
            ? fill(props.t.kpi?.delta_pp, { value: number(Math.abs(value), 1) })
            : `${number(Math.abs(value), 1)}%`;

    return {
        icon: value === 0 ? 'ti-minus' : up ? 'ti-arrow-up-right' : 'ti-arrow-down-right',
        tone: value === 0 ? 'au-delta--neutral' : up === upIsGood ? 'au-delta--good' : 'au-delta--bad',
        text: fill(up ? props.t.kpi?.delta_up : props.t.kpi?.delta_down, { value: formatted }),
        short: `${up ? '+' : value < 0 ? '−' : ''}${formatted}`,
    };
}

const cards = computed(() => {
    const c = current.value;
    const d = delta.value;
    const k = props.t.kpi ?? {};

    return [
        {
            key: 'cost',
            icon: 'ti-currency-dollar',
            tone: 'primary',
            label: k.cost,
            value: money(c.cost_brl),
            sub: fill(k.cost_usd, { value: usd(c.cost_usd) }),
            delta: deltaInfo(d.cost_brl, false),
        },
        {
            key: 'runs',
            icon: 'ti-bolt',
            tone: 'info',
            label: k.runs,
            value: number(c.runs),
            sub: fill(k.calls, { count: number(c.calls) }),
            delta: deltaInfo(d.runs, true),
        },
        {
            key: 'failure',
            icon: 'ti-alert-triangle',
            tone: c.failure_rate > 0 ? 'danger' : 'success',
            label: k.failure_rate,
            value: `${number(c.failure_rate, 1)}%`,
            sub: fill(k.failed_of, { failed: number(c.failed), total: number(c.runs) }),
            delta: deltaInfo(d.failure_rate_pp, false, 'pp'),
        },
        {
            key: 'avg',
            icon: 'ti-calculator',
            tone: 'secondary',
            label: k.avg_cost,
            value: money(c.avg_cost_brl),
            sub: fill(k.tokens, { in: compact(c.tokens_in), out: compact(c.tokens_out) }),
            delta: deltaInfo(d.avg_cost_brl, false),
        },
        {
            key: 'credits',
            icon: 'ti-coin',
            tone: 'warning',
            label: k.credits,
            value: number(c.credits),
            sub: null,
            delta: deltaInfo(d.credits, true),
        },
        {
            key: 'revenue',
            icon: 'ti-cash',
            tone: 'success',
            label: k.revenue,
            value: money(c.revenue_brl),
            sub: null,
            delta: deltaInfo(d.revenue_brl, true),
        },
        {
            key: 'margin',
            icon: c.margin_brl >= 0 ? 'ti-trending-up' : 'ti-trending-down',
            tone: c.margin_brl >= 0 ? 'success' : 'danger',
            label: k.margin,
            value: money(c.margin_brl),
            sub: c.margin_pct === null ? k.margin_none : fill(k.margin_pct, { pct: number(c.margin_pct, 1) }),
            delta: deltaInfo(d.margin_brl, true),
        },
        {
            key: 'active',
            icon: 'ti-users',
            tone: 'secondary',
            label: k.active,
            value: number(c.entities),
            sub: fill(k.active_detail, { entities: number(c.entities), users: number(c.users) }),
            delta: null,
        },
    ];
});

function compact(value) {
    return new Intl.NumberFormat(locale.value, { notation: 'compact', maximumFractionDigits: 1 }).format(
        Number(value) || 0,
    );
}

const rateNote = computed(() =>
    props.rate.is_fallback
        ? fill(props.t.rate_fallback, { rate: money(props.rate.rate) })
        : fill(props.t.rate_note, { rate: money(props.rate.rate), date: date(props.rate.topped_up_at) }),
);

const revenueNote = computed(() => fill(props.t.revenue_note, { value: number(props.rate.usd_per_credit, 2) }));

// ── Rankings ────────────────────────────────────────────────────────────
const col = computed(() => props.t.columns ?? {});

const workflowColumns = computed(() => [
    { key: 'runs', label: col.value.runs, type: 'number' },
    { key: 'failure_rate', label: col.value.failure_rate, type: 'percent' },
    { key: 'cost_brl', label: col.value.cost, type: 'money' },
    { key: 'avg_cost_brl', label: col.value.avg_cost, type: 'money', class: 'd-none d-lg-table-cell' },
    { key: 'credits', label: col.value.credits, type: 'number', class: 'd-none d-md-table-cell' },
]);

const entityColumns = computed(() => [
    { key: 'runs', label: col.value.runs, type: 'number' },
    { key: 'failure_rate', label: col.value.failure_rate, type: 'percent', class: 'd-none d-md-table-cell' },
    { key: 'cost_brl', label: col.value.cost, type: 'money' },
    { key: 'avg_cost_brl', label: col.value.avg_cost, type: 'money', class: 'd-none d-xxl-table-cell' },
    { key: 'revenue_brl', label: col.value.revenue, type: 'money', class: 'd-none d-lg-table-cell' },
    { key: 'margin_brl', label: col.value.margin, type: 'money', class: 'd-none d-lg-table-cell' },
]);

const userColumns = computed(() => [
    { key: 'runs', label: col.value.runs, type: 'number' },
    { key: 'failure_rate', label: col.value.failure_rate, type: 'percent', class: 'd-none d-md-table-cell' },
    { key: 'cost_brl', label: col.value.cost, type: 'money' },
    { key: 'avg_cost_brl', label: col.value.avg_cost, type: 'money', class: 'd-none d-lg-table-cell' },
]);

const providerColumns = computed(() => [
    { key: 'calls', label: col.value.calls, type: 'number' },
    { key: 'call_failure_rate', label: col.value.failure_rate, type: 'percent' },
    {
        key: 'skipped_calls',
        label: col.value.skipped,
        type: 'number',
        hint: col.value.skipped_hint,
        class: 'd-none d-md-table-cell',
    },
    { key: 'avg_latency_ms', label: col.value.latency, type: 'latency', class: 'd-none d-lg-table-cell' },
    { key: 'tokens', label: col.value.tokens, type: 'tokens', class: 'd-none d-xl-table-cell' },
    { key: 'cost_brl', label: col.value.cost, type: 'money' },
]);

const providerRows = computed(() =>
    props.byProvider.map((row) => ({ ...row, label: `${row.provider_label} · ${row.model}` })),
);

// ── Execuções ───────────────────────────────────────────────────────────
function onSort({ sort, direction }) {
    visit({ sort, direction });
}

const drawerOpen = ref(false);
const drawerRunId = ref(null);

function openRun(run) {
    drawerRunId.value = run.id;
    drawerOpen.value = true;
}

const userFilterLabel = computed(() => fill(props.t.filters?.user, { name: props.filters.user_name ?? '' }));

const breadcrumbs = computed(() => [
    { label: props.t.breadcrumb_home, url: route('panel.dashboard'), active: false },
    { label: props.t.title, url: '#', active: true },
]);
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <!-- Cabeçalho -->
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
                <div class="me-auto">
                    <h4 class="fw-bold mb-0">{{ t.title }}</h4>
                    <p class="text-muted mb-0 small" style="max-width: 680px">{{ t.subtitle }}</p>
                </div>
                <a :href="exportUrl" class="btn btn-outline-primary btn-sm" data-test="export-csv">
                    <i class="ti ti-file-spreadsheet me-1" aria-hidden="true"></i>{{ t.export_csv }}
                </a>
            </div>

            <!-- Período -->
            <div class="d-flex flex-wrap align-items-center gap-1 mb-2" role="group" :aria-label="t.period?.label">
                <button
                    v-for="preset in presets"
                    :key="preset"
                    type="button"
                    class="btn btn-sm"
                    :class="
                        (preset === 'custom' ? showCustom : filters.preset === preset && !showCustom)
                            ? 'btn-primary'
                            : 'btn-outline-secondary'
                    "
                    :aria-pressed="filters.preset === preset"
                    :disabled="loading"
                    @click="selectPreset(preset)"
                >
                    {{ t.period?.[preset] }}
                </button>
                <span v-if="loading" class="spinner-border spinner-border-sm text-primary ms-2" role="status">
                    <span class="visually-hidden">{{ t.loading }}</span>
                </span>
            </div>

            <form
                v-if="showCustom"
                class="d-flex flex-wrap align-items-end gap-2 mb-3 au-custom"
                @submit.prevent="applyCustom"
            >
                <div>
                    <label class="form-label small mb-1" for="au-from">{{ t.period?.from }}</label>
                    <input
                        id="au-from"
                        v-model="customFrom"
                        type="date"
                        class="form-control form-control-sm"
                        required
                    />
                </div>
                <div>
                    <label class="form-label small mb-1" for="au-to">{{ t.period?.to }}</label>
                    <input
                        id="au-to"
                        v-model="customTo"
                        type="date"
                        class="form-control form-control-sm"
                        :min="customFrom"
                        required
                    />
                </div>
                <button type="submit" class="btn btn-primary btn-sm">{{ t.period?.apply }}</button>
                <div v-if="periodError" class="w-100 small text-danger" role="alert" data-test="period-error">
                    {{ periodError }}
                </div>
            </form>

            <!-- Filtros -->
            <div class="row g-2 mb-2 align-items-end">
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label small mb-1" for="au-entity">{{ t.filters?.entity }}</label>
                    <select
                        id="au-entity"
                        class="form-select form-select-sm"
                        :value="filters.entity_id ?? ''"
                        @change="setFilter('entity_id', $event.target.value)"
                    >
                        <option value="">{{ t.filters?.entity_all }}</option>
                        <option v-for="option in options.entities" :key="option.value" :value="option.value">
                            {{ option.is_internal ? `${option.label} — ${t.internal}` : option.label }}
                        </option>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label small mb-1" for="au-workflow">{{ t.filters?.workflow }}</label>
                    <select
                        id="au-workflow"
                        class="form-select form-select-sm"
                        :value="filters.workflow ?? ''"
                        @change="setFilter('workflow', $event.target.value)"
                    >
                        <option value="">{{ t.filters?.workflow_all }}</option>
                        <option v-for="option in options.workflows" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label small mb-1" for="au-provider">{{ t.filters?.provider }}</label>
                    <select
                        id="au-provider"
                        class="form-select form-select-sm"
                        :value="filters.provider ?? ''"
                        @change="setFilter('provider', $event.target.value)"
                    >
                        <option value="">{{ t.filters?.provider_all }}</option>
                        <option v-for="option in options.providers" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label small mb-1" for="au-status">{{ t.filters?.status }}</label>
                    <select
                        id="au-status"
                        class="form-select form-select-sm"
                        :value="filters.status ?? ''"
                        @change="setFilter('status', $event.target.value)"
                    >
                        <option value="">{{ t.filters?.status_all }}</option>
                        <option v-for="option in options.statuses" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>
            </div>

            <div v-if="hasFilters" class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span
                    v-if="filters.user_id"
                    class="badge badge-soft-primary rounded fs-12 d-inline-flex align-items-center gap-1"
                >
                    <i class="ti ti-user" aria-hidden="true"></i>{{ userFilterLabel }}
                    <button
                        type="button"
                        class="btn-close ms-1"
                        :aria-label="t.filters?.remove_filter"
                        :title="t.filters?.remove_filter"
                        @click="setFilter('user_id', null)"
                    ></button>
                </span>
                <button type="button" class="btn btn-link btn-sm p-0" data-test="clear-filters" @click="clearFilters">
                    <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ t.filters?.clear }}
                </button>
            </div>

            <!-- Notas: cotação, receita, comparação, LGPD -->
            <div class="small text-muted mb-3 d-grid gap-1">
                <div :class="{ 'text-warning-emphasis': rate.is_fallback }" data-test="rate-note">
                    <i class="ti ti-currency-dollar me-1" aria-hidden="true"></i>{{ rateNote }}
                </div>
                <div>
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ revenueNote }} {{ t.compare_note }}
                </div>
                <div><i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ t.lgpd_note }}</div>
            </div>

            <!-- Indicadores -->
            <div class="row g-3 mb-3">
                <div v-for="card in cards" :key="card.key" class="col-6 col-xl-3">
                    <div class="au-stat" :class="`au-stat--${card.tone}`" :data-test="`kpi-${card.key}`">
                        <div class="au-stat__icon" aria-hidden="true"><i :class="`ti ${card.icon}`"></i></div>
                        <div class="min-w-0">
                            <div class="au-stat__label">{{ card.label }}</div>
                            <div class="au-stat__value">{{ card.value }}</div>
                            <div v-if="card.sub" class="au-stat__sub text-truncate" :title="card.sub">
                                {{ card.sub }}
                            </div>
                            <div v-if="card.delta" class="au-delta" :class="card.delta.tone" :title="card.delta.text">
                                <i :class="`ti ${card.delta.icon}`" aria-hidden="true"></i>
                                <span aria-hidden="true">{{ card.delta.short }}</span>
                                <span class="visually-hidden">{{ card.delta.text }}</span>
                            </div>
                            <div v-else-if="card.key !== 'active'" class="au-delta au-delta--neutral">
                                {{ t.kpi?.no_compare }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Série -->
            <div class="card mb-3">
                <div class="card-header bg-transparent">
                    <h2 class="h6 mb-0 fw-semibold">
                        <i class="ti ti-chart-bar me-1 text-primary" aria-hidden="true"></i
                        >{{ series.granularity === 'month' ? t.chart?.title_month : t.chart?.title_day }}
                    </h2>
                </div>
                <div class="card-body">
                    <AiUsageTrendChart :points="series.points" :granularity="series.granularity" :t="t.chart ?? {}" />
                </div>
            </div>

            <!-- Rankings -->
            <div class="row g-3 mb-3">
                <div class="col-12 col-xl-6">
                    <AiUsageBreakdown
                        :title="t.breakdown?.by_workflow"
                        icon="ti ti-sparkles"
                        :rows="byWorkflow"
                        :columns="workflowColumns"
                        :name-label="col.action"
                        :total-cost="current.cost_brl"
                        drillable
                        :t="t.breakdown ?? {}"
                        @select="(row) => setFilter('workflow', row.workflow)"
                    />
                </div>
                <div class="col-12 col-xl-6">
                    <AiUsageBreakdown
                        :title="t.breakdown?.by_provider"
                        icon="ti ti-server-2"
                        :rows="providerRows"
                        :columns="providerColumns"
                        :name-label="col.provider"
                        :total-cost="current.cost_brl"
                        drillable
                        :t="t.breakdown ?? {}"
                        @select="(row) => setFilter('provider', row.provider)"
                    />
                </div>
                <div class="col-12 col-xl-6">
                    <AiUsageBreakdown
                        :title="t.breakdown?.by_entity"
                        icon="ti ti-building-hospital"
                        :rows="byEntity.rows"
                        :columns="entityColumns"
                        name-key="name"
                        :name-label="col.entity"
                        :total-cost="current.cost_brl"
                        :total="byEntity.total"
                        drillable
                        :internal-label="t.internal"
                        :t="t.breakdown ?? {}"
                        @select="(row) => setFilter('entity_id', row.entity_id)"
                    />
                </div>
                <div class="col-12 col-xl-6">
                    <AiUsageBreakdown
                        :title="t.breakdown?.by_user"
                        icon="ti ti-user"
                        :rows="byUser.rows"
                        :columns="userColumns"
                        name-key="name"
                        sub-key="entity_name"
                        :name-label="col.user"
                        :total-cost="current.cost_brl"
                        :total="byUser.total"
                        drillable
                        :internal-label="t.internal"
                        :t="t.breakdown ?? {}"
                        @select="(row) => visit({ user_id: row.user_id, entity_id: row.entity_id })"
                    />
                </div>
            </div>

            <!-- Execuções -->
            <div class="card mb-0">
                <div class="card-header bg-transparent">
                    <h2 class="h6 mb-0 fw-semibold">
                        <i class="ti ti-list-details me-1 text-primary" aria-hidden="true"></i>{{ t.runs_title }}
                    </h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <SortableTh
                                    col-key="created_at"
                                    :current-sort="filters.sort"
                                    :current-dir="filters.direction"
                                    @sort="onSort"
                                    >{{ col.date }}</SortableTh
                                >
                                <th>{{ col.entity }}</th>
                                <th class="d-none d-md-table-cell">{{ col.user }}</th>
                                <th>{{ col.action }}</th>
                                <th>{{ col.status }}</th>
                                <th class="d-none d-lg-table-cell">{{ col.providers }}</th>
                                <th class="d-none d-lg-table-cell text-end">{{ col.credits }}</th>
                                <SortableTh
                                    col-key="cost"
                                    :current-sort="filters.sort"
                                    :current-dir="filters.direction"
                                    class="text-end"
                                    @sort="onSort"
                                    >{{ col.cost }}</SortableTh
                                >
                                <th class="text-end">
                                    <span class="visually-hidden">{{ t.view_run }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!runs.data.length">
                                <td colspan="9" class="text-center text-muted py-4">{{ t.runs_empty }}</td>
                            </tr>
                            <tr v-for="run in runs.data" :key="run.id" data-test="run-row">
                                <td class="text-nowrap small">{{ dateTime(run.created_at) }}</td>
                                <td class="small">
                                    {{ run.entity_name }}
                                    <span v-if="run.is_internal" class="badge badge-soft-info rounded fs-11 ms-1">{{
                                        t.internal
                                    }}</span>
                                </td>
                                <td class="small d-none d-md-table-cell">{{ run.user_name }}</td>
                                <td class="small">{{ run.workflow_label }}</td>
                                <td>
                                    <span class="badge" :class="runStatusClass(run.status)">{{
                                        run.status_label
                                    }}</span>
                                    <div v-if="run.failed_calls" class="small text-danger">
                                        <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                                        {{ number(run.failed_calls) }}/{{ number(run.calls) }}
                                    </div>
                                </td>
                                <td class="small d-none d-lg-table-cell">{{ run.providers.join(', ') || '—' }}</td>
                                <td class="small d-none d-lg-table-cell text-end au-num">{{ number(run.credits) }}</td>
                                <td class="text-end au-num small" :title="usd(run.cost_usd)">
                                    {{ money(run.cost_brl) }}
                                </td>
                                <td class="text-end">
                                    <ActionIconButton icon="ti ti-eye" :title="t.view_run" @click="openRun(run)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-transparent">
                    <TablePagination
                        :data="runs"
                        :showing-from="t.showing_from"
                        :showing-of="t.showing_of"
                        :showing-suffix="t.showing_suffix"
                        :previous-label="t.previous"
                        :next-label="t.next"
                    />
                </div>
            </div>
        </div>

        <AiUsageRunDrawer :open="drawerOpen" :run-id="drawerRunId" :t="t" @close="drawerOpen = false" />
    </AppLayout>
</template>

<style scoped>
.au-custom {
    background: var(--bs-tertiary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
}

.au-stat {
    --au-tone: var(--bs-secondary);
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    height: 100%;
    padding: 0.875rem 1rem;
    background: var(--bs-card-bg, var(--bs-body-bg));
    border: 1px solid var(--bs-border-color);
    border-top: 3px solid var(--au-tone);
    border-radius: 0.75rem;
}
.au-stat--primary {
    --au-tone: var(--bs-primary);
}
.au-stat--info {
    --au-tone: var(--bs-info);
}
.au-stat--success {
    --au-tone: var(--bs-success);
}
.au-stat--danger {
    --au-tone: var(--bs-danger);
}
.au-stat--warning {
    --au-tone: var(--bs-warning);
}
.au-stat__icon {
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    border-radius: 0.6rem;
    color: var(--au-tone);
    background: var(--bs-tertiary-bg);
}
.au-stat__label {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--bs-secondary-color);
}
.au-stat__value {
    font-size: 1.2rem;
    font-weight: 800;
    line-height: 1.25;
    color: var(--bs-emphasis-color);
    font-variant-numeric: tabular-nums;
}
.au-stat__sub {
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
}
.au-delta {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-top: 2px;
    font-size: 0.72rem;
    font-weight: 700;
}
.au-delta--good {
    color: var(--bs-success);
}
.au-delta--bad {
    color: var(--bs-danger);
}
.au-delta--neutral {
    color: var(--bs-secondary-color);
    font-weight: 500;
}
.au-num {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
</style>
