<script setup>
import { computed, ref, watch, useId } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import PatientAllergiesPanel from '@/Components/Panel/ClinicalSafety/PatientAllergiesPanel.vue';
import PatientMedicationsPanel from '@/Components/Panel/ClinicalSafety/PatientMedicationsPanel.vue';
import { useClinicalSafety } from '@/composables/useClinicalSafety.js';

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
    // Convênio já salvo ({ id, name, active } do editData): `covenants` só traz
    // ativos; o inativo que o paciente já tem entra marcado "(inativo)".
    covenantOption: { type: Object, default: null },
    // Paciente já cadastrado: alergias/medicações gravam na hora (Fase 2).
    // Sem id (paciente novo): vão junto com o cadastro.
    patientId: { type: String, default: null },
});

// ── Alergias e medicações em uso (Fase 2 / A1-A2) ─────────────────────────
// Carrega só quando a aba Clínico é aberta (leitura de dado sensível fica
// registrada no servidor).
const safety = useClinicalSafety(computed(() => props.patientId));
let safetyLoadedFor = null;
watch(
    () => [props.section, props.patientId],
    ([section, id]) => {
        // Outro paciente (ou nenhum): nada do anterior fica na tela, e a
        // resposta dele, se ainda pendente, é descartada.
        if (String(id ?? '') !== String(safetyLoadedFor ?? '')) {
            safety.cancel();
            safety.summary = null;
            safetyLoadedFor = null;
        }
        if (!id) return;
        if (section === 'clinical' && safetyLoadedFor !== id) {
            safetyLoadedFor = id;
            // Falhou: desmarca pra trocar de aba e voltar tentar de novo —
            // sem isto, o bloco ficava vazio pra sempre pro mesmo paciente
            // (18ª revisão Codex, P2). Só desmarca se ainda for ESTE
            // paciente (senão pisa no carregamento de outro já em curso).
            safety.load().then((ok) => {
                if (!ok && safetyLoadedFor === id) safetyLoadedFor = null;
            });
        }
    },
    { immediate: true },
);
// Paciente novo: listas pendentes vão no próprio formulário (usePatientForm).
function setPending(key, value) {
    // eslint-disable-next-line vue/no-mutating-props -- `form` é o useForm compartilhado (ver topo do arquivo)
    Object.assign(props.form, { [key]: value });
}
const medicineSearchUrl = computed(() => {
    try {
        return route('panel.medicines.search');
    } catch {
        return '';
    }
});

// Textos compartilhados (lang/<locale>/ui.php → patient_form) — o mesmo
// componente serve Pacientes e Agenda, cada um com o seu `t` de página.
const page = usePage();
const sharedUi = computed(() => page?.props?.t_ui ?? {});
const idPrefix = useId();
const fieldId = (field) => `patient-${idPrefix}-${field}`;
const ui = computed(() => page?.props?.t_ui?.patient_form ?? {});

const covenantOptions = computed(() => {
    const current = props.covenantOption;
    if (!current?.id || props.covenants.some((c) => c.id === current.id)) return props.covenants;

    const label = current.active ? current.name : `${current.name} ${ui.value.covenant_inactive_suffix ?? '(inativo)'}`;
    return [...props.covenants, { id: current.id, name: label }];
});

// Homônimo: o servidor avisa (erro em confirm_homonym) e a recepção confirma
// que é outra pessoa (trocar nome/nascimento desfaz — ver usePatientForm).
const homonymId = `homonym-confirm-${Math.random().toString(36).slice(2, 8)}`;

// Particular não tem carteirinha — o backend já descarta o valor
// (PatientService::isParticular); aqui só evita o usuário digitar à toa.
const isParticular = computed(() => {
    const selected = covenantOptions.value.find((c) => c.id === props.form.covenant_id);
    return (selected?.name ?? '').trim().toUpperCase() === 'PARTICULAR';
});

