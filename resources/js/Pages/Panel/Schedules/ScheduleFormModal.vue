<script setup>
import { ref, watch, computed } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

const props = defineProps({
    open:          { type: Boolean, required: true },
    editSchedule:  { type: Object,  default: null },
    prefillData:   { type: Object,  default: null },
    doctors:       { type: Array,   default: () => [] },
    covenants:     { type: Array,   default: () => [] },
    visitTypes:    { type: Array,   default: () => [] },
    storeUrl:      { type: String,  required: true },
    defaultDate:   { type: String,  default: '' },
    t:             { type: Object,  required: true },
});

const emit = defineEmits(['close', 'saved']);

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
    resource_ids:       [],
    waiting_list_id:    '',
    use_recurrence:     false,
    recurrence_type:    'weekly',
    recurrence_until:   '',
});

// ── Patient search ─────────────────────────────────────────────────────────────
const patientSearch   = ref('');
const patientResults  = ref([]);
const patientDebounce = ref(null);
const showQuickReg    = ref(false);
const quickName       = ref('');

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
}

function clearPatient() {
    form.value.patient_id = '';
    form.value.full_name  = '';
    patientSearch.value   = '';
    patientResults.value  = [];
}

async function quickRegister() {
    if (!quickName.value.trim()) return;
    const res = await fetch('/panel/patients/quick', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ full_name: quickName.value.trim() }),
    });
    if (res.ok) {
        const json = await res.json();
        selectPatient({ id: json.id, full_name: json.full_name, cellphone: '', telephone: '' });
        showQuickReg.value = false;
        quickName.value    = '';
    }
}

// ── Slot picker ────────────────────────────────────────────────────────────────
const slotsLoading   = ref(false);
const slots          = ref([]);
const hasSchedule    = ref(false);
const slotInterval   = ref(15);
const selectedDate   = ref('');
const showManualTime = ref(false);

// Gera horários padrão localmente (fallback quando médico não tem escala configurada)
function generateFallbackSlots(date, interval) {
    const result = [];
    let min = 7 * 60; // 07:00
    const end = 19 * 60; // 19:00
    while (min < end) {
        const h    = String(Math.floor(min / 60)).padStart(2, '0');
        const m    = String(min % 60).padStart(2, '0');
        const time = `${h}:${m}`;
        result.push({ time, datetime: `${date}T${time}`, available: true });
        min += interval;
    }
    return result;
}

async function loadSlots() {
    const doctorId = form.value.doctor_id;
    if (!doctorId || !selectedDate.value) { slots.value = []; return; }

    slotsLoading.value = true;
    const qs = new URLSearchParams({ doctor_id: doctorId, date: selectedDate.value });
    if (props.editSchedule?.id) qs.set('schedule_id', props.editSchedule.id);

    try {
        const res = await fetch(`/panel/schedules/slots?${qs}`, {
            headers: { Accept: 'application/json' },
        });
        if (res.ok) {
            const json         = await res.json();
            slotInterval.value = json.interval ?? 15;
            hasSchedule.value  = json.has_schedule ?? false;
            // Se tem escala configurada usa os slots do backend; senão gera localmente
            slots.value = json.has_schedule
                ? (json.slots ?? [])
                : generateFallbackSlots(selectedDate.value, json.interval ?? 15);
        }
    } catch { /**/ }
    slotsLoading.value = false;
}

function selectSlot(slot) {
    form.value.date_time = slot.datetime;
    showManualTime.value = false;
    loadResources();
}

function clearSlot() {
    form.value.date_time  = '';
    resources.value       = [];
    resourcesLoaded.value = false;
}

function slotBtnClass(slot) {
    if (form.value.date_time === slot.datetime) return 'btn-primary';
    if (!slot.available)                        return 'btn-secondary opacity-50';
    return 'btn-outline-primary';
}

const selectedDateTimeLabel = computed(() => {
    if (!form.value.date_time) return '';
    try {
        const locale = window.sessionLocale ?? 'pt-BR';
        const dt     = new Date(form.value.date_time + ':00');
        const d      = dt.toLocaleDateString(locale, { weekday: 'short', day: '2-digit', month: 'short' });
        const h      = dt.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
        return `${d} · ${h}`;
    } catch { return form.value.date_time; }
});

function addDaysToSelected(n) {
    if (!selectedDate.value) return;
    const dt = new Date(selectedDate.value + 'T12:00:00');
    dt.setDate(dt.getDate() + n);
    selectedDate.value = dt.toISOString().substring(0, 10);
    clearSlot();
}

watch(() => form.value.doctor_id, () => { clearSlot(); loadSlots(); });
watch(selectedDate, () => { clearSlot(); loadSlots(); });

