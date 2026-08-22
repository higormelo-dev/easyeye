<script setup>
import { ref, watch, computed, nextTick } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import SlotPicker    from '@/Components/Panel/SlotPicker.vue';
import SearchSelect  from '@/Components/Panel/SearchSelect.vue';
import PatientFormSections from '@/Pages/Panel/Patients/PatientFormSections.vue';
import { usePatientForm } from '@/Support/usePatientForm.js';

const props = defineProps({
    open:            { type: Boolean, required: true },
    editSchedule:    { type: Object,  default: null },
    prefillData:     { type: Object,  default: null },
    doctors:         { type: Array,   default: () => [] },
    covenants:       { type: Array,   default: () => [] },
    visitTypes:      { type: Array,   default: () => [] },
    attendanceTypes: { type: Array,   default: () => [] },
    specialties:     { type: Array,   default: () => [] },
    // Cadastro completo do paciente DENTRO do agendamento: as abas
    // Pessoal/Clínico/Contato/Endereço (mesmos campos de Pacientes >
    // Editar Paciente, via PatientFormSections + usePatientForm) ficam na
    // mesma janela, ao lado da aba Agendamento. Ver SchedulesController::index().
    skinTypes:       { type: Array,   default: () => [] },
    irisTypes:       { type: Array,   default: () => [] },
    genders:         { type: Object,  default: () => ({}) },
    maritalStatuses: { type: Object,  default: () => ({}) },
    statesOfBrazil:  { type: Object,  default: () => ({}) },
    storeUrl:        { type: String,  required: true },
    defaultDate:     { type: String,  default: '' },
    t:               { type: Object,  required: true },
});

const emit = defineEmits(['close', 'saved']);

// ── Opções de recorrência (rótulos vindos das traduções) ──────────────────────
const recurrenceTypeOptions = computed(() => [
    { value: 'weekly',  label: props.t.form_weekly },
    { value: 'monthly', label: props.t.form_monthly },
]);

// ── Estado do formulário ───────────────────────────────────────────────────────
const saving = ref(false);
const errors = ref({});

const form = ref({
    doctor_id:          '',
    patient_id:         '',
    full_name:          '',
    date_time:          '',
    telephone:          '',
    cellphone:          '',
    cellphone_whatsapp: false,
    notes:              '',
    covenant_id:        '',
    visit_id:           '',
    attendance_type:    '',
    specialty_area:     '',
    resource_ids:       [],
    waiting_list_id:    '',
    use_recurrence:     false,
    recurrence_type:    'weekly',
    recurrence_until:   '',
});

// ── Abas (Agendamento + cadastro do paciente) ─────────────────────────────────
// Primeira aba = fluxo rápido atual da Agenda; as demais reaproveitam os
// mesmos campos de Pacientes > Editar Paciente. Tudo salva junto no
// "Salvar e vincular": primeiro o paciente (se criado/alterado), depois o
// agendamento já vinculado.
const activeTab = ref('schedule');

const patientTabs = [
    { key: 'personal', label: 'Pessoal',  icon: 'ti ti-user-circle' },
    { key: 'clinical', label: 'Clínico',  icon: 'ti ti-stethoscope' },
    { key: 'contact',  label: 'Contato',  icon: 'ti ti-phone' },
    { key: 'address',  label: 'Endereço', icon: 'ti ti-map-pin' },
];

const {
    form: patientForm,
    loading: patientLoading,
    resetForm: resetPatientForm,
    loadEditData,
    savePatient,
    lookupCep,
    genderOptions,
    maritalOptions,
    stateOptions,
    tabHasErrors,
    tabIncomplete,
} = usePatientForm(props);

// Abas do paciente "em uso": paciente vinculado ou algo digitado nelas.
// Controla os badges de pendência (não poluir quem só faz o fluxo rápido)
// e se o submit precisa salvar o paciente.
const patientTabsActive = computed(() => Boolean(form.value.patient_id || patientForm.isDirty));

