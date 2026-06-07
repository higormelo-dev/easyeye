<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

const props = defineProps({
    open:           { type: Boolean, required: true },
    patientId:      { type: String,  default: null },
    covenants:      { type: Array,   default: () => [] },
    skinTypes:      { type: Array,   default: () => [] },
    irisTypes:      { type: Array,   default: () => [] },
    genders:        { type: Object,  default: () => ({}) },
    maritalStatuses:{ type: Object,  default: () => ({}) },
    statesOfBrazil: { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close']);

const isEdit    = computed(() => !!props.patientId);
const title     = computed(() => isEdit.value ? 'Editar Paciente' : 'Novo Paciente');
const activeTab = ref('personal');
const loading   = ref(false);

const form = useForm({
    // Clínico
    covenant_id: '',
    card_number: '',
    skin_id:     '',
    iris_id:     '',
    active:      true,
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
    // Documento
    state_registry:         '',
    state_registry_agency:  '',
    state_registry_initial: '',
    state_registry_date:    '',
    // Contato
    telephone: '',
    cellphone: '',
    whatsapp:  false,
    // Endereço
    zipcode:    '',
    address:    '',
    number:     '',
    complement: '',
    district:   '',
    city:       '',
    state:      '',
    country:    'Brasil',
});

function resetForm() {
    form.reset();
    form.clearErrors();
    activeTab.value = 'personal';
}

async function loadEditData(id) {
    loading.value = true;
    try {
        const res  = await fetch(route('panel.patients.editData', id));
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
    if (props.patientId) await loadEditData(props.patientId);
});

function submit() {
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
    Object.entries(props.statesOfBrazil).map(([v, l]) => ({ value: v, label: l })),
);

const tabHasErrors = computed(() => ({
    personal: Object.keys(form.errors).some(k =>
        ['name','nickname','national_registry','birth_date','gender','marital_status','email','mother_name','father_name'].includes(k),
    ),
    clinical: Object.keys(form.errors).some(k =>
        ['covenant_id','card_number','skin_id','iris_id'].includes(k),
    ),
    contact: Object.keys(form.errors).some(k =>
        ['telephone','cellphone','whatsapp'].includes(k),
    ),
    address: Object.keys(form.errors).some(k =>
        ['zipcode','address','number','complement','district','city','state','country'].includes(k),
    ),
}));
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
                <li class="nav-item">
                    <button class="nav-link"
                            :class="{ active: activeTab === 'personal', 'text-danger': tabHasErrors.personal }"
                            type="button"
                            @click="activeTab = 'personal'">
                        <i class="ti ti-user-circle me-1"></i>Pessoal
                        <i v-if="tabHasErrors.personal" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link"
                            :class="{ active: activeTab === 'clinical', 'text-danger': tabHasErrors.clinical }"
                            type="button"
                            @click="activeTab = 'clinical'">
                        <i class="ti ti-stethoscope me-1"></i>Clínico
                        <i v-if="tabHasErrors.clinical" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link"
                            :class="{ active: activeTab === 'contact', 'text-danger': tabHasErrors.contact }"
                            type="button"
                            @click="activeTab = 'contact'">
                        <i class="ti ti-phone me-1"></i>Contato
                        <i v-if="tabHasErrors.contact" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link"
                            :class="{ active: activeTab === 'address', 'text-danger': tabHasErrors.address }"
                            type="button"
                            @click="activeTab = 'address'">
                        <i class="ti ti-map-pin me-1"></i>Endereço
                        <i v-if="tabHasErrors.address" class="ti ti-alert-circle text-danger ms-1"></i>
                    </button>
                </li>
            </ul>
        </template>

        <!-- ── Corpo do formulário ───────────────────────────────────────────── -->
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
                    <label class="form-label">Apelido</label>
                    <input v-model="form.nickname" type="text" class="form-control">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label">Data de nascimento <span class="text-danger">*</span></label>
                        <input v-model="form.birth_date"
                               type="date"
                               class="form-control"
                               :class="{ 'is-invalid': form.errors.birth_date }">
                        <div v-if="form.errors.birth_date" class="invalid-feedback">{{ form.errors.birth_date }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Gênero <span class="text-danger">*</span></label>
                        <SearchSelect v-model="form.gender"
                                      :options="genderOptions"
                                      :value-key="'value'"
                                      :label-key="'label'"
                                      :placeholder="'Selecione'"
                                      :clearable="false"
                                      :invalid="!!form.errors.gender" />
                        <div v-if="form.errors.gender" class="invalid-feedback d-block">{{ form.errors.gender }}</div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label">Estado civil <span class="text-danger">*</span></label>
                        <SearchSelect v-model="form.marital_status"
                                      :options="maritalOptions"
                                      :value-key="'value'"
                                      :label-key="'label'"
                                      :placeholder="'Selecione'"
                                      :clearable="false"
                                      :invalid="!!form.errors.marital_status" />
                        <div v-if="form.errors.marital_status" class="invalid-feedback d-block">{{ form.errors.marital_status }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">CPF <span class="text-danger">*</span></label>
                        <input v-model="form.national_registry"
                               type="text"
                               class="form-control"
                               placeholder="000.000.000-00"
                               :class="{ 'is-invalid': form.errors.national_registry }">
                        <div v-if="form.errors.national_registry" class="invalid-feedback">{{ form.errors.national_registry }}</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">E-mail</label>
                    <input v-model="form.email"
                           type="email"
                           class="form-control"
                           :class="{ 'is-invalid': form.errors.email }">
                    <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label">Nome da mãe</label>
                        <input v-model="form.mother_name" type="text" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Nome do pai</label>
                        <input v-model="form.father_name" type="text" class="form-control">
                    </div>
                </div>

                <div v-if="isEdit" class="mb-3">
                    <div class="form-check form-switch">
                        <input id="chkActive" v-model="form.active" class="form-check-input" type="checkbox">
                        <label class="form-check-label" for="chkActive">Paciente ativo</label>
                    </div>
                </div>
            </div>

            <!-- TAB: Clínico -->
            <div v-show="activeTab === 'clinical'">
                <div class="mb-3">
                    <label class="form-label">Convênio</label>
                    <SearchSelect v-model="form.covenant_id"
                                  :options="covenants"
                                  :placeholder="'Selecione'"
                                  :invalid="!!form.errors.covenant_id" />
                    <div v-if="form.errors.covenant_id" class="invalid-feedback d-block">{{ form.errors.covenant_id }}</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Número da carteirinha</label>
                    <input v-model="form.card_number" type="text" class="form-control">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label">Tipo de pele</label>
                        <SearchSelect v-model="form.skin_id"
                                      :options="skinTypes"
                                      :placeholder="'Não informado'" />
                    </div>
                    <div class="col-6">
                        <label class="form-label">Tipo de íris</label>
                        <SearchSelect v-model="form.iris_id"
                                      :options="irisTypes"
                                      :placeholder="'Não informado'" />
                    </div>
                </div>

                <div class="card border-0 bg-light p-3 mt-2">
                    <p class="text-muted small fw-medium mb-2">Registro estadual (opcional)</p>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small">RG</label>
                            <input v-model="form.state_registry" type="text" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Órgão emissor</label>
                            <input v-model="form.state_registry_agency" type="text" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">UF do RG</label>
                            <SearchSelect v-model="form.state_registry_initial"
                                          :options="stateOptions"
                                          :value-key="'value'"
                                          :label-key="'value'"
                                          :placeholder="'—'" />
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Data do RG</label>
                            <input v-model="form.state_registry_date" type="date" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB: Contato -->
            <div v-show="activeTab === 'contact'">
                <div class="mb-3">
                    <label class="form-label">Celular <span class="text-danger">*</span></label>
                    <input v-model="form.cellphone"
                           type="text"
                           class="form-control"
                           placeholder="(00) 00000-0000"
                           :class="{ 'is-invalid': form.errors.cellphone }">
                    <div v-if="form.errors.cellphone" class="invalid-feedback">{{ form.errors.cellphone }}</div>
                </div>

                <div class="mb-3">
                    <div class="form-check">
                        <input id="chkWhatsapp" v-model="form.whatsapp" class="form-check-input" type="checkbox">
                        <label class="form-check-label" for="chkWhatsapp">
                            <i class="fab fa-whatsapp text-success me-1"></i>Este celular tem WhatsApp
                        </label>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Telefone fixo</label>
                    <input v-model="form.telephone" type="text" class="form-control" placeholder="(00) 0000-0000">
                </div>
            </div>

            <!-- TAB: Endereço -->
            <div v-show="activeTab === 'address'">
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
                        <SearchSelect v-model="form.state"
                                      :options="stateOptions"
                                      :value-key="'value'"
                                      :label-key="'value'"
                                      :placeholder="'—'" />
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">País</label>
                    <input v-model="form.country" type="text" class="form-control">
                </div>
            </div>

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