// ── Resources ──────────────────────────────────────────────────────────────────
const resources        = ref([]);
const resourcesLoaded  = ref(false);
const resourcesLoading = ref(false);

async function loadResources() {
    if (!form.value.date_time) return;
    resourcesLoading.value = true;
    resourcesLoaded.value  = false;
    const sid = props.editSchedule?.id;
    const qs  = new URLSearchParams({ date_time: form.value.date_time });
    if (sid) qs.set('schedule_id', sid);
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

// ── Open / close ───────────────────────────────────────────────────────────────
watch(() => props.open, (open) => {
    if (!open) return;
    errors.value       = {};
    saving.value       = false;
    showManualTime.value = false;
    patientResults.value = [];
    showQuickReg.value   = false;
    quickName.value      = '';

    if (props.editSchedule) {
        const s  = props.editSchedule;
        const dt = s.date_time ? s.date_time.substring(0, 16) : '';
        selectedDate.value = dt ? dt.substring(0, 10) : '';

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
            resource_ids:       (s.resources ?? []).map(r => r.id),
            waiting_list_id:    '',
            use_recurrence:     false,
            recurrence_type:    'weekly',
            recurrence_until:   '',
        };
        patientSearch.value = s.full_name ?? '';
        loadResources();
    } else {
        const p = props.prefillData;
        selectedDate.value = props.defaultDate || new Date().toISOString().substring(0, 10);

        form.value = {
            doctor_id:          p?.doctor_id ?? (props.doctors.length === 1 ? props.doctors[0].id : ''),
            patient_id:         p?.patient_id ?? '',
            full_name:          p?.full_name ?? '',
            date_time:          '',
            telephone:          p?.telephone ?? '',
            cellphone:          p?.cellphone ?? '',
            cellphone_whatsapp: p?.cellphone_whatsapp ?? false,
            notes:              p?.notes ?? '',
            covenant_id:        p?.covenant_id ?? '',
            visit_id:           p?.visit_id ?? '',
            resource_ids:       [],
            waiting_list_id:    p?.id ?? '',
            use_recurrence:     false,
            recurrence_type:    'weekly',
            recurrence_until:   '',
        };
        patientSearch.value = p?.full_name ?? '';
        slots.value         = [];
        resources.value     = [];
    }
});

// ── Submit ─────────────────────────────────────────────────────────────────────
const isEdit    = computed(() => !!props.editSchedule);
const submitUrl = computed(() =>
    isEdit.value
        ? props.editSchedule.update_url ?? `/panel/schedules/${props.editSchedule.id}`
        : props.storeUrl,
);

