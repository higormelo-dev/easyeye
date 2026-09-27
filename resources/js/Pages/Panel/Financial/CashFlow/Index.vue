<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout          from '@/Layouts/AppLayout.vue';
import PageHeader         from '@/Components/Panel/PageHeader.vue';
import PeriodFilter       from '@/Components/Panel/PeriodFilter.vue';
import KpiCard            from '@/Components/Panel/KpiCard.vue';
import SearchInput        from '@/Components/Panel/SearchInput.vue';
import SearchSelect       from '@/Components/Panel/SearchSelect.vue';
import TablePagination    from '@/Components/Panel/TablePagination.vue';
import CenteredModal      from '@/Components/Panel/CenteredModal.vue';
import { useTrans }        from '@/composables/useTrans';
import CashEntryFormModal  from './CashEntryFormModal.vue';
import CashFlowTable       from './CashFlowTable.vue';
import CashFlowCards       from './CashFlowCards.vue';
import { useCashEntryFormat } from './useCashEntryFormat.js';

/**
 * Fluxo de caixa: filtros na mesma barra (período, busca, tipo, status,
 * categoria) aplicados automaticamente, KPIs do servidor com os MESMOS
 * filtros da tabela (overview), tabela ordenável (md+) / cards (abaixo de md)
 * com totais do conjunto filtrado, lançamento em modal e exclusão confirmada.
 */
const props = defineProps({
    breadcrumbs:       { type: Array,   default: () => [] },
    entries:           { type: Object,  required: true },
    overview:          { type: Object,  default: () => ({}) },
    categories:        { type: Array,   default: () => [] },
    covenants:         { type: Array,   default: () => [] },
    payment_methods:   { type: Array,   default: () => [] },
    // Fechamentos ativos que cruzam o período filtrado ([{ period_start, period_end }]).
    closed_periods:    { type: Array,   default: () => [] },
    filters:           { type: Object,  default: () => ({}) },
    today:             { type: String,  default: '' },
    can_edit_schedule: { type: Boolean, default: false },
    t:                 { type: Object,  default: () => ({}) },
});

const { tx } = useTrans(() => props.t);
const { money, signedMoney, date, typeLabel, statusLabel, entryAmount } = useCashEntryFormat(() => props.t);

const uid = useId();
const ids = {
    category: `cash-flow-category-${uid}`,
    delTitle: `cash-flow-delete-title-${uid}`,
};

const SEARCH_DEBOUNCE_MS = 400;
const STATUSES           = ['pending', 'paid', 'cancelled'];
const DEFAULT_SORT       = 'entry_date';
const DEFAULT_DIRECTION  = 'desc';

// `today` junto: aba aberta de um dia para o outro recebe o dia novo ao salvar/excluir.
const RELOAD_PROPS = ['entries', 'overview', 'closed_periods', 'today'];

const rows = computed(() => props.entries?.data ?? []);

// ── Filtros: estado local (fonte da verdade da barra), aplicados na hora ────
const period         = ref({ from: props.filters.from ?? '', to: props.filters.to ?? '' });

// O servidor normaliza o período (data inválida → mês atual; início > fim →
// invertido): a barra passa a mostrar o período que os KPIs/tabela usam.
watch(() => [props.filters.from, props.filters.to], ([from, to]) => {
    period.value = { from: from ?? '', to: to ?? '' };
});
const search         = ref(props.filters.search ?? '');
const typeFilter     = ref(props.filters.type ?? '');
const statusFilter   = ref(props.filters.status ?? '');
const categoryFilter = ref(props.filters.category_id ?? '');
const loading        = ref(false);

function withoutEmpty(values) {
    return Object.fromEntries(Object.entries(values).filter(([, v]) => v !== null && v !== undefined && v !== ''));
}

function queryParams(overrides = {}) {
    const params = {
        from:        period.value.from,
        to:          period.value.to,
        search:      search.value.trim(),
        type:        typeFilter.value,
        status:      statusFilter.value,
        category_id: categoryFilter.value,
        sort:        props.filters.sort,
        direction:   props.filters.direction,
        ...overrides,
    };

    // Ordenação padrão fica fora da URL.
    if (params.sort === DEFAULT_SORT && params.direction === DEFAULT_DIRECTION) {
        params.sort      = '';
        params.direction = '';
    }

    return withoutEmpty(params);
}

