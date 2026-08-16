<script setup>
/**
 * ImportExternalExamModal — "Importar exame externo" (Gerenciador de Imagens).
 *
 * Upload manual de N arquivos de exame para um paciente, sem passar pelo
 * integrador/equipamento — ver ExternalExamImportController::store() /
 * ImportExternalExamRequest / ExternalExamImportService (cria um PatientExam
 * por arquivo, agrupados por import_batch_id).
 *
 * Diagnóstico é opcional e embutido direto no form via Cid10Picker (mesmo
 * componente/endereços de DiagnosisManagerModal.vue), sem abrir outro modal
 * aninhado.
 *
 * Props:
 *   open       – controla visibilidade
 *   patient    – paciente em foco na tela (id, code, full_name/person.full_name),
 *                quando já selecionado na página. Se null, mostra campo de
 *                busca (mesmo endpoint usado em ScheduleFormModal.vue —
 *                panel.patients.search).
 *   doctors    – [{id, name}] — já carregado por EyeImagesController::index()
 *   examTypes  – [{id, name}] — idem (props.exam_types da página)
 *   equipments – [{id, name}] — idem (props.equipments da página)
 *   urls       – { import_store, diagnosis_search, diagnosis_store }
 *
 * Emits:
 *   close    – fecha o modal (backdrop, X, cancelar)
 *   imported – exame(s) importado(s) com sucesso — o pai fecha o modal e
 *              atualiza a lista (fetchPatients), sem reload de página inteira.
 *              O toast de sucesso vem "de graça": o backend responde com um
 *              redirect + flash('success', ...) que o AppLayout já exibe.
 */
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import Cid10Picker from '@/Components/Panel/Cid10Picker.vue';

const props = defineProps({
    open:       { type: Boolean, required: true },
    patient:    { type: Object,  default: null },
    doctors:    { type: Array,   default: () => [] },
    examTypes:  { type: Array,   default: () => [] },
    equipments: { type: Array,   default: () => [] },
    urls:       { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'imported']);

function todayIso() {
    return new Date().toISOString().substring(0, 10);
}

function patientLabel(p) {
    return p?.person?.full_name ?? p?.full_name ?? '—';
}

const form = useForm({
    patient_id: '',
    exam_id: '',
    exam_performed_at: todayIso(),
    doctor_id: '',
    entity_integrator_equipment_id: '',
    external_origin: '',
    diagnoses: [],
    files: [],
});

// ── Paciente: pré-selecionado (props.patient) ou busca ──────────────────────
const hasFixedPatient = computed(() => !!props.patient?.id);

const patientQuery     = ref('');
const patientResults   = ref([]);
const patientSearching = ref(false);
const pickedPatient    = ref(null);
let patientDebounce = null;

function onPatientInput() {
    clearTimeout(patientDebounce);
    pickedPatient.value = null;
    form.patient_id = '';
    const q = patientQuery.value.trim();
    if (q.length < 2) { patientResults.value = []; return; }
    patientDebounce = setTimeout(searchPatients, 350);
}

async function searchPatients() {
    patientSearching.value = true;
    try {
        const { data } = await window.axios.get(route('panel.patients.search'), {
            params: { q: patientQuery.value.trim() },
        });
        patientResults.value = Array.isArray(data) ? data : [];
    } catch {
        patientResults.value = [];
    } finally {
        patientSearching.value = false;
    }
}

function selectPatient(p) {
    pickedPatient.value  = p;
    form.patient_id      = p.id;
    patientQuery.value   = p.full_name;
    patientResults.value = [];
}

function clearPickedPatient() {
    pickedPatient.value  = null;
    form.patient_id      = '';
    patientQuery.value   = '';
    patientResults.value = [];
}

// ── Origem: equipamento cadastrado vs texto livre (UX only — ambos os campos
// são nullable no backend, não há regra de "um dos dois é obrigatório") ─────
const originMode = ref('equipment'); // 'equipment' | 'manual'

watch(originMode, (mode) => {
    if (mode === 'equipment') form.external_origin = '';
    else form.entity_integrator_equipment_id = '';
});

// ── Diagnóstico (Cid10Picker embutido — mesmo padrão de DiagnosisManagerModal) ─
const creatingCustomDiagnosis = ref(false);
const createDiagnosisError    = ref('');

async function onCreateDiagnosis(term) {
    if (!props.urls?.diagnosis_store) return;
    creatingCustomDiagnosis.value = true;
    createDiagnosisError.value    = '';
    try {
        const { data } = await window.axios.post(props.urls.diagnosis_store, { name: term });
        const created = data?.data;
        if (created) {
            form.diagnoses = [
                ...form.diagnoses,
                {
                    code: created.code ?? null,
                    custom_diagnosis_id: created.custom_diagnosis_id,
                    description: created.description,
                    is_primary: form.diagnoses.length === 0,
                },
            ];
        }
    } catch (error) {
        createDiagnosisError.value = error?.response?.data?.message ?? 'Não foi possível cadastrar o diagnóstico.';
    } finally {
        creatingCustomDiagnosis.value = false;
    }
}

// ── Arquivos (multi-file básico: input[multiple] + preview + remover) ──────
const fileItems = ref([]); // [{ id, file, name, size, isImage, previewUrl }]

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    return `${(bytes / (1024 ** i)).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function onFilesChange(e) {
    const picked = Array.from(e.target.files ?? []);
    e.target.value = ''; // permite reselecionar o mesmo arquivo depois de remover

    for (const file of picked) {
        fileItems.value.push({
            id: `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
            file,
            name: file.name,
            size: file.size,
            isImage: /^image\//.test(file.type),
            previewUrl: /^image\//.test(file.type) ? URL.createObjectURL(file) : null,
        });
    }
    syncFormFiles();
}

