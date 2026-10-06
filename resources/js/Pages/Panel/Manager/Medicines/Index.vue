<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useConfirmationWithReason } from '@/composables/useConfirmationWithReason.js';
import MedicineTable from './MedicineTable.vue';
import MedicineCards from './MedicineCards.vue';
import MedicineDetailDrawer from './MedicineDetailDrawer.vue';
import { choice } from '@/utils/billingPeriods.js';
import MedicineFormModal from './MedicineFormModal.vue';
import { useImportProgress } from '@/composables/useImportProgress';
import PosologyBatchModal from './PosologyBatchModal.vue';
import PosologyBatchPanel from './PosologyBatchPanel.vue';

/**
 * Manager → Medicamentos: catálogo GLOBAL usado na busca do receituário de
 * todas as clínicas. Itens curados (manuais, com posologia sugerida) +
 * importação da lista de preços CMED/Anvisa (apresentações, genéricos).
 */
const props = defineProps({
    medicines: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    imports: { type: Array, default: () => [] },
    // Importação na fila/processando (progressPayload do servidor) ou null.
    runningImport: { type: Object, default: null },
    presentations: { type: Array, default: () => [] },
    // Verificação semanal automática ligada (CMED_SYNC_ENABLED).
    autoSync: { type: Boolean, default: false },
    // IAs configuradas (botão "Gerar com IA" no formulário; com 2+, o admin escolhe).
    aiProviders: { type: Array, default: () => [] },
    // Lote "Gerar posologia com IA": em andamento (progressPayload) ou null,
    // histórico e teto de chamadas por lote.
    runningPosologyBatch: { type: Object, default: null },
    posologyBatches: { type: Array, default: () => [] },
    posologyBatchCap: { type: Number, default: 0 },
    t: { type: Object, default: () => ({}) },
});

const { number } = useLocaleFormat();
const page = usePage();

const tab = ref(props.runningImport ? 'imports' : 'catalog');

// ── Tabela / cards (mesmo padrão de Planos) ──────────────────────────────
const VIEW_KEY = 'mgr_medicines_view';
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
const status = ref(props.filters.status ?? '');
const cmedSituation = ref(props.filters.cmed_situation ?? '');
const ophthalmic = ref(!!props.filters.ophthalmic);
const posology = ref(props.filters.posology ?? '');
const sort = ref(props.filters.sort ?? 'name');
const direction = ref(props.filters.direction ?? 'asc');

