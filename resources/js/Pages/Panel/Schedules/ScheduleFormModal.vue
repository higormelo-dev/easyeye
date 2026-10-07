<script setup>
import { ref, watch, computed, nextTick, onBeforeUnmount } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import SlotPicker from '@/Components/Panel/SlotPicker.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import PatientFormSections from '@/Pages/Panel/Patients/PatientFormSections.vue';
import { usePatientForm } from '@/Support/usePatientForm.js';

const props = defineProps({
    open: { type: Boolean, required: true },
    editSchedule: { type: Object, default: null },
    prefillData: { type: Object, default: null },
    doctors: { type: Array, default: () => [] },
    covenants: { type: Array, default: () => [] },
    visitTypes: { type: Array, default: () => [] },
    specialties: { type: Array, default: () => [] },
    // Cadastro completo do paciente DENTRO do agendamento: as abas
    // Pessoal/Clínico/Contato/Endereço (mesmos campos de Pacientes >
    // Editar Paciente, via PatientFormSections + usePatientForm) ficam na
    // mesma janela, ao lado da aba Agendamento. Ver SchedulesController::index().
    skinTypes: { type: Array, default: () => [] },
    irisTypes: { type: Array, default: () => [] },
    genders: { type: Object, default: () => ({}) },
    maritalStatuses: { type: Object, default: () => ({}) },
    statesOfBrazil: { type: Object, default: () => ({}) },
    storeUrl: { type: String, required: true },
    defaultDate: { type: String, default: '' },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved']);

// ── Série de sessões (rótulos vindos das traduções) ───────────────────────────
const recurrenceTypeOptions = computed(() => [
    { value: 'weekly', label: props.t.form_weekly },
    { value: 'monthly', label: props.t.form_monthly },
    { value: 'weekdays', label: props.t.form_weekdays ?? 'Dias da semana' },
]);

const sessionLocale = typeof window !== 'undefined' && window.sessionLocale ? window.sessionLocale : 'pt-BR';

// Seg..Sáb (domingo fora: clínica não atende tratamento seriado no domingo).
const weekdayOptions = computed(() =>
    [1, 2, 3, 4, 5, 6].map((day) => ({
        day,
        label: new Date(2023, 0, 1 + day)
            .toLocaleDateString(sessionLocale, { weekday: 'short' })
            .replace(/\.$/, '')
            .replace(/^\w/, (c) => c.toUpperCase()),
    })),
);

const SERIES_MAX = 60;

// ── Estado do formulário ───────────────────────────────────────────────────────
const saving = ref(false);
const errors = ref({});

const form = ref({
    doctor_id: '',
    patient_id: '',
    full_name: '',
    date_time: '',
    telephone: '',
    cellphone: '',
    cellphone_whatsapp: false,
    notes: '',
    covenant_id: '',
    visit_id: '',
    specialty_area: '',
    resource_ids: [],
    waiting_list_id: '',
    use_recurrence: false,
    recurrence_type: 'weekly',
    recurrence_until: '',
    recurrence_weekdays: [],
    recurrence_end: 'count',
    recurrence_count: 4,
    is_fit_in: false,
});

// ── Prévia da série: datas + conflitos (servidor), datas removidas à mão ──────
const seriesPreview = ref([]);
const seriesLoading = ref(false);
const seriesError = ref('');
const seriesTruncated = ref(false);
const seriesRemoved = ref([]); // date_time das sessões que a recepção tirou
let seriesDebounce = null;
let seriesRequest = 0;

const seriesSelected = computed(() =>
    seriesPreview.value.filter((session, index) => index === 0 || !seriesRemoved.value.includes(session.date_time)),
);
const seriesConflicts = computed(() => seriesSelected.value.filter((session) => session.errors.length > 0));

function seriesParams() {
    const f = form.value;
    const params = new URLSearchParams({
        doctor_id: f.doctor_id,
        date_time: f.date_time,
        frequency: f.recurrence_type,
        end: f.recurrence_end,
        is_fit_in: f.is_fit_in ? '1' : '0',
    });
    if (f.recurrence_end === 'count') params.set('count', String(f.recurrence_count || ''));
    else params.set('until', f.recurrence_until || '');
    if (f.recurrence_type === 'weekdays') f.recurrence_weekdays.forEach((d) => params.append('weekdays[]', String(d)));
    (f.resource_ids ?? []).forEach((id) => params.append('resource_ids[]', id));
    return params;
}

function seriesReady() {
    const f = form.value;
    if (!f.use_recurrence || !f.doctor_id || !f.date_time) return false;
    if (f.recurrence_type === 'weekdays' && f.recurrence_weekdays.length === 0) return false;
    if (f.recurrence_end === 'count') return Number(f.recurrence_count) >= 2;
    return Boolean(f.recurrence_until);
}

async function loadSeriesPreview() {
    if (!seriesReady()) {
        seriesPreview.value = [];
        return;
    }
    const ticket = ++seriesRequest;
    seriesLoading.value = true;
    seriesError.value = '';
    try {
        const res = await fetch(`${route('panel.schedules.series.preview')}?${seriesParams()}`, {
            headers: { Accept: 'application/json' },
        });
        const json = await res.json().catch(() => ({}));
        if (ticket !== seriesRequest) return;
        if (!res.ok) {
            seriesPreview.value = [];
            seriesError.value = Object.values(json.errors ?? {})[0]?.[0] ?? json.message ?? '';
            return;
        }
        seriesPreview.value = json.sessions ?? [];
        seriesTruncated.value = Boolean(json.truncated);
    } catch {
        // Falha de rede: sem prévia (o salvar valida tudo de novo no servidor).
        if (ticket === seriesRequest) seriesPreview.value = [];
    } finally {
        if (ticket === seriesRequest) seriesLoading.value = false;
    }
}

function scheduleSeriesPreview() {
    clearTimeout(seriesDebounce);
    seriesDebounce = setTimeout(loadSeriesPreview, 400);
}

function toggleSession(session) {
    const idx = seriesRemoved.value.indexOf(session.date_time);
    if (idx === -1) seriesRemoved.value.push(session.date_time);
    else seriesRemoved.value.splice(idx, 1);
}

function toggleWeekday(day) {
    const list = form.value.recurrence_weekdays;
    const idx = list.indexOf(day);
    if (idx === -1) list.push(day);
    else list.splice(idx, 1);
}

function sessionDateLabel(iso) {
    const dt = new Date(`${iso}:00`);
    if (Number.isNaN(dt.getTime())) return iso;
    const date = dt.toLocaleDateString(sessionLocale, {
        weekday: 'short',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
    const time = dt.toLocaleTimeString(sessionLocale, { hour: '2-digit', minute: '2-digit' });
    return `${date} · ${time}`;
}

// ── Abas (Agendamento + cadastro do paciente) ─────────────────────────────────
// Primeira aba = fluxo rápido atual da Agenda; as demais reaproveitam os
// mesmos campos de Pacientes > Editar Paciente. Tudo salva junto no
// "Salvar e vincular": primeiro o paciente (se criado/alterado), depois o
// agendamento já vinculado.
const activeTab = ref('schedule');

const patientTabs = [
    { key: 'personal', label: 'Pessoal', icon: 'ti ti-user-circle' },
    { key: 'clinical', label: 'Clínico', icon: 'ti ti-stethoscope' },
    { key: 'contact', label: 'Contato', icon: 'ti ti-phone' },
    { key: 'address', label: 'Endereço', icon: 'ti ti-map-pin' },
];

const {
    form: patientForm,
    loading: patientLoading,
    planOption,
    covenantOption,
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
const patientSearch = ref('');
const patientResults = ref([]);
const patientDebounce = ref(null);

function onPatientInput() {
    clearTimeout(patientDebounce.value);
    if (patientSearch.value.length < 2) {
        patientResults.value = [];
        return;
    }
    patientDebounce.value = setTimeout(searchPatients, 350);
}

async function searchPatients() {
    try {
        const res = await fetch(`/panel/patients/search?q=${encodeURIComponent(patientSearch.value)}`, {
            headers: { Accept: 'application/json' },
        });
        patientResults.value = res.ok ? await res.json() : [];
    } catch {
        patientResults.value = [];
    }
}

// Debounces (prévia da série, busca de paciente) morrem com o modal: nada de
// fetch/re-render depois de sair da tela.
onBeforeUnmount(() => {
    clearTimeout(seriesDebounce);
    clearTimeout(patientDebounce.value);
});

function selectPatient(p) {
    form.value.patient_id = p.id;
    form.value.full_name = p.full_name;
    form.value.cellphone = p.cellphone ?? '';
    form.value.telephone = p.telephone ?? '';
    patientSearch.value = p.full_name;
    patientResults.value = [];

    // Carrega o cadastro completo nas abas — a secretária pode completar
    // os dados na hora; só vira UPDATE se ela alterar algo (isDirty).
    loadEditData(p.id);
}

function clearPatient() {
    form.value.patient_id = '';
    form.value.full_name = '';
    patientSearch.value = '';
    patientResults.value = [];
    resetPatientForm();
}

// ── Recursos ───────────────────────────────────────────────────────────────────
const resources = ref([]);
const resourcesLoaded = ref(false);
const resourcesLoading = ref(false);

async function loadResources(dt) {
    if (!dt) return;
    resourcesLoading.value = true;
    resourcesLoaded.value = false;
    const qs = new URLSearchParams({ date_time: dt });
    if (props.editSchedule?.id) qs.set('schedule_id', props.editSchedule.id);
    try {
        const res = await fetch(`/panel/schedules/resources?${qs}`, {
            headers: { Accept: 'application/json' },
        });
        if (res.ok) {
            resources.value = (await res.json()).data ?? [];
            resourcesLoaded.value = true;
        }
    } catch {
        /**/
    }
    resourcesLoading.value = false;
}

// Reage a mudanças no datetime selecionado (via SlotPicker ou override manual)
watch(
    () => form.value.date_time,
    (dt) => {
        if (dt) {
            loadResources(dt);
        } else {
            resources.value = [];
            resourcesLoaded.value = false;
        }
    },
);

// Prévia acompanha o que muda (data/horário, médico, frequência, fim, sala, encaixe).
watch(
    () => [
        form.value.use_recurrence,
        form.value.doctor_id,
        form.value.date_time,
        form.value.recurrence_type,
        form.value.recurrence_weekdays.join(','),
        form.value.recurrence_end,
        form.value.recurrence_count,
        form.value.recurrence_until,
        (form.value.resource_ids ?? []).join(','),
        form.value.is_fit_in,
    ],
    () => {
        if (!isEdit.value) scheduleSeriesPreview();
    },
);

// ── Abertura / fechamento do modal ─────────────────────────────────────────────
watch(
    () => props.open,
    async (open) => {
        if (!open) return;

        // Reset campos auxiliares
        errors.value = {};
        seriesPreview.value = [];
        seriesRemoved.value = [];
        seriesError.value = '';
        seriesTruncated.value = false;
        saving.value = false;
        patientResults.value = [];
        resources.value = [];
        resourcesLoaded.value = false;
        activeTab.value = 'schedule';
        resetPatientForm();

        if (props.editSchedule) {
            const s = props.editSchedule;
            // show() manda date_time formatado (d/m/Y H:i, para o drawer) e
            // date_time_iso para o formulário.
            const iso = s.date_time_iso ?? s.date_time ?? '';
            const dt = iso ? iso.substring(0, 16) : '';
            const date = dt.substring(0, 10);

            form.value = {
                doctor_id: s.doctor_id ?? '',
                patient_id: s.patient_id ?? '',
                full_name: s.full_name ?? s.patient_name ?? '',
                date_time: dt,
                telephone: s.telephone ?? '',
                cellphone: s.cellphone ?? '',
                cellphone_whatsapp: s.cellphone_whatsapp ?? false,
                notes: s.notes ?? '',
                covenant_id: s.covenant_id ?? '',
                visit_id: s.visit_id ?? '',
                specialty_area: s.specialty_area ?? '',
                resource_ids: (s.resources ?? []).map((r) => r.id),
                waiting_list_id: '',
                use_recurrence: false,
                recurrence_type: 'weekly',
                recurrence_until: '',
                recurrence_weekdays: [],
                recurrence_end: 'count',
                recurrence_count: 4,
                is_fit_in: Boolean(s.is_fit_in),
            };
            patientSearch.value = s.full_name ?? s.patient_name ?? '';

            // Paciente vinculado: abas já abrem com o cadastro completo.
            if (s.patient_id) loadEditData(s.patient_id);

            // Navega o SlotPicker para a data do agendamento
            await nextTick();
            slotPickerRef.value?.setDate(date);
        } else {
            const initDate = props.defaultDate || new Date().toISOString().substring(0, 10);
            const p = props.prefillData;

            const prefillDatetime = p?.date_time ?? '';

            form.value = {
                doctor_id: p?.doctor_id ?? (props.doctors.length === 1 ? props.doctors[0].id : ''),
                patient_id: p?.patient_id ?? '',
                full_name: p?.full_name ?? '',
                date_time: prefillDatetime,
                telephone: p?.telephone ?? '',
                cellphone: p?.cellphone ?? '',
                cellphone_whatsapp: p?.cellphone_whatsapp ?? false,
                notes: p?.notes ?? '',
                covenant_id: p?.covenant_id ?? '',
                visit_id: p?.visit_id ?? '',
                specialty_area: p?.specialty_area ?? '',
                resource_ids: [],
                waiting_list_id: p?.id ?? '',
                use_recurrence: false,
                recurrence_type: 'weekly',
                recurrence_until: '',
                recurrence_weekdays: [],
                recurrence_end: 'count',
                recurrence_count: 4,
                is_fit_in: false,
            };
            patientSearch.value = p?.full_name ?? '';

            if (p?.patient_id) loadEditData(p.patient_id);

            // Navega o SlotPicker para a data; se há datetime prefill o slot fica pré-selecionado
            const resetDate = prefillDatetime ? prefillDatetime.substring(0, 10) : initDate;
            await nextTick();
            slotPickerRef.value?.reset(resetDate);
        }
    },
);

// ── Convênio ───────────────────────────────────────────────────────────────────
// A lista (props.covenants) só tem ativos. Agendamento antigo cujo convênio foi
// inativado depois mantém o dele (o servidor aceita o mesmo id) — a opção
// aparece marcada "(inativo)" em vez de o campo parecer vazio. Vindo da lista
// de espera, o inativo também aparece marcado (o servidor recusa e a recepção
// troca por um ativo).
const covenantOptions = computed(() => {
    const s = props.editSchedule ?? props.prefillData;
    if (!s?.covenant_id || props.covenants.some((c) => c.id === s.covenant_id)) return props.covenants;

    const suffix = props.t.covenant_inactive_suffix ?? '(inativo)';
    return [...props.covenants, { id: s.covenant_id, name: `${s.covenant_name ?? ''} ${suffix}`.trim() }];
});

// ── Envio do formulário ────────────────────────────────────────────────────────
const isEdit = computed(() => !!props.editSchedule);
const submitUrl = computed(() =>
    isEdit.value ? (props.editSchedule.update_url ?? `/panel/schedules/${props.editSchedule.id}`) : props.storeUrl,
);

function firstPatientTabWithError() {
    return patientTabs.find((tab) => tabHasErrors.value[tab.key])?.key ?? 'personal';
}

async function onSubmit() {
    if (saving.value) return;

    // Série: precisa da prévia carregada e sem data com conflito marcada.
    if (!isEdit.value && form.value.use_recurrence) {
        if (seriesLoading.value) return;
        if (seriesSelected.value.length < 2) {
            seriesError.value = props.t.series_need_two ?? 'A série precisa de pelo menos 2 sessões.';
            return;
        }
        if (seriesConflicts.value.length > 0) {
            seriesError.value = props.t.series_remove_conflicts ?? 'Desmarque as datas com conflito para salvar.';
            return;
        }
    }

    saving.value = true;
    errors.value = {};

    // 1) Cadastro do paciente preenchido/alterado nas abas → salva ANTES do
    //    agendamento (cria ou atualiza) e vincula. Erro de validação leva
    //    direto pra aba com problema, sem perder nada do agendamento.
    if (patientForm.isDirty) {
        const result = await savePatient(form.value.patient_id || null);

        if (!result.ok) {
            activeTab.value = firstPatientTabWithError();
            saving.value = false;
            return;
        }

        form.value.patient_id = result.patient.id;
        form.value.full_name = result.patient.full_name || form.value.full_name;
        if (!form.value.cellphone && result.patient.cellphone) form.value.cellphone = result.patient.cellphone;
        if (!form.value.telephone && result.patient.telephone) form.value.telephone = result.patient.telephone;
        patientSearch.value = result.patient.full_name || patientSearch.value;
    }

    // 2) Agendamento (fluxo original) — série: datas 2..N que ficaram na prévia
    // (o servidor revalida tudo e grava tudo ou nada).
    const useSeries = !isEdit.value && form.value.use_recurrence;
    const body = {
        doctor_id: form.value.doctor_id,
        patient_id: form.value.patient_id,
        full_name: form.value.full_name,
        date_time: form.value.date_time || null,
        telephone: form.value.telephone,
        cellphone: form.value.cellphone,
        cellphone_whatsapp: form.value.cellphone_whatsapp,
        notes: form.value.notes,
        covenant_id: form.value.covenant_id,
        visit_id: form.value.visit_id,
        specialty_area: form.value.specialty_area,
        resource_ids: form.value.resource_ids,
        waiting_list_id: form.value.waiting_list_id,
        is_fit_in: form.value.is_fit_in,
        ...(useSeries ? { series_dates: seriesSelected.value.slice(1).map((session) => session.date_time) } : {}),
    };

    const res = await fetch(submitUrl.value, {
        method: isEdit.value ? 'PUT' : 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
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
                <button
                    class="nav-link"
                    :class="{ active: activeTab === 'schedule', 'text-danger': Object.keys(errors).length > 0 }"
                    type="button"
                    @click="activeTab = 'schedule'"
                >
                    <i class="ti ti-calendar-event me-1"></i>Agendamento
                    <i v-if="Object.keys(errors).length > 0" class="ti ti-alert-circle text-danger ms-1"></i>
                </button>
            </li>
            <li v-for="tab in patientTabs" :key="tab.key" class="nav-item">
                <button
                    class="nav-link"
                    :class="{
                        active: activeTab === tab.key,
                        'text-danger': tabHasErrors[tab.key],
                        'text-primary fw-semibold':
                            !tabHasErrors[tab.key] && patientTabsActive && tabIncomplete[tab.key],
                    }"
                    type="button"
                    @click="activeTab = tab.key"
                >
                    <i :class="tab.icon" class="me-1"></i>{{ tab.label }}
                    <i v-if="tabHasErrors[tab.key]" class="ti ti-alert-circle text-danger ms-1"></i>
                    <i
                        v-else-if="patientTabsActive && tabIncomplete[tab.key]"
                        class="ti ti-circle-dot text-primary ms-1"
                        style="font-size: 0.5rem; vertical-align: middle"
                    ></i>
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
                    <SearchSelect
                        v-model="form.doctor_id"
                        :options="doctors"
                        :placeholder="t.form_select"
                        :clearable="false"
                        :invalid="!!errors.doctor_id"
                    />
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

                    <!-- Encaixe: paciente a mais no turno (pode cair em horário
                         ocupado/fora do intervalo; teto por médico e turno). -->
                    <div class="form-check mb-2">
                        <input
                            id="schedule-fit-in"
                            v-model="form.is_fit_in"
                            type="checkbox"
                            class="form-check-input"
                            data-test="fit-in-toggle"
                        />
                        <label for="schedule-fit-in" class="form-check-label fw-semibold">
                            <i class="ti ti-arrow-autofit-height me-1 text-purple" aria-hidden="true"></i
                            >{{ t.form_fit_in ?? 'Encaixe' }}
                        </label>
                        <div class="form-text small mt-0">{{ t.form_fit_in_hint }}</div>
                    </div>

                    <SlotPicker
                        v-if="open"
                        ref="slotPickerRef"
                        v-model="form.date_time"
                        :doctor-id="form.doctor_id"
                        :schedule-id="editSchedule?.id ?? null"
                        :allow-occupied="form.is_fit_in"
                        :t="t"
                    />
                </div>

                <!-- ── Paciente ──────────────────────────────────────────────── -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">{{ t.form_patient }}</label>
                    <div class="position-relative">
                        <input
                            v-model="patientSearch"
                            type="text"
                            class="form-control"
                            :placeholder="t.form_patient_search"
                            autocomplete="off"
                            @input="onPatientInput"
                        />
                        <button
                            v-if="form.patient_id"
                            type="button"
                            class="btn btn-sm btn-outline-secondary position-absolute end-0 top-0 mt-1 me-1"
                            @click="clearPatient"
                        >
                            <i class="fas fa-times"></i>
                        </button>
                        <ul
                            v-if="patientResults.length > 0"
                            class="list-group position-absolute w-100 shadow-sm"
                            style="z-index: 1060; max-height: 200px; overflow-y: auto"
                        >
                            <li
                                v-for="p in patientResults"
                                :key="p.id"
                                class="list-group-item list-group-item-action py-2 px-3"
                                style="cursor: pointer"
                                @mousedown.prevent="selectPatient(p)"
                            >
                                <div class="fw-semibold small">{{ p.full_name }}</div>
                                <div class="text-muted" style="font-size: 0.75rem">
                                    {{ p.cellphone || p.telephone || '—' }} &bull; {{ p.code }}
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div class="mt-1">
                        <!-- Leva pras abas de cadastro na MESMA janela (aproveita o
                         que já foi digitado aqui) — sem sair da Agenda. -->
                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 text-decoration-none"
                            @click="goToPatientTabs"
                        >
                            <i class="fas fa-plus me-1"></i>{{ t.form_register }}
                        </button>
                    </div>
                </div>

                <!-- Nome livre (sem paciente vinculado) -->
                <div v-if="!form.patient_id" class="mb-3">
                    <label class="form-label fw-semibold">{{ t.form_full_name }}</label>
                    <input
                        v-model="form.full_name"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': errors.full_name }"
                    />
                    <div v-if="errors.full_name" class="invalid-feedback">{{ errors.full_name[0] }}</div>
                </div>

                <!-- ── Contato (preenchido ao escolher o paciente) ──────────── -->
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold" for="schedule-telephone">{{ t.form_telephone }}</label>
                        <input
                            id="schedule-telephone"
                            v-model="form.telephone"
                            v-mask="'phone'"
                            type="text"
                            inputmode="numeric"
                            class="form-control"
                            placeholder="(00) 0000-0000"
                        />
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold" for="schedule-cellphone">{{ t.form_cellphone }}</label>
                        <input
                            id="schedule-cellphone"
                            v-model="form.cellphone"
                            v-mask="'phone'"
                            type="text"
                            inputmode="numeric"
                            class="form-control"
                            placeholder="(00) 00000-0000"
                        />
                        <div class="form-check mt-1">
                            <input
                                id="whatsapp"
                                v-model="form.cellphone_whatsapp"
                                type="checkbox"
                                class="form-check-input"
                            />
                            <label for="whatsapp" class="form-check-label small">
                                <i class="fab fa-whatsapp text-success me-1"></i>{{ t.form_whatsapp }}
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ── Convênio + Tipo de consulta + Especialidade/Área ─────────
                     "Tipo de atendimento" (enum ScheduleAttendanceType) saiu:
                     repetia as opções do "Tipo de consulta" (catálogo
                     visit_types), que é o que alimenta faturamento/repasse. -->
                <div class="row g-2 mb-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">{{ t.form_covenant }}</label>
                        <SearchSelect
                            v-model="form.covenant_id"
                            :options="covenantOptions"
                            :placeholder="t.form_none"
                            :invalid="!!errors.covenant_id"
                        />
                        <div v-if="errors.covenant_id" class="invalid-feedback d-block">
                            {{ errors.covenant_id[0] }}
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">{{ t.form_visit_type }}</label>
                        <SearchSelect v-model="form.visit_id" :options="visitTypes" :placeholder="t.form_none" />
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">{{ t.form_specialty_area }}</label>
                        <SearchSelect
                            v-model="form.specialty_area"
                            :options="specialties"
                            :placeholder="t.form_none"
                            :invalid="!!errors.specialty_area"
                        />
                        <div v-if="errors.specialty_area" class="invalid-feedback d-block">
                            {{ errors.specialty_area[0] }}
                        </div>
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

                    <p v-else-if="resourcesLoaded && resources.length === 0" class="text-muted small mb-0">
                        <i class="fas fa-info-circle me-1"></i>{{ t.form_no_resources }}
                    </p>

                    <div v-else class="d-flex flex-wrap gap-3">
                        <div v-for="r in resources" :key="r.id" class="form-check">
                            <input
                                :id="`res-${r.id}`"
                                v-model="form.resource_ids"
                                type="checkbox"
                                class="form-check-input"
                                :value="r.id"
                                :disabled="!r.available && !form.resource_ids.includes(r.id)"
                            />
                            <label :for="`res-${r.id}`" class="form-check-label small">
                                {{ r.name }}
                                <span
                                    v-if="!r.available && !form.resource_ids.includes(r.id)"
                                    class="badge bg-danger ms-1"
                                    style="font-size: 0.65rem"
                                >
                                    {{ t.form_busy }}
                                </span>
                                <span
                                    v-else-if="r.available && !form.resource_ids.includes(r.id)"
                                    class="badge bg-success ms-1"
                                    style="font-size: 0.65rem"
                                >
                                    {{ t.form_free }}
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ── Observações ───────────────────────────────────────────── -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">{{ t.form_notes }}</label>
                    <textarea
                        v-model="form.notes"
                        class="form-control"
                        rows="3"
                        :placeholder="t.form_notes_ph"
                    ></textarea>
                </div>

                <!-- ── Série de sessões (somente ao criar) ──────────────────────
                     Semanal, mensal ou dias da semana; N sessões ou até a data.
                     Prévia com os conflitos de cada data (médico, intervalo,
                     bloqueio, sala) — a recepção desmarca o que não serve. -->
                <div v-if="!isEdit" class="mb-3" data-test="series-section">
                    <div class="form-check">
                        <input
                            id="use-recurrence"
                            v-model="form.use_recurrence"
                            type="checkbox"
                            class="form-check-input"
                        />
                        <label for="use-recurrence" class="form-check-label fw-semibold">
                            {{ t.form_recurrence }}
                        </label>
                        <div class="form-text small mt-0">{{ t.form_series_hint }}</div>
                    </div>
                    <div v-if="form.use_recurrence" class="mt-2 row g-2">
                        <div class="col-12 col-sm-6">
                            <label class="form-label small">{{ t.form_rec_freq }}</label>
                            <SearchSelect
                                v-model="form.recurrence_type"
                                :options="recurrenceTypeOptions"
                                :value-key="'value'"
                                :label-key="'label'"
                                :clearable="false"
                                :searchable="false"
                            />
                        </div>
                        <div class="col-12 col-sm-6">
                            <span class="form-label small d-block">{{ t.form_rec_end ?? 'Termina' }}</span>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <div class="form-check form-check-inline me-0">
                                    <input
                                        id="rec-end-count"
                                        v-model="form.recurrence_end"
                                        class="form-check-input"
                                        type="radio"
                                        value="count"
                                    />
                                    <label class="form-check-label small" for="rec-end-count">{{
                                        t.form_rec_after ?? 'Após'
                                    }}</label>
                                </div>
                                <input
                                    v-model.number="form.recurrence_count"
                                    type="number"
                                    min="2"
                                    :max="SERIES_MAX"
                                    class="form-control form-control-sm rec-count-input"
                                    :disabled="form.recurrence_end !== 'count'"
                                    :aria-label="t.form_rec_sessions ?? 'sessões'"
                                />
                                <span class="small text-muted">{{ t.form_rec_sessions ?? 'sessões' }}</span>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
                                <div class="form-check form-check-inline me-0">
                                    <input
                                        id="rec-end-until"
                                        v-model="form.recurrence_end"
                                        class="form-check-input"
                                        type="radio"
                                        value="until"
                                    />
                                    <label class="form-check-label small" for="rec-end-until">{{
                                        t.form_rec_until
                                    }}</label>
                                </div>
                                <input
                                    v-model="form.recurrence_until"
                                    type="date"
                                    class="form-control form-control-sm rec-until-input"
                                    :disabled="form.recurrence_end !== 'until'"
                                    :aria-label="t.form_rec_until"
                                />
                            </div>
                        </div>

                        <div v-if="form.recurrence_type === 'weekdays'" class="col-12">
                            <span class="form-label small d-block">{{ t.form_rec_weekdays ?? 'Dias da semana' }}</span>
                            <div class="d-flex flex-wrap gap-1" role="group" :aria-label="t.form_rec_weekdays">
                                <button
                                    v-for="option in weekdayOptions"
                                    :key="option.day"
                                    type="button"
                                    class="btn btn-sm"
                                    :class="
                                        form.recurrence_weekdays.includes(option.day)
                                            ? 'btn-primary'
                                            : 'btn-outline-secondary'
                                    "
                                    :aria-pressed="form.recurrence_weekdays.includes(option.day) ? 'true' : 'false'"
                                    :data-weekday="option.day"
                                    @click="toggleWeekday(option.day)"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Prévia -->
                        <div class="col-12" data-test="series-preview">
                            <div v-if="!form.date_time" class="text-muted small">
                                <i class="fas fa-info-circle me-1"></i
                                >{{ t.series_pick_time ?? 'Escolha o horário da 1ª sessão.' }}
                            </div>
                            <div v-else-if="seriesLoading" class="small text-muted d-flex align-items-center gap-2">
                                <span class="spinner-border spinner-border-sm"></span
                                >{{ t.series_loading ?? 'Calculando datas…' }}
                            </div>
                            <template v-else-if="seriesPreview.length">
                                <div class="d-flex justify-content-between align-items-baseline small mb-1">
                                    <strong>{{
                                        (t.series_summary ?? ':count sessões').replace(':count', seriesSelected.length)
                                    }}</strong>
                                    <span v-if="seriesConflicts.length" class="text-danger">
                                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                        >{{
                                            (t.series_conflicts_count ?? ':count com conflito').replace(
                                                ':count',
                                                seriesConflicts.length,
                                            )
                                        }}
                                    </span>
                                </div>
                                <ul class="list-group list-group-flush series-preview-list border rounded">
                                    <li
                                        v-for="(session, index) in seriesPreview"
                                        :key="session.date_time"
                                        class="list-group-item d-flex align-items-start gap-2 py-1 px-2"
                                        :class="{
                                            'series-removed': index > 0 && seriesRemoved.includes(session.date_time),
                                            'series-conflict': session.errors.length > 0,
                                        }"
                                        :data-session="session.number"
                                    >
                                        <input
                                            :id="`series-${index}`"
                                            type="checkbox"
                                            class="form-check-input mt-1 flex-shrink-0"
                                            :checked="index === 0 || !seriesRemoved.includes(session.date_time)"
                                            :disabled="index === 0"
                                            :title="index === 0 ? t.series_first_fixed : undefined"
                                            @change="toggleSession(session)"
                                        />
                                        <label :for="`series-${index}`" class="small flex-grow-1 mb-0">
                                            <span class="fw-semibold">{{ session.number }}.</span>
                                            {{ sessionDateLabel(session.date_time) }}
                                            <span v-if="session.errors.length" class="d-block text-danger">
                                                <i class="ti ti-alert-circle me-1" aria-hidden="true"></i
                                                >{{ session.errors[0] }}
                                            </span>
                                        </label>
                                        <i
                                            v-if="!session.errors.length"
                                            class="ti ti-circle-check text-success mt-1"
                                            :title="t.series_ok ?? 'Livre'"
                                            aria-hidden="true"
                                        ></i>
                                    </li>
                                </ul>
                                <p v-if="seriesTruncated" class="small text-warning-emphasis mt-1 mb-0">
                                    <i class="ti ti-info-circle me-1"></i
                                    >{{
                                        (t.series_truncated ?? 'Limite de :max sessões por série.').replace(
                                            ':max',
                                            SERIES_MAX,
                                        )
                                    }}
                                </p>
                            </template>
                            <div v-if="seriesError || errors.series_dates" class="text-danger small mt-1" role="alert">
                                <i class="fas fa-exclamation-circle me-1"></i
                                >{{ seriesError || errors.series_dates?.[0] }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- /aba Agendamento -->

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
                            Editando o cadastro de <strong>{{ patientSearch || form.full_name }}</strong> — as
                            alterações são salvas junto com o agendamento.
                        </span>
                        <span v-else>
                            Novo paciente — o cadastro é criado e vinculado ao clicar em
                            <strong>{{ t.form_save_link }}</strong
                            >.
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
                        :plan-option="planOption"
                        :covenant-option="covenantOption"
                    />
                </div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-secondary" @click="emit('close')">
                {{ t.form_cancel }}
            </button>
            <button
                type="button"
                class="btn btn-primary px-4"
                :disabled="saving || patientForm.processing"
                @click="onSubmit"
            >
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

.rec-count-input {
    width: 4.5rem;
}

.rec-until-input {
    width: 10rem;
}

.series-preview-list {
    max-height: 220px;
    overflow-y: auto;
}

.series-preview-list .series-removed label {
    text-decoration: line-through;
    color: var(--bs-secondary-color);
}

.series-preview-list .series-conflict:not(.series-removed) {
    background: rgba(220, 53, 69, 0.06);
}

.text-purple {
    color: #7c3aed;
}

[data-bs-theme='dark'] .series-preview-list .series-conflict:not(.series-removed) {
    background: rgba(248, 113, 113, 0.12);
}
</style>
