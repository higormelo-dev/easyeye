<script setup>
import { computed, onBeforeUnmount, reactive, ref, useId } from 'vue';
import { clinicalTexts, tc, fill } from '@/Support/clinicalSafety.js';
import { enterSubmits } from '@/Support/subform.js';

/**
 * Bloco de MEDICAÇÕES EM USO do paciente (Fase 2 / A2) — lista persistente
 * entre consultas, com busca no catálogo CMED (princípio ativo vem do
 * catálogo; nome livre também vale). Os alertas de risco ocular saem do
 * princípio ativo (servidor).
 *
 * Mesma regra das alergias: a recepção registra como informada e corrige só a
 * não confirmada; o médico com cadastro ativo confirma, suspende, reativa e
 * descarta. Modo `pending` (paciente novo): v-model:medications.
 */
const props = defineProps({
    mode: { type: String, default: 'live' }, // live | pending
    store: { type: Object, default: null },
    medications: { type: Array, default: () => [] }, // pending
    // Busca no catálogo (panel.medicines.search) — perfis sem a rota digitam o nome.
    searchUrl: { type: String, default: '' },
    // Pending: erro do SERVIDOR ao enviar o cadastro ("medications.0.started_on"
    // — data no futuro) — sem isto, só a aba Clínico ficava marcada, sem
    // dizer qual item (10ª revisão Codex, P2).
    errors: { type: Object, default: () => ({}) },
    t: { type: Object, default: null },
});

const emit = defineEmits(['update:medications']);

const texts = clinicalTexts(props.t);
const tt = (path, fallback) => tc(texts, path, fallback);
const uid = useId();

const isLive = computed(() => props.mode === 'live');
const summary = computed(() => props.store?.summary ?? null);
const canReview = computed(() => isLive.value && Boolean(summary.value?.can_review));
const busy = computed(() => Boolean(props.store?.busy));

const list = computed(() => (isLive.value ? (summary.value?.medications ?? []) : props.medications));
const active = computed(() => list.value.filter((m) => !m.status || m.status === 'active'));
const history = computed(() => list.value.filter((m) => m.status && m.status !== 'active'));
const showHistory = ref(false);

// Pending: todo item ainda sem status cai em `active` (nenhum foi suspenso/
// descartado ainda), na mesma ordem de `props.medications` — o índice bate
// com a chave do servidor.
function pendingError(index) {
    const prefix = `medications.${index}.`;
    const key = Object.keys(props.errors).find((k) => k.startsWith(prefix));

    return key ? props.errors[key] : '';
}
const listError = computed(() => props.errors.medications ?? '');

const SOURCES = computed(() =>
    canReview.value ? ['patient', 'external_prescription', 'clinic'] : ['patient', 'external_prescription'],
);

const formOpen = ref(false);
const editingId = ref(null);
const formErrors = ref({});
const message = ref('');
const blank = () => ({
    medicine_id: null,
    name: '',
    active_ingredient: '',
    posology: '',
    started_on: '',
    source: '',
});
const form = reactive(blank());

// ── Busca no catálogo ────────────────────────────────────────────────────
const query = ref('');
const results = ref([]);
const searching = ref(false);
const resultsOpen = ref(false);
let timer = null;
let abort = null;

function onQuery() {
    // Digitar por cima solta o vínculo com o catálogo: o princípio ativo
    // (só-leitura, vem do item escolhido) não pode sobrar de uma escolha
    // anterior — senão salva com o nome novo e o princípio ativo de outro
    // medicamento (2ª revisão Codex).
    form.medicine_id = null;
    form.active_ingredient = '';
    form.name = query.value;
    clearTimeout(timer);
    timer = setTimeout(runSearch, 250);
}