function removeFile(id) {
    const idx = fileItems.value.findIndex((i) => i.id === id);
    if (idx === -1) return;
    if (fileItems.value[idx].previewUrl) URL.revokeObjectURL(fileItems.value[idx].previewUrl);
    fileItems.value.splice(idx, 1);
    syncFormFiles();
}

function syncFormFiles() {
    form.files = fileItems.value.map((i) => i.file);
}

function clearFileItems() {
    fileItems.value.forEach((i) => { if (i.previewUrl) URL.revokeObjectURL(i.previewUrl); });
    fileItems.value = [];
}

// ── Validação client-side básica (a de verdade é sempre a do backend) ───────
const clientError = ref('');

function validateClientSide() {
    if (!form.patient_id) return 'Selecione o paciente.';
    if (!form.exam_id) return 'Selecione o tipo de exame.';
    if (fileItems.value.length === 0) return 'Selecione ao menos um arquivo.';
    return '';
}

// Erros que não têm campo próprio no template (ex.: "diagnoses", "diagnoses.0").
const knownFields = [
    'patient_id', 'exam_id', 'exam_performed_at', 'doctor_id',
    'entity_integrator_equipment_id', 'external_origin',
];
const genericErrors = computed(() => Object.entries(form.errors ?? {})
    .filter(([key]) => !knownFields.includes(key) && key !== 'files' && !key.startsWith('files.'))
    .map(([, msg]) => msg));

function submit() {
    clientError.value = '';
    const err = validateClientSide();
    if (err) { clientError.value = err; return; }

    form.post(props.urls.import_store, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            clearFileItems();
            form.reset();
            emit('imported');
        },
        onError: () => {
            clientError.value = 'Verifique os campos destacados abaixo.';
        },
    });
}

function close() {
    emit('close');
}

// ── Reset ao abrir (mesmo padrão de DiagnosisManagerModal.vue) ─────────────
watch(() => props.open, (isOpen) => {
    if (!isOpen) return;

    form.clearErrors();
    clientError.value          = '';
    createDiagnosisError.value = '';
    clearFileItems();
    originMode.value = props.equipments?.length ? 'equipment' : 'manual';

    form.patient_id                     = props.patient?.id ?? '';
    form.exam_id                        = '';
    form.exam_performed_at              = todayIso();
    form.doctor_id                      = '';
    form.entity_integrator_equipment_id = '';
    form.external_origin                = '';
    form.diagnoses                      = [];
    form.files                          = [];

    pickedPatient.value  = null;
    patientQuery.value   = '';
    patientResults.value = [];
});
</script>

