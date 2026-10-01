<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useTrans } from '@/composables/useTrans.js';
import BillingKpis from './BillingKpis.vue';
import BillingFlowStepper from './BillingFlowStepper.vue';
import BillingFilterBar from './BillingFilterBar.vue';
import EligibleTable from './EligibleTable.vue';
import ClaimsTable from './ClaimsTable.vue';
import BatchesTable from './BatchesTable.vue';
import IndividualClaimModal from './IndividualClaimModal.vue';
import BatchFormModal from './BatchFormModal.vue';
import ReceiptModal from './ReceiptModal.vue';
import DenyClaimModal from './DenyClaimModal.vue';
import SubmitBatchModal from './SubmitBatchModal.vue';
import ImportReturnModal from './ImportReturnModal.vue';
import PendingResultModal from './PendingResultModal.vue';
import FixPendingModal from './FixPendingModal.vue';
import ClaimsSelectionBar from './ClaimsSelectionBar.vue';
import BillingOperations from './BillingOperations.vue';
import { cleanParams, firstError, pageRows } from './billingHelpers.js';
import { useClaimSelection } from './useClaimSelection.js';

/**
 * Faturamento — atendimento → guia (individual ou lote) → envio do lote →
 * recebimento/glosa. KPIs do período no topo, fluxo explicado em uma linha e
 * três abas (A faturar, Guias, Lotes). Período (data do atendimento),
 * convênio, status e lote ficam na URL, aplicados na hora, e voltam
 * normalizados do servidor em `filters`; a aba ativa também vai na URL.
 * Cada aba é paginada no servidor e tem busca e ordem próprias
 * (?{aba}_page=, ?{aba}_search=, ?{aba}_sort=/_direction=, normalizadas em
 * `lists`); os KPIs são sempre do conjunto filtrado inteiro.
 *
 * Regras de transição (pagar/glosar/enviar/cancelar/corrigir/anexar) são do
 * servidor: a tela só mostra as ações de `allowed_actions`. Ações em lote
 * (recebimento das guias selecionadas ou do lote, adicionar/reprocessar
 * guias do lote, incluir guia em lote) ficam em BillingOperations. Textos:
 * lang/{locale}/financial_billing.php. Emissão TISS real via app/Domains/Tiss;
 * convênio sem registro ANS (particular) fica fora do protocolo — ver
 * ResolveTissOperatorForCovenantAction.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    /** Paginators do Laravel ({ data, links, total... }), um por aba. */
    eligibleSchedules: { type: [Object, Array], default: () => ({ data: [] }) },
    claims: { type: [Object, Array], default: () => ({ data: [] }) },
    batches: { type: [Object, Array], default: () => ({ data: [] }) },
    kpis: { type: Object, default: () => ({}) },
    totals: { type: Object, default: () => ({}) },
    /** Busca/ordem aplicadas por aba: { eligible: { search, sort, direction, default_sort, default_direction }, ... }. */
    lists: { type: Object, default: () => ({}) },
    covenants: { type: Array, default: () => [] },
    filteredCovenant: { type: Object, default: null },
    filters: { type: Object, default: () => ({}) },
    claimStatuses: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    /** Recebimento das guias selecionadas (JSON) e o teto de guias por pedido. */
    bulkReceiptUrl: { type: String, default: '' },
    bulkMaxClaims: { type: Number, default: 200 },
    storeIndividualUrl: { type: String, required: true },
    storeBatchUrl: { type: String, required: true },
    importReturnUrl: { type: String, required: true },
    glosasUrl: { type: String, default: '' },
    procedurePricesUrl: { type: String, default: '' },
    cid10SearchUrl: { type: String, default: '' },
    glosaReasons: { type: Array, default: () => [] },
    tussCodes: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const { tx } = useTrans(() => props.t);

/* ───────────────────────── Abas (na URL) ───────────────────────── */
const TAB_KEYS = ['eligible', 'claims', 'batches'];

const activeTab = ref(TAB_KEYS.includes(props.filters.tab) ? props.filters.tab : 'eligible');