watch(isParticular, (particular) => {
    if (particular) {
        props.form.card_number = '';
        props.form.covenant_plan_id = '';
    }
});

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
    <div v-show="section === 'personal'" data-patient-section="personal">
        <div class="mb-3">
            <label :for="fieldId('name')" class="form-label">Nome completo <span class="text-danger">*</span></label>
            <input
                :id="fieldId('name')"
                v-model="form.name"
                aria-required="true"
                :aria-invalid="!!form.errors.name"
                :aria-describedby="form.errors.name ? fieldId('name') + '-error' : undefined"
                type="text"
                class="form-control"
                :class="{ 'is-invalid': form.errors.name }"
            />
            <div v-if="form.errors.name" :id="fieldId('name') + '-error'" class="invalid-feedback">
                {{ form.errors.name }}
            </div>
        </div>

        <!-- Homônimo: mesmo nome de outro paciente desta clínica (com outra data
             de nascimento ou sem ela). Salva só com a confirmação explícita. -->
        <div
            v-if="form.errors.confirm_homonym"
            class="alert alert-warning py-2 mb-3"
            role="alert"
            data-testid="homonym-alert"
        >
            <strong class="d-block mb-1">
                <i class="ti ti-users me-1" aria-hidden="true"></i>{{ ui.homonym_title ?? 'Paciente com o mesmo nome' }}
            </strong>
            <p class="mb-2 small">{{ form.errors.confirm_homonym }}</p>
            <div class="form-check mb-0">
                <input
                    :id="homonymId"
                    v-model="form.confirm_homonym"
                    type="checkbox"
                    class="form-check-input"
                    data-testid="homonym-confirm"
                />
                <label class="form-check-label fw-semibold" :for="homonymId">
                    {{ ui.homonym_confirm ?? 'Confirmo que é outro paciente' }}
                </label>
            </div>
        </div>

        <div class="mb-3">
            <label :for="fieldId('nickname')" class="form-label">Apelido</label>
            <input
                :id="fieldId('nickname')"
                v-model="form.nickname"
                :aria-invalid="!!form.errors.nickname"
                :aria-describedby="form.errors.nickname ? fieldId('nickname') + '-error' : undefined"
                :class="{ 'is-invalid': form.errors.nickname }"
                type="text"
                class="form-control"
            />
            <div v-if="form.errors.nickname" :id="fieldId('nickname') + '-error'" class="invalid-feedback d-block">
                {{ form.errors.nickname }}
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6">
                <label :for="fieldId('birth_date')" class="form-label"
                    >Data de nascimento <span class="text-danger">*</span></label
                >
                <input
                    :id="fieldId('birth_date')"
                    v-model="form.birth_date"
                    aria-required="true"
                    :aria-invalid="!!form.errors.birth_date"
                    :aria-describedby="form.errors.birth_date ? fieldId('birth_date') + '-error' : undefined"
                    type="date"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.birth_date }"
                />
                <div v-if="form.errors.birth_date" :id="fieldId('birth_date') + '-error'" class="invalid-feedback">
                    {{ form.errors.birth_date }}
                </div>
            </div>
            <div class="col-6">
                <label :for="fieldId('gender')" class="form-label">Gênero <span class="text-danger">*</span></label>
                <SearchSelect
                    :id="fieldId('gender')"
                    v-model="form.gender"
                    aria-required="true"
                    :aria-invalid="!!form.errors.gender"
                    :aria-describedby="form.errors.gender ? fieldId('gender') + '-error' : undefined"
                    :options="genderOptions"
                    :value-key="'value'"
                    :label-key="'label'"
                    :placeholder="'Selecione'"
                    :clearable="false"
                    :invalid="!!form.errors.gender"
                />
                <div v-if="form.errors.gender" :id="fieldId('gender') + '-error'" class="invalid-feedback d-block">
                    {{ form.errors.gender }}
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6">
                <label :for="fieldId('marital_status')" class="form-label"
                    >Estado civil <span class="text-danger">*</span></label
                >
                <SearchSelect
                    :id="fieldId('marital_status')"
                    v-model="form.marital_status"
                    aria-required="true"
                    :aria-invalid="!!form.errors.marital_status"
                    :aria-describedby="form.errors.marital_status ? fieldId('marital_status') + '-error' : undefined"
                    :options="maritalOptions"
                    :value-key="'value'"
                    :label-key="'label'"
                    :placeholder="'Selecione'"
                    :clearable="false"
                    :invalid="!!form.errors.marital_status"
                />
                <div
                    v-if="form.errors.marital_status"
                    :id="fieldId('marital_status') + '-error'"
                    class="invalid-feedback d-block"
                >
                    {{ form.errors.marital_status }}
                </div>
            </div>
            <div class="col-6">
                <label :for="fieldId('national_registry')" class="form-label"
                    >CPF <span class="text-danger">*</span></label
                >
                <input
                    :id="fieldId('national_registry')"
                    v-model="form.national_registry"
                    v-mask="'cpf'"
                    aria-required="true"
                    :aria-invalid="!!form.errors.national_registry"
                    :aria-describedby="
                        form.errors.national_registry ? fieldId('national_registry') + '-error' : undefined
                    "
                    type="text"
                    inputmode="numeric"
                    class="form-control"
                    placeholder="000.000.000-00"
                    :class="{ 'is-invalid': form.errors.national_registry }"
                />
                <div
                    v-if="form.errors.national_registry"
                    :id="fieldId('national_registry') + '-error'"
                    class="invalid-feedback"
                >
                    {{ form.errors.national_registry }}
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label :for="fieldId('email')" class="form-label">E-mail <span class="text-danger">*</span></label>
            <input
                :id="fieldId('email')"
                v-model="form.email"
                aria-required="true"
                :aria-invalid="!!form.errors.email"
                :aria-describedby="form.errors.email ? fieldId('email') + '-error' : undefined"
                type="email"
                class="form-control"
                :class="{ 'is-invalid': form.errors.email }"
            />
            <div v-if="form.errors.email" :id="fieldId('email') + '-error'" class="invalid-feedback">
                {{ form.errors.email }}
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6">
                <label :for="fieldId('mother_name')" class="form-label">Nome da mãe</label>
                <input
                    :id="fieldId('mother_name')"
                    v-model="form.mother_name"
                    :aria-invalid="!!form.errors.mother_name"
                    :aria-describedby="form.errors.mother_name ? fieldId('mother_name') + '-error' : undefined"
                    :class="{ 'is-invalid': form.errors.mother_name }"
                    type="text"
                    class="form-control"
                />
                <div
                    v-if="form.errors.mother_name"
                    :id="fieldId('mother_name') + '-error'"
                    class="invalid-feedback d-block"
                >
                    {{ form.errors.mother_name }}
                </div>
            </div>
            <div class="col-6">
                <label :for="fieldId('father_name')" class="form-label">Nome do pai</label>
                <input
                    :id="fieldId('father_name')"
                    v-model="form.father_name"
                    :aria-invalid="!!form.errors.father_name"
                    :aria-describedby="form.errors.father_name ? fieldId('father_name') + '-error' : undefined"
                    :class="{ 'is-invalid': form.errors.father_name }"
                    type="text"
                    class="form-control"
                />
                <div
                    v-if="form.errors.father_name"
                    :id="fieldId('father_name') + '-error'"
                    class="invalid-feedback d-block"
                >
                    {{ form.errors.father_name }}
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label for="patient-occupation" class="form-label">{{ ui.occupation ?? 'Profissão' }}</label>
            <input
                id="patient-occupation"
                v-model="form.occupation"
                :aria-invalid="!!form.errors.occupation"
                :aria-describedby="form.errors.occupation ? 'patient-occupation' + '-error' : undefined"
                type="text"
                class="form-control"
                maxlength="120"
                autocomplete="organization-title"
                :placeholder="ui.occupation_placeholder"
                :class="{ 'is-invalid': form.errors.occupation }"
            />
            <div v-if="form.errors.occupation" :id="'patient-occupation' + '-error'" class="invalid-feedback">
                {{ form.errors.occupation }}
            </div>
        </div>

        <div v-if="isEdit" class="mb-3">
            <div class="form-check form-switch">
                <input
                    id="chkActive"
                    v-model="form.active"
                    :aria-invalid="!!form.errors.active"
                    :aria-describedby="form.errors.active ? 'chkActive' + '-error' : undefined"
                    :class="{ 'is-invalid': form.errors.active }"
                    class="form-check-input"
                    type="checkbox"
                />
                <div v-if="form.errors.active" :id="'chkActive' + '-error'" class="invalid-feedback d-block">
                    {{ form.errors.active }}
                </div>
                <label class="form-check-label" for="chkActive">Paciente ativo</label>
            </div>
        </div>
    </div>

    <!-- TAB: Clínico -->
    <div v-show="section === 'clinical'" data-patient-section="clinical">
        <div class="mb-3">
            <label :for="fieldId('covenant_id')" class="form-label">Convênio <span class="text-danger">*</span></label>
            <SearchSelect
                :id="fieldId('covenant_id')"
                v-model="form.covenant_id"
                aria-required="true"
                :aria-invalid="!!form.errors.covenant_id"
                :aria-describedby="form.errors.covenant_id ? fieldId('covenant_id') + '-error' : undefined"
                :options="covenantOptions"
                :placeholder="'Selecione'"
                :invalid="!!form.errors.covenant_id"
                @change="onCovenantChange"
            />
            <div
                v-if="form.errors.covenant_id"
                :id="fieldId('covenant_id') + '-error'"
                class="invalid-feedback d-block"
            >
                {{ form.errors.covenant_id }}
            </div>
        </div>

        <div class="mb-3 patient-plan-field">
            <label :for="fieldId('covenant_plan_id')" class="form-label">{{ ui.plan }}</label>
            <SearchSelect
                :id="fieldId('covenant_plan_id')"
                v-model="form.covenant_plan_id"
                :aria-invalid="!!form.errors.covenant_plan_id"
                :aria-describedby="form.errors.covenant_plan_id ? fieldId('covenant_plan_id') + '-error' : undefined"
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
            <div
                v-if="form.errors.covenant_plan_id"
                :id="fieldId('covenant_plan_id') + '-error'"
                class="invalid-feedback d-block"
            >
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
            <label :for="fieldId('card_number')" class="form-label">Número da carteirinha</label>
            <input
                :id="fieldId('card_number')"
                v-model="form.card_number"
                :aria-invalid="!!form.errors.card_number"
                :aria-describedby="form.errors.card_number ? fieldId('card_number') + '-error' : undefined"
                :class="{ 'is-invalid': form.errors.card_number }"
                type="text"
                class="form-control"
                :disabled="isParticular"
                :placeholder="isParticular ? 'Não se aplica a Particular' : ''"
            />
            <div
                v-if="form.errors.card_number"
                :id="fieldId('card_number') + '-error'"
                class="invalid-feedback d-block"
            >
                {{ form.errors.card_number }}
            </div>
            <small v-if="isParticular" class="text-muted">Paciente particular não tem carteirinha de convênio.</small>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6">
                <label :for="fieldId('skin_id')" class="form-label">Tipo de pele</label>
                <SearchSelect
                    :id="fieldId('skin_id')"
                    v-model="form.skin_id"
                    :aria-invalid="!!form.errors.skin_id"
                    :aria-describedby="form.errors.skin_id ? fieldId('skin_id') + '-error' : undefined"
                    :invalid="!!form.errors.skin_id"
                    :options="skinTypes"
                    :placeholder="'Não informado'"
                />
                <div v-if="form.errors.skin_id" :id="fieldId('skin_id') + '-error'" class="invalid-feedback d-block">
                    {{ form.errors.skin_id }}
                </div>
            </div>
            <div class="col-6">
                <label :for="fieldId('iris_id')" class="form-label">Tipo de íris</label>
                <SearchSelect
                    :id="fieldId('iris_id')"
                    v-model="form.iris_id"
                    :aria-invalid="!!form.errors.iris_id"
                    :aria-describedby="form.errors.iris_id ? fieldId('iris_id') + '-error' : undefined"
                    :invalid="!!form.errors.iris_id"
                    :options="irisTypes"
                    :placeholder="'Não informado'"
                />
                <div v-if="form.errors.iris_id" :id="fieldId('iris_id') + '-error'" class="invalid-feedback d-block">
                    {{ form.errors.iris_id }}
                </div>
            </div>
        </div>

        <!-- Alergias e medicações em uso (Fase 2): a recepção registra como
             informado pelo paciente; o médico confirma no atendimento. -->
        <div class="border rounded p-3 mb-3 patient-clinical-safety" data-testid="patient-clinical-safety">
            <template v-if="patientId">
                <div v-if="safety.loading" class="text-center py-2">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                </div>
                <template v-else-if="safety.summary">
                    <PatientAllergiesPanel mode="live" :store="safety" :search-url="medicineSearchUrl" class="mb-3" />
                    <PatientMedicationsPanel mode="live" :store="safety" :search-url="medicineSearchUrl" />
                </template>
                <div v-else-if="safety.error" class="text-danger small py-2" role="alert">
                    {{
                        ui.safety_load_error ??
                        'Não foi possível carregar alergias e medicações. Troque de aba e volte para tentar de novo.'
                    }}
                </div>
            </template>
            <template v-else>
                <PatientAllergiesPanel
                    :allergies="form.allergies"
                    :denied="form.allergies_denied"
                    mode="pending"
                    :search-url="medicineSearchUrl"
                    :errors="form.errors"
                    class="mb-3"
                    @update:allergies="setPending('allergies', $event)"
                    @update:denied="setPending('allergies_denied', $event)"
                />
                <PatientMedicationsPanel
                    :medications="form.medications"
                    mode="pending"
                    :search-url="medicineSearchUrl"
                    :errors="form.errors"
                    @update:medications="setPending('medications', $event)"
                />
            </template>
        </div>

        <div class="card border-0 bg-light p-3 mt-2">
            <p class="text-muted small fw-medium mb-2">Registro estadual (opcional)</p>
            <div class="row g-3">
                <div class="col-6">
                    <label :for="fieldId('state_registry')" class="form-label small">RG</label>
                    <input
                        :id="fieldId('state_registry')"
                        v-model="form.state_registry"
                        :aria-invalid="!!form.errors.state_registry"
                        :aria-describedby="
                            form.errors.state_registry ? fieldId('state_registry') + '-error' : undefined
                        "
                        :class="{ 'is-invalid': form.errors.state_registry }"
                        type="text"
                        class="form-control"
                    />
                    <div
                        v-if="form.errors.state_registry"
                        :id="fieldId('state_registry') + '-error'"
                        class="invalid-feedback d-block"
                    >
                        {{ form.errors.state_registry }}
                    </div>
                </div>
                <div class="col-6">
                    <label :for="fieldId('state_registry_agency')" class="form-label small">Órgão emissor</label>
                    <input
                        :id="fieldId('state_registry_agency')"
                        v-model="form.state_registry_agency"
                        :aria-invalid="!!form.errors.state_registry_agency"
                        :aria-describedby="
                            form.errors.state_registry_agency ? fieldId('state_registry_agency') + '-error' : undefined
                        "
                        :class="{ 'is-invalid': form.errors.state_registry_agency }"
                        type="text"
                        class="form-control"
                    />
                    <div
                        v-if="form.errors.state_registry_agency"
                        :id="fieldId('state_registry_agency') + '-error'"
                        class="invalid-feedback d-block"
                    >
                        {{ form.errors.state_registry_agency }}
                    </div>
                </div>
                <div class="col-6">
                    <label :for="fieldId('state_registry_initial')" class="form-label small">UF do RG</label>
                    <SearchSelect
                        :id="fieldId('state_registry_initial')"
                        v-model="form.state_registry_initial"
                        :aria-invalid="!!form.errors.state_registry_initial"
                        :aria-describedby="
                            form.errors.state_registry_initial
                                ? fieldId('state_registry_initial') + '-error'
                                : undefined
                        "
                        :invalid="!!form.errors.state_registry_initial"
                        :options="stateOptions"
                        :value-key="'value'"
                        :label-key="'value'"
                        :placeholder="'—'"
                    />
                    <div
                        v-if="form.errors.state_registry_initial"
                        :id="fieldId('state_registry_initial') + '-error'"
                        class="invalid-feedback d-block"
                    >
                        {{ form.errors.state_registry_initial }}
                    </div>
                </div>
                <div class="col-6">
                    <label :for="fieldId('state_registry_date')" class="form-label small">Data do RG</label>
                    <input
                        :id="fieldId('state_registry_date')"
                        v-model="form.state_registry_date"
                        :aria-invalid="!!form.errors.state_registry_date"
                        :aria-describedby="
                            form.errors.state_registry_date ? fieldId('state_registry_date') + '-error' : undefined
                        "
                        :class="{ 'is-invalid': form.errors.state_registry_date }"
                        type="date"
                        class="form-control"
                    />
                    <div
                        v-if="form.errors.state_registry_date"
                        :id="fieldId('state_registry_date') + '-error'"
                        class="invalid-feedback d-block"
                    >
                        {{ form.errors.state_registry_date }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB: Contato -->
    <div v-show="section === 'contact'" data-patient-section="contact">
        <div class="mb-3">
            <label :for="fieldId('cellphone')" class="form-label">Celular <span class="text-danger">*</span></label>
            <input
                :id="fieldId('cellphone')"
                v-model="form.cellphone"
                v-mask="'phone'"
                aria-required="true"
                :aria-invalid="!!form.errors.cellphone"
                :aria-describedby="form.errors.cellphone ? fieldId('cellphone') + '-error' : undefined"
                type="text"
                inputmode="numeric"
                class="form-control"
                placeholder="(00) 00000-0000"
                :class="{ 'is-invalid': form.errors.cellphone }"
            />
            <div v-if="form.errors.cellphone" :id="fieldId('cellphone') + '-error'" class="invalid-feedback">
                {{ form.errors.cellphone }}
            </div>
        </div>

        <div class="mb-3">
            <div class="form-check">
                <input
                    id="chkWhatsapp"
                    v-model="form.whatsapp"
                    :aria-invalid="!!form.errors.whatsapp"
                    :aria-describedby="form.errors.whatsapp ? 'chkWhatsapp' + '-error' : undefined"
                    :class="{ 'is-invalid': form.errors.whatsapp }"
                    class="form-check-input"
                    type="checkbox"
                />
                <div v-if="form.errors.whatsapp" :id="'chkWhatsapp' + '-error'" class="invalid-feedback d-block">
                    {{ form.errors.whatsapp }}
                </div>
                <label class="form-check-label" for="chkWhatsapp">
                    <i class="fab fa-whatsapp text-success me-1"></i>Este celular tem WhatsApp
                </label>
            </div>
        </div>

        <!-- Autorização de lembretes de retorno por WhatsApp (LGPD): só faz sentido com WhatsApp no celular. -->
        <div class="mb-3">
            <div class="form-check">
                <input
                    id="chkCareReminders"
                    class="form-check-input"
                    type="checkbox"
                    :checked="form.care_reminders_consent === true"
                    :disabled="!form.whatsapp"
                    :aria-describedby="
                        form.errors.care_reminders_consent
                            ? 'chkCareRemindersHint chkCareRemindersError'
                            : 'chkCareRemindersHint'
                    "
                    :aria-invalid="!!form.errors.care_reminders_consent"
                    :class="{ 'is-invalid': form.errors.care_reminders_consent }"
                    data-testid="care-reminders-consent"
                    @change="form.care_reminders_consent = $event.target.checked"
                />
                <label class="form-check-label" for="chkCareReminders">{{ ui.care_reminders }}</label>
            </div>
            <div id="chkCareRemindersHint" class="form-text">
                {{ form.whatsapp ? ui.care_reminders_hint : ui.care_reminders_needs_whatsapp }}
            </div>
            <div
                v-if="form.errors.care_reminders_consent"
                id="chkCareRemindersError"
                class="text-danger small"
                role="alert"
            >
                {{ form.errors.care_reminders_consent }}
            </div>
        </div>

        <div class="mb-3">
            <label :for="fieldId('telephone')" class="form-label">Telefone fixo</label>
            <input
                :id="fieldId('telephone')"
                v-model="form.telephone"
                v-mask="'phone'"
                :aria-invalid="!!form.errors.telephone"
                :aria-describedby="form.errors.telephone ? fieldId('telephone') + '-error' : undefined"
                :class="{ 'is-invalid': form.errors.telephone }"
                type="text"
                inputmode="numeric"
                class="form-control"
                placeholder="(00) 0000-0000"
            />
            <div v-if="form.errors.telephone" :id="fieldId('telephone') + '-error'" class="invalid-feedback d-block">
                {{ form.errors.telephone }}
            </div>
        </div>
    </div>

    <!-- TAB: Endereço -->
    <div v-show="section === 'address'" data-patient-section="address">
        <div class="mb-3">
            <label :for="fieldId('zipcode')" class="form-label">CEP</label>
            <div class="input-group">
                <input
                    :id="fieldId('zipcode')"
                    v-model="form.zipcode"
                    v-mask="'cep'"
                    :aria-invalid="!!form.errors.zipcode"
                    :aria-describedby="form.errors.zipcode ? fieldId('zipcode') + '-error' : undefined"
                    :class="{ 'is-invalid': form.errors.zipcode }"
                    type="text"
                    inputmode="numeric"
                    class="form-control"
                    placeholder="00000-000"
                    @blur="lookupCep"
                />
                <div v-if="form.errors.zipcode" :id="fieldId('zipcode') + '-error'" class="invalid-feedback d-block">
                    {{ form.errors.zipcode }}
                </div>
                <ActionIconButton icon="ti ti-search" :title="sharedUi.cep_lookup || 'Buscar CEP'" @click="lookupCep" />
            </div>
        </div>

        <div class="mb-3">
            <label :for="fieldId('address')" class="form-label">Logradouro</label>
            <input
                :id="fieldId('address')"
                v-model="form.address"
                :aria-invalid="!!form.errors.address"
                :aria-describedby="form.errors.address ? fieldId('address') + '-error' : undefined"
                :class="{ 'is-invalid': form.errors.address }"
                type="text"
                class="form-control"
            />
            <div v-if="form.errors.address" :id="fieldId('address') + '-error'" class="invalid-feedback d-block">
                {{ form.errors.address }}
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-4">
                <label :for="fieldId('number')" class="form-label">Número</label>
                <input
                    :id="fieldId('number')"
                    v-model="form.number"
                    :aria-invalid="!!form.errors.number"
                    :aria-describedby="form.errors.number ? fieldId('number') + '-error' : undefined"
                    :class="{ 'is-invalid': form.errors.number }"
                    type="text"
                    class="form-control"
                />
                <div v-if="form.errors.number" :id="fieldId('number') + '-error'" class="invalid-feedback d-block">
                    {{ form.errors.number }}
                </div>
            </div>
            <div class="col-8">
                <label :for="fieldId('complement')" class="form-label">Complemento</label>
                <input
                    :id="fieldId('complement')"
                    v-model="form.complement"
                    :aria-invalid="!!form.errors.complement"
                    :aria-describedby="form.errors.complement ? fieldId('complement') + '-error' : undefined"
                    :class="{ 'is-invalid': form.errors.complement }"
                    type="text"
                    class="form-control"
                />
                <div
                    v-if="form.errors.complement"
                    :id="fieldId('complement') + '-error'"
                    class="invalid-feedback d-block"
                >
                    {{ form.errors.complement }}
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label :for="fieldId('district')" class="form-label">Bairro</label>
            <input
                :id="fieldId('district')"
                v-model="form.district"
                :aria-invalid="!!form.errors.district"
                :aria-describedby="form.errors.district ? fieldId('district') + '-error' : undefined"
                :class="{ 'is-invalid': form.errors.district }"
                type="text"
                class="form-control"
            />
            <div v-if="form.errors.district" :id="fieldId('district') + '-error'" class="invalid-feedback d-block">
                {{ form.errors.district }}
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-8">
                <label :for="fieldId('city')" class="form-label">Cidade</label>
                <input
                    :id="fieldId('city')"
                    v-model="form.city"
                    :aria-invalid="!!form.errors.city"
                    :aria-describedby="form.errors.city ? fieldId('city') + '-error' : undefined"
                    :class="{ 'is-invalid': form.errors.city }"
                    type="text"
                    class="form-control"
                />
                <div v-if="form.errors.city" :id="fieldId('city') + '-error'" class="invalid-feedback d-block">
                    {{ form.errors.city }}
                </div>
            </div>
            <div class="col-4">
                <label :for="fieldId('state')" class="form-label">UF</label>
                <SearchSelect
                    :id="fieldId('state')"
                    v-model="form.state"
                    :aria-invalid="!!form.errors.state"
                    :aria-describedby="form.errors.state ? fieldId('state') + '-error' : undefined"
                    :invalid="!!form.errors.state"
                    :options="stateOptions"
                    :value-key="'value'"
                    :label-key="'value'"
                    :placeholder="'—'"
                />
                <div v-if="form.errors.state" :id="fieldId('state') + '-error'" class="invalid-feedback d-block">
                    {{ form.errors.state }}
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label :for="fieldId('country')" class="form-label">País</label>
            <input
                :id="fieldId('country')"
                v-model="form.country"
                :aria-invalid="!!form.errors.country"
                :aria-describedby="form.errors.country ? fieldId('country') + '-error' : undefined"
                :class="{ 'is-invalid': form.errors.country }"
                type="text"
                class="form-control"
            />
            <div v-if="form.errors.country" :id="fieldId('country') + '-error'" class="invalid-feedback d-block">
                {{ form.errors.country }}
            </div>
        </div>
    </div>
</template>
