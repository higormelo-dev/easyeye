<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans';
import CovenantClaimsPanel from './CovenantClaimsPanel.vue';
import { usePercent, usePlural } from './useReportPage.js';

/**
 * Consolidado por convênio (agregado no servidor, uma linha por convênio).
 *
 * - Ordenação NO CLIENTE sobre o agregado: todas as linhas já estão na tela
 *   (dezenas, não milhares), então reordenar não precisa de nova consulta nem
 *   de viagem ao servidor; a ordem padrão (maior faturado) é a do servidor.
 * - Primeira coluna fixa (sticky, fundo var(--bs-body-bg)) para a rolagem
 *   horizontal no celular não perder o nome do convênio.
 * - Linha expansível (um convênio por vez) com as guias do período:
 *   aria-expanded/aria-controls no botão; Esc ou "Fechar" recolhem e o foco
 *   volta ao botão do convênio.
 * - % Glosa acima do limiar ganha selo com TEXTO ("Alta"), não só cor.
 */
const props = defineProps({
    rows:      { type: Array,  default: () => [] },   // byCovenant
    summary:   { type: Object, default: () => ({}) },
    filters:   { type: Object, required: true },      // { from, to } aplicados
    threshold: { type: Number, default: 10 },         // glosa_alert_threshold (%)
    claimsUrl: { type: String, default: '' },         // routes.claims
    t:         { type: Object, default: () => ({}) },
});

/** Chave da linha "Sem convênio" (covenant_id '' — a mesma do BI) no DOM. */
const NO_COVENANT_KEY = 'none';

const COLUMNS = [
    { key: 'covenant', label: 'col_covenant', class: 'covenants-table__sticky' },
    { key: 'amount', label: 'col_billed', class: 'text-end' },
    { key: 'paid', label: 'col_received', class: 'text-end' },
    { key: 'denied', label: 'col_glosa', class: 'text-end d-none d-lg-table-cell' },
    { key: 'open', label: 'col_open', class: 'text-end d-none d-lg-table-cell' },
    { key: 'glosa_rate', label: 'col_glosa_rate', class: 'text-end' },
    { key: 'received_rate', label: 'col_received_rate', class: 'text-end d-none d-lg-table-cell' },
];

const { locale, money } = useLocaleFormat();
const { tx: txRoot } = useTrans(() => props.t);
const { tx } = useTrans(() => props.t.covenants ?? {});
const { percent } = usePercent();
const { plural } = usePlural();

const uid       = useId();
const headingId = `covenants-table-title-${uid}`;

const c = computed(() => props.t.covenants ?? {});

const thresholdText = computed(() => percent(props.threshold, 0));
const alertHint     = computed(() => tx('glosa_alert_hint', { threshold: thresholdText.value }));

// ── Ordenação (cliente) ─────────────────────────────────────────────────────
const sort = ref({ key: 'amount', dir: 'desc' });

const collator = computed(() => new Intl.Collator(locale.value, { sensitivity: 'base', numeric: true }));

function isBlank(value) {
    return value === null || value === undefined || value === '';
}

/** Desempate estável: nome do convênio e depois o id. */
function tieBreak(a, b) {
    return collator.value.compare(a.covenant ?? '', b.covenant ?? '')
        || String(a.covenant_id ?? '').localeCompare(String(b.covenant_id ?? ''));
}

const sortedRows = computed(() => {
    const { key, dir } = sort.value;
    const factor = dir === 'asc' ? 1 : -1;

    return [...props.rows].sort((a, b) => {
        if (key === 'covenant') {
            return (collator.value.compare(a.covenant ?? '', b.covenant ?? '') * factor) || tieBreak(a, b);
        }

        // Percentual indefinido (sem faturado) sempre por último.
        if (isBlank(a[key]) || isBlank(b[key])) {
            if (isBlank(a[key]) && isBlank(b[key])) return tieBreak(a, b);

            return isBlank(a[key]) ? 1 : -1;
        }

        return ((Number(a[key]) - Number(b[key])) * factor) || tieBreak(a, b);
    });
});

function onSort({ sort: key, direction }) {
    sort.value = { key, dir: direction };
}

const sortTitle = (column) => txRoot('sort_by', { column: c.value[column.label] ?? column.key });

// ── Linha expansível ────────────────────────────────────────────────────────
const expandedKey = ref(null);
const toggleButtons = new Map();

const rowKey   = (row) => (row.covenant_id ? String(row.covenant_id) : NO_COVENANT_KEY);
const detailId = (row) => `covenant-claims-${uid}-${rowKey(row)}`;
const isOpen   = (row) => expandedKey.value === rowKey(row);

