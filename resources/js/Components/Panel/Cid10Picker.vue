<script setup>
/**
 * Cid10Picker — seletor de diagnóstico (CID-10) com autocomplete.
 *
 * Extraído do bloco inline de CID-10 do MedicalRecordForm.vue (prontuário),
 * com API genérica (v-model de array de {code, description}) para reuso em
 * outros fluxos de laudo — ex.: aprovação de laudo de IA do Gerenciador de
 * Imagens (AiAssistantPanel.vue). O MedicalRecordForm.vue continua com sua
 * implementação própria (form clínico versionado/assinado — risco alto pra
 * ganho zero refatorar agora); este componente é a base para migrá-lo depois.
 *
 * Estendido para o "Diagnóstico do exame" (DiagnosisManagerModal.vue), que
 * busca no catálogo combinado CID-10 + customizados da clínica
 * (ExamDiagnosisController::search) — resposta em `{data: [...]}`, itens
 * `{code|custom_diagnosis_id, description}`. O endpoint original
 * (Cid10SearchController) continua retornando array cru
 * `[{id, code, description, category}]` — o método search() abaixo aceita
 * as duas formas de resposta.
 *
 * Acessibilidade: padrão combobox do WAI-ARIA (input role=combobox +
 * listbox/option, aria-activedescendant), Esc fecha só a lista (não o modal
 * em volta), Tab para outro campo ou clique fora fecham a lista, região de
 * status anuncia "buscando"/total. Textos no idioma do usuário via `t_ui.cid10`
 * (lang/{locale}/ui.php, compartilhado em toda página); o português abaixo é só
 * fallback, como no CenteredModal. Chips com as cores "subtle" do Bootstrap
 * (acompanham o tema escuro).
 *
 * Props:
 *   modelValue       – v-model, Array<{code, description, custom_diagnosis_id?, is_primary?}>
 *   searchUrl        – endpoint de busca (GET ?q=termo). Aceita array cru
 *                       [{code, description, ...}] ou {data: [...]}.
 *   mostUsedUrl       – (opcional) endpoint de "mais usados", chamado ao
 *                       focar o input ainda vazio (antes de digitar). Mesmo
 *                       formato de resposta do searchUrl.
 *   allowCustomEntry – (opcional, default false) quando true e a busca não
 *                       encontra um resultado com match exato, mostra a
 *                       linha "+ Cadastrar novo diagnóstico" que emite
 *                       `create` com o termo digitado — o pai decide como
 *                       persistir (endpoint de criação) e injeta o resultado
 *                       de volta no v-model.
 *   creating         – (opcional, default false) enquanto true, desabilita e
 *                       mostra spinner na linha de cadastro — controlado
 *                       pelo pai durante o POST de criação.
 *   primaryToggle    – (opcional, default false) cada chip selecionado ganha
 *                       uma estrela clicável pra marcar como diagnóstico
 *                       principal (só um por vez); os itens do v-model
 *                       passam a incluir `is_primary: boolean`.
 *   disabled         – desabilita input e remoção
 *   multiple         – permite mais de um CID selecionado (default true)
 *   maxItems         – limite de itens selecionáveis (default 20)
 *   placeholder      – placeholder do input de busca (padrão: t_ui.cid10.placeholder)
 *   label            – rótulo exibido acima do campo (opcional, ligado ao input)
 *   inputId          – (opcional) id do input, quando o rótulo fica fora (ex.: CidField)
 *   ariaLabelledby   – (opcional) id do rótulo externo
 *   ariaDescribedby  – (opcional) ids de dica/erro externos
 *   invalid          – (opcional) marca o input com aria-invalid
 *
 * Emits:
 *   update:modelValue
 *   create(term: string) — termo sem match exato, só quando allowCustomEntry
 */
import { ref, computed, useId, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useTrans } from '@/composables/useTrans';

