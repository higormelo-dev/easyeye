<script setup>
import { computed, onBeforeUnmount, reactive, ref, useId } from 'vue';
import { clinicalTexts, tc, fill } from '@/Support/clinicalSafety.js';
import { enterSubmits } from '@/Support/subform.js';

/**
 * Bloco de ALERGIAS do paciente (Fase 2 / A1) — cadastro do paciente (aba
 * Clínico) e prontuário.
 *
 *  - Modo `live` (paciente já existe): cada ação grava na hora pelo `store`
 *    (useClinicalSafety); o servidor decide o que o perfil pode.
 *  - Modo `pending` (paciente novo): a lista vai junto com o cadastro
 *    (v-model:allergies / v-model:denied), sempre como informada.
 *
 * Recepção registra "informada pelo paciente" e corrige só a informada; o
 * médico com cadastro ativo (`summary.can_review`) confirma, corrige e
 * descarta. Descartada fica no histórico.
 *
 * Item do catálogo (CMED) OPCIONAL além da substância digitada: grava o
 * medicine_id e o princípio ativo do catálogo (o servidor relê o item, de
 * outra clínica é ignorado) — a checagem da receita passa a pegar outras
 * marcas da mesma substância. Mesma busca das medicações em uso
 * (panel.medicines.search); perfis sem a rota só digitam.
 */
const props = defineProps({
    mode: { type: String, default: 'live' }, // live | pending
    store: { type: Object, default: null },
    allergies: { type: Array, default: () => [] }, // pending
    denied: { type: Boolean, default: false }, // pending
    // Busca no catálogo (panel.medicines.search) — vazio = sem seletor.
    searchUrl: { type: String, default: '' },
    // Pending: erro do SERVIDOR ao enviar o cadastro (10ª revisão Codex, P2)
    // — ver PatientMedicationsPanel.vue, mesmo padrão.
    errors: { type: Object, default: () => ({}) },
    t: { type: Object, default: null },
});

const emit = defineEmits(['update:allergies', 'update:denied']);

const texts = clinicalTexts(props.t);
const tt = (path, fallback) => tc(texts, path, fallback);
const uid = useId();

const CATEGORIES = ['medication', 'eye_drop', 'anesthetic', 'antiseptic', 'latex', 'preservative', 'food', 'other'];
const SEVERITIES = ['mild', 'moderate', 'severe'];

const isLive = computed(() => props.mode === 'live');
const summary = computed(() => props.store?.summary ?? null);
const canReview = computed(() => isLive.value && Boolean(summary.value?.can_review));
const busy = computed(() => Boolean(props.store?.busy));

const list = computed(() => (isLive.value ? (summary.value?.allergies ?? []) : props.allergies));
const active = computed(() => list.value.filter((a) => !a.status || a.status !== 'discarded'));
const discarded = computed(() => list.value.filter((a) => a.status === 'discarded'));

function pendingError(index) {
    const prefix = `allergies.${index}.`;
    const key = Object.keys(props.errors).find((k) => k.startsWith(prefix));

    return key ? props.errors[key] : '';
}
const listError = computed(() => props.errors.allergies ?? '');
const isDenied = computed(() => (isLive.value ? summary.value?.allergy_state === 'denied' : props.denied));

const formOpen = ref(false);
const editingId = ref(null);
const formErrors = ref({});
const message = ref('');
const blank = () => ({
    category: 'medication',
    substance: '',
    medicine_id: null,
    // Rótulo do item escolhido (só tela; o servidor ignora).
    medicine_label: '',
    active_ingredient: '',
    reaction: '',
    severity: '',
    notes: '',
    status: 'confirmed',
});
const form = reactive(blank());

const discardingId = ref(null);
const discardReason = ref('');
const showHistory = ref(false);

// ── Item do catálogo (opcional) ─────────────────────────────────────────────
// Combobox (WAI-ARIA 1.2): setas percorrem, Enter escolhe, Esc fecha; busca
// com debounce e a anterior é abortada (AbortController).
const catalogQuery = ref('');
const catalogResults = ref([]);
const catalogSearching = ref(false);
const catalogOpen = ref(false);
const catalogActive = ref(-1);
let catalogTimer = null;
let catalogAbort = null;

