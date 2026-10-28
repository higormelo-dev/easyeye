<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import ImportReturnModal from '../Billing/ImportReturnModal.vue';
import { cleanParams } from '../Billing/billingHelpers.js';
import GlosaKpis from './GlosaKpis.vue';
import GlosaFilterBar from './GlosaFilterBar.vue';
import GlosaTable from './GlosaTable.vue';
import GlosaDetailPanel from './GlosaDetailPanel.vue';
import GlosaActionModal from './GlosaActionModal.vue';

/**
 * Conciliação de glosas como fila de trabalho: aba "Pendentes" (abertas e
 * recorridas de qualquer data, pelo prazo — vencidas primeiro) e o histórico
 * em "Resolvidas"/"Todas" (período). KPIs são filtros (aria-pressed); busca,
 * status, convênio e período ficam na URL e são aplicados na hora.
 *
 * Lista paginada no servidor (paginator do Laravel + TablePagination; os
 * links levam os filtros da URL). KPIs e contagens das abas são agregados do
 * servidor — valem para o filtro inteiro, não só para a página.
 *
 * Detalhes num painel lateral carregado por recarga parcial
 * (only: ['glosaDetail'], data: { detail }) — sem rota nova. As transições
 * (recorrer, marcar como enviado, decisão) e os 409 são do servidor.
 * Textos: lang/{locale}/financial_glosas.php (+ t.shared, t.import).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    today: { type: String, default: '' },
    summary: { type: Object, required: true },
    tabCounts: { type: Object, default: () => ({}) },
    /** Paginator do Laravel: { data, current_page, last_page, from, to, total, links, ... }. */
    glosas: { type: Object, default: () => ({ data: [] }) },
    byOperator: { type: Array, default: () => [] },
    operators: { type: Array, default: () => [] },
    statusOptions: { type: Object, default: () => ({}) },
    glosaDetail: { type: Object, default: null },
    covenants: { type: Array, default: () => [] },
    importReturnUrl: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const { money, number } = useLocaleFormat();
const { tx } = useTrans(() => props.t);

const TAB_KEYS = ['pending', 'resolved', 'all'];

const activeTab = ref(TAB_KEYS.includes(props.filters.tab) ? props.filters.tab : 'pending');

watch(
    () => props.filters?.tab,
    (tab) => {
        if (TAB_KEYS.includes(tab)) activeTab.value = tab;
    },
);

const tabs = computed(() =>
    [
        { key: 'pending', icon: 'ti ti-hourglass-high', label: props.t.tab_pending },
        { key: 'resolved', icon: 'ti ti-circle-check', label: props.t.tab_resolved },
        { key: 'all', icon: 'ti ti-list', label: props.t.tab_all },
    ].map((tab) => ({ ...tab, count: props.tabCounts?.[tab.key] ?? null })),
);

const dueSoonDays = computed(() => Number(props.summary?.due_soon_days ?? 5));
const tabStatusOptions = computed(() => props.statusOptions?.[activeTab.value] ?? []);

/* ───────────────────────── Filtros (URL, aplicação automática) ───────────────────────── */
const filtering = ref(false);

const currentParams = computed(() => ({
    tab: activeTab.value,
    from: props.filters.from,
    to: props.filters.to,
    status: props.filters.status,
    operator_id: props.filters.operator_id,
    due: props.filters.due,
    search: props.filters.search,
}));