/** Troca de filtro/ordem: volta para a página 1, mantém o resto e não empilha histórico. */
function visit(overrides = {}) {
    router.get(route('panel.financial.cash-flow.index'), queryParams(overrides), {
        preserveState:  true,
        preserveScroll: true,
        replace:        true,
        onStart:        () => { loading.value = true; },
        onFinish:       () => { loading.value = false; },
    });
}

let searchTimer       = null;
let skipSearchWatcher = false;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = null;

    if (skipSearchWatcher) {
        skipSearchWatcher = false;

        return;
    }

    searchTimer = setTimeout(() => {
        searchTimer = null;
        visit();
    }, SEARCH_DEBOUNCE_MS);
});

onBeforeUnmount(() => clearTimeout(searchTimer));

function onPeriodChange({ from, to }) {
    period.value = { from, to };
    visit();
}

const typeOptions = computed(() => [
    { value: '',        label: props.t.filter_type_all },
    { value: 'income',  label: props.t.filter_type_income },
    { value: 'expense', label: props.t.filter_type_expense },
]);

function setType(value) {
    if (typeFilter.value === value) return;

    typeFilter.value = value;

    // Categoria do outro tipo nunca teria resultado: sai junto.
    const category = props.categories.find((c) => c.id === categoryFilter.value);
    if (value && category && category.type !== value) categoryFilter.value = '';

    visit();
}

function onStatusChange(event) {
    statusFilter.value = event.target.value;
    visit();
}

const categoryOptions = computed(() => (typeFilter.value
    ? props.categories.filter((c) => c.type === typeFilter.value)
    : props.categories));

function onCategoryChange(value) {
    const next = value ?? '';
    if (next === categoryFilter.value) return;

    categoryFilter.value = next;
    visit();
}

function onSort({ sort, direction }) {
    visit({ sort, direction });
}

const hasListFilters = computed(() => !!(search.value.trim() || typeFilter.value || statusFilter.value || categoryFilter.value));

/** Limpa busca, tipo, status e categoria; o período escolhido continua. */
function clearFilters() {
    clearTimeout(searchTimer);
    searchTimer = null;

    if (search.value !== '') skipSearchWatcher = true;

    search.value         = '';
    typeFilter.value     = '';
    statusFilter.value   = '';
    categoryFilter.value = '';
    visit();
}

/**
 * Recarrega lista/KPIs após salvar ou excluir. Se a página atual (> 1) ficou
 * vazia — excluiu o último item dela —, vai para a última página com dados em
 * vez de mostrar "nenhum lançamento" com o total ainda > 0.
 */
function reloadList() {
    router.reload({
        only: RELOAD_PROPS,
        onSuccess: (page) => {
            const entries = page?.props?.entries;
            if (!entries || entries.data?.length || !(entries.current_page > 1) || !entries.last_page_url) return;

            router.get(entries.last_page_url, {}, { preserveState: true, preserveScroll: true, replace: true });
        },
    });
}

// ── Atalhos do cabeçalho (mantêm o De/Até atual) ────────────────────────────
const periodParams = computed(() => withoutEmpty({ from: props.filters.from, to: props.filters.to }));

// Fechamento não aceita fim no futuro: o atalho já leva o "Até" limitado a hoje.
const closeCashHref = computed(() => {
    const to = props.today && props.filters.to && props.filters.to > props.today ? props.today : props.filters.to;

    return route('panel.financial.cash-closing.index', withoutEmpty({ from: props.filters.from, to }));
});
const reportHref = computed(() => route('panel.financial.reports.cash-flow', periodParams.value));

// ── Aviso de período fechado ────────────────────────────────────────────────
const closedPeriodsText = computed(() => props.closed_periods
    .map((p) => `${date(p.period_start)}–${date(p.period_end)}`)
    .join(', '));

// ── KPIs (overview do servidor, mesmos filtros da tabela) ───────────────────
const kpis = computed(() => {
    const o = props.overview ?? {};

    return [
        { key: 'received',          tone: 'success',   icon: 'ti ti-arrow-down-left', value: money(o.received ?? 0) },
        { key: 'receivable',        tone: 'info',      icon: 'ti ti-clock',           value: money(o.receivable ?? 0) },
        { key: 'paid',              tone: 'danger',    icon: 'ti ti-arrow-up-right',  value: money(o.paid ?? 0) },
        { key: 'payable',           tone: 'warning',   icon: 'ti ti-clock-pause',     value: money(o.payable ?? 0) },
        { key: 'realized_balance',  tone: 'primary',   icon: 'ti ti-scale',           value: signedMoney(o.realized_balance ?? 0) },
        { key: 'projected_balance', tone: 'secondary', icon: 'ti ti-trending-up',     value: signedMoney(o.projected_balance ?? 0) },
    ].map((kpi) => ({ ...kpi, label: props.t[`kpi_${kpi.key}`], hint: props.t[`kpi_${kpi.key}_hint`] }));
});