function goToPatientTabs() {
    // Prefill de cortesia: aproveita o que a secretária já digitou na aba
    // Agendamento pra não redigitar no cadastro.
    if (!patientForm.name && (form.value.full_name || patientSearch.value)) {
        patientForm.name = form.value.full_name || patientSearch.value;
    }
    if (!patientForm.cellphone && form.value.cellphone) patientForm.cellphone = form.value.cellphone;
    if (!patientForm.telephone && form.value.telephone) patientForm.telephone = form.value.telephone;
    if (!patientForm.whatsapp && form.value.cellphone_whatsapp) patientForm.whatsapp = true;
    if (!patientForm.covenant_id && form.value.covenant_id) patientForm.covenant_id = form.value.covenant_id;

    activeTab.value = 'personal';
}

// ── SlotPicker ref ─────────────────────────────────────────────────────────────
const slotPickerRef = ref(null);

// ── Busca de paciente ──────────────────────────────────────────────────────────
const patientSearch   = ref('');
const patientResults  = ref([]);
const patientDebounce = ref(null);

function onPatientInput() {
    clearTimeout(patientDebounce.value);
    if (patientSearch.value.length < 2) { patientResults.value = []; return; }
    patientDebounce.value = setTimeout(searchPatients, 350);
}

async function searchPatients() {
    const res = await fetch(`/panel/patients/search?q=${encodeURIComponent(patientSearch.value)}`, {
        headers: { Accept: 'application/json' },
    });
    patientResults.value = res.ok ? await res.json() : [];
}

function selectPatient(p) {
    form.value.patient_id = p.id;
    form.value.full_name  = p.full_name;
    form.value.cellphone  = p.cellphone ?? '';
    form.value.telephone  = p.telephone ?? '';
    patientSearch.value   = p.full_name;
    patientResults.value  = [];

    // Carrega o cadastro completo nas abas — a secretária pode completar
    // os dados na hora; só vira UPDATE se ela alterar algo (isDirty).
    loadEditData(p.id);
}

function clearPatient() {
    form.value.patient_id = '';
    form.value.full_name  = '';
    patientSearch.value   = '';
    patientResults.value  = [];
    resetPatientForm();
}

// ── Recursos ───────────────────────────────────────────────────────────────────
const resources        = ref([]);
const resourcesLoaded  = ref(false);
const resourcesLoading = ref(false);

async function loadResources(dt) {
    if (!dt) return;
    resourcesLoading.value = true;
    resourcesLoaded.value  = false;
    const qs = new URLSearchParams({ date_time: dt });
    if (props.editSchedule?.id) qs.set('schedule_id', props.editSchedule.id);
    try {
        const res = await fetch(`/panel/schedules/resources?${qs}`, {
            headers: { Accept: 'application/json' },
        });
        if (res.ok) {
            resources.value       = (await res.json()).data ?? [];
            resourcesLoaded.value = true;
        }
    } catch { /**/ }
    resourcesLoading.value = false;
}

// Reage a mudanças no datetime selecionado (via SlotPicker ou override manual)
watch(
    () => form.value.date_time,
    (dt) => {
        if (dt) {
            loadResources(dt);
        } else {
            resources.value       = [];
            resourcesLoaded.value = false;
        }
    },
);

