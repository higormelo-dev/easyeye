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
    modelValue: { type: [String, Number, Boolean, Array, null], default: null },
    options: { type: Array, default: () => [] },
    valueKey: { type: String, default: 'id' },
    labelKey: { type: String, default: 'name' },
    placeholder: { type: String, default: 'Selecione...' },
    disabled: { type: Boolean, default: false },
    clearable: { type: Boolean, default: true },
    searchable: { type: Boolean, default: true },
    invalid: { type: Boolean, default: false },
    remoteSearchUrl: { type: String, default: '' },
    remoteMinChars: { type: Number, default: 2 },
    // Altura máxima da lista aberta (--ms-max-height do @vueform/multiselect;
    // default do tema = 10rem). Listas longas (ex.: acuidade visual) passam
    // um valor maior pra reduzir rolagem.
    listHeight: { type: String, default: '' },
    // Seleção múltipla (mode="multiple" do multiselect): v-model vira ARRAY —
    // características de lente do prontuário (Multifocal + Antirreflexo...).
    // O campo fica numa linha só (não cresce a cada escolha, como os chips do
    // mode="tags" faziam): nomes separados por vírgula + quantidade; a lista
    // inteira fica no title, no texto assistivo e no dropdown (desmarcar).
    multiple: { type: Boolean, default: false },
    // Barra de filtro (busca .input-group-sm + selects lado a lado): sem
    // isso o select sai no tamanho "regular" da lib (~47px) enquanto o
    // input de busca ao lado é "sm" (~31px), destoando de altura.
    sm: { type: Boolean, default: false },
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
    get: () => {
        if (props.multiple) return Array.isArray(props.modelValue) ? props.modelValue : [];

        return props.modelValue === '' ? null : props.modelValue;
    },
    set: (v) => {
        // Modo tags: v-model é sempre ARRAY (nunca degrada pra '' — o
        // multiselect em mode="tags" quebra com valor não-array).
        if (props.multiple) {
            const arr = Array.isArray(v) ? v : v == null ? [] : [v];
            emit('update:modelValue', arr);
            emit('change', arr);

            return;
        }

        selectedOption.value =
            v == null ? null : (effectiveOptions.value.find((o) => o[props.valueKey] === v) ?? selectedOption.value);
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
        ? remoteOptions.value.length
            ? remoteOptions.value
            : props.options
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

// ── Seleção múltipla em uma linha ──────────────────────────────────────────
// Rótulo do multiselect no mode="multiple": os nomes escolhidos, na ordem da
// seleção. A lib usa o mesmo texto para leitores de tela (aria), então a
// quantidade exibida ao lado pode ficar só visual.
function multipleLabel(values) {
    return (values ?? [])
        .map((o) => o?.[props.labelKey])
        .filter((l) => l != null && l !== '')
        .join(', ');
}

// Lista completa no hover (o rótulo da lib tem pointer-events: none).
const selectedTitle = computed(() => {
    if (!props.multiple) return undefined;

    const byValue = new Map(effectiveOptions.value.map((o) => [o[props.valueKey], o]));

    return multipleLabel(value.value.map((v) => byValue.get(v))) || undefined;
});
</script>

<template>
    <Multiselect
        v-model="value"
        class="search-select"
        :class="{ 'is-invalid': invalid, 'search-select--sm': sm, 'search-select--multiple': multiple }"
        :style="listHeight ? { '--ms-max-height': listHeight } : {}"
        :options="effectiveOptions"
        :value-prop="valueKey"
        :label="labelKey"
        :track-by="labelKey"
        :mode="multiple ? 'multiple' : 'single'"
        :hide-selected="multiple ? false : undefined"
        :multiple-label="multipleLabel"
        :title="selectedTitle"
        :searchable="searchable"
        :can-clear="clearable"
        :can-deselect="clearable"
        :close-on-select="!multiple"
        :placeholder="placeholder"
        :disabled="disabled"
        no-options-text="Nenhuma opção"
        no-results-text="Nada encontrado"
        @search-change="onSearchChange"
    >
        <template v-if="multiple" #multiplelabel="{ values }">
            <div class="multiselect-multiple-label search-select__summary">
                <span class="search-select__summary-text">{{ multipleLabel(values) }}</span>
                <span v-if="values.length > 1" class="search-select__count" aria-hidden="true">{{
                    values.length
                }}</span>
            </div>
        </template>
    </Multiselect>
</template>

<style src="@vueform/multiselect/themes/default.css"></style>

<style>
/* Alinha o multiselect ao visual do .form-select (Bootstrap 5). */
.search-select.multiselect {
    --ms-radius: 0.375rem;
    --ms-border-color: var(--bs-border-color, #dee2e6);
    --ms-ring-width: 0.25rem;
    --ms-ring-color: rgba(13, 110, 253, 0.25);
    --ms-py: 0.375rem;
    --ms-px: 0.75rem;
    --ms-font-size: 1rem;
    --ms-line-height: 1.5;
    --ms-option-font-size: 1rem;
    --ms-dropdown-border-color: var(--bs-border-color, #dee2e6);
    --ms-dropdown-radius: 0.375rem;
    min-height: calc(1.5em + 0.75rem + 2px);
    /* A lib (default.css) seta margin:0 auto no .multiselect base — pensado
       pra centralizar um form isolado, mas dentro de um flex row com
       max-width (barras de filtro) sobra espaço "fantasma" dos dois lados
       de cada select, abrindo vãos enormes entre eles em vez de ficarem
       lado a lado. Zera a centralização; quem quiser centralizar usa
       margin no wrapper de fora. */
    margin: 0;
}

/* Variante compacta (prop `sm`) — mesma altura/fonte do .input-group-sm
   do Bootstrap (SearchInput), pra ficar no padrão quando usado lado a
   lado com a busca numa barra de filtro (ex.: report-settings). */
.search-select--sm.multiselect {
    --ms-py: 0.25rem;
    --ms-px: 0.5rem;
    --ms-font-size: 14px;
    --ms-line-height: 1.5;
    --ms-option-font-size: 14px;
    /* height (não só min-height): o wrapper interno do @vueform soma ~2px
       e deixava o controle em 33px vs 31px do input-group-sm ao lado
       (mesmo ajuste já usado em _medical-records.scss .pmr-screen). */
    height: 31px;
    min-height: 31px;
}
.search-select--sm.multiselect .multiselect-wrapper {
    min-height: 0;
    height: 100%;
}

/* Seleção múltipla numa linha: nomes cortados com reticências e a
   quantidade (mesma cor dos antigos chips) sempre visível ao lado. */
.search-select__summary {
    gap: 0.375rem;
}
.search-select__summary-text {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
/* Múltipla, lista aberta: marcadas com fundo claro + ✓ (em vez do bloco
   verde contínuo do tema) — cada item fica distinto e desmarca no clique. */
.search-select--multiple.multiselect {
    --ms-option-bg-selected: rgba(16, 185, 129, 0.12);
    --ms-option-color-selected: var(--bs-body-color, #212529);
    --ms-option-bg-selected-pointed: rgba(16, 185, 129, 0.22);
    --ms-option-color-selected-pointed: var(--bs-body-color, #212529);
}
.search-select--multiple .multiselect-option.is-selected::after {
    content: '\2713';
    margin-left: auto;
    padding-left: 0.5rem;
    color: #10b981;
    font-weight: 700;
}
.search-select__count {
    flex-shrink: 0;
    min-width: 1.25rem;
    padding: 0 0.375rem;
    border-radius: 999px;
    background: var(--ms-tag-bg, #10b981);
    color: var(--ms-tag-color, #fff);
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1.25rem;
    text-align: center;
}

.search-select.multiselect.is-active {
    --ms-border-color: #86b7fe;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

.search-select.multiselect.is-invalid {
    --ms-border-color: #dc3545;
    --ms-ring-color: rgba(220, 53, 69, 0.25);
}

/*
 * Dark mode: a lib (@vueform/multiselect/themes/default.css) só expõe cor
 * de fundo/borda via --ms-*; a caixa e o dropdown de opções sem essas vars
 * setadas ficam com o fallback #fff da própria lib (fixo, não reage a
 * data-bs-theme) enquanto o texto herda a cor clara do body em dark mode —
 * texto claro sobre fundo branco fixo, quase ilegível. A lib também não
 * expõe var nenhuma pro texto do valor selecionado nem da opção "normal"
 * (só pointed/selected/disabled têm --ms-option-color-*) — por isso o
 * `color` explícito abaixo, herdado via cascata normal do CSS pro texto.
 * Mesma paleta já usada nos inputs (.pmr-form dark, _medical-records.scss)
 * — consistência entre os dois pontos de entrada de dado do sistema.
 */
:root[data-bs-theme='dark'] .search-select.multiselect {
    --ms-bg: #121a26;
    --ms-bg-disabled: #18212f;
    --ms-border-color: #384559;
    --ms-border-color-active: var(--primary);
    --ms-dropdown-bg: #121a26;
    --ms-dropdown-border-color: #384559;
    --ms-placeholder-color: #8695a8;
    --ms-caret-color: #8695a8;
    --ms-clear-color: #8695a8;
    --ms-clear-color-hover: #dbe4ef;
    --ms-empty-color: #8695a8;
    --ms-option-bg-pointed: #1c2735;
    --ms-option-color-pointed: #dbe4ef;
    --ms-option-bg-selected: var(--primary);
    --ms-option-color-selected: #fff;
    --ms-option-bg-selected-pointed: var(--primary-hover, var(--primary));
    --ms-option-color-selected-pointed: #fff;
    --ms-option-bg-disabled: #18212f;
    --ms-option-color-disabled: #55627a;
    --ms-group-label-bg: #18212f;
    --ms-group-label-color: #b9c7d8;
    --ms-group-label-bg-pointed: #1c2735;
    --ms-group-label-color-pointed: #dbe4ef;
    color: #dbe4ef;
}

:root[data-bs-theme='dark'] .search-select.multiselect.is-active {
    --ms-border-color: var(--primary);
}

:root[data-bs-theme='dark'] .search-select--multiple.multiselect {
    --ms-option-bg-selected: rgba(16, 185, 129, 0.18);
    --ms-option-color-selected: #dbe4ef;
    --ms-option-bg-selected-pointed: rgba(16, 185, 129, 0.3);
    --ms-option-color-selected-pointed: #fff;
}
</style>