// ── Modal de lançamento ─────────────────────────────────────────────────────
const formOpen     = ref(false);
const editingEntry = ref(null);

function openCreate() {
    editingEntry.value = null;
    formOpen.value     = true;
}

function openEdit(entry) {
    if (entry.lock_reason) return;

    editingEntry.value = entry;
    formOpen.value     = true;
}

/** `keepOpen`: "Salvar e lançar outro" — o modal continua aberto para o próximo. */
function onSaved({ message = '', entryDate = '', keepOpen = false } = {}) {
    if (!keepOpen) formOpen.value = false;

    let text = message;
    if (entryDate && ((props.filters.from && entryDate < props.filters.from) || (props.filters.to && entryDate > props.filters.to))) {
        // Sem este aviso o lançamento "sumia" da lista e era lançado de novo (duplicado).
        text = `${text} ${tx('saved_outside_period', { date: date(entryDate) })}`.trim();
    }
    if (text) window.showSuccessToast?.(text);

    reloadList();
}

// ── Exclusão com confirmação (resumo do lançamento) ─────────────────────────
const deleting        = ref(null);
const deleteBusy      = ref(false);
const deleteError     = ref('');
const deleteCancelBtn = ref(null);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function errorMessageFrom(json, status, fallback) {
    const first = json?.errors && typeof json.errors === 'object' ? Object.values(json.errors)[0] : null;
    if (first) return Array.isArray(first) ? String(first[0] ?? '') : String(first);
    if (status === 419) return props.t.session_expired;

    return json?.message || fallback;
}

function askDelete(entry) {
    if (entry.lock_reason) return;

    deleteError.value = '';
    deleting.value    = entry;
}

function cancelDelete() {
    if (deleteBusy.value) return;

    deleting.value = null;
}

