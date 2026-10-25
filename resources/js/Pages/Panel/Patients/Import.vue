<script setup>
import { useImportProgress } from '@/composables/useImportProgress';
import { ref, computed, nextTick, onBeforeUnmount, watch } from 'vue';
import { useForm, router, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import PatientImportCovenantMapping from '@/Components/Panel/PatientImportCovenantMapping.vue';
import { latestRequest, isAbortError } from '@/Support/latestRequest.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Importador CSV de pacientes:
 *  1. Upload do arquivo → backend gera preview (cabeçalho + 5 linhas) sem
 *     enfileirar o job. `preview_id` vem no flash.
 *  2. Usuário revisa preview e clica "Confirmar" → dispara o job.
 *  3. WebSocket/Reverb acompanha o progresso até `is_done`.
 *
 * Padrão job-tries=1 + dedup CPF/(nome+tel) preservado no backend.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    imports: { type: Array, default: () => [] },
    pending_import: { type: Object, default: null },
    preview_id: { type: String, default: null },
    plan_status: { type: Object, default: () => ({}) },
    can_create_covenant: { type: Boolean, default: false },
    urls: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
});

const page = usePage();
const { date } = useLocaleFormat();
const pageError = ref('');
const mappingNotice = ref('');
const confirming = ref(false);
const confirmPosting = ref(false);
const mappingRef = ref(null);
const previewReport = ref(null);
const resolution = ref(null);
const resolutionSource = ref(null);
const resolutionRef = ref(null);
const resolvingId = ref(null);
const reportRequest = latestRequest();
let resolutionTrigger = null;
const mappingText = computed(() => props.t.covenant_mapping ?? {});
const tr = (key, values = {}) =>
    Object.entries(values).reduce(
        (text, [name, value]) => text.replaceAll(`:${name}`, String(value)),
        mappingText.value[key] ?? key,
    );
const mappingUrls = (item) => ({
    show: item.urls.covenants,
    update: item.urls.covenants,
    reprocess: item.urls.covenants_reprocess,
    covenantSearch: props.urls.covenant_search,
    covenantPlanSearch: props.urls.covenant_plan_search,
});
const previewSummary = computed(() => previewImport.value?.preview?.covenant_summary);
const previewItems = computed(() => previewReport.value?.items ?? previewSummary.value?.items ?? []);
const previewTotals = computed(
    () =>
        previewReport.value?.totals ?? {
            ...previewSummary.value?.totals,
            failed_rows: previewItems.value.reduce((sum, item) => sum + (item.failed_rows ?? 0), 0),
            failed_rows_exact: false,
        },
);
watch(
    () => props.preview_id,
    () => {
        previewReport.value = null;
    },
);
onBeforeUnmount(() => reportRequest.cancel());

function updatePreviewReport(report) {
    previewReport.value = report;
}
function updateResolution(report) {
    resolution.value = report;
}
function responseMessage(json) {
    return [].concat(json.errors?.import ?? json.errors?.reprocess ?? json.message ?? tr('save_failed'))[0];
}
async function openResolution(item, event) {
    const request = reportRequest.begin();
    resolutionTrigger = event?.currentTarget ?? null;
    resolvingId.value = item.id;
    resolution.value = null;
    resolutionSource.value = null;
    pageError.value = '';
    try {
        if (item.id === props.preview_id && mappingRef.value?.hasChanges) {
            if (!(await mappingRef.value.save()) || !request.current()) return;
        }
        const response = await fetch(item.urls.covenants, {
            headers: { Accept: 'application/json' },
            signal: request.signal,
        });
        const json = await response.json();
        if (!request.current()) return;
        if (!response.ok) {
            pageError.value = responseMessage(json);
            return;
        }
        if (item.id === props.preview_id) {
            previewReport.value = json.data;
            await nextTick();
            mappingRef.value?.$el?.querySelector('input[type="search"]')?.focus();
            return;
        }
        resolutionSource.value = item;
        resolution.value = json.data;
        await nextTick();
        resolutionRef.value?.focus();
    } catch (error) {
        if (request.current() && !isAbortError(error)) pageError.value = tr('save_failed');
    } finally {
        if (request.current()) resolvingId.value = null;
    }
}
async function closeResolution() {
    reportRequest.cancel();
    resolution.value = null;
    resolutionSource.value = null;
    resolvingId.value = null;
    await nextTick();
    resolutionTrigger?.focus();
}
function reprocessed() {
    mappingNotice.value = tr('reprocess_started');
    resolution.value = null;
    resolutionSource.value = null;
    router.reload({ only: ['imports', 'pending_import', 'preview_id'] });
}

