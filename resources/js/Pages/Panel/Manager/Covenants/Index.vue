<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useConfirmationWithReason } from '@/composables/useConfirmationWithReason.js';
import { useImportProgress } from '@/composables/useImportProgress';
import CovenantTable from './CovenantTable.vue';
import CovenantCards from './CovenantCards.vue';
import CovenantDetailDrawer from './CovenantDetailDrawer.vue';
import CovenantFormModal from './CovenantFormModal.vue';

/**
 * Manager → Convênios: catálogo GLOBAL de convênios visto por todas as
 * clínicas. Operadoras sincronizadas com o Cadastro de Operadoras da ANS
 * (dados abertos) + cadastros manuais.
 */
const props = defineProps({
    covenants: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    imports: { type: Array, default: () => [] },
    // Sincronização na fila/processando (progressPayload do servidor) ou null.
    runningImport: { type: Object, default: null },
    modalities: { type: Array, default: () => [] },
    defaultModalities: { type: Array, default: () => [] },
    ufs: { type: Array, default: () => [] },
    autoSync: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
    tPlans: { type: Object, default: () => ({}) }, // covenant_plans
});

const { number } = useLocaleFormat();
const page = usePage();

const tab = ref(props.runningImport ? 'imports' : 'catalog');

// ── Tabela / cards (mesmo padrão de Medicamentos) ────────────────────────
const VIEW_KEY = 'mgr_covenants_view';
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
const modality = ref(props.filters.modality ?? '');
const uf = ref(props.filters.uf ?? '');
const cancelled = ref(!!props.filters.cancelled);
const sort = ref(props.filters.sort ?? 'name');
const direction = ref(props.filters.direction ?? 'asc');

