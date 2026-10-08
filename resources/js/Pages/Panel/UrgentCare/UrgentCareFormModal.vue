<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import QuickAllergyFields from '@/Components/Panel/ClinicalSafety/QuickAllergyFields.vue';
import { quickAllergyPayload } from '@/Support/clinicalSafety.js';

/**
 * Adicionar / editar item do pronto atendimento.
 *
 *  - Paciente: busca (Pacientes › search) ou cadastro rápido (nome, celular,
 *    nascimento opcional) com a regra de duplicado/homônimo do servidor
 *    (PatientDuplicateFinder): duplicado real bloqueia, homônimo pede
 *    "Confirmo que é outro paciente". Só no cadastro do item.
 *  - Motivo (lista oftalmológica) + descrição (obrigatória em "Outro").
 *  - Prioridade: o motivo sugere (queimadura química, trauma e perda súbita
 *    de visão → imediata) e a idade sugere a prioritária por lei (60+) —
 *    só enquanto a recepção não escolheu a prioridade à mão.
 *  - Médico preferido (opcional) e convênio (só ativos; vem do cadastro).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    entry: { type: Object, default: null },
    reasons: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    doctors: { type: Array, default: () => [] },
    covenants: { type: Array, default: () => [] },
    legalPriorityAge: { type: Number, default: 60 },
    canRegisterPatient: { type: Boolean, default: false },
    // Pacientes já na fila (não podem entrar de novo).
    queuedPatientIds: { type: Array, default: () => [] },
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved']);

const OTHER = 99;
const NORMAL = 3;
const LEGAL = 2;
const CHEMICAL_BURN = 3;

const isEdit = computed(() => Boolean(props.entry));

const form = useForm({
    patient_id: '',
    reason: null,
    reason_details: '',
    priority: NORMAL,
    preferred_doctor_id: '',
    covenant_id: '',
});

const patient = ref(null);
const priorityTouched = ref(false);
const suggestion = ref('');

function tx(key, params = {}) {
    return Object.entries(params).reduce(
        (text, [name, value]) => text.replace(`:${name}`, String(value)),
        props.t[key] ?? key,
    );
}

function reset() {
    const e = props.entry;
    form.patient_id = e?.patient_id ?? '';
    form.reason = e?.reason ?? null;
    form.reason_details = e?.reason_details ?? '';
    form.priority = e?.priority ?? NORMAL;
    form.preferred_doctor_id = e?.preferred_doctor_id ?? '';
    form.covenant_id = e?.covenant_id ?? '';
    form.clearErrors?.();
    patient.value = e
        ? { id: e.patient_id, full_name: e.patient_name, code: e.patient_code, age: e.age, covenant_id: e.covenant_id }
        : null;
    priorityTouched.value = Boolean(e);
    suggestion.value = '';
    search.value = '';
    results.value = [];
    searchError.value = '';
    quickOpen.value = false;
    resetQuick();
}

// ── Busca de paciente ─────────────────────────────────────────────────────
const search = ref('');
const results = ref([]);
const searching = ref(false);
const searchError = ref('');
let searchTimer = null;
let searchTicket = 0;

function onSearchInput() {
    clearTimeout(searchTimer);
    searchError.value = '';
    if (search.value.trim().length < 2) {
        results.value = [];
        return;
    }
    searchTimer = setTimeout(runSearch, 350);
}

async function runSearch() {
    const ticket = ++searchTicket;
    searching.value = true;
    try {
        const res = await fetch(`${props.urls.patient_search}?q=${encodeURIComponent(search.value.trim())}`, {
            headers: { Accept: 'application/json' },
        });
        const json = res.ok ? await res.json() : [];
        if (ticket === searchTicket) results.value = Array.isArray(json) ? json : [];
    } catch {
        if (ticket === searchTicket) {
            results.value = [];
            searchError.value = props.t.patient_none ?? '';
        }
    } finally {
        if (ticket === searchTicket) searching.value = false;
    }
}

const queuedIds = computed(() => new Set(props.queuedPatientIds.map(String)));

function isQueued(p) {
    return queuedIds.value.has(String(p.id));
}

function selectPatient(p) {
    if (isQueued(p)) return;
    patient.value = p;
    form.patient_id = p.id;
    results.value = [];
    search.value = '';
    quickOpen.value = false;

    // Convênio do cadastro, se ainda ativo (a lista só tem ativos).
    if (!form.covenant_id && p.covenant_id && props.covenants.some((c) => String(c.id) === String(p.covenant_id))) {
        form.covenant_id = p.covenant_id;
    }

    suggestByAge(p.age);
}

function changePatient() {
    patient.value = null;
    form.patient_id = '';
}

// ── Cadastro rápido ───────────────────────────────────────────────────────
const quickOpen = ref(false);
// allergies/allergies_denied: alergias informadas pelo paciente ou "nega
// alergias" (Fase 2 / G2) — nenhum dos dois = "sem registro de alergias".
const blankQuick = () => ({
    name: '',
    cellphone: '',
    birth_date: '',
    confirm_homonym: false,
    allergies: '',
    allergies_denied: false,
});
const quick = ref(blankQuick());
const quickErrors = ref({});
const quickSaving = ref(false);

function resetQuick() {
    quick.value = blankQuick();
    quickErrors.value = {};
}

function openQuick() {
    quickOpen.value = true;
    if (!quick.value.name && search.value.trim()) quick.value.name = search.value.trim();
}

watch(
    () => [quick.value.name, quick.value.birth_date],
    () => {
        quick.value.confirm_homonym = false;
    },
);

async function quickRegister() {
    if (quickSaving.value) return;
    quickSaving.value = true;
    quickErrors.value = {};
    try {
        const res = await fetch(props.urls.patient_quick, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                name: quick.value.name.trim(),
                cellphone: quick.value.cellphone,
                birth_date: quick.value.birth_date || null,
                confirm_homonym: quick.value.confirm_homonym,
                ...quickAllergyPayload(quick.value.allergies, quick.value.allergies_denied),
            }),
        });
        const json = await res.json().catch(() => ({}));
        if (res.ok && json.patient) {
            selectPatient(json.patient);
            resetQuick();
            return;
        }
        quickErrors.value = json.errors ?? { name: [json.message ?? ''] };
    } catch {
        quickErrors.value = { name: [props.t.patient_none ?? ''] };
    } finally {
        quickSaving.value = false;
    }
}

// ── Motivo e prioridade ───────────────────────────────────────────────────
const selectedReason = computed(() => props.reasons.find((r) => r.value === form.reason) ?? null);
const isOther = computed(() => form.reason === OTHER);
const isChemicalBurn = computed(() => form.reason === CHEMICAL_BURN);

function chooseReason(reason) {
    form.reason = reason.value;
    if (!priorityTouched.value && reason.suggested_priority) {
        form.priority = reason.suggested_priority;
        suggestion.value = props.t.priority_suggested ?? '';
    }
}

function suggestByAge(age) {
    if (priorityTouched.value || age === null || age === undefined) return;
    if (Number(age) >= props.legalPriorityAge && form.priority === NORMAL) {
        form.priority = LEGAL;
        suggestion.value = tx('priority_suggested_age', { age });
    }
}

function choosePriority(value) {
    form.priority = value;
    priorityTouched.value = true;
    suggestion.value = '';
}

// ── Enviar ────────────────────────────────────────────────────────────────
const canSubmit = computed(
    () =>
        Boolean(form.patient_id) &&
        form.reason !== null &&
        (!isOther.value || form.reason_details.trim() !== '') &&
        !form.processing,
);

function submit() {
    if (!canSubmit.value) return;
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('saved'),
    };
    if (isEdit.value) {
        // O paciente do item não muda na edição (o servidor proíbe o campo).
        form.transform((data) => Object.fromEntries(Object.entries(data).filter(([key]) => key !== 'patient_id')));
        form.put(route('panel.urgent-care.update', props.entry.id), options);
    } else {
        form.transform((data) => data);
        form.post(props.urls.store, options);
    }
}

// Cada abertura começa limpa (depois de todos os refs declarados — o
// `immediate` roda já no setup).
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) reset();
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(searchTimer));

defineExpose({ selectPatient, chooseReason, choosePriority, form, quickRegister, quick, runSearch });
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="emit('close')">
        <template #header>
            <h5 class="mb-0 d-flex align-items-center gap-2">
                <i class="ti ti-urgent text-danger" aria-hidden="true"></i>
                {{ isEdit ? t.edit_title : t.add_title }}
            </h5>
        </template>

        <form id="uc-form" class="uc-form" novalidate @submit.prevent="submit">
            <!-- Paciente -->
            <fieldset class="mb-3">
                <legend class="form-label fw-semibold mb-1">{{ t.field_patient }} *</legend>

                <div v-if="patient" class="uc-patient" data-testid="uc-selected-patient">
                    <i class="ti ti-user text-primary" aria-hidden="true"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">{{ patient.full_name }}</div>
                        <div class="small text-muted">
                            {{ patient.code }}
                            <template v-if="patient.age !== null && patient.age !== undefined">
                                · {{ tx('age_years', { age: patient.age }) }}
                            </template>
                        </div>
                    </div>
                    <button
                        v-if="!isEdit"
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        @click="changePatient"
                    >
                        {{ t.patient_change }}
                    </button>
                </div>

                <template v-else>
                    <div class="input-group">
                        <span class="input-group-text"><i class="ti ti-user-search" aria-hidden="true"></i></span>
                        <input
                            v-model="search"
                            type="search"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.patient_id }"
                            :placeholder="t.patient_search_ph"
                            :aria-label="t.field_patient"
                            autocomplete="off"
                            data-testid="uc-patient-search"
                            @input="onSearchInput"
                        />
                    </div>
                    <div v-if="form.errors.patient_id" class="invalid-feedback d-block">
                        {{ form.errors.patient_id }}
                    </div>
                    <p v-if="search && search.trim().length < 2" class="form-text mb-0">{{ t.patient_search_min }}</p>

                    <ul
                        v-if="results.length"
                        class="list-group uc-results mt-1"
                        role="listbox"
                        :aria-label="t.field_patient"
                    >
                        <li
                            v-for="p in results"
                            :key="p.id"
                            class="list-group-item p-0"
                            role="option"
                            :aria-selected="false"
                        >
                            <button
                                type="button"
                                class="btn w-100 text-start d-flex align-items-center gap-2 px-3 py-2"
                                :disabled="isQueued(p)"
                                :title="isQueued(p) ? t.patient_already : ''"
                                data-testid="uc-patient-option"
                                @click="selectPatient(p)"
                            >
                                <span class="flex-grow-1 min-w-0">
                                    <span class="d-block fw-medium text-truncate">{{ p.full_name }}</span>
                                    <span class="d-block small text-muted">
                                        {{ p.code }}
                                        <template v-if="p.age !== null && p.age !== undefined">
                                            · {{ tx('age_years', { age: p.age }) }}</template
                                        >
                                        <template v-if="p.cellphone"> · {{ p.cellphone }}</template>
                                    </span>
                                </span>
                                <span v-if="isQueued(p)" class="badge bg-secondary-subtle text-secondary-emphasis">{{
                                    t.patient_already
                                }}</span>
                            </button>
                        </li>
                    </ul>
                    <p
                        v-else-if="search.trim().length >= 2 && !searching"
                        class="small text-muted mt-1 mb-0"
                        role="status"
                    >
                        {{ searchError || t.patient_none }}
                    </p>

                    <div v-if="canRegisterPatient" class="mt-2">
                        <button
                            v-if="!quickOpen"
                            type="button"
                            class="btn btn-sm btn-link px-0"
                            data-testid="uc-quick-open"
                            @click="openQuick"
                        >
                            <i class="ti ti-user-plus me-1" aria-hidden="true"></i>{{ t.patient_new }}
                        </button>
                        <div v-else class="uc-quick border rounded p-2" data-testid="uc-quick">
                            <p class="small fw-semibold mb-2">{{ t.quick_title }}</p>
                            <div class="row g-2">
                                <div class="col-12 col-md-6">
                                    <label class="form-label small mb-1" for="uc-quick-name"
                                        >{{ t.quick_name }} *</label
                                    >
                                    <input
                                        id="uc-quick-name"
                                        v-model="quick.name"
                                        type="text"
                                        class="form-control form-control-sm"
                                        :class="{ 'is-invalid': quickErrors.name }"
                                        maxlength="255"
                                        data-testid="uc-quick-name"
                                    />
                                    <div v-if="quickErrors.name" class="invalid-feedback">
                                        {{ quickErrors.name[0] }}
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small mb-1" for="uc-quick-cell"
                                        >{{ t.quick_cellphone }} *</label
                                    >
                                    <input
                                        id="uc-quick-cell"
                                        v-model="quick.cellphone"
                                        type="tel"
                                        inputmode="tel"
                                        class="form-control form-control-sm"
                                        :class="{ 'is-invalid': quickErrors.cellphone }"
                                        data-testid="uc-quick-cellphone"
                                    />
                                    <div v-if="quickErrors.cellphone" class="invalid-feedback">
                                        {{ quickErrors.cellphone[0] }}
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small mb-1" for="uc-quick-birth">{{
                                        t.quick_birth_date
                                    }}</label>
                                    <input
                                        id="uc-quick-birth"
                                        v-model="quick.birth_date"
                                        type="date"
                                        class="form-control form-control-sm"
                                        :class="{ 'is-invalid': quickErrors.birth_date }"
                                        data-testid="uc-quick-birth"
                                    />
                                    <div v-if="quickErrors.birth_date" class="invalid-feedback">
                                        {{ quickErrors.birth_date[0] }}
                                    </div>
                                </div>
                            </div>
                            <p class="form-text mb-2">{{ t.quick_birth_hint }}</p>
                            <QuickAllergyFields
                                v-model:allergies="quick.allergies"
                                v-model:denied="quick.allergies_denied"
                                :errors="quickErrors"
                                class="mb-2"
                            />
                            <div
                                v-if="quickErrors.confirm_homonym"
                                class="alert alert-warning py-2 small mb-2"
                                role="alert"
                                data-testid="uc-quick-homonym"
                            >
                                {{ quickErrors.confirm_homonym[0] }}
                                <div class="form-check mt-1">
                                    <input
                                        id="uc-quick-homonym"
                                        v-model="quick.confirm_homonym"
                                        class="form-check-input"
                                        type="checkbox"
                                    />
                                    <label class="form-check-label" for="uc-quick-homonym">{{
                                        t.quick_homonym_confirm
                                    }}</label>
                                </div>
                            </div>
                            <div class="d-flex gap-2 justify-content-end">
                                <button type="button" class="btn btn-sm btn-light" @click="quickOpen = false">
                                    {{ t.cancel }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-primary"
                                    :disabled="quickSaving || !quick.name.trim() || !quick.cellphone"
                                    data-testid="uc-quick-save"
                                    @click="quickRegister"
                                >
                                    {{ quickSaving ? t.saving : t.quick_save }}
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </fieldset>

            <!-- Motivo -->
            <fieldset class="mb-3">
                <legend class="form-label fw-semibold mb-1">{{ t.field_reason }} *</legend>
                <!-- Rádios nativos (setas do teclado, leitor de tela) com cara de chip. -->
                <div class="uc-chips">
                    <template v-for="reason in reasons" :key="reason.value">
                        <input
                            :id="`uc-reason-${reason.key}`"
                            type="radio"
                            class="uc-radio"
                            name="uc-reason"
                            :value="reason.value"
                            :checked="form.reason === reason.value"
                            :data-reason="reason.key"
                            @change="chooseReason(reason)"
                        />
                        <label class="uc-chip" :for="`uc-reason-${reason.key}`">{{ reason.label }}</label>
                    </template>
                </div>
                <div v-if="form.errors.reason" class="invalid-feedback d-block">{{ form.errors.reason }}</div>

                <div
                    v-if="isChemicalBurn"
                    class="alert alert-danger d-flex gap-2 align-items-start py-2 small mt-2 mb-0"
                    role="alert"
                    data-testid="uc-chemical-alert"
                >
                    <i class="ti ti-alert-triangle fs-5 flex-shrink-0" aria-hidden="true"></i>
                    <span>{{ t.chemical_burn_alert }}</span>
                </div>

                <label class="form-label small mt-2 mb-1" for="uc-details">
                    {{ t.field_reason_details }}<template v-if="isOther"> *</template>
                </label>
                <textarea
                    id="uc-details"
                    v-model="form.reason_details"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.reason_details }"
                    rows="2"
                    maxlength="500"
                    :placeholder="isOther ? t.reason_details_required : t.reason_details_ph"
                    :required="isOther"
                    data-testid="uc-details"
                ></textarea>
                <div v-if="form.errors.reason_details" class="invalid-feedback">{{ form.errors.reason_details }}</div>
                <span v-if="selectedReason" class="visually-hidden">{{ selectedReason.label }}</span>
            </fieldset>

            <!-- Prioridade -->
            <fieldset class="mb-3">
                <legend class="form-label fw-semibold mb-1">{{ t.field_priority }} *</legend>
                <div class="uc-priorities">
                    <template v-for="p in priorities" :key="p.value">
                        <input
                            :id="`uc-priority-${p.key}`"
                            type="radio"
                            class="uc-radio"
                            name="uc-priority"
                            :value="p.value"
                            :checked="form.priority === p.value"
                            :data-priority="p.key"
                            :aria-describedby="`uc-priority-${p.key}-hint`"
                            @change="choosePriority(p.value)"
                        />
                        <label
                            class="uc-priority-option"
                            :class="`uc-priority-option--${p.key}`"
                            :for="`uc-priority-${p.key}`"
                        >
                            <span class="uc-priority-option__label">{{ p.label }}</span>
                            <span :id="`uc-priority-${p.key}-hint`" class="uc-priority-option__hint">{{ p.hint }}</span>
                        </label>
                    </template>
                </div>
                <p v-if="suggestion" class="form-text text-primary mb-0" role="status" data-testid="uc-suggestion">
                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ suggestion }}
                </p>
                <div v-if="form.errors.priority" class="invalid-feedback d-block">{{ form.errors.priority }}</div>
            </fieldset>

            <div class="row g-2">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold mb-1" for="uc-doctor">{{ t.field_doctor }}</label>
                    <select
                        id="uc-doctor"
                        v-model="form.preferred_doctor_id"
                        class="form-select"
                        :class="{ 'is-invalid': form.errors.preferred_doctor_id }"
                        data-testid="uc-doctor"
                    >
                        <option value="">{{ t.no_doctor }}</option>
                        <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                    <div v-if="form.errors.preferred_doctor_id" class="invalid-feedback">
                        {{ form.errors.preferred_doctor_id }}
                    </div>
                    <p class="form-text mb-0">{{ t.doctor_optional }}</p>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold mb-1" for="uc-covenant">{{ t.field_covenant }}</label>
                    <select
                        id="uc-covenant"
                        v-model="form.covenant_id"
                        class="form-select"
                        :class="{ 'is-invalid': form.errors.covenant_id }"
                        data-testid="uc-covenant"
                    >
                        <option value="">{{ t.no_covenant }}</option>
                        <option v-for="c in covenants" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <div v-if="form.errors.covenant_id" class="invalid-feedback">{{ form.errors.covenant_id }}</div>
                    <p class="form-text mb-0">{{ t.covenant_hint }}</p>
                </div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">{{ t.cancel }}</button>
            <button type="submit" form="uc-form" class="btn btn-primary" :disabled="!canSubmit" data-testid="uc-submit">
                {{ form.processing ? t.saving : isEdit ? t.save : t.add }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
/* legend do Bootstrap vem em tamanho de título: aqui é rótulo de campo. */
.uc-form legend {
    float: none;
    font-size: 0.875rem;
    line-height: 1.5;
}