// ── Abertura / fechamento do modal ─────────────────────────────────────────────
watch(
    () => props.open,
    async (open) => {
        if (!open) return;

        // Reset campos auxiliares
        errors.value          = {};
        saving.value          = false;
        patientResults.value  = [];
        resources.value       = [];
        resourcesLoaded.value = false;
        activeTab.value       = 'schedule';
        resetPatientForm();

        if (props.editSchedule) {
            const s    = props.editSchedule;
            const dt   = s.date_time ? s.date_time.substring(0, 16) : '';
            const date = dt.substring(0, 10);

            form.value = {
                doctor_id:          s.doctor_id ?? '',
                patient_id:         s.patient_id ?? '',
                full_name:          s.full_name ?? '',
                date_time:          dt,
                telephone:          s.telephone ?? '',
                cellphone:          s.cellphone ?? '',
                cellphone_whatsapp: s.cellphone_whatsapp ?? false,
                notes:              s.notes ?? '',
                covenant_id:        s.covenant_id ?? '',
                visit_id:           s.visit_id ?? '',
                attendance_type:    s.attendance_type ?? '',
                specialty_area:     s.specialty_area ?? '',
                resource_ids:       (s.resources ?? []).map(r => r.id),
                waiting_list_id:    '',
                use_recurrence:     false,
                recurrence_type:    'weekly',
                recurrence_until:   '',
            };
            patientSearch.value = s.full_name ?? '';

            // Paciente vinculado: abas já abrem com o cadastro completo.
            if (s.patient_id) loadEditData(s.patient_id);

            // Navega o SlotPicker para a data do agendamento
            await nextTick();
            slotPickerRef.value?.setDate(date);
        } else {
            const initDate = props.defaultDate || new Date().toISOString().substring(0, 10);
            const p        = props.prefillData;

            const prefillDatetime = p?.date_time ?? '';

            form.value = {
                doctor_id:          p?.doctor_id ?? (props.doctors.length === 1 ? props.doctors[0].id : ''),
                patient_id:         p?.patient_id ?? '',
                full_name:          p?.full_name ?? '',
                date_time:          prefillDatetime,
                telephone:          p?.telephone ?? '',
                cellphone:          p?.cellphone ?? '',
                cellphone_whatsapp: p?.cellphone_whatsapp ?? false,
                notes:              p?.notes ?? '',
                covenant_id:        p?.covenant_id ?? '',
                visit_id:           p?.visit_id ?? '',
                attendance_type:    p?.attendance_type ?? '',
                specialty_area:     p?.specialty_area ?? '',
                resource_ids:       [],
                waiting_list_id:    p?.id ?? '',
                use_recurrence:     false,
                recurrence_type:    'weekly',
                recurrence_until:   '',
            };
            patientSearch.value = p?.full_name ?? '';

            if (p?.patient_id) loadEditData(p.patient_id);

            // Navega o SlotPicker para a data; se há datetime prefill o slot fica pré-selecionado
            const resetDate = prefillDatetime
                ? prefillDatetime.substring(0, 10)
                : initDate;
            await nextTick();
            slotPickerRef.value?.reset(resetDate);
        }
    },
);

// ── Envio do formulário ────────────────────────────────────────────────────────
const isEdit    = computed(() => !!props.editSchedule);
const submitUrl = computed(() =>
    isEdit.value
        ? (props.editSchedule.update_url ?? `/panel/schedules/${props.editSchedule.id}`)
        : props.storeUrl,
);

function firstPatientTabWithError() {
    return patientTabs.find(tab => tabHasErrors.value[tab.key])?.key ?? 'personal';
}

