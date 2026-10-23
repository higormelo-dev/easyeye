<script setup>
import { ref, watch, computed, nextTick, onBeforeUnmount } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import { latestRequest, isAbortError } from '@/Support/latestRequest.js';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard.js';

const props = defineProps({
    open: { type: Boolean, required: true },
    doctorId: { type: String, default: null },
    genders: { type: Object, default: () => ({}) },
    maritalStatuses: { type: Object, default: () => ({}) },
    statesOfBrazil: { type: Object, default: () => ({}) },
    // Traduções de lang/<locale>/doctors.php (usa só a seção `invitation` aqui).
    t: { type: Object, default: () => ({}) },
});

const inviteT = computed(() => props.t?.invitation ?? {});

const emit = defineEmits(['close']);
const isEdit = computed(() => !!props.doctorId);
const title = computed(() => (isEdit.value ? 'Editar Médico' : 'Novo Médico'));
const loading = ref(false);
const loadError = ref('');
const editLoad = latestRequest();
const formRef = ref(null);
const page = usePage();
const ui = computed(() => page?.props?.t_ui ?? {});
const activeTab = ref('personal');
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const emptyDefaults = {
    // Pessoal
    name: '',
    nickname: '',
    national_registry: '',
    birth_date: '',
    gender: '',
    marital_status: '',
    email: '',
    mother_name: '',
    father_name: '',
    // Médico
    record: '',
    record_specialty: '',
    cbo_code: '',
    color: '#3699ff',
    observation: '',
    partner: false,
    active: true,
    // Contato + Endereço
    telephone: '',
    cellphone: '',
    whatsapp: false,
    zipcode: '',
    address: '',
    number: '',
    complement: '',
    district: '',
    city: '',
    state: '',
    // Acesso (somente criação)
    password: '',
    password_confirmation: '',
};
const form = useForm({ ...emptyDefaults });

function resetForm() {
    editLoad.cancel();
    loadError.value = '';
    loading.value = false;
    form.defaults({ ...emptyDefaults });
    form.reset();
    form.clearErrors();
    form.color = '#3699ff';
    activeTab.value = 'personal';
    // Reabrir o modal nunca começa com senha visível.
    showPassword.value = false;
    showPasswordConfirmation.value = false;
}

async function loadEditData(id) {
    const req = editLoad.begin();
    loading.value = true;
    loadError.value = '';
    try {
        const res = await fetch(route('panel.doctors.editData', id), { signal: req.signal });
        if (!res.ok) throw new Error();
        const json = await res.json();
        if (!req.current()) return;
        for (const key of Object.keys(emptyDefaults)) {
            if (Object.hasOwn(json.data ?? {}, key)) form[key] = json.data[key] ?? emptyDefaults[key];
        }
        form.clearErrors();
        form.defaults(form.data());
    } catch (error) {
        if (req.current() && !isAbortError(error))
            loadError.value = ui.value.load_error || 'Não foi possível carregar os dados.';
    } finally {
        if (req.current()) loading.value = false;
    }
}
watch(
    () => [props.open, props.doctorId],
    async ([open]) => {
        editLoad.cancel();
        if (!open) return;
        resetForm();
        if (props.doctorId) await loadEditData(props.doctorId);
    },
);
onBeforeUnmount(() => editLoad.cancel());

const { bypass } = useUnsavedChangesGuard({
    isDirty: () => props.open && form.isDirty,
    message: () => ui.value.discard_changes || 'Há alterações não salvas. Descartar?',
});
function confirmClose() {
    return !form.isDirty || window.confirm(ui.value.discard_changes || 'Há alterações não salvas. Descartar?');
}
function requestClose() {
    if (confirmClose()) emit('close');
}
async function focusFirstError() {
    const tab = ['personal', 'doctor', 'contact', 'auth'].find((key) => tabErrors.value[key]);
    if (!tab || !props.open) return;
    activeTab.value = tab;
    await nextTick();
    const invalid = formRef.value?.querySelector(
        `[data-tab="${tab}"] .is-invalid, [data-tab="${tab}"] [aria-invalid="true"]`,
    );
    (invalid?.querySelector('input') ?? invalid)?.focus();
}
const errorSummary = computed(() => Object.entries(form.errors).filter(([key]) => key !== 'existing_doctor'));

