<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:           { type: Boolean, required: true },
    doctorId:       { type: String,  default: null },
    genders:        { type: Object,  default: () => ({}) },
    maritalStatuses:{ type: Object,  default: () => ({}) },
    statesOfBrazil: { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close']);
const isEdit  = computed(() => !!props.doctorId);
const title   = computed(() => isEdit.value ? 'Editar Médico' : 'Novo Médico');
const loading = ref(false);
const activeTab = ref('personal');

const form = useForm({
    // Pessoal
    name:              '',
    nickname:          '',
    national_registry: '',
    birth_date:        '',
    gender:            '',
    marital_status:    '',
    email:             '',
    mother_name:       '',
    father_name:       '',
    // Médico
    record:           '',
    record_specialty: '',
    color:            '#3699ff',
    observation:      '',
    partner:          false,
    active:           true,
    // Contato + Endereço
    telephone: '',
    cellphone: '',
    whatsapp:  false,
    zipcode:   '',
    address:   '',
    number:    '',
    complement:'',
    district:  '',
    city:      '',
    state:     '',
    // Acesso (somente criação)
    password:             '',
    password_confirmation:'',
});

function resetForm() {
    form.reset();
    form.clearErrors();
    form.color = '#3699ff';
    activeTab.value = 'personal';
}

async function loadEditData(id) {
    loading.value = true;
    try {
        const res  = await fetch(route('panel.doctors.editData', id));
        const json = await res.json();
        const d    = json.data;
        Object.keys(form).forEach((key) => {
            if (key in d) form[key] = d[key] ?? form[key];
        });
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, async (val) => {
    if (!val) return;
    resetForm();
    if (props.doctorId) await loadEditData(props.doctorId);
});

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    if (isEdit.value) {
        form.put(route('panel.doctors.update', props.doctorId), opts);
    } else {
        form.post(route('panel.doctors.store'), opts);
    }
}

async function lookupCep() {
    const cep = form.zipcode.replace(/\D/g, '');
    if (cep.length !== 8) return;
    try {
        const res = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
        const d   = await res.json();
        if (!d.erro) {
            form.address  = d.logradouro ?? form.address;
            form.district = d.bairro     ?? form.district;
            form.city     = d.localidade ?? form.city;
            form.state    = d.uf         ?? form.state;
        }
    } catch { /**/ }
}

const genderOptions = computed(() =>
    Object.entries(props.genders).map(([v, l]) => ({ value: Number(v), label: l })),
);
const maritalOptions = computed(() =>
    Object.entries(props.maritalStatuses).map(([v, l]) => ({ value: Number(v), label: l })),
);
const stateOptions = computed(() =>
    Object.entries(props.statesOfBrazil).map(([v]) => v),
);

const tabErrors = computed(() => ({
    personal: ['name','nickname','national_registry','birth_date','gender','marital_status','email','mother_name','father_name'].some(k => k in form.errors),
    doctor:   ['record','record_specialty','color','observation'].some(k => k in form.errors),
    contact:  ['telephone','cellphone','whatsapp','zipcode','address','number','complement','district','city','state'].some(k => k in form.errors),
    auth:     ['password','password_confirmation'].some(k => k in form.errors),
}));
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="580"
        :loading="loading"
        @close="emit('close')"
    >
        <!-- ── Cabeçalho ─────────────────────────────────────────────────────── -->
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-stethoscope me-2 text-primary"></i>{{ title }}
            </h5>
        </template>

        <!-- ── Abas ──────────────────────────────────────────────────────────── -->
        <template #tabs>
            <ul class="nav nav-tabs border-0">
                <li class="nav-item">
                    <button type="button"
                            class="nav-link"
                            :class="{ active: activeTab === 'personal', 'text-danger': tabErrors.personal }"
                            @click="activeTab = 'personal'">
                        <i class="ti ti-user me-1"></i>Pessoal
                        <i v-if="tabErrors.personal" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button type="button"
                            class="nav-link"
                            :class="{ active: activeTab === 'doctor', 'text-danger': tabErrors.doctor }"
                            @click="activeTab = 'doctor'">
                        <i class="ti ti-stethoscope me-1"></i>Médico
                        <i v-if="tabErrors.doctor" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button type="button"
                            class="nav-link"
                            :class="{ active: activeTab === 'contact', 'text-danger': tabErrors.contact }"
                            @click="activeTab = 'contact'">
                        <i class="ti ti-phone me-1"></i>Contato
                        <i v-if="tabErrors.contact" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
                <li v-if="!isEdit" class="nav-item">
                    <button type="button"
                            class="nav-link"
                            :class="{ active: activeTab === 'auth', 'text-danger': tabErrors.auth }"
                            @click="activeTab = 'auth'">
                        <i class="ti ti-lock me-1"></i>Acesso
                        <i v-if="tabErrors.auth" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
            </ul>
        </template>

        <!-- ── Corpo ─────────────────────────────────────────────────────────── -->
        <form @submit.prevent="submit">

            <!-- TAB: Pessoal -->
            <div v-show="activeTab === 'personal'">
                <div class="mb-3">
                    <label class="form-label">Nome completo <span class="text-danger">*</span></label>
                    <input v-model="form.name"
                           type="text"
                           class="form-control"
                           :class="{ 'is-invalid': form.errors.name }">
                    <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Apelido <span class="text-danger">*</span></label>
                    <input v-model="form.nickname"
                           type="text"
                           class="form-control"
                           :class="{ 'is-invalid': form.errors.nickname }">
                    <div v-if="form.errors.nickname" class="invalid-feedback">{{ form.errors.nickname }}</div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label">Data de nascimento</label>
                        <input v-model="form.birth_date" type="date" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Gênero</label>
                        <select v-model="form.gender" class="form-select">
                            <option value="">Selecione</option>
                            <option v-for="o in genderOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label">CPF <span class="text-danger">*</span></label>
                        <input v-model="form.national_registry"
                               type="text"
                               class="form-control"
                               placeholder="00000000000"
                               :class="{ 'is-invalid': form.errors.national_registry }">
                        <div v-if="form.errors.national_registry" class="invalid-feedback">{{ form.errors.national_registry }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Estado civil</label>
                        <select v-model="form.marital_status" class="form-select">
                            <option value="">Selecione</option>
                            <option v-for="o in maritalOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">E-mail <span class="text-danger">*</span></label>
                    <input v-model="form.email"
                           type="email"
                           class="form-control"
                           :class="{ 'is-invalid': form.errors.email }">
                    <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                </div>

                <div v-if="isEdit" class="mb-3">
                    <div class="form-check form-switch">
                        <input id="drActive" v-model="form.active" class="form-check-input" type="checkbox">
                        <label class="form-check-label" for="drActive">Médico ativo</label>
                    </div>
                </div>
            </div>

            <!-- TAB: Médico -->
            <div v-show="activeTab === 'doctor'">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label">CRM <span class="text-danger">*</span></label>
                        <input v-model="form.record"
                               type="text"
                               class="form-control"
                               :class="{ 'is-invalid': form.errors.record }">
                        <div v-if="form.errors.record" class="invalid-feedback">{{ form.errors.record }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Especialidade <span class="text-danger">*</span></label>
                        <input v-model="form.record_specialty"
                               type="text"
                               class="form-control"
                               :class="{ 'is-invalid': form.errors.record_specialty }">
                        <div v-if="form.errors.record_specialty" class="invalid-feedback">{{ form.errors.record_specialty }}</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        Cor na agenda <span class="text-danger">*</span>
                        <span class="ms-2 rounded-circle d-inline-block border"
                              :style="{ background: form.color, width: '16px', height: '16px', verticalAlign: 'middle' }">
                        </span>
                    </label>
                    <input v-model="form.color"
                           type="color"
                           class="form-control form-control-color"
                           style="height:38px;"
                           :class="{ 'is-invalid': form.errors.color }">
                    <div v-if="form.errors.color" class="invalid-feedback">{{ form.errors.color }}</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Observações</label>
                    <textarea v-model="form.observation"
                              class="form-control"
                              rows="3"
                              placeholder="Informações adicionais sobre o médico...">
                    </textarea>
                </div>

                <div class="form-check mb-3">
                    <input id="drPartner" v-model="form.partner" class="form-check-input" type="checkbox">
                    <label class="form-check-label" for="drPartner">Médico parceiro</label>
                </div>
            </div>

            <!-- TAB: Contato + Endereço -->
            <div v-show="activeTab === 'contact'">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label">Celular</label>
                        <input v-model="form.cellphone" type="text" class="form-control" placeholder="(00) 00000-0000">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Telefone</label>
                        <input v-model="form.telephone" type="text" class="form-control" placeholder="(00) 0000-0000">
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input id="drWhatsapp" v-model="form.whatsapp" class="form-check-input" type="checkbox">
                    <label class="form-check-label" for="drWhatsapp">
                        <i class="fab fa-whatsapp text-success me-1"></i>Celular tem WhatsApp
                    </label>
                </div>

                <hr class="my-3">

                <div class="mb-3">
                    <label class="form-label">CEP</label>
                    <div class="input-group">
                        <input v-model="form.zipcode"
                               type="text"
                               class="form-control"
                               placeholder="00000-000"
                               @blur="lookupCep">
                        <button type="button" class="btn btn-outline-secondary" @click="lookupCep">
                            <i class="ti ti-search"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Logradouro</label>
                    <input v-model="form.address" type="text" class="form-control">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-4">
                        <label class="form-label">Número</label>
                        <input v-model="form.number" type="text" class="form-control">
                    </div>
                    <div class="col-8">
                        <label class="form-label">Complemento</label>
                        <input v-model="form.complement" type="text" class="form-control">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Bairro</label>
                    <input v-model="form.district" type="text" class="form-control">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-8">
                        <label class="form-label">Cidade</label>
                        <input v-model="form.city" type="text" class="form-control">
                    </div>
                    <div class="col-4">
                        <label class="form-label">UF</label>
                        <select v-model="form.state" class="form-select">
                            <option value="">—</option>
                            <option v-for="s in stateOptions" :key="s" :value="s">{{ s }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- TAB: Acesso (somente criação) -->
            <div v-show="activeTab === 'auth'">
                <div class="alert alert-info small mb-3 py-2">
                    <i class="ti ti-info-circle me-1"></i>
                    O médico receberá estas credenciais para acessar o sistema.
                </div>

                <div class="mb-3">
                    <label class="form-label">Senha <span class="text-danger">*</span></label>
                    <input v-model="form.password"
                           type="password"
                           class="form-control"
                           autocomplete="new-password"
                           :class="{ 'is-invalid': form.errors.password }">
                    <div v-if="form.errors.password" class="invalid-feedback">{{ form.errors.password }}</div>
                    <div class="form-text">Mínimo 8 caracteres com letras maiúsculas, minúsculas, números e símbolos.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Confirmar senha <span class="text-danger">*</span></label>
                    <input v-model="form.password_confirmation"
                           type="password"
                           class="form-control"
                           autocomplete="new-password"
                           :class="{ 'is-invalid': form.errors.password_confirmation }">
                    <div v-if="form.errors.password_confirmation" class="invalid-feedback">{{ form.errors.password_confirmation }}</div>
                </div>
            </div>

        </form>

        <!-- ── Rodapé ─────────────────────────────────────────────────────────── -->
        <template #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">Cancelar</button>
            <button type="button"
                    class="btn btn-primary px-4"
                    :disabled="form.processing"
                    @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ isEdit ? 'Salvar alterações' : 'Cadastrar médico' }}
            </button>
        </template>

    </OffcanvasPanel>
</template>