function bindToggle(row) {
    return (element) => {
        if (element) toggleButtons.set(rowKey(row), element);
        else toggleButtons.delete(rowKey(row));
    };
}

function toggle(row) {
    expandedKey.value = isOpen(row) ? null : rowKey(row);
}

async function collapse(row) {
    if (!isOpen(row)) return;

    expandedKey.value = null;
    await nextTick();
    toggleButtons.get(rowKey(row))?.focus();
}

// Período novo sem o convênio aberto: nada fica "expandido" apontando para o vazio.
watch(() => props.rows, (rows) => {
    if (expandedKey.value && !rows.some((row) => rowKey(row) === expandedKey.value)) expandedKey.value = null;
});

const claimsCount = (count) => plural(c.value, 'claims_count', count);
</script>

<template>
    <section class="card border-0 shadow-sm covenants-report" :aria-labelledby="headingId" data-test="covenants-table">
        <div class="card-header bg-transparent">
            <h2 :id="headingId" class="fs-6 mb-0 fw-semibold">
                <i class="ti ti-medical-cross me-1 text-primary" aria-hidden="true"></i>{{ c.by_covenant }}
            </h2>
            <p v-if="rows.length" class="small text-body-secondary mb-0" data-test="toggle-hint">{{ c.toggle_hint }}</p>
        </div>

        <div class="table-responsive covenants-report__scroll">
            <table class="table table-hover align-middle mb-0 covenants-table">
                <caption class="visually-hidden">{{ c.by_covenant }}</caption>
                <thead class="table-light">
                    <tr>
                        <SortableTh
                            v-for="column in COLUMNS"
                            :key="column.key"
                            scope="col"
                            :class="column.class"
                            :col-key="column.key"
                            :current-sort="sort.key"
                            :current-dir="sort.dir"
                            :title="sortTitle(column)"
                            @sort="onSort"
                        >{{ c[column.label] }}</SortableTh>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0">
                        <td :colspan="COLUMNS.length" class="text-center text-body-secondary py-5" data-test="covenants-empty">{{ c.no_data }}</td>
                    </tr>
                    <template v-for="row in sortedRows" :key="rowKey(row)">
                        <tr
                            :class="{ 'covenants-table__row--open': isOpen(row) }"
                            :data-covenant="rowKey(row)"
                            data-test="covenant-row"
                        >
                            <th scope="row" class="covenants-table__sticky">
                                <button
                                    :ref="bindToggle(row)"
                                    type="button"
                                    class="covenant-toggle"
                                    :aria-expanded="isOpen(row) ? 'true' : 'false'"
                                    :aria-controls="detailId(row)"
                                    data-test="covenant-toggle"
                                    @click="toggle(row)"
                                    @keydown.esc="collapse(row)"
                                >
                                    <i class="ti ti-chevron-right covenant-toggle__icon" aria-hidden="true"></i>
                                    <span class="covenant-toggle__text">
                                        <span class="covenant-toggle__name" data-test="covenant-name">{{ row.covenant }}</span>
                                        <span
                                            v-if="row.inactive"
                                            class="badge badge-soft-secondary border ms-1 fw-normal"
                                            :title="c.inactive_hint"
                                            data-test="covenant-inactive"
                                        >{{ c.inactive_badge }}</span>
                                        <span class="d-block small text-body-secondary fw-normal" data-test="covenant-claims">{{ claimsCount(row.claims) }}</span>
                                    </span>
                                </button>
                            </th>
                            <td class="text-end text-body text-nowrap" data-test="col-amount">{{ money(row.amount) }}</td>
                            <td class="text-end text-body text-nowrap" data-test="col-paid">{{ money(row.paid) }}</td>
                            <td class="text-end text-body text-nowrap d-none d-lg-table-cell" data-test="col-denied">{{ money(row.denied) }}</td>
                            <td class="text-end text-body text-nowrap d-none d-lg-table-cell" data-test="col-open">{{ money(row.open) }}</td>
                            <td class="text-end text-nowrap" data-test="col-glosa-rate">
                                <span
                                    v-if="row.glosa_alert"
                                    class="badge badge-soft-danger border border-danger fw-semibold"
                                    :title="alertHint"
                                    data-test="glosa-alert"
                                >
                                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ percent(row.glosa_rate) }} · {{ c.glosa_alert_badge }}
                                </span>
                                <span v-else class="text-body">{{ percent(row.glosa_rate) }}</span>
                            </td>
                            <td class="text-end text-body text-nowrap d-none d-lg-table-cell" data-test="col-received-rate">{{ percent(row.received_rate) }}</td>
                        </tr>
                        <tr v-if="isOpen(row)" class="covenants-table__detail" data-test="covenant-detail">
                            <td :colspan="COLUMNS.length" class="covenants-table__detail-cell">
                                <CovenantClaimsPanel
                                    :id="detailId(row)"
                                    :row="row"
                                    :filters="filters"
                                    :endpoint="claimsUrl"
                                    :t="t"
                                    @close="collapse(row)"
                                />
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot v-if="rows.length">
                    <tr class="covenants-table__totals" data-test="covenant-totals">
                        <th scope="row" class="covenants-table__sticky">
                            {{ c.footer_total }}
                            <span class="d-block small text-body-secondary fw-normal">{{ claimsCount(summary.total_claims ?? 0) }}</span>
                        </th>
                        <td class="text-end text-body text-nowrap" data-test="total-amount">{{ money(summary.total_amount ?? 0) }}</td>
                        <td class="text-end text-body text-nowrap" data-test="total-paid">{{ money(summary.total_paid ?? 0) }}</td>
                        <td class="text-end text-body text-nowrap d-none d-lg-table-cell" data-test="total-denied">{{ money(summary.total_denied ?? 0) }}</td>
                        <td class="text-end text-body text-nowrap d-none d-lg-table-cell" data-test="total-open">{{ money(summary.total_open ?? 0) }}</td>
                        <td class="text-end text-nowrap" data-test="total-glosa-rate">
                            <span v-if="summary.glosa_alert" class="badge badge-soft-danger border border-danger fw-semibold" :title="alertHint">
                                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ percent(summary.glosa_rate) }} · {{ c.glosa_alert_badge }}
                            </span>
                            <span v-else class="text-body">{{ percent(summary.glosa_rate) }}</span>
                        </td>
                        <td class="text-end text-body text-nowrap d-none d-lg-table-cell" data-test="total-received-rate">{{ percent(summary.received_rate) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p v-if="rows.length" class="card-footer bg-transparent small text-body-secondary mb-0" data-test="glosa-legend">
            <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ tx('glosa_alert_legend', { threshold: thresholdText }) }}
        </p>
    </section>
