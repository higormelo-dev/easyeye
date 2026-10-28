<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import KpiCard from '@/Components/Panel/KpiCard.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useConfirmationWithReason } from '@/composables/useConfirmationWithReason.js';
import { useImportProgress } from '@/composables/useImportProgress';
import Cid10Table from './Cid10Table.vue';
import Cid10Cards from './Cid10Cards.vue';
import Cid10DetailDrawer from './Cid10DetailDrawer.vue';
import Cid10FormModal from './Cid10FormModal.vue';
import Cid10ReviewDrawer from './Cid10ReviewDrawer.vue';

/**
 * Manager → CID-10: catálogo GLOBAL usado na busca de diagnóstico de
 * prontuários, exames e guias de todas as clínicas. Lista oficial do
 * DATASUS + códigos personalizados, com descrição oficial guardada ao lado,
 * uso agregado pelas clínicas e importação do CID10CSV.zip (progresso em
 * tempo real via WebSocket — sem polling).
 */
const props = defineProps({
    codes: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    chapters: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    usageAt: { type: String, default: null },
    review: { type: Object, default: () => ({ total: 0, clinics: [] }) },
    // Lista detalhada (Inertia optional): só vem quando a gaveta abre.
    reviewRecords: { type: Array, default: null },
    imports: { type: Array, default: () => [] },
    // Importação na fila/processando (progressPayload do servidor) ou null.
    runningImport: { type: Object, default: null },
    t: { type: Object, default: () => ({}) },
});

const { number, dateTime } = useLocaleFormat();
const page = usePage();

const tab = ref(props.runningImport ? 'imports' : 'catalog');

// ── Tabela / cards (mesmo padrão de Medicamentos) ────────────────────────
const VIEW_KEY = 'mgr_cid10_view';
const view = ref(readView());

function readView() {
    try {
        return localStorage.getItem(VIEW_KEY) === 'cards' ? 'cards' : 'table';
    } catch {
        return 'table';
    }
}

function setView(value) {
    view.value = value;
    try {
        localStorage.setItem(VIEW_KEY, value);
    } catch {
        // armazenamento bloqueado: vale só nesta visita
    }
}

// ── Filtros e ordenação (server-side) ────────────────────────────────────
const search = ref(props.filters.search ?? '');
const source = ref(props.filters.source ?? '');
const chapter = ref(props.filters.chapter ?? '');
const category = ref(props.filters.category ?? '');
const usage = ref(props.filters.usage ?? '');
const edited = ref(!!props.filters.edited);
const sort = ref(props.filters.sort ?? 'code');
const direction = ref(props.filters.direction ?? 'asc');

function applyFilters() {
    const custom = sort.value !== 'code' || direction.value !== 'asc';

    router.get(
        route('manager.cid10.index'),
        {
            search: search.value || undefined,
            source: source.value || undefined,
            chapter: chapter.value || undefined,
            category: category.value || undefined,
            usage: usage.value || undefined,
            edited: edited.value ? 1 : undefined,
            // Ordem padrão (código A→Z) fica fora da URL.
            sort: custom ? sort.value : undefined,
            direction: custom ? direction.value : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['codes', 'filters', 'categories'] },
    );
}

function onSort(payload) {
    sort.value = payload.sort;
    direction.value = payload.direction;
    applyFilters();
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
});
// Trocar de capítulo: a categoria escolhida pode não existir nele.
watch(chapter, () => {
    category.value = '';
});
watch([source, chapter, category, usage, edited], applyFilters);

const hasFilters = computed(
    () => !!(search.value || source.value || chapter.value || category.value || usage.value || edited.value),
);

function clearFilters() {
    search.value = '';
    source.value = '';
    chapter.value = '';
    category.value = '';
    usage.value = '';
    edited.value = false;
}