// ── Upload ──────────────────────────────────────────────────────────────────
const uploadForm = useForm({ file: null });
const fileInput = ref(null);

function onFileChange(e) {
    uploadForm.file = e.target.files[0];
}

function submitUpload() {
    if (!uploadForm.file) return;
    uploadForm.post(props.urls.store, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

// ── Preview / confirm ───────────────────────────────────────────────────────
const previewImport = computed(() => {
    if (!props.preview_id) return null;
    return props.imports.find((i) => i.id === props.preview_id);
});

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

// Confirmação padrão via SweetAlert2 (já usado no resto do painel — ver
// Layouts/AppLayout.vue e EyeImages/Index.vue), com fallback pro confirm()
// nativo do browser se o script não tiver carregado por algum motivo.
async function confirmDialog({
    icon = 'warning',
    title,
    text,
    confirmButtonText,
    cancelButtonText = 'Voltar',
    confirmButtonColor,
}) {
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon,
            title,
            text,
            showCancelButton: true,
            confirmButtonText,
            cancelButtonText,
            confirmButtonColor,
        });

        return result.isConfirmed;
    }

    return window.confirm(text ?? title);
}

async function confirmImport(item) {
    if (confirming.value) return;
    confirming.value = true;
    pageError.value = '';
    try {
        if (mappingRef.value?.hasChanges && !(await mappingRef.value.save())) return;
        const unresolved = previewTotals.value.unmatched_names ?? mappingRef.value?.unresolvedCount ?? 0;
        const text = [
            tr('preview_confirm_text', { file: item.original_name }),
            unresolved > 0 ? tr('unmatched_confirm', { count: unresolved }) : '',
        ]
            .filter(Boolean)
            .join(' ');
        const ok = await confirmDialog({
            icon: 'question',
            title: tr('preview_confirm_title'),
            text,
            confirmButtonText: tr('preview_confirm_button'),
            cancelButtonText: tr('cancel'),
        });
        if (!ok) return;
        confirmPosting.value = true;
        const response = await fetch(item.urls.confirm, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (!response.ok) {
            const json = await response.json();
            pageError.value = responseMessage(json);
            return;
        }
        router.reload({ only: ['imports', 'pending_import', 'preview_id'] });
    } catch {
        pageError.value = tr('save_failed');
    } finally {
        confirming.value = false;
        confirmPosting.value = false;
    }
}

async function cancelImport(item) {
    const ok = await confirmDialog({
        title: 'Cancelar e remover importação?',
        text: `O arquivo "${item.original_name}" será descartado.`,
        confirmButtonText: 'Cancelar e remover',
        confirmButtonColor: '#dc3545',
    });
    if (!ok) return;

    await fetch(item.urls.cancel, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    router.reload({ only: ['imports', 'pending_import', 'preview_id'] });
}

// Cancela um import já confirmado (na fila ou processando). O backend não
// apaga o registro nesse caso — só sinaliza; o job em execução para sozinho
// no próximo checkpoint, preservando as linhas já importadas.
async function cancelProcessingImport(item) {
    const ok = await confirmDialog({
        title: 'Cancelar esta importação?',
        text: 'As linhas já processadas não serão desfeitas, mas o restante do arquivo não será importado.',
        confirmButtonText: 'Cancelar importação',
        confirmButtonColor: '#dc3545',
    });
    if (!ok) return;

    await fetch(item.urls.cancel, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    router.reload({ only: ['imports', 'pending_import', 'preview_id'] });
}

// ── Progresso em tempo real (WebSocket/Reverb — sem polling HTTP) ────────────
// Eventos import.progress do canal privado da importação (ver
// composables/useImportProgress.js). Ao assinar/reconectar, relê as props uma
// vez pra não perder o que aconteceu antes da conexão.
const liveImport = ref(props.pending_import);
const { realtimeConnected } = useImportProgress(liveImport, {
    onDone: () => router.reload({ only: ['imports', 'pending_import'] }),
    onResync: () => router.reload({ only: ['pending_import'] }),
});

// Mantém o estado vivo em sincronia com a prop (enviar/confirmar/cancelar
// atualizam pending_import via reload parcial — o componente não remonta).
watch(
    () => props.pending_import,
    (value) => {
        liveImport.value = value;
    },
);

// ── Helpers ─────────────────────────────────────────────────────────────────
const statusBadgeClass = (color) => `badge bg-${color} fs-11 fw-medium`;

const flashError = computed(
    () => pageError.value || page.props?.flash?.error || page.props?.errors?.import || uploadForm.errors.file,
);
</script>

<template>
    <AppLayout title="Importar pacientes" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader
                title="Importação em massa de pacientes"
                subtitle="Upload CSV — geração de preview antes da importação efetiva."
            >
                <template #actions>
                    <a :href="urls.template" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-download me-1"></i>Modelo CSV
                    </a>
                    <Link :href="urls.patients" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-arrow-left me-1"></i>Pacientes
                    </Link>
                </template>
            </PageHeader>

            <!-- Limite do plano -->
            <div v-if="plan_status?.max" class="alert alert-info small d-flex align-items-start mb-3">
                <i class="ti ti-info-circle me-2 fs-5 mt-1"></i>
                <div>
                    Seu plano permite até <strong>{{ plan_status.max }}</strong> pacientes. Utilizados:
                    <strong>{{ plan_status.used }}</strong
                    >. Disponíveis: <strong>{{ plan_status.available ?? '—' }}</strong
                    >.
                </div>
            </div>

            <!-- Flash de erro -->
            <div v-if="flashError" class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <i class="ti ti-alert-triangle me-1"></i>{{ flashError }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <p v-if="mappingNotice" class="alert alert-success" role="status">{{ mappingNotice }}</p>

            <!-- Progresso do import por WebSocket/Reverb -->
            <div v-if="liveImport && !liveImport.is_done" class="alert alert-info">
                <div class="d-flex align-items-center mb-2">
                    <span class="spinner-border spinner-border-sm me-2"></span>
                    <strong>Importação em andamento — {{ liveImport.original_name }}</strong>
                    <span :class="`badge bg-${liveImport.status_color} ms-2`">{{ liveImport.status_label }}</span>
                    <button
                        type="button"
                        class="btn btn-outline-danger btn-sm ms-auto"
                        @click="cancelProcessingImport(liveImport)"
                    >
                        <i class="ti ti-ban me-1"></i>Cancelar importação
                    </button>
                </div>

                <div class="progress mb-2" style="height: 8px">
                    <div
                        class="progress-bar bg-info progress-bar-striped progress-bar-animated"
                        :style="`width: ${liveImport.progress}%`"
                    ></div>
                </div>

                <div class="small text-muted">
                    {{ liveImport.processed_rows }} de {{ liveImport.total_rows }} processados —
                    <strong class="text-success">{{ liveImport.imported_rows }}</strong> importados,
                    <strong class="text-warning">{{ liveImport.skipped_rows }}</strong> ignorados,
                    <strong class="text-danger">{{ liveImport.error_rows }}</strong> erros
                    <template v-if="liveImport.warning_rows">
                        · <strong class="text-warning">{{ liveImport.warning_rows }}</strong> {{ t.col_warnings }}
                    </template>
                </div>
                <small v-if="!realtimeConnected" class="d-block text-muted mt-1">
                    <i class="ti ti-plug-connected-x me-1"></i>{{ page.props.t_ui?.realtime_offline }}
                </small>
            </div>

            <!-- Preview pendente (antes de confirmar) -->
            <div v-if="previewImport" class="card mb-3 border-warning">
                <div class="card-header bg-warning-subtle">
                    <h6 class="mb-0 fw-semibold">
                        <i class="ti ti-eye me-1"></i>
                        Pré-visualização — {{ previewImport.original_name }}
                    </h6>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Revise as primeiras linhas do arquivo. Confirme para iniciar a importação ou cancele para
                        descartar.
                    </p>

                    <div v-if="previewImport.preview?.mapped_columns?.length" class="table-responsive mb-3">
                        <table class="table table-sm table-bordered small">
                            <thead class="table-light">
                                <tr>
                                    <th v-for="col in previewImport.preview.mapped_columns" :key="col.field">
                                        {{ col.label }}
                                        <span v-if="col.required" class="text-danger">*</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, ri) in previewImport.preview.sample_rows ?? []" :key="ri">
                                    <td v-for="col in previewImport.preview.mapped_columns" :key="col.field">
                                        {{ row[col.label] ?? '' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="previewImport.preview?.unmapped_columns?.length"
                        class="alert alert-secondary small py-2 mb-3"
                    >
                        <i class="ti ti-info-circle me-1"></i>
                        Colunas do arquivo não reconhecidas (serão ignoradas):
                        <strong>{{ previewImport.preview.unmapped_columns.join(', ') }}</strong>
                    </div>

                    <div
                        v-if="previewImport.preview?.missing_required?.length"
                        class="alert alert-danger small py-2 mb-3"
                    >
                        <i class="ti ti-alert-triangle me-1"></i>
                        Colunas obrigatórias ausentes:
                        <strong>{{ previewImport.preview.missing_required.join(', ') }}</strong>
                        <div class="mt-1 text-muted">
                            Confira se a <strong>primeira linha</strong> do arquivo tem os nomes das colunas (ex.:
                            "nome", "celular") e não já os dados de um paciente. Baixe o
                            <a :href="urls.template">Modelo CSV</a> pra comparar o formato esperado.
                        </div>
                    </div>

                    <p v-if="previewImport.preview?.covenant_warning" class="alert alert-warning">
                        {{ tr('catalog_unavailable') }}
                    </p>
                    <div v-if="previewSummary?.truncated && !previewReport" class="alert alert-info">
                        {{ tr('truncated') }}
                        <button
                            type="button"
                            class="btn btn-link btn-sm"
                            data-test="full-covenant-report"
                            @click="openResolution(previewImport, $event)"
                        >
                            {{ tr('resolve') }}
                        </button>
                    </div>
                    <PatientImportCovenantMapping
                        v-if="previewSummary && previewImport.urls.covenants"
                        :key="previewImport.id"
                        ref="mappingRef"
                        :import-id="previewImport.id"
                        :items="previewItems"
                        :names-capped="previewReport?.names_capped ?? previewSummary?.names_capped ?? false"
                        :totals="previewTotals"
                        :permissions="previewReport?.permissions ?? { can_create_covenant, can_reprocess: false }"
                        :urls="mappingUrls(previewImport)"
                        mode="preview"
                        :t="mappingText"
                        :busy="confirmPosting"
                        :files-expired="previewReport?.import.files_expired ?? previewImport.files_expired"
                        @saved="updatePreviewReport"
                    />

                    <div class="d-flex gap-2 justify-content-end">
                        <button
                            type="button"
                            class="btn btn-outline-danger btn-sm"
                            :disabled="confirming"
                            @click="cancelImport(previewImport)"
                        >
                            <i class="ti ti-x me-1"></i>Cancelar
                        </button>
                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            data-test="confirm-import"
                            :disabled="
                                confirming ||
                                previewImport.files_expired ||
                                previewImport.preview?.can_proceed === false
                            "
                            @click="confirmImport(previewImport)"
                        >
                            <i class="ti ti-check me-1"></i>Confirmar e importar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Upload novo -->
            <div v-if="!liveImport || liveImport.is_done" class="card mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="ti ti-upload me-1"></i>Novo arquivo CSV</h6>
                    <form @submit.prevent="submitUpload">
                        <label class="form-label small"
                            >Arquivo (.csv, máx 20MB) <span class="text-danger">*</span></label
                        >
                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <input
                                ref="fileInput"
                                type="file"
                                accept=".csv,text/csv"
                                class="form-control form-control-sm"
                                :class="{ 'is-invalid': uploadForm.errors.file }"
                                required
                                @change="onFileChange"
                            />
                            <button
                                type="submit"
                                class="btn btn-primary btn-sm text-nowrap"
                                :disabled="!uploadForm.file || uploadForm.processing"
                            >
                                <span v-if="uploadForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-upload me-1"></i>
                                Enviar
                            </button>
                        </div>
                        <div v-if="uploadForm.errors.file" class="invalid-feedback d-block">
                            {{ uploadForm.errors.file }}
                        </div>
                        <small class="text-muted d-block mt-1">
                            Separador: ponto-e-vírgula (;). Encoding UTF-8. Convênio vazio entra como Particular;
                            convênio com nome não cadastrado na clínica vira erro na linha. Veja o
                            <a :href="urls.template">modelo</a>.
                        </small>
                        <small class="text-muted d-block mt-1" data-testid="import-allergies-hint">
                            <i class="ti ti-alert-triangle me-1 text-danger" aria-hidden="true"></i
                            >{{ t.allergies_hint }}
                        </small>
                    </form>
                </div>
            </div>

            <div
                v-if="resolution && resolutionSource"
                ref="resolutionRef"
                tabindex="-1"
                data-test="covenant-resolution"
                class="mb-3"
            >
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h2 class="h5 mb-0">{{ resolution.import.original_name }}</h2>
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        data-test="close-covenant-resolution"
                        @click="closeResolution"
                    >
                        {{ tr('close') }}
                    </button>
                </div>
                <PatientImportCovenantMapping
                    :key="resolution.import.id"
                    :import-id="resolution.import.id"
                    :items="resolution.items"
                    :names-capped="resolution.names_capped ?? false"
                    :totals="resolution.totals"
                    :permissions="resolution.permissions"
                    :urls="mappingUrls(resolutionSource)"
                    :mode="['done', 'failed'].includes(resolution.import.status) ? 'reprocess' : 'preview'"
                    :t="mappingText"
                    :files-expired="resolution.import.files_expired"
                    :report-available="resolution.import.report_available"
                    @saved="updateResolution"
                    @reprocessed="reprocessed"
                />
            </div>

            <!-- Histórico -->
            <div class="card">
                <div class="card-header bg-transparent">
                    <h6 class="mb-0 fw-semibold"><i class="ti ti-history me-1"></i>Histórico de importações</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Arquivo</th>
                                <th>Usuário</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Linhas</th>
                                <th class="text-end">Importadas</th>
                                <th class="text-end">Erros</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="imports.length === 0">
                                <td colspan="8" class="text-center py-4 text-muted">Sem importações ainda.</td>
                            </tr>
                            <tr v-for="item in imports" :id="`patient-import-${item.id}`" :key="item.id">
                                <td class="small text-muted">{{ item.created_at }}</td>
                                <td>
                                    {{ item.original_name }}
                                    <div v-if="item.is_reprocess" class="small">
                                        <span class="badge bg-info-subtle text-info-emphasis">{{
                                            tr('reprocess_badge')
                                        }}</span>
                                        <a
                                            v-if="imports.some((parent) => parent.id === item.reprocess_of_id)"
                                            :href="`#patient-import-${item.reprocess_of_id}`"
                                            class="d-block"
                                            >{{ tr('reprocess_of', { id: item.reprocess_of_id }) }}</a
                                        >
                                        <span v-else-if="item.reprocess_of_id" class="d-block">{{
                                            tr('reprocess_of', { id: item.reprocess_of_id })
                                        }}</span>
                                    </div>
                                    <small v-if="item.files_expired" class="d-block text-muted">{{
                                        tr('expired_label')
                                    }}</small>
                                    <small v-else-if="item.files_expire_at" class="d-block text-muted">{{
                                        tr('available_until', { date: date(item.files_expire_at) })
                                    }}</small>
                                </td>
                                <td class="text-muted small">{{ item.user_name }}</td>
                                <td class="text-center">
                                    <span :class="statusBadgeClass(item.status_color)">{{ item.status_label }}</span>
                                </td>
                                <td class="text-end">{{ item.total_rows }}</td>
                                <td class="text-end text-success">{{ item.imported_rows }}</td>
                                <td class="text-end text-danger">
                                    {{ item.error_rows }}
                                    <div v-if="item.warning_rows" class="small text-warning">
                                        {{ item.warning_rows }} {{ t.col_warnings }}
                                    </div>
                                </td>
                                <td class="text-end">
                                    <button
                                        v-if="(item.has_covenant_errors || item.error_rows > 0) && item.urls.covenants"
                                        type="button"
                                        class="btn btn-sm btn-outline-primary me-2 mb-1"
                                        data-test="resolve-covenants"
                                        :aria-busy="resolvingId === item.id"
                                        @click="openResolution(item, $event)"
                                    >
                                        {{ tr('resolve') }}
                                    </button>
                                    <a
                                        v-if="item.has_errors_file && item.urls.errors && !item.files_expired"
                                        :href="item.urls.errors"
                                        class="btn btn-sm btn-outline-secondary"
                                        title="Baixar erros"
                                    >
                                        <i class="ti ti-download"></i>
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