/** Paginator de cada aba (as três vêm em toda resposta). */
const PAGES = {
    eligible: () => props.eligibleSchedules,
    claims: () => props.claims,
    batches: () => props.batches,
};

function pageTotal(page) {
    return Array.isArray(page) ? page.length : Number(page?.total ?? 0);
}

const tabs = computed(() => [
    {
        key: 'eligible',
        icon: 'ti ti-list-check',
        label: props.t.tab_eligible,
        count: props.totals.eligible ?? pageTotal(props.eligibleSchedules),
    },
    {
        key: 'claims',
        icon: 'ti ti-file-invoice',
        label: props.t.tab_claims,
        count: props.totals.claims ?? pageTotal(props.claims),
    },
    {
        key: 'batches',
        icon: 'ti ti-package',
        label: props.t.tab_batches,
        count: props.totals.batches ?? pageTotal(props.batches),
    },
]);

function setTab(key) {
    if (!TAB_KEYS.includes(key) || key === activeTab.value) return;

    activeTab.value = key;

    // Só atualiza a URL (sem ida ao servidor): recarregar ou compartilhar o
    // link reabre a mesma aba, e o back() das ações redireciona para ela.
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', key);
        // Sem `props`: mantém as props atuais (reescrever `filters` aqui
        // dispararia os watchers dos filtros com valores antigos).
        router.replace({
            url: `${url.pathname}${url.search}`,
            preserveState: true,
            preserveScroll: true,
            flash: (current) => current,
        });
    } catch {
        // URL indisponível (ex.: ambiente de teste) — a aba local já mudou.
    }
}

function onTabKeydown(event, key) {
    const index = TAB_KEYS.indexOf(key);
    const moves = { ArrowRight: 1, ArrowLeft: -1, Home: -index, End: TAB_KEYS.length - 1 - index };
    if (!(event.key in moves)) return;

    event.preventDefault();
    const next = TAB_KEYS[(index + moves[event.key] + TAB_KEYS.length) % TAB_KEYS.length];
    setTab(next);
    nextTick(() => document.getElementById(`billing-tab-${next}`)?.focus());
}

// Aba que voltou do servidor numa visita (ex.: KPI que abre a aba Guias).
watch(
    () => props.filters?.tab,
    (tab) => {
        if (TAB_KEYS.includes(tab)) activeTab.value = tab;
    },
);

/* ───────────────────────── Filtros (na URL, aplicação automática) ───────────────────────── */
const SEARCH_DEBOUNCE_MS = 400;

const filtering = ref(false);

const currentParams = computed(() => ({
    from: props.filters.from,
    to: props.filters.to,
    covenant_id: props.filters.covenant_id,
    claim_status: props.filters.claim_status,
    batch_id: props.filters.batch_id,
    tab: activeTab.value,
}));

// Busca digitada em cada aba: estado local (o que está no campo), enviado
// com debounce; o servidor devolve a normalizada em `lists`.
const searchTerms = reactive(Object.fromEntries(TAB_KEYS.map((tab) => [tab, props.lists?.[tab]?.search ?? ''])));
const searchTimers = {};

/**
 * Busca e ordem de cada aba para a URL — a ordem só quando difere do padrão
 * da aba (URL limpa). `sortOverrides` troca a ordem de uma aba.
 */
function listParams(sortOverrides = {}) {
    const params = {};

    TAB_KEYS.forEach((tab) => {
        const list = props.lists?.[tab] ?? {};
        const sort = sortOverrides[tab]?.sort ?? list.sort;
        const dir = sortOverrides[tab]?.direction ?? list.direction;

        if (searchTerms[tab]) params[`${tab}_search`] = searchTerms[tab];

        if (sort && (sort !== list.default_sort || dir !== list.default_direction)) {
            params[`${tab}_sort`] = sort;
            params[`${tab}_direction`] = dir;
        }
    });

    return params;
}