// ── KPIs (KpiCard tinted, mesmo padrão de Assinaturas) ───────────────────
// Cards de filtro alternam o filtro correspondente; "Registros a revisar"
// abre a gaveta com a lista.
const kpis = computed(() => [
    {
        key: 'total',
        icon: 'ti-list-numbers',
        tone: 'primary',
        active: !source.value && !edited.value && !usage.value,
    },
    { key: 'official', icon: 'ti-certificate', tone: 'success', active: source.value === 'datasus' },
    { key: 'custom', icon: 'ti-pencil-plus', tone: 'purple', active: source.value === 'custom' },
    { key: 'edited', icon: 'ti-edit', tone: 'warning', active: edited.value },
    { key: 'used', icon: 'ti-building-hospital', tone: 'teal', active: usage.value === 'used' },
]);

function applyKpi(key) {
    tab.value = 'catalog';
    if (key === 'total') {
        source.value = '';
        edited.value = false;
        usage.value = '';
    } else if (key === 'official' || key === 'custom') {
        const value = key === 'official' ? 'datasus' : 'custom';
        source.value = source.value === value ? '' : value;
    } else if (key === 'edited') {
        edited.value = !edited.value;
    } else if (key === 'used') {
        usage.value = usage.value === 'used' ? '' : 'used';
    }
}

const usageUpdatedText = computed(() =>
    props.usageAt ? (props.t.usage_updated ?? '').replace(':date', dateTime(props.usageAt)) : '',
);

// ── Registros a revisar ──────────────────────────────────────────────────
const reviewOpen = ref(false);
const reviewLoading = ref(false);

function openReview() {
    reviewOpen.value = true;
    if (props.reviewRecords !== null) return;

    reviewLoading.value = true;
    router.reload({
        only: ['reviewRecords'],
        onFinish: () => {
            reviewLoading.value = false;
        },
    });
}

// ── Cadastro / edição ────────────────────────────────────────────────────
const formOpen = ref(false);
const editing = ref(null);

function openCreate() {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(code) {
    detailOpen.value = false;
    editing.value = code;
    formOpen.value = true;
}

// ── Detalhes ─────────────────────────────────────────────────────────────
const detail = ref(null);
const detailOpen = ref(false);

function openDetail(code) {
    detail.value = code;
    detailOpen.value = true;
}

// A linha mudou (editar): o drawer aberto acompanha.
watch(
    () => props.codes.data,
    (rows) => {
        if (detail.value) detail.value = rows.find((r) => r.id === detail.value.id) ?? detail.value;
    },
);

// ── Exclusão: justificativa obrigatória + auditoria (padrão do manager) ──
const {
    state: reasonModal,
    open: openReasonModal,
    close: closeReasonModal,
    handle: handleReasonConfirm,
} = useConfirmationWithReason();
const deleteError = ref('');

function remove(code) {
    deleteError.value = '';
    const message = (props.t.confirm_delete_text ?? '')
        .replace(':code', code.code)
        .replace(':description', code.description);

    openReasonModal({
        title: (props.t.confirm_delete_title ?? '').replace(':code', code.code),
        message: code.is_custom ? message : `${message} ${props.t.confirm_delete_official ?? ''}`,
        confirmLabel: props.t.delete,
        confirmVariant: 'danger',
        onConfirm: (reason) =>
            new Promise((resolve, reject) => {
                router.delete(route('manager.cid10.destroy', code.id), {
                    data: { reason },
                    preserveScroll: true,
                    onSuccess: () => {
                        if (detail.value?.id === code.id) detailOpen.value = false;
                        resolve();
                    },
                    onError: (errors) => {
                        deleteError.value = errors.code ?? errors.reason ?? Object.values(errors)[0] ?? '';
                        reject(errors);
                    },
                });
            }),
    });
}

function confirmDelete(reason) {
    // Erro do servidor fica no modal (prop error); não é exceção da página.
    return handleReasonConfirm(reason).catch(() => {});
}

// ── Importação (CID10CSV.zip ou CSVs) ────────────────────────────────────
// Barra de progresso em tempo real (WebSocket/Reverb — sem polling HTTP),
// mesmo padrão de Medicamentos. Ao terminar, mantém o cartão com o
// resultado e recarrega histórico, números e catálogo.
const progressImport = ref(props.runningImport);
const importRunning = computed(() => !!progressImport.value && !progressImport.value.is_done);

const { realtimeConnected, resync } = useImportProgress(progressImport, {
    onDone: () => router.reload({ only: ['imports', 'stats', 'codes', 'categories'] }),
    // Assinou/reconectou (ou "Atualizar status"): relê o estado uma vez.
    onResync: () => router.reload({ only: ['runningImport', 'imports', 'stats', 'codes'] }),
});

watch(
    () => props.runningImport,
    (value) => {
        if (value) {
            progressImport.value = value;
            return;
        }
        // Terminou antes de o WebSocket conectar: resultado final vem do histórico.
        if (importRunning.value) {
            const finished = props.imports.find((i) => i.id === progressImport.value.id);
            if (finished) progressImport.value = finished;
        }
    },
);

const importForm = useForm({ files: [] });
const fileInputKey = ref(0); // troca a key do input pra limpá-lo

const importError = computed(
    () =>
        importForm.errors.files ??
        Object.entries(importForm.errors).find(([key]) => key.startsWith('files.'))?.[1] ??
        '',
);

const canSubmitImport = computed(() => importForm.files.length > 0 && !importForm.processing && !importRunning.value);

function pickFiles(event) {
    importForm.files = Array.from(event.target.files ?? []);
}

function submitImport() {
    if (!canSubmitImport.value) return;

    importForm.post(route('manager.cid10.imports.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset('files');
            fileInputKey.value++;
        },
    });
}

