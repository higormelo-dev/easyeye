<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { currencySymbol, formatMoneyInput, parseMoneyInput } from '@/utils/money.js';

/**
 * MoneyInput — valor em dinheiro digitado no formato do idioma do usuário
 * (pt-BR "1.234,56"; en "1,234.56"), com o símbolo da moeda ao lado.
 *
 * - v-model é NÚMERO (2 casas) ou null (vazio) — o backend recebe o valor
 *   canônico, nunca o texto mascarado.
 * - Durante a digitação o texto fica como o usuário escreveu; ao sair do campo
 *   é reformatado ("10,5" → "10,50").
 * - Sem valor inicial fica vazio com placeholder localizado ("0,00"), em vez de
 *   um "0" que precisa ser apagado.
 * - Atributos extras (id, name, aria-describedby, required, data-test...) vão
 *   para o <input>; o id/for fica com quem chama (rótulo visível).
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue:    { type: [Number, String], default: null },
    currency:      { type: String,  default: 'BRL' },
    /** Idioma BCP 47; padrão: o do usuário (prop Inertia `locale`). */
    locale:        { type: String,  default: '' },
    placeholder:   { type: String,  default: '' },
    invalid:       { type: Boolean, default: false },
    disabled:      { type: Boolean, default: false },
    readonly:      { type: Boolean, default: false },
    allowNegative: { type: Boolean, default: false },
    /** 'sm' | '' */
    size:          { type: String,  default: 'sm' },
});

const emit = defineEmits(['update:modelValue', 'blur']);

const page = usePage();

const resolvedLocale  = computed(() => props.locale || String(page?.props?.locale ?? 'pt_BR').replace('_', '-'));
const symbol          = computed(() => currencySymbol(resolvedLocale.value, props.currency));
const placeholderText = computed(() => props.placeholder || formatMoneyInput(0, resolvedLocale.value));

const focused = ref(false);
const text    = ref(formatMoneyInput(props.modelValue, resolvedLocale.value));

// Valor mudou por fora (reset do formulário, edição carregada): reformata,
// exceto enquanto o usuário digita (não reescreve o que ele está escrevendo).
watch(() => props.modelValue, (value) => {
    if (focused.value) return;

    text.value = formatMoneyInput(value, resolvedLocale.value);
});

watch(resolvedLocale, (locale) => {
    text.value = formatMoneyInput(props.modelValue, locale);
});

function onInput(event) {
    text.value = event.target.value;

    emit('update:modelValue', parseMoneyInput(text.value, resolvedLocale.value, { allowNegative: props.allowNegative }));
}

function onFocus() {
    focused.value = true;
}

function onBlur(event) {
    focused.value = false;

    const value = parseMoneyInput(text.value, resolvedLocale.value, { allowNegative: props.allowNegative });

    text.value = formatMoneyInput(value, resolvedLocale.value);
    emit('update:modelValue', value);
    emit('blur', event);
}

const groupClass = computed(() => (props.size === 'sm' ? 'input-group input-group-sm' : 'input-group'));
</script>

<template>
    <div :class="groupClass" class="money-input">
        <span class="input-group-text" aria-hidden="true">{{ symbol }}</span>
        <input
            v-bind="$attrs"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            class="form-control text-end money-input__field"
            :class="{ 'is-invalid': invalid }"
            :value="text"
            :placeholder="placeholderText"
            :disabled="disabled"
            :readonly="readonly"
            :aria-invalid="invalid ? 'true' : 'false'"
            @input="onInput"
            @focus="onFocus"
            @blur="onBlur"
        >
    </div>
</template>

<style scoped>
.money-input__field {
    font-variant-numeric: tabular-nums;
}
</style>