/** Página atual das demais abas (a aba que mudou volta para a 1ª). */
function pageParams(exceptTab) {
    const params = {};

    TAB_KEYS.forEach((tab) => {
        const current = Number(PAGES[tab]()?.current_page ?? 1);
        if (tab !== exceptTab && current > 1) params[`${tab}_page`] = current;
    });

    return params;
}

function visit(params) {
    router.get(route('panel.financial.billing.index'), cleanParams(params), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => {
            filtering.value = true;
        },
        onFinish: () => {
            filtering.value = false;
        },
    });
}

/** Filtro global (período, convênio, status, lote): as três listas voltam para a 1ª página. */
function applyFilters(patch) {
    if (TAB_KEYS.includes(patch.tab)) activeTab.value = patch.tab;

    visit({ ...currentParams.value, ...listParams(), ...patch, tab: activeTab.value });
}

function clearFilters() {
    TAB_KEYS.forEach((tab) => {
        clearTimeout(searchTimers[tab]);
        searchTerms[tab] = '';
    });

    visit({ tab: activeTab.value });
}

function onSearch(tab, value) {
    searchTerms[tab] = value ?? '';
    clearTimeout(searchTimers[tab]);

    searchTimers[tab] = setTimeout(() => {
        visit({ ...currentParams.value, ...listParams(), ...pageParams(tab) });
    }, SEARCH_DEBOUNCE_MS);
}

function onSort(tab, { sort, direction }) {
    visit({ ...currentParams.value, ...listParams({ [tab]: { sort, direction } }), ...pageParams(tab) });
}

onBeforeUnmount(() => TAB_KEYS.forEach((tab) => clearTimeout(searchTimers[tab])));

/** KPIs "Em aberto" / "Pendências TISS": filtram (ou desfiltram) a aba Guias. */
function toggleClaimStatus(status) {
    applyFilters({ claim_status: props.filters.claim_status === status ? '' : status, tab: 'claims' });
}

/** Código do lote na aba Guias → aba Lotes filtrada por ele (e vice-versa). */
function filterByBatch(claim) {
    applyFilters({ batch_id: claim.batch_id, tab: 'batches' });
}

function viewBatchClaims(batch) {
    applyFilters({ batch_id: batch.id, tab: 'claims' });
}

/* ───────────────────────── Avisos da página ───────────────────────── */
const actionError = ref('');

/* ───────────────────────── Seleção (A faturar) ───────────────────────── */
// Marcados guardados com a linha (id → atendimento): a seleção atravessa as
// páginas, a busca e a ordem (o contador mostra o total), e o modal de lote
// recebe as linhas marcadas de outras páginas junto com as desta.
const selectedSchedules = ref({});
const selectedScheduleIds = computed(() => Object.keys(selectedSchedules.value));
const eligibleRows = computed(() => pageRows(props.eligibleSchedules));

function selectionScope() {
    return [props.filters.from, props.filters.to, props.filters.covenant_id].join('|');
}

let lastSelectionScope = selectionScope();

// Período/convênio novos mudam quem é elegível: descarta os marcados que não
// estão mais na lista (antes ficavam "invisíveis" e iam para o lote).
watch(
    () => props.eligibleSchedules,
    () => {
        const scope = selectionScope();
        if (scope === lastSelectionScope) return;

        lastSelectionScope = scope;
        const visible = new Set(eligibleRows.value.map((s) => s.id));

        selectedSchedules.value = Object.fromEntries(
            Object.entries(selectedSchedules.value).filter(([id]) => visible.has(id)),
        );
    },
);

function toggleSchedule(id) {
    const next = { ...selectedSchedules.value };

    if (next[id]) {
        delete next[id];
    } else {
        const row = eligibleRows.value.find((s) => s.id === id);
        if (row) next[id] = row;
    }

    selectedSchedules.value = next;
}

