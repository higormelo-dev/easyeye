<script setup>
import { computed, ref, watch } from 'vue';
import Multiselect from '@vueform/multiselect';

// Select com busca, nativo Vue 3 (sem jQuery, SSR-safe).
// Drop-in para <select> simples: v-model + :options (array de objetos).
//
// Onda 4, C2 — modo remoto opcional:
//   - remoteSearchUrl: URL com `__Q__` para substituir pela query digitada.
//     Quando preenchido, busca no servidor (debounced) e substitui as options
//     que ficam visíveis. As `options` iniciais ainda funcionam como seed.
//   - remoteMinChars: número mínimo de caracteres para disparar a busca.
const props = defineProps({
    modelValue:      { type: [String, Number, Boolean, null], default: null },
    options:         { type: Array,   default: () => [] },
    valueKey:        { type: String,  default: 'id' },
    labelKey:        { type: String,  default: 'name' },
    placeholder:     { type: String,  default: 'Selecione...' },
    disabled:        { type: Boolean, default: false },
    clearable:       { type: Boolean, default: true },
    searchable:      { type: Boolean, default: true },
    invalid:         { type: Boolean, default: false },
    remoteSearchUrl: { type: String,  default: '' },
    remoteMinChars:  { type: Number,  default: 2 },
    // Altura máxima da lista aberta (--ms-max-height do @vueform/multiselect;
    // default do tema = 10rem). Listas longas (ex.: acuidade visual) passam
    // um valor maior pra reduzir rolagem.
    listHeight:      { type: String,  default: '' },
});

// `option-selected` — Onda IOL Lenses: emite o OBJETO completo da opção
// escolhida (ou `null` ao limpar), não só o valor escalar do v-model. Só
// existe porque `change`/`update:modelValue` já emitem apenas o valor cru
// (contrato antigo preservado p/ os ~15 consumidores existentes) — quando o
// caller precisa dos DEMAIS campos da linha remota (ex.: IolLensFormModal
// lendo manufacturer/model_name/category/image_url do catálogo global pra
// auto-preencher o resto do form), esse evento novo e opcional resolve sem
// quebrar ninguém que já usa o componente.
const emit = defineEmits(['update:modelValue', 'change', 'option-selected']);

// Onda 4 / C2 fix — em modo remoto, o Multiselect limpa o texto de busca ao
// selecionar uma opção; isso zera `remoteOptions` (ver watch(searchTerm)
// abaixo) e o valor selecionado perde o label (Multiselect não acha mais o
// objeto correspondente em `effectiveOptions`). Fixamos a opção escolhida
// aqui e a reinjetamos em `effectiveOptions` até o valor mudar de novo.
const selectedOption = ref(null);

const value = computed({
    get: () => (props.modelValue === '' ? null : props.modelValue),
    set: (v) => {
        selectedOption.value = v == null
            ? null
            : (effectiveOptions.value.find((o) => o[props.valueKey] === v) ?? selectedOption.value);
        const out = v ?? '';
        emit('update:modelValue', out);
        emit('change', out);
        emit('option-selected', selectedOption.value);
    },
});

// ── Onda 4 / C2 — busca remota debounced ───────────────────────────────────
const remoteOptions = ref([]);
const effectiveOptions = computed(() => {
    const base = props.remoteSearchUrl
        ? (remoteOptions.value.length ? remoteOptions.value : props.options)
        : props.options;

    const sel = selectedOption.value;
    if (!sel) return base;

    return base.some((o) => o[props.valueKey] === sel[props.valueKey]) ? base : [sel, ...base];
});

const searchTerm = ref('');
let remoteTimer = null;

watch(searchTerm, (q) => {
    if (!props.remoteSearchUrl) return;
    if (remoteTimer) clearTimeout(remoteTimer);

    const term = (q || '').trim();
    if (term.length < props.remoteMinChars) {
        remoteOptions.value = [];
        return;
    }

    remoteTimer = setTimeout(async () => {
        try {
            const url = props.remoteSearchUrl.replace('__Q__', encodeURIComponent(term));
            const { data } = await window.axios.get(url);
            const rows = Array.isArray(data?.data) ? data.data : [];
            // Normaliza para a forma esperada pelo Multiselect via valueKey/labelKey,
            // preservando (`...r` primeiro) os DEMAIS campos originais da linha —
            // necessário pro `option-selected` acima devolver o objeto completo
            // (ex.: manufacturer/model_name/category/image_url), não só id/label.
            remoteOptions.value = rows.map((r) => ({
                ...r,
                [props.valueKey]: r.id ?? r[props.valueKey],
                [props.labelKey]: r.label ?? r[props.labelKey],
                sub_label: r.sub_label ?? '',
            }));
        } catch {
            // silencioso — seed mantém UX funcional
        }
    }, 300);
});

function onSearchChange(q) {
    searchTerm.value = q ?? '';
}
</script>

<template>
    <Multiselect
        v-model="value"
        class="search-select"
        :class="{ 'is-invalid': invalid }"
        :style="listHeight ? { '--ms-max-height': listHeight } : {}"
        :options="effectiveOptions"
        :value-prop="valueKey"
        :label="labelKey"
        :track-by="labelKey"
        :searchable="searchable"
        :can-clear="clearable"
        :can-deselect="clearable"
        :close-on-select="true"
        :placeholder="placeholder"
        :disabled="disabled"
        no-options-text="Nenhuma opção"
        no-results-text="Nada encontrado"
        @search-change="onSearchChange"
    />
</template>

<style src="@vueform/multiselect/themes/default.css"></style>

<style>
/* Alinha o multiselect ao visual do .form-select (Bootstrap 5). */
.search-select.multiselect {
    --ms-radius: .375rem;
    --ms-border-color: var(--bs-border-color, #dee2e6);
    --ms-ring-width: .25rem;
    --ms-ring-color: rgba(13, 110, 253, .25);
    --ms-py: .375rem;
    --ms-px: .75rem;
    --ms-font-size: 1rem;
    --ms-line-height: 1.5;
    --ms-option-font-size: 1rem;
    --ms-dropdown-border-color: var(--bs-border-color, #dee2e6);
    --ms-dropdown-radius: .375rem;
    min-height: calc(1.5em + .75rem + 2px);
}

.search-select.multiselect.is-active {
    --ms-border-color: #86b7fe;
    box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25);
}

.search-select.multiselect.is-invalid {
    --ms-border-color: #dc3545;
    --ms-ring-color: rgba(220, 53, 69, .25);
}
</style>