// Parada há quanto tempo (sem polling): o servidor manda idle_seconds; aqui
// só soma o tempo desde que o estado chegou (relógio local, sem requisição).
const idleSeconds = ref(0);
let idleBase = { at: Date.now(), seconds: 0 };

watch(
    progressImport,
    (imp) => {
        idleBase = { at: Date.now(), seconds: imp?.idle_seconds ?? 0 };
        idleSeconds.value = idleBase.seconds;
    },
    { immediate: true },
);

const idleTimer = setInterval(() => {
    if (importRunning.value) idleSeconds.value = idleBase.seconds + Math.floor((Date.now() - idleBase.at) / 1000);
}, 5000);

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    clearInterval(idleTimer);
});

// Na fila (worker ainda não pegou) e parada além do esperado (worker fora do ar).
const importQueued = computed(() => importRunning.value && progressImport.value?.status === 'pending');
const importStalled = computed(
    () =>
        importRunning.value &&
        progressImport.value?.stall_after_seconds != null &&
        idleSeconds.value >= progressImport.value.stall_after_seconds,
);

const cancelling = ref(false);
const cancelError = ref('');

function cancelImport() {
    if (!progressImport.value || cancelling.value) return;

    cancelling.value = true;
    cancelError.value = '';
    router.post(
        route('manager.cid10.imports.cancel', progressImport.value.id),
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                cancelError.value = errors.import ?? Object.values(errors)[0] ?? '';
            },
            onFinish: () => {
                cancelling.value = false;
            },
        },
    );
}

function alertClass(imp) {
    if (importRunning.value) return importStalled.value ? 'alert-warning' : 'alert-info';
    if (imp.status === 'done') return 'alert-success';

    return imp.status === 'cancelled' ? 'alert-secondary' : 'alert-danger';
}

function fmtProgress(imp) {
    return (props.t.import_progress_rows ?? '')
        .replace(':processed', number(imp.processed_rows ?? 0))
        .replace(':total', number(imp.total_rows ?? 0));
}

function fileLabel(file) {
    return `${file.name} (${props.t[`kind_${file.kind}`] ?? file.kind}${file.converted ? ` · ${props.t.converted_hint}` : ''})`;
}