/** Cabeçalho: marca/desmarca a página atual (as outras páginas ficam como estão). */
function toggleSelectAll() {
    const rows = eligibleRows.value;
    const allSelected = rows.length > 0 && rows.every((s) => selectedSchedules.value[s.id]);
    const next = { ...selectedSchedules.value };

    rows.forEach((s) => {
        if (allSelected) delete next[s.id];
        else next[s.id] = s;
    });

    selectedSchedules.value = next;
}

/** Linhas para o modal de lote: a página atual + os marcados de outras páginas. */
const batchCandidates = computed(() => {
    const rows = eligibleRows.value;
    const visible = new Set(rows.map((s) => s.id));

    return [...rows, ...Object.values(selectedSchedules.value).filter((s) => !visible.has(s.id))];
});

/* ───────────────────────── Seleção (Guias) → recebimento em lote ───────────────────────── */
// Só guias com 'pay' em allowed_actions; atravessa páginas (o total soma as
// marcadas de outras páginas); filtros novos descartam o que saiu da lista.
const {
    ids: selectedClaimIds,
    rows: selectedClaimRows,
    count: selectedClaimCount,
    total: selectedClaimTotal,
    toggle: toggleClaimSelection,
    togglePage: toggleClaimPage,
    clear: clearClaimSelection,
} = useClaimSelection(
    () => props.claims,
    () =>
        [
            props.filters.from,
            props.filters.to,
            props.filters.covenant_id,
            props.filters.claim_status,
            props.filters.batch_id,
        ].join('|'),
);

const selectionOnOtherPages = computed(() => {
    const visible = new Set(pageRows(props.claims).map((c) => c.id));

    return selectedClaimIds.value.some((id) => !visible.has(id));
});

/* ───────────────────────── Ações em lote (BillingOperations) ───────────────────────── */
const operation = ref(null); // { kind, claims? | batch? | claim? }

function openOperation(kind, payload = {}) {
    actionError.value = '';
    operation.value = { kind, ...payload };
}

/** Gravou (o modal segue aberto com o resultado): lista, lotes e KPIs recarregam por trás. */
function onOperationDone(kind) {
    if (kind === 'bulk_receipt') clearClaimSelection();

    router.reload({ only: ['claims', 'batches', 'kpis', 'totals'], preserveScroll: true });
}

/* ───────────────────────── Guia individual / Lote ───────────────────────── */
const individualOpen = ref(false);
const individualSchedule = ref(null);
const batchOpen = ref(false);

function openIndividual(schedule) {
    individualSchedule.value = schedule;
    individualOpen.value = true;
}

function onBatchSaved() {
    batchOpen.value = false;
    selectedSchedules.value = {};
}

/* ───────────────────────── Recebimento / Glosa ───────────────────────── */
const receiptOpen = ref(false);
const receiptClaim = ref(null);
const denyOpen = ref(false);
const denyClaim = ref(null);

function openReceipt(claim) {
    actionError.value = '';
    receiptClaim.value = claim;
    receiptOpen.value = true;
}

function openDeny(claim) {
    actionError.value = '';
    denyClaim.value = claim;
    denyOpen.value = true;
}

/* ───────────────────────── Enviar lote (com confirmação) ───────────────────────── */
const submitOpen = ref(false);
const submitTarget = ref(null);
const submitError = ref('');
const submittingBatchId = ref(null);

function askSubmit(batch) {
    submitTarget.value = batch;
    submitError.value = '';
    submitOpen.value = true;
}

function confirmSubmit() {
    const batch = submitTarget.value;
    if (!batch || submittingBatchId.value) return;

    router.post(
        batch.submit_url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                submittingBatchId.value = batch.id;
                submitError.value = '';
            },
            onError: (errors) => {
                submitError.value = firstError(errors) || props.t.unexpected_error;
            },
            onSuccess: () => {
                submitOpen.value = false;
            },
            onFinish: () => {
                submittingBatchId.value = null;
            },
        },
    );
}

/* ───────────────────────── Cancelar guia / lote (motivo obrigatório) ───────────────────────── */
const CANCEL_REASON_MIN = 10;
const CANCEL_REASON_MAX = 1000;

