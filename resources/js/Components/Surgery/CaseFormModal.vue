<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import Cid10Picker from '@/Components/Panel/Cid10Picker.vue';
import PatientSearchField from '@/Components/Surgery/PatientSearchField.vue';

/**
 * Pedido cirúrgico: criar (POST) ou editar (PUT) o caso. Quatro abas:
 *  - Dados gerais: paciente (só na criação), cirurgião, cirurgia, olho, regime,
 *    local, convênio, diagnósticos e indicação.
 *  - Procedimentos e OPME: o que a operadora autoriza.
 *  - Planejamento (só médico): técnica, anestesia e LIO planejada por olho. O
 *    sistema NÃO calcula a potência — registra o que o cirurgião informa.
 *  - Equipe e exames.
 *
 * O servidor decide o que cada estado aceita (`editable`): grupos travados
 * ficam desabilitados aqui, mas a regra é a do servidor (revisão esperada +
 * relida sob trava).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    /** Cabeçalho do caso (SurgicalCaseView::detail) — null = criação. */
    caseData: { type: Object, default: null },
    /** Seção "request" (procedimentos, OPME, LIO, exames, equipe) — só na edição. */
    request: { type: Object, default: null },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
    currentDoctorId: { type: String, default: null },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const ui = computed(() => props.t.ui?.form ?? {});
const isEdit = computed(() => Boolean(props.caseData));
const editable = computed(() => props.caseData?.editable ?? { request: true, planning: true, any: true });

const FORMULAS = ['SRK/T', 'Haigis', 'Holladay 2', 'Hoffer Q', 'Barrett Universal II', 'Kane', 'EVO', 'Olsen'];
const EXAM_ROLES = ['biometry', 'topography', 'oct', 'other'];

const tab = ref('general');
const patient = ref(null);
const patientExams = ref([]);
const procedureTerm = ref('');
const procedureResults = ref([]);
const confirmClose = ref(false);

const form = useForm(blank());

function blank() {
    return {
        patient_id: '',
        surgeon_doctor_id: '',
        title: '',
        eye: 'od',
        surgery_type_id: '',
        covenant_id: '',
        covenant_card_number: '',
        technique: '',
        anesthesia: '',
        regime: 'ambulatory',
        urgency: 'elective',
        estimated_duration_minutes: '',
        surgery_facility_id: '',
        cid_codes: [],
        clinical_indication: '',
        days_requested: 1,
        needs_opme: false,
        notes: '',
        expected_revision: null,
        procedures: [],
        materials: [],
        iol_plans: [],
        exams: [],
        team: [],
    };
}

function fill() {
    const c = props.caseData;
    const r = props.request ?? {};
    const base = blank();

    if (!c) {
        base.surgeon_doctor_id = props.currentDoctorId ?? '';
        Object.assign(form, base);
        form.defaults(base);
        form.reset();
        patient.value = null;
        patientExams.value = [];
        return;
    }

    const data = {
        ...base,
        surgeon_doctor_id: c.surgeon?.id ?? '',
        title: c.title ?? '',
        eye: c.eye_value ?? 'od',
        surgery_type_id: c.surgery_type_id ?? '',
        covenant_id: c.covenant_id ?? '',
        covenant_card_number: c.covenant_card_number ?? '',
        technique: c.technique ?? '',
        anesthesia: c.anesthesia?.value ?? '',
        regime: c.regime_value ?? 'ambulatory',
        urgency: c.urgency_value ?? 'elective',
        estimated_duration_minutes: c.estimated_duration ?? '',
        surgery_facility_id: c.facility_id ?? '',
        cid_codes: (c.cid_codes ?? []).map((x) => ({ ...x })),
        clinical_indication: c.clinical_indication ?? '',
        days_requested: c.days_requested ?? 1,
        needs_opme: Boolean(c.needs_opme),
        notes: c.notes ?? '',
        expected_revision: c.revision ?? null,
        procedures: (r.procedures ?? []).map(({ procedure_id, tuss_code, description, quantity, access_route }) => ({
            procedure_id,
            tuss_code: tuss_code ?? '',
            description,
            quantity,
            access_route: access_route ?? '',
        })),
        materials: (r.materials ?? []).map((m) => ({
            entity_product_id: m.entity_product_id,
            description: m.description,
            quantity: m.quantity,
            manufacturer: m.manufacturer ?? '',
            anvisa_registration: m.anvisa_registration ?? '',
            manufacturer_ref: m.manufacturer_ref ?? '',
            operating_authorization: m.operating_authorization ?? '',
            manufacturer_option: m.manufacturer_option ?? '',
            unit_value: m.unit_value ?? '',
            technical_justification: m.technical_justification ?? '',
        })),
        iol_plans: (r.iol_plans ?? []).map((p) => ({
            eye: p.eye,
            entity_product_id: p.entity_product_id,
            model_label: p.model_label,
            power: p.power,
            cylinder: p.cylinder ?? '',
            axis: p.axis ?? '',
            formula: p.formula ?? '',
            a_constant: p.a_constant ?? '',
            target_refraction: p.target_refraction ?? '',
            predicted_refraction: p.predicted_refraction ?? '',
            notes: p.notes ?? '',
        })),
        exams: (r.exams ?? []).map((e) => ({ patient_exam_id: e.patient_exam_id, role: e.role, notes: e.notes ?? '' })),
        team: (r.team ?? []).map((m) => ({
            role: m.role,
            doctor_id: m.doctor_id ?? '',
            name: m.name,
            registry: m.registry ?? '',
        })),
    };

    Object.assign(form, data);
    form.defaults(data);
    form.reset();
    patient.value = c.patient ? { id: c.patient.id, full_name: c.patient.name, code: c.patient.code } : null;
    loadExams(c.patient?.id);
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        tab.value = 'general';
        form.clearErrors();
        procedureTerm.value = '';
        procedureResults.value = [];
        fill();
    },
    { immediate: true },
);

