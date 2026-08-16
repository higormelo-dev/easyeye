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
 *   placeholder      – placeholder do input de busca
 *   label            – rótulo exibido acima do campo (opcional)
 *
 * Emits:
 *   update:modelValue
 *   create(term: string) — termo sem match exato, só quando allowCustomEntry
 */
import { ref, computed } from 'vue';

const props = defineProps({
    modelValue:       { type: Array,   default: () => [] },
    searchUrl:        { type: String,  required: true },
    mostUsedUrl:      { type: String,  default: '' },
    allowCustomEntry: { type: Boolean, default: false },
    creating:         { type: Boolean, default: false },
    primaryToggle:    { type: Boolean, default: false },
    disabled:         { type: Boolean, default: false },
    multiple:         { type: Boolean, default: true },
    maxItems:         { type: Number, default: 20 },
    placeholder: { type: String, default: 'Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…' },
    label:       { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'create']);

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

async function search() {
    showingMostUsed.value = false;
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
    open.value             = false;
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
    query.value   = '';
    results.value = [];
    open.value    = false;
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
    <div>
        <label v-if="label" class="pmr-label">{{ label }}</label>

        <div v-if="selected.length > 0" class="d-flex flex-wrap gap-1 mb-1">
            <span
                v-for="item in selected"
                :key="itemId(item)"
                class="badge d-inline-flex align-items-center gap-1"
                :style="{
                    background: primaryToggle && item.is_primary ? '#fff6e0' : '#e8f4fd',
                    color: '#1a5c8a',
                    fontSize: '.8rem',
                    fontWeight: 500,
                    border: primaryToggle && item.is_primary ? '1px solid #f0c14b' : '1px solid #b8d9f0',
                    padding: '.3rem .5rem',
                }"
            >
                <button
                    v-if="primaryToggle && !disabled"
                    type="button"
                    class="btn btn-link p-0 border-0 lh-1"
                    style="font-size:.75rem;"
                    :title="item.is_primary ? 'Diagnóstico principal' : 'Marcar como diagnóstico principal'"
                    @click="togglePrimary(itemId(item))"
                ><i class="fa" :class="item.is_primary ? 'fa-star text-warning' : 'fa-star-o text-muted'"></i></button>
                <i v-else-if="primaryToggle && item.is_primary" class="fa fa-star text-warning" style="font-size:.75rem;" title="Diagnóstico principal"></i>
                <span v-if="item.code" class="fw-semibold">{{ item.code }}</span>
                <span
                    class="text-secondary fw-normal"
                    style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                >{{ item.code ? '– ' : '' }}{{ item.description }}</span>
                <button
                    v-if="!disabled"
                    type="button"
                    class="btn-close btn-close-sm ms-1"
                    style="font-size:.6rem;"
                    @click="removeItem(itemId(item))"
                ></button>
            </span>
        </div>

        <div v-if="!disabled && (multiple ? selected.length < maxItems : selected.length === 0)" class="position-relative">
            <div class="input-group input-group-sm">
                <input
                    v-model="query"
                    type="text"
                    class="form-control form-control-sm"
                    autocomplete="off"
                    :placeholder="placeholder"
                    @input="search"
                    @focus="onFocus"
                    @keydown.arrow-down.prevent="moveActive(1)"
                    @keydown.arrow-up.prevent="moveActive(-1)"
                    @keydown.enter.prevent="selectActive"
                    @keydown.escape="open = false"
                >
                <span v-if="searching || loadingMostUsed" class="input-group-text bg-transparent border-start-0 px-2">
                    <span class="spinner-border spinner-border-sm text-secondary" style="width:.8rem;height:.8rem;"></span>
                </span>
            </div>
            <ul
                v-if="open && (activeList.length > 0 || showCreateRow)"
                class="list-group shadow-sm position-absolute w-100"
                style="z-index:1055;top:100%;max-height:260px;overflow-y:auto;"
            >
                <li v-if="trimmedQuery.length === 0 && showingMostUsed && activeList.length > 0"
                    class="list-group-item disabled text-muted fw-semibold py-1 px-2"
                    style="font-size:.68rem;text-transform:uppercase;letter-spacing:.03em;"
                >Mais usados</li>

                <li
                    v-for="(item, index) in activeList"
                    :key="itemId(item) || index"
                    class="list-group-item list-group-item-action py-1 px-2"
                    :class="{ active: index === activeIndex }"
                    style="cursor:pointer;font-size:.82rem;"
                    @mouseenter="activeIndex = index"
                    @mousedown.prevent="selectItem(item)"
                >
                    <span v-if="item.code" class="fw-semibold me-1">{{ item.code }}</span>
                    <span>{{ item.code ? '– ' : '' }}{{ item.description }}</span>
                    <span v-if="!item.code" class="badge bg-secondary-subtle text-secondary ms-1" style="font-size:.6rem;">Customizado</span>
                </li>

                <li
                    v-if="showCreateRow"
                    class="list-group-item list-group-item-action py-1 px-2 text-primary"
                    :class="{ active: activeIndex === activeList.length, disabled: creating }"
                    style="cursor:pointer;font-size:.82rem;"
                    @mouseenter="activeIndex = activeList.length"
                    @mousedown.prevent="triggerCreate"
                >
                    <span v-if="creating" class="spinner-border spinner-border-sm me-1" style="width:.75rem;height:.75rem;"></span>
                    <span v-else>+</span>
                    Cadastrar novo diagnóstico: '{{ trimmedQuery }}'
                </li>
            </ul>
        </div>
    </div>
</template>