const props = defineProps({
    modelValue:       { type: Array,   default: () => [] },
    searchUrl:        { type: String,  required: true },
    mostUsedUrl:      { type: String,  default: '' },
    allowCustomEntry: { type: Boolean, default: false },
    creating:         { type: Boolean, default: false },
    primaryToggle:    { type: Boolean, default: false },
    disabled:         { type: Boolean, default: false },
    multiple:         { type: Boolean, default: true },
    maxItems:         { type: Number,  default: 20 },
    placeholder:      { type: String,  default: '' },
    label:            { type: String,  default: '' },
    inputId:          { type: String,  default: '' },
    ariaLabelledby:   { type: String,  default: '' },
    ariaDescribedby:  { type: String,  default: '' },
    invalid:          { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'create']);

// Fallback (pt_BR) — o texto do idioma do usuário vem de t_ui.cid10.
const FALLBACK_TEXT = {
    placeholder:    'Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…',
    search_label:   'Buscar diagnóstico (CID-10)',
    suggestions:    'Sugestões de diagnóstico',
    most_used:      'Mais usados',
    custom:         'Customizado',
    create:         "Cadastrar novo diagnóstico: ':term'",
    primary:        'Diagnóstico principal',
    mark_primary:   'Marcar como diagnóstico principal',
    primary_toggle: 'Diagnóstico principal: :item',
    remove:         'Remover :item',
    searching:      'Buscando…',
    results_one:    ':count resultado',
    results_other:  ':count resultados',
    no_results:     'Nenhum diagnóstico encontrado.',
};

const page   = usePage();
const text   = computed(() => ({ ...FALLBACK_TEXT, ...(page?.props?.t_ui?.cid10 ?? {}) }));
const { tx } = useTrans(() => text.value);

const selected = computed({
    get: () => props.modelValue ?? [],
    set: (v) => emit('update:modelValue', v),
});

const query           = ref('');
const results         = ref([]);
const mostUsedResults = ref([]);
const showingMostUsed = ref(false);
const open            = ref(false);
const searching       = ref(false);
const loadingMostUsed = ref(false);
const activeIndex     = ref(-1);
// Busca concluída para o termo atual (para anunciar "nenhum encontrado").
const searched        = ref(false);

const rootRef    = ref(null);
const uid        = useId();
const inputDomId = computed(() => props.inputId || `cid10-${uid}`);
const listboxId  = computed(() => `${inputDomId.value}-listbox`);
const createId   = computed(() => `${listboxId.value}-create`);
const optionId   = (index) => `${listboxId.value}-opt-${index}`;

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Identidade estável de um item de diagnóstico — CID-10 pelo código,
 * customizado pelo id (código sempre nulo pra customizados). Usada pra
 * dedupe contra a seleção atual e como :key das listas.
 */
function itemId(item) {
    if (!item) return '';
    return item.custom_diagnosis_id ? `custom:${item.custom_diagnosis_id}` : `cid10:${item.code}`;
}

/** Texto do chip para leitores de tela ("H40.1 – Glaucoma" ou só a descrição). */
function itemText(item) {
    return item.code ? `${item.code} – ${item.description}` : item.description;
}

/** Aceita tanto array cru quanto {data: [...]} — ver doc do componente. */
function unwrapList(json) {
    if (Array.isArray(json)) return json;
    if (Array.isArray(json?.data)) return json.data;
    return [];
}

const trimmedQuery = computed(() => (query.value || '').trim());

/** Lista exibida no dropdown: "mais usados" (sem digitação) ou busca. */
const activeList = computed(() => (
    trimmedQuery.value.length === 0 && showingMostUsed.value ? mostUsedResults.value : results.value
));

const hasExactMatch = computed(() => {
    const q = trimmedQuery.value.toLowerCase();
    if (!q) return false;
    return results.value.some((r) => (r.description || '').toLowerCase() === q || (r.code || '').toLowerCase() === q);
});

const showCreateRow = computed(() => (
    props.allowCustomEntry && !props.disabled && trimmedQuery.value.length >= 2 && !hasExactMatch.value
));

const showingMostUsedList = computed(() => trimmedQuery.value.length === 0 && showingMostUsed.value);

const listVisible = computed(() => open.value && (activeList.value.length > 0 || showCreateRow.value));

const activeDescendant = computed(() => {
    if (!listVisible.value || activeIndex.value < 0) return undefined;
    if (activeIndex.value < activeList.value.length) return optionId(activeIndex.value);
    return showCreateRow.value ? createId.value : undefined;
});

const statusText = computed(() => {
    if (searching.value || loadingMostUsed.value) return text.value.searching;

    const count = activeList.value.length;
    if (listVisible.value && count > 0) return tx(count === 1 ? 'results_one' : 'results_other', { count });

    return searched.value && trimmedQuery.value.length >= 2 && count === 0 ? text.value.no_results : '';
});

function closeList() {
    open.value        = false;
    activeIndex.value = -1;
}

async function search() {
    showingMostUsed.value = false;
    searched.value        = false;
    const q = trimmedQuery.value;
    if (q.length < 2 || !props.searchUrl) {
        results.value = [];
        open.value    = showCreateRow.value;
        return;
    }

    searching.value = true;
    try {
        const res = await fetch(`${props.searchUrl}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (!res.ok) {
            results.value = [];
            open.value    = showCreateRow.value;
            return;
        }
        const list = unwrapList(await res.json());
        results.value = list.filter((c) => !selected.value.some((s) => itemId(s) === itemId(c)));
        activeIndex.value = -1;
        searched.value    = true;
        open.value = results.value.length > 0 || showCreateRow.value;
    } catch (e) {
        console.error('CID-10 search error:', e);
    } finally {
        searching.value = false;
    }
}

/** Ao focar o input vazio: mostra "Mais usados" (só quando mostUsedUrl é dado). */
async function onFocus() {
    if (props.disabled || !props.mostUsedUrl || trimmedQuery.value.length > 0) return;

    loadingMostUsed.value = true;
    try {
        const res = await fetch(props.mostUsedUrl, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (!res.ok) return;
        const list = unwrapList(await res.json());
        mostUsedResults.value = list.filter((c) => !selected.value.some((s) => itemId(s) === itemId(c)));
        showingMostUsed.value = true;
        activeIndex.value     = -1;
        open.value            = mostUsedResults.value.length > 0;
    } catch (e) {
        console.error('CID-10 mais usados error:', e);
    } finally {
        loadingMostUsed.value = false;
    }
}

/**
 * Tab para outro campo fecha a lista. Sem relatedTarget (ex.: clique na barra
 * de rolagem da própria lista) não fecha — o clique fora trata o resto.
 */
function onBlur(event) {
    const next = event.relatedTarget;
    if (next && !rootRef.value?.contains(next)) closeList();
}

/** Esc fecha só a lista; com ela fechada o Esc segue para o modal em volta. */
function onEscape(event) {
    if (!listVisible.value) return;
    event.stopPropagation();
    closeList();
}

function onDocumentPointerDown(event) {
    if (open.value && rootRef.value && !rootRef.value.contains(event.target)) closeList();
}

onMounted(() => document.addEventListener('pointerdown', onDocumentPointerDown, true));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointerDown, true));

function buildSelectedItem(item) {
    const built = { code: item.code ?? null, description: item.description };
    if (item.custom_diagnosis_id) built.custom_diagnosis_id = item.custom_diagnosis_id;
    // Primeiro item vira principal por padrão (mesma regra de
    // DiagnosisCatalogService::normalizePrimary no backend); o usuário troca
    // depois clicando na estrela de outro chip.
    if (props.primaryToggle) built.is_primary = !props.multiple || selected.value.length === 0;
    return built;
}

function selectItem(item) {
    if (props.disabled) return;
    if (!props.multiple) {
        selected.value = [buildSelectedItem(item)];
    } else if (selected.value.length < props.maxItems && !selected.value.some((s) => itemId(s) === itemId(item))) {
        selected.value = [...selected.value, buildSelectedItem(item)];
    }
    query.value            = '';
    results.value          = [];
    mostUsedResults.value  = [];
    showingMostUsed.value  = false;
    searched.value         = false;
    closeList();
}

function removeItem(id) {
    if (props.disabled) return;
    const remaining = selected.value.filter((s) => itemId(s) !== id);
    // Se o item removido era o principal, promove o primeiro restante —
    // mantém sempre exatamente um is_primary quando primaryToggle está ativo.
    if (props.primaryToggle && remaining.length && !remaining.some((s) => s.is_primary)) {
        remaining[0] = { ...remaining[0], is_primary: true };
    }
    selected.value = remaining;
}

function togglePrimary(id) {
    if (props.disabled || !props.primaryToggle) return;
    selected.value = selected.value.map((item) => ({ ...item, is_primary: itemId(item) === id }));
}

function triggerCreate() {
    if (props.disabled || props.creating || !trimmedQuery.value) return;
    emit('create', trimmedQuery.value);
    query.value    = '';
    results.value  = [];
    searched.value = false;
    closeList();
}

function selectActive() {
    const list = activeList.value;
    if (activeIndex.value >= 0 && activeIndex.value < list.length) {
        selectItem(list[activeIndex.value]);
        return;
    }
    if (showCreateRow.value && activeIndex.value === list.length) {
        triggerCreate();
    }
}

function moveActive(delta) {
    const max = activeList.value.length - 1 + (showCreateRow.value ? 1 : 0);
    activeIndex.value = Math.min(Math.max(activeIndex.value + delta, 0), Math.max(max, 0));
}
</script>

<template>
    <div ref="rootRef">
        <label v-if="label" :for="inputDomId" class="pmr-label">{{ label }}</label>

        <div v-if="selected.length > 0" class="d-flex flex-wrap gap-1 mb-1">
            <span
                v-for="item in selected"
                :key="itemId(item)"
                class="badge cid-chip d-inline-flex align-items-center gap-1 border"
                :class="primaryToggle && item.is_primary
                    ? 'bg-warning-subtle text-warning-emphasis border-warning-subtle'
                    : 'bg-primary-subtle text-primary-emphasis border-primary-subtle'"
                data-test="cid-chip"
            >
                <button
                    v-if="primaryToggle && !disabled"
                    type="button"
                    class="btn btn-link p-0 border-0 lh-1 cid-chip__star"
                    :title="item.is_primary ? text.primary : text.mark_primary"
                    :aria-label="tx('primary_toggle', { item: itemText(item) })"
                    :aria-pressed="item.is_primary ? 'true' : 'false'"
                    data-test="cid-primary"
                    @click="togglePrimary(itemId(item))"
                ><i class="fa" :class="item.is_primary ? 'fa-star text-warning' : 'fa-star-o text-body-secondary'" aria-hidden="true"></i></button>
                <template v-else-if="primaryToggle && item.is_primary">
                    <i class="fa fa-star text-warning cid-chip__star" :title="text.primary" aria-hidden="true"></i>
                    <span class="visually-hidden">{{ text.primary }}</span>
                </template>
                <span v-if="item.code" class="fw-semibold">{{ item.code }}</span>
                <span class="fw-normal cid-chip__description">{{ item.code ? '– ' : '' }}{{ item.description }}</span>
                <button
                    v-if="!disabled"
                    type="button"
                    class="btn-close btn-close-sm ms-1 cid-chip__remove"
                    :title="tx('remove', { item: itemText(item) })"
                    :aria-label="tx('remove', { item: itemText(item) })"
                    data-test="cid-remove"
                    @click="removeItem(itemId(item))"
                ></button>
            </span>
        </div>

        <div v-if="!disabled && (multiple ? selected.length < maxItems : selected.length === 0)" class="position-relative">
            <div class="input-group input-group-sm">
                <input
                    :id="inputDomId"
                    v-model="query"
                    type="text"
                    class="form-control form-control-sm"
                    autocomplete="off"
                    role="combobox"
                    aria-autocomplete="list"
                    :aria-expanded="listVisible ? 'true' : 'false'"
                    :aria-controls="listboxId"
                    :aria-activedescendant="activeDescendant"
                    :aria-labelledby="ariaLabelledby || undefined"
                    :aria-label="!label && !ariaLabelledby ? text.search_label : undefined"
                    :aria-describedby="ariaDescribedby || undefined"
                    :aria-invalid="invalid ? 'true' : undefined"
                    :placeholder="placeholder || text.placeholder"
                    @input="search"
                    @focus="onFocus"
                    @blur="onBlur"
                    @keydown.arrow-down.prevent="moveActive(1)"
                    @keydown.arrow-up.prevent="moveActive(-1)"
                    @keydown.enter.prevent="selectActive"
                    @keydown.esc="onEscape"
                >
                <span v-if="searching || loadingMostUsed" class="input-group-text bg-transparent border-start-0 px-2">
                    <span class="spinner-border spinner-border-sm text-secondary cid-spinner" aria-hidden="true"></span>
                </span>
            </div>
            <span class="visually-hidden" role="status" aria-live="polite">{{ statusText }}</span>
            <ul
                v-if="listVisible"
                :id="listboxId"
                role="listbox"
                :aria-label="showingMostUsedList ? text.most_used : text.suggestions"
                class="list-group shadow-sm position-absolute w-100 cid-listbox"
            >
                <li v-if="showingMostUsedList && activeList.length > 0"
                    class="list-group-item disabled text-body-secondary fw-semibold py-1 px-2 cid-listbox__heading"
                    aria-hidden="true"
                >{{ text.most_used }}</li>

                <li
                    v-for="(item, index) in activeList"
                    :id="optionId(index)"
                    :key="itemId(item) || index"
                    role="option"
                    :aria-selected="index === activeIndex ? 'true' : 'false'"
                    class="list-group-item list-group-item-action py-1 px-2 cid-listbox__option"
                    :class="{ active: index === activeIndex }"
                    @mouseenter="activeIndex = index"
                    @mousedown.prevent="selectItem(item)"
                >
                    <span v-if="item.code" class="fw-semibold me-1">{{ item.code }}</span>
                    <span>{{ item.code ? '– ' : '' }}{{ item.description }}</span>
                    <span v-if="!item.code" class="badge bg-secondary-subtle text-secondary-emphasis ms-1 cid-listbox__badge">{{ text.custom }}</span>
                </li>

                <li
                    v-if="showCreateRow"
                    :id="createId"
                    role="option"
                    :aria-selected="activeIndex === activeList.length ? 'true' : 'false'"
                    :aria-disabled="creating ? 'true' : undefined"
                    class="list-group-item list-group-item-action py-1 px-2 text-primary cid-listbox__option"
                    :class="{ active: activeIndex === activeList.length, disabled: creating }"
                    data-test="cid-create"
                    @mouseenter="activeIndex = activeList.length"
                    @mousedown.prevent="triggerCreate"
                >
                    <span v-if="creating" class="spinner-border spinner-border-sm me-1 cid-spinner" aria-hidden="true"></span>
                    <span v-else aria-hidden="true">+</span>
                    {{ tx('create', { term: trimmedQuery }) }}
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
.cid-chip {
    font-size: 0.8rem;
    font-weight: 500;
    padding: 0.3rem 0.5rem;
}

.cid-chip__star {
    font-size: 0.75rem;
}

.cid-chip__description {
    max-width: 260px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    opacity: 0.85;
}

.cid-chip__remove {
    font-size: 0.6rem;
}

.cid-listbox {
    z-index: 1055;
    top: 100%;
    max-height: 260px;
    overflow-y: auto;
}

.cid-listbox__heading {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.cid-listbox__option {
    cursor: pointer;
    font-size: 0.82rem;
}

.cid-listbox__badge {
    font-size: 0.6rem;
}

.cid-spinner {
    width: 0.8rem;
    height: 0.8rem;
}
</style>