async function runSearch() {
    const q = query.value.trim();
    abort?.abort();
    if (q.length < 2 || !props.searchUrl) {
        results.value = [];
        resultsOpen.value = q.length >= 2;
        return;
    }
    searching.value = true;
    const controller = new AbortController();
    abort = controller;
    try {
        const res = await fetch(`${props.searchUrl}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        results.value = res.ok ? await res.json() : [];
    } catch (e) {
        if (e?.name !== 'AbortError') results.value = [];
    } finally {
        if (abort === controller) searching.value = false;
        resultsOpen.value = true;
    }
}

onBeforeUnmount(() => {
    clearTimeout(timer);
    abort?.abort();
});

function pick(item) {
    form.medicine_id = item.id;
    form.name = [item.name, item.concentration].filter(Boolean).join(' ');
    form.active_ingredient = item.active_ingredient ?? '';
    query.value = form.name;
    resultsOpen.value = false;
}

function useTyped() {
    form.medicine_id = null;
    form.name = query.value.trim();
    resultsOpen.value = false;
}

function resultDetail(item) {
    return [item.active_ingredient, item.presentation, item.laboratory].filter(Boolean).join(' · ');
}

// ── Formulário ───────────────────────────────────────────────────────────
function openNew() {
    Object.assign(form, blank());
    query.value = '';
    results.value = [];
    editingId.value = null;
    formErrors.value = {};
    message.value = '';
    formOpen.value = true;
}

function openEdit(m) {
    Object.assign(form, blank(), {
        medicine_id: m.medicine_id ?? null,
        name: m.name,
        active_ingredient: m.active_ingredient ?? '',
        posology: m.posology ?? '',
        started_on: m.started_on ?? '',
        source: m.source ?? '',
    });
    query.value = m.name;
    editingId.value = m.id ?? m._key;
    formErrors.value = {};
    message.value = '';
    formOpen.value = true;
}

function canEdit(m) {
    return canReview.value || (!m.confirmed && m.status === 'active');
}

function translateError(msg) {
    return msg === 'save_error' || msg === 'load_error' ? tt(`allergies.${msg}`, msg) : msg;
}

// Enter num campo salva só a medicação (nunca o formulário do paciente).
const onFormKeydown = enterSubmits(() => {
    if (!busy.value) save();
});

async function save() {
    formErrors.value = {};
    message.value = '';
    if (!form.medicine_id && form.name.trim().length < 2) {
        formErrors.value = { name: [tt('medications.name', 'Medicamento')] };
        return;
    }
    const data = { ...form, source: form.source || undefined };

    if (!isLive.value) {
        const next = [...props.medications];
        const item = { ...data, _key: editingId.value ?? `m-${Date.now()}` };
        const index = next.findIndex((m) => m._key === editingId.value);
        if (index >= 0) next.splice(index, 1, item);
        else next.push(item);
        emit('update:medications', next);
        formOpen.value = false;
        return;
    }

    const result = editingId.value
        ? await props.store.updateMedication(editingId.value, data)
        : await props.store.addMedication(data);
    if (result.ok) {
        formOpen.value = false;
        editingId.value = null;
    } else {
        formErrors.value = result.errors ?? {};
        message.value = translateError(result.message);
    }
}

function removePending(key) {
    emit(
        'update:medications',
        props.medications.filter((m) => m._key !== key),
    );
}

async function act(fn) {
    const result = await fn();
    if (!result.ok) message.value = translateError(result.message);
}

const sourceLabel = (value) => tt(`medication_sources.${value}`, value);
const fieldError = (name) => formErrors.value?.[name]?.[0] ?? '';

function meta(m) {
    const parts = [];
    if (m.started_on_label) parts.push(fill(tt('medications.since', 'desde :date'), { date: m.started_on_label }));
    if (m.ended_on_label) parts.push(fill(tt('medications.until', 'até :date'), { date: m.ended_on_label }));
    if (m.source_label || m.source) parts.push(m.source_label ?? sourceLabel(m.source));
    return parts.join(' · ');
}
</script>

<template>
    <div class="cs-panel" data-testid="medications-panel">
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <h6 class="mb-0 fw-semibold">
                <i class="ti ti-pill me-1 text-primary" aria-hidden="true"></i
                >{{ tt('medications.title', 'Medicações em uso') }}
            </h6>
            <button
                v-if="!formOpen"
                type="button"
                class="btn btn-sm btn-outline-primary ms-auto"
                :disabled="busy"
                data-testid="medication-add"
                @click="openNew"
            >
                <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ tt('medications.add', 'Adicionar medicação') }}
            </button>
        </div>

        <div v-if="message" class="alert alert-danger py-1 px-2 small mb-2" role="alert">{{ message }}</div>

        <!-- <div>, não <form>: o painel fica dentro do formulário do paciente. -->
        <div
            v-if="formOpen"
            class="border rounded p-2 mb-2"
            role="group"
            :aria-label="tt('medications.title', 'Medicações em uso')"
            data-testid="medication-form"
            @keydown="onFormKeydown"
        >
            <div class="row g-2">
                <div class="col-12 position-relative">
                    <label class="form-label small mb-1" :for="`${uid}-q`"
                        >{{ tt('medications.name', 'Medicamento') }}
                        <span class="text-danger" aria-hidden="true">*</span></label
                    >
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="ti ti-search" aria-hidden="true"></i></span>
                        <input
                            :id="`${uid}-q`"
                            v-model="query"
                            type="text"
                            maxlength="255"
                            autocomplete="off"
                            class="form-control"
                            :class="{ 'is-invalid': fieldError('name') }"
                            :placeholder="tt('medications.name_ph', 'Buscar no catálogo (CMED) ou digitar o nome')"
                            role="combobox"
                            :aria-expanded="resultsOpen ? 'true' : 'false'"
                            :aria-controls="`${uid}-results`"
                            data-testid="medication-search"
                            @input="onQuery"
                        />
                        <span v-if="searching" class="input-group-text bg-transparent">
                            <span
                                class="spinner-border spinner-border-sm"
                                :aria-label="tt('medications.searching', 'Buscando…')"
                            ></span>
                        </span>
                    </div>
                    <small v-if="form.medicine_id" class="text-success d-block mt-1">
                        <i class="ti ti-check me-1" aria-hidden="true"></i>{{ tt('medications.catalog', 'Catálogo') }}
                    </small>
                    <ul
                        v-if="resultsOpen && query.trim().length >= 2"
                        :id="`${uid}-results`"
                        class="list-group shadow-sm position-absolute w-100 cs-med-results"
                        role="listbox"
                    >
                        <li
                            v-for="item in results"
                            :key="item.id"
                            class="list-group-item list-group-item-action py-1 px-2"
                            role="option"
                            tabindex="0"
                            data-testid="medication-result"
                            @mousedown.prevent="pick(item)"
                            @keydown.enter.prevent="pick(item)"
                        >
                            <span class="fw-semibold">{{ item.name }}</span>
                            <span v-if="item.concentration" class="ms-1">{{ item.concentration }}</span>
                            <div v-if="resultDetail(item)" class="small text-body-secondary text-truncate">
                                {{ resultDetail(item) }}
                            </div>
                        </li>
                        <li
                            class="list-group-item list-group-item-action py-1 px-2 small"
                            role="option"
                            tabindex="0"
                            data-testid="medication-use-typed"
                            @mousedown.prevent="useTyped"
                            @keydown.enter.prevent="useTyped"
                        >
                            <i class="ti ti-pencil me-1" aria-hidden="true"></i
                            >{{
                                fill(tt('medications.use_typed', 'Usar ":name" (nome livre)'), { name: query.trim() })
                            }}
                        </li>
                    </ul>
                    <div v-if="fieldError('name')" class="invalid-feedback d-block">{{ fieldError('name') }}</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1" :for="`${uid}-ing`">{{
                        tt('medications.active_ingredient', 'Princípio ativo')
                    }}</label>
                    <input
                        :id="`${uid}-ing`"
                        v-model="form.active_ingredient"
                        type="text"
                        maxlength="500"
                        class="form-control form-control-sm"
                        :readonly="Boolean(form.medicine_id)"
                        data-testid="medication-ingredient"
                    />
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1" :for="`${uid}-start`">{{
                        tt('medications.started_on', 'Início')
                    }}</label>
                    <input
                        :id="`${uid}-start`"
                        v-model="form.started_on"
                        type="date"
                        class="form-control form-control-sm"
                        :class="{ 'is-invalid': fieldError('started_on') }"
                    />
                    <div v-if="fieldError('started_on')" class="invalid-feedback">{{ fieldError('started_on') }}</div>
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label small mb-1" :for="`${uid}-pos`">{{
                        tt('medications.posology', 'Posologia / observação')
                    }}</label>
                    <input
                        :id="`${uid}-pos`"
                        v-model="form.posology"
                        type="text"
                        maxlength="1000"
                        class="form-control form-control-sm"
                    />
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1" :for="`${uid}-src`">{{
                        tt('medications.source', 'Fonte')
                    }}</label>
                    <select :id="`${uid}-src`" v-model="form.source" class="form-select form-select-sm">
                        <option value="">—</option>
                        <option v-for="s in SOURCES" :key="s" :value="s">{{ sourceLabel(s) }}</option>
                    </select>
                </div>
            </div>
            <div class="d-flex gap-2 justify-content-end mt-2">
                <button type="button" class="btn btn-sm btn-light" @click="formOpen = false">
                    {{ tt('medications.cancel', 'Cancelar') }}
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    :disabled="busy"
                    data-testid="medication-save"
                    @click="save"
                >
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                    >{{ tt('medications.save', 'Salvar') }}
                </button>
            </div>
        </div>

        <div v-if="!isLive && listError" class="small text-danger mb-2" data-testid="medications-list-error">
            {{ listError }}
        </div>
        <ul v-if="active.length" class="list-unstyled mb-2" data-testid="medication-list">
            <li v-for="(m, i) in active" :key="m.id ?? m._key" class="cs-med-item">
                <div class="d-flex align-items-start gap-2 flex-wrap">
                    <div class="flex-grow-1 min-w-0">
                        <strong>{{ m.name }}</strong>
                        <span v-if="m.active_ingredient" class="small text-body-secondary">
                            · {{ m.active_ingredient }}</span
                        >
                        <div v-if="m.posology" class="small">{{ m.posology }}</div>
                        <div class="small text-body-secondary">{{ meta(m) }}</div>
                        <div
                            v-if="!isLive && pendingError(i)"
                            class="small text-danger"
                            data-testid="medication-pending-error"
                        >
                            {{ pendingError(i) }}
                        </div>
                        <span
                            v-if="isLive && !m.confirmed"
                            class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle mt-1"
                            >{{ tt('medications.unconfirmed', 'Não confirmada pelo médico') }}</span
                        >
                    </div>
                    <div class="d-flex gap-1 flex-wrap">
                        <template v-if="isLive">
                            <button
                                v-if="canReview && !m.confirmed"
                                type="button"
                                class="btn btn-sm btn-success"
                                :disabled="busy"
                                :aria-label="`${tt('medications.confirm', 'Confirmar')}: ${m.name}`"
                                data-testid="medication-confirm"
                                @click="act(() => store.confirmMedication(m.id))"
                            >
                                <i class="ti ti-check me-1" aria-hidden="true"></i
                                >{{ tt('medications.confirm', 'Confirmar') }}
                            </button>
                            <button
                                v-if="canEdit(m)"
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                :disabled="busy"
                                :aria-label="`${tt('medications.edit', 'Corrigir')}: ${m.name}`"
                                :title="tt('medications.edit', 'Corrigir')"
                                @click="openEdit(m)"
                            >
                                <i class="ti ti-pencil" aria-hidden="true"></i>
                            </button>
                            <button
                                v-if="canReview"
                                type="button"
                                class="btn btn-sm btn-outline-warning"
                                :disabled="busy"
                                :aria-label="`${tt('medications.suspend', 'Suspender')}: ${m.name}`"
                                :title="tt('medications.suspend', 'Suspender')"
                                data-testid="medication-suspend"
                                @click="act(() => store.setMedicationStatus(m.id, 'suspended'))"
                            >
                                <i class="ti ti-player-pause" aria-hidden="true"></i>
                            </button>
                            <button
                                v-if="canReview"
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                :disabled="busy"
                                :aria-label="`${tt('medications.discard', 'Descartar')}: ${m.name}`"
                                :title="tt('medications.discard', 'Descartar')"
                                @click="act(() => store.setMedicationStatus(m.id, 'discarded'))"
                            >
                                <i class="ti ti-ban" aria-hidden="true"></i>
                            </button>
                        </template>
                        <button
                            v-else
                            type="button"
                            class="btn btn-sm btn-outline-danger"
                            :aria-label="fill(tt('allergies.pending_remove', 'Remover :name'), { name: m.name })"
                            @click="removePending(m._key)"
                        >
                            <i class="ti ti-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </li>
        </ul>
        <p v-else-if="!formOpen" class="small text-body-secondary mb-2">
            {{ tt('medications.empty', 'Nenhuma medicação na lista.') }}
        </p>

        <div v-if="history.length" class="small">
            <button
                type="button"
                class="btn btn-link btn-sm p-0"
                :aria-expanded="showHistory ? 'true' : 'false'"
                @click="showHistory = !showHistory"
            >
                <i class="ti ti-history me-1" aria-hidden="true"></i
                >{{ tt('medications.history', 'Suspensas e descartadas') }} ({{ history.length }})
            </button>
            <ul v-if="showHistory" class="list-unstyled mt-1 mb-0">
                <li
                    v-for="m in history"
                    :key="m.id"
                    class="d-flex align-items-center gap-2 flex-wrap text-body-secondary"
                >
                    <span
                        ><component :is="m.status === 'discarded' ? 's' : 'span'">{{ m.name }}</component> —
                        {{ m.status_label }}<span v-if="meta(m)"> · {{ meta(m) }}</span></span
                    >
                    <button
                        v-if="canReview && m.status === 'suspended'"
                        type="button"
                        class="btn btn-link btn-sm p-0"
                        :disabled="busy"
                        @click="act(() => store.setMedicationStatus(m.id, 'active'))"
                    >
                        <i class="ti ti-player-play me-1" aria-hidden="true"></i
                        >{{ tt('medications.reactivate', 'Reativar') }}
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
.cs-med-item strong {
    color: var(--bs-emphasis-color);
}
.cs-med-item {
    padding: 0.45rem 0.6rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-left: 4px solid var(--bs-primary, #0d6efd);
    border-radius: 0.4rem;
    margin-bottom: 0.4rem;
    background: var(--bs-body-bg);
}
.cs-med-results {
    z-index: 1090;
    top: 100%;
    max-height: 240px;
    overflow-y: auto;
    font-size: 0.82rem;
}
</style>