watch(patient, (p) => {
    form.patient_id = p?.id ?? '';

    if (!isEdit.value) {
        form.exams = [];
        loadExams(p?.id);

        const covenantId = p?.covenant_id;
        if (covenantId && (props.options.covenants ?? []).some((c) => String(c.id) === String(covenantId))) {
            form.covenant_id = covenantId;
        }
    }
});

async function loadExams(patientId) {
    patientExams.value = [];
    if (!patientId || !props.urls.patient_exams_base) return;

    try {
        const res = await fetch(props.urls.patient_exams_base.replace('__ID__', patientId), {
            headers: { Accept: 'application/json' },
        });
        patientExams.value = res.ok ? await res.json() : [];
    } catch {
        patientExams.value = [];
    }
}

// ── Regras de tela ────────────────────────────────────────────────────────
const needsInpatient = computed(() => form.regime !== 'ambulatory');
const showPlanning = computed(() => props.can.perform);
const requestLocked = computed(() => isEdit.value && !editable.value.request);
const planningLocked = computed(() => isEdit.value && !editable.value.planning);
const anyLocked = computed(() => isEdit.value && !editable.value.any);

watch(
    () => form.surgery_type_id,
    (id) => {
        const type = (props.options.surgery_types ?? []).find((x) => String(x.id) === String(id));
        if (type && !form.title.trim()) form.title = type.name;
    },
);

const facilityOptions = computed(() => props.options.facilities ?? []);

function err(path) {
    return form.errors[path] ?? '';
}

const tabErrors = computed(() => {
    const keys = Object.keys(form.errors);
    const starts = (prefixes) => keys.some((k) => prefixes.some((p) => k === p || k.startsWith(`${p}.`)));

    return {
        general: starts([
            'patient_id',
            'surgeon_doctor_id',
            'title',
            'eye',
            'surgery_type_id',
            'covenant_id',
            'covenant_card_number',
            'regime',
            'urgency',
            'estimated_duration_minutes',
            'surgery_facility_id',
            'cid_codes',
            'clinical_indication',
            'days_requested',
            'notes',
        ]),
        request: starts(['procedures', 'materials', 'needs_opme', 'request']),
        planning: starts(['technique', 'anesthesia', 'iol_plans']),
        team: starts(['exams', 'team']),
    };
});

// ── Procedimentos ─────────────────────────────────────────────────────────
let procedureTimer = null;
let procedureTicket = 0;

function onProcedureInput() {
    clearTimeout(procedureTimer);
    if (procedureTerm.value.trim().length < 2) {
        procedureResults.value = [];
        return;
    }
    procedureTimer = setTimeout(searchProcedures, 300);
}

async function searchProcedures() {
    const ticket = ++procedureTicket;
    try {
        const res = await fetch(`${props.urls.procedure_search}?q=${encodeURIComponent(procedureTerm.value.trim())}`, {
            headers: { Accept: 'application/json' },
        });
        const json = res.ok ? await res.json() : [];
        if (ticket === procedureTicket) procedureResults.value = Array.isArray(json) ? json : [];
    } catch {
        if (ticket === procedureTicket) procedureResults.value = [];
    }
}

function addProcedure(found = null) {
    form.procedures.push({
        procedure_id: found?.id ?? null,
        tuss_code: found?.code ?? '',
        description: found?.name ?? '',
        quantity: 1,
        access_route: '',
    });
    procedureTerm.value = '';
    procedureResults.value = [];
}

function addMaterial() {
    form.materials.push({
        entity_product_id: null,
        description: '',
        quantity: 1,
        manufacturer: '',
        anvisa_registration: '',
        manufacturer_ref: '',
        operating_authorization: '',
        manufacturer_option: '',
        unit_value: '',
        technical_justification: '',
    });
}

/** O olho já é usado pelo plano de OUTRA linha? (um plano de LIO por olho) */
function eyeTaken(index, eye) {
    return form.iol_plans.some((plan, i) => i !== index && plan.eye === eye);
}

