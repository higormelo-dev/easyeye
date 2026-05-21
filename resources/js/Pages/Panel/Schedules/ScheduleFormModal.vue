<script setup>
import { ref, watch, computed, nextTick } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import SlotPicker    from '@/Components/Panel/SlotPicker.vue';

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
    resource_ids:       [],
    waiting_list_id:    '',
    use_recurrence:     false,
    recurrence_type:    'weekly',
    recurrence_until:   '',
});

// ── SlotPicker ref ─────────────────────────────────────────────────────────────
const slotPickerRef = ref(null);

// ── Busca de paciente ──────────────────────────────────────────────────────────
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
        errors.value         = {};
        saving.value         = false;
        patientResults.value = [];
        showQuickReg.value   = false;
        quickName.value      = '';
        resources.value      = [];
        resourcesLoaded.value = false;

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
                resource_ids:       (s.resources ?? []).map(r => r.id),
                waiting_list_id:    '',
                use_recurrence:     false,
                recurrence_type:    'weekly',
                recurrence_until:   '',
            };
            patientSearch.value = s.full_name ?? '';

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
                resource_ids:       [],
                waiting_list_id:    p?.id ?? '',
                use_recurrence:     false,
                recurrence_type:    'weekly',
                recurrence_until:   '',
            };
            patientSearch.value = p?.full_name ?? '';

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
