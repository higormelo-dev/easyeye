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
});

const emit = defineEmits(['update:modelValue', 'change']);

const value = computed({
    get: () => (props.modelValue === '' ? null : props.modelValue),
    set: (v) => {
        const out = v ?? '';
        emit('update:modelValue', out);
        emit('change', out);
    },
});

// ── Onda 4 / C2 — busca remota debounced ───────────────────────────────────
const remoteOptions = ref([]);
const effectiveOptions = computed(() => {
    if (!props.remoteSearchUrl) return props.options;
    return remoteOptions.value.length ? remoteOptions.value : props.options;
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
            // Normaliza para a forma esperada pelo Multiselect via valueKey/labelKey.
            remoteOptions.value = rows.map((r) => ({
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