function addIol() {
    const used = new Set(form.iol_plans.map((p) => p.eye));
    const eye = form.eye === 'oe' ? 'oe' : 'od';
    form.iol_plans.push({
        eye: used.has(eye) ? (eye === 'od' ? 'oe' : 'od') : eye,
        entity_product_id: null,
        model_label: '',
        power: '',
        cylinder: '',
        axis: '',
        formula: '',
        a_constant: '',
        target_refraction: '',
        predicted_refraction: '',
        notes: '',
    });
}

function addTeam() {
    form.team.push({ role: 'assistant', doctor_id: '', name: '', registry: '' });
}

function toggleExam(exam, on) {
    if (on) {
        if (!form.exams.some((e) => e.patient_exam_id === exam.id)) {
            form.exams.push({ patient_exam_id: exam.id, role: 'other', notes: '' });
        }
        return;
    }
    form.exams = form.exams.filter((e) => e.patient_exam_id !== exam.id);
}

const examSelected = (id) => form.exams.find((e) => e.patient_exam_id === id);

// ── Envio ─────────────────────────────────────────────────────────────────
function cleaned(data) {
    const blankToNull = (v) => (v === '' || v === undefined ? null : v);

    const payload = {
        ...data,
        surgery_type_id: blankToNull(data.surgery_type_id),
        covenant_id: blankToNull(data.covenant_id),
        covenant_card_number: blankToNull(data.covenant_card_number),
        technique: blankToNull(data.technique),
        anesthesia: blankToNull(data.anesthesia),
        estimated_duration_minutes: blankToNull(data.estimated_duration_minutes),
        surgery_facility_id: blankToNull(data.surgery_facility_id),
        clinical_indication: blankToNull(data.clinical_indication),
        notes: blankToNull(data.notes),
        needs_opme: Boolean(data.needs_opme),
        materials: data.needs_opme ? data.materials : [],
        team: data.team.map((m) => ({ ...m, doctor_id: blankToNull(m.doctor_id), registry: blankToNull(m.registry) })),
    };

    if (isEdit.value) delete payload.patient_id;
    if (!props.can.perform) {
        delete payload.technique;
        delete payload.anesthesia;
        delete payload.iol_plans;
    }
    if (requestLocked.value) {
        for (const key of [
            'title',
            'eye',
            'surgery_type_id',
            'covenant_id',
            'covenant_card_number',
            'regime',
            'urgency',
            'cid_codes',
            'clinical_indication',
            'days_requested',
            'needs_opme',
            'procedures',
            'materials',
        ]) {
            delete payload[key];
        }
    }

    return payload;
}

function save() {
    if (form.processing) return;

    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: () => {
            const first = Object.entries(tabErrors.value).find(([, hasError]) => hasError);
            if (first) tab.value = first[0];
        },
    };

    form.transform(cleaned);

    if (isEdit.value) {
        form.put(props.urls.update, options);
    } else {
        form.post(props.urls.store, options);
    }
}

function requestClose() {
    if (form.isDirty && !confirmClose.value) {
        confirmClose.value = true;
        return;
    }
    confirmClose.value = false;
    emit('close');
}

onBeforeUnmount(() => {
    clearTimeout(procedureTimer);
});

const examName = (exam) => [exam.name, exam.performed_at, exam.laterality].filter(Boolean).join(' · ');
</script>

