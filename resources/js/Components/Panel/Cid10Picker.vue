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
 * Props:
 *   modelValue  – v-model, Array<{code, description}>
 *   searchUrl   – endpoint de busca (GET ?q=termo), retorna array
 *                 [{id, code, description, category}] — mesmo shape default
 *                 do Cid10SearchController (sem `shape=select`)
 *   disabled    – desabilita input e remoção
 *   multiple    – permite mais de um CID selecionado (default true)
 *   maxItems    – limite de itens selecionáveis (default 20)
 *   placeholder – placeholder do input de busca
 *   label       – rótulo exibido acima do campo (opcional)
 */
import { ref, computed } from 'vue';

const props = defineProps({
    modelValue:  { type: Array, default: () => [] },
    searchUrl:   { type: String, required: true },
    disabled:    { type: Boolean, default: false },
    multiple:    { type: Boolean, default: true },
    maxItems:    { type: Number, default: 20 },
    placeholder: { type: String, default: 'Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…' },
    label:       { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const selected = computed({
    get: () => props.modelValue ?? [],
    set: (v) => emit('update:modelValue', v),
});

const query       = ref('');
const results     = ref([]);
const open        = ref(false);
const searching   = ref(false);
const activeIndex = ref(-1);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function search() {
    const q = (query.value || '').trim();
    if (q.length < 2 || !props.searchUrl) {
        results.value = [];
        open.value    = false;
        return;
    }

    searching.value = true;
    try {
        const res = await fetch(`${props.searchUrl}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (!res.ok) {
            results.value = [];
            open.value    = false;
            return;
        }
        const list = await res.json();
        results.value = (Array.isArray(list) ? list : []).filter(
            (c) => !selected.value.some((s) => s.code === c.code),
        );
        open.value        = results.value.length > 0;
        activeIndex.value = -1;
    } catch (e) {
        console.error('CID-10 search error:', e);
    } finally {
        searching.value = false;
    }
}

function selectItem(item) {
    if (props.disabled) return;
    if (!props.multiple) {
        selected.value = [{ code: item.code, description: item.description }];
    } else if (selected.value.length < props.maxItems && !selected.value.some((s) => s.code === item.code)) {
        selected.value = [...selected.value, { code: item.code, description: item.description }];
    }
    query.value   = '';
    results.value = [];
    open.value    = false;
}

function removeItem(code) {
    if (props.disabled) return;
    selected.value = selected.value.filter((s) => s.code !== code);
}

function selectActive() {
    if (activeIndex.value >= 0 && activeIndex.value < results.value.length) {
        selectItem(results.value[activeIndex.value]);
    }
}
</script>

<template>
    <div>
        <label v-if="label" class="pmr-label">{{ label }}</label>

        <div v-if="selected.length > 0" class="d-flex flex-wrap gap-1 mb-1">
            <span
                v-for="item in selected"
                :key="item.code"
                class="badge d-inline-flex align-items-center gap-1"
                style="background:#e8f4fd;color:#1a5c8a;font-size:.8rem;font-weight:500;border:1px solid #b8d9f0;padding:.3rem .5rem;"
            >
                <span class="fw-semibold">{{ item.code }}</span>
                <span
                    class="text-secondary fw-normal"
                    style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                >– {{ item.description }}</span>
                <button
                    v-if="!disabled"
                    type="button"
                    class="btn-close btn-close-sm ms-1"
                    style="font-size:.6rem;"
                    @click="removeItem(item.code)"
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
                    @keydown.arrow-down.prevent="activeIndex = Math.min(activeIndex + 1, results.length - 1)"
                    @keydown.arrow-up.prevent="activeIndex = Math.max(activeIndex - 1, 0)"
                    @keydown.enter.prevent="selectActive"
                    @keydown.escape="open = false"
                >
                <span v-if="searching" class="input-group-text bg-transparent border-start-0 px-2">
                    <span class="spinner-border spinner-border-sm text-secondary" style="width:.8rem;height:.8rem;"></span>
                </span>
            </div>
            <ul
                v-if="open && results.length > 0"
                class="list-group shadow-sm position-absolute w-100"
                style="z-index:1055;top:100%;max-height:260px;overflow-y:auto;"
            >
                <li
                    v-for="(item, index) in results"
                    :key="item.id"
                    class="list-group-item list-group-item-action py-1 px-2"
                    :class="{ active: index === activeIndex }"
                    style="cursor:pointer;font-size:.82rem;"
                    @mouseenter="activeIndex = index"
                    @mousedown.prevent="selectItem(item)"
                >
                    <span class="fw-semibold me-1">{{ item.code }}</span>
                    <span>– {{ item.description }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
