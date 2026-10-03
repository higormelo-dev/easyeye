<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import MedicineFormModal from './MedicineFormModal.vue';
import { useImportProgress } from '@/composables/useImportProgress';

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
    t: { type: Object, default: () => ({}) },
});

const { number } = useLocaleFormat();
const page = usePage();

const tab = ref(props.runningImport ? 'imports' : 'catalog');

// ── Filtros (server-side) ────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
const source = ref(props.filters.source ?? '');
const status = ref(props.filters.status ?? '');
const ophthalmic = ref(!!props.filters.ophthalmic);

function applyFilters() {
    router.get(
        route('manager.medicines.index'),
        {
            search: search.value || undefined,
            source: source.value || undefined,
            status: status.value || undefined,
            ophthalmic: ophthalmic.value ? 1 : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['medicines', 'filters'] },
    );
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
});
watch([source, status, ophthalmic], applyFilters);

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

async function remove(medicine) {
    const message = (props.t.confirm_delete_text ?? '').replace(':name', medicine.name);
    const confirmed = window.Swal
        ? (
              await window.Swal.fire({
                  icon: 'warning',
                  title: props.t.confirm_delete_title,
                  text: message,
                  showCancelButton: true,
                  confirmButtonText: props.t.delete,
                  cancelButtonText: props.t.cancel,
              })
          ).isConfirmed
        : window.confirm(message);
    if (confirmed) router.delete(route('manager.medicines.destroy', medicine.id), { preserveScroll: true });
}

// ── Importação CMED/Anvisa ───────────────────────────────────────────────
const importForm = useForm({ cmed_file: null, open_data_file: null });

function submitImport() {
    importForm.post(route('manager.medicines.imports.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset();
            tab.value = 'imports';
        },
    });
}

// Barra de progresso em tempo real (WebSocket/Reverb — sem polling HTTP),
// mesmo padrão das importações de pacientes/médicos/agenda. Ao terminar,
// mantém o cartão com o resultado e recarrega histórico, números e catálogo.
const progressImport = ref(props.runningImport);
const importRunning = computed(() => !!progressImport.value && !progressImport.value.is_done);