</template>

<style scoped>
/* A área rolável é o container: o painel das guias ocupa a largura VISÍVEL. */
.covenants-report__scroll {
    container-type: inline-size;
}

.covenants-table td,
.covenants-table tfoot th {
    font-variant-numeric: tabular-nums;
}

/* Primeira coluna fixa na rolagem horizontal; fundo opaco nos dois temas. */
.covenants-table .covenants-table__sticky {
    position: sticky;
    left: 0;
    z-index: 1;
    min-width: 9rem;
    max-width: 16rem;
    background-color: var(--bs-body-bg);
}

.covenants-table thead .covenants-table__sticky {
    z-index: 2;
}

.covenants-table .covenants-table__sticky::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 1px;
    background-color: var(--bs-border-color);
}

@media (min-width: 992px) {
    .covenants-table .covenants-table__sticky {
        min-width: 13rem;
        max-width: 22rem;
    }
}

.covenant-toggle {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    width: 100%;
    padding: 0;
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    text-align: start;
    cursor: pointer;
}

.covenant-toggle:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
    border-radius: 2px;
}

.covenant-toggle__icon {
    margin-top: 0.2rem;
    transition: transform var(--ee-duration-fast, 150ms) ease;
}

.covenant-toggle[aria-expanded='true'] .covenant-toggle__icon {
    transform: rotate(90deg);
}

.covenant-toggle__text {
    min-width: 0;
    overflow-wrap: anywhere;
}

.covenant-toggle:hover .covenant-toggle__name {
    text-decoration: underline;
}

.covenants-table__row--open > * {
    --bs-table-bg-state: var(--bs-tertiary-bg);
}

/* Linha de detalhe: sem o padding fixo das células do tema e sem hover. */
.covenants-table .covenants-table__detail > .covenants-table__detail-cell {
    padding: 0 !important;
    background-color: var(--bs-tertiary-bg);
    --bs-table-bg-state: transparent;
}

.covenants-table__detail-cell > :deep(.covenant-claims) {
    position: sticky;
    left: 0;
    box-sizing: border-box;
    width: 100%;
    width: 100cqi;
    max-width: 100%;
    padding: 1rem;
}

.covenants-table tfoot > tr > * {
    border-top: 2px solid var(--bs-border-color);
    padding: 12px 16px;
    font-weight: 600;
}

@media (prefers-reduced-motion: reduce) {
    .covenant-toggle__icon {
        transition: none;
    }
}
</style>
