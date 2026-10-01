<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { router, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import PricesToolbar from './PricesToolbar.vue';
import PricesSaveBar from './PricesSaveBar.vue';
import PriceAdjustModal from './PriceAdjustModal.vue';
import PriceCopyModal from './PriceCopyModal.vue';
import { useUnsavedChangesGuard } from './useUnsavedChangesGuard.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans.js';
import { formatMoneyInput } from '@/utils/money.js';

/**
 * Tabela de Preços: preço de cada procedimento por convênio.
 *
 * - Grade montada a partir do convênio que VEIO do servidor (selectedCovenantId):
 *   a troca nunca é otimista, então o Salvar grava sempre no convênio da grade.
 * - "Cobrar do convênio (guia TISS)" só vale para convênio com operadora TISS
 *   (`covenants[].tiss`, calculado no servidor); nos demais fica desligado e o
 *   servidor grava false mesmo que o request mande true.
 * - Sem preço próprio vale o preço padrão do sistema (`inheritedPrices`), que
 *   aparece como placeholder.
 * - Busca/filtros são locais; linhas alteradas ou com erro nunca somem da vista.
 * - Alterações não salvas: barra fixa com o total e confirmação ao trocar de
 *   convênio ou sair da página.
 * - Salvar envia SÓ as linhas alteradas (preço = grava; vazio = remove aquele
 *   preço); o que não vai no lote não muda no servidor. Vão junto as linhas de
 *   convênio sem TISS que o banco ainda marca para cobrança por guia (a grade
 *   já mostra desligado; salvar corrige).
 * - "Ajustar preços" (menu da grade): Reajustar % e Copiar de outro convênio,
 *   com prévia, aplicados só na grade — nada é salvo até o Salvar. Os preços
 *   do convênio de origem chegam por recarga parcial (only: ['sourcePrices']).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    covenants: { type: Array, default: () => [] }, // [{ id, name, tiss }]
    procedures: { type: Array, default: () => [] }, // [{ id, code, name }]
    selectedCovenantId: { type: String, default: '' },
    prices: { type: Object, default: () => ({}) }, // { procedure_id: { price, charging } }
    inheritedPrices: { type: Object, default: () => ({}) }, // { procedure_id: price } (padrão do sistema)
    // Recarga parcial do "Copiar de outro convênio": { covenant_id, prices: { procedure_id: price } } | null.
    sourcePrices: { type: Object, default: null },
    limits: { type: Object, default: () => ({}) }, // { max_items } por salvamento
    links: { type: Object, default: () => ({}) }, // { covenants: url|null }
    t: { type: Object, default: () => ({}) },
});

const { tx } = useTrans(() => props.t);
const { locale, money, number } = useLocaleFormat();
const page = usePage();

const isBlank = (value) => value === '' || value === null || value === undefined;

/** Busca sem acento e sem caixa ("Mapeamento" acha "mapeamento"). */
function normalize(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

/* ───────────────────────── Convênio da grade ───────────────────────── */
const covenantId = computed(() => props.selectedCovenantId);
const selectedCovenant = computed(() => props.covenants.find((c) => String(c.id) === String(covenantId.value)) ?? null);
const selectedCovenantName = computed(() => selectedCovenant.value?.name ?? '');
const covenantTiss = computed(() => Boolean(selectedCovenant.value?.tiss));

/* ───────────────────────── Grade ───────────────────────── */
const saving = ref(false);
const loading = ref(false);
const saveError = ref(null);
const rowErrors = ref({}); // { index: { price, procedure_id, charging } }

// Ações em lote: aviso "alterado na grade, nada salvo" e preços já carregados
// dos convênios de origem (covenant_id → { procedure_id: preço }).
const bulkNotice = ref('');
const sourceCache = ref({});

function buildRows() {
    return props.procedures.map((p) => {
        const existing = props.prices?.[p.id] ?? {};
        const inherited = props.inheritedPrices?.[p.id];

        return {
            procedure_id: p.id,
            code: p.code,
            name: p.name,
            price: isBlank(existing.price) ? null : Number(existing.price),
            // Convênio sem operadora TISS nunca cobra por guia; linha nova de convênio TISS nasce marcada.
            charging: covenantTiss.value ? (existing.charging ?? true) : false,
            inherited: isBlank(inherited) ? null : Number(inherited),
            searchKey: normalize(`${p.code ?? ''} ${p.name ?? ''}`),
        };
    });
}

const snapshot = (list) => list.map((row) => ({ price: row.price, charging: row.charging }));

const rows = ref(buildRows());
const baseline = ref(snapshot(rows.value));

function resetGrid() {
    rows.value = buildRows();
    baseline.value = snapshot(rows.value);
    rowErrors.value = {};
    saveError.value = null;
    bulkNotice.value = '';
    // Salvou ou trocou de convênio: preços de origem em cache podem estar velhos.
    sourceCache.value = {};
}

// Troca de convênio ou volta do salvar: reconstrói a grade. O convênio também é
// observado porque, com preços iguais nos dois (ex.: ambos vazios), o Inertia
// mantém a MESMA referência de 'prices' e o watch de 'prices' sozinho não dispara.
watch(
    [() => props.prices, () => props.inheritedPrices, () => props.selectedCovenantId, () => props.covenants],
    resetGrid,
);

function sameRow(row, original) {
    const priceA = isBlank(row?.price) ? null : Number(row.price);
    const priceB = isBlank(original?.price) ? null : Number(original.price);

    return priceA === priceB && Boolean(row?.charging) === Boolean(original?.charging);
}

const dirtyFlags = computed(() => rows.value.map((row, i) => !sameRow(row, baseline.value[i])));
const dirtyCount = computed(() => dirtyFlags.value.filter(Boolean).length);

const hasPrice = (row) => !isBlank(row.price) || row.inherited !== null;
const pricedCount = computed(() => rows.value.filter(hasPrice).length);

// Linhas que o banco ainda marca para cobrança por guia num convênio sem operadora TISS.
const legacyChargingCount = computed(() =>
    covenantTiss.value ? 0 : props.procedures.filter((p) => props.prices?.[p.id]?.charging === true).length,
);

/** Linha com preço que o banco marca para cobrança por guia num convênio sem TISS (salvar corrige). */
function isLegacyCharging(row) {
    return !covenantTiss.value && !isBlank(row.price) && props.prices?.[row.procedure_id]?.charging === true;
}

// Índices da grade que o Salvar envia: as alteradas + as marcações antigas a corrigir.
const pendingIndexes = computed(() =>
    rows.value.reduce((list, row, index) => {
        if (dirtyFlags.value[index] || isLegacyCharging(row)) list.push(index);

        return list;
    }, []),
);

const maxItems = computed(() => Number(props.limits?.max_items) || 0);

/* ───────────────────────── Busca e filtros (locais) ───────────────────────── */
const search = ref('');
const filter = ref('all');

const counts = computed(() => ({
    all: rows.value.length,
    priced: pricedCount.value,
    unpriced: rows.value.length - pricedCount.value,
}));

function matchesFilter(row, index) {
    if (filter.value === 'all' || dirtyFlags.value[index]) return true; // linha editada não "pula" de filtro

    return filter.value === 'priced' ? hasPrice(row) : !hasPrice(row);
}

const visibleRows = computed(() => {
    const term = normalize(search.value.trim());

    return rows.value
        .map((row, index) => ({ row, index }))
        .filter(
            ({ row, index }) =>
                rowErrors.value[index] || ((!term || row.searchKey.includes(term)) && matchesFilter(row, index)),
        );
});

function clearFilters() {
    search.value = '';
    filter.value = 'all';
}

/* ───────────────────────── Textos por linha ───────────────────────── */
const procedureLabel = (row) => `${row.code} ${row.name}`;

/** Preço padrão do sistema no formato do campo ("150,00"); sem ele, o placeholder padrão do MoneyInput. */
function pricePlaceholder(row) {
    return row.inherited === null ? '' : formatMoneyInput(row.inherited, locale.value);
}

const showInherited = (row) => row.inherited !== null && isBlank(row.price);

function priceDescribedBy(index, row) {
    if (rowErrors.value[index]?.price) return `pp-price-error-${index}`;

    return showInherited(row) ? `pp-inherited-${index}` : undefined;
}

function chargingTitle(row) {
    if (!covenantTiss.value) return tx('charging_cash_hint');

    return isBlank(row.price) ? tx('charging_disabled_hint') : undefined;
}

/* ───────────────────────── Sair com alterações ───────────────────────── */
const { bypass } = useUnsavedChangesGuard({
    isDirty: () => dirtyCount.value > 0,
    message: () => tx('leave_confirm', { count: dirtyCount.value }),
});

/* ───────────────────────── Troca de convênio (sem perder edições) ───────────────────────── */
const pendingCovenantId = ref(null);
// Foco inicial na ação SEGURA (continuar editando): Enter reflexo não descarta.
const keepEditingButton = ref(null);

// O SearchSelect guarda o item escolhido internamente; quando a troca é cancelada
// (ou não se concretiza), remonta o seletor para voltar a mostrar o convênio da grade.
const covenantSelectKey = ref(0);
function resyncCovenantSelect() {
    covenantSelectKey.value += 1;
}

function requestCovenantChange(id) {
    if (!id || String(id) === String(covenantId.value)) return;

    if (dirtyCount.value > 0) {
        pendingCovenantId.value = id;
        nextTick(() => keepEditingButton.value?.focus?.());

        return;
    }

    loadCovenant(id);
}

let lastLoadToken = 0;

function loadCovenant(id) {
    const token = ++lastLoadToken;
    let switched = false;

    bypass(() =>
        router.get(
            route('panel.financial.procedure-prices.index'),
            { covenant_id: id },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['prices', 'inheritedPrices', 'selectedCovenantId'],
                onStart: () => {
                    loading.value = true;
                },
                onSuccess: (response) => {
                    switched = String(response?.props?.selectedCovenantId ?? '') === String(id);
                },
                onFinish: () => {
                    if (token !== lastLoadToken) return; // substituída por uma troca mais recente
                    loading.value = false;
                    if (!switched) resyncCovenantSelect();
                },
            },
        ),
    );
}

function confirmDiscard() {
    const id = pendingCovenantId.value;
    pendingCovenantId.value = null;
    if (id) loadCovenant(id);
}

function cancelDiscard() {
    pendingCovenantId.value = null;
    resyncCovenantSelect();
}

function onKeydown(event) {
    if (event.key === 'Escape' && pendingCovenantId.value) cancelDiscard();
}

watch(pendingCovenantId, (pending) => {
    if (typeof document === 'undefined') return;
    if (pending) document.addEventListener('keydown', onKeydown);
    else document.removeEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') document.removeEventListener('keydown', onKeydown);
});

/* ───────────────────────── Salvar só as linhas alteradas (erros ligados à linha) ───────────────────────── */
// Índice da grade de cada item enviado: o servidor responde items.N.* pela posição no lote.
let sentIndexes = [];

function mapRowErrors(errors) {
    const mapped = {};
    for (const [key, message] of Object.entries(errors ?? {})) {
        const match = /^items\.(\d+)\.(\w+)$/.exec(key);
        if (!match) continue;

        const index = sentIndexes[Number(match[1])];
        if (index === undefined) continue;

        mapped[index] = { ...(mapped[index] ?? {}), [match[2]]: String(message) };
    }

    return mapped;
}

function save() {
    if (!covenantId.value || pendingIndexes.value.length === 0) return;

    if (maxItems.value > 0 && pendingIndexes.value.length > maxItems.value) {
        saveError.value = tx('too_many_changes', {
            count: number(pendingIndexes.value.length),
            max: number(maxItems.value),
        });

        return;
    }

    saving.value = true;
    saveError.value = null;
    rowErrors.value = {};
    sentIndexes = [...pendingIndexes.value];

    // Semântica explícita por linha: preço informado = grava; null = remove aquele preço.
    const items = sentIndexes.map((index) => {
        const r = rows.value[index];

        return {
            procedure_id: r.procedure_id,
            price: isBlank(r.price) ? null : r.price,
            charging: covenantTiss.value ? Boolean(r.charging) : false,
        };
    });

    bypass(() =>
        router.post(
            route('panel.financial.procedure-prices.store'),
            { covenant_id: covenantId.value, items },
            {
                preserveScroll: true,
                // Sucesso: o flash 'success' do servidor aparece no layout.
                onError: (errors) => {
                    rowErrors.value = mapRowErrors(errors);
                    const invalidRows = Object.keys(rowErrors.value).length;
                    const general = Object.entries(errors ?? {}).find(([key]) => !key.startsWith('items.'))?.[1];

                    saveError.value = [
                        tx('save_error'),
                        invalidRows ? tx('rows_with_errors', { count: invalidRows }) : null,
                        general,
                    ]
                        .filter(Boolean)
                        .join(' ');

                    if (window.showErrorToast) window.showErrorToast(saveError.value);
                    nextTick(() => document.querySelector('.pp-row-invalid [aria-invalid="true"]')?.focus?.());
                },
                onFinish: () => {
                    saving.value = false;
                },
            },
        ),
    );
}

/* ───────────────────────── Ajustar preços em lote (só na grade; nada é salvo) ───────────────────────── */
const bulkModal = ref(null); // 'adjust' | 'copy' | null
const allEntries = computed(() => rows.value.map((row, index) => ({ row, index })));

// Convênios de origem da cópia: os da tela (da clínica ou globais), menos o da grade.
const copySources = computed(() => props.covenants.filter((c) => String(c.id) !== String(covenantId.value)));

function openBulk(kind) {
    bulkNotice.value = '';
    bulkModal.value = kind;
}

function closeBulk() {
    bulkModal.value = null;
}

/** Aplica [{ index, to }] na grade: as linhas ficam "alteradas" até o Salvar. */
function applyBulk(changes) {
    const errors = { ...rowErrors.value };

    for (const { index, to } of changes) {
        if (!rows.value[index]) continue;
        rows.value[index].price = to;
        delete errors[index];
    }

    rowErrors.value = errors;
    bulkModal.value = null;
    bulkNotice.value = tx('bulk_applied', { count: number(changes.length) });
}

/* Preços do convênio de origem: recarga parcial (only: ['sourcePrices']), sem rota nova. */
const sourceRequestedId = ref('');
const sourceLoading = ref(false);
const sourceFailed = ref(false);
let sourceToken = 0;
let cancelSourceLoad = null;

function rememberSource(value) {
    if (!value?.covenant_id) return;

    sourceCache.value = { ...sourceCache.value, [String(value.covenant_id)]: value.prices ?? {} };
}

watch(() => props.sourcePrices, rememberSource, { immediate: true });

const sourcePriceMap = computed(() =>
    sourceRequestedId.value ? (sourceCache.value[sourceRequestedId.value] ?? null) : null,
);

function loadSourcePrices(id) {
    cancelSourceLoad?.();
    cancelSourceLoad = null;
    sourceRequestedId.value = id ? String(id) : '';
    sourceFailed.value = false;
    sourceLoading.value = false;

    if (!id || sourcePriceMap.value) return;

    const token = ++sourceToken;
    let loaded = false;
    sourceLoading.value = true;

    bypass(() =>
        router.reload({
            only: ['sourcePrices'],
            data: { source_covenant_id: id },
            preserveUrl: true,
            onCancelToken: (cancelToken) => {
                cancelSourceLoad = () => cancelToken?.cancel?.();
            },
            onSuccess: (response) => {
                rememberSource(response?.props?.sourcePrices);
                loaded = String(response?.props?.sourcePrices?.covenant_id ?? '') === String(id);
            },
            onFinish: () => {
                if (token !== sourceToken) return; // substituída por outra escolha
                cancelSourceLoad = null;
                sourceLoading.value = false;
                sourceFailed.value = !loaded && !sourcePriceMap.value;
            },
        }),
    );
}

watch(bulkModal, (kind) => {
    if (kind === 'copy') return;

    // Fechou a cópia: nada fica carregando nem marcado como falha.
    cancelSourceLoad?.();
    cancelSourceLoad = null;
    sourceToken += 1;
    sourceRequestedId.value = '';
    sourceLoading.value = false;
    sourceFailed.value = false;
});

// Botão flutuante do Assistente de IA ocupa o canto inferior direito.
const avoidFab = computed(() => Boolean(page?.props?.aiAssistant?.enabled));
</script>

<template>
    <AppLayout :title="tx('title')" :breadcrumbs="breadcrumbs">
        <div class="page-financial-procedure-prices">
            <PageHeader :title="tx('title')" :subtitle="tx('subtitle')">
                <template #actions>
                    <!-- Sem aria-live: quem anuncia as mudanças é a barra de salvar. -->
                    <span
                        v-if="covenants.length && rows.length"
                        class="badge rounded-pill pp-counter"
                        data-test="priced-counter"
                    >
                        {{ tx('priced_counter', { priced: number(pricedCount), total: number(rows.length) }) }}
                    </span>
                </template>
            </PageHeader>

            <!-- Sem convênio: não há o que precificar -->
            <div v-if="!covenants.length" class="card border-0 shadow-sm" data-test="empty-covenants">
                <div class="card-body text-center py-5">
                    <i class="ti ti-building-hospital fs-1 text-body-secondary" aria-hidden="true"></i>
                    <p class="fw-semibold mt-2 mb-2">{{ tx('no_covenants') }}</p>
                    <Link
                        v-if="links.covenants"
                        :href="links.covenants"
                        class="btn btn-primary btn-sm"
                        data-test="link-covenants"
                    >
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ tx('no_covenants_action') }}
                    </Link>
                    <p v-else class="small text-body-secondary mb-0">{{ tx('no_covenants_ask') }}</p>
                </div>
            </div>

            <template v-else>
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div
                            v-if="saveError"
                            class="alert alert-danger d-flex align-items-start gap-2"
                            role="alert"
                            data-test="save-error"
                        >
                            <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i><span>{{ saveError }}</span>
                        </div>

                        <div class="row g-3 align-items-end mb-3">
                            <div class="col-md-5 col-lg-4">
                                <label id="pp-covenant-label" class="form-label">{{ tx('covenant') }}</label>
                                <div role="group" aria-labelledby="pp-covenant-label">
                                    <SearchSelect
                                        :key="covenantSelectKey"
                                        :model-value="covenantId"
                                        :options="covenants"
                                        :clearable="false"
                                        :placeholder="tx('covenant_placeholder')"
                                        @update:model-value="requestCovenantChange"
                                    />
                                </div>
                            </div>
                            <div class="col-md-7 col-lg-8 d-flex flex-column gap-1">
                                <span
                                    v-if="selectedCovenant"
                                    class="badge rounded-pill align-self-start border"
                                    :class="
                                        covenantTiss
                                            ? 'bg-primary-subtle text-primary-emphasis border-primary-subtle'
                                            : 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle'
                                    "
                                    data-test="covenant-kind"
                                >
                                    <i
                                        class="ti me-1"
                                        :class="covenantTiss ? 'ti-file-invoice' : 'ti-cash'"
                                        aria-hidden="true"
                                    ></i
                                    >{{ covenantTiss ? tx('covenant_tiss') : tx('covenant_cash') }}
                                </span>
                                <small class="text-body-secondary">{{ tx('empty_hint') }}</small>
                            </div>
                        </div>

                        <div
                            id="pp-charging-help"
                            class="alert alert-info small d-flex align-items-start gap-2"
                            data-test="charging-help"
                        >
                            <i class="ti ti-info-circle mt-1" aria-hidden="true"></i>
                            <span
                                ><strong>{{ tx('charging') }}:</strong>
                                {{ covenantTiss ? tx('charging_help') : tx('charging_help_cash') }}</span
                            >
                        </div>

                        <div
                            v-if="legacyChargingCount > 0"
                            class="alert alert-warning small d-flex align-items-start gap-2"
                            role="status"
                            data-test="charging-legacy"
                        >
                            <i class="ti ti-alert-triangle mt-1" aria-hidden="true"></i>
                            <span>{{ tx('charging_legacy', { count: legacyChargingCount }) }}</span>
                        </div>

                        <PricesToolbar
                            v-if="rows.length"
                            v-model:search="search"
                            v-model:filter="filter"
                            :counts="counts"
                            :t="t"
                        >
                            <template #actions>
                                <span class="d-inline-flex" data-test="bulk-menu">
                                    <ActionDropdown
                                        :title="tx('bulk_menu_label')"
                                        btn-class="btn btn-sm btn-outline-primary"
                                        :min-width="240"
                                    >
                                        <template #trigger>
                                            <i class="ti ti-adjustments-horizontal me-1" aria-hidden="true"></i
                                            >{{ tx('bulk_menu')
                                            }}<i class="ti ti-chevron-down ms-1" aria-hidden="true"></i>
                                        </template>
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item rounded-1"
                                                data-test="bulk-adjust"
                                                :disabled="loading || saving"
                                                @click="openBulk('adjust')"
                                            >
                                                <i class="ti ti-percentage me-1" aria-hidden="true"></i
                                                >{{ tx('bulk_adjust') }}
                                            </button>
                                        </li>
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item rounded-1"
                                                data-test="bulk-copy"
                                                :disabled="loading || saving || copySources.length === 0"
                                                @click="openBulk('copy')"
                                            >
                                                <i class="ti ti-copy me-1" aria-hidden="true"></i>{{ tx('bulk_copy') }}
                                            </button>
                                        </li>
                                    </ActionDropdown>
                                </span>
                            </template>
                        </PricesToolbar>

                        <!-- Região viva sempre presente: o aviso é lido quando aparece. -->
                        <div role="status" aria-live="polite" data-test="bulk-notice-region">
                            <div
                                v-if="bulkNotice"
                                class="alert alert-info small d-flex align-items-start gap-2 py-2"
                                data-test="bulk-notice"
                            >
                                <i class="ti ti-info-circle mt-1" aria-hidden="true"></i><span>{{ bulkNotice }}</span>
                            </div>
                        </div>

                        <div
                            class="table-responsive"
                            :class="{ 'pp-loading': loading }"
                            :aria-busy="loading ? 'true' : 'false'"
                        >
                            <table class="table table-sm align-middle mb-0">
                                <caption class="visually-hidden">
                                    {{
                                        tx('title')
                                    }}
                                    —
                                    {{
                                        selectedCovenantName
                                    }}
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col" class="pp-col-code">{{ tx('code') }}</th>
                                        <th scope="col">{{ tx('procedure') }}</th>
                                        <th scope="col" class="pp-col-price text-end">{{ tx('price') }}</th>
                                        <th scope="col" class="pp-col-charging text-center">{{ tx('charging') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="rows.length === 0" data-test="empty-procedures">
                                        <td colspan="4" class="text-center text-body-secondary py-4">
                                            <p class="fw-semibold mb-1">{{ tx('no_procedures') }}</p>
                                            <p class="small mb-0">{{ tx('no_procedures_hint') }}</p>
                                        </td>
                                    </tr>
                                    <tr v-else-if="visibleRows.length === 0" data-test="no-results">
                                        <td colspan="4" class="text-center text-body-secondary py-4">
                                            <p class="mb-2">{{ tx('no_results') }}</p>
                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary btn-sm"
                                                data-test="clear-filters"
                                                @click="clearFilters"
                                            >
                                                <i class="ti ti-filter-off me-1" aria-hidden="true"></i
                                                >{{ tx('clear_filters') }}
                                            </button>
                                        </td>
                                    </tr>
                                    <tr
                                        v-for="entry in visibleRows"
                                        :key="entry.row.procedure_id"
                                        :class="{
                                            'pp-row-invalid': rowErrors[entry.index],
                                            'pp-row-dirty': dirtyFlags[entry.index],
                                        }"
                                        :data-procedure="entry.row.procedure_id"
                                        data-test="price-row"
                                    >
                                        <td>
                                            <code>{{ entry.row.code }}</code>
                                            <span v-if="dirtyFlags[entry.index]" class="visually-hidden">
                                                ({{ tx('row_changed') }})</span
                                            >
                                        </td>
                                        <td>{{ entry.row.name }}</td>
                                        <td>
                                            <MoneyInput
                                                v-model="entry.row.price"
                                                :placeholder="pricePlaceholder(entry.row)"
                                                :invalid="Boolean(rowErrors[entry.index]?.price)"
                                                :disabled="loading"
                                                :aria-label="tx('price_aria', { procedure: procedureLabel(entry.row) })"
                                                :aria-describedby="priceDescribedBy(entry.index, entry.row)"
                                                data-test="price-input"
                                            />
                                            <div
                                                v-if="rowErrors[entry.index]?.price"
                                                :id="`pp-price-error-${entry.index}`"
                                                class="invalid-feedback d-block text-end"
                                                data-test="row-error"
                                            >
                                                {{ rowErrors[entry.index].price }}
                                            </div>
                                            <div
                                                v-else-if="showInherited(entry.row)"
                                                :id="`pp-inherited-${entry.index}`"
                                                class="form-text text-end mt-1"
                                                data-test="inherited-price"
                                            >
                                                {{ tx('inherited_price', { price: money(entry.row.inherited) }) }}
                                            </div>
                                            <div
                                                v-if="rowErrors[entry.index]?.procedure_id"
                                                class="invalid-feedback d-block text-end"
                                            >
                                                {{ rowErrors[entry.index].procedure_id }}
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <input
                                                v-model="entry.row.charging"
                                                type="checkbox"
                                                class="form-check-input"
                                                :class="{ 'is-invalid': rowErrors[entry.index]?.charging }"
                                                :aria-label="
                                                    tx('charging_aria', { procedure: procedureLabel(entry.row) })
                                                "
                                                aria-describedby="pp-charging-help"
                                                :aria-invalid="rowErrors[entry.index]?.charging ? 'true' : 'false'"
                                                :title="chargingTitle(entry.row)"
                                                :disabled="loading || !covenantTiss || isBlank(entry.row.price)"
                                            />
                                            <div
                                                v-if="rowErrors[entry.index]?.charging"
                                                class="invalid-feedback d-block"
                                            >
                                                {{ rowErrors[entry.index].charging }}
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <PricesSaveBar
                    v-if="rows.length"
                    :dirty-count="dirtyCount"
                    :saving="saving"
                    :disabled="loading || !covenantId || pendingIndexes.length === 0"
                    :avoid-fab="avoidFab"
                    :t="t"
                    @save="save"
                />

                <PriceAdjustModal
                    :open="bulkModal === 'adjust'"
                    :visible-entries="visibleRows"
                    :all-entries="allEntries"
                    :t="t"
                    @close="closeBulk"
                    @apply="applyBulk"
                />

                <PriceCopyModal
                    :open="bulkModal === 'copy'"
                    :sources="copySources"
                    :entries="allEntries"
                    :prices="sourcePriceMap"
                    :loading="sourceLoading"
                    :failed="sourceFailed"
                    :t="t"
                    @close="closeBulk"
                    @load="loadSourcePrices"
                    @apply="applyBulk"
                />
            </template>

            <!-- Troca de convênio com alterações não salvas -->
            <CenteredModal :open="pendingCovenantId !== null" size="sm" @close="cancelDiscard">
                <template #header>
                    <h2 class="h5 mb-0">
                        <i class="ti ti-alert-triangle me-1 text-warning" aria-hidden="true"></i
                        >{{ tx('discard_title') }}
                    </h2>
                </template>
                <p class="mb-0" data-test="discard-body">
                    {{ tx('discard_body', { count: dirtyCount, covenant: selectedCovenantName }) }}
                </p>
                <template #footer>
                    <button
                        ref="keepEditingButton"
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        data-test="discard-cancel"
                        @click="cancelDiscard"
                    >
                        {{ tx('discard_cancel') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-warning btn-sm"
                        data-test="discard-confirm"
                        @click="confirmDiscard"
                    >
                        {{ tx('discard_confirm') }}
                    </button>
                </template>
            </CenteredModal>
        </div>
    </AppLayout>
</template>

<style scoped>
.pp-col-code {
    width: 120px;
}

.pp-col-price {
    width: 220px;
}

.pp-col-charging {
    width: 180px;
}

.pp-counter {
    color: var(--bs-primary-text-emphasis);
    background-color: var(--bs-primary-bg-subtle);
    border: 1px solid var(--bs-primary-border-subtle);
    font-variant-numeric: tabular-nums;
}

.pp-loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Linha alterada e ainda não salva: faixa à esquerda + fundo suave (os dois temas). */
.pp-row-dirty > td {
    background-color: var(--bs-warning-bg-subtle);
}

.pp-row-dirty > td:first-child {
    box-shadow: inset 3px 0 0 var(--bs-warning);
}

.pp-row-invalid > td:first-child {
    box-shadow: inset 3px 0 0 var(--bs-danger);
}
</style>