async function confirmDelete() {
    const entry = deleting.value;
    if (!entry || deleteBusy.value) return;

    deleteBusy.value  = true;
    deleteError.value = '';

    try {
        const res = await fetch(route('panel.financial.cash-flow.destroy', entry.id), {
            method:  'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        const json = await res.json().catch(() => ({}));

        if (!res.ok) {
            deleteError.value = errorMessageFrom(json, res.status, props.t.delete_error);

            return;
        }

        deleting.value = null;
        window.showSuccessToast?.(json.message || props.t.deleted);
        reloadList();
    } catch {
        deleteError.value = props.t.network_error;
    } finally {
        deleteBusy.value = false;
    }
}

function onDeleteKeydown(event) {
    if (event.key === 'Escape') cancelDelete();
}

watch(deleting, async (entry) => {
    if (!entry) {
        document.removeEventListener('keydown', onDeleteKeydown);

        return;
    }

    document.addEventListener('keydown', onDeleteKeydown);
    await nextTick();
    deleteCancelBtn.value?.focus();
});

onBeforeUnmount(() => document.removeEventListener('keydown', onDeleteKeydown));

const deletingId = computed(() => (deleteBusy.value ? deleting.value?.id : null));
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.page_title" :total="entries.total ?? 0" :total-label="t.total_label">
                <template #actions>
                    <div class="d-flex flex-wrap gap-2">
                        <Link :href="reportHref" class="btn btn-outline-secondary btn-sm" data-test="report-link">
                            <i class="ti ti-report-analytics me-1" aria-hidden="true"></i>{{ t.report }}
                        </Link>
                        <Link :href="closeCashHref" class="btn btn-outline-primary btn-sm" data-test="close-cash-link">
                            <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ t.close_cash }}
                        </Link>
                        <button type="button" class="btn btn-primary btn-sm" data-test="new-entry" @click="openCreate">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.new_entry }}
                        </button>
                    </div>
                </template>
            </PageHeader>

            <!-- Filtros: aplicação automática (busca com debounce) -->
            <div class="cash-flow-toolbar d-flex flex-wrap align-items-end gap-2 mb-3" role="search" :aria-label="t.filters_label" data-test="filters">
                <PeriodFilter
                    compact
                    :from="period.from"
                    :to="period.to"
                    :today="today"
                    :labels="t.shared?.period"
                    @change="onPeriodChange"
                />
                <SearchInput
                    v-model="search"
                    :placeholder="t.search_placeholder"
                    :clear-label="t.search_clear"
                    max-width="300px"
                    wrapper-class="cash-flow-toolbar__search"
                />
                <div class="btn-group btn-group-sm" role="group" :aria-label="t.filter_type" data-test="type-filter">
                    <button
                        v-for="option in typeOptions"
                        :key="option.value || 'all'"
                        type="button"
                        class="btn"
                        :class="typeFilter === option.value ? 'btn-primary' : 'btn-outline-secondary'"
                        :aria-pressed="typeFilter === option.value ? 'true' : 'false'"
                        :data-test="`type-${option.value || 'all'}`"
                        @click="setType(option.value)"
                    >{{ option.label }}</button>
                </div>
                <select
                    class="form-select form-select-sm cash-flow-toolbar__select"
                    :aria-label="t.filter_status"
                    :value="statusFilter"
                    data-test="status-filter"
                    @change="onStatusChange"
                >
                    <option value="">{{ t.filter_status_all }}</option>
                    <option v-for="status in STATUSES" :key="status" :value="status">{{ statusLabel(status) }}</option>
                </select>
                <div class="cash-flow-toolbar__category">
                    <span :id="ids.category" class="visually-hidden">{{ t.filter_category }}</span>
                    <SearchSelect
                        :model-value="categoryFilter"
                        :options="categoryOptions"
                        :placeholder="t.filter_category_all"
                        :aria-labelledby="ids.category"
                        sm
                        @update:model-value="onCategoryChange"
                    />
                </div>
                <button
                    v-if="hasListFilters"
                    type="button"
                    class="btn btn-link btn-sm text-decoration-none"
                    data-test="clear-filters"
                    @click="clearFilters"
                >
                    <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ t.filter_clear }}
                </button>
                <span class="small text-muted align-self-center" role="status" aria-live="polite" data-test="filtering">
                    <template v-if="loading">
                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ t.filtering }}
                    </template>
                </span>
            </div>

            <!-- KPIs: mesmos filtros da lista, cancelados fora -->
            <section :aria-label="t.kpis_label" class="mb-2" data-test="kpis">
                <div class="row g-3">
                    <div v-for="kpi in kpis" :key="kpi.key" class="col-6 col-md-4 col-xl-2">
                        <KpiCard
                            :label="kpi.label"
                            :value="kpi.value"
                            :icon="kpi.icon"
                            :tone="kpi.tone"
                            :hint="kpi.hint"
                            :loading="loading"
                            :test-id="kpi.key"
                        />
                    </div>
                </div>
            </section>
            <p class="small text-muted mb-3">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.kpi_scope_note }}
            </p>

            <!-- Período com caixa fechado -->
            <div v-if="closed_periods.length" class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2" role="status" data-test="closed-banner">
                <i class="ti ti-lock" aria-hidden="true"></i>
                <span class="me-auto">{{ tx('closed_banner', { periods: closedPeriodsText }) }}</span>
                <Link :href="closeCashHref" class="btn btn-sm btn-outline-secondary">{{ t.closed_banner_link }}</Link>
            </div>

            <!-- Lista: tabela (md+) e cards (abaixo de md) -->
            <div class="cash-flow-results" :class="{ 'cash-flow-results--loading': loading }" :aria-busy="loading ? 'true' : 'false'">
                <div v-if="rows.length === 0" class="card">
                    <div class="card-body text-center text-muted py-5" data-test="empty-state">
                        <i class="ti ti-cash-register fs-1 d-block mb-2" aria-hidden="true"></i>
                        <p class="mb-3">{{ hasListFilters ? t.empty_filtered : t.empty }}</p>
                        <div class="d-flex justify-content-center flex-wrap gap-2">
                            <button type="button" class="btn btn-primary btn-sm" data-test="empty-new-entry" @click="openCreate">
                                <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.new_entry }}
                            </button>
                            <button v-if="hasListFilters" type="button" class="btn btn-outline-secondary btn-sm" data-test="empty-clear-filters" @click="clearFilters">
                                <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ t.filter_clear }}
                            </button>
                        </div>
                    </div>
                </div>
                <template v-else>
                    <div class="d-none d-md-block">
                        <CashFlowTable
                            :rows="rows"
                            :overview="overview"
                            :filters="filters"
                            :busy-id="deletingId"
                            :t="t"
                            @sort="onSort"
                            @edit="openEdit"
                            @delete="askDelete"
                        />
                    </div>
                    <div class="d-md-none">
                        <CashFlowCards
                            :rows="rows"
                            :overview="overview"
                            :busy-id="deletingId"
                            :t="t"
                            @edit="openEdit"
                            @delete="askDelete"
                        />
                    </div>
                </template>
            </div>

            <TablePagination
                :data="entries"
                class="mt-3"
                :showing-from="t.pagination_showing"
                :showing-of="t.pagination_of"
                :showing-suffix="t.pagination_suffix"
                :aria-label="t.pagination_label"
                :previous-label="t.pagination_previous"
                :next-label="t.pagination_next"
            />

            <CashEntryFormModal
                :open="formOpen"
                :entry="editingEntry"
                :categories="categories"
                :covenants="covenants"
                :payment-methods="payment_methods"
                :today="today"
                :can-edit-schedule="can_edit_schedule"
                :t="t"
                @close="formOpen = false"
                @saved="onSaved"
            />

            <!-- Confirmação de exclusão com o resumo do lançamento -->
            <CenteredModal :open="!!deleting" size="sm" @close="cancelDelete">
                <template #header>
                    <h5 :id="ids.delTitle" class="modal-title mb-0">
                        <i class="ti ti-trash me-1 text-danger" aria-hidden="true"></i>{{ t.delete_title }}
                    </h5>
                </template>

                <template v-if="deleting">
                    <p class="small text-muted mb-2">{{ t.delete_message }}</p>
                    <dl class="row small mb-0" data-test="delete-summary">
                        <template v-if="deleting.code">
                            <dt class="col-5 fw-medium">{{ t.col_code }}</dt>
                            <dd class="col-7 mb-1">{{ deleting.code }}</dd>
                        </template>
                        <dt class="col-5 fw-medium">{{ t.col_description }}</dt>
                        <dd class="col-7 mb-1 text-break">{{ deleting.description }}</dd>
                        <dt class="col-5 fw-medium">{{ t.col_date }}</dt>
                        <dd class="col-7 mb-1">{{ date(deleting.entry_date) }}</dd>
                        <dt class="col-5 fw-medium">{{ t.col_type }}</dt>
                        <dd class="col-7 mb-1">{{ typeLabel(deleting.type) }}</dd>
                        <dt class="col-5 fw-medium">{{ t.col_value }}</dt>
                        <dd class="col-7 mb-0 fw-bold">{{ entryAmount(deleting) }}</dd>
                    </dl>

                    <div v-if="deleteError" class="alert alert-danger small d-flex gap-2 mt-3 mb-0" role="alert" data-test="delete-error">
                        <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i>
                        <span>{{ deleteError }}</span>
                    </div>
                </template>

                <template #footer>
                    <button ref="deleteCancelBtn" type="button" class="btn btn-outline-secondary btn-sm" :disabled="deleteBusy" @click="cancelDelete">
                        {{ t.cancel }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-danger btn-sm"
                        :disabled="deleteBusy"
                        :aria-busy="deleteBusy ? 'true' : 'false'"
                        data-test="confirm-delete"
                        @click="confirmDelete"
                    >
                        <span v-if="deleteBusy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-trash me-1" aria-hidden="true"></i>
                        {{ t.delete_confirm }}
                    </button>
                </template>
            </CenteredModal>
        </div>
    </AppLayout>
</template>

<style scoped>
.cash-flow-toolbar__select {
    width: auto;
    min-width: 10rem;
}

.cash-flow-toolbar__category {
    min-width: 12rem;
}

.cash-flow-results {
    transition: opacity var(--ee-duration-fast, 150ms) ease;
}

.cash-flow-results--loading {
    opacity: 0.6;
}

@media (prefers-reduced-motion: reduce) {
    .cash-flow-results {
        transition: none;
    }
}

/* Celular: busca, status e categoria em linha inteira. */
@media (max-width: 575.98px) {
    .cash-flow-toolbar__search,
    .cash-flow-toolbar__select,
    .cash-flow-toolbar__category {
        flex: 1 1 100%;
    }

    /* SearchInput fixa max-width inline. */
    .cash-flow-toolbar__search :deep(.input-group) {
        max-width: none !important;
    }
}
</style>