function visit(params) {
    router.get(route('panel.financial.tiss.glosas.index'), cleanParams(params), {
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

function applyFilters(patch) {
    if (TAB_KEYS.includes(patch.tab)) activeTab.value = patch.tab;

    const next = { ...currentParams.value, ...patch, tab: activeTab.value };
    const allowed = props.statusOptions?.[next.tab];

    // Filtros que não existem na aba saem da URL (o servidor também os ignora).
    if (next.tab !== 'pending') next.due = '';
    if (next.status && Array.isArray(allowed) && !allowed.some((option) => option.value === next.status))
        next.status = '';

    visit(next);
}

function clearFilters() {
    visit({ tab: activeTab.value });
}

function setTab(key) {
    if (!TAB_KEYS.includes(key) || key === activeTab.value) return;

    applyFilters({ tab: key });
}

function onTabKeydown(event, key) {
    const index = TAB_KEYS.indexOf(key);
    const moves = { ArrowRight: 1, ArrowLeft: -1, Home: -index, End: TAB_KEYS.length - 1 - index };
    if (!(event.key in moves)) return;

    event.preventDefault();
    const next = TAB_KEYS[(index + moves[event.key] + TAB_KEYS.length) % TAB_KEYS.length];
    setTab(next);
    nextTick(() => document.getElementById(`glosas-tab-${next}`)?.focus());
}

/* ───────────────────────── KPIs como filtros ───────────────────────── */
const activeKpi = computed(() => {
    const { due, status } = props.filters;

    if (due === 'overdue') return 'overdue';
    if (due === 'soon') return 'soon';
    if (status === 'recovered') return 'recovered';
    if (activeTab.value === 'pending' && (status === 'open' || status === 'appealed')) return status;

    return null;
});

const KPI_FILTERS = {
    open: { tab: 'pending', status: 'open', due: '' },
    appealed: { tab: 'pending', status: 'appealed', due: '' },
    overdue: { tab: 'pending', status: '', due: 'overdue' },
    soon: { tab: 'pending', status: '', due: 'soon' },
    recovered: { tab: 'resolved', status: 'recovered', due: '' },
};

function toggleKpi(kind) {
    if (!KPI_FILTERS[kind]) return;

    applyFilters(activeKpi.value === kind ? { status: '', due: '' } : KPI_FILTERS[kind]);
}

/* ───────────────────────── Lista (página atual do paginator) ───────────────────────── */
const listTitle = computed(() => props.t[`list_title_${activeTab.value}`] ?? props.t.title);

const glosaRows = computed(() => (Array.isArray(props.glosas?.data) ? props.glosas.data : []));

// Anunciado a leitores de tela ao trocar de página (o TablePagination só mostra o intervalo).
const pageStatus = computed(() => {
    const pages = Number(props.glosas?.last_page ?? 1);
    if (pages <= 1) return '';

    return tx('pagination_status', { page: number(props.glosas?.current_page ?? 1), pages: number(pages) });
});

const hasContextFilter = computed(() =>
    Boolean(props.filters.search || props.filters.status || props.filters.operator_id || props.filters.due),
);

const emptyText = computed(() => {
    if (hasContextFilter.value) return props.t.empty_filtered;

    return activeTab.value === 'pending' ? props.t.empty_pending : props.t.empty;
});

/* ───────────────────────── Ações (modal) ───────────────────────── */
const actionModalRef = ref(null);
const action = ref({ kind: null, glosa: null, appeal: null });

function openAction(kind, glosa, appeal = null) {
    action.value = { kind, glosa, appeal };
}

function closeAction() {
    action.value = { kind: null, glosa: null, appeal: null };
}

/* ───────────────────────── Detalhes (painel lateral, recarga parcial) ───────────────────────── */
// Link direto (?detail=<id>): o servidor já manda o detalhe na carga da página
// (ou `missing`, e o painel avisa que a glosa não foi encontrada).
const initialDetail = props.glosaDetail ?? null;

const detailOpen = ref(Boolean(initialDetail));
const detailId = ref(initialDetail?.id ?? null);
const detailLoading = ref(false);
const detail = ref(initialDetail);
let detailReturnFocus = null;

function loadDetail(id) {
    detailLoading.value = true;

    router.reload({
        only: ['glosaDetail'],
        data: { detail: id },
        preserveUrl: true,
        onFinish: () => {
            detailLoading.value = false;
        },
    });
}

function openDetail(glosa) {
    detailReturnFocus = typeof document !== 'undefined' ? document.activeElement : null;
    detailId.value = glosa.id;
    detail.value = null;
    detailOpen.value = true;

    loadDetail(glosa.id);
}

function closeDetail() {
    detailOpen.value = false;
    detailId.value = null;
    nextTick(() => detailReturnFocus?.focus?.());
}

// Resposta da recarga parcial (ou de uma ação feita com o painel aberto).
// null depois de outras visitas não apaga o que o painel está mostrando.
watch(
    () => props.glosaDetail,
    (value) => {
        if (!value || !detailOpen.value) return;
        if (value.missing || value.id === detailId.value) detail.value = value;
    },
);

function onActionDone() {
    // A ação recarrega a página (back()); com o painel aberto, busca o detalhe atualizado.
    if (detailOpen.value && detailId.value) loadDetail(detailId.value);
}

/* ───────────────────────── Importar retorno TISS ───────────────────────── */
const importOpen = ref(false);

/* ───────────────────────── Teclado: Esc fecha o que estiver por cima ───────────────────────── */
function onKeydown(event) {
    if (event.key !== 'Escape') return;

    if (action.value.kind) {
        actionModalRef.value?.requestClose();

        return;
    }

    if (detailOpen.value) closeDetail();
}

watch(
    () => Boolean(action.value.kind) || detailOpen.value,
    (anyOpen) => {
        if (typeof document === 'undefined') return;
        if (anyOpen) document.addEventListener('keydown', onKeydown);
        else document.removeEventListener('keydown', onKeydown);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="page-financial-glosas">
            <PageHeader
                :title="t.title"
                :subtitle="t.subtitle"
                :total="tabCounts.pending ?? null"
                :total-label="t.total_label"
            >
                <template #actions>
                    <button
                        v-if="importReturnUrl"
                        type="button"
                        class="btn btn-outline-primary btn-sm"
                        data-test="open-import"
                        @click="importOpen = true"
                    >
                        <i class="ti ti-file-upload me-1" aria-hidden="true"></i>{{ t.btn_import_return }}
                    </button>
                </template>
            </PageHeader>

            <GlosaKpis :summary="summary" :active="activeKpi" :loading="filtering" :t="t" @toggle="toggleKpi" />

            <!-- Abas (servidor: cada aba é uma consulta) -->
            <ul class="nav nav-tabs mb-3" role="tablist" :aria-label="t.tabs_label">
                <li v-for="tab in tabs" :key="tab.key" class="nav-item" role="presentation">
                    <button
                        :id="`glosas-tab-${tab.key}`"
                        type="button"
                        role="tab"
                        :class="['nav-link', { active: activeTab === tab.key }]"
                        :aria-selected="activeTab === tab.key ? 'true' : 'false'"
                        aria-controls="glosas-panel"
                        :tabindex="activeTab === tab.key ? 0 : -1"
                        :data-test="`glosas-tab-${tab.key}`"
                        @click="setTab(tab.key)"
                        @keydown="onTabKeydown($event, tab.key)"
                    >
                        <i :class="[tab.icon, 'me-1']" aria-hidden="true"></i>{{ tab.label }}
                        <span v-if="tab.count !== null" class="badge rounded-pill badge-soft-secondary ms-1">{{
                            tab.count
                        }}</span>
                    </button>
                </li>
            </ul>

            <section
                id="glosas-panel"
                role="tabpanel"
                :aria-labelledby="`glosas-tab-${activeTab}`"
                :aria-busy="filtering ? 'true' : 'false'"
            >
                <GlosaFilterBar
                    :filters="filters"
                    :operators="operators"
                    :status-options="tabStatusOptions"
                    :tab="activeTab"
                    :today="today"
                    :filtering="filtering"
                    :t="t"
                    @change="applyFilters"
                    @clear="clearFilters"
                />

                <div class="card mb-3">
                    <div class="card-header bg-transparent border-bottom">
                        <h2 class="h6 mb-0 fw-semibold">
                            <i class="ti ti-gavel me-1 text-primary" aria-hidden="true"></i>{{ listTitle }}
                        </h2>
                    </div>
                    <GlosaTable
                        :glosas="glosaRows"
                        :today="today"
                        :due-soon-days="dueSoonDays"
                        :busy="filtering"
                        :empty-text="emptyText"
                        :t="t"
                        @action="openAction"
                        @details="openDetail"
                    />
                    <TablePagination
                        :data="glosas"
                        class="px-3 pb-3"
                        :showing-from="t.pagination_showing"
                        :showing-of="t.pagination_of"
                        :showing-suffix="t.pagination_suffix"
                        :aria-label="t.pagination_label"
                        :previous-label="t.pagination_previous"
                        :next-label="t.pagination_next"
                        data-test="glosas-pagination"
                    />
                    <p class="visually-hidden" role="status" aria-live="polite" data-test="glosas-page-status">
                        {{ pageStatus }}
                    </p>
                </div>

                <!-- Resumo por convênio (histórico do período) -->
                <div v-if="activeTab !== 'pending' && byOperator.length > 0" class="card" data-test="by-operator">
                    <div class="card-header bg-transparent border-bottom">
                        <h2 class="h6 mb-0 fw-semibold">
                            <i class="ti ti-chart-pie me-1 text-primary" aria-hidden="true"></i>{{ t.by_covenant }}
                        </h2>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">{{ t.col_covenant }}</th>
                                    <th scope="col" class="text-center">{{ t.col_count }}</th>
                                    <th scope="col" class="text-end">{{ t.col_total }}</th>
                                    <th scope="col" class="text-end">{{ t.col_open }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="op in byOperator" :key="op.id || op.name">
                                    <td class="fw-medium">{{ op.name }}</td>
                                    <td class="text-center">{{ op.count }}</td>
                                    <td class="text-end">{{ money(op.total) }}</td>
                                    <td class="text-end fw-semibold text-body">{{ money(op.open) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <GlosaDetailPanel
                :open="detailOpen"
                :loading="detailLoading"
                :detail="detail"
                :today="today"
                :due-soon-days="dueSoonDays"
                :busy="filtering"
                :t="t"
                @close="closeDetail"
                @action="openAction"
            />

            <GlosaActionModal
                ref="actionModalRef"
                :kind="action.kind"
                :glosa="action.glosa"
                :appeal="action.appeal"
                :appeal-response-days="Number(summary.appeal_response_days ?? 60)"
                :t="t"
                @close="closeAction"
                @done="onActionDone"
            />

            <ImportReturnModal
                v-if="importReturnUrl"
                :open="importOpen"
                :covenants="covenants"
                :url="importReturnUrl"
                :t="t.import ?? {}"
                @close="importOpen = false"
                @saved="importOpen = false"
            />
        </div>
    </AppLayout>
</template>
