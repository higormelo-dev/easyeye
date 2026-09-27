<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useForm, router, Link, usePage } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Importador CSV de agendamentos (mirror de Patients/Import.vue e
 * Doctors/Import.vue):
 *  1. Upload do arquivo → backend gera preview (cabeçalho + 5 linhas) sem
 *     enfileirar o job. `preview_id` vem no flash.
 *  2. Usuário revisa preview e clica "Confirmar" → dispara o job.
 *  3. Polling do `status` endpoint (2s) até `is_done`.
 *
 * Sem bloco de limite de plano (diferente de Patients/Doctors) — não existe
 * feature key de limite de agendamentos (ver ScheduleImportService).
 */
const props = defineProps({
    breadcrumbs:    { type: Array,  default: () => [] },
    imports:        { type: Array,  default: () => [] },
    pending_import: { type: Object, default: null },
    preview_id:     { type: String, default: null },
    urls:           { type: Object, required: true },
    t:              { type: Object, default: () => ({}) },
});

const page = usePage();

// ── Upload ──────────────────────────────────────────────────────────────────
const uploadForm = useForm({ file: null });
const fileInput  = ref(null);

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
    return props.imports.find(i => i.id === props.preview_id);
});

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

// Confirmação padrão via SweetAlert2 (já usado no resto do painel — ver
// Layouts/AppLayout.vue e EyeImages/Index.vue), com fallback pro confirm()
// nativo do browser se o script não tiver carregado por algum motivo.
async function confirmDialog({ icon = 'warning', title, text, confirmButtonText, cancelButtonText = 'Voltar', confirmButtonColor }) {
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon, title, text,
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
    const ok = await confirmDialog({
        icon: 'question',
        title: 'Confirmar importação?',
        text: `Iniciar a importação de "${item.original_name}"?`,
        confirmButtonText: 'Confirmar e importar',
        cancelButtonText: 'Cancelar',
    });
    if (!ok) return;

    await fetch(item.urls.confirm, {
        method:  'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    router.reload({ only: ['imports', 'pending_import', 'preview_id'] });
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
        method:  'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
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

    stopPolling();
    await fetch(item.urls.cancel, {
        method:  'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    router.reload({ only: ['imports', 'pending_import', 'preview_id'] });
}

// ── Polling (2s) enquanto houver import processando ─────────────────────────
const pollingImport = ref(props.pending_import);
let pollTimer = null;

async function pollStatus() {
    if (!pollingImport.value || pollingImport.value.is_done) return;
    try {
        const res  = await fetch(pollingImport.value.urls.status, { headers: { Accept: 'application/json' } });
        const json = await res.json();

        pollingImport.value = { ...pollingImport.value, ...json };

        if (json.is_done) {
            router.reload({ only: ['imports', 'pending_import'] });
        }
    } catch { /* silent */ }
}

function startPolling() {
    if (pollTimer || !pollingImport.value || pollingImport.value.is_done) return;
    pollTimer = setInterval(pollStatus, 2000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

// `pollingImport` era um snapshot único de props.pending_import — depois de
// enviar/confirmar/cancelar um import, o Inertia atualiza a prop (router.reload
// parcial), mas o componente não remonta, então esse ref nunca refletia a
// mudança sem F5 manual. Este watch mantém os dois sincronizados sempre.
watch(() => props.pending_import, (value) => {
    pollingImport.value = value;
    value && !value.is_done ? startPolling() : stopPolling();
});

onMounted(startPolling);
onBeforeUnmount(stopPolling);

// ── Helpers ─────────────────────────────────────────────────────────────────
const statusBadgeClass = (color) => `badge bg-${color} fs-11 fw-medium`;

const flashError = computed(() => page.props?.flash?.error ?? uploadForm.errors.file);
</script>

<template>
    <AppLayout title="Importar agendamentos" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Importação em massa de agendamentos" subtitle="Upload CSV — geração de preview antes da importação efetiva.">
                <template #actions>
                    <a :href="urls.template" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-download me-1"></i>Modelo CSV
                    </a>
                    <Link :href="urls.schedules" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-arrow-left me-1"></i>Agenda
                    </Link>
                </template>
            </PageHeader>

            <!-- Flash de erro -->
            <div v-if="flashError" class="alert alert-danger alert-dismissible fade show mb-3">
                <i class="ti ti-alert-triangle me-1"></i>{{ flashError }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <!-- Polling do import em processamento -->
            <div v-if="pollingImport && !pollingImport.is_done" class="alert alert-info">
                <div class="d-flex align-items-center mb-2">
                    <span class="spinner-border spinner-border-sm me-2"></span>
                    <strong>Importação em andamento — {{ pollingImport.original_name }}</strong>
                    <span :class="`badge bg-${pollingImport.status_color} ms-2`">{{ pollingImport.status_label }}</span>
                    <button
                        type="button"
                        class="btn btn-outline-danger btn-sm ms-auto"
                        @click="cancelProcessingImport(pollingImport)"
                    >
                        <i class="ti ti-ban me-1"></i>Cancelar importação
                    </button>
                </div>

                <div class="progress mb-2" style="height: 8px;">
                    <div
                        class="progress-bar bg-info progress-bar-striped progress-bar-animated"
                        :style="`width: ${pollingImport.progress}%`"
                    ></div>
                </div>

                <div class="small text-muted">
                    {{ pollingImport.processed_rows }} de {{ pollingImport.total_rows }} processados —
                    <strong class="text-success">{{ pollingImport.imported_rows }}</strong> importados,
                    <strong class="text-warning">{{ pollingImport.skipped_rows }}</strong> ignorados,
                    <strong class="text-danger">{{ pollingImport.error_rows }}</strong> erros
                </div>
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
                        Revise as primeiras linhas do arquivo. Confirme para iniciar a importação ou cancele para descartar.
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
                                    <td v-for="col in previewImport.preview.mapped_columns" :key="col.field">{{ row[col.label] ?? '' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="previewImport.preview?.unmapped_columns?.length" class="alert alert-secondary small py-2 mb-3">
                        <i class="ti ti-info-circle me-1"></i>
                        Colunas do arquivo não reconhecidas (serão ignoradas): <strong>{{ previewImport.preview.unmapped_columns.join(', ') }}</strong>
                    </div>

                    <div v-if="previewImport.preview?.missing_required?.length" class="alert alert-danger small py-2 mb-3">
                        <i class="ti ti-alert-triangle me-1"></i>
                        Colunas obrigatórias ausentes: <strong>{{ previewImport.preview.missing_required.join(', ') }}</strong>
                        <div class="mt-1 text-muted">
                            Confira se a <strong>primeira linha</strong> do arquivo tem os nomes das colunas (ex.: "nome_paciente", "data_hora") e não já os dados de um agendamento. Baixe o
                            <a :href="urls.template">Modelo CSV</a> pra comparar o formato esperado.
                        </div>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-outline-danger btn-sm" @click="cancelImport(previewImport)">
                            <i class="ti ti-x me-1"></i>Cancelar
                        </button>
                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            :disabled="previewImport.preview?.can_proceed === false"
                            @click="confirmImport(previewImport)"
                        >
                            <i class="ti ti-check me-1"></i>Confirmar e importar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Upload novo -->
            <div v-if="!pollingImport || pollingImport.is_done" class="card mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">
                        <i class="ti ti-upload me-1"></i>Novo arquivo CSV
                    </h6>
                    <form @submit.prevent="submitUpload">
                        <label class="form-label small">Arquivo (.csv, máx 20MB) <span class="text-danger">*</span></label>
                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <input
                                ref="fileInput"
                                type="file"
                                accept=".csv,text/csv"
                                class="form-control form-control-sm"
                                :class="{ 'is-invalid': uploadForm.errors.file }"
                                @change="onFileChange"
                                required
                            >
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
                        <div v-if="uploadForm.errors.file" class="invalid-feedback d-block">{{ uploadForm.errors.file }}</div>
                        <small class="text-muted d-block mt-1">
                            Separador: ponto-e-vírgula (;). Encoding UTF-8. Colunas: codigo_importacao_medico ou
                            crm_medico, nome_paciente, data_hora (obrigatórias) — codigo_importacao_paciente,
                            cpf_paciente, situacao, tipo_atendimento, especialidade, convenio, telefone, celular,
                            observacoes, codigo_importacao (opcionais). Veja o
                            <a :href="urls.template">modelo</a>.
                        </small>
                    </form>
                </div>
            </div>

            <!-- Histórico -->
            <div class="card">
                <div class="card-header bg-transparent">
                    <h6 class="mb-0 fw-semibold">
                        <i class="ti ti-history me-1"></i>Histórico de importações
                    </h6>
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
                            <tr v-for="item in imports" :key="item.id">
                                <td class="small text-muted">{{ item.created_at }}</td>
                                <td>{{ item.original_name }}</td>
                                <td class="text-muted small">{{ item.user_name }}</td>
                                <td class="text-center">
                                    <span :class="statusBadgeClass(item.status_color)">{{ item.status_label }}</span>
                                </td>
                                <td class="text-end">{{ item.total_rows }}</td>
                                <td class="text-end text-success">{{ item.imported_rows }}</td>
                                <td class="text-end text-danger">{{ item.error_rows }}</td>
                                <td class="text-end">
                                    <a
                                        v-if="item.has_errors_file && item.urls.errors"
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