// Médico que já tem login no EasyEye (outra clínica): o servidor não cadastra
// e devolve `existing_doctor` — a clínica pode enviar um convite, que vai ao
// e-mail do login dele (nunca ao digitado) e só ele aceita.
const existingDoctor = computed(() => form.errors.existing_doctor ?? null);

function sendInvitation() {
    bypass(() =>
        form.post(route('panel.doctors.invitations.store'), {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        }),
    );
}

function submit() {
    if (loading.value || loadError.value || form.processing) return;
    const opts = { preserveScroll: true, onSuccess: () => emit('close'), onError: focusFirstError };
    if (isEdit.value) {
        bypass(() => form.put(route('panel.doctors.update', props.doctorId), opts));
    } else {
        bypass(() => form.post(route('panel.doctors.store'), opts));
    }
}

async function lookupCep() {
    const cep = form.zipcode.replace(/\D/g, '');
    if (cep.length !== 8) return;
    try {
        const res = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
        const d = await res.json();
        if (!d.erro) {
            form.address = d.logradouro ?? form.address;
            form.district = d.bairro ?? form.district;
            form.city = d.localidade ?? form.city;
            form.state = d.uf ?? form.state;
        }
    } catch {
        /**/
    }
}

const genderOptions = computed(() => Object.entries(props.genders).map(([v, l]) => ({ value: Number(v), label: l })));
const maritalOptions = computed(() =>
    Object.entries(props.maritalStatuses).map(([v, l]) => ({ value: Number(v), label: l })),
);
const stateOptions = computed(() => Object.entries(props.statesOfBrazil).map(([v]) => ({ value: v, label: v })));

const tabErrors = computed(() => ({
    personal: [
        'name',
        'nickname',
        'national_registry',
        'birth_date',
        'gender',
        'marital_status',
        'email',
        'mother_name',
        'father_name',
        'active',
    ].some((k) => k in form.errors),
    doctor: ['record', 'record_specialty', 'cbo_code', 'color', 'observation', 'partner'].some((k) => k in form.errors),
    contact: [
        'telephone',
        'cellphone',
        'whatsapp',
        'zipcode',
        'address',
        'number',
        'complement',
        'district',
        'city',
        'state',
    ].some((k) => k in form.errors),
    auth: ['password', 'password_confirmation'].some((k) => k in form.errors),
}));
</script>