const { realtimeConnected } = useImportProgress(progressImport, {
    onDone: () => router.reload({ only: ['imports', 'stats', 'medicines'] }),
    // Assinou/reconectou: relê o estado uma vez (eventos anteriores à conexão).
    onResync: () => router.reload({ only: ['runningImport', 'imports', 'stats'] }),
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

onBeforeUnmount(() => clearTimeout(searchTimer));

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
        <PageHeader :title="t.page_title" :subtitle="t.page_subtitle">
            <template #actions>
                <button type="button" class="btn btn-outline-primary fs-13" @click="tab = 'imports'">
                    <i class="ti ti-file-import me-1"></i>{{ t.btn_import }}
                </button>
                <button type="button" class="btn btn-primary fs-13" @click="openCreate">
                    <i class="ti ti-plus me-1"></i>{{ t.btn_new }}
                </button>
            </template>
        </PageHeader>

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
                <select v-model="status" class="form-select w-auto" :aria-label="t.filter_status">
                    <option value="">{{ t.filter_status_all }}</option>
                    <option value="active">{{ t.status_active }}</option>
                    <option value="inactive">{{ t.status_inactive }}</option>
                </select>
                <div class="form-check mb-0">
                    <input id="flt-oft" v-model="ophthalmic" type="checkbox" class="form-check-input" />
                    <label for="flt-oft" class="form-check-label">{{ t.filter_ophthalmic }}</label>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ t.col_medicine }}</th>
                                <th class="d-none d-lg-table-cell">{{ t.col_presentation }}</th>
                                <th class="d-none d-md-table-cell">{{ t.col_laboratory }}</th>
                                <th>{{ t.col_source }}</th>
                                <th>{{ t.col_status }}</th>
                                <th class="text-end">{{ t.col_actions }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!medicines.data.length">
                                <td colspan="6" class="text-center text-muted py-4">{{ t.empty }}</td>
                            </tr>
                            <tr v-for="m in medicines.data" :key="m.id">
                                <td>
                                    <div class="fw-semibold">
                                        {{ m.name }}
                                        <span v-if="m.concentration" class="fw-normal">{{ m.concentration }}</span>
                                        <span
                                            v-if="m.is_ophthalmic"
                                            class="badge bg-info-subtle text-info ms-1"
                                            :title="t.ophthalmic"
                                            ><i class="ti ti-eye"></i
                                        ></span>
                                    </div>
                                    <div v-if="m.active_ingredient" class="small text-muted">
                                        {{ m.active_ingredient }}
                                    </div>
                                    <div v-if="m.dosage || m.frequency" class="small text-primary">
                                        <i class="ti ti-clipboard-text me-1"></i>
                                        {{ [m.dosage, m.frequency, m.duration].filter(Boolean).join(' · ') }}
                                    </div>
                                </td>
                                <td class="d-none d-lg-table-cell small">
                                    <div>{{ m.form ?? '—' }}</div>
                                    <div v-if="m.presentation_detail" class="text-muted">
                                        {{ m.presentation_detail }}
                                    </div>
                                </td>
                                <td class="d-none d-md-table-cell small">
                                    <div>{{ m.laboratory ?? '—' }}</div>
                                    <div v-if="m.category" class="text-muted">{{ m.category }}</div>
                                </td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="
                                            m.source === 'cmed'
                                                ? 'bg-secondary-subtle text-secondary'
                                                : 'bg-primary-subtle text-primary'
                                        "
                                        >{{ m.source_label }}</span
                                    >
                                </td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="m.active ? 'bg-success' : 'bg-light text-muted border'"
                                        >{{ m.active ? t.status_active : t.status_inactive }}</span
                                    >
                                    <div v-if="m.source === 'cmed' && !m.is_marketed" class="small text-muted">
                                        {{ t.not_marketed }}
                                    </div>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        :title="m.source === 'cmed' ? t.edit_posology : t.edit"
                                        :aria-label="m.source === 'cmed' ? t.edit_posology : t.edit"
                                        @click="openEdit(m)"
                                    >
                                        <i class="ti ti-pencil"></i>
                                    </button>
                                    <template v-if="m.source === 'manual'">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary ms-1"
                                            :title="m.active ? t.deactivate : t.activate"
                                            :aria-label="m.active ? t.deactivate : t.activate"
                                            @click="toggleActive(m)"
                                        >
                                            <i :class="m.active ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger ms-1"
                                            :title="t.delete"
                                            :aria-label="t.delete"
                                            @click="remove(m)"
                                        >
                                            <i class="ti ti-trash"></i>
                                        </button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-transparent">
                    <TablePagination
                        :data="medicines"
                        :showing-from="t.showing_from"
                        :showing-of="t.showing_of"
                        :showing-suffix="t.showing_suffix"
                        :previous-label="t.previous"
                        :next-label="t.next"
                    />
                </div>
            </div>
        </div>

        <!-- ════════ Importações ════════ -->
        <div v-show="tab === 'imports'">
            <div class="card">
                <div class="card-body">
                    <h6 class="fw-semibold">{{ t.import_title }}</h6>
                    <p class="text-muted small mb-3">{{ t.import_help }}</p>

                    <!-- Progresso da importação (consulta a cada 2 s) -->
                    <div
                        v-if="progressImport"
                        class="alert mb-3"
                        :class="
                            importRunning
                                ? 'alert-info'
                                : progressImport.status === 'done'
                                  ? 'alert-success'
                                  : 'alert-danger'
                        "
                        role="status"
                        aria-live="polite"
                    >
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <strong class="text-truncate">{{ progressImport.cmed_original_name }}</strong>
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
                        <div class="small">
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
                        <div v-if="progressImport.error" class="small text-danger mt-1">{{ progressImport.error }}</div>
                        <small v-if="importRunning && !realtimeConnected" class="d-block text-muted mt-1">
                            <i class="ti ti-plug-connected-x me-1"></i>{{ page.props.t_ui?.realtime_offline }}
                        </small>
                    </div>

                    <form class="row g-3" @submit.prevent="submitImport">
                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-semibold" for="imp-cmed">
                                {{ t.import_cmed_file }} <span class="text-danger">*</span>
                            </label>
                            <input
                                id="imp-cmed"
                                type="file"
                                class="form-control"
                                :class="{ 'is-invalid': importForm.errors.cmed_file }"
                                accept=".xlsx,.xls,.csv"
                                @input="importForm.cmed_file = $event.target.files[0] ?? null"
                            />
                            <div class="form-text">{{ t.import_cmed_hint }}</div>
                            <div class="invalid-feedback">{{ importForm.errors.cmed_file }}</div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-semibold" for="imp-open">{{ t.import_open_data_file }}</label>
                            <input
                                id="imp-open"
                                type="file"
                                class="form-control"
                                :class="{ 'is-invalid': importForm.errors.open_data_file }"
                                accept=".csv"
                                @input="importForm.open_data_file = $event.target.files[0] ?? null"
                            />
                            <div class="form-text">{{ t.import_open_data_hint }}</div>
                            <div class="invalid-feedback">{{ importForm.errors.open_data_file }}</div>
                        </div>
                        <div class="col-12">
                            <button
                                type="submit"
                                class="btn btn-primary"
                                :disabled="!importForm.cmed_file || importForm.processing || importRunning"
                            >
                                <span v-if="importForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ti ti-upload me-1"></i>{{ t.import_submit }}
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
                                <th class="d-none d-md-table-cell">{{ t.col_files }}</th>
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
                                    <div>{{ imp.cmed_original_name }}</div>
                                    <div v-if="imp.open_data_original_name" class="text-muted">
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
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <MedicineFormModal
            :open="formOpen"
            :medicine="editing"
            :presentations="presentations"
            :t="t"
            @close="formOpen = false"
        />
    </AppLayout>
</template>