<template>
    <CenteredModal :open="open" size="xl" @close="requestClose">
        <template #header>{{ isEdit ? ui.edit_title : ui.new_title }}</template>

        <template #tabs>
            <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                <li
                    v-for="key in ['general', 'request', 'planning', 'team']"
                    :key="key"
                    class="nav-item"
                    role="presentation"
                >
                    <button
                        v-if="key !== 'planning' || showPlanning"
                        type="button"
                        class="nav-link"
                        :class="{ active: tab === key }"
                        role="tab"
                        :aria-selected="tab === key"
                        :data-testid="`surgery-form-tab-${key}`"
                        @click="tab = key"
                    >
                        {{ ui[`tab_${key}`] }}
                        <i v-if="tabErrors[key]" class="ti ti-alert-circle text-danger ms-1" aria-hidden="true"></i>
                        <span v-if="tabErrors[key]" class="visually-hidden">{{ ui.tab_has_errors }}</span>
                    </button>
                </li>
            </ul>
        </template>

        <form class="p-2" novalidate @submit.prevent="save">
            <div v-if="confirmClose" class="alert alert-warning d-flex align-items-center gap-2" role="alert">
                <span class="me-auto">{{ ui.discard_confirm }}</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="confirmClose = false">
                    {{ ui.keep_editing }}
                </button>
                <button type="button" class="btn btn-sm btn-warning" @click="requestClose">{{ ui.discard }}</button>
            </div>

            <div v-if="anyLocked" class="alert alert-secondary" role="status">{{ ui.locked_all }}</div>

            <fieldset :disabled="anyLocked || form.processing">
                <!-- ── Dados gerais ─────────────────────────────────────── -->
                <div v-show="tab === 'general'" class="row g-3">
                    <div class="col-12">
                        <PatientSearchField
                            v-model="patient"
                            :search-url="urls.patient_search"
                            :label="ui.patient"
                            :placeholder="ui.patient_placeholder"
                            :no-results="ui.patient_none"
                            :change-label="ui.patient_change"
                            :disabled="isEdit"
                            :invalid="Boolean(err('patient_id'))"
                        />
                        <div v-if="err('patient_id')" class="invalid-feedback d-block">{{ err('patient_id') }}</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-medium" for="sf-surgeon">{{ ui.surgeon }}</label>
                        <select
                            id="sf-surgeon"
                            v-model="form.surgeon_doctor_id"
                            class="form-select"
                            :class="{ 'is-invalid': err('surgeon_doctor_id') }"
                            :disabled="planningLocked"
                        >
                            <option value="">{{ ui.surgeon_placeholder }}</option>
                            <option v-for="d in options.surgeons" :key="d.id" :value="d.id">
                                {{ d.name }}<template v-if="d.registry"> — {{ d.registry }}</template>
                            </option>
                        </select>
                        <div class="invalid-feedback">{{ err('surgeon_doctor_id') }}</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-medium" for="sf-type">{{ ui.surgery_type }}</label>
                        <select
                            id="sf-type"
                            v-model="form.surgery_type_id"
                            class="form-select"
                            :disabled="requestLocked"
                        >
                            <option value="">{{ ui.surgery_type_placeholder }}</option>
                            <option v-for="type in options.surgery_types" :key="type.id" :value="type.id">
                                {{ type.name }}
                            </option>
                        </select>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label fw-medium" for="sf-title">{{ ui.title }}</label>
                        <input
                            id="sf-title"
                            v-model="form.title"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': err('title') }"
                            maxlength="200"
                            :disabled="requestLocked"
                            data-testid="surgery-form-title"
                        />
                        <div class="invalid-feedback">{{ err('title') }}</div>
                    </div>

                    <div class="col-md-4">
                        <span id="sf-eye-label" class="form-label fw-medium d-block">{{ ui.eye }}</span>
                        <div class="btn-group w-100" role="group" aria-labelledby="sf-eye-label">
                            <template v-for="eye in options.eyes" :key="eye.value">
                                <input
                                    :id="`sf-eye-${eye.value}`"
                                    v-model="form.eye"
                                    type="radio"
                                    class="btn-check"
                                    :value="eye.value"
                                    name="sf-eye"
                                    :disabled="requestLocked"
                                />
                                <label class="btn btn-outline-primary" :for="`sf-eye-${eye.value}`">{{
                                    eye.short
                                }}</label>
                            </template>
                        </div>
                        <div v-if="err('eye')" class="invalid-feedback d-block">{{ err('eye') }}</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-medium" for="sf-regime">{{ ui.regime }}</label>
                        <select id="sf-regime" v-model="form.regime" class="form-select" :disabled="requestLocked">
                            <option v-for="r in options.regimes" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium" for="sf-urgency">{{ ui.urgency }}</label>
                        <select id="sf-urgency" v-model="form.urgency" class="form-select" :disabled="requestLocked">
                            <option v-for="u in options.urgencies" :key="u.value" :value="u.value">
                                {{ u.label }}
                            </option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium" for="sf-duration">{{ ui.duration }}</label>
                        <input
                            id="sf-duration"
                            v-model="form.estimated_duration_minutes"
                            type="number"
                            min="5"
                            max="720"
                            class="form-control"
                            :class="{ 'is-invalid': err('estimated_duration_minutes') }"
                            :disabled="planningLocked"
                        />
                        <div class="invalid-feedback">{{ err('estimated_duration_minutes') }}</div>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label fw-medium" for="sf-facility">{{ ui.facility }}</label>
                        <select
                            id="sf-facility"
                            v-model="form.surgery_facility_id"
                            class="form-select"
                            :class="{ 'is-invalid': err('surgery_facility_id') }"
                            :disabled="planningLocked"
                        >
                            <option value="">{{ ui.facility_placeholder }}</option>
                            <option v-for="f in facilityOptions" :key="f.id" :value="f.id">{{ f.name }}</option>
                        </select>
                        <div class="invalid-feedback">{{ err('surgery_facility_id') }}</div>
                        <div v-if="!facilityOptions.length" class="form-text">
                            {{ ui.facility_none }}
                            <a v-if="urls.facilities" :href="urls.facilities">{{ ui.facility_configure }}</a>
                        </div>
                    </div>
                    <div v-if="needsInpatient" class="col-md-4">
                        <label class="form-label fw-medium" for="sf-days">{{ ui.days }}</label>
                        <input
                            id="sf-days"
                            v-model.number="form.days_requested"
                            type="number"
                            min="1"
                            max="60"
                            class="form-control"
                            :class="{ 'is-invalid': err('days_requested') }"
                            :disabled="requestLocked"
                        />
                        <div class="invalid-feedback">{{ err('days_requested') }}</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-medium" for="sf-covenant">{{ ui.covenant }}</label>
                        <select
                            id="sf-covenant"
                            v-model="form.covenant_id"
                            class="form-select"
                            :class="{ 'is-invalid': err('covenant_id') }"
                            :disabled="requestLocked"
                        >
                            <option value="">{{ ui.covenant_placeholder }}</option>
                            <option v-for="c in options.covenants" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <div class="invalid-feedback">{{ err('covenant_id') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-medium" for="sf-card">{{ ui.card_number }}</label>
                        <input
                            id="sf-card"
                            v-model="form.covenant_card_number"
                            type="text"
                            class="form-control"
                            maxlength="40"
                            :disabled="requestLocked"
                        />
                    </div>

                    <div class="col-12">
                        <Cid10Picker
                            v-model="form.cid_codes"
                            :search-url="urls.cid_search"
                            :max-items="4"
                            :label="ui.cids"
                            :disabled="requestLocked || anyLocked"
                            :invalid="Boolean(err('cid_codes'))"
                        />
                        <div class="form-text">{{ ui.cid_hint }}</div>
                        <div v-if="err('cid_codes')" class="invalid-feedback d-block">{{ err('cid_codes') }}</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-medium" for="sf-indication">{{ ui.indication }}</label>
                        <textarea
                            id="sf-indication"
                            v-model="form.clinical_indication"
                            class="form-control"
                            rows="3"
                            maxlength="5000"
                            :disabled="requestLocked"
                        ></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-medium" for="sf-notes">{{ ui.notes }}</label>
                        <textarea
                            id="sf-notes"
                            v-model="form.notes"
                            class="form-control"
                            rows="2"
                            maxlength="5000"
                        ></textarea>
                    </div>
                </div>

                <!-- ── Procedimentos e OPME ─────────────────────────────── -->
                <div v-show="tab === 'request'" class="d-grid gap-3">
                    <div v-if="requestLocked" class="alert alert-secondary mb-0" role="status">
                        {{ ui.locked_request }}
                    </div>

                    <section aria-labelledby="sf-procedures">
                        <h3 id="sf-procedures" class="h6">{{ ui.procedures }}</h3>
                        <div v-if="err('request')" class="alert alert-danger py-2">{{ err('request') }}</div>

                        <div class="position-relative mb-2">
                            <input
                                v-model="procedureTerm"
                                type="search"
                                class="form-control"
                                :placeholder="ui.procedure_search_placeholder"
                                :aria-label="ui.procedure_search"
                                :disabled="requestLocked"
                                autocomplete="off"
                                data-testid="surgery-procedure-search"
                                @input="onProcedureInput"
                            />
                            <ul
                                v-if="procedureResults.length"
                                class="list-group position-absolute w-100 shadow-sm"
                                style="z-index: 5; max-height: 220px; overflow-y: auto"
                            >
                                <li v-for="found in procedureResults" :key="found.id" class="list-group-item p-0">
                                    <button
                                        type="button"
                                        class="btn btn-link text-start text-decoration-none w-100 px-3 py-2"
                                        @click="addProcedure(found)"
                                    >
                                        <span class="text-muted small me-2">{{ found.code }}</span
                                        >{{ found.name }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary mb-2"
                            :disabled="requestLocked"
                            @click="addProcedure()"
                        >
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ ui.procedure_add_manual }}
                        </button>

                        <div
                            v-for="(row, i) in form.procedures"
                            :key="i"
                            class="row g-2 align-items-start border rounded p-2 mb-2"
                            data-testid="surgery-procedure-row"
                        >
                            <div class="col-md-2">
                                <label class="form-label small mb-1" :for="`sf-p-tuss-${i}`">{{
                                    ui.procedure_tuss
                                }}</label>
                                <input
                                    :id="`sf-p-tuss-${i}`"
                                    v-model="row.tuss_code"
                                    type="text"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': err(`procedures.${i}.tuss_code`) }"
                                    maxlength="10"
                                    :disabled="requestLocked"
                                />
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-1" :for="`sf-p-desc-${i}`">{{
                                    ui.procedure_description
                                }}</label>
                                <input
                                    :id="`sf-p-desc-${i}`"
                                    v-model="row.description"
                                    type="text"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': err(`procedures.${i}.description`) }"
                                    maxlength="255"
                                    :disabled="requestLocked"
                                />
                                <div class="invalid-feedback">{{ err(`procedures.${i}.description`) }}</div>
                            </div>
                            <div class="col-md-1">
                                <label class="form-label small mb-1" :for="`sf-p-qty-${i}`">{{
                                    ui.procedure_qty
                                }}</label>
                                <input
                                    :id="`sf-p-qty-${i}`"
                                    v-model.number="row.quantity"
                                    type="number"
                                    min="1"
                                    max="99"
                                    class="form-control form-control-sm"
                                    :disabled="requestLocked"
                                />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1" :for="`sf-p-route-${i}`">{{
                                    ui.procedure_route
                                }}</label>
                                <select
                                    :id="`sf-p-route-${i}`"
                                    v-model="row.access_route"
                                    class="form-select form-select-sm"
                                    :disabled="requestLocked"
                                >
                                    <option value="">—</option>
                                    <option v-for="r in ['single', 'multiple', 'same']" :key="r" :value="r">
                                        {{ ui[`route_${r}`] }}
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-1 d-flex align-items-end justify-content-end">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    :disabled="requestLocked"
                                    :title="ui.remove"
                                    :aria-label="ui.remove"
                                    @click="form.procedures.splice(i, 1)"
                                >
                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <p v-if="!form.procedures.length" class="text-muted small mb-0">{{ ui.procedures_empty }}</p>
                    </section>

                    <section aria-labelledby="sf-opme">
                        <div class="form-check form-switch mb-2">
                            <input
                                id="sf-needs-opme"
                                v-model="form.needs_opme"
                                type="checkbox"
                                class="form-check-input"
                                role="switch"
                                :disabled="requestLocked"
                            />
                            <label class="form-check-label fw-medium" for="sf-needs-opme">{{ ui.needs_opme }}</label>
                        </div>

                        <template v-if="form.needs_opme">
                            <h3 id="sf-opme" class="h6">{{ ui.materials }}</h3>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary mb-2"
                                :disabled="requestLocked"
                                @click="addMaterial"
                            >
                                <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ ui.material_add }}
                            </button>

                            <div
                                v-for="(m, i) in form.materials"
                                :key="i"
                                class="border rounded p-2 mb-2"
                                data-testid="surgery-material-row"
                            >
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1" :for="`sf-m-desc-${i}`">{{
                                            ui.material_description
                                        }}</label>
                                        <input
                                            :id="`sf-m-desc-${i}`"
                                            v-model="m.description"
                                            type="text"
                                            class="form-control form-control-sm"
                                            :class="{ 'is-invalid': err(`materials.${i}.description`) }"
                                            maxlength="255"
                                            :disabled="requestLocked"
                                        />
                                        <div class="invalid-feedback">{{ err(`materials.${i}.description`) }}</div>
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label small mb-1" :for="`sf-m-qty-${i}`">{{
                                            ui.material_qty
                                        }}</label>
                                        <input
                                            :id="`sf-m-qty-${i}`"
                                            v-model.number="m.quantity"
                                            type="number"
                                            min="1"
                                            max="99"
                                            class="form-control form-control-sm"
                                            :disabled="requestLocked"
                                        />
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small mb-1" :for="`sf-m-man-${i}`">{{
                                            ui.material_manufacturer
                                        }}</label>
                                        <input
                                            :id="`sf-m-man-${i}`"
                                            v-model="m.manufacturer"
                                            type="text"
                                            class="form-control form-control-sm"
                                            maxlength="120"
                                            :disabled="requestLocked"
                                        />
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1" :for="`sf-m-val-${i}`">{{
                                            ui.material_value
                                        }}</label>
                                        <input
                                            :id="`sf-m-val-${i}`"
                                            v-model="m.unit_value"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="form-control form-control-sm"
                                            :disabled="requestLocked"
                                        />
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small mb-1" :for="`sf-m-anvisa-${i}`">{{
                                            ui.material_anvisa
                                        }}</label>
                                        <input
                                            :id="`sf-m-anvisa-${i}`"
                                            v-model="m.anvisa_registration"
                                            type="text"
                                            class="form-control form-control-sm"
                                            maxlength="15"
                                            :disabled="requestLocked"
                                        />
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small mb-1" :for="`sf-m-ref-${i}`">{{
                                            ui.material_ref
                                        }}</label>
                                        <input
                                            :id="`sf-m-ref-${i}`"
                                            v-model="m.manufacturer_ref"
                                            type="text"
                                            class="form-control form-control-sm"
                                            maxlength="60"
                                            :disabled="requestLocked"
                                        />
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small mb-1" :for="`sf-m-auth-${i}`">{{
                                            ui.material_auth
                                        }}</label>
                                        <input
                                            :id="`sf-m-auth-${i}`"
                                            v-model="m.operating_authorization"
                                            type="text"
                                            class="form-control form-control-sm"
                                            maxlength="40"
                                            :disabled="requestLocked"
                                        />
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-1" :for="`sf-m-just-${i}`">{{
                                            ui.material_justification
                                        }}</label>
                                        <textarea
                                            :id="`sf-m-just-${i}`"
                                            v-model="m.technical_justification"
                                            class="form-control form-control-sm"
                                            rows="2"
                                            maxlength="3000"
                                            :disabled="requestLocked"
                                        ></textarea>
                                    </div>
                                    <div class="col-12 text-end">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            :disabled="requestLocked"
                                            @click="form.materials.splice(i, 1)"
                                        >
                                            <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ ui.remove }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </section>
                </div>

                <!-- ── Planejamento (médico) ────────────────────────────── -->
                <div v-if="showPlanning" v-show="tab === 'planning'" class="d-grid gap-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="sf-anesthesia">{{ ui.anesthesia }}</label>
                            <select
                                id="sf-anesthesia"
                                v-model="form.anesthesia"
                                class="form-select"
                                :disabled="planningLocked"
                            >
                                <option value="">{{ ui.anesthesia_none }}</option>
                                <option v-for="a in options.anesthesias" :key="a.value" :value="a.value">
                                    {{ a.label }}
                                </option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-medium" for="sf-technique">{{ ui.technique }}</label>
                            <textarea
                                id="sf-technique"
                                v-model="form.technique"
                                class="form-control"
                                rows="3"
                                maxlength="5000"
                                :disabled="planningLocked"
                            ></textarea>
                        </div>
                    </div>

                    <section aria-labelledby="sf-iol">
                        <h3 id="sf-iol" class="h6">{{ ui.iol_title }}</h3>
                        <p class="text-muted small">{{ ui.iol_hint }}</p>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary mb-2"
                            :disabled="planningLocked || form.iol_plans.length >= 2"
                            @click="addIol"
                        >
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ ui.iol_add }}
                        </button>

                        <div
                            v-for="(p, i) in form.iol_plans"
                            :key="i"
                            class="border rounded p-2 mb-2"
                            data-testid="surgery-iol-row"
                        >
                            <div class="row g-2">
                                <div class="col-md-2">
                                    <label class="form-label small mb-1" :for="`sf-i-eye-${i}`">{{ ui.iol_eye }}</label>
                                    <select
                                        :id="`sf-i-eye-${i}`"
                                        v-model="p.eye"
                                        class="form-select form-select-sm"
                                        :class="{ 'is-invalid': err(`iol_plans.${i}.eye`) }"
                                        :aria-invalid="err(`iol_plans.${i}.eye`) ? 'true' : undefined"
                                        :aria-describedby="
                                            err(`iol_plans.${i}.eye`) ? `sf-i-eye-${i}-error` : undefined
                                        "
                                        :disabled="planningLocked"
                                    >
                                        <!-- Um plano por olho: o olho que outra linha já usa não se repete. -->
                                        <option value="od" :disabled="eyeTaken(i, 'od')">OD</option>
                                        <option value="oe" :disabled="eyeTaken(i, 'oe')">OE</option>
                                    </select>
                                    <div :id="`sf-i-eye-${i}-error`" class="invalid-feedback">
                                        {{ err(`iol_plans.${i}.eye`) }}
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small mb-1" :for="`sf-i-model-${i}`">{{
                                        ui.iol_model
                                    }}</label>
                                    <input
                                        :id="`sf-i-model-${i}`"
                                        v-model="p.model_label"
                                        type="text"
                                        class="form-control form-control-sm"
                                        :class="{ 'is-invalid': err(`iol_plans.${i}.model_label`) }"
                                        maxlength="150"
                                        :disabled="planningLocked"
                                    />
                                    <div class="invalid-feedback">{{ err(`iol_plans.${i}.model_label`) }}</div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1" :for="`sf-i-power-${i}`">{{
                                        ui.iol_power
                                    }}</label>
                                    <input
                                        :id="`sf-i-power-${i}`"
                                        v-model="p.power"
                                        type="number"
                                        step="0.5"
                                        class="form-control form-control-sm"
                                        :class="{ 'is-invalid': err(`iol_plans.${i}.power`) }"
                                        :disabled="planningLocked"
                                    />
                                    <div class="invalid-feedback">{{ err(`iol_plans.${i}.power`) }}</div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1" :for="`sf-i-a-${i}`">{{
                                        ui.iol_a_constant
                                    }}</label>
                                    <input
                                        :id="`sf-i-a-${i}`"
                                        v-model="p.a_constant"
                                        type="number"
                                        step="0.01"
                                        class="form-control form-control-sm"
                                        :disabled="planningLocked"
                                    />
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1" :for="`sf-i-cyl-${i}`">{{
                                        ui.iol_cylinder
                                    }}</label>
                                    <input
                                        :id="`sf-i-cyl-${i}`"
                                        v-model="p.cylinder"
                                        type="number"
                                        step="0.25"
                                        min="0"
                                        class="form-control form-control-sm"
                                        :disabled="planningLocked"
                                    />
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1" :for="`sf-i-axis-${i}`">{{
                                        ui.iol_axis
                                    }}</label>
                                    <input
                                        :id="`sf-i-axis-${i}`"
                                        v-model="p.axis"
                                        type="number"
                                        min="0"
                                        max="180"
                                        class="form-control form-control-sm"
                                        :disabled="planningLocked"
                                    />
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small mb-1" :for="`sf-i-formula-${i}`">{{
                                        ui.iol_formula
                                    }}</label>
                                    <input
                                        :id="`sf-i-formula-${i}`"
                                        v-model="p.formula"
                                        type="text"
                                        class="form-control form-control-sm"
                                        list="sf-formulas"
                                        maxlength="30"
                                        :disabled="planningLocked"
                                    />
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1" :for="`sf-i-target-${i}`">{{
                                        ui.iol_target
                                    }}</label>
                                    <input
                                        :id="`sf-i-target-${i}`"
                                        v-model="p.target_refraction"
                                        type="number"
                                        step="0.25"
                                        class="form-control form-control-sm"
                                        :disabled="planningLocked"
                                    />
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small mb-1" :for="`sf-i-pred-${i}`">{{
                                        ui.iol_predicted
                                    }}</label>
                                    <input
                                        :id="`sf-i-pred-${i}`"
                                        v-model="p.predicted_refraction"
                                        type="number"
                                        step="0.01"
                                        class="form-control form-control-sm"
                                        :disabled="planningLocked"
                                    />
                                </div>
                                <div class="col-12 text-end">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        :disabled="planningLocked"
                                        @click="form.iol_plans.splice(i, 1)"
                                    >
                                        <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ ui.remove }}
                                    </button>
                                </div>
                            </div>
                        </div>
                        <datalist id="sf-formulas">
                            <option v-for="f in FORMULAS" :key="f" :value="f"></option>
                        </datalist>
                    </section>
                </div>

                <!-- ── Equipe e exames ─────────────────────────────────── -->
                <div v-show="tab === 'team'" class="d-grid gap-3">
                    <section aria-labelledby="sf-team">
                        <h3 id="sf-team" class="h6">{{ ui.team }}</h3>
                        <button type="button" class="btn btn-sm btn-outline-secondary mb-2" @click="addTeam">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ ui.team_add }}
                        </button>
                        <div
                            v-for="(m, i) in form.team"
                            :key="i"
                            class="row g-2 border rounded p-2 mb-2"
                            data-testid="surgery-team-row"
                        >
                            <div class="col-md-3">
                                <label class="form-label small mb-1" :for="`sf-t-role-${i}`">{{ ui.team_role }}</label>
                                <select :id="`sf-t-role-${i}`" v-model="m.role" class="form-select form-select-sm">
                                    <option v-for="r in options.team_roles" :key="r.value" :value="r.value">
                                        {{ r.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1" :for="`sf-t-doc-${i}`">{{ ui.team_doctor }}</label>
                                <select :id="`sf-t-doc-${i}`" v-model="m.doctor_id" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    <option v-for="d in options.surgeons" :key="d.id" :value="d.id">
                                        {{ d.name }}
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1" :for="`sf-t-name-${i}`">{{ ui.team_name }}</label>
                                <input
                                    :id="`sf-t-name-${i}`"
                                    v-model="m.name"
                                    type="text"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': err(`team.${i}.name`) }"
                                    maxlength="150"
                                    :disabled="Boolean(m.doctor_id)"
                                />
                                <div class="invalid-feedback">{{ err(`team.${i}.name`) }}</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-1" :for="`sf-t-reg-${i}`">{{
                                    ui.team_registry
                                }}</label>
                                <input
                                    :id="`sf-t-reg-${i}`"
                                    v-model="m.registry"
                                    type="text"
                                    class="form-control form-control-sm"
                                    maxlength="40"
                                    :disabled="Boolean(m.doctor_id)"
                                />
                            </div>
                            <div class="col-md-1 d-flex align-items-end justify-content-end">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    :title="ui.remove"
                                    :aria-label="ui.remove"
                                    @click="form.team.splice(i, 1)"
                                >
                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </section>

                    <section aria-labelledby="sf-exams">
                        <h3 id="sf-exams" class="h6">{{ ui.exams }}</h3>
                        <p class="text-muted small">{{ ui.exams_hint }}</p>
                        <p v-if="!form.patient_id && !isEdit" class="text-muted small">{{ ui.exams_pick_patient }}</p>
                        <p v-else-if="!patientExams.length" class="text-muted small">{{ ui.exams_none }}</p>
                        <ul v-else class="list-group">
                            <li
                                v-for="exam in patientExams"
                                :key="exam.id"
                                class="list-group-item d-flex align-items-center gap-2"
                            >
                                <input
                                    :id="`sf-exam-${exam.id}`"
                                    type="checkbox"
                                    class="form-check-input mt-0"
                                    :checked="Boolean(examSelected(exam.id))"
                                    @change="toggleExam(exam, $event.target.checked)"
                                />
                                <label class="me-auto" :for="`sf-exam-${exam.id}`">{{ examName(exam) }}</label>
                                <select
                                    v-if="examSelected(exam.id)"
                                    v-model="examSelected(exam.id).role"
                                    class="form-select form-select-sm w-auto"
                                    :aria-label="ui.exam_role"
                                >
                                    <option v-for="r in EXAM_ROLES" :key="r" :value="r">
                                        {{ ui[`exam_role_${r}`] }}
                                    </option>
                                </select>
                            </li>
                        </ul>
                    </section>
                </div>
            </fieldset>
        </form>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" @click="requestClose">{{ ui.cancel }}</button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="form.processing || anyLocked"
                data-testid="surgery-form-save"
                @click="save"
            >
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ form.processing ? ui.saving : ui.save }}
            </button>
        </template>
    </CenteredModal>
</template>
