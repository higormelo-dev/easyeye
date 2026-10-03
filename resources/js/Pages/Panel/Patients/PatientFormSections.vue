<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * PatientFormSections — corpo das abas do cadastro de paciente
 * (Pessoal / Clínico / Contato / Endereço), sem chrome de modal.
 *
 * Usado por PatientFormModal (Pacientes) e ScheduleFormModal (abas do
 * agendamento). O `form` é o useForm de usePatientForm — reativo, os
 * v-model gravam direto nele. `section` controla qual aba está visível
 * (v-show: as ocultas continuam montadas, preservando estado).
 */
const props = defineProps({
    form: { type: Object, required: true },
    section: { type: String, required: true }, // personal | clinical | contact | address
    covenants: { type: Array, default: () => [] },
    skinTypes: { type: Array, default: () => [] },
    irisTypes: { type: Array, default: () => [] },
    genderOptions: { type: Array, default: () => [] },
    maritalOptions: { type: Array, default: () => [] },
    stateOptions: { type: Array, default: () => [] },
    isEdit: { type: Boolean, default: false },
    lookupCep: { type: Function, default: () => {} },
    // Plano já salvo (opção completa vinda do editData) — rótulo do seletor.
    planOption: { type: Object, default: null },
});

// Particular não tem carteirinha — o backend já descarta o valor
// (PatientService::isParticular); aqui só evita o usuário digitar à toa.
const isParticular = computed(() => {
    const selected = props.covenants.find((c) => c.id === props.form.covenant_id);
    return (selected?.name ?? '').trim().toUpperCase() === 'PARTICULAR';
});

watch(isParticular, (particular) => {
    if (particular) {
        props.form.card_number = '';
        props.form.covenant_plan_id = '';
    }
});

// Textos compartilhados (lang/<locale>/ui.php → patient_form) — o mesmo
// componente serve Pacientes e Agenda, cada um com o seu `t` de página.
const page = usePage();
const ui = computed(() => page?.props?.t_ui?.patient_form ?? {});

// ── Plano do convênio ─────────────────────────────────────────────────────
// Lista inicial (primeiros planos do convênio) + busca no servidor ao
// digitar: a maior operadora tem ~4,5 mil planos.
const planSeed = ref([]);
const planLoading = ref(false);
const pickedPlan = ref(null); // opção escolhida agora (detalhes abaixo do campo)
let planRequest = 0;

async function loadPlans(covenantId) {
    const request = ++planRequest;
    planSeed.value = [];

    if (!covenantId || isParticular.value) {
        planLoading.value = false;
        return;
    }

    planLoading.value = true;
    try {
        const { data } = await axios.get(route('panel.covenant-plans.search'), { params: { covenant_id: covenantId } });
        if (request === planRequest) planSeed.value = data?.data ?? [];
    } catch {
        // sem lista inicial: a busca ao digitar continua funcionando
    } finally {
        if (request === planRequest) planLoading.value = false;
    }
}

watch(() => props.form.covenant_id, loadPlans, { immediate: true });

// Trocar o convênio na tela invalida o plano (cada plano é de um convênio).
// Só na ação do usuário: carregar um cadastro também muda o convênio.
function onCovenantChange() {
    props.form.covenant_plan_id = '';
    pickedPlan.value = null;
}

const planOptions = computed(() => {
    const saved = props.planOption;

    if (!saved || saved.id !== props.form.covenant_plan_id || planSeed.value.some((p) => p.id === saved.id)) {
        return planSeed.value;
    }

    return [saved, ...planSeed.value];
});

const currentPlan = computed(() => {
    const id = props.form.covenant_plan_id;
    if (!id) return null;
    if (pickedPlan.value?.id === id) return pickedPlan.value;

    return planOptions.value.find((p) => p.id === id) ?? null;
});

const planSearchUrl = computed(() =>
    props.form.covenant_id && !isParticular.value
        ? route('panel.covenant-plans.search', { covenant_id: props.form.covenant_id, q: '__Q__' })
        : '',
);

const planPlaceholder = computed(() => {
    if (isParticular.value) return ui.value.plan_particular;
    if (!props.form.covenant_id) return ui.value.plan_select_covenant;

    return ui.value.plan_placeholder;
});

const showNoPlans = computed(
    () =>
        !planLoading.value &&
        !!props.form.covenant_id &&
        !isParticular.value &&
        !props.form.covenant_plan_id &&
        planSeed.value.length === 0,
);
</script>