<template>
    <OffcanvasPanel :open="open" :width="580" :loading="loading" :confirm-close="confirmClose" @close="emit('close')">
        <!-- ── Cabeçalho ─────────────────────────────────────────────────────── -->
        <template #header>
            <h5 class="mb-0 fw-semibold"><i class="ti ti-stethoscope me-2 text-primary"></i>{{ title }}</h5>
        </template>

        <!-- ── Abas ──────────────────────────────────────────────────────────── -->
        <template #tabs>
            <ul class="nav nav-tabs border-0">
                <li class="nav-item">
                    <button
                        type="button"
                        class="nav-link"
                        :class="{ active: activeTab === 'personal', 'text-danger': tabErrors.personal }"
                        @click="activeTab = 'personal'"
                    >
                        <i class="ti ti-user me-1"></i>Pessoal
                        <i v-if="tabErrors.personal" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button
                        type="button"
                        class="nav-link"
                        :class="{ active: activeTab === 'doctor', 'text-danger': tabErrors.doctor }"
                        @click="activeTab = 'doctor'"
                    >
                        <i class="ti ti-stethoscope me-1"></i>Médico
                        <i v-if="tabErrors.doctor" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button
                        type="button"
                        class="nav-link"
                        :class="{ active: activeTab === 'contact', 'text-danger': tabErrors.contact }"
                        @click="activeTab = 'contact'"
                    >
                        <i class="ti ti-phone me-1"></i>Contato
                        <i v-if="tabErrors.contact" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
                <li v-if="!isEdit" class="nav-item">
                    <button
                        type="button"
                        class="nav-link"
                        :class="{ active: activeTab === 'auth', 'text-danger': tabErrors.auth }"
                        @click="activeTab = 'auth'"
                    >
                        <i class="ti ti-lock me-1"></i>Acesso
                        <i v-if="tabErrors.auth" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
            </ul>
        </template>

        <!-- ── Corpo ─────────────────────────────────────────────────────────── -->
        <div
            v-if="existingDoctor && !isEdit"
            class="alert alert-info d-flex flex-column gap-2"
            role="status"
            aria-live="polite"
        >
            <div><i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ existingDoctor }}</div>
            <div>
                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    :disabled="form.processing"
                    @click="sendInvitation"
                >
                    <span
                        v-if="form.processing"
                        class="spinner-border spinner-border-sm me-1"
                        aria-hidden="true"
                    ></span>
                    <i v-else class="ti ti-send me-1" aria-hidden="true"></i>{{ inviteT.send_button }}
                </button>
            </div>
        </div>

        <div v-if="loadError" role="alert" class="alert alert-danger" data-testid="doctor-load-error">
            {{ loadError }}
            <button type="button" class="btn btn-outline-primary btn-sm" @click="loadEditData(doctorId)">
                {{ ui.retry || 'Tentar novamente' }}
            </button>
        </div>
        <form v-else ref="formRef" @submit.prevent="submit">
            <div
                v-if="errorSummary.length > 1"
                class="alert alert-danger"
                role="alert"
                data-testid="form-error-summary"
            >
                {{ ui.form_errors_summary || 'Corrija os campos destacados.' }}
                <ul class="mb-0">
                    <li v-for="[key, message] in errorSummary" :key="key">{{ message }}</li>
                </ul>
            </div>
            <!-- TAB: Pessoal -->
            <div v-show="activeTab === 'personal'" data-tab="personal">
                <div class="mb-3">
                    <label :for="'doctor-name'" class="form-label"
                        >Nome completo <span class="text-danger">*</span></label
                    >
                    <input
                        :id="'doctor-name'"
                        v-model="form.name"
                        aria-required="true"
                        :aria-invalid="!!form.errors.name"
                        :aria-describedby="form.errors.name ? 'doctor-name' + '-error' : undefined"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.name }"
                    />
                    <div v-if="form.errors.name" :id="'doctor-name' + '-error'" class="invalid-feedback">
                        {{ form.errors.name }}
                    </div>
                </div>

                <div class="mb-3">
                    <label :for="'doctor-nickname'" class="form-label"
                        >Apelido <span class="text-danger">*</span></label
                    >
                    <input
                        :id="'doctor-nickname'"
                        v-model="form.nickname"
                        aria-required="true"
                        :aria-invalid="!!form.errors.nickname"
                        :aria-describedby="form.errors.nickname ? 'doctor-nickname' + '-error' : undefined"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.nickname }"
                    />
                    <div v-if="form.errors.nickname" :id="'doctor-nickname' + '-error'" class="invalid-feedback">
                        {{ form.errors.nickname }}
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label :for="'doctor-birth_date'" class="form-label">Data de nascimento</label>
                        <input
                            :id="'doctor-birth_date'"
                            v-model="form.birth_date"
                            :aria-invalid="!!form.errors.birth_date"
                            :aria-describedby="form.errors.birth_date ? 'doctor-birth_date' + '-error' : undefined"
                            :class="{ 'is-invalid': form.errors.birth_date }"
                            type="date"
                            class="form-control"
                        />
                        <div
                            v-if="form.errors.birth_date"
                            :id="'doctor-birth_date' + '-error'"
                            class="invalid-feedback d-block"
                        >
                            {{ form.errors.birth_date }}
                        </div>
                    </div>
                    <div class="col-6">
                        <label :for="'doctor-gender'" class="form-label">Gênero</label>
                        <SearchSelect
                            :id="'doctor-gender'"
                            v-model="form.gender"
                            :aria-invalid="!!form.errors.gender"
                            :aria-describedby="form.errors.gender ? 'doctor-gender' + '-error' : undefined"
                            :invalid="!!form.errors.gender"
                            :options="genderOptions"
                            :value-key="'value'"
                            :label-key="'label'"
                            :placeholder="'Selecione'"
                        />
                        <div
                            v-if="form.errors.gender"
                            :id="'doctor-gender' + '-error'"
                            class="invalid-feedback d-block"
                        >
                            {{ form.errors.gender }}
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label :for="'doctor-national_registry'" class="form-label"
                            >CPF <span class="text-danger">*</span></label
                        >
                        <input
                            :id="'doctor-national_registry'"
                            v-model="form.national_registry"
                            v-mask="'cpf'"
                            aria-required="true"
                            :aria-invalid="!!form.errors.national_registry"
                            :aria-describedby="
                                form.errors.national_registry ? 'doctor-national_registry' + '-error' : undefined
                            "
                            type="text"
                            inputmode="numeric"
                            class="form-control"
                            placeholder="000.000.000-00"
                            :class="{ 'is-invalid': form.errors.national_registry }"
                        />
                        <div
                            v-if="form.errors.national_registry"
                            :id="'doctor-national_registry' + '-error'"
                            class="invalid-feedback"
                        >
                            {{ form.errors.national_registry }}
                        </div>
                    </div>
                    <div class="col-6">
                        <label :for="'doctor-marital_status'" class="form-label">Estado civil</label>
                        <SearchSelect
                            :id="'doctor-marital_status'"
                            v-model="form.marital_status"
                            :aria-invalid="!!form.errors.marital_status"
                            :aria-describedby="
                                form.errors.marital_status ? 'doctor-marital_status' + '-error' : undefined
                            "
                            :invalid="!!form.errors.marital_status"
                            :options="maritalOptions"
                            :value-key="'value'"
                            :label-key="'label'"
                            :placeholder="'Selecione'"
                        />
                        <div
                            v-if="form.errors.marital_status"
                            :id="'doctor-marital_status' + '-error'"
                            class="invalid-feedback d-block"
                        >
                            {{ form.errors.marital_status }}
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label :for="'doctor-email'" class="form-label">E-mail <span class="text-danger">*</span></label>
                    <input
                        :id="'doctor-email'"
                        v-model="form.email"
                        aria-required="true"
                        :aria-invalid="!!form.errors.email"
                        :aria-describedby="form.errors.email ? 'doctor-email' + '-error' : undefined"
                        type="email"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.email }"
                    />
                    <div v-if="form.errors.email" :id="'doctor-email' + '-error'" class="invalid-feedback">
                        {{ form.errors.email }}
                    </div>
                </div>

                <div v-if="isEdit" class="mb-3">
                    <div class="form-check form-switch">
                        <input
                            id="doctor-active"
                            v-model="form.active"
                            :aria-invalid="!!form.errors.active"
                            :aria-describedby="form.errors.active ? 'doctor-active' + '-error' : undefined"
                            :class="{ 'is-invalid': form.errors.active }"
                            class="form-check-input"
                            type="checkbox"
                        />
                        <div v-if="form.errors.active" :id="'doctor-active' + '-error'" class="invalid-feedback d-block">
                            {{ form.errors.active }}
                        </div>
                        <label class="form-check-label" for="doctor-active">Médico ativo</label>
                    </div>
                </div>
            </div>

            <!-- TAB: Médico -->
            <div v-show="activeTab === 'doctor'" data-tab="doctor">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label :for="'doctor-record'" class="form-label">CRM <span class="text-danger">*</span></label>
                        <input
                            :id="'doctor-record'"
                            v-model="form.record"
                            aria-required="true"
                            :aria-invalid="!!form.errors.record"
                            :aria-describedby="form.errors.record ? 'doctor-record' + '-error' : undefined"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.record }"
                        />
                        <div v-if="form.errors.record" :id="'doctor-record' + '-error'" class="invalid-feedback">
                            {{ form.errors.record }}
                        </div>
                    </div>
                    <div class="col-6">
                        <label :for="'doctor-record_specialty'" class="form-label"
                            >Especialidade <span class="text-danger">*</span></label
                        >
                        <input
                            :id="'doctor-record_specialty'"
                            v-model="form.record_specialty"
                            aria-required="true"
                            :aria-invalid="!!form.errors.record_specialty"
                            :aria-describedby="
                                form.errors.record_specialty ? 'doctor-record_specialty' + '-error' : undefined
                            "
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.record_specialty }"
                        />
                        <div
                            v-if="form.errors.record_specialty"
                            :id="'doctor-record_specialty' + '-error'"
                            class="invalid-feedback"
                        >
                            {{ form.errors.record_specialty }}
                        </div>
                    </div>
                    <div class="col-6">
                        <label :for="'doctor-cbo_code'" class="form-label">CBO</label>
                        <input
                            :id="'doctor-cbo_code'"
                            v-model="form.cbo_code"
                            :aria-invalid="!!form.errors.cbo_code"
                            :aria-describedby="form.errors.cbo_code ? 'doctor-cbo_code' + '-error' : undefined"
                            type="text"
                            maxlength="10"
                            placeholder="225265"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.cbo_code }"
                        />
                        <div v-if="form.errors.cbo_code" :id="'doctor-cbo_code' + '-error'" class="invalid-feedback">
                            {{ form.errors.cbo_code }}
                        </div>
                        <small class="text-muted"
                            >Classificação Brasileira de Ocupações — 225265 = Médico oftalmologista. Exigido pelo XML
                            TISS.</small
                        >
                    </div>
                </div>

                <div class="mb-3">
                    <label :for="'doctor-color'" class="form-label">
                        Cor na agenda <span class="text-danger">*</span>
                        <span
                            class="ms-2 rounded-circle d-inline-block border"
                            :style="{ background: form.color, width: '16px', height: '16px', verticalAlign: 'middle' }"
                        >
                        </span>
                    </label>
                    <input
                        :id="'doctor-color'"
                        v-model="form.color"
                        aria-required="true"
                        :aria-invalid="!!form.errors.color"
                        :aria-describedby="form.errors.color ? 'doctor-color' + '-error' : undefined"
                        type="color"
                        class="form-control form-control-color"
                        style="height: 38px"
                        :class="{ 'is-invalid': form.errors.color }"
                    />
                    <div v-if="form.errors.color" :id="'doctor-color' + '-error'" class="invalid-feedback">
                        {{ form.errors.color }}
                    </div>
                </div>

                <div class="mb-3">
                    <label :for="'doctor-observation'" class="form-label">Observações</label>
                    <textarea
                        :id="'doctor-observation'"
                        v-model="form.observation"
                        :aria-invalid="!!form.errors.observation"
                        :aria-describedby="form.errors.observation ? 'doctor-observation' + '-error' : undefined"
                        :class="{ 'is-invalid': form.errors.observation }"
                        class="form-control"
                        rows="3"
                        placeholder="Informações adicionais sobre o médico..."
                    >
                    </textarea>
                    <div
                        v-if="form.errors.observation"
                        :id="'doctor-observation' + '-error'"
                        class="invalid-feedback d-block"
                    >
                        {{ form.errors.observation }}
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input
                        id="doctor-partner"
                        v-model="form.partner"
                        :aria-invalid="!!form.errors.partner"
                        :aria-describedby="form.errors.partner ? 'doctor-partner' + '-error' : undefined"
                        :class="{ 'is-invalid': form.errors.partner }"
                        class="form-check-input"
                        type="checkbox"
                    />
                    <div v-if="form.errors.partner" :id="'doctor-partner' + '-error'" class="invalid-feedback d-block">
                        {{ form.errors.partner }}
                    </div>
                    <label class="form-check-label" for="doctor-partner">Médico parceiro</label>
                </div>
            </div>

            <!-- TAB: Contato + Endereço -->
            <div v-show="activeTab === 'contact'" data-tab="contact">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label :for="'doctor-cellphone'" class="form-label">Celular</label>
                        <input
                            :id="'doctor-cellphone'"
                            v-model="form.cellphone"
                            v-mask="'phone'"
                            :aria-invalid="!!form.errors.cellphone"
                            :aria-describedby="form.errors.cellphone ? 'doctor-cellphone' + '-error' : undefined"
                            :class="{ 'is-invalid': form.errors.cellphone }"
                            type="text"
                            inputmode="numeric"
                            class="form-control"
                            placeholder="(00) 00000-0000"
                        />
                        <div
                            v-if="form.errors.cellphone"
                            :id="'doctor-cellphone' + '-error'"
                            class="invalid-feedback d-block"
                        >
                            {{ form.errors.cellphone }}
                        </div>
                    </div>
                    <div class="col-6">
                        <label :for="'doctor-telephone'" class="form-label">Telefone</label>
                        <input
                            :id="'doctor-telephone'"
                            v-model="form.telephone"
                            v-mask="'phone'"
                            :aria-invalid="!!form.errors.telephone"
                            :aria-describedby="form.errors.telephone ? 'doctor-telephone' + '-error' : undefined"
                            :class="{ 'is-invalid': form.errors.telephone }"
                            type="text"
                            inputmode="numeric"
                            class="form-control"
                            placeholder="(00) 0000-0000"
                        />
                        <div
                            v-if="form.errors.telephone"
                            :id="'doctor-telephone' + '-error'"
                            class="invalid-feedback d-block"
                        >
                            {{ form.errors.telephone }}
                        </div>
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input
                        id="doctor-whatsapp"
                        v-model="form.whatsapp"
                        :aria-invalid="!!form.errors.whatsapp"
                        :aria-describedby="form.errors.whatsapp ? 'doctor-whatsapp' + '-error' : undefined"
                        :class="{ 'is-invalid': form.errors.whatsapp }"
                        class="form-check-input"
                        type="checkbox"
                    />
                    <div v-if="form.errors.whatsapp" :id="'doctor-whatsapp' + '-error'" class="invalid-feedback d-block">
                        {{ form.errors.whatsapp }}
                    </div>
                    <label class="form-check-label" for="doctor-whatsapp">
                        <i class="fab fa-whatsapp text-success me-1"></i>Celular tem WhatsApp
                    </label>
                </div>

                <hr class="my-3" />

                <div class="mb-3">
                    <label :for="'doctor-zipcode'" class="form-label">CEP</label>
                    <div class="input-group">
                        <input
                            :id="'doctor-zipcode'"
                            v-model="form.zipcode"
                            v-mask="'cep'"
                            :aria-invalid="!!form.errors.zipcode"
                            :aria-describedby="form.errors.zipcode ? 'doctor-zipcode' + '-error' : undefined"
                            :class="{ 'is-invalid': form.errors.zipcode }"
                            type="text"
                            inputmode="numeric"
                            class="form-control"
                            placeholder="00000-000"
                            @blur="lookupCep"
                        />
                        <div
                            v-if="form.errors.zipcode"
                            :id="'doctor-zipcode' + '-error'"
                            class="invalid-feedback d-block"
                        >
                            {{ form.errors.zipcode }}
                        </div>
                        <ActionIconButton
                            icon="ti ti-search"
                            :label="ui.cep_lookup || 'Buscar CEP'"
                            @click="lookupCep"
                        />
                    </div>
                </div>

                <div class="mb-3">
                    <label :for="'doctor-address'" class="form-label">Logradouro</label>
                    <input
                        :id="'doctor-address'"
                        v-model="form.address"
                        :aria-invalid="!!form.errors.address"
                        :aria-describedby="form.errors.address ? 'doctor-address' + '-error' : undefined"
                        :class="{ 'is-invalid': form.errors.address }"
                        type="text"
                        class="form-control"
                    />
                    <div v-if="form.errors.address" :id="'doctor-address' + '-error'" class="invalid-feedback d-block">
                        {{ form.errors.address }}
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-4">
                        <label :for="'doctor-number'" class="form-label">Número</label>
                        <input
                            :id="'doctor-number'"
                            v-model="form.number"
                            :aria-invalid="!!form.errors.number"
                            :aria-describedby="form.errors.number ? 'doctor-number' + '-error' : undefined"
                            :class="{ 'is-invalid': form.errors.number }"
                            type="text"
                            class="form-control"
                        />
                        <div
                            v-if="form.errors.number"
                            :id="'doctor-number' + '-error'"
                            class="invalid-feedback d-block"
                        >
                            {{ form.errors.number }}
                        </div>
                    </div>
                    <div class="col-8">
                        <label :for="'doctor-complement'" class="form-label">Complemento</label>
                        <input
                            :id="'doctor-complement'"
                            v-model="form.complement"
                            :aria-invalid="!!form.errors.complement"
                            :aria-describedby="form.errors.complement ? 'doctor-complement' + '-error' : undefined"
                            :class="{ 'is-invalid': form.errors.complement }"
                            type="text"
                            class="form-control"
                        />
                        <div
                            v-if="form.errors.complement"
                            :id="'doctor-complement' + '-error'"
                            class="invalid-feedback d-block"
                        >
                            {{ form.errors.complement }}
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label :for="'doctor-district'" class="form-label">Bairro</label>
                    <input
                        :id="'doctor-district'"
                        v-model="form.district"
                        :aria-invalid="!!form.errors.district"
                        :aria-describedby="form.errors.district ? 'doctor-district' + '-error' : undefined"
                        :class="{ 'is-invalid': form.errors.district }"
                        type="text"
                        class="form-control"
                    />
                    <div
                        v-if="form.errors.district"
                        :id="'doctor-district' + '-error'"
                        class="invalid-feedback d-block"
                    >
                        {{ form.errors.district }}
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-8">
                        <label :for="'doctor-city'" class="form-label">Cidade</label>
                        <input
                            :id="'doctor-city'"
                            v-model="form.city"
                            :aria-invalid="!!form.errors.city"
                            :aria-describedby="form.errors.city ? 'doctor-city' + '-error' : undefined"
                            :class="{ 'is-invalid': form.errors.city }"
                            type="text"
                            class="form-control"
                        />
                        <div v-if="form.errors.city" :id="'doctor-city' + '-error'" class="invalid-feedback d-block">
                            {{ form.errors.city }}
                        </div>
                    </div>
                    <div class="col-4">
                        <label :for="'doctor-state'" class="form-label">UF</label>
                        <SearchSelect
                            :id="'doctor-state'"
                            v-model="form.state"
                            :aria-invalid="!!form.errors.state"
                            :aria-describedby="form.errors.state ? 'doctor-state' + '-error' : undefined"
                            :invalid="!!form.errors.state"
                            :options="stateOptions"
                            :value-key="'value'"
                            :label-key="'label'"
                            :placeholder="'—'"
                        />
                        <div v-if="form.errors.state" :id="'doctor-state' + '-error'" class="invalid-feedback d-block">
                            {{ form.errors.state }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB: Acesso (somente criação) -->
            <div v-show="activeTab === 'auth'" data-tab="auth">
                <div class="alert alert-info small mb-3 py-2">
                    <i class="ti ti-info-circle me-1"></i>
                    O médico receberá estas credenciais para acessar o sistema.
                </div>

                <div class="mb-3">
                    <label :for="'doctor-password'" class="form-label">Senha <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input
                            :id="'doctor-password'"
                            v-model="form.password"
                            aria-required="true"
                            :aria-invalid="!!form.errors.password"
                            :aria-describedby="form.errors.password ? 'doctor-password' + '-error' : undefined"
                            :type="showPassword ? 'text' : 'password'"
                            class="form-control"
                            autocomplete="new-password"
                            :class="{ 'is-invalid': form.errors.password }"
                        />
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            :aria-label="
                                showPassword ? ui.password_hide || 'Ocultar senha' : ui.password_show || 'Mostrar senha'
                            "
                            :aria-pressed="showPassword"
                            @click="showPassword = !showPassword"
                        >
                            <i aria-hidden="true" :class="showPassword ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                        </button>
                    </div>
                    <div
                        v-if="form.errors.password"
                        :id="'doctor-password' + '-error'"
                        class="invalid-feedback d-block"
                    >
                        {{ form.errors.password }}
                    </div>
                    <div class="form-text">
                        Mínimo 8 caracteres com letras maiúsculas, minúsculas, números e símbolos.
                    </div>
                </div>

                <div class="mb-3">
                    <label :for="'doctor-password_confirmation'" class="form-label"
                        >Confirmar senha <span class="text-danger">*</span></label
                    >
                    <div class="input-group">
                        <input
                            :id="'doctor-password_confirmation'"
                            v-model="form.password_confirmation"
                            aria-required="true"
                            :aria-invalid="!!form.errors.password_confirmation"
                            :aria-describedby="
                                form.errors.password_confirmation
                                    ? 'doctor-password_confirmation' + '-error'
                                    : undefined
                            "
                            :type="showPasswordConfirmation ? 'text' : 'password'"
                            class="form-control"
                            autocomplete="new-password"
                            :class="{ 'is-invalid': form.errors.password_confirmation }"
                        />
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            :aria-label="
                                showPasswordConfirmation
                                    ? ui.password_hide || 'Ocultar senha'
                                    : ui.password_show || 'Mostrar senha'
                            "
                            :aria-pressed="showPasswordConfirmation"
                            @click="showPasswordConfirmation = !showPasswordConfirmation"
                        >
                            <i aria-hidden="true" :class="showPasswordConfirmation ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                        </button>
                    </div>
                    <div
                        v-if="form.errors.password_confirmation"
                        :id="'doctor-password_confirmation' + '-error'"
                        class="invalid-feedback d-block"
                    >
                        {{ form.errors.password_confirmation }}
                    </div>
                </div>
            </div>
        </form>

        <!-- ── Rodapé ─────────────────────────────────────────────────────────── -->
        <template #footer>
            <button type="button" class="btn btn-light" @click="requestClose">
                {{ loadError ? ui.close || 'Fechar' : 'Cancelar' }}
            </button>
            <button v-if="loadError" type="button" class="btn btn-outline-primary" @click="loadEditData(doctorId)">
                {{ ui.retry || 'Tentar novamente' }}
            </button>
            <button
                v-else
                type="button"
                class="btn btn-primary px-4"
                :disabled="form.processing || loading || !!loadError"
                @click="submit"
            >
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ isEdit ? 'Salvar alterações' : 'Cadastrar médico' }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
