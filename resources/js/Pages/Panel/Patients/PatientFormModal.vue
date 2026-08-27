<script setup>
import { ref, watch, computed } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import PatientFormSections from '@/Pages/Panel/Patients/PatientFormSections.vue';
import { usePatientForm } from '@/Support/usePatientForm.js';

const props = defineProps({
    open:           { type: Boolean, required: true },
    patientId:      { type: String,  default: null },
    covenants:      { type: Array,   default: () => [] },
    skinTypes:      { type: Array,   default: () => [] },
    irisTypes:      { type: Array,   default: () => [] },
    genders:        { type: Object,  default: () => ({}) },
    maritalStatuses:{ type: Object,  default: () => ({}) },
    statesOfBrazil: { type: Object,  default: () => ({}) },
    // `embedded` — usado quando este modal abre de DENTRO de outro fluxo em
    // vez da própria página Panel/Patients. O submit padrão do Inertia
    // (form.post/put) não serve: ele segue o redirect do backend pra
    // `panel.patients.index`, navegando pra fora do fluxo hospedeiro. Em modo
    // embedded o submit vira fetch()+JSON (backend já responde
    // PatientResource quando wantsJson()) e emite `saved` em vez de redirect.
    embedded: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'saved']);

const isEdit    = computed(() => !!props.patientId);
const title     = computed(() => isEdit.value ? 'Editar Paciente' : 'Novo Paciente');
const activeTab = ref('personal');

// Toda a lógica do formulário (campos, load, save embedded, CEP, badges de
// aba) vive em usePatientForm — compartilhada com o agendamento
// (ScheduleFormModal), que renderiza as mesmas abas inline.
const {
    form, loading, resetForm, loadEditData, savePatient, lookupCep,
    genderOptions, maritalOptions, stateOptions, tabHasErrors, tabIncomplete,
} = usePatientForm(props);

watch(() => props.open, async (val) => {
    if (!val) return;
    resetForm();
    activeTab.value = 'personal';
    if (props.patientId) await loadEditData(props.patientId);
});

async function submit() {
    if (props.embedded) {
        const result = await savePatient(props.patientId);
        if (result.ok) emit('saved', result.patient);
        return;
    }

    if (isEdit.value) {
        form.put(route('panel.patients.update', props.patientId), {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
    } else {
        form.post(route('panel.patients.store'), {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
    }
}

const tabs = [
    { key: 'personal', label: 'Pessoal',  icon: 'ti ti-user-circle' },
    { key: 'clinical', label: 'Clínico',  icon: 'ti ti-stethoscope' },
    { key: 'contact',  label: 'Contato',  icon: 'ti ti-phone' },
    { key: 'address',  label: 'Endereço', icon: 'ti ti-map-pin' },
];
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="600"
        :loading="loading"
        @close="emit('close')"
    >
        <!-- ── Cabeçalho ────────────────────────────────────────────────────── -->
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-user me-2 text-primary"></i>{{ title }}
            </h5>
        </template>

        <!-- ── Abas ─────────────────────────────────────────────────────────── -->
        <template #tabs>
            <ul class="nav nav-tabs border-0">
                <li v-for="tab in tabs" :key="tab.key" class="nav-item">
                    <button class="nav-link"
                            :class="{
                                active: activeTab === tab.key,
                                'text-danger': tabHasErrors[tab.key],
                                'text-primary fw-semibold': !tabHasErrors[tab.key] && tabIncomplete[tab.key],
                            }"
                            type="button"
                            @click="activeTab = tab.key">
                        <i :class="tab.icon" class="me-1"></i>{{ tab.label }}
                        <i v-if="tabHasErrors[tab.key]" class="ti ti-alert-circle text-danger ms-1"></i>
                        <i v-else-if="tabIncomplete[tab.key]" class="ti ti-circle-dot text-primary ms-1" style="font-size:.5rem;vertical-align:middle;"></i>
                    </button>
                </li>
            </ul>
        </template>

        <!-- ── Corpo do formulário ───────────────────────────────────────────── -->
        <!-- min-height fixo: v-show usa display:none nas abas ocultas (não
             contribuem pro layout), então sem isso o offcanvas encolhe/cresce
             a cada troca de aba. Altura calibrada pra caber a aba Pessoal
             (a mais longa) sem sobra visível nas mais curtas. -->
        <form class="patient-form-tabs" @submit.prevent="submit">
            <PatientFormSections
                :form="form"
                :section="activeTab"
                :covenants="covenants"
                :skin-types="skinTypes"
                :iris-types="irisTypes"
                :gender-options="genderOptions"
                :marital-options="maritalOptions"
                :state-options="stateOptions"
                :is-edit="isEdit"
                :lookup-cep="lookupCep"
            />
        </form>

        <!-- ── Rodapé ────────────────────────────────────────────────────────── -->
        <template #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">Cancelar</button>
            <button type="button"
                    class="btn btn-primary px-4"
                    :disabled="form.processing"
                    @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ isEdit ? 'Salvar alterações' : 'Cadastrar paciente' }}
            </button>
        </template>

    </OffcanvasPanel>
</template>

<style scoped>
/* Tamanho fixo entre abas (ver comentário acima do <form>). */
.patient-form-tabs {
    min-height: 480px;
}
</style>