<template>
    <OffcanvasPanel :open="open" :width="640" @close="close">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-upload me-2 text-primary"></i>Importar exame externo
            </h5>
            <p class="text-muted mt-1 mb-0" style="font-size:.8rem;">
                Upload manual de exame realizado fora do integrador (ex.: laudo trazido pelo paciente).
            </p>
        </template>

        <div v-if="clientError" class="alert alert-warning py-2 px-3 small mb-3">{{ clientError }}</div>
        <div v-if="genericErrors.length" class="alert alert-danger py-2 px-3 small mb-3">
            <div v-for="(msg, i) in genericErrors" :key="i">{{ msg }}</div>
        </div>

        <!-- Paciente -->
        <div class="mb-3">
            <label class="form-label fw-semibold">Paciente <span class="text-danger">*</span></label>

            <div v-if="hasFixedPatient" class="form-control bg-body-secondary d-flex align-items-center gap-2">
                <i class="fa fa-user text-muted"></i>
                <span>{{ patientLabel(patient) }}</span>
                <span v-if="patient.code" class="text-muted">({{ patient.code }})</span>
            </div>

            <div v-else class="position-relative">
                <div v-if="pickedPatient" class="form-control d-flex align-items-center justify-content-between">
                    <span>
                        <i class="fa fa-user text-muted me-2"></i>{{ patientLabel(pickedPatient) }}
                        <span v-if="pickedPatient.code" class="text-muted">({{ pickedPatient.code }})</span>
                    </span>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="clearPickedPatient">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <template v-else>
                    <input v-model="patientQuery"
                           type="text"
                           class="form-control"
                           :class="{ 'is-invalid': form.errors.patient_id }"
                           placeholder="Buscar paciente por nome, código ou telefone..."
                           autocomplete="off"
                           @input="onPatientInput">
                    <span v-if="patientSearching" class="position-absolute top-50 end-0 translate-middle-y me-3">
                        <span class="spinner-border spinner-border-sm text-secondary"></span>
                    </span>
                    <ul v-if="patientResults.length > 0"
                        class="list-group position-absolute w-100 shadow-sm"
                        style="z-index:1060;max-height:220px;overflow-y:auto;">
                        <li v-for="p in patientResults" :key="p.id"
                            class="list-group-item list-group-item-action py-2 px-3"
                            style="cursor:pointer;"
                            @mousedown.prevent="selectPatient(p)">
                            <div class="fw-semibold small">{{ p.full_name }}</div>
                            <div class="text-muted" style="font-size:.75rem;">
                                {{ p.cellphone || p.telephone || '—' }} &bull; {{ p.code }}
                            </div>
                        </li>
                    </ul>
                </template>
            </div>
            <div v-if="form.errors.patient_id" class="text-danger small mt-1">{{ form.errors.patient_id }}</div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-sm-6">
                <label class="form-label fw-semibold">Tipo de exame <span class="text-danger">*</span></label>
                <select v-model="form.exam_id" class="form-select" :class="{ 'is-invalid': form.errors.exam_id }">
                    <option value="">Selecione...</option>
                    <option v-for="t in examTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <div v-if="form.errors.exam_id" class="invalid-feedback d-block">{{ form.errors.exam_id }}</div>
            </div>

            <div class="col-12 col-sm-6">
                <label class="form-label fw-semibold">Data do exame <span class="text-danger">*</span></label>
                <input v-model="form.exam_performed_at" type="date" class="form-control"
                       :class="{ 'is-invalid': form.errors.exam_performed_at }" :max="todayIso()">
                <div v-if="form.errors.exam_performed_at" class="invalid-feedback d-block">
                    {{ form.errors.exam_performed_at }}
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Médico</label>
            <select v-model="form.doctor_id" class="form-select" :class="{ 'is-invalid': form.errors.doctor_id }">
                <option value="">Não informado</option>
                <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
            <div v-if="form.errors.doctor_id" class="invalid-feedback d-block">{{ form.errors.doctor_id }}</div>
        </div>

        <!-- Origem -->
        <div class="mb-3">
            <label class="form-label fw-semibold d-block">Origem do exame</label>
            <div class="btn-group btn-group-sm mb-2" role="group">
                <input type="radio" class="btn-check" id="import-origin-equipment" value="equipment"
                       v-model="originMode" :disabled="!equipments.length">
                <label class="btn btn-outline-secondary" for="import-origin-equipment">Equipamento cadastrado</label>

                <input type="radio" class="btn-check" id="import-origin-manual" value="manual" v-model="originMode">
                <label class="btn btn-outline-secondary" for="import-origin-manual">Descrever origem</label>
            </div>

            <select v-if="originMode === 'equipment'" v-model="form.entity_integrator_equipment_id"
                    class="form-select" :class="{ 'is-invalid': form.errors.entity_integrator_equipment_id }">
                <option value="">Não informado</option>
                <option v-for="eq in equipments" :key="eq.id" :value="eq.id">{{ eq.name }}</option>
            </select>
            <input v-else v-model="form.external_origin" type="text" class="form-control"
                   :class="{ 'is-invalid': form.errors.external_origin }"
                   placeholder="Ex.: Clínica X, exame trazido pelo paciente..." maxlength="255">

            <div v-if="form.errors.entity_integrator_equipment_id" class="invalid-feedback d-block">
                {{ form.errors.entity_integrator_equipment_id }}
            </div>
            <div v-if="form.errors.external_origin" class="invalid-feedback d-block">
                {{ form.errors.external_origin }}
            </div>
        </div>

        <!-- Diagnóstico (opcional) -->
        <div class="mb-3">
            <div v-if="createDiagnosisError" class="alert alert-warning py-2 px-3 small mb-2">
                {{ createDiagnosisError }}
            </div>
            <Cid10Picker
                v-model="form.diagnoses"
                :search-url="urls.diagnosis_search || ''"
                :most-used-url="urls.diagnosis_search || ''"
                allow-custom-entry
                primary-toggle
                :creating="creatingCustomDiagnosis"
                :max-items="20"
                label="Diagnóstico (opcional)"
                placeholder="Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…"
                @create="onCreateDiagnosis"
            />
        </div>

        <!-- Arquivos -->
        <div class="mb-2">
            <label class="form-label fw-semibold">Arquivos <span class="text-danger">*</span></label>
            <input type="file" class="form-control" :class="{ 'is-invalid': form.errors.files }"
                   multiple accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                   @change="onFilesChange">
            <div class="form-text">Até 10 arquivos, JPG/PNG/PDF, até 20 MB cada.</div>
            <div v-if="form.errors.files" class="invalid-feedback d-block">{{ form.errors.files }}</div>

            <ul v-if="fileItems.length" class="list-group mt-2">
                <li v-for="(item, idx) in fileItems" :key="item.id"
                    class="list-group-item d-flex align-items-center gap-2 py-2">
                    <img v-if="item.previewUrl" :src="item.previewUrl" alt=""
                         style="width:36px;height:36px;object-fit:cover;border-radius:4px;flex-shrink:0;">
                    <i v-else class="fa fa-file-pdf text-danger" style="font-size:1.4rem;width:36px;text-align:center;flex-shrink:0;"></i>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="small fw-semibold text-truncate">{{ item.name }}</div>
                        <div class="text-muted" style="font-size:.72rem;">{{ formatBytes(item.size) }}</div>
                        <div v-if="form.errors[`files.${idx}`]" class="text-danger small">
                            {{ form.errors[`files.${idx}`] }}
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-link text-danger flex-shrink-0" @click="removeFile(item.id)">
                        <i class="fa fa-trash"></i>
                    </button>
                </li>
            </ul>
        </div>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="close">Cancelar</button>
            <button type="button" class="btn btn-primary btn-sm" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                Importar
            </button>
        </template>
    </OffcanvasPanel>
</template>