<template>
    <!-- TAB: Pessoal -->
    <div v-show="section === 'personal'">
        <div class="mb-3">
            <label class="form-label">Nome completo <span class="text-danger">*</span></label>
            <input v-model="form.name" type="text" class="form-control" :class="{ 'is-invalid': form.errors.name }" />
            <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
        </div>

        <div class="mb-3">
            <label class="form-label">Apelido</label>
            <input v-model="form.nickname" type="text" class="form-control" />
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6">
                <label class="form-label">Data de nascimento <span class="text-danger">*</span></label>
                <input
                    v-model="form.birth_date"
                    type="date"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.birth_date }"
                />
                <div v-if="form.errors.birth_date" class="invalid-feedback">{{ form.errors.birth_date }}</div>
            </div>
            <div class="col-6">
                <label class="form-label">Gênero <span class="text-danger">*</span></label>
                <SearchSelect
                    v-model="form.gender"
                    :options="genderOptions"
                    :value-key="'value'"
                    :label-key="'label'"
                    :placeholder="'Selecione'"
                    :clearable="false"
                    :invalid="!!form.errors.gender"
                />
                <div v-if="form.errors.gender" class="invalid-feedback d-block">{{ form.errors.gender }}</div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6">
                <label class="form-label">Estado civil <span class="text-danger">*</span></label>
                <SearchSelect
                    v-model="form.marital_status"
                    :options="maritalOptions"
                    :value-key="'value'"
                    :label-key="'label'"
                    :placeholder="'Selecione'"
                    :clearable="false"
                    :invalid="!!form.errors.marital_status"
                />
                <div v-if="form.errors.marital_status" class="invalid-feedback d-block">
                    {{ form.errors.marital_status }}
                </div>
            </div>
            <div class="col-6">
                <label class="form-label">CPF <span class="text-danger">*</span></label>
                <input
                    v-model="form.national_registry"
                    v-mask="'cpf'"
                    type="text"
                    inputmode="numeric"
                    class="form-control"
                    placeholder="000.000.000-00"
                    :class="{ 'is-invalid': form.errors.national_registry }"
                />
                <div v-if="form.errors.national_registry" class="invalid-feedback">
                    {{ form.errors.national_registry }}
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">E-mail <span class="text-danger">*</span></label>
            <input
                v-model="form.email"
                type="email"
                class="form-control"
                :class="{ 'is-invalid': form.errors.email }"
            />
            <div v-if="form.errors.email" class="invalid-feedback">{{ form.errors.email }}</div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6">
                <label class="form-label">Nome da mãe</label>
                <input v-model="form.mother_name" type="text" class="form-control" />
            </div>
            <div class="col-6">
                <label class="form-label">Nome do pai</label>
                <input v-model="form.father_name" type="text" class="form-control" />
            </div>
        </div>

        <div class="mb-3">
            <label for="patient-occupation" class="form-label">{{ ui.occupation ?? 'Profissão' }}</label>
            <input
                id="patient-occupation"
                v-model="form.occupation"
                type="text"
                class="form-control"
                maxlength="120"
                autocomplete="organization-title"
                :placeholder="ui.occupation_placeholder"
                :class="{ 'is-invalid': form.errors.occupation }"
                :aria-invalid="form.errors.occupation ? 'true' : 'false'"
                :aria-describedby="form.errors.occupation ? 'patient-occupation-error' : undefined"
            />
            <div v-if="form.errors.occupation" id="patient-occupation-error" class="invalid-feedback">
                {{ form.errors.occupation }}
            </div>
        </div>

        <div v-if="isEdit" class="mb-3">
            <div class="form-check form-switch">
                <input id="chkActive" v-model="form.active" class="form-check-input" type="checkbox" />
                <label class="form-check-label" for="chkActive">Paciente ativo</label>
            </div>
        </div>
    </div>

    <!-- TAB: Clínico -->
    <div v-show="section === 'clinical'">
        <div class="mb-3">
            <label class="form-label">Convênio <span class="text-danger">*</span></label>
            <SearchSelect
                v-model="form.covenant_id"
                :options="covenants"
                :placeholder="'Selecione'"
                :invalid="!!form.errors.covenant_id"
                @change="onCovenantChange"
            />
            <div v-if="form.errors.covenant_id" class="invalid-feedback d-block">{{ form.errors.covenant_id }}</div>
        </div>

        <div class="mb-3 patient-plan-field">
            <label class="form-label">{{ ui.plan }}</label>
            <SearchSelect
                v-model="form.covenant_plan_id"
                :options="planOptions"
                value-key="id"
                label-key="label"
                :remote-search-url="planSearchUrl"
                :remote-min-chars="2"
                show-sub-label
                :disabled="!form.covenant_id || isParticular"
                :placeholder="planPlaceholder"
                :no-options-text="ui.plan_empty"
                :no-results-text="ui.plan_no_results"
                :invalid="!!form.errors.covenant_plan_id"
                @option-selected="pickedPlan = $event"
            />
            <div v-if="form.errors.covenant_plan_id" class="invalid-feedback d-block">
                {{ form.errors.covenant_plan_id }}
            </div>
            <div aria-live="polite">
                <small v-if="currentPlan?.sub_label" class="d-block text-muted mt-1 patient-plan-details">
                    {{ currentPlan.sub_label }}
                </small>
                <small v-if="currentPlan && currentPlan.active === false" class="d-block text-warning-emphasis mt-1">
                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ ui.plan_unavailable }}
                </small>
                <small v-else-if="showNoPlans" class="d-block text-muted mt-1 patient-plan-none">{{
                    ui.plan_none
                }}</small>
                <small v-else-if="!form.covenant_plan_id && !isParticular" class="d-block text-muted mt-1">
                    {{ ui.plan_hint }}
                </small>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Número da carteirinha</label>
            <input
                v-model="form.card_number"
                type="text"
                class="form-control"
                :disabled="isParticular"
                :placeholder="isParticular ? 'Não se aplica a Particular' : ''"
            />
            <small v-if="isParticular" class="text-muted">Paciente particular não tem carteirinha de convênio.</small>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6">
                <label class="form-label">Tipo de pele</label>
                <SearchSelect v-model="form.skin_id" :options="skinTypes" :placeholder="'Não informado'" />
            </div>
            <div class="col-6">
                <label class="form-label">Tipo de íris</label>
                <SearchSelect v-model="form.iris_id" :options="irisTypes" :placeholder="'Não informado'" />
            </div>
        </div>

        <div class="card border-0 bg-light p-3 mt-2">
            <p class="text-muted small fw-medium mb-2">Registro estadual (opcional)</p>
            <div class="row g-3">
                <div class="col-6">
                    <label class="form-label small">RG</label>
                    <input v-model="form.state_registry" type="text" class="form-control" />
                </div>
                <div class="col-6">
                    <label class="form-label small">Órgão emissor</label>
                    <input v-model="form.state_registry_agency" type="text" class="form-control" />
                </div>
                <div class="col-6">
                    <label class="form-label small">UF do RG</label>
                    <SearchSelect
                        v-model="form.state_registry_initial"
                        :options="stateOptions"
                        :value-key="'value'"
                        :label-key="'value'"
                        :placeholder="'—'"
                    />
                </div>
                <div class="col-6">
                    <label class="form-label small">Data do RG</label>
                    <input v-model="form.state_registry_date" type="date" class="form-control" />
                </div>
            </div>
        </div>
    </div>

    <!-- TAB: Contato -->
    <div v-show="section === 'contact'">
        <div class="mb-3">
            <label class="form-label">Celular <span class="text-danger">*</span></label>
            <input
                v-model="form.cellphone"
                v-mask="'phone'"
                type="text"
                inputmode="numeric"
                class="form-control"
                placeholder="(00) 00000-0000"
                :class="{ 'is-invalid': form.errors.cellphone }"
            />
            <div v-if="form.errors.cellphone" class="invalid-feedback">{{ form.errors.cellphone }}</div>
        </div>

        <div class="mb-3">
            <div class="form-check">
                <input id="chkWhatsapp" v-model="form.whatsapp" class="form-check-input" type="checkbox" />
                <label class="form-check-label" for="chkWhatsapp">
                    <i class="fab fa-whatsapp text-success me-1"></i>Este celular tem WhatsApp
                </label>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Telefone fixo</label>
            <input
                v-model="form.telephone"
                v-mask="'phone'"
                type="text"
                inputmode="numeric"
                class="form-control"
                placeholder="(00) 0000-0000"
            />
        </div>
    </div>

    <!-- TAB: Endereço -->
    <div v-show="section === 'address'">
        <div class="mb-3">
            <label class="form-label">CEP</label>
            <div class="input-group">
                <input
                    v-model="form.zipcode"
                    v-mask="'cep'"
                    type="text"
                    inputmode="numeric"
                    class="form-control"
                    placeholder="00000-000"
                    @blur="lookupCep"
                />
                <button type="button" class="btn btn-outline-secondary" @click="lookupCep">
                    <i class="ti ti-search"></i>
                </button>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Logradouro</label>
            <input v-model="form.address" type="text" class="form-control" />
        </div>

        <div class="row g-3 mb-3">
            <div class="col-4">
                <label class="form-label">Número</label>
                <input v-model="form.number" type="text" class="form-control" />
            </div>
            <div class="col-8">
                <label class="form-label">Complemento</label>
                <input v-model="form.complement" type="text" class="form-control" />
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Bairro</label>
            <input v-model="form.district" type="text" class="form-control" />
        </div>

        <div class="row g-3 mb-3">
            <div class="col-8">
                <label class="form-label">Cidade</label>
                <input v-model="form.city" type="text" class="form-control" />
            </div>
            <div class="col-4">
                <label class="form-label">UF</label>
                <SearchSelect
                    v-model="form.state"
                    :options="stateOptions"
                    :value-key="'value'"
                    :label-key="'value'"
                    :placeholder="'—'"
                />
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">País</label>
            <input v-model="form.country" type="text" class="form-control" />
        </div>
    </div>
</template>