function applyFilters() {
    const customSort = sort.value !== 'name' || direction.value !== 'asc';

    router.get(
        route('manager.covenants.index'),
        {
            search: search.value || undefined,
            source: source.value || undefined,
            status: status.value || undefined,
            modality: modality.value || undefined,
            uf: uf.value || undefined,
            cancelled: cancelled.value ? 1 : undefined,
            // Ordem padrão (nome A→Z) fica fora da URL.
            sort: customSort ? sort.value : undefined,
            direction: customSort ? direction.value : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['covenants', 'filters'] },
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
watch([source, status, modality, uf, cancelled], applyFilters);

// ── Cadastro / edição ────────────────────────────────────────────────────
const formOpen = ref(false);
const editing = ref(null);

function openCreate() {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(covenant) {
    editing.value = covenant;
    formOpen.value = true;
}

function toggleActive(covenant) {
    router.put(route('manager.covenants.update', covenant.id), { active: !covenant.active }, { preserveScroll: true });
}

// ── Detalhes ─────────────────────────────────────────────────────────────
const detail = ref(null);
const detailOpen = ref(false);

function openDetail(covenant) {
    detail.value = covenant;
    detailOpen.value = true;
}

function editFromDetail(covenant) {
    detailOpen.value = false;
    openEdit(covenant);
}

// A linha mudou (ativar/editar): o drawer aberto acompanha.
watch(
    () => props.covenants.data,
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

function remove(covenant) {
    deleteError.value = '';
    openReasonModal({
        title: props.t.confirm_delete_title,
        message: (props.t.confirm_delete_text ?? '').replace(':name', covenant.name),
        confirmLabel: props.t.delete,
        confirmVariant: 'danger',
        onConfirm: (reason) =>
            new Promise((resolve, reject) => {
                router.delete(route('manager.covenants.destroy', covenant.id), {
                    data: { reason },
                    preserveScroll: true,
                    onSuccess: () => {
                        if (detail.value?.id === covenant.id) detailOpen.value = false;
                        resolve();
                    },
                    onError: (errors) => {
                        // "Em uso" também volta em errors.reason.
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

// ── Sincronização com a ANS ──────────────────────────────────────────────
// Barra de progresso em tempo real (WebSocket/Reverb — sem polling HTTP),
// mesmo padrão das demais importações. Ao terminar, mantém o cartão com o
// resultado e recarrega histórico, números e catálogo.
const progressImport = ref(props.runningImport);
const importRunning = computed(() => !!progressImport.value && !progressImport.value.is_done);

const { realtimeConnected, resync } = useImportProgress(progressImport, {
    onDone: () => router.reload({ only: ['imports', 'stats', 'covenants'] }),
    // Assinou/reconectou (ou "Atualizar status"): relê o estado uma vez.
    onResync: () => router.reload({ only: ['runningImport', 'imports', 'stats', 'covenants'] }),
});

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
onBeforeUnmount(() => clearInterval(idleTimer));

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
        route('manager.covenants.imports.cancel', progressImport.value.id),
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

const importForm = useForm({
    source: 'ans',
    modalities: [...props.defaultModalities],
    active_file: null,
    cancelled_file: null,
});

const isUpload = computed(() => importForm.source === 'upload');
const fileInputs = ref(0); // troca a key dos inputs de arquivo pra limpá-los

const canSubmitImport = computed(
    () =>
        importForm.modalities.length > 0 &&
        (!isUpload.value || !!importForm.active_file) &&
        !importForm.processing &&
        !importRunning.value,
);

function submitImport() {
    if (!canSubmitImport.value) return;

    importForm
        // Download da ANS: arquivos não vão (mesmo que tenham sido escolhidos antes).
        .transform((data) => (data.source === 'upload' ? data : { source: data.source, modalities: data.modalities }))
        .post(route('manager.covenants.imports.store'), {
            forceFormData: isUpload.value,
            preserveScroll: true,
            onSuccess: () => {
                importForm.reset('active_file', 'cancelled_file');
                fileInputs.value++;
            },
        });
}

function pickFile(field, event) {
    importForm[field] = event.target.files?.[0] ?? null;
}

onBeforeUnmount(() => clearTimeout(searchTimer));

function hasPlanResults(imp) {
    return (
        (imp.plans_created_count ?? 0) +
            (imp.plans_updated_count ?? 0) +
            (imp.plans_unchanged_count ?? 0) +
            (imp.plans_deactivated_count ?? 0) >
        0
    );
}

function fmtProgress(text, imp) {
    return (text ?? '')
        .replace(':processed', number(imp.processed_rows ?? 0))
        .replace(':total', number(imp.total_rows ?? 0));
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
            :total="covenants.total ?? null"
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
                    :title="t.btn_sync"
                    :aria-label="t.btn_sync"
                    @click="tab = 'imports'"
                >
                    <i class="ti ti-refresh"></i><span class="d-none d-md-inline ms-1">{{ t.btn_sync }}</span>
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

        <!-- Números do catálogo -->
        <div class="row g-2 mb-3">
            <div
                v-for="key in ['active', 'ans', 'manual', 'cancelled', 'plans']"
                :key="key"
                class="col-6 col-md-4 col-xl"
            >
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
                    <i class="ti ti-refresh me-1"></i>{{ t.tab_imports }}
                    <span v-if="importRunning" class="spinner-border spinner-border-sm ms-1" aria-hidden="true"></span>
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
                    <option value="ans">{{ t.source_ans }}</option>
                    <option value="manual">{{ t.source_manual }}</option>
                </select>
                <select v-model="status" class="form-select w-auto" :aria-label="t.filter_status">
                    <option value="">{{ t.filter_status_all }}</option>
                    <option value="active">{{ t.status_active }}</option>
                    <option value="inactive">{{ t.status_inactive }}</option>
                </select>
                <select v-model="modality" class="form-select w-auto mw-100" :aria-label="t.filter_modality">
                    <option value="">{{ t.filter_modality_all }}</option>
                    <option v-for="m in modalities" :key="m" :value="m">{{ m }}</option>
                </select>
                <select v-model="uf" class="form-select w-auto" :aria-label="t.filter_uf">
                    <option value="">{{ t.filter_uf_all }}</option>
                    <option v-for="u in ufs" :key="u" :value="u">{{ u }}</option>
                </select>
                <div class="form-check mb-0">
                    <input id="flt-cancelled" v-model="cancelled" type="checkbox" class="form-check-input" />
                    <label for="flt-cancelled" class="form-check-label">{{ t.filter_cancelled }}</label>
                </div>
            </div>

            <CovenantTable
                v-if="view === 'table'"
                :covenants="covenants"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @view="openDetail"
                @edit="openEdit"
                @delete="remove"
                @toggle-active="toggleActive"
            />
            <CovenantCards
                v-else
                :covenants="covenants"
                :t="t"
                @view="openDetail"
                @edit="openEdit"
                @delete="remove"
                @toggle-active="toggleActive"
            />
        </div>

        <!-- ════════ Sincronização ANS ════════ -->
        <div v-show="tab === 'imports'">
            <div class="card">
                <div class="card-body">
                    <h6 class="fw-semibold">{{ t.import_title }}</h6>
                    <p class="text-muted small mb-2">{{ t.import_help }}</p>
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
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <strong class="text-truncate">{{ progressImport.source_label }}</strong>
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
                                >{{ t.result_deactivated }}:
                                <strong>{{ number(progressImport.deactivated_count) }}</strong></span
                            >
                            ·
                            <span class="text-muted"
                                >{{ t.result_skipped_modality }}:
                                <strong>{{ number(progressImport.skipped_modality) }}</strong></span
                            >
                        </div>
                        <div v-if="hasPlanResults(progressImport)" class="small mt-1">
                            <strong>{{ t.result_plans }}:</strong>
                            {{ t.result_created }} {{ number(progressImport.plans_created_count) }} ·
                            {{ t.result_updated }} {{ number(progressImport.plans_updated_count) }} ·
                            {{ t.result_deactivated }} {{ number(progressImport.plans_deactivated_count) }}
                        </div>
                        <div v-if="progressImport.error" class="small text-danger mt-1">{{ progressImport.error }}</div>
                        <div v-if="progressImport.plans_error" class="small text-warning-emphasis mt-1" role="alert">
                            <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.plans_error_label }}
                            {{ progressImport.plans_error }}
                        </div>
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
                                        id="imp-mode-ans"
                                        v-model="importForm.source"
                                        type="radio"
                                        value="ans"
                                        class="form-check-input"
                                    />
                                    <label for="imp-mode-ans" class="form-check-label">{{ t.import_mode_ans }}</label>
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

                        <fieldset class="col-12">
                            <legend class="form-label fw-semibold fs-14 mb-1">{{ t.import_modalities }}</legend>
                            <div class="form-text mt-0 mb-2">{{ t.import_modalities_hint }}</div>
                            <div class="row g-1">
                                <div v-for="(m, i) in modalities" :key="m" class="col-12 col-sm-6 col-xl-3">
                                    <div class="form-check mb-0">
                                        <input
                                            :id="`imp-mod-${i}`"
                                            v-model="importForm.modalities"
                                            type="checkbox"
                                            :value="m"
                                            class="form-check-input"
                                        />
                                        <label :for="`imp-mod-${i}`" class="form-check-label">{{ m }}</label>
                                    </div>
                                </div>
                            </div>
                            <div
                                v-if="importForm.errors.modalities || importForm.errors['modalities.0']"
                                class="text-danger small mt-1"
                            >
                                {{ importForm.errors.modalities ?? importForm.errors['modalities.0'] }}
                            </div>
                        </fieldset>

                        <template v-if="isUpload">
                            <div class="col-12">
                                <div class="alert alert-light border small mb-0">
                                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.import_upload_hint }}
                                    <div class="mt-1">{{ t.plans_upload_note }}</div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label fw-semibold" for="imp-active">
                                    {{ t.import_active_file }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="imp-active"
                                    :key="`active-${fileInputs}`"
                                    type="file"
                                    class="form-control"
                                    :class="{ 'is-invalid': importForm.errors.active_file }"
                                    accept=".csv,text/csv"
                                    @input="pickFile('active_file', $event)"
                                />
                                <div class="invalid-feedback">{{ importForm.errors.active_file }}</div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label fw-semibold" for="imp-cancelled">{{
                                    t.import_cancelled_file
                                }}</label>
                                <input
                                    id="imp-cancelled"
                                    :key="`cancelled-${fileInputs}`"
                                    type="file"
                                    class="form-control"
                                    :class="{ 'is-invalid': importForm.errors.cancelled_file }"
                                    accept=".csv,text/csv"
                                    @input="pickFile('cancelled_file', $event)"
                                />
                                <div class="invalid-feedback">{{ importForm.errors.cancelled_file }}</div>
                            </div>
                        </template>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary" :disabled="!canSubmitImport">
                                <span v-if="importForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else :class="`ti ${isUpload ? 'ti-upload' : 'ti-cloud-download'} me-1`"></i>
                                {{ isUpload ? t.import_submit_upload : t.import_submit_ans }}
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
                                    <div v-if="imp.active_original_name" class="text-muted">
                                        {{ imp.active_original_name }}
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
                                            {{ t.result_unchanged }}: {{ number(imp.unchanged_count) }} ·
                                            {{ t.result_skipped_modality }}: {{ number(imp.skipped_modality) }} ·
                                            {{ t.result_skipped_invalid }}: {{ number(imp.skipped_invalid) }}
                                        </div>
                                        <div v-if="hasPlanResults(imp)" class="mt-1">
                                            <strong>{{ t.result_plans }}:</strong>
                                            {{ t.result_created }} {{ number(imp.plans_created_count) }} ·
                                            {{ t.result_updated }} {{ number(imp.plans_updated_count) }} ·
                                            {{ t.result_deactivated }} {{ number(imp.plans_deactivated_count) }} ·
                                            {{ t.result_unchanged }} {{ number(imp.plans_unchanged_count) }}
                                        </div>
                                        <div v-else-if="imp.source === 'upload'" class="text-muted mt-1">
                                            {{ t.plans_upload_note }}
                                        </div>
                                        <div v-if="imp.plans_error" class="text-warning-emphasis mt-1">
                                            <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                            >{{ t.plans_error_label }} {{ imp.plans_error }}
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

        <CovenantFormModal
            :open="formOpen"
            :covenant="editing"
            :modalities="modalities"
            :ufs="ufs"
            :t="t"
            @close="formOpen = false"
        />

        <CovenantDetailDrawer
            :open="detailOpen"
            :covenant="detail"
            :t="t"
            :tp="tPlans"
            @close="detailOpen = false"
            @edit="editFromDetail"
            @plans-changed="router.reload({ only: ['covenants', 'stats'] })"
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