.uc-patient {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.5rem;
    background: var(--bs-tertiary-bg);
}

.uc-results {
    max-height: 240px;
    overflow-y: auto;
}

.uc-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.uc-chip {
    border: 1px solid var(--bs-border-color);
    border-radius: 999px;
    padding: 0.3rem 0.75rem;
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    font-size: 0.8125rem;
    line-height: 1.3;
}

/* Rádio nativo escondido visualmente (continua focável e anunciado). */
.uc-radio {
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
    pointer-events: none;
}

.uc-chip {
    cursor: pointer;
    margin: 0;
}

.uc-chip:hover {
    background: var(--bs-tertiary-bg);
}

.uc-radio:focus-visible + .uc-chip,
.uc-radio:focus-visible + .uc-priority-option {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
}

.uc-radio:checked + .uc-chip {
    background: var(--bs-primary);
    border-color: var(--bs-primary);
    color: #fff;
}

.uc-priorities {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.5rem;
}

@media (max-width: 767.98px) {
    .uc-priorities {
        grid-template-columns: 1fr;
    }
}

.uc-priority-option {
    --uc-color: #6c757d;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    text-align: start;
    padding: 0.55rem 0.7rem;
    border: 1px solid var(--bs-border-color);
    border-left: 4px solid var(--uc-color);
    border-radius: 0.5rem;
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
}

.uc-priority-option--immediate {
    --uc-color: #dc2626;
}

.uc-priority-option--legal {
    --uc-color: #b45309;
}

.uc-priority-option {
    cursor: pointer;
    margin: 0;
}

.uc-radio:checked + .uc-priority-option {
    border-color: var(--uc-color);
    box-shadow: 0 0 0 2px var(--uc-color) inset;
}

.uc-priority-option__label {
    font-weight: 600;
    font-size: 0.875rem;
}

.uc-radio:checked + .uc-priority-option .uc-priority-option__label::before {
    content: '✓ ';
}

.uc-priority-option__hint {
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
    line-height: 1.3;
}

[data-bs-theme='dark'] .uc-priority-option--immediate {
    --uc-color: #f87171;
}

[data-bs-theme='dark'] .uc-priority-option--legal {
    --uc-color: #fbbf24;
}
</style>
