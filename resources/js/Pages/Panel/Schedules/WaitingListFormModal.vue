<script setup>
import { ref, watch } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:       { type: Boolean, required: true },
    doctors:    { type: Array,   default: () => [] },
    covenants:  { type: Array,   default: () => [] },
    visitTypes: { type: Array,   default: () => [] },
    t:          { type: Object,  required: true },
});

const emit = defineEmits(['close', 'saved']);

const saving = ref(false);
const errors = ref({});

const form = ref(emptyForm());

function emptyForm() {
    return {
        doctor_id:           '',
        patient_id:          '',
        full_name:           '',
        telephone:           '',
        cellphone:           '',
        cellphone_whatsapp:  false,
        covenant_id:         '',
        visit_id:            '',
        notes:               '',
        preferred_date_from: '',
        preferred_date_until:'',
    };
}

watch(() => props.open, (val) => {
    if (val) {
        form.value    = emptyForm();
        errors.value  = {};
        saving.value  = false;
        patientSearch.value  = '';
        patientResults.value = [];
        showQuickReg.value   = false;
        quickName.value      = '';
    }
});

// ── Patient search ─────────────────────────────────────────────────────────────
const patientSearch   = ref('');
const patientResults  = ref([]);
const showQuickReg    = ref(false);
const quickName       = ref('');
let   searchDebounce  = null;

function onPatientInput() {
    clearTimeout(searchDebounce);
    if (patientSearch.value.length < 2) { patientResults.value = []; return; }
    searchDebounce = setTimeout(searchPatients, 350);
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
    if (! quickName.value.trim()) return;
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

// ── Submit ─────────────────────────────────────────────────────────────────────
async function onSubmit() {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};

    const res = await fetch(route('panel.waiting-list.store'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(form.value),
    });
    const json = await res.json();
    saving.value = false;

    if (res.ok) {
        if (window.showSuccessToast) window.showSuccessToast(json.message);
        emit('saved', json.data);
    } else if (res.status === 422) {
        errors.value = json.errors ?? {};
    } else {
        if (window.showErrorToast) window.showErrorToast(json.message ?? 'Erro ao salvar.');
    }
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="560" @close="emit('close')">

        <template #header>
            <h5 class="mb-0 fw-bold">
                <i class="fas fa-hourglass-half me-2 text-warning"></i>{{ t.wl_title }}
            </h5>
        </template>

        <form @submit.prevent="onSubmit">

            <!-- Doctor -->
            <div class="mb-3">
                <label class="form-label fw-semibold">{{ t.wl_doctor }} <span class="text-danger">*</span></label>
                <select v-model="form.doctor_id"
                        class="form-select"
                        :class="{ 'is-invalid': errors.doctor_id }">
                    <option value="">{{ t.form_select }}</option>
                    <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
                <div v-if="errors.doctor_id" class="invalid-feedback">{{ errors.doctor_id[0] }}</div>
            </div>

            <!-- Preferred period -->
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    {{ t.wl_period }} <small class="text-muted fw-normal">{{ t.wl_period_opt }}</small>
                </label>
                <div class="input-group">
                    <input v-model="form.preferred_date_from"
                           type="date"
                           class="form-control"
                           :class="{ 'is-invalid': errors.preferred_date_from }">
                    <span class="input-group-text">{{ t.wl_until }}</span>
                    <input v-model="form.preferred_date_until"
                           type="date"
                           class="form-control"
                           :class="{ 'is-invalid': errors.preferred_date_until }">
                </div>
                <div v-if="errors.preferred_date_from || errors.preferred_date_until"
                     class="invalid-feedback d-block">
                    {{ (errors.preferred_date_from ?? errors.preferred_date_until)?.[0] }}
                </div>
            </div>

            <!-- Patient search -->
            <div class="mb-3">
                <label class="form-label fw-semibold">{{ t.wl_patient }}</label>
                <div class="position-relative">
                    <input v-model="patientSearch"
                           type="text"
                           class="form-control"
                           :placeholder="t.wl_patient_search"
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
                        <li class="list-group-item list-group-item-action text-primary py-2 px-3"
                            style="cursor:pointer;"
                            @mousedown.prevent="showQuickReg = true; quickName = patientSearch">
                            <i class="fas fa-plus-circle me-1"></i>
                            {{ t.wl_register }} "{{ patientSearch }}"
                        </li>
                    </ul>
                </div>

                <!-- Quick registration -->
                <div v-if="showQuickReg" class="card card-body bg-light mt-2 p-3">
                    <p class="mb-2 fw-semibold small">{{ t.wl_quick_register }}</p>
                    <div class="row g-2">
                        <div class="col-8">
                            <input v-model="quickName"
                                   type="text"
                                   class="form-control form-control-sm"
                                   :placeholder="t.wl_full_name + ' *'"
                                   @keyup.enter="quickRegister">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-primary" @click="quickRegister">
                            {{ t.wl_save_link }}
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                @click="showQuickReg = false">{{ t.wl_cancel }}</button>
                    </div>
                </div>
            </div>

            <!-- Full name -->
            <div class="mb-3">
                <label class="form-label fw-semibold">{{ t.wl_full_name }} <span class="text-danger">*</span></label>
                <input v-model="form.full_name"
                       type="text"
                       class="form-control"
                       :class="{ 'is-invalid': errors.full_name }"
                       :placeholder="t.wl_patient_name">
                <div v-if="errors.full_name" class="invalid-feedback">{{ errors.full_name[0] }}</div>
            </div>

            <!-- Covenant + visit type -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.wl_covenant }}</label>
                    <select v-model="form.covenant_id" class="form-select">
                        <option value="">{{ t.wl_none }}</option>
                        <option v-for="c in covenants" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.wl_visit_type }}</label>
                    <select v-model="form.visit_id" class="form-select">
                        <option value="">{{ t.wl_none }}</option>
                        <option v-for="v in visitTypes" :key="v.id" :value="v.id">{{ v.name }}</option>
                    </select>
                </div>
            </div>

            <!-- Phone -->
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.wl_telephone }}</label>
                    <input v-model="form.telephone" type="text" class="form-control" placeholder="(00) 0000-0000">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">{{ t.wl_cellphone }}</label>
                    <input v-model="form.cellphone" type="text" class="form-control" placeholder="(00) 00000-0000">
                </div>
            </div>

            <div class="form-check mb-3">
                <input id="wl-whatsapp" v-model="form.cellphone_whatsapp" type="checkbox" class="form-check-input">
                <label for="wl-whatsapp" class="form-check-label small">
                    <i class="fab fa-whatsapp text-success me-1"></i>{{ t.wl_whatsapp }}
                </label>
            </div>

            <!-- Notes -->
            <div class="mb-3">
                <label class="form-label fw-semibold">{{ t.wl_notes }}</label>
                <textarea v-model="form.notes"
                          class="form-control"
                          rows="2"
                          :placeholder="t.wl_notes_ph"></textarea>
            </div>

        </form>

        <template #footer>
            <button type="button" class="btn btn-secondary" @click="emit('close')">
                {{ t.wl_cancel }}
            </button>
            <button type="button" class="btn btn-warning px-4" :disabled="saving" @click="onSubmit">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                <i class="fas fa-hourglass-half me-1"></i>{{ t.wl_add_btn }}
            </button>
        </template>

    </OffcanvasPanel>
</template>
