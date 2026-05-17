<script setup>
import { ref, watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:     { type: Boolean, required: true },
    entityId: { type: String,  default: null },
    t:        { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close']);
const isEdit  = computed(() => !!props.entityId);
const title   = computed(() => isEdit.value ? props.t.form_title_edit : props.t.form_title_create);
const loading = ref(false);
const activeTab = ref('dados');

const form = useForm({
    name: '', subdomain: '', email: '', telephone: '', cellphone: '',
    national_registration: '', state_registration: '', municipal_registration: '', website: '',
    zipcode: '', address: '', number: '', complement: '', district: '',
    city: '', state: '', country: 'Brasil',
    schedule_interval: 15, active: true,
});

function resetForm() {
    form.reset(); form.clearErrors();
    form.country = 'Brasil'; form.schedule_interval = 15;
    activeTab.value = 'dados';
}

async function loadEditData(id) {
    loading.value = true;
    try {
        const res  = await fetch(route('manager.entities.edit-data', id));
        const json = await res.json();
        Object.keys(form).forEach(k => { if (k in json.data && json.data[k] !== null) form[k] = json.data[k]; });
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, async (val) => {
    if (val) { resetForm(); if (props.entityId) await loadEditData(props.entityId); }
});

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    isEdit.value
        ? form.put(route('manager.entities.update', props.entityId), opts)
        : form.post(route('manager.entities.store'), opts);
}

async function lookupCep() {
    const cep = form.zipcode.replace(/\D/g, '');
    if (cep.length !== 8) return;
    try {
        const d = await fetch(`https://viacep.com.br/ws/${cep}/json/`).then(r => r.json());
        if (!d.erro) {
            form.address = d.logradouro ?? form.address;
            form.district = d.bairro    ?? form.district;
            form.city     = d.localidade ?? form.city;
            form.state    = d.uf        ?? form.state;
        }
    } catch { /* silent */ }
}

const tabErrors = computed(() => ({
    dados:    ['name','subdomain','email','telephone','cellphone','national_registration','state_registration','municipal_registration','website'].some(k => k in form.errors),
    endereco: ['zipcode','address','number','complement','district','city','state','country'].some(k => k in form.errors),
    config:   ['schedule_interval','active'].some(k => k in form.errors),
}));
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="580"
        :loading="loading"
        :loading-label="t.loading"
        @close="$emit('close')"
    >
        <!-- Header -->
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-building me-2 text-primary"></i>{{ title }}
            </h5>
        </template>

        <!-- Tabs -->
        <template #tabs>
            <ul class="nav nav-tabs border-0">
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: activeTab==='dados', 'text-danger': tabErrors.dados }" @click="activeTab='dados'">
                        <i class="ti ti-building me-1"></i> {{ t.tab_data }}
                        <i v-if="tabErrors.dados" class="ti ti-alert-circle text-danger ms-1 fs-12"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: activeTab==='endereco', 'text-danger': tabErrors.endereco }" @click="activeTab='endereco'">
                        <i class="ti ti-map-pin me-1"></i> {{ t.tab_address }}
                        <i v-if="tabErrors.endereco" class="ti ti-alert-circle text-danger ms-1 fs-12"></i>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" :class="{ active: activeTab==='config', 'text-danger': tabErrors.config }" @click="activeTab='config'">
                        <i class="ti ti-settings me-1"></i> {{ t.tab_config }}
                        <i v-if="tabErrors.config" class="ti ti-alert-circle text-danger ms-1 fs-12"></i>
                    </button>
                </li>
            </ul>
        </template>

        <!-- Body -->
        <form @submit.prevent="submit">

            <!-- TAB: Dados -->
            <div v-show="activeTab === 'dados'">
                <div class="row g-3">
                    <div class="col-8">
                        <label class="form-label">{{ t.field_name_required }}</label>
                        <input v-model="form.name" type="text" maxlength="150" class="form-control" autocomplete="off" :class="{ 'is-invalid': form.errors.name }">
                        <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                    </div>
                    <div class="col-4">
                        <label class="form-label">{{ t.field_subdomain }}</label>
                        <input v-model="form.subdomain" type="text" maxlength="100" class="form-control" :placeholder="t.field_subdomain_placeholder" :class="{ 'is-invalid': form.errors.subdomain }">
                        <div v-if="form.errors.subdomain" class="invalid-feedback">{{ form.errors.subdomain }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.field_email }}</label>
                        <input v-model="form.email" type="email" maxlength="150" class="form-control" :class="{ 'is-invalid': form.errors.email }">
                        <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.field_telephone }}</label>
                        <input v-model="form.telephone" type="text" maxlength="20" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.field_cellphone }}</label>
                        <input v-model="form.cellphone" type="text" maxlength="20" class="form-control">
                    </div>
                    <div class="col-4">
                        <label class="form-label">{{ t.field_national_registration }}</label>
                        <input v-model="form.national_registration" type="text" maxlength="20" class="form-control">
                    </div>
                    <div class="col-4">
                        <label class="form-label">{{ t.field_state_registration }}</label>
                        <input v-model="form.state_registration" type="text" maxlength="30" class="form-control">
                    </div>
                    <div class="col-4">
                        <label class="form-label">{{ t.field_municipal_registration }}</label>
                        <input v-model="form.municipal_registration" type="text" maxlength="30" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.field_website }}</label>
                        <input v-model="form.website" type="text" maxlength="150" class="form-control" :placeholder="t.field_website_placeholder" :class="{ 'is-invalid': form.errors.website }">
                        <div v-if="form.errors.website" class="invalid-feedback">{{ form.errors.website }}</div>
                    </div>
                </div>
            </div>

            <!-- TAB: Endereço -->
            <div v-show="activeTab === 'endereco'">
                <div class="row g-3">
                    <div class="col-4">
                        <label class="form-label">{{ t.field_zipcode }}</label>
                        <div class="input-group">
                            <input v-model="form.zipcode" type="text" maxlength="10" class="form-control" :placeholder="t.field_zipcode_placeholder" @blur="lookupCep">
                            <button type="button" class="btn btn-outline-secondary" :title="t.btn_lookup_cep" @click="lookupCep">
                                <i class="ti ti-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.field_address }}</label>
                        <input v-model="form.address" type="text" maxlength="200" class="form-control">
                    </div>
                    <div class="col-2">
                        <label class="form-label">{{ t.field_number }}</label>
                        <input v-model="form.number" type="text" maxlength="10" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.field_complement }}</label>
                        <input v-model="form.complement" type="text" maxlength="100" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.field_district }}</label>
                        <input v-model="form.district" type="text" maxlength="100" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t.field_city_required }}</label>
                        <input v-model="form.city" type="text" maxlength="100" class="form-control" :class="{ 'is-invalid': form.errors.city }">
                        <div v-if="form.errors.city" class="invalid-feedback">{{ form.errors.city }}</div>
                    </div>
                    <div class="col-2">
                        <label class="form-label">{{ t.field_state_required }}</label>
                        <input v-model="form.state" type="text" maxlength="2" class="form-control text-uppercase" :class="{ 'is-invalid': form.errors.state }">
                        <div v-if="form.errors.state" class="invalid-feedback">{{ form.errors.state }}</div>
                    </div>
                    <div class="col-4">
                        <label class="form-label">{{ t.field_country_required }}</label>
                        <input v-model="form.country" type="text" maxlength="50" class="form-control" :class="{ 'is-invalid': form.errors.country }">
                        <div v-if="form.errors.country" class="invalid-feedback">{{ form.errors.country }}</div>
                    </div>
                </div>
            </div>

            <!-- TAB: Configurações -->
            <div v-show="activeTab === 'config'">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">{{ t.field_schedule_interval }}</label>
                        <select v-model.number="form.schedule_interval" class="form-select">
                            <option :value="15">{{ t.interval_15 }}</option>
                            <option :value="20">{{ t.interval_20 }}</option>
                            <option :value="30">{{ t.interval_30 }}</option>
                        </select>
                    </div>
                    <div v-if="isEdit" class="col-6">
                        <label class="form-label">{{ t.field_status }}</label>
                        <select v-model="form.active" class="form-select">
                            <option :value="true">{{ t.status_option_active }}</option>
                            <option :value="false">{{ t.status_option_inactive }}</option>
                        </select>
                    </div>
                </div>
                <div v-if="!isEdit" class="alert alert-info small mt-3 py-2">
                    <i class="ti ti-info-circle me-1"></i> {{ t.config_info_after_create }}
                </div>
            </div>
        </form>

        <!-- Footer -->
        <template #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ t.btn_cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                {{ isEdit ? t.btn_save_changes : t.btn_create_company }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
