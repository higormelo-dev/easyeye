<script setup>
import { computed, reactive, ref, useId, watch, onBeforeUnmount } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import Cid10Picker from '@/Components/Panel/Cid10Picker.vue';
import DocumentDateField from './DocumentDateField.vue';
import PersonalTemplatesBar from './PersonalTemplatesBar.vue';
import { documentTexts, td, fill, documentDatePayload, requestSummary } from '@/Support/clinicalDocuments.js';

/**
 * Pedido de exames (Fase 2 / B2): kit (da clínica ou padrão, filtrado pelo
 * convênio do atendimento no servidor) → lista com caixas de marcar, + exames
 * avulsos do catálogo (ou texto livre), caráter, indicação, CIDs (vêm do
 * diagnóstico do prontuário), observações e data do documento. Modelos
 * pessoais do médico no mesmo seletor dos kits (B3).
 *
 * O servidor monta o documento a partir dos itens (nome/código do catálogo
 * vêm dele), valida teto, tenant, indicações e CIDs.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    kitsUrl: { type: String, default: '' },
    procedureSearchUrl: { type: String, default: '' },
    indicationSearchUrl: { type: String, default: '' },
    cid10SearchUrl: { type: String, default: '' },
    templatesUrl: { type: String, default: '' },
    templateItemUrl: { type: String, default: '' },
    // Prontuário de origem (o servidor confere que o modelo não leva dado do paciente).
    templateContext: { type: Object, default: () => ({}) },
    dateConfig: { type: Object, default: () => ({}) },
    limits: { type: Object, default: () => ({ items: 20, indications: 5, cids: 4 }) },
    // Diagnóstico do prontuário ({code, description}[]) — pré-preenche os CIDs.
    initialCids: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
    serverErrors: { type: Object, default: () => ({}) },
    t: { type: Object, default: null },
});

const emit = defineEmits(['close', 'submit']);

const texts = documentTexts(props.t);
const tt = (path, fallback) => td(texts, path, fallback);
const uid = useId();
const TYPES = ['rotina', 'urgencia', 'controle', 'comparativo'];

// ── Estado do pedido ──────────────────────────────────────────────────────
const blank = () => ({
    items: [],
    kitIds: [],
    type: '',
    indications: [],
    indicationText: '',
    cids: [],
    notes: '',
    // Pedido em texto livre (sem item nenhum) — separado de `notes`
    // (observações); os dois reaproveitavam o mesmo campo e um pisava no
    // outro, duplicando o texto no documento ou perdendo a observação do
    // modelo (17ª revisão Codex, P2).
    textOnlyBody: '',
    documentDate: '',
    templateId: '',
});
const state = reactive(blank());
let keySeq = 0;

const checkedItems = computed(() => state.items.filter((i) => i.checked));
const maxItems = computed(() => Number(props.limits?.items ?? 20));
const full = computed(() => checkedItems.value.length >= maxItems.value);
const maxKits = computed(() => Number(props.limits?.kits ?? 5));

// ── Rascunho (aviso de saída do prontuário) ──────────────────────────────
const signature = () =>
    JSON.stringify({
        items: checkedItems.value.map((i) => [i.procedure_id, i.code, i.name]),
        type: state.type,
        ind: state.indications.map((i) => i.id),
        // CIDs e data do documento também contam como edição pendente — sem
        // eles aqui, mudar só um dos dois depois de emitir não disparava o
        // aviso de "saída sem salvar" (2ª revisão Codex).
        cids: state.cids.map((c) => c.code),
        text: state.indicationText.trim(),
        notes: state.notes.trim(),
        // Corpo em texto livre (sem item nenhum) — sem isto, o aviso de
        // "saída sem salvar" nunca disparava pra um pedido só-de-texto, e
        // editar um JÁ EMITIDO também não era detectado (18ª revisão Codex,
        // P2).
        body: state.textOnlyBody.trim(),
        date: state.documentDate,
    });
let issuedSignature = '';
let cidsSeeded = false;

defineExpose({
    hasDraft: () =>
        (checkedItems.value.length > 0 || state.textOnlyBody.trim() !== '') && signature() !== issuedSignature,
    markIssued: () => {
        issuedSignature = signature();
    },
});

function clearAll() {
    Object.assign(state, blank());
    state.cids = [...(props.initialCids ?? [])].slice(0, props.limits?.cids ?? 4);
    issuedSignature = '';
}

// ── Kits (servidor filtra pelo convênio) ─────────────────────────────────
const kits = ref([]);
const covenant = ref(null);
const kitsState = ref('idle'); // idle | loading | error | ready
const choice = ref('');
const templates = ref([]);

async function loadKits() {
    if (!props.kitsUrl) return;
    kitsState.value = 'loading';
    try {
        const res = await fetch(props.kitsUrl, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error();
        const data = await res.json();
        kits.value = data.kits ?? [];
        covenant.value = data.covenant ?? null;
        kitsState.value = 'ready';
    } catch {
        kitsState.value = 'error';
    }
}

async function loadTemplates() {
    if (!props.templatesUrl) return;
    try {
        // Só os modelos de pedido salvos aqui (texto + itens): o HTML do editor
        // de Documentações não tem itens para emitir.
        const res = await fetch(`${props.templatesUrl}?kind=procedure&format=text`, {
            headers: { Accept: 'application/json' },
        });
        templates.value = res.ok ? ((await res.json())?.data ?? []) : [];
    } catch {
        templates.value = [];
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        loadKits();
        loadTemplates();
        // CIDs do diagnóstico do prontuário — só na 1ª abertura (depois, o
        // que o médico ajustou fica).
        if (!cidsSeeded) {
            state.cids = [...(props.initialCids ?? [])].slice(0, props.limits?.cids ?? 4);
            cidsSeeded = true;
        }
    },
    { immediate: true },
);

const clinicKits = computed(() => kits.value.filter((k) => k.origin === 'clinic'));
const globalKits = computed(() => kits.value.filter((k) => k.origin === 'global'));

const itemKey = (i) => (i.procedure_id ? `p:${i.procedure_id}` : `c:${i.code ?? ''}:${String(i.name).toLowerCase()}`);

// Retorna se o item ficou no pedido (já estava ou coube) — false só quando
// recusado por causa do teto (2ª revisão Codex: quem chama em lote, como um
// kit, precisa saber o que não entrou para não anunciar sucesso total).
function addItem(item) {
    const key = itemKey(item);
    const existing = state.items.find((i) => itemKey(i) === key);
    if (existing) {
        // Já marcado: não muda a contagem, sempre cabe. Desmarcado: reativar
        // é igual a entrar com um item novo — também respeita o teto (3ª
        // revisão Codex, P2), senão o kit furava o limite pela porta dos
        // fundos (item já existia, só não estava marcado).
        if (existing.checked) return true;
        if (full.value) return false;
        existing.checked = true;
        return true;
    }
    if (full.value) return false;
    state.items.push({
        key: `i${++keySeq}`,
        procedure_id: item.procedure_id ?? null,
        code: item.code ?? null,
        name: item.name,
        kit_id: item.kit_id ?? null,
        in_catalog: Boolean(item.in_catalog ?? item.procedure_id),
        checked: true,
    });
    return true;
}

const notice = ref('');

// Kits somam (dá para juntar dois): o seletor volta ao "Escolher…" depois de
// cada escolha — direto no DOM, porque o valor do v-model volta ao mesmo ''.
function onChoice(event) {
    const value = event?.target?.value ?? choice.value;
    choice.value = '';
    if (event?.target) event.target.value = '';
    if (value.startsWith('kit:')) {
        const kit = kits.value.find((k) => k.id === value.slice(4));
        if (!kit) return;
        // Teto de KITS por pedido (servidor, MAX_KITS): kit novo além do
        // limite nem entra — o id sem exame correspondente travava a
        // emissão (422) mesmo com o pedido dentro do teto de exames, e não
        // tinha como tirar esse id sem limpar o rascunho (3ª revisão Codex,
        // P2). Reaplicar um kit já escolhido (completar o que faltou) não
        // conta de novo.
        if (!state.kitIds.includes(kit.id) && state.kitIds.length >= maxKits.value) {
            notice.value = fill(tt('request.kits_max', 'Limite de :max kits por pedido.'), { max: maxKits.value });

            return;
        }
        // Teto atingido no meio do kit: os que não couberam ficam de fora —
        // o aviso não pode dizer "adicionado" como se tivesse entrado tudo.
        const omitted = kit.items.filter((item) => !addItem({ ...item, kit_id: kit.id })).map((item) => item.name);
        if (!state.kitIds.includes(kit.id)) state.kitIds.push(kit.id);
        notice.value = omitted.length
            ? fill(
                  tt(
                      'request.kit_partially_applied',
                      'Kit ":name" adicionado só em parte — ficou de fora: :items (limite de :max exames por pedido).',
                  ),
                  {
                      name: kit.name,
                      items: omitted.join(', '),
                      max: maxItems.value,
                  },
              )
            : fill(tt('request.kit_applied', 'Kit ":name" adicionado ao pedido.'), { name: kit.name });
    } else if (value.startsWith('tpl:')) {
        const template = templates.value.find((t) => t.id === value.slice(4));
        if (template) applyTemplate(template);
    }
}

function applyTemplate(template) {
    const p = template.payload ?? {};
    const items = p.items ?? [];
    state.items = [];
    state.kitIds = [];
    items.forEach((item) => addItem({ ...item, in_catalog: Boolean(item.procedure_id) }));
    state.type = p.type ?? '';
    state.indications = (p.indications ?? []).slice(0, props.limits?.indications ?? 5);
    state.indicationText = p.indication_text ?? '';
    // Modelo de pedido SEM payload.items (só corpo) é combinação válida — a
    // API aceita e lista aqui; sem o texto como fallback, o pedido ficava
    // sem exame e sem texto, com "Emitir" desabilitado mesmo avisando que o
    // modelo foi aplicado (14ª revisão Codex, P2). `notes` sempre vem do
    // payload — não do corpo — senão a observação salva no modelo
    // desaparecia e o corpo saía duplicado no documento (17ª revisão Codex,
    // P2).
    state.textOnlyBody = items.length ? '' : (template.body ?? '').trim();
    state.notes = p.notes ?? '';
    state.templateId = template.id;
    notice.value = fill(tt('templates.applied', 'Modelo ":name" aplicado.'), { name: template.name });
}

function removeItem(key) {
    state.items = state.items.filter((i) => i.key !== key);
    // Kit sem nenhum exame no pedido (todos removidos um a um): tira o id
    // também — senão ele ficava "fantasma" ocupando vaga no teto de kits sem
    // nenhum exame correspondente no pedido.
    state.kitIds = state.kitIds.filter((id) => state.items.some((i) => i.kit_id === id));
}

// ── Exame avulso (catálogo da clínica ou texto livre) ────────────────────
const search = ref('');
const results = ref([]);
const resultsOpen = ref(false);
let searchTimer = null;
let searchAbort = null;

function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(runSearch, 250);
}

async function runSearch() {
    const q = search.value.trim();
    searchAbort?.abort();
    if (q.length < 2) {
        results.value = [];
        resultsOpen.value = false;
        return;
    }
    const controller = new AbortController();
    searchAbort = controller;
    try {
        const res = props.procedureSearchUrl
            ? await fetch(`${props.procedureSearchUrl}?q=${encodeURIComponent(q)}`, {
                  headers: { Accept: 'application/json' },
                  signal: controller.signal,
              })
            : null;
        results.value = res?.ok ? await res.json() : [];
    } catch (e) {
        if (e?.name === 'AbortError') return;
        results.value = [];
    }
    resultsOpen.value = true;
}

function pickProcedure(item) {
    addItem({ procedure_id: item.id, code: item.code, name: item.name, in_catalog: true });
    search.value = '';
    results.value = [];
    resultsOpen.value = false;
}

function useTyped() {
    const name = search.value.trim();
    if (name.length < 2) return;
    addItem({ name, in_catalog: false });
    search.value = '';
    resultsOpen.value = false;
}

// ── Indicações ───────────────────────────────────────────────────────────
const indQuery = ref('');
const indResults = ref([]);
const indOpen = ref(false);
let indTimer = null;

function onIndication() {
    clearTimeout(indTimer);
    indTimer = setTimeout(async () => {
        const q = indQuery.value.trim();
        if (q.length < 2 || !props.indicationSearchUrl) {
            indResults.value = [];
            indOpen.value = false;
            return;
        }
        try {
            const res = await fetch(`${props.indicationSearchUrl}?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
            });
            indResults.value = res.ok ? await res.json() : [];
        } catch {
            indResults.value = [];
        }
        indOpen.value = indResults.value.length > 0;
    }, 250);
}

function pickIndication(item) {
    if (!state.indications.some((i) => i.id === item.id) && state.indications.length < (props.limits?.indications ?? 5))
        state.indications.push({ id: item.id, description: item.description });
    indQuery.value = '';
    indResults.value = [];
    indOpen.value = false;
}

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    clearTimeout(indTimer);
    searchAbort?.abort();
});

// ── Modelo pessoal (B3) ──────────────────────────────────────────────────
function buildTemplate() {
    const items = checkedItems.value.map((i) => ({ procedure_id: i.procedure_id, code: i.code, name: i.name }));
    if (!items.length) return null;
    return {
        body: requestSummary(checkedItems.value),
        payload: {
            items,
            type: state.type || null,
            indications: state.indications.map((i) => i.id),
            indication_text: state.indicationText.trim() || null,
            notes: state.notes.trim() || null,
        },
    };
}

// ── Emitir ───────────────────────────────────────────────────────────────
const fieldError = (name) => props.serverErrors?.[name]?.[0] ?? '';
const itemsError = computed(() => {
    const keys = Object.keys(props.serverErrors ?? {}).filter((k) => k === 'items' || k.startsWith('items.'));
    return keys.length ? props.serverErrors[keys[0]][0] : '';
});

function submit() {
    // Pedido sem NENHUM item marcado só vale com o corpo em texto livre
    // (modelo só de corpo aplicado, ou texto digitado direto) — botão já
    // ficava habilitado nesse caso (:disabled abaixo), mas o clique não
    // emitia nada (14ª revisão Codex, P2).
    if ((!checkedItems.value.length && !state.textOnlyBody.trim()) || props.busy) return;
    const payload = {
        items: checkedItems.value.map((i) => ({
            ...(i.procedure_id
                ? { procedure_id: i.procedure_id }
                : { name: i.name, ...(i.code ? { code: i.code } : {}) }),
            ...(i.kit_id ? { kit_id: i.kit_id } : {}),
        })),
        ...(state.kitIds.length ? { kit_ids: state.kitIds } : {}),
        ...(state.type ? { type: state.type } : {}),
        ...(state.indications.length ? { indications: state.indications.map((i) => i.id) } : {}),
        ...(state.indicationText.trim() ? { indication_text: state.indicationText.trim() } : {}),
        ...(state.cids.length ? { cids: state.cids.map((c) => c.code) } : {}),
        ...(state.notes.trim() ? { notes: state.notes.trim() } : {}),
        // Sem item estruturado, o servidor exige `content` (texto livre
        // antigo, `required_without:items`) — vem do corpo em texto livre,
        // não das observações (17ª revisão Codex, P2).
        ...(!checkedItems.value.length && state.textOnlyBody.trim() ? { content: state.textOnlyBody.trim() } : {}),
        ...(state.templateId ? { template_id: state.templateId } : {}),
        ...documentDatePayload(state.documentDate, props.dateConfig?.today),
    };
    emit('submit', payload);
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-clipboard-list me-2 text-primary" aria-hidden="true"></i
                >{{ tt('request.title', 'Pedido de exames') }}
            </h5>
        </template>

        <div class="ee-req" data-testid="procedure-request">
            <!-- Kit ou modelo -->
            <div class="mb-2">
                <label class="form-label small mb-1" :for="`${uid}-kit`">{{
                    tt('request.kit', 'Kit ou modelo')
                }}</label>
                <select
                    :id="`${uid}-kit`"
                    v-model="choice"
                    class="form-select form-select-sm"
                    :disabled="kitsState === 'loading'"
                    :aria-describedby="`${uid}-kit-hint`"
                    data-testid="request-kit-select"
                    @change="onChoice($event)"
                >
                    <option value="">
                        {{
                            kitsState === 'loading'
                                ? tt('request.kits_loading', 'Carregando kits…')
                                : tt('request.kit_ph', 'Escolher kit ou modelo…')
                        }}
                    </option>
                    <optgroup v-if="templates.length" :label="tt('request.kits_mine', 'Meus modelos')">
                        <option v-for="tpl in templates" :key="tpl.id" :value="`tpl:${tpl.id}`">{{ tpl.name }}</option>
                    </optgroup>
                    <optgroup v-if="clinicKits.length" :label="tt('request.kits_clinic', 'Kits da clínica')">
                        <option v-for="kit in clinicKits" :key="kit.id" :value="`kit:${kit.id}`">
                            {{ kit.name }} ({{ kit.items.length }})
                        </option>
                    </optgroup>
                    <optgroup v-if="globalKits.length" :label="tt('request.kits_global', 'Kits padrão do EasyEye')">
                        <option v-for="kit in globalKits" :key="kit.id" :value="`kit:${kit.id}`">
                            {{ kit.name }} ({{ kit.items.length }})
                        </option>
                    </optgroup>
                </select>
                <small :id="`${uid}-kit-hint`" class="form-text d-block">
                    <template v-if="kitsState === 'error'">{{
                        tt('request.kits_error', 'Não foi possível carregar os kits.')
                    }}</template>
                    <template v-else-if="covenant">{{
                        fill(tt('request.kit_covenant', 'Kits para o convênio :name'), { name: covenant.name })
                    }}</template>
                    <template v-else-if="kitsState === 'ready'">{{
                        tt('request.kit_no_covenant', 'Paciente sem convênio — kits sem convênio vinculado.')
                    }}</template>
                    <template v-if="kitsState === 'ready' && !kits.length">
                        {{ tt('request.kits_empty', '') }}</template
                    >
                </small>
                <div class="small text-success" role="status" aria-live="polite">{{ notice }}</div>
            </div>

            <!-- Exames -->
            <fieldset class="ee-req__items mb-2">
                <legend class="form-label small mb-1 d-flex justify-content-between w-100">
                    <span>{{ tt('request.items', 'Exames do pedido') }}</span>
                    <span class="text-body-secondary" data-testid="request-count">{{
                        fill(tt('request.count', ':count de :max exames'), {
                            count: checkedItems.length,
                            max: maxItems,
                        })
                    }}</span>
                </legend>
                <textarea
                    v-if="!state.items.length"
                    v-model="state.textOnlyBody"
                    class="form-control form-control-sm mb-2"
                    rows="3"
                    maxlength="10000"
                    data-testid="request-text-only-body"
                    :placeholder="
                        tt('request.items_empty', 'Escolha um kit, adicione exames avulsos, ou descreva o pedido aqui.')
                    "
                ></textarea>
                <ul v-else class="list-unstyled mb-2" data-testid="request-items">
                    <li v-for="item in state.items" :key="item.key" class="ee-req__item">
                        <div class="form-check mb-0 flex-grow-1 min-w-0">
                            <input
                                :id="`${uid}-${item.key}`"
                                v-model="item.checked"
                                class="form-check-input"
                                type="checkbox"
                                :disabled="!item.checked && full"
                            />
                            <label class="form-check-label" :for="`${uid}-${item.key}`">
                                <span :class="{ 'text-decoration-line-through text-body-secondary': !item.checked }">{{
                                    item.name
                                }}</span>
                                <span v-if="item.code" class="badge bg-secondary-subtle text-secondary-emphasis ms-1">{{
                                    fill(tt('request.code', 'cód. :code'), { code: item.code })
                                }}</span>
                            </label>
                            <div
                                v-if="!item.in_catalog"
                                class="small text-warning-emphasis"
                                data-testid="request-item-warning"
                            >
                                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                >{{
                                    tt(
                                        'request.not_in_catalog',
                                        'Sem cadastro no catálogo de procedimentos da clínica — sai como texto',
                                    )
                                }}
                            </div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-sm btn-link text-danger p-0"
                            :aria-label="fill(tt('request.remove', 'Remover :name'), { name: item.name })"
                            @click="removeItem(item.key)"
                        >
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </button>
                    </li>
                </ul>
                <div v-if="itemsError" class="invalid-feedback d-block" role="alert">{{ itemsError }}</div>
                <div v-if="full" class="small text-warning-emphasis mb-1" role="status">
                    {{ fill(tt('request.max_reached', 'Limite de :max exames por pedido.'), { max: maxItems }) }}
                </div>

                <div class="position-relative">
                    <label class="form-label small mb-1" :for="`${uid}-q`">{{
                        tt('request.add_exam', 'Adicionar exame avulso')
                    }}</label>
                    <input
                        :id="`${uid}-q`"
                        v-model="search"
                        type="text"
                        autocomplete="off"
                        class="form-control form-control-sm"
                        role="combobox"
                        :aria-expanded="resultsOpen ? 'true' : 'false'"
                        :aria-controls="`${uid}-results`"
                        :placeholder="tt('request.search_ph', 'Buscar no catálogo (2+ letras)…')"
                        :disabled="full"
                        data-testid="request-search"
                        @input="onSearch"
                        @keydown.enter.prevent="useTyped"
                    />
                    <ul
                        v-if="resultsOpen && search.trim().length >= 2"
                        :id="`${uid}-results`"
                        class="list-group shadow-sm position-absolute w-100 ee-req__results"
                        role="listbox"
                    >
                        <li
                            v-for="item in results"
                            :key="item.id"
                            class="list-group-item list-group-item-action py-1 px-2 small"
                            role="option"
                            tabindex="0"
                            @mousedown.prevent="pickProcedure(item)"
                            @keydown.enter.prevent="pickProcedure(item)"
                        >
                            <span class="fw-semibold">{{ item.name }}</span>
                            <span v-if="item.code" class="text-body-secondary ms-1">({{ item.code }})</span>
                        </li>
                        <li
                            class="list-group-item list-group-item-action py-1 px-2 small"
                            role="option"
                            tabindex="0"
                            data-testid="request-free-item"
                            @mousedown.prevent="useTyped"
                            @keydown.enter.prevent="useTyped"
                        >
                            <i class="ti ti-pencil me-1" aria-hidden="true"></i
                            >{{ fill(tt('request.free_item', 'Usar ":name" (texto livre)'), { name: search.trim() }) }}
                        </li>
                    </ul>
                </div>
            </fieldset>

            <div class="row g-2 mb-2">
                <div class="col-12 col-sm-5">
                    <label class="form-label small mb-1" :for="`${uid}-type`">{{
                        tt('request.type', 'Caráter')
                    }}</label>
                    <select
                        :id="`${uid}-type`"
                        v-model="state.type"
                        class="form-select form-select-sm"
                        data-testid="request-type"
                    >
                        <option value="">{{ tt('request.type_none', 'Não informar') }}</option>
                        <option v-for="type in TYPES" :key="type" :value="type">
                            {{ tt(`request.types.${type}`, type) }}
                        </option>
                    </select>
                </div>
                <div class="col-12 col-sm-7">
                    <DocumentDateField
                        v-model="state.documentDate"
                        :config="dateConfig"
                        :error="fieldError('document_date')"
                        :t="t"
                    />
                </div>
            </div>

            <!-- Indicação clínica -->
            <div class="mb-2 position-relative">
                <label class="form-label small mb-1" :for="`${uid}-ind`">{{
                    tt('request.indications', 'Indicação clínica')
                }}</label>
                <div v-if="state.indications.length" class="d-flex flex-wrap gap-1 mb-1">
                    <span
                        v-for="ind in state.indications"
                        :key="ind.id"
                        class="badge bg-primary-subtle text-primary-emphasis d-inline-flex align-items-center gap-1"
                    >
                        {{ ind.description }}
                        <button
                            type="button"
                            class="btn-close btn-close-sm"
                            style="font-size: 0.55rem"
                            :aria-label="fill(tt('request.remove', 'Remover :name'), { name: ind.description })"
                            @click="state.indications = state.indications.filter((i) => i.id !== ind.id)"
                        ></button>
                    </span>
                </div>
                <input
                    :id="`${uid}-ind`"
                    v-model="indQuery"
                    type="text"
                    autocomplete="off"
                    class="form-control form-control-sm"
                    :placeholder="tt('request.indication_search_ph', 'Buscar indicação (2+ letras)…')"
                    :disabled="state.indications.length >= (limits?.indications ?? 5)"
                    @input="onIndication"
                />
                <ul v-if="indOpen" class="list-group shadow-sm position-absolute w-100 ee-req__results" role="listbox">
                    <li
                        v-for="item in indResults"
                        :key="item.id"
                        class="list-group-item list-group-item-action py-1 px-2 small"
                        role="option"
                        tabindex="0"
                        @mousedown.prevent="pickIndication(item)"
                        @keydown.enter.prevent="pickIndication(item)"
                    >
                        {{ item.description }}
                    </li>
                </ul>
                <input
                    v-model="state.indicationText"
                    type="text"
                    maxlength="500"
                    class="form-control form-control-sm mt-1"
                    :aria-label="tt('request.indication_text', 'Indicação (texto livre)')"
                    :placeholder="tt('request.indication_text_ph', 'Ex.: suspeita de glaucoma')"
                />
            </div>

            <!-- CIDs -->
            <div class="mb-2">
                <Cid10Picker
                    v-if="cid10SearchUrl"
                    v-model="state.cids"
                    :search-url="cid10SearchUrl"
                    :max-items="limits?.cids ?? 4"
                    :label="tt('request.cids', 'CID-10 do pedido')"
                    :invalid="Boolean(fieldError('cids') || fieldError('cids.0'))"
                />
                <small class="form-text d-block">{{
                    tt('request.cids_hint', 'Vem do diagnóstico do prontuário — ajuste se precisar.')
                }}</small>
                <div v-if="fieldError('cids.0')" class="invalid-feedback d-block">{{ fieldError('cids.0') }}</div>
            </div>

            <div class="mb-2">
                <label class="form-label small mb-1" :for="`${uid}-notes`">{{
                    tt('request.notes', 'Observações (opcional)')
                }}</label>
                <textarea
                    :id="`${uid}-notes`"
                    v-model="state.notes"
                    class="form-control form-control-sm"
                    rows="2"
                    maxlength="2000"
                    :placeholder="tt('request.notes_ph', 'Ex.: realizar em ambos os olhos')"
                ></textarea>
            </div>

            <PersonalTemplatesBar
                v-if="templatesUrl && templateItemUrl"
                v-model="state.templateId"
                kind="procedure"
                format="text"
                :context="templateContext"
                :url="templatesUrl"
                :item-url-template="templateItemUrl"
                :show-select="false"
                :build="buildTemplate"
                :disabled="!checkedItems.length"
                :t="t"
                class="border-top pt-2"
                @changed="loadTemplates"
            />
        </div>

        <template #footer>
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm me-auto"
                :disabled="!state.items.length && !state.notes && !state.textOnlyBody"
                @click="clearAll"
            >
                <i class="ti ti-eraser me-1" aria-hidden="true"></i>{{ tt('request.clear', 'Limpar') }}
            </button>
            <button type="button" class="btn btn-secondary btn-sm" @click="$emit('close')">
                {{ tt('request.cancel', 'Cancelar') }}
            </button>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="busy || (!checkedItems.length && !state.textOnlyBody.trim())"
                data-testid="request-emit"
                @click="submit"
            >
                <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-printer me-1" aria-hidden="true"></i>{{ tt('request.emit', 'Emitir pedido') }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.ee-req__items {
    border: 1px solid var(--bs-border-color);
    border-radius: 0.4rem;
    padding: 0.5rem 0.6rem;
    background: var(--bs-tertiary-bg);
}
.ee-req__item {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.3rem 0;
    border-bottom: 1px dashed var(--bs-border-color);
}
.ee-req__item:last-child {
    border-bottom: 0;
}
.ee-req__results {
    z-index: 1080;
    top: 100%;
    max-height: 240px;
    overflow-y: auto;
}
.min-w-0 {
    min-width: 0;
}
</style>
