<script setup>
/**
 * DiagnosisManagerModal — "Diagnóstico do exame" (Gerenciador de Imagens).
 *
 * Edita PatientExam::diagnosis_cids do exame em foco, combinando o catálogo
 * CID-10 com diagnósticos customizados da clínica (ExamDiagnosisController).
 * Permite cadastrar diagnóstico customizado inline e marcar um deles como
 * principal — ver Cid10Picker.vue (allowCustomEntry/primaryToggle).
 *
 * Props:
 *   open – controla visibilidade
 *   exam – exame em foco (id, diagnosis_cids, exam_type_name, laterality,
 *          created_at_fmt, ...) — ver EyeImagesController::serializePatients()
 *   urls – { search, store, update } já resolvidos pelo pai (Index.vue) a
 *          partir de props.urls.diagnoses_search / diagnoses_store /
 *          exam_diagnosis_update (o __ID__ deste último já substituído pelo
 *          id do exame em foco).
 *
 * Emits:
 *   close
 *   updated({ id, diagnosis_cids }) – exame salvo, pro pai mesclar na lista
 *          local sem precisar de reload de página inteira.
 */
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import Cid10Picker from '@/Components/Panel/Cid10Picker.vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    exam: { type: Object, default: null },
    urls: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'updated']);

const form           = useForm({ diagnoses: [] });
const creatingCustom = ref(false);
const createError    = ref('');

function latLabel(v) {
    return ({ 1: 'OD', 2: 'OE' })[v] ?? 'AO';
}

// Erros de validação (422): a chave pode ser tanto `diagnoses` (ex.: CID
// duplicado — DiagnosisCatalogService::rejectDuplicates) quanto
// `diagnoses.N.campo` (ex.: descrição vazia) — mostramos todos.
const errorMessages = computed(() => Object.values(form.errors ?? {}));

watch(() => props.open, (isOpen) => {
    if (!isOpen) return;
    form.clearErrors();
    createError.value = '';
    form.diagnoses = Array.isArray(props.exam?.diagnosis_cids)
        ? props.exam.diagnosis_cids.map((d) => ({ ...d }))
        : [];
});

// Ao emit('create', termo) do Cid10Picker: cadastra o diagnóstico customizado
// da clínica (ExamDiagnosisController::store) e injeta o resultado na seleção.
async function onCreateDiagnosis(term) {
    if (!props.urls?.store) return;
    creatingCustom.value = true;
    createError.value    = '';
    try {
        const { data } = await window.axios.post(props.urls.store, { name: term });
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
        createError.value = error?.response?.data?.message ?? 'Não foi possível cadastrar o diagnóstico.';
    } finally {
        creatingCustom.value = false;
    }
}

function submit() {
    if (!props.urls?.update || form.diagnoses.length === 0) return;
    form.put(props.urls.update, {
        preserveScroll: true,
        onSuccess: () => {
            if (window.showSuccessToast) window.showSuccessToast('Diagnóstico salvo.');
            emit('updated', { id: props.exam?.id, diagnosis_cids: form.diagnoses });
            emit('close');
        },
        onError: () => {
            if (window.showErrorToast) window.showErrorToast('Verifique os diagnósticos informados.');
        },
    });
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="620" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-stethoscope me-2 text-info"></i>Diagnóstico do exame
            </h5>
            <div v-if="exam" class="text-muted mt-1" style="font-size:.78rem;">
                {{ exam.exam_type_name || exam.exam_type?.name || 'Exame' }}
                <span class="mx-1">·</span>{{ latLabel(exam.laterality) }}
                <template v-if="exam.created_at_fmt"><span class="mx-1">·</span>{{ exam.created_at_fmt }}</template>
            </div>
        </template>

        <div v-if="!exam" class="text-muted text-center py-4">
            Nenhum exame selecionado.
        </div>

        <template v-else>
            <div v-if="errorMessages.length" class="alert alert-danger py-2 px-3 small mb-3">
                <div v-for="(msg, i) in errorMessages" :key="i">{{ msg }}</div>
            </div>
            <div v-if="createError" class="alert alert-warning py-2 px-3 small mb-3">
                {{ createError }}
            </div>

            <Cid10Picker
                v-model="form.diagnoses"
                :search-url="urls.search"
                :most-used-url="urls.search"
                allow-custom-entry
                primary-toggle
                :creating="creatingCustom"
                :max-items="20"
                label="Diagnóstico (CID-10 ou customizado da clínica)"
                placeholder="Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…"
                @create="onCreateDiagnosis"
            />

            <p v-if="form.diagnoses.length === 0" class="text-muted small mt-2 mb-0">
                Adicione ao menos um diagnóstico para salvar.
            </p>
        </template>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="$emit('close')">
                Cancelar
            </button>
            <button type="button" class="btn btn-primary btn-sm"
                    :disabled="form.processing || !exam || form.diagnoses.length === 0"
                    @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                Salvar
            </button>
        </template>
    </OffcanvasPanel>
</template>
