<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useForm, router, Link, usePage } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Importador CSV de pacientes:
 *  1. Upload do arquivo → backend gera preview (cabeçalho + 5 linhas) sem
 *     enfileirar o job. `preview_id` vem no flash.
 *  2. Usuário revisa preview e clica "Confirmar" → dispara o job.
 *  3. Polling do `status` endpoint (2s) até `is_done`.
 *
 * Padrão job-tries=1 + dedup CPF/(nome+tel) preservado no backend.
 */
const props = defineProps({
    breadcrumbs:    { type: Array,  default: () => [] },
    imports:        { type: Array,  default: () => [] },
    pending_import: { type: Object, default: null },
    preview_id:     { type: String, default: null },
    plan_status:    { type: Object, default: () => ({}) },
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

async function confirmImport(item) {
    if (!confirm(`Confirmar importação de "${item.original_name}"?`)) return;
    await fetch(item.urls.confirm, {
        method:  'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    router.reload({ only: ['imports', 'pending_import', 'preview_id'] });
}

async function cancelImport(item) {
    if (!confirm(`Cancelar e remover "${item.original_name}"?`)) return;
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

        // Mescla os campos atualizados ao item em memória
        pollingImport.value = { ...pollingImport.value, ...json };

        if (json.is_done) {
            // Recarrega lista completa para atualizar tudo
            router.reload({ only: ['imports', 'pending_import'] });
        }
    } catch { /* silent */ }
}

onMounted(() => {
    if (pollingImport.value && !pollingImport.value.is_done) {
        pollTimer = setInterval(pollStatus, 2000);
    }
});

onBeforeUnmount(() => {
    if (pollTimer) clearInterval(pollTimer);
});

// ── Helpers ─────────────────────────────────────────────────────────────────
const statusBadgeClass = (color) => `badge bg-${color} fs-11 fw-medium`;

const flashError = computed(() => page.props?.flash?.error ?? uploadForm.errors.file);
</script>

<template>
    <AppLayout title="Importar pacientes" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Importação em massa de pacientes" subtitle="Upload CSV — geração de preview antes da importação efetiva.">
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
                    Seu plano permite até <strong>{{ plan_status.max }}</strong> pacientes.
                    Utilizados: <strong>{{ plan_status.used }}</strong>.
                    Disponíveis: <strong>{{ plan_status.available ?? '—' }}</strong>.
                </div>
            </div>

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

                    <div v-if="previewImport.preview?.headers" class="table-responsive mb-3">
                        <table class="table table-sm table-bordered small">
                            <thead class="table-light">
                                <tr>
                                    <th v-for="h in previewImport.preview.headers" :key="h">{{ h }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, ri) in previewImport.preview.rows ?? []" :key="ri">
                                    <td v-for="(cell, ci) in row" :key="ci">{{ cell }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-outline-danger btn-sm" @click="cancelImport(previewImport)">
                            <i class="ti ti-x me-1"></i>Cancelar
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" @click="confirmImport(previewImport)">
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
                    <form @submit.prevent="submitUpload" class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label small">Arquivo (.csv, máx 20MB) <span class="text-danger">*</span></label>
                            <input
                                ref="fileInput"
                                type="file"
                                accept=".csv,text/csv"
                                class="form-control form-control-sm"
                                :class="{ 'is-invalid': uploadForm.errors.file }"
                                @change="onFileChange"
                                required
                            >
                            <div v-if="uploadForm.errors.file" class="invalid-feedback">{{ uploadForm.errors.file }}</div>
                            <small class="text-muted">
                                Separador: ponto-e-vírgula (;). Encoding UTF-8. Veja o
                                <a :href="urls.template">modelo</a>.
                            </small>
                        </div>
                        <div class="col-md-4 d-flex gap-2">
                            <button
                                type="submit"
                                class="btn btn-primary btn-sm w-100"
                                :disabled="!uploadForm.file || uploadForm.processing"
                            >
                                <span v-if="uploadForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-upload me-1"></i>
                                Enviar
                            </button>
                        </div>
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