const breadcrumbs = [
    { label: props.t.breadcrumb_home ?? 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: props.t.page_title, url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <PageHeader
            :title="t.page_title"
            :total="codes.total ?? null"
            :total-label="t.total_label"
            :view="view"
            :view-table-title="t.view_table"
            :view-cards-title="t.view_cards"
            :show-view-toggle="tab === 'catalog'"
            @set-view="setView"
        >
            <template #actions>
                <button
                    type="button"
                    class="btn btn-outline-primary fs-13"
                    :title="t.btn_import"
                    :aria-label="t.btn_import"
                    data-test="open-import"
                    @click="tab = 'imports'"
                >
                    <i class="ti ti-file-import" aria-hidden="true"></i
                    ><span class="d-none d-md-inline ms-1">{{ t.btn_import }}</span>
                </button>
                <button
                    type="button"
                    class="btn btn-primary fs-13"
                    :title="t.btn_new"
                    :aria-label="t.btn_new"
                    data-test="new-code"
                    @click="openCreate"
                >
                    <i class="ti ti-plus" aria-hidden="true"></i
                    ><span class="d-none d-sm-inline ms-1">{{ t.btn_new }}</span>
                </button>
            </template>
        </PageHeader>

        <!-- ── Resumo do catálogo ─────────────────────────────────────────── -->
        <section class="cid-summary mb-3" :aria-label="t.kpi_label">
            <KpiCard
                v-for="card in kpis"
                :key="card.key"
                tinted
                toggle
                :active="card.active"
                :tone="card.tone"
                :icon="`ti ${card.icon}`"
                :label="t[`kpi_${card.key}`]"
                :hint="t[`kpi_${card.key}_hint`]"
                :value="number(stats[card.key] ?? 0)"
                :test-id="card.key"
                @click="applyKpi(card.key)"
            />
            <KpiCard
                tinted
                :action="(stats.review ?? 0) > 0"
                tone="danger"
                icon="ti ti-alert-triangle"
                :label="t.kpi_review"
                :hint="(stats.review ?? 0) > 0 ? t.kpi_review_hint : t.kpi_review_none"
                :value="number(stats.review ?? 0)"
                test-id="review"
                @click="openReview"
            />
        </section>

        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item" role="presentation">
                <button
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === 'catalog' }"
                    role="tab"
                    :aria-selected="tab === 'catalog'"
                    data-test="tab-catalog"
                    @click="tab = 'catalog'"
                >
                    <i class="ti ti-list me-1" aria-hidden="true"></i>{{ t.tab_catalog }}
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === 'imports' }"
                    role="tab"
                    :aria-selected="tab === 'imports'"
                    data-test="tab-imports"
                    @click="tab = 'imports'"
                >
                    <i class="ti ti-file-import me-1" aria-hidden="true"></i>{{ t.tab_imports }}
                    <span v-if="importRunning" class="spinner-border spinner-border-sm ms-1" aria-hidden="true"></span>
                </button>
            </li>
        </ul>

        <!-- ════════ Catálogo ════════ -->
        <div v-show="tab === 'catalog'">
            <div class="cid-filters mb-2" role="search">
                <SearchInput
                    v-model="search"
                    class="cid-filters__search"
                    wrapper-class=""
                    :placeholder="t.search_placeholder"
                    :clear-label="t.search_clear"
                    max-width="320px"
                />
                <label class="visually-hidden" for="cid-filter-source">{{ t.filter_source }}</label>
                <select id="cid-filter-source" v-model="source" class="form-select form-select-sm">
                    <option value="">{{ t.filter_source_all }}</option>
                    <option value="datasus">{{ t.source_datasus }}</option>
                    <option value="custom">{{ t.source_custom }}</option>
                </select>
                <label class="visually-hidden" for="cid-filter-chapter">{{ t.filter_chapter }}</label>
                <select id="cid-filter-chapter" v-model="chapter" class="form-select form-select-sm">
                    <option value="">{{ t.filter_chapter_all }}</option>
                    <option v-for="c in chapters" :key="c.chapter" :value="c.chapter">{{ c.label }}</option>
                </select>
                <label class="visually-hidden" for="cid-filter-category">{{ t.filter_category }}</label>
                <select id="cid-filter-category" v-model="category" class="form-select form-select-sm">
                    <option value="">{{ t.filter_category_all }}</option>
                    <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                </select>
                <label class="visually-hidden" for="cid-filter-usage">{{ t.filter_usage }}</label>
                <select id="cid-filter-usage" v-model="usage" class="form-select form-select-sm">
                    <option value="">{{ t.filter_usage_all }}</option>
                    <option value="used">{{ t.filter_usage_used }}</option>
                    <option value="unused">{{ t.filter_usage_unused }}</option>
                </select>
                <div class="form-check mb-0">
                    <input id="cid-filter-edited" v-model="edited" type="checkbox" class="form-check-input" />
                    <label for="cid-filter-edited" class="form-check-label">{{ t.filter_edited }}</label>
                </div>
                <button
                    v-if="hasFilters"
                    type="button"
                    class="btn btn-sm btn-link text-decoration-none"
                    data-test="clear-filters"
                    @click="clearFilters"
                >
                    <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ t.clear_filters }}
                </button>
            </div>
            <p v-if="usageUpdatedText" class="small text-muted mb-3">
                <i class="ti ti-clock me-1" aria-hidden="true"></i>{{ usageUpdatedText }}
            </p>

            <Cid10Table
                v-if="view === 'table'"
                :codes="codes"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @view="openDetail"
                @edit="openEdit"
                @delete="remove"
            />
            <Cid10Cards v-else :codes="codes" :t="t" @view="openDetail" @edit="openEdit" @delete="remove" />
        </div>

        <!-- ════════ Importações ════════ -->
        <div v-show="tab === 'imports'">
            <div class="card">
                <div class="card-body">
                    <h6 class="fw-semibold">{{ t.import_title }}</h6>
                    <p class="text-muted small mb-3">
                        {{ t.import_help }}
                        <a
                            href="http://www2.datasus.gov.br/cid10/V2008/descrcsv.htm"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="ms-1"
                            >{{ t.import_source_link }}<i class="ti ti-external-link ms-1" aria-hidden="true"></i
                        ></a>
                    </p>

                    <!-- Progresso em tempo real (WebSocket) -->
                    <div
                        v-if="progressImport"
                        class="alert mb-3"
                        :class="alertClass(progressImport)"
                        role="status"
                        aria-live="polite"
                        data-test="import-progress"
                    >
                        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                            <strong class="text-truncate">{{ progressImport.original_name }}</strong>
                            <span :class="`badge bg-${progressImport.status_color}`">{{
                                progressImport.status_label
                            }}</span>
                            <span v-if="progressImport.phase_label" class="small text-muted">
                                — {{ progressImport.phase_label }}
                            </span>
                            <button
                                v-if="!importRunning"
                                type="button"
                                class="btn-close ms-auto"
                                :aria-label="t.close"
                                @click="progressImport = null"
                            ></button>
                        </div>
                        <div
                            class="progress mb-2"
                            style="height: 8px"
                            role="progressbar"
                            :aria-valuenow="progressImport.progress"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="t.import_progress_label"
                        >
                            <div
                                class="progress-bar"
                                :class="
                                    importRunning
                                        ? 'bg-info progress-bar-striped progress-bar-animated'
                                        : progressImport.status === 'done'
                                          ? 'bg-success'
                                          : progressImport.status === 'cancelled'
                                            ? 'bg-secondary'
                                            : 'bg-danger'
                                "
                                :style="`width: ${importRunning ? Math.max(progressImport.progress, 3) : 100}%`"
                            ></div>
                        </div>
                        <div v-if="importQueued" class="small">
                            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                            >{{ t.import_waiting_worker }}
                        </div>
                        <div v-else class="small">
                            <template v-if="progressImport.total_rows">
                                {{ fmtProgress(progressImport) }} ({{ progressImport.progress }}%)
                            </template>
                            <template v-if="progressImport.status === 'done'">
                                — {{ t.result_read }}: <strong>{{ number(progressImport.read_count) }}</strong> ·
                                <span class="text-success"
                                    >{{ t.result_created }}:
                                    <strong>{{ number(progressImport.created_count) }}</strong></span
                                >
                                · {{ t.result_corrected }}:
                                <strong>{{ number(progressImport.corrected_count) }}</strong> · {{ t.result_kept }}:
                                <strong>{{ number(progressImport.kept_edited_count) }}</strong> ·
                                <span class="text-warning"
                                    >{{ t.result_errors }}:
                                    <strong>{{ number(progressImport.skipped_invalid) }}</strong></span
                                >
                            </template>
                        </div>
                        <div v-if="progressImport.error" class="small text-danger mt-1">{{ progressImport.error }}</div>
                        <div v-if="importStalled" class="small mt-2" role="alert">
                            <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                            >{{ importQueued ? t.import_stalled_pending : t.import_stalled_processing }}
                            <div class="mt-2">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    :disabled="cancelling"
                                    data-test="import-cancel"
                                    @click="cancelImport"
                                >
                                    <span
                                        v-if="cancelling"
                                        class="spinner-border spinner-border-sm me-1"
                                        aria-hidden="true"
                                    ></span>
                                    <i v-else class="ti ti-player-stop me-1" aria-hidden="true"></i
                                    >{{ t.import_cancel }}
                                </button>
                            </div>
                            <div v-if="cancelError" class="text-danger mt-1">{{ cancelError }}</div>
                        </div>
                        <div
                            v-if="importRunning && !realtimeConnected"
                            class="d-flex flex-wrap align-items-center gap-2 mt-2 small text-muted"
                        >
                            <span
                                ><i class="ti ti-plug-connected-x me-1" aria-hidden="true"></i
                                >{{ page.props.t_ui?.realtime_offline }}</span
                            >
                            <button
                                type="button"
                                class="btn btn-sm btn-light"
                                data-test="import-resync"
                                @click="resync"
                            >
                                <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ t.import_refresh_status }}
                            </button>
                        </div>
                    </div>

                    <form class="row g-3" @submit.prevent="submitImport">
                        <div class="col-12 col-lg-8">
                            <label class="form-label fw-semibold" for="cid-import-files">
                                {{ t.import_files }} <span class="text-danger">*</span>
                            </label>
                            <input
                                id="cid-import-files"
                                :key="`files-${fileInputKey}`"
                                type="file"
                                multiple
                                class="form-control"
                                :class="{ 'is-invalid': importError }"
                                accept=".zip,.csv"
                                aria-describedby="cid-import-files-hint"
                                @input="pickFiles"
                            />
                            <div id="cid-import-files-hint" class="form-text">{{ t.import_files_hint }}</div>
                            <div class="invalid-feedback" data-test="import-error">{{ importError }}</div>
                        </div>

                        <div class="col-12">
                            <button
                                type="submit"
                                class="btn btn-primary"
                                :disabled="!canSubmitImport"
                                data-test="import-submit"
                            >
                                <span v-if="importForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-upload me-1" aria-hidden="true"></i>
                                {{ t.import_submit }}
                            </button>
                            <span v-if="importForm.files.length" class="small text-muted ms-2">{{
                                (t.import_selected ?? '').replace(':count', number(importForm.files.length))
                            }}</span>
                            <div
                                v-if="importForm.progress"
                                class="progress mt-2"
                                role="progressbar"
                                :aria-valuenow="importForm.progress.percentage"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                style="height: 4px; max-width: 320px"
                            >
                                <div
                                    class="progress-bar"
                                    :style="{ width: importForm.progress.percentage + '%' }"
                                ></div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-transparent fw-semibold">{{ t.import_history }}</div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.col_date }}</th>
                                <th>{{ t.col_status }}</th>
                                <th class="d-none d-md-table-cell">{{ t.col_files }}</th>
                                <th>{{ t.col_result }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!imports.length">
                                <td colspan="4" class="text-center text-muted py-3">{{ t.import_empty }}</td>
                            </tr>
                            <tr v-for="imp in imports" :key="imp.id" data-test="import-row">
                                <td class="text-nowrap">
                                    {{ imp.created_at }}
                                    <div v-if="imp.user" class="text-muted">{{ imp.user }}</div>
                                </td>
                                <td>
                                    <span class="badge" :class="`bg-${imp.status_color}`">{{ imp.status_label }}</span>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <div v-for="file in imp.files" :key="file.kind" class="text-muted text-break">
                                        {{ fileLabel(file) }}
                                    </div>
                                </td>
                                <td>
                                    <div v-if="imp.error" class="text-danger">{{ imp.error }}</div>
                                    <div v-else-if="imp.status === 'done'">
                                        <span class="me-2"
                                            >{{ t.result_read }}: <strong>{{ number(imp.read_count) }}</strong></span
                                        >
                                        <span class="me-2"
                                            >{{ t.result_created }}:
                                            <strong>{{ number(imp.created_count) }}</strong></span
                                        >
                                        <span class="me-2"
                                            >{{ t.result_corrected }}:
                                            <strong>{{ number(imp.corrected_count) }}</strong></span
                                        >
                                        <div class="text-muted">
                                            {{ t.result_official }}: {{ number(imp.official_updated_count) }} ·
                                            {{ t.result_kept }}: {{ number(imp.kept_edited_count) }} ·
                                            {{ t.result_errors }}: {{ number(imp.skipped_invalid) }}
                                        </div>
                                    </div>
                                    <span v-else class="text-muted">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <Cid10FormModal :open="formOpen" :code="editing" :categories="categories" :t="t" @close="formOpen = false" />

        <Cid10DetailDrawer
            :open="detailOpen"
            :code="detail"
            :t="t"
            @close="detailOpen = false"
            @edit="openEdit"
            @delete="remove"
        />

        <Cid10ReviewDrawer
            :open="reviewOpen"
            :summary="review"
            :records="reviewRecords"
            :loading="reviewLoading"
            :t="t"
            @close="reviewOpen = false"
        />

        <!-- Confirmação destrutiva com justificativa -->
        <ConfirmationWithReasonModal
            :open="reasonModal.open"
            :title="reasonModal.title"
            :message="reasonModal.message"
            :confirm-label="reasonModal.confirmLabel"
            :confirm-variant="reasonModal.confirmVariant"
            :saving="reasonModal.saving"
            :error="deleteError"
            @close="closeReasonModal"
            @confirm="confirmDelete"
        />
    </AppLayout>
</template>

<style scoped>
.cid-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 0.75rem;
}
/* Celular: 2 por linha (os rótulos quebram em duas linhas). */
@media (max-width: 575.98px) {
    .cid-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem;
    }
}
.cid-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}
.cid-filters__search {
    flex: 0 1 320px;
}
.cid-filters .form-select {
    width: auto;
    min-width: 160px;
    max-width: 100%;
}
/* Capítulo/categoria têm nomes longos: limitam a largura no desktop. */
#cid-filter-chapter,
#cid-filter-category {
    max-width: 260px;
}
@media (max-width: 575.98px) {
    .cid-filters__search,
    .cid-filters .form-select {
        flex: 1 1 100%;
    }
    #cid-filter-chapter,
    #cid-filter-category {
        max-width: 100%;
    }
    /* A busca limita a 320px (inline): no celular ocupa a largura toda, como os selects. */
    .cid-filters__search :deep(.input-group) {
        max-width: none !important;
    }
}
</style>
