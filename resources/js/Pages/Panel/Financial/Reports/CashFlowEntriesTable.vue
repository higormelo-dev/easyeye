<script setup>
import { computed, useId } from 'vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import SortableTh       from '@/Components/Panel/SortableTh.vue';
import TablePagination  from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans';

/**
 * Lançamentos do relatório de fluxo de caixa, paginados no servidor: tipo com
 * texto + ícone (não "R"/"D"), status em badge-soft (seguro no tema escuro),
 * valor com sinal e key estável (id). Cabeçalhos ordenáveis só nas colunas
 * da whitelist do servidor (FinancialReportsController::CASH_FLOW_SORTABLE);
 * cada linha tem o atalho para a tela de Fluxo de caixa. Colunas secundárias
 * somem em telas menores.
 */
const props = defineProps({
    entries:  { type: Object,  default: () => ({ data: [] }) }, // paginator Laravel: { data: [{ id, code, entry_date, description, category_name, covenant_name, payment_method_label, type, status, amount, cash_flow_url }], links, total, ... }
    filters:  { type: Object,  default: () => ({}) },           // { sort, direction } aplicados
    filtered: { type: Boolean, default: false },                // busca/filtros aplicados (mensagem do vazio)
    loading:  { type: Boolean, default: false },
    t:        { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['sort']);

const { signedMoney, date } = useLocaleFormat();

const headingId = `cf-entries-${useId()}`;

const TYPE_META = {
    income:  { badge: 'badge-soft-success border border-success', icon: 'ti-arrow-down-left' },
    expense: { badge: 'badge-soft-danger border border-danger', icon: 'ti-arrow-up-right' },
};

const STATUS_BADGE = {
    paid:      'badge-soft-success border border-success',
    pending:   'badge-soft-warning border border-warning',
    cancelled: 'badge-soft-secondary border border-secondary',
};

const FALLBACK_BADGE = 'badge-soft-secondary border';

const COLUMN_COUNT = 10;

const c = computed(() => props.t.cashflow ?? {});
const { tx } = useTrans(() => props.t.cashflow ?? {});
const { tx: txRoot } = useTrans(() => props.t ?? {});

const rows        = computed(() => props.entries?.data ?? []);
const currentSort = computed(() => props.filters?.sort ?? 'entry_date');
const currentDir  = computed(() => props.filters?.direction ?? 'asc');
const hasPages    = computed(() => Number(props.entries?.last_page ?? 1) > 1);

const typeLabel   = (type) => props.t.entry_type?.[type] ?? type;
const statusLabel = (status) => props.t.entry_status?.[status] ?? status;
const sortTitle   = (column) => txRoot('sort_by', { column });

/** Receita com "+", despesa com "−": a diferença não depende só da cor. */
function entryValue(entry) {
    const amount = Math.abs(Number(entry.amount) || 0);

    return signedMoney(entry.type === 'expense' ? -amount : amount);
}

/** Rótulo acessível do atalho: com o código quando existe. */
function openLabel(entry) {
    return entry.code ? tx('open_in_cash_flow_code', { code: entry.code }) : c.value.open_in_cash_flow;
}
</script>

<template>
    <section class="card border-0 shadow-sm" :aria-labelledby="headingId" data-test="entries">
        <div class="card-header bg-transparent">
            <h2 :id="headingId" class="fs-6 mb-0 fw-semibold">
                <i class="ti ti-list me-1 text-primary" aria-hidden="true"></i>{{ c.entries }}
            </h2>
            <slot name="toolbar" />
        </div>
        <slot name="alert" />
        <div class="table-responsive" :aria-busy="loading ? 'true' : 'false'">
            <table class="table table-hover align-middle mb-0 cf-entries-table" :class="{ 'cf-entries-table--loading': loading }">
                <caption class="visually-hidden">{{ c.entries }}</caption>
                <thead class="table-light">
                    <tr>
                        <SortableTh col-key="entry_date" scope="col" :current-sort="currentSort" :current-dir="currentDir" :title="sortTitle(c.col_date)" @sort="emit('sort', $event)">{{ c.col_date }}</SortableTh>
                        <SortableTh col-key="code" scope="col" class="d-none d-lg-table-cell" :current-sort="currentSort" :current-dir="currentDir" :title="sortTitle(c.col_code)" @sort="emit('sort', $event)">{{ c.col_code }}</SortableTh>
                        <SortableTh col-key="description" scope="col" :current-sort="currentSort" :current-dir="currentDir" :title="sortTitle(c.col_description)" @sort="emit('sort', $event)">{{ c.col_description }}</SortableTh>
                        <th scope="col" class="d-none d-lg-table-cell">{{ c.col_category }}</th>
                        <th scope="col" class="d-none d-xl-table-cell">{{ c.col_covenant }}</th>
                        <th scope="col" class="d-none d-xl-table-cell">{{ c.col_payment_method }}</th>
                        <SortableTh col-key="type" scope="col" class="text-center" :current-sort="currentSort" :current-dir="currentDir" :title="sortTitle(c.col_type)" @sort="emit('sort', $event)">{{ c.col_type }}</SortableTh>
                        <SortableTh col-key="status" scope="col" class="text-center" :current-sort="currentSort" :current-dir="currentDir" :title="sortTitle(c.col_status)" @sort="emit('sort', $event)">{{ c.col_status }}</SortableTh>
                        <SortableTh col-key="amount" scope="col" class="text-end" :current-sort="currentSort" :current-dir="currentDir" :title="sortTitle(c.col_value)" @sort="emit('sort', $event)">{{ c.col_value }}</SortableTh>
                        <th scope="col" class="text-end">{{ c.col_actions }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td :colspan="COLUMN_COUNT" class="text-center text-body-secondary py-5" data-test="entries-empty">
                            {{ filtered ? c.no_entries_filtered : c.no_entries }}
                        </td>
                    </tr>
                    <tr v-for="entry in rows" :key="entry.id" data-test="entry-row">
                        <td class="text-nowrap small text-body" data-test="entry-date">
                            <time :datetime="entry.entry_date">{{ date(entry.entry_date) }}</time>
                        </td>
                        <td class="d-none d-lg-table-cell text-nowrap small text-body-secondary" data-test="entry-code">{{ entry.code || '—' }}</td>
                        <td class="text-body cf-entries-table__description" :title="entry.description">{{ entry.description }}</td>
                        <td class="d-none d-lg-table-cell small text-body-secondary" data-test="entry-category">{{ entry.category_name || '—' }}</td>
                        <td class="d-none d-xl-table-cell small text-body-secondary">{{ entry.covenant_name || '—' }}</td>
                        <td class="d-none d-xl-table-cell small text-body-secondary text-nowrap" data-test="entry-payment-method">{{ entry.payment_method_label || '—' }}</td>
                        <td class="text-center">
                            <span class="badge fw-medium" :class="TYPE_META[entry.type]?.badge ?? FALLBACK_BADGE" data-test="entry-type">
                                <i class="ti me-1" :class="TYPE_META[entry.type]?.icon" aria-hidden="true"></i>{{ typeLabel(entry.type) }}
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge fw-medium" :class="STATUS_BADGE[entry.status] ?? FALLBACK_BADGE" data-test="entry-status">{{ statusLabel(entry.status) }}</span>
                        </td>
                        <td class="text-end fw-semibold text-body text-nowrap cf-entries-table__value" data-test="entry-value">{{ entryValue(entry) }}</td>
                        <td class="text-end">
                            <ActionIconButton
                                v-if="entry.cash_flow_url"
                                icon="ti ti-external-link"
                                variant="info"
                                :title="openLabel(entry)"
                                :inertia-href="entry.cash_flow_url"
                                data-test="entry-open"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="hasPages" class="px-3 pb-3" data-test="entries-pagination">
            <TablePagination
                :data="entries"
                :showing-from="c.pagination_showing"
                :showing-of="c.pagination_of"
                :showing-suffix="c.pagination_suffix"
                :aria-label="c.pagination_label"
                :previous-label="c.pagination_previous"
                :next-label="c.pagination_next"
            />
        </div>
    </section>
</template>

<style scoped>
.cf-entries-table__description {
    max-width: 22rem;
    overflow-wrap: anywhere;
}

.cf-entries-table__value {
    font-variant-numeric: tabular-nums;
}

.cf-entries-table--loading tbody {
    opacity: 0.6;
    transition: opacity var(--cf-fade, 150ms) ease-out;
}

@media (prefers-reduced-motion: reduce) {
    .cf-entries-table--loading tbody {
        transition: none;
    }
}
</style>