async function onSubmit() {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};

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

        <form @submit.prevent="onSubmit">

            <!-- ── Médico ────────────────────────────────────────────────── -->
            <div v-if="doctors.length !== 1" class="mb-3">
                <label class="form-label fw-semibold">
                    {{ t.form_doctor }} <span class="text-danger">*</span>
                </label>
                <select v-model="form.doctor_id"
                        class="form-select"
                        :class="{ 'is-invalid': errors.doctor_id }">
                    <option value="">{{ t.form_select }}</option>
                    <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
                <div v-if="errors.doctor_id" class="invalid-feedback">{{ errors.doctor_id[0] }}</div>
            </div>

            <!-- ════════════════════════════════════════════════════════════
                 SELETOR DE DATA E HORÁRIO
                 ════════════════════════════════════════════════════════════ -->
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    {{ t.form_date_time }} <span class="text-danger">*</span>
                </label>

                <!-- Erro de validação -->
                <div v-if="errors.date_time" class="text-danger small mb-1">
                    <i class="fas fa-exclamation-circle me-1"></i>{{ errors.date_time[0] }}
                </div>

                <!-- Seletor de data com navegação rápida -->
                <div class="input-group input-group-sm mb-2">
                    <button type="button"
                            class="btn btn-outline-secondary"
                            :disabled="!selectedDate"
                            @click="addDaysToSelected(-1)">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <input v-model="selectedDate"
                           type="date"
                           class="form-control text-center fw-semibold">
                    <button type="button"
                            class="btn btn-outline-secondary"
                            :disabled="!selectedDate"
                            @click="addDaysToSelected(1)">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>

                <!-- Mensagem: selecione o médico -->
                <p v-if="!form.doctor_id" class="text-muted small mb-1">
                    <i class="fas fa-user-md me-1"></i>{{ t.form_select_doctor_first }}
                </p>

                <!-- Mensagem: selecione a data -->
                <p v-else-if="!selectedDate" class="text-muted small mb-1">
                    <i class="fas fa-calendar me-1"></i>{{ t.form_select_date_first }}
                </p>

                <!-- Carregando -->
                <div v-else-if="slotsLoading" class="py-1">
                    <span class="spinner-border spinner-border-sm text-primary me-1"></span>
                    <span class="text-muted small">{{ t.form_loading_slots }}</span>
                </div>

                <!-- Grid de horários (idêntico ao visual Alpine.js) -->
                <div v-else-if="slots.length > 0">
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        <button v-for="slot in slots"
                                :key="slot.datetime"
                                type="button"
                                class="btn px-2 py-1"
                                style="font-size:.78rem; min-width:52px;"
                                :class="slotBtnClass(slot)"
                                :disabled="!slot.available && form.date_time !== slot.datetime"
                                :title="!slot.available && form.date_time !== slot.datetime ? t.form_slot_occupied : slot.time"
                                @click="selectSlot(slot)">
                            {{ slot.time }}
                        </button>
                    </div>
                    <!-- Hint de intervalo — igual ao Alpine -->
                    <small class="text-muted mt-2 d-block">
                        <i class="fas fa-info-circle me-1"></i>
                        {{ t.form_interval_hint?.replace(':min', slotInterval) ?? `Intervalo de ${slotInterval} min` }}
                        <span v-if="!hasSchedule"> {{ t.form_no_scale }}</span>
                    </small>
                </div>

                <!-- Nenhum slot disponível -->
                <p v-else class="text-muted small mb-1">
                    <i class="fas fa-clock me-1"></i>{{ t.form_no_slots }}
                </p>

                <!-- Override manual -->
                <div class="mt-2">
                    <button type="button"
                            class="btn btn-link btn-sm p-0 text-muted text-decoration-none"
                            style="font-size:.78rem;"
                            @click="showManualTime = !showManualTime">
                        <i class="fas fa-pencil-alt me-1"></i>{{ t.form_manual_override }}
                    </button>
                    <div v-if="showManualTime" class="mt-1">
                        <input v-model="form.date_time"
                               type="datetime-local"
                               class="form-control form-control-sm"
                               :class="{ 'is-invalid': errors.date_time }"
                               @change="loadResources">
                        <div v-if="errors.date_time" class="invalid-feedback">{{ errors.date_time[0] }}</div>
                    </div>
                </div>
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
                    <button v-if="!showQuickReg"
                            type="button"
                            class="btn btn-link btn-sm p-0 text-decoration-none"
                            @click="showQuickReg = true">
                        <i class="fas fa-plus me-1"></i>{{ t.form_register }}
                    </button>
                    <div v-else class="d-flex gap-2 mt-1">
                        <input v-model="quickName"
                               type="text"
                               class="form-control form-control-sm"
                               :placeholder="t.form_patient_name"
                               @keyup.enter="quickRegister">
                        <button type="button" class="btn btn-sm btn-success" @click="quickRegister">OK</button>
                        <button type="button"
                                class="btn btn-sm btn-secondary"
                                @click="showQuickReg = false; quickName = ''">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
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
                    <select v-model="form.covenant_id" class="form-select">
                        <option value="">{{ t.form_none }}</option>
                        <option v-for="c in covenants" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.form_visit_type }}</label>
                    <select v-model="form.visit_id" class="form-select">
                        <option value="">{{ t.form_none }}</option>
                        <option v-for="v in visitTypes" :key="v.id" :value="v.id">{{ v.name }}</option>
                    </select>
                </div>
            </div>

            <!-- ── Recursos ──────────────────────────────────────────────── -->
            <div v-if="form.date_time" class="mb-3">
                <label class="form-label fw-semibold">
                    {{ t.form_resources }}
                    <small class="text-muted fw-normal">{{ t.form_resources_opt }}</small>
                </label>
                <!-- Carregando -->
                <div v-if="resourcesLoading" class="py-1">
                    <span class="spinner-border spinner-border-sm text-secondary me-1"></span>
                    <span class="text-muted small">{{ t.loading }}</span>
                </div>
                <!-- Sem recursos cadastrados na clínica -->
                <p v-else-if="resourcesLoaded && resources.length === 0"
                   class="text-muted small mb-0">
                    <i class="fas fa-info-circle me-1"></i>{{ t.form_no_resources }}
                </p>
                <!-- Lista de recursos -->
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
                        <select v-model="form.recurrence_type" class="form-select form-select-sm">
                            <option value="weekly">{{ t.form_weekly }}</option>
                            <option value="monthly">{{ t.form_monthly }}</option>
                        </select>
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

        </form>

        <template #footer>
            <button type="button" class="btn btn-secondary" @click="emit('close')">
                {{ t.form_cancel }}
            </button>
            <button type="button"
                    class="btn btn-primary px-4"
                    :disabled="saving"
                    @click="onSubmit">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                {{ saving ? t.saving : t.form_save_link }}
            </button>
        </template>

    </CenteredModal>
</template>