function applyFilters() {
    router.get(
        route('manager.medicines.index'),
        {
            search: search.value || undefined,
            source: source.value || undefined,
            status: status.value || undefined,
            cmed_situation: cmedSituation.value || undefined,
            ophthalmic: ophthalmic.value ? 1 : undefined,
            posology: posology.value || undefined,
            // Ordem padrão (nome A→Z) fica fora da URL.
            sort: sort.value !== 'name' || direction.value !== 'asc' ? sort.value : undefined,
            direction: sort.value !== 'name' || direction.value !== 'asc' ? direction.value : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['medicines', 'filters'] },
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
watch([source, status, cmedSituation, ophthalmic, posology], applyFilters);

// ── Cadastro / edição ────────────────────────────────────────────────────
const formOpen = ref(false);
const editing = ref(null);

function openCreate() {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(medicine) {
    editing.value = medicine;
    formOpen.value = true;
}

function toggleActive(medicine) {
    router.put(route('manager.medicines.update', medicine.id), { active: !medicine.active }, { preserveScroll: true });
}

// ── Detalhes ─────────────────────────────────────────────────────────────
const detail = ref(null);
const detailOpen = ref(false);

function openDetail(medicine) {
    detail.value = medicine;
    detailOpen.value = true;
}

function editFromDetail(medicine) {
    detailOpen.value = false;
    openEdit(medicine);
}

// A linha mudou (ativar/editar/aprovar): o drawer aberto acompanha.
watch(
    () => props.medicines.data,
    (rows) => {
        if (detail.value) detail.value = rows.find((r) => r.id === detail.value.id) ?? detail.value;

        // "Revisar agora": filtrou as pendentes — abre a primeira.
        if (openFirstPending.value) {
            openFirstPending.value = false;
            const first = rows.find((r) => r.posology_pending_review);
            if (first) openDetail(first);
        }
    },
);

// ── Revisão da posologia gerada por IA em um clique ──────────────────────
const approving = ref(false);
const approveError = ref('');
const approveMessage = ref('');
const openFirstPending = ref(false);

/** Próxima pendente na página (depois da atual, na ordem da lista). */
function nextPendingAfter(medicine, rows = props.medicines.data) {
    const index = rows.findIndex((r) => r.id === medicine.id);

    return (
        rows.slice(index + 1).find((r) => r.posology_pending_review) ??
        rows.slice(0, Math.max(index, 0)).find((r) => r.posology_pending_review) ??
        null
    );
}

const hasNextPending = computed(() => !!detail.value && !!nextPendingAfter(detail.value));

function approvePosology(medicine, goNext = false) {
    if (approving.value) return;

    approving.value = true;
    approveError.value = '';
    approveMessage.value = '';

    router.post(
        route('manager.medicines.posology.approve', medicine.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['medicines', 'stats', 'flash'],
            onSuccess: () => {
                // Com o filtro "não revisada" a linha sai da lista: a gaveta
                // não pode seguir mostrando o item como pendente.
                const fresh = props.medicines.data.find((r) => r.id === medicine.id);
                if (detail.value?.id === medicine.id) {
                    detail.value = fresh ?? { ...medicine, posology_pending_review: false, posology_source: 'manual' };
                }

                if (!goNext) return;

                const next = nextPendingAfter(medicine);
                if (next) {
                    detail.value = next;
                    detailOpen.value = true;
                } else {
                    approveMessage.value = props.t.posology_review_last;
                }
            },
            onError: (errors) => {
                approveError.value = errors.posology ?? Object.values(errors)[0] ?? '';
            },
            onFinish: () => {
                approving.value = false;
            },
        },
    );
}

// Mensagens da gaveta valem só para o item em que apareceram.
watch(
    () => detail.value?.id,
    () => {
        approveError.value = '';
        if (!approving.value) approveMessage.value = '';
    },
);

function reviewNow() {
    if (posology.value === 'ai_pending') {
        const first = props.medicines.data.find((r) => r.posology_pending_review);
        if (first) openDetail(first);
        return;
    }
    openFirstPending.value = true;
    posology.value = 'ai_pending';
}

const reviewBanner = computed(() =>
    choice(props.t.posology_review_banner, Number(props.stats?.ai_pending ?? 0), {
        count: number(props.stats?.ai_pending ?? 0),
    }),
);

// ── Exclusão: justificativa obrigatória + auditoria (padrão do manager) ──
const {
    state: reasonModal,
    open: openReasonModal,
    close: closeReasonModal,
    handle: handleReasonConfirm,
} = useConfirmationWithReason();
const deleteError = ref('');

function remove(medicine) {
    deleteError.value = '';
    openReasonModal({
        title: props.t.confirm_delete_title,
        message: (props.t.confirm_delete_text ?? '').replace(':name', medicine.name),
        confirmLabel: props.t.delete,
        confirmVariant: 'danger',
        onConfirm: (reason) =>
            new Promise((resolve, reject) => {
                router.delete(route('manager.medicines.destroy', medicine.id), {
                    data: { reason },
                    preserveScroll: true,
                    onSuccess: () => {
                        if (detail.value?.id === medicine.id) detailOpen.value = false;
                        resolve();
                    },
                    onError: (errors) => {
                        deleteError.value = errors.reason ?? Object.values(errors)[0] ?? '';
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

// ── Carga do catálogo: download da CMED/Anvisa ou envio dos arquivos ─────
// Barra de progresso em tempo real (WebSocket/Reverb — sem polling HTTP),
// mesmo padrão das demais importações. Ao terminar, mantém o cartão com o
// resultado e recarrega histórico, números e catálogo.
const progressImport = ref(props.runningImport);
const importRunning = computed(() => !!progressImport.value && !progressImport.value.is_done);

const { realtimeConnected, resync } = useImportProgress(progressImport, {
    onDone: () => router.reload({ only: ['imports', 'stats', 'medicines'] }),
    // Assinou/reconectou (ou "Atualizar status"): relê o estado uma vez.
    onResync: () => router.reload({ only: ['runningImport', 'imports', 'stats', 'medicines'] }),
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

const importForm = useForm({ source: 'cmed', force: false, cmed_file: null, open_data_file: null });
const isUpload = computed(() => importForm.source === 'upload');
const fileInputs = ref(0); // troca a key dos inputs de arquivo pra limpá-los

const canSubmitImport = computed(
    () => (!isUpload.value || !!importForm.cmed_file) && !importForm.processing && !importRunning.value,
);

function submitImport() {
    if (!canSubmitImport.value) return;

    importForm
        // Download: arquivos não vão (mesmo que tenham sido escolhidos antes).
        .transform((data) =>
            data.source === 'upload'
                ? { source: 'upload', cmed_file: data.cmed_file, open_data_file: data.open_data_file }
                : { source: 'cmed', force: !!data.force },
        )
        .post(route('manager.medicines.imports.store'), {
            forceFormData: isUpload.value,
            preserveScroll: true,
            onSuccess: () => {
                importForm.reset('cmed_file', 'open_data_file', 'force');
                fileInputs.value++;
                tab.value = 'imports';
            },
        });
}

function pickFile(field, event) {
    importForm[field] = event.target.files?.[0] ?? null;
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
        route('manager.medicines.imports.cancel', progressImport.value.id),
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

function fmtProgress(text, imp) {
    return (text ?? '')
        .replace(':processed', number(imp.processed_rows ?? 0))
        .replace(':total', number(imp.total_rows ?? 0));
}

function listLabel(imp) {
    return imp.list_published_at ? (props.t.list_published ?? '').replace(':date', imp.list_published_at) : null;
}

// ── Posologia por IA em lote (filtros aplicados) ─────────────────────────
// O painel (aba "Posologia por IA") acompanha o lote por WebSocket e avisa o
// estado aqui: com um lote rodando, o botão do cabeçalho mostra o progresso
// em vez de abrir outro.
const batchPanel = ref(null);
const batchProgress = ref(props.runningPosologyBatch);
const batchRunning = computed(() => !!batchProgress.value && !batchProgress.value.is_done);
const batchModalOpen = ref(false);

function onBatchButton() {
    if (batchRunning.value) {
        tab.value = 'posology';
        return;
    }
    batchModalOpen.value = true;
}

function onBatchStarted() {
    batchModalOpen.value = false;
    tab.value = 'posology';
}

function onBatchAlreadyRunning(batch) {
    batchPanel.value?.show(batch);
    tab.value = 'posology';
}

const batchButtonText = computed(() =>
    batchRunning.value
        ? (props.t.batch_button_running ?? '').replace(':progress', String(batchProgress.value.progress ?? 0))
        : props.t.batch_button,
);

const breadcrumbs = [
    { label: props.t.breadcrumb_home ?? 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: props.t.page_title, url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <PageHeader
            :title="t.page_title"
            :total="medicines.total ?? null"
            :total-label="t.total_label"
            :view="view"
            :view-table-title="t.view_table"
            :view-cards-title="t.view_cards"
            :show-view-toggle="tab === 'catalog'"
            @set-view="setView"
        >
            <template #actions>
                <button
                    v-if="aiProviders.length || batchRunning"
                    type="button"
                    class="btn btn-soft-primary fs-13"
                    :title="batchRunning ? t.batch_button_running_hint : t.batch_button_hint"
                    :aria-label="batchButtonText"
                    data-test="batch-button"
                    @click="onBatchButton"
                >
                    <span v-if="batchRunning" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <i v-else class="ti ti-sparkles" aria-hidden="true"></i
                    ><span class="d-none d-lg-inline ms-1">{{ batchButtonText }}</span
                    ><span class="d-inline d-lg-none ms-1">{{
                        batchRunning ? `${batchProgress.progress ?? 0}%` : t.batch_button_short
                    }}</span>
                </button>
                <button
                    type="button"
                    class="btn btn-outline-primary fs-13"
                    :title="t.btn_import"
                    :aria-label="t.btn_import"
                    @click="tab = 'imports'"
                >
                    <i class="ti ti-refresh"></i><span class="d-none d-md-inline ms-1">{{ t.btn_import }}</span>
                </button>
                <button
                    type="button"
                    class="btn btn-primary fs-13"
                    :title="t.btn_new"
                    :aria-label="t.btn_new"
                    @click="openCreate"
                >
                    <i class="ti ti-plus"></i><span class="d-none d-sm-inline ms-1">{{ t.btn_new }}</span>
                </button>
            </template>
        </PageHeader>

        <!-- Posologias geradas por IA aguardando revisão: atalho para revisar em sequência -->
        <div
            v-if="(stats.ai_pending ?? 0) > 0"
            class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2"
            role="status"
            data-test="review-banner"
        >
            <i class="ti ti-sparkles" aria-hidden="true"></i>
            <span class="flex-grow-1">{{ reviewBanner }}</span>
            <button type="button" class="btn btn-sm btn-warning" data-test="review-now" @click="reviewNow">
                <i class="ti ti-checklist me-1" aria-hidden="true"></i>{{ t.posology_review_now }}
            </button>
        </div>

        <!-- Números do catálogo -->
        <div class="row g-2 mb-3">
            <div v-for="key in ['active', 'cmed', 'manual', 'ophthalmic']" :key="key" class="col-6 col-lg-3">
                <div class="card mb-0 h-100">
                    <div class="card-body py-2 px-3">
                        <div class="text-muted small">{{ t['stat_' + key] }}</div>
                        <div class="fs-4 fw-semibold">{{ number(stats[key] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item" role="presentation">
                <button
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === 'catalog' }"
                    role="tab"
                    :aria-selected="tab === 'catalog'"
                    @click="tab = 'catalog'"
                >
                    <i class="ti ti-list me-1"></i>{{ t.tab_catalog }}
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === 'imports' }"
                    role="tab"
                    :aria-selected="tab === 'imports'"
                    @click="tab = 'imports'"
                >
                    <i class="ti ti-file-import me-1"></i>{{ t.tab_imports }}
                    <span v-if="importRunning" class="spinner-border spinner-border-sm ms-1" aria-hidden="true"></span>
                </button>
            </li>
            <li
                v-if="aiProviders.length || posologyBatches.length || batchProgress"
                class="nav-item"
                role="presentation"
            >
                <button
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === 'posology' }"
                    role="tab"
                    :aria-selected="tab === 'posology'"
                    data-test="tab-posology"
                    @click="tab = 'posology'"
                >
                    <i class="ti ti-sparkles me-1"></i>{{ t.tab_posology_batches }}
                    <span v-if="batchRunning" class="spinner-border spinner-border-sm ms-1" aria-hidden="true"></span>
                </button>
            </li>
        </ul>

        <!-- ════════ Catálogo ════════ -->
        <div v-show="tab === 'catalog'">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <SearchInput
                    v-model="search"
                    :placeholder="t.search_placeholder"
                    max-width="360px"
                    wrapper-class="mb-0 flex-grow-1"
                />
                <select v-model="source" class="form-select w-auto" :aria-label="t.filter_source">
                    <option value="">{{ t.filter_source_all }}</option>
                    <option value="manual">{{ t.source_manual }}</option>
                    <option value="cmed">{{ t.source_cmed }}</option>
                </select>
                <select v-model="cmedSituation" class="form-select w-auto" :aria-label="t.filter_cmed_situation">
                    <option value="">{{ t.filter_cmed_situation_all }}</option>
                    <option value="marketed">{{ t.cmed_marketed }}</option>
                    <option value="not_marketed">{{ t.not_marketed }}</option>
                    <option value="left_list">{{ t.cmed_left_list }}</option>
                </select>
                <select v-model="status" class="form-select w-auto" :aria-label="t.filter_status">
                    <option value="">{{ t.filter_status_all }}</option>
                    <option value="active">{{ t.status_active }}</option>
                    <option value="inactive">{{ t.status_inactive }}</option>
                </select>
                <select
                    v-model="posology"
                    class="form-select w-auto"
                    :aria-label="t.filter_posology"
                    data-test="filter-posology"
                >
                    <option value="">{{ t.filter_posology_all }}</option>
                    <option value="empty">{{ t.posology_filter_empty }}</option>
                    <option value="ai_pending">{{ t.posology_filter_ai_pending }}</option>
                    <option value="reviewed">{{ t.posology_filter_reviewed }}</option>
                </select>
                <div class="form-check mb-0">
                    <input id="flt-oft" v-model="ophthalmic" type="checkbox" class="form-check-input" />
                    <label for="flt-oft" class="form-check-label">{{ t.filter_ophthalmic }}</label>
                </div>
            </div>

            <MedicineTable
                v-if="view === 'table'"
                :medicines="medicines"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @view="openDetail"
                @edit="openEdit"
                @delete="remove"
                @toggle-active="toggleActive"
                @approve="approvePosology($event)"
            />
            <MedicineCards
                v-else
                :medicines="medicines"
                :t="t"
                @view="openDetail"
                @edit="openEdit"
                @delete="remove"
                @toggle-active="toggleActive"
                @approve="approvePosology($event)"
            />
        </div>

        <!-- ════════ Importações ════════ -->
        <div v-show="tab === 'imports'">
            <div class="card">
                <div class="card-body">
                    <h6 class="fw-semibold">{{ t.import_title }}</h6>
                    <p class="text-muted small mb-2">{{ t.import_help_sync }}</p>
                    <p v-if="autoSync" class="small mb-3">
                        <i class="ti ti-calendar-repeat me-1 text-primary" aria-hidden="true"></i
                        >{{ t.import_auto_hint }}
                    </p>

                    <!-- Progresso em tempo real (WebSocket) -->
                    <div
                        v-if="progressImport"
                        class="alert mb-3"
                        :class="alertClass(progressImport)"
                        role="status"
                        aria-live="polite"
                    >
                        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                            <strong class="text-truncate">{{
                                progressImport.source === 'upload'
                                    ? progressImport.cmed_original_name
                                    : progressImport.source_label
                            }}</strong>
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
                        <div v-if="listLabel(progressImport)" class="small mb-2">
                            <i class="ti ti-calendar me-1" aria-hidden="true"></i>{{ listLabel(progressImport) }}
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
                                {{ fmtProgress(t.import_progress_rows, progressImport) }}
                                ({{ progressImport.progress }}%) —
                            </template>
                            <span class="text-success"
                                >{{ t.result_created }}:
                                <strong>{{ number(progressImport.created_count) }}</strong></span
                            >
                            ·
                            <span
                                >{{ t.result_updated }}:
                                <strong>{{ number(progressImport.updated_count) }}</strong></span
                            >
                            ·
                            <span class="text-warning"
                                >{{ t.result_skipped_hospital }}:
                                <strong>{{ number(progressImport.skipped_hospital) }}</strong></span
                            >
                            ·
                            <span class="text-muted"
                                >{{ t.result_skipped_inactive }}:
                                <strong>{{ number(progressImport.skipped_inactive_registration) }}</strong></span
                            >
                            <template v-if="progressImport.status === 'done'">
                                · {{ t.result_deactivated }}:
                                <strong>{{ number(progressImport.deactivated_count) }}</strong>
                            </template>
                        </div>
                        <div v-if="progressImport.notice" class="small mt-1">
                            <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ progressImport.notice }}
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
                            <button type="button" class="btn btn-sm btn-light" @click="resync">
                                <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ t.import_refresh_status }}
                            </button>
                        </div>
                    </div>

                    <form class="row g-3" @submit.prevent="submitImport">
                        <fieldset class="col-12">
                            <legend class="form-label fw-semibold fs-14 mb-1">{{ t.import_mode }}</legend>
                            <div class="d-flex flex-wrap gap-3">
                                <div class="form-check mb-0">
                                    <input
                                        id="imp-mode-cmed"
                                        v-model="importForm.source"
                                        type="radio"
                                        value="cmed"
                                        class="form-check-input"
                                    />
                                    <label for="imp-mode-cmed" class="form-check-label">{{ t.import_mode_cmed }}</label>
                                </div>
                                <div class="form-check mb-0">
                                    <input
                                        id="imp-mode-upload"
                                        v-model="importForm.source"
                                        type="radio"
                                        value="upload"
                                        class="form-check-input"
                                    />
                                    <label for="imp-mode-upload" class="form-check-label">{{
                                        t.import_mode_upload
                                    }}</label>
                                </div>
                            </div>
                            <div v-if="importForm.errors.source" class="text-danger small mt-1">
                                {{ importForm.errors.source }}
                            </div>
                        </fieldset>

                        <div v-if="!isUpload" class="col-12">
                            <div class="form-check mb-0">
                                <input
                                    id="imp-force"
                                    v-model="importForm.force"
                                    type="checkbox"
                                    class="form-check-input"
                                />
                                <label for="imp-force" class="form-check-label">{{ t.import_force }}</label>
                            </div>
                        </div>

                        <template v-else>
                            <div class="col-12">
                                <div class="alert alert-light border small mb-0">
                                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.import_help }}
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label fw-semibold" for="imp-cmed">
                                    {{ t.import_cmed_file }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="imp-cmed"
                                    :key="`cmed-${fileInputs}`"
                                    type="file"
                                    class="form-control"
                                    :class="{ 'is-invalid': importForm.errors.cmed_file }"
                                    accept=".xlsx,.xls,.csv"
                                    @input="pickFile('cmed_file', $event)"
                                />
                                <div class="form-text">{{ t.import_cmed_hint }}</div>
                                <div class="invalid-feedback">{{ importForm.errors.cmed_file }}</div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label fw-semibold" for="imp-open">{{
                                    t.import_open_data_file
                                }}</label>
                                <input
                                    id="imp-open"
                                    :key="`open-${fileInputs}`"
                                    type="file"
                                    class="form-control"
                                    :class="{ 'is-invalid': importForm.errors.open_data_file }"
                                    accept=".csv"
                                    @input="pickFile('open_data_file', $event)"
                                />
                                <div class="form-text">{{ t.import_open_data_hint }}</div>
                                <div class="invalid-feedback">{{ importForm.errors.open_data_file }}</div>
                            </div>
                        </template>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary" :disabled="!canSubmitImport">
                                <span v-if="importForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else :class="`ti ${isUpload ? 'ti-upload' : 'ti-cloud-download'} me-1`"></i>
                                {{ isUpload ? t.import_submit : t.import_submit_cmed }}
                            </button>
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
                                <th class="d-none d-md-table-cell">{{ t.col_origin }}</th>
                                <th>{{ t.col_result }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!imports.length">
                                <td colspan="4" class="text-center text-muted py-3">{{ t.import_empty }}</td>
                            </tr>
                            <tr v-for="imp in imports" :key="imp.id">
                                <td class="text-nowrap">
                                    {{ imp.created_at }}
                                    <div v-if="imp.user" class="text-muted">{{ imp.user }}</div>
                                </td>
                                <td>
                                    <span class="badge" :class="`bg-${imp.status_color}`">{{ imp.status_label }}</span>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <div>{{ imp.source_label }}</div>
                                    <div v-if="listLabel(imp)" class="text-muted">{{ listLabel(imp) }}</div>
                                    <div v-if="imp.cmed_original_name" class="text-muted text-break">
                                        {{ imp.cmed_original_name }}
                                    </div>
                                    <div v-if="imp.open_data_original_name" class="text-muted text-break">
                                        {{ imp.open_data_original_name }}
                                    </div>
                                </td>
                                <td>
                                    <div v-if="imp.error" class="text-danger">{{ imp.error }}</div>
                                    <div v-else-if="imp.status === 'done'">
                                        <span class="me-2"
                                            >{{ t.result_created }}:
                                            <strong>{{ number(imp.created_count) }}</strong></span
                                        >
                                        <span class="me-2"
                                            >{{ t.result_updated }}:
                                            <strong>{{ number(imp.updated_count) }}</strong></span
                                        >
                                        <span class="me-2"
                                            >{{ t.result_deactivated }}:
                                            <strong>{{ number(imp.deactivated_count) }}</strong></span
                                        >
                                        <div class="text-muted">
                                            {{ t.result_skipped_hospital }}: {{ number(imp.skipped_hospital) }} ·
                                            {{ t.result_skipped_inactive }}:
                                            {{ number(imp.skipped_inactive_registration) }} ·
                                            {{ t.result_skipped_invalid }}: {{ number(imp.skipped_invalid) }}
                                        </div>
                                    </div>
                                    <span v-else class="text-muted">—</span>
                                    <div v-if="imp.notice" class="text-muted mt-1">
                                        <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ imp.notice }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ════════ Posologia por IA (lote) ════════ -->
        <div v-show="tab === 'posology'">
            <PosologyBatchPanel
                ref="batchPanel"
                :running="runningPosologyBatch"
                :batches="posologyBatches"
                :t="t"
                @progress="batchProgress = $event"
            />
        </div>

        <PosologyBatchModal
            :open="batchModalOpen"
            :filters="filters"
            :ai-providers="aiProviders"
            :t="t"
            @close="batchModalOpen = false"
            @started="onBatchStarted"
            @running="onBatchAlreadyRunning"
        />

        <MedicineFormModal
            :open="formOpen"
            :medicine="editing"
            :presentations="presentations"
            :ai-providers="aiProviders"
            :t="t"
            @close="formOpen = false"
            @providers-stale="router.reload({ only: ['aiProviders'] })"
        />

        <MedicineDetailDrawer
            :open="detailOpen"
            :medicine="detail"
            :t="t"
            :approving="approving"
            :has-next-pending="hasNextPending"
            :approve-message="approveMessage"
            :approve-error="approveError"
            @close="detailOpen = false"
            @edit="editFromDetail"
            @approve="approvePosology"
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