const cancelTarget = ref(null); // { kind: 'claim' | 'batch', row }
const cancelSaving = ref(false);
const cancelError = ref('');

const cancelTexts = computed(() => {
    const target = cancelTarget.value;
    if (!target) return { title: '', message: '', confirm: '' };

    return target.kind === 'batch'
        ? {
              title: tx('cancel_batch_title', { code: target.row.code }),
              message: props.t.cancel_batch_message,
              confirm: props.t.cancel_batch_confirm,
          }
        : {
              title: tx('cancel_claim_title', { code: target.row.code }),
              message: props.t.cancel_claim_message,
              confirm: props.t.cancel_claim_confirm,
          };
});

/**
 * O modal de motivo não devolve o foco: volta ao botão "Mais ações" da linha
 * (ou, se a linha não tem mais o menu, à aba ativa).
 */
function restoreRowFocus(kind, id) {
    nextTick(() => {
        const trigger = document.querySelector(`[data-row-actions="${kind}-${id}"] button`);
        (trigger ?? document.getElementById(`billing-tab-${activeTab.value}`))?.focus?.({ preventScroll: true });
    });
}

function askCancel(kind, row) {
    actionError.value = '';
    cancelError.value = '';
    cancelTarget.value = { kind, row };
}

function closeCancel() {
    if (cancelSaving.value) return;

    const target = cancelTarget.value;
    cancelTarget.value = null;
    if (target) restoreRowFocus(target.kind, target.row.id);
}

function confirmCancel(reason) {
    const target = cancelTarget.value;
    if (!target || cancelSaving.value) return;

    router.post(
        target.row.cancel_url,
        { reason },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                cancelSaving.value = true;
                cancelError.value = '';
            },
            onError: (errors) => {
                cancelError.value = firstError(errors) || props.t.unexpected_error;
            },
            onSuccess: () => {
                cancelTarget.value = null;
                restoreRowFocus(target.kind, target.row.id);
            },
            onFinish: () => {
                cancelSaving.value = false;
            },
        },
    );
}

/* ───────────────────────── Corrigir pendência (guia TISS fora do lote) ───────────────────────── */
const fixOpen = ref(false);
const fixClaim = ref(null);

function openFix(claim) {
    actionError.value = '';
    fixClaim.value = claim;
    fixOpen.value = true;
}

/** Correção salva: o modal mostra o resultado; a lista, os KPIs e os lotes recarregam por trás. */
function onFixSaved() {
    router.reload({ only: ['claims', 'batches', 'kpis', 'totals'], preserveScroll: true });
}

/* ───────────────────────── Importar retorno / Pré-validação ───────────────────────── */
const importOpen = ref(false);
const checkingClaimId = ref(null);
const pendingResult = ref(null);
const pendingResultOpen = ref(false);