const catalogListId = `${uid}-catalog-list`;
const catalogOptionId = (index) => `${uid}-catalog-opt-${index}`;
const catalogActiveId = computed(() =>
    catalogOpen.value && catalogActive.value >= 0 ? catalogOptionId(catalogActive.value) : undefined,
);

function resetCatalogSearch() {
    clearTimeout(catalogTimer);
    catalogAbort?.abort();
    catalogQuery.value = '';
    catalogResults.value = [];
    catalogSearching.value = false;
    catalogOpen.value = false;
    catalogActive.value = -1;
}

function onCatalogInput() {
    catalogActive.value = -1;
    clearTimeout(catalogTimer);
    catalogTimer = setTimeout(runCatalogSearch, 250);
}

async function runCatalogSearch() {
    const q = catalogQuery.value.trim();
    catalogAbort?.abort();
    if (q.length < 2 || !props.searchUrl) {
        catalogResults.value = [];
        catalogOpen.value = false;
        catalogSearching.value = false;
        return;
    }
    catalogSearching.value = true;
    const controller = new AbortController();
    catalogAbort = controller;
    try {
        const res = await fetch(`${props.searchUrl}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        const json = res.ok ? await res.json() : [];
        if (catalogAbort !== controller) return;
        catalogResults.value = Array.isArray(json) ? json : [];
        catalogOpen.value = true;
    } catch (e) {
        if (e?.name === 'AbortError') return;
        catalogResults.value = [];
        catalogOpen.value = true;
    } finally {
        if (catalogAbort === controller) catalogSearching.value = false;
    }
}

function onCatalogKeydown(event) {
    const count = catalogResults.value.length;
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (!catalogOpen.value && count) catalogOpen.value = true;
        if (count) catalogActive.value = (catalogActive.value + 1) % count;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (count) catalogActive.value = catalogActive.value <= 0 ? count - 1 : catalogActive.value - 1;
    } else if (event.key === 'Enter') {
        // Enter na busca nunca envia o formulário da alergia.
        event.preventDefault();
        if (catalogOpen.value && catalogActive.value >= 0) pickCatalog(catalogResults.value[catalogActive.value]);
    } else if (event.key === 'Escape' && catalogOpen.value) {
        event.preventDefault();
        event.stopPropagation();
        catalogOpen.value = false;
        catalogActive.value = -1;
    }
}

function catalogLabel(item) {
    return [item.name, item.concentration].filter(Boolean).join(' ');
}

function catalogDetail(item) {
    return [item.active_ingredient, item.presentation, item.laboratory].filter(Boolean).join(' · ');
}

function pickCatalog(item) {
    if (!item) return;
    form.medicine_id = item.id;
    form.medicine_label = catalogLabel(item);
    // Sempre troca pelo do catálogo (mesmo vazio) — senão o princípio ativo
    // do remédio ANTERIOR ficava preso (campo vira readonly com medicine_id
    // preenchido, sem jeito de corrigir na tela) ao escolher outro remédio
    // sem princípio ativo próprio (17ª revisão Codex, P2).
    form.active_ingredient = item.active_ingredient || '';
    // A substância continua livre (e obrigatória): só preenche se vazia.
    if (!form.substance.trim()) form.substance = item.name ?? '';
    resetCatalogSearch();
}

function unlinkCatalog() {
    form.medicine_id = null;
    form.medicine_label = '';
}

onBeforeUnmount(() => {
    clearTimeout(catalogTimer);
    catalogAbort?.abort();
});

function openNew() {
    resetCatalogSearch();
    Object.assign(form, blank());
    editingId.value = null;
    formErrors.value = {};
    message.value = '';
    formOpen.value = true;
}

function openEdit(a) {
    resetCatalogSearch();
    Object.assign(form, blank(), {
        category: a.category,
        substance: a.substance,
        medicine_id: a.medicine_id ?? null,
        medicine_label: a.medicine_label ?? '',
        active_ingredient: a.active_ingredient ?? '',
        reaction: a.reaction ?? '',
        severity: a.severity ?? '',
        notes: a.notes ?? '',
        status: a.status === 'reported' ? 'reported' : 'confirmed',
    });
    editingId.value = a.id;
    formErrors.value = {};
    message.value = '';
    formOpen.value = true;
}

function translateError(msg) {
    return msg === 'save_error' || msg === 'load_error' ? tt(`allergies.${msg}`, msg) : msg;
}

function canEdit(a) {
    return canReview.value || a.status === 'reported';
}

function payload() {
    const data = { ...form };
    delete data.medicine_label;
    if (!canReview.value) delete data.status;
    return data;
}

// Enter num campo salva só a alergia (nunca o formulário do paciente).
const onFormKeydown = enterSubmits(() => {
    if (!busy.value) save();
});

async function save() {
    formErrors.value = {};
    message.value = '';
    if (form.substance.trim().length < 2) {
        formErrors.value = { substance: [tt('allergies.substance', 'Substância')] };
        return;
    }

    if (!isLive.value) {
        const next = [...props.allergies];
        // medicine_label só para mostrar na lista pendente (o servidor ignora).
        const item = {
            ...payload(),
            medicine_label: form.medicine_label,
            status: 'reported',
            _key: editingId.value ?? `p-${Date.now()}`,
        };
        delete item.status;
        const index = next.findIndex((a) => a._key === editingId.value);
        if (index >= 0) next.splice(index, 1, item);
        else next.push(item);
        emit('update:allergies', next);
        if (props.denied) emit('update:denied', false);
        formOpen.value = false;
        return;
    }

    const result = editingId.value
        ? await props.store.updateAllergy(editingId.value, payload())
        : await props.store.addAllergy(payload());
    if (result.ok) {
        formOpen.value = false;
        editingId.value = null;
    } else {
        formErrors.value = result.errors ?? {};
        message.value = translateError(result.message || 'save_error');
    }
}

function removePending(key) {
    emit(
        'update:allergies',
        props.allergies.filter((a) => a._key !== key),
    );
}

async function confirmAllergy(a) {
    const result = await props.store.confirmAllergy(a.id);
    if (!result.ok) message.value = translateError(result.message);
}

function startDiscard(a) {
    discardingId.value = a.id;
    discardReason.value = '';
}

async function discard() {
    const result = await props.store.discardAllergy(discardingId.value, discardReason.value.trim());
    if (result.ok) discardingId.value = null;
    else message.value = translateError(result.message);
}

async function toggleDenied() {
    if (!isLive.value) {
        emit('update:denied', !props.denied);
        return;
    }
    const result = await props.store.setDenied(!isDenied.value);
    if (!result.ok) message.value = translateError(result.message);
}

const categoryLabel = (value) => tt(`categories.${value}`, value);
const severityLabel = (value) => (value ? tt(`severities.${value}`, value) : '');
const statusLabel = (a) => a.status_label ?? tt(`allergy_statuses.${a.status ?? 'reported'}`, '');

function meta(a) {
    if (!isLive.value)
        return tt('allergies.pending_hint', 'Vai junto com o cadastro do paciente, como informada pelo paciente.');
    const parts = [];
    if (a.registered_by || a.registered_at_label)
        parts.push(
            fill(tt('allergies.registered', 'Registrada por :name em :date'), {
                name: a.registered_by ?? '—',
                date: a.registered_at_label ?? '',
            }),
        );
    if (a.confirmed_by && a.status === 'confirmed')
        parts.push(
            fill(tt('allergies.confirmed', 'Confirmada por :name em :date'), {
                name: a.confirmed_by,
                date: a.confirmed_at_label ?? '',
            }),
        );
    if (a.status === 'discarded')
        parts.push(
            fill(tt('allergies.discarded', 'Descartada por :name em :date'), {
                name: a.discarded_by ?? '—',
                date: a.discarded_at_label ?? '',
            }),
        );
    return parts.join(' · ');
}

const fieldError = (name) => formErrors.value?.[name]?.[0] ?? '';
</script>

<template>
    <div class="cs-panel" data-testid="allergies-panel">
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <h6 class="mb-0 fw-semibold">
                <i class="ti ti-alert-triangle me-1 text-danger" aria-hidden="true"></i
                >{{ tt('allergies.title', 'Alergias') }}
            </h6>
            <button
                v-if="!formOpen"
                type="button"
                class="btn btn-sm btn-outline-primary ms-auto"
                :disabled="busy"
                data-testid="allergy-add"
                @click="openNew"
            >
                <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ tt('allergies.add', 'Adicionar alergia') }}
            </button>
        </div>

        <p v-if="isLive && !canReview" class="small text-body-secondary mb-2">
            <i class="ti ti-info-circle me-1" aria-hidden="true"></i
            >{{
                tt(
                    'allergies.reception_hint',
                    'Você registra como "informada pelo paciente"; o médico confirma ou descarta no atendimento.',
                )
            }}
        </p>

        <div v-if="message" class="alert alert-danger py-1 px-2 small mb-2" role="alert">{{ message }}</div>

        <!-- Formulário (novo / corrigir) -->
        <!-- <div>, não <form>: o painel fica dentro do formulário do paciente
             (form aninhado: o submit subia e salvava o paciente inteiro). -->
        <div
            v-if="formOpen"
            class="cs-panel__form border rounded p-2 mb-2"
            role="group"
            :aria-label="tt('allergies.title', 'Alergias')"
            data-testid="allergy-form"
            @keydown="onFormKeydown"
        >
            <div class="row g-2">
                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1" :for="`${uid}-cat`">{{
                        tt('allergies.category', 'Categoria')
                    }}</label>
                    <select :id="`${uid}-cat`" v-model="form.category" class="form-select form-select-sm">
                        <option v-for="c in CATEGORIES" :key="c" :value="c">{{ categoryLabel(c) }}</option>
                    </select>
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label small mb-1" :for="`${uid}-sub`"
                        >{{ tt('allergies.substance', 'Substância') }}
                        <span class="text-danger" aria-hidden="true">*</span></label
                    >
                    <input
                        :id="`${uid}-sub`"
                        v-model="form.substance"
                        type="text"
                        maxlength="160"
                        required
                        class="form-control form-control-sm"
                        :class="{ 'is-invalid': fieldError('substance') }"
                        :placeholder="tt('allergies.substance_ph', 'Ex.: dipirona, penicilina, látex')"
                        data-testid="allergy-substance"
                    />
                    <div v-if="fieldError('substance')" class="invalid-feedback">{{ fieldError('substance') }}</div>
                </div>
                <!-- Item do catálogo (opcional): princípio ativo do catálogo na checagem da receita -->
                <div v-if="searchUrl || form.medicine_id" class="col-12">
                    <label class="form-label small mb-1" :for="`${uid}-cat-q`">{{
                        tt('allergies.catalog_label', 'Item do catálogo (opcional)')
                    }}</label>
                    <div
                        v-if="form.medicine_id"
                        class="cs-catalog-chip d-flex align-items-center gap-2 flex-wrap"
                        data-testid="allergy-catalog-linked"
                    >
                        <i class="ti ti-link text-success" aria-hidden="true"></i>
                        <span class="min-w-0 text-break">
                            <span class="fw-semibold">{{
                                form.medicine_label || tt('allergies.catalog_linked', 'Vinculada ao catálogo')
                            }}</span>
                            <span v-if="form.active_ingredient" class="text-body-secondary">
                                · {{ form.active_ingredient }}</span
                            >
                        </span>
                        <button
                            type="button"
                            class="btn btn-sm btn-link p-0 ms-auto"
                            data-testid="allergy-catalog-unlink"
                            @click="unlinkCatalog"
                        >
                            <i class="ti ti-unlink me-1" aria-hidden="true"></i
                            >{{ tt('allergies.catalog_unlink', 'Desvincular do catálogo') }}
                        </button>
                    </div>
                    <div v-else class="position-relative">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="ti ti-search" aria-hidden="true"></i></span>
                            <input
                                :id="`${uid}-cat-q`"
                                v-model="catalogQuery"
                                type="text"
                                maxlength="120"
                                autocomplete="off"
                                class="form-control"
                                role="combobox"
                                aria-autocomplete="list"
                                :aria-expanded="catalogOpen ? 'true' : 'false'"
                                :aria-controls="catalogListId"
                                :aria-activedescendant="catalogActiveId"
                                :aria-describedby="`${uid}-cat-hint`"
                                :placeholder="
                                    tt(
                                        'allergies.catalog_ph',
                                        'Buscar no catálogo (CMED): nome comercial ou princípio ativo',
                                    )
                                "
                                data-testid="allergy-catalog-search"
                                @input="onCatalogInput"
                                @keydown="onCatalogKeydown"
                                @blur="catalogOpen = false"
                            />
                            <span v-if="catalogSearching" class="input-group-text bg-transparent">
                                <span
                                    class="spinner-border spinner-border-sm"
                                    role="status"
                                    :aria-label="tt('medications.searching', 'Buscando…')"
                                ></span>
                            </span>
                        </div>
                        <ul
                            v-show="catalogOpen"
                            :id="catalogListId"
                            class="list-group shadow-sm position-absolute cs-catalog-results"
                            role="listbox"
                            :aria-label="tt('allergies.catalog_results', 'Itens do catálogo')"
                        >
                            <li
                                v-for="(item, index) in catalogResults"
                                :id="catalogOptionId(index)"
                                :key="item.id"
                                class="list-group-item list-group-item-action py-1 px-2"
                                :class="{ active: index === catalogActive }"
                                role="option"
                                :aria-selected="index === catalogActive ? 'true' : 'false'"
                                data-testid="allergy-catalog-result"
                                @mousedown.prevent="pickCatalog(item)"
                            >
                                <span class="fw-semibold">{{ item.name }}</span>
                                <span v-if="item.concentration" class="ms-1">{{ item.concentration }}</span>
                                <div v-if="catalogDetail(item)" class="small cs-catalog-detail text-truncate">
                                    {{ catalogDetail(item) }}
                                </div>
                            </li>
                            <li
                                v-if="!catalogResults.length"
                                class="list-group-item py-1 px-2 small text-body-secondary"
                                role="option"
                                aria-disabled="true"
                                aria-selected="false"
                            >
                                {{
                                    tt(
                                        'allergies.catalog_empty',
                                        'Nenhum item do catálogo encontrado — a substância digitada continua valendo.',
                                    )
                                }}
                            </li>
                        </ul>
                    </div>
                    <small :id="`${uid}-cat-hint`" class="form-text d-block">{{
                        tt(
                            'allergies.catalog_hint',
                            'Vincular o item do catálogo leva o princípio ativo para a checagem da receita (outras marcas da mesma substância também alertam).',
                        )
                    }}</small>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1" :for="`${uid}-rea`">{{
                        tt('allergies.reaction', 'Reação')
                    }}</label>
                    <input
                        :id="`${uid}-rea`"
                        v-model="form.reaction"
                        type="text"
                        maxlength="500"
                        class="form-control form-control-sm"
                        :placeholder="tt('allergies.reaction_ph', 'Ex.: urticária, falta de ar')"
                    />
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1" :for="`${uid}-sev`">{{
                        tt('allergies.severity', 'Gravidade')
                    }}</label>
                    <select :id="`${uid}-sev`" v-model="form.severity" class="form-select form-select-sm">
                        <option value="">{{ tt('severity_unknown', 'Gravidade não informada') }}</option>
                        <option v-for="s in SEVERITIES" :key="s" :value="s">{{ severityLabel(s) }}</option>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1" :for="`${uid}-ing`">{{
                        tt('allergies.active_ingredient', 'Princípio ativo')
                    }}</label>
                    <input
                        :id="`${uid}-ing`"
                        v-model="form.active_ingredient"
                        type="text"
                        maxlength="500"
                        class="form-control form-control-sm"
                        :readonly="Boolean(form.medicine_id)"
                        data-testid="allergy-ingredient"
                    />
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1" :for="`${uid}-not`">{{
                        tt('allergies.notes', 'Observação')
                    }}</label>
                    <input
                        :id="`${uid}-not`"
                        v-model="form.notes"
                        type="text"
                        maxlength="2000"
                        class="form-control form-control-sm"
                    />
                </div>
                <div v-if="canReview" class="col-12">
                    <span class="form-label small mb-1 d-block">{{
                        tt('allergies.register_as', 'Registrar como')
                    }}</span>
                    <div class="d-flex gap-3 flex-wrap">
                        <label class="form-check small mb-0">
                            <input v-model="form.status" class="form-check-input" type="radio" value="confirmed" />
                            {{ tt('allergy_statuses.confirmed', 'Confirmada pelo médico') }}
                        </label>
                        <label class="form-check small mb-0">
                            <input v-model="form.status" class="form-check-input" type="radio" value="reported" />
                            {{ tt('allergy_statuses.reported', 'Informada pelo paciente') }}
                        </label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 justify-content-end mt-2 flex-wrap">
                <small v-if="isLive" class="text-body-secondary me-auto align-self-center">{{
                    tt('allergies.saved_now', 'Gravado na hora (não depende de salvar o cadastro).')
                }}</small>
                <button type="button" class="btn btn-sm btn-light" @click="formOpen = false">
                    {{ tt('allergies.cancel', 'Cancelar') }}
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    :disabled="busy"
                    data-testid="allergy-save"
                    @click="save"
                >
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                    >{{ tt('allergies.save', 'Salvar') }}
                </button>
            </div>
        </div>

        <!-- Ativas -->
        <div v-if="!isLive && listError" class="small text-danger mb-2" data-testid="allergies-list-error">
            {{ listError }}
        </div>
        <ul v-if="active.length" class="list-unstyled mb-2 cs-panel__list" data-testid="allergy-list">
            <li
                v-for="(a, i) in active"
                :key="a.id ?? a._key"
                class="cs-panel__item"
                :class="`cs-panel__item--${a.status ?? 'reported'}`"
            >
                <div class="d-flex align-items-start gap-2 flex-wrap">
                    <div class="flex-grow-1 min-w-0">
                        <strong>{{ a.substance }}</strong>
                        <span class="text-body-secondary small"> · {{ categoryLabel(a.category) }}</span>
                        <div v-if="a.active_ingredient" class="small text-body-secondary">
                            {{
                                fill(tt('allergies.ingredient_line', 'Princípio ativo: :name'), {
                                    name: a.active_ingredient,
                                })
                            }}<span v-if="a.medicine_id"> ({{ tt('allergies.from_catalog', 'do catálogo') }})</span>
                        </div>
                        <div class="small">
                            <span v-if="a.reaction">{{ tt('allergies.reaction', 'Reação') }}: {{ a.reaction }}</span>
                            <span v-if="a.reaction && a.severity"> · </span>
                            <span
                                v-if="a.severity"
                                :class="{ 'fw-semibold text-danger-emphasis': a.severity === 'severe' }"
                                >{{ a.severity_label ?? severityLabel(a.severity) }}</span
                            >
                        </div>
                        <span
                            class="badge mt-1"
                            :class="
                                a.status === 'confirmed'
                                    ? 'text-bg-danger'
                                    : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'
                            "
                            >{{ statusLabel(a) }}</span
                        >
                        <div class="small text-body-secondary mt-1">{{ meta(a) }}</div>
                        <div
                            v-if="!isLive && pendingError(i)"
                            class="small text-danger"
                            data-testid="allergy-pending-error"
                        >
                            {{ pendingError(i) }}
                        </div>
                    </div>
                    <div class="d-flex gap-1 flex-wrap">
                        <template v-if="isLive">
                            <button
                                v-if="canReview && a.status === 'reported'"
                                type="button"
                                class="btn btn-sm btn-success"
                                :disabled="busy"
                                :aria-label="`${tt('allergies.confirm', 'Confirmar')}: ${a.substance}`"
                                data-testid="allergy-confirm"
                                @click="confirmAllergy(a)"
                            >
                                <i class="ti ti-check me-1" aria-hidden="true"></i
                                >{{ tt('allergies.confirm', 'Confirmar') }}
                            </button>
                            <button
                                v-if="canEdit(a)"
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                :disabled="busy"
                                :aria-label="`${tt('allergies.edit', 'Corrigir')}: ${a.substance}`"
                                :title="tt('allergies.edit', 'Corrigir')"
                                @click="openEdit(a)"
                            >
                                <i class="ti ti-pencil" aria-hidden="true"></i>
                            </button>
                            <button
                                v-if="canReview"
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                :disabled="busy"
                                :aria-label="`${tt('allergies.discard', 'Descartar')}: ${a.substance}`"
                                :title="tt('allergies.discard', 'Descartar')"
                                data-testid="allergy-discard"
                                @click="startDiscard(a)"
                            >
                                <i class="ti ti-ban" aria-hidden="true"></i>
                            </button>
                        </template>
                        <button
                            v-else
                            type="button"
                            class="btn btn-sm btn-outline-danger"
                            :aria-label="fill(tt('allergies.pending_remove', 'Remover :name'), { name: a.substance })"
                            @click="removePending(a._key)"
                        >
                            <i class="ti ti-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <!-- Descartar (motivo opcional) -->
                <div
                    v-if="discardingId === a.id"
                    class="border-top mt-2 pt-2"
                    role="group"
                    :aria-label="tt('allergies.discard_title', 'Descartar alergia')"
                >
                    <label class="form-label small mb-1" :for="`${uid}-dr-${a.id}`">{{
                        tt('allergies.discard_reason', 'Motivo do descarte (opcional)')
                    }}</label>
                    <div class="d-flex gap-2 flex-wrap">
                        <input
                            :id="`${uid}-dr-${a.id}`"
                            v-model="discardReason"
                            type="text"
                            maxlength="500"
                            class="form-control form-control-sm flex-grow-1"
                            style="min-width: 12rem"
                        />
                        <button type="button" class="btn btn-sm btn-light" @click="discardingId = null">
                            {{ tt('allergies.cancel', 'Cancelar') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-danger"
                            :disabled="busy"
                            data-testid="allergy-discard-confirm"
                            @click="discard"
                        >
                            {{ tt('allergies.discard_confirm', 'Descartar alergia') }}
                        </button>
                    </div>
                </div>
            </li>
        </ul>
        <p v-else-if="!formOpen" class="small text-body-secondary mb-2">
            {{ tt('allergies.empty', 'Nenhuma alergia ativa.') }}
        </p>

        <!-- Nega alergias -->
        <div v-if="!active.length" class="mb-2">
            <button
                type="button"
                class="btn btn-sm"
                :class="isDenied ? 'btn-secondary' : 'btn-outline-secondary'"
                :aria-pressed="isDenied ? 'true' : 'false'"
                :disabled="busy"
                data-testid="allergy-deny"
                @click="toggleDenied"
            >
                <i class="ti ti-circle-check me-1" aria-hidden="true"></i
                >{{
                    isDenied
                        ? tt('allergies.undeny', 'Desfazer "nega alergias"')
                        : tt('allergies.deny', 'Paciente nega alergias')
                }}
            </button>
            <small v-if="isLive && isDenied && summary?.denied" class="ms-2 text-body-secondary">{{
                fill(tt('banner.denied_detail', 'Nega alergias — registrado por :name em :date'), {
                    name: summary.denied.by ?? '—',
                    date: summary.denied.at_label ?? '',
                })
            }}</small>
        </div>

        <!-- Histórico (descartadas) -->
        <div v-if="discarded.length" class="small">
            <button
                type="button"
                class="btn btn-link btn-sm p-0"
                :aria-expanded="showHistory ? 'true' : 'false'"
                @click="showHistory = !showHistory"
            >
                <i class="ti ti-history me-1" aria-hidden="true"></i
                >{{ tt('allergies.history', 'Histórico (descartadas)') }} ({{ discarded.length }})
            </button>
            <ul v-if="showHistory" class="list-unstyled mt-1 mb-0">
                <li v-for="a in discarded" :key="a.id" class="text-body-secondary">
                    <s>{{ a.substance }}</s> — {{ meta(a)
                    }}<span v-if="a.discard_reason"> ({{ a.discard_reason }})</span>
                </li>
            </ul>
        </div>

        <p class="small text-body-secondary mb-0 mt-2">
            <i class="ti ti-lock me-1" aria-hidden="true"></i
            >{{ tt('allergies.sensitive', 'Dado de saúde sensível (LGPD).') }}
        </p>
    </div>
</template>

<style scoped>
.cs-catalog-results {
    z-index: 1090;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 240px;
    overflow-y: auto;
    font-size: 0.82rem;
}
.cs-catalog-results .list-group-item {
    cursor: pointer;
}
.cs-catalog-results .list-group-item.active .cs-catalog-detail {
    color: inherit;
}
.cs-catalog-detail {
    color: var(--bs-secondary-color);
}
.cs-catalog-chip {
    padding: 0.35rem 0.6rem;
    border: 1px solid var(--bs-success-border-subtle, #a3cfbb);
    border-radius: 0.4rem;
    background: var(--bs-success-bg-subtle, #d1e7dd);
    color: var(--bs-body-color);
    font-size: 0.85rem;
}
[data-bs-theme='dark'] .cs-catalog-chip {
    background: rgba(25, 135, 84, 0.15);
    border-color: rgba(117, 183, 152, 0.45);
}
.cs-panel__item strong {
    color: var(--bs-emphasis-color);
}
.cs-panel__item {
    padding: 0.5rem 0.6rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-left: 4px solid var(--bs-warning, #ffc107);
    border-radius: 0.4rem;
    margin-bottom: 0.4rem;
    background: var(--bs-body-bg);
}
.cs-panel__item--confirmed {
    border-left-color: var(--bs-danger, #dc3545);
}
</style>