async function onSubmit() {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};

    // 1) Cadastro do paciente preenchido/alterado nas abas → salva ANTES do
    //    agendamento (cria ou atualiza) e vincula. Erro de validação leva
    //    direto pra aba com problema, sem perder nada do agendamento.
    if (patientForm.isDirty) {
        const result = await savePatient(form.value.patient_id || null);

        if (!result.ok) {
            activeTab.value = firstPatientTabWithError();
            saving.value    = false;
            return;
        }

        form.value.patient_id = result.patient.id;
        form.value.full_name  = result.patient.full_name || form.value.full_name;
        if (!form.value.cellphone && result.patient.cellphone) form.value.cellphone = result.patient.cellphone;
        if (!form.value.telephone && result.patient.telephone) form.value.telephone = result.patient.telephone;
        patientSearch.value = result.patient.full_name || patientSearch.value;
    }

    // 2) Agendamento (fluxo original)
    const body = {
        ...form.value,
        date_time:        form.value.date_time || null,
        recurrence_type:  form.value.use_recurrence ? form.value.recurrence_type  : null,
        recurrence_until: form.value.use_recurrence ? form.value.recurrence_until : null,
    };

    const res = await fetch(submitUrl.value, {
        method: isEdit.value ? 'PUT' : 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(body),
    });
    const json = await res.json();
    saving.value = false;

    if (res.ok) {
        if (window.showSuccessToast) window.showSuccessToast(json.message);
        emit('saved', json);
    } else if (res.status === 422) {
        errors.value = json.errors ?? {};
        activeTab.value = 'schedule';
    } else {
        if (window.showErrorToast) window.showErrorToast(json.message ?? 'Erro ao salvar.');
    }
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="emit('close')">

        <template #header>
            <h5 class="mb-0 fw-bold">
                <i class="fas fa-calendar-plus me-2 text-primary"></i>
                {{ isEdit ? t.form_title : t.btn_new }}
            </h5>
        </template>

        <!-- ── Abas: Agendamento + cadastro completo do paciente ─────────── -->
        <ul class="nav nav-tabs mb-3 schedule-form-tabs">
            <li class="nav-item">
                <button class="nav-link"
                        :class="{ active: activeTab === 'schedule', 'text-danger': Object.keys(errors).length > 0 }"
                        type="button"
                        @click="activeTab = 'schedule'">
                    <i class="ti ti-calendar-event me-1"></i>Agendamento
                    <i v-if="Object.keys(errors).length > 0" class="ti ti-alert-circle text-danger ms-1"></i>
                </button>
            </li>
            <li v-for="tab in patientTabs" :key="tab.key" class="nav-item">
                <button class="nav-link"
                        :class="{
                            active: activeTab === tab.key,
                            'text-danger': tabHasErrors[tab.key],
                            'text-primary fw-semibold': !tabHasErrors[tab.key] && patientTabsActive && tabIncomplete[tab.key],
                        }"
                        type="button"
                        @click="activeTab = tab.key">
                    <i :class="tab.icon" class="me-1"></i>{{ tab.label }}
                    <i v-if="tabHasErrors[tab.key]" class="ti ti-alert-circle text-danger ms-1"></i>
                    <i v-else-if="patientTabsActive && tabIncomplete[tab.key]" class="ti ti-circle-filled text-primary ms-1" style="font-size:.5rem;vertical-align:middle;"></i>
                </button>
            </li>
        </ul>

        <form @submit.prevent="onSubmit">

            <!-- ════════════ ABA: AGENDAMENTO (fluxo rápido atual) ════════════ -->
            <div v-show="activeTab === 'schedule'">

            <!-- ── Médico ────────────────────────────────────────────────── -->
            <div v-if="doctors.length !== 1" class="mb-3">
                <label class="form-label fw-semibold">
                    {{ t.form_doctor }} <span class="text-danger">*</span>
                </label>
                <SearchSelect v-model="form.doctor_id"
                              :options="doctors"
                              :placeholder="t.form_select"
                              :clearable="false"
                              :invalid="!!errors.doctor_id" />
                <div v-if="errors.doctor_id" class="invalid-feedback d-block">{{ errors.doctor_id[0] }}</div>
            </div>

            <!-- ════════════════════════════════════════════════════════════
                 SELETOR DE DATA E HORÁRIO — componente SlotPicker
                 ════════════════════════════════════════════════════════════ -->
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    {{ t.form_date_time }} <span class="text-danger">*</span>
                </label>

                <div v-if="errors.date_time" class="text-danger small mb-2">
                    <i class="fas fa-exclamation-circle me-1"></i>{{ errors.date_time[0] }}
                </div>

                <SlotPicker
                    v-if="open"
                    ref="slotPickerRef"
                    v-model="form.date_time"
                    :doctor-id="form.doctor_id"
                    :schedule-id="editSchedule?.id ?? null"
                    :t="t"
                />
            </div>

            <!-- ── Paciente ──────────────────────────────────────────────── -->
            <div class="mb-3">
                <label class="form-label fw-semibold">{{ t.form_patient }}</label>
                <div class="position-relative">
                    <input v-model="patientSearch"
                           type="text"
                           class="form-control"
                           :placeholder="t.form_patient_search"
                           autocomplete="off"
                           @input="onPatientInput">
                    <button v-if="form.patient_id"
                            type="button"
                            class="btn btn-sm btn-outline-secondary position-absolute end-0 top-0 mt-1 me-1"
                            @click="clearPatient">
                        <i class="fas fa-times"></i>
                    </button>
                    <ul v-if="patientResults.length > 0"
                        class="list-group position-absolute w-100 shadow-sm"
                        style="z-index:1060;max-height:200px;overflow-y:auto;">
                        <li v-for="p in patientResults"
                            :key="p.id"
                            class="list-group-item list-group-item-action py-2 px-3"
                            style="cursor:pointer;"
                            @mousedown.prevent="selectPatient(p)">
                            <div class="fw-semibold small">{{ p.full_name }}</div>
                            <div class="text-muted" style="font-size:.75rem;">
                                {{ p.cellphone || p.telephone || '—' }} &bull; {{ p.code }}
                            </div>
                        </li>
                    </ul>
                </div>
                <div class="mt-1">
                    <!-- Leva pras abas de cadastro na MESMA janela (aproveita o
                         que já foi digitado aqui) — sem sair da Agenda. -->
                    <button type="button"
                            class="btn btn-link btn-sm p-0 text-decoration-none"
                            @click="goToPatientTabs">
                        <i class="fas fa-plus me-1"></i>{{ t.form_register }}
                    </button>
                </div>
            </div>

            <!-- Nome livre (sem paciente vinculado) -->
            <div v-if="!form.patient_id" class="mb-3">
                <label class="form-label fw-semibold">{{ t.form_full_name }}</label>
                <input v-model="form.full_name"
                       type="text"
                       class="form-control"
                       :class="{ 'is-invalid': errors.full_name }">
                <div v-if="errors.full_name" class="invalid-feedback">{{ errors.full_name[0] }}</div>
            </div>

            <!-- ── Convênio + Tipo de consulta ───────────────────────────── -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.form_covenant }}</label>
                    <SearchSelect v-model="form.covenant_id"
                                  :options="covenants"
                                  :placeholder="t.form_none" />
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.form_visit_type }}</label>
                    <SearchSelect v-model="form.visit_id"
                                  :options="visitTypes"
                                  :placeholder="t.form_none" />
                </div>
            </div>

            <!-- ── Tipo de atendimento + Especialidade/Área ─────────────── -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.form_attendance_type }}</label>
                    <SearchSelect v-model="form.attendance_type"
                                  :options="attendanceTypes"
                                  :placeholder="t.form_none"
                                  :searchable="false"
                                  :invalid="!!errors.attendance_type" />
                    <div v-if="errors.attendance_type" class="invalid-feedback d-block">{{ errors.attendance_type[0] }}</div>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.form_specialty_area }}</label>
                    <SearchSelect v-model="form.specialty_area"
                                  :options="specialties"
                                  :placeholder="t.form_none"
                                  :invalid="!!errors.specialty_area" />
                    <div v-if="errors.specialty_area" class="invalid-feedback d-block">{{ errors.specialty_area[0] }}</div>
                </div>
            </div>

            <!-- ── Recursos da clínica ───────────────────────────────────── -->
            <div v-if="form.date_time" class="mb-3">
                <label class="form-label fw-semibold">
                    {{ t.form_resources }}
                    <small class="text-muted fw-normal">{{ t.form_resources_opt }}</small>
                </label>

                <div v-if="resourcesLoading" class="py-1 d-flex align-items-center gap-2">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                    <span class="text-muted small">{{ t.loading }}</span>
                </div>

                <p v-else-if="resourcesLoaded && resources.length === 0"
                   class="text-muted small mb-0">
                    <i class="fas fa-info-circle me-1"></i>{{ t.form_no_resources }}
                </p>

                <div v-else class="d-flex flex-wrap gap-3">
                    <div v-for="r in resources" :key="r.id" class="form-check">
                        <input :id="`res-${r.id}`"
                               v-model="form.resource_ids"
                               type="checkbox"
                               class="form-check-input"
                               :value="r.id"
                               :disabled="!r.available && !form.resource_ids.includes(r.id)">
                        <label :for="`res-${r.id}`" class="form-check-label small">
                            {{ r.name }}
                            <span v-if="!r.available && !form.resource_ids.includes(r.id)"
                                  class="badge bg-danger ms-1"
                                  style="font-size:.65rem;">
                                {{ t.form_busy }}
                            </span>
                            <span v-else-if="r.available && !form.resource_ids.includes(r.id)"
                                  class="badge bg-success ms-1"
                                  style="font-size:.65rem;">
                                {{ t.form_free }}
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- ── Telefones ─────────────────────────────────────────────── -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.form_telephone }}</label>
                    <input v-model="form.telephone" type="text" class="form-control">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.form_cellphone }}</label>
                    <input v-model="form.cellphone" type="text" class="form-control">
                </div>
            </div>
            <div class="form-check mb-3">
                <input id="whatsapp" v-model="form.cellphone_whatsapp" type="checkbox" class="form-check-input">
                <label for="whatsapp" class="form-check-label small">
                    <i class="fab fa-whatsapp text-success me-1"></i>{{ t.form_whatsapp }}
                </label>
            </div>

            <!-- ── Observações ───────────────────────────────────────────── -->
            <div class="mb-3">
                <label class="form-label fw-semibold">{{ t.form_notes }}</label>
                <textarea v-model="form.notes"
                          class="form-control"
                          rows="3"
                          :placeholder="t.form_notes_ph"></textarea>
            </div>

            <!-- ── Recorrência (somente ao criar) ───────────────────────── -->
            <div v-if="!isEdit" class="mb-3">
                <div class="form-check">
                    <input id="use-recurrence"
                           v-model="form.use_recurrence"
                           type="checkbox"
                           class="form-check-input">
                    <label for="use-recurrence" class="form-check-label fw-semibold">
                        {{ t.form_recurrence }}
                    </label>
                </div>
                <div v-if="form.use_recurrence" class="mt-2 row g-2">
                    <div class="col-6">
                        <label class="form-label small">{{ t.form_rec_freq }}</label>
                        <SearchSelect v-model="form.recurrence_type"
                                      :options="recurrenceTypeOptions"
                                      :value-key="'value'"
                                      :label-key="'label'"
                                      :clearable="false"
                                      :searchable="false" />
                    </div>
                    <div class="col-6">
                        <label class="form-label small">{{ t.form_rec_until }}</label>
                        <input v-model="form.recurrence_until"
                               type="date"
                               class="form-control form-control-sm">
                    </div>
                    <div class="col-12">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>{{ t.form_rec_hint }}
                        </small>
                    </div>
                </div>
            </div>

            </div><!-- /aba Agendamento -->

            <!-- ════════════ ABAS: CADASTRO DO PACIENTE ════════════ -->
            <div v-show="activeTab !== 'schedule'" class="schedule-patient-tabs">
                <div v-if="patientLoading" class="py-2 d-flex align-items-center gap-2">
                    <span class="spinner-border spinner-border-sm text-secondary"></span>
                    <span class="text-muted small">{{ t.loading }}</span>
                </div>

                <div v-else>
                    <div class="alert alert-light border small py-2 d-flex align-items-center gap-2 mb-3">
                        <i class="ti ti-info-circle text-primary"></i>
                        <span v-if="form.patient_id">
                            Editando o cadastro de <strong>{{ patientSearch || form.full_name }}</strong> — as alterações são salvas junto com o agendamento.
                        </span>
                        <span v-else>
                            Novo paciente — o cadastro é criado e vinculado ao clicar em <strong>{{ t.form_save_link }}</strong>.
                        </span>
                    </div>

                    <PatientFormSections
                        :form="patientForm"
                        :section="activeTab"
                        :covenants="covenants"
                        :skin-types="skinTypes"
                        :iris-types="irisTypes"
                        :gender-options="genderOptions"
                        :marital-options="maritalOptions"
                        :state-options="stateOptions"
                        :is-edit="!!form.patient_id"
                        :lookup-cep="lookupCep"
                    />
                </div>
            </div>

        </form>

        <template #footer>
            <button type="button" class="btn btn-secondary" @click="emit('close')">
                {{ t.form_cancel }}
            </button>
            <button type="button"
                    class="btn btn-primary px-4"
                    :disabled="saving || patientForm.processing"
                    @click="onSubmit">
                <span v-if="saving || patientForm.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ saving ? t.saving : t.form_save_link }}
            </button>
        </template>

    </CenteredModal>
</template>

<style scoped>
/* Altura mínima nas abas do paciente: v-show esconde sem ocupar espaço e o
   modal ficaria "pulando" a cada troca de aba. */
.schedule-patient-tabs {
    min-height: 480px;
}
</style>