async function checkPending(claim) {
    if (!claim.pre_validate_url || checkingClaimId.value) return;

    checkingClaimId.value = claim.id;
    actionError.value = '';

    try {
        const { data } = await window.axios.get(claim.pre_validate_url);
        pendingResult.value = data;
        pendingResultOpen.value = true;
    } catch {
        actionError.value = props.t.pending_check_failed;
    } finally {
        checkingClaimId.value = null;
    }
}
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.title">
                <template #actions>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            data-test="header-new-batch"
                            @click="batchOpen = true"
                        >
                            <i class="ti ti-package me-1" aria-hidden="true"></i>{{ t.btn_new_batch }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-primary btn-sm"
                            data-test="open-import"
                            @click="importOpen = true"
                        >
                            <i class="ti ti-file-upload me-1" aria-hidden="true"></i>{{ t.btn_import_return }}
                        </button>
                        <Link
                            v-if="glosasUrl"
                            :href="glosasUrl"
                            class="btn btn-outline-secondary btn-sm"
                            data-test="glosas-link"
                        >
                            <i class="ti ti-gavel me-1" aria-hidden="true"></i>{{ t.btn_glosas }}
                        </Link>
                    </div>
                </template>
            </PageHeader>

            <div
                v-if="actionError"
                class="alert alert-danger alert-dismissible d-flex align-items-center gap-2 mb-3"
                role="alert"
                data-test="action-error"
            >
                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                <span>{{ actionError }}</span>
                <button type="button" class="btn-close" :aria-label="t.dismiss" @click="actionError = ''"></button>
            </div>

            <BillingKpis
                :kpis="kpis"
                :claim-status="filters.claim_status ?? ''"
                :glosas-url="glosasUrl"
                :loading="filtering"
                :t="t"
                @toggle-status="toggleClaimStatus"
            />

            <BillingFlowStepper :t="t" />

            <BillingFilterBar
                :filters="filters"
                :covenants="covenants"
                :filtered-covenant="filteredCovenant"
                :claim-statuses="claimStatuses"
                :today="today"
                :show-status="activeTab === 'claims'"
                :filtering="filtering"
                :t="t"
                @change="applyFilters"
                @clear="clearFilters"
            />

            <!-- Abas -->
            <ul class="nav nav-tabs mb-3" role="tablist" :aria-label="t.tabs_label">
                <li v-for="tab in tabs" :key="tab.key" class="nav-item" role="presentation">
                    <button
                        :id="`billing-tab-${tab.key}`"
                        type="button"
                        role="tab"
                        :class="['nav-link', { active: activeTab === tab.key }]"
                        :aria-selected="activeTab === tab.key ? 'true' : 'false'"
                        :aria-controls="`billing-panel-${tab.key}`"
                        :tabindex="activeTab === tab.key ? 0 : -1"
                        :data-test="`tab-${tab.key}`"
                        @click="setTab(tab.key)"
                        @keydown="onTabKeydown($event, tab.key)"
                    >
                        <i :class="[tab.icon, 'me-1']" aria-hidden="true"></i>{{ tab.label }}
                        <span class="badge rounded-pill badge-soft-secondary ms-1">{{ tab.count }}</span>
                    </button>
                </li>
            </ul>

            <section
                v-show="activeTab === 'eligible'"
                id="billing-panel-eligible"
                role="tabpanel"
                aria-labelledby="billing-tab-eligible"
                :aria-busy="filtering ? 'true' : 'false'"
            >
                <EligibleTable
                    :schedules="eligibleSchedules"
                    :selected-ids="selectedScheduleIds"
                    :search="searchTerms.eligible"
                    :sort="lists.eligible?.sort"
                    :direction="lists.eligible?.direction"
                    :t="t"
                    @toggle="toggleSchedule"
                    @toggle-all="toggleSelectAll"
                    @bill="openIndividual"
                    @new-batch="batchOpen = true"
                    @search="onSearch('eligible', $event)"
                    @sort="onSort('eligible', $event)"
                />
            </section>

            <section
                v-show="activeTab === 'claims'"
                id="billing-panel-claims"
                role="tabpanel"
                aria-labelledby="billing-tab-claims"
                :aria-busy="filtering ? 'true' : 'false'"
            >
                <ClaimsTable
                    :claims="claims"
                    :search="searchTerms.claims"
                    :sort="lists.claims?.sort"
                    :direction="lists.claims?.direction"
                    :checking-claim-id="checkingClaimId"
                    :selected-ids="selectedClaimIds"
                    :t="t"
                    @check-pending="checkPending"
                    @receive="openReceipt"
                    @deny="openDeny"
                    @filter-batch="filterByBatch"
                    @fix-pending="openFix"
                    @attach="openOperation('attach_claim', { claim: $event })"
                    @cancel="askCancel('claim', $event)"
                    @toggle-select="toggleClaimSelection"
                    @toggle-select-page="toggleClaimPage"
                    @search="onSearch('claims', $event)"
                    @sort="onSort('claims', $event)"
                />
                <ClaimsSelectionBar
                    :count="selectedClaimCount"
                    :total="selectedClaimTotal"
                    :other-pages="selectionOnOtherPages"
                    :t="t"
                    @receive="openOperation('bulk_receipt', { claims: selectedClaimRows })"
                    @clear="clearClaimSelection"
                />
            </section>

            <section
                v-show="activeTab === 'batches'"
                id="billing-panel-batches"
                role="tabpanel"
                aria-labelledby="billing-tab-batches"
                :aria-busy="filtering ? 'true' : 'false'"
            >
                <BatchesTable
                    :batches="batches"
                    :search="searchTerms.batches"
                    :sort="lists.batches?.sort"
                    :direction="lists.batches?.direction"
                    :submitting-batch-id="submittingBatchId"
                    :t="t"
                    @submit="askSubmit"
                    @view-claims="viewBatchClaims"
                    @cancel="askCancel('batch', $event)"
                    @receive="openOperation('batch_receipt', { batch: $event })"
                    @add-claims="openOperation('add_claims', { batch: $event })"
                    @reprocess="openOperation('reprocess', { batch: $event })"
                    @search="onSearch('batches', $event)"
                    @sort="onSort('batches', $event)"
                />
            </section>
        </div>

        <IndividualClaimModal
            :open="individualOpen"
            :schedule="individualSchedule"
            :covenants="covenants"
            :tuss-codes="tussCodes"
            :url="storeIndividualUrl"
            :cid10-search-url="cid10SearchUrl"
            :procedure-prices-url="procedurePricesUrl"
            :t="t"
            @close="individualOpen = false"
            @saved="individualOpen = false"
        />

        <BatchFormModal
            :open="batchOpen"
            :covenants="covenants"
            :eligible-schedules="batchCandidates"
            :selected-ids="selectedScheduleIds"
            :filters="filters"
            :tuss-codes="tussCodes"
            :url="storeBatchUrl"
            :cid10-search-url="cid10SearchUrl"
            :procedure-prices-url="procedurePricesUrl"
            :t="t"
            @close="batchOpen = false"
            @saved="onBatchSaved"
        />

        <ReceiptModal
            :open="receiptOpen"
            :claim="receiptClaim"
            :payment-methods="paymentMethods"
            :today="today"
            :t="t"
            @close="receiptOpen = false"
            @saved="receiptOpen = false"
        />

        <DenyClaimModal
            :open="denyOpen"
            :claim="denyClaim"
            :glosa-reasons="glosaReasons"
            :glosas-url="glosasUrl"
            :t="t"
            @close="denyOpen = false"
        />

        <SubmitBatchModal
            :open="submitOpen"
            :batch="submitTarget"
            :processing="submittingBatchId !== null"
            :error="submitError"
            :t="t"
            @close="submitOpen = false"
            @confirm="confirmSubmit"
        />

        <ConfirmationWithReasonModal
            :open="cancelTarget !== null"
            :title="cancelTexts.title"
            :message="cancelTexts.message"
            :confirm-label="cancelTexts.confirm"
            confirm-variant="danger"
            :saving="cancelSaving"
            :min-length="CANCEL_REASON_MIN"
            :max-length="CANCEL_REASON_MAX"
            :error="cancelError"
            @close="closeCancel"
            @confirm="confirmCancel"
        />

        <BillingOperations
            :operation="operation"
            :payment-methods="paymentMethods"
            :today="today"
            :bulk-receipt-url="bulkReceiptUrl"
            :bulk-max-claims="bulkMaxClaims"
            :t="t"
            @close="operation = null"
            @done="onOperationDone"
        />

        <FixPendingModal
            :open="fixOpen"
            :claim="fixClaim"
            :cid10-search-url="cid10SearchUrl"
            :t="t"
            @close="fixOpen = false"
            @saved="onFixSaved"
        />

        <ImportReturnModal
            :open="importOpen"
            :covenants="covenants"
            :url="importReturnUrl"
            :t="t"
            @close="importOpen = false"
            @saved="importOpen = false"
        />

        <PendingResultModal
            :open="pendingResultOpen"
            :result="pendingResult"
            :t="t"
            @close="pendingResultOpen = false"
        />
    </AppLayout>
</template>
