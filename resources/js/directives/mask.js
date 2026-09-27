import { MASKS, MASK_KEEP, isFormattable } from '@/utils/masks.js';

/**
 * v-mask="'cpf' | 'cnpj' | 'cpfCnpj' | 'phone' | 'cep'"
 *
 * Máscara BR em tempo real para <input>, usada JUNTO com v-model:
 *
 *   <input v-model="form.national_registry" v-mask="'cpf'" inputmode="numeric">
 *
 * - Digitação/colagem: reformata o valor, preserva a posição do cursor (conta
 *   os caracteres mantidos pela máscara antes do cursor) e reemite `input`
 *   para o v-model receber o valor formatado — independe da ordem dos
 *   listeners do v-model.
 * - Valor vindo do servidor (sem pontuação): exibido formatado sem disparar
 *   `input`, pra não marcar o form como alterado (useForm.isDirty). Só quando
 *   está completo num formato reconhecido (isFormattable) — legado fora do
 *   padrão aparece como está, igual ao BrazilianFormat do PHP.
 *
 * Formatadores em resources/js/utils/masks.js (funções puras, sem dependência).
 * O backend normaliza antes de gravar — a máscara é só apresentação.
 */

const DIGIT = /\d/;

function caretAfterKept(formatted, keptCount, keep) {
    if (keptCount <= 0) return 0;

    let seen = 0;
    for (let i = 0; i < formatted.length; i++) {
        if (keep.test(formatted[i]) && ++seen === keptCount) return i + 1;
    }

    return formatted.length;
}

function onInput(event) {
    const el     = event.target;
    const format = el._maskFormat;
    // Composição (IME) em andamento: o v-model também espera o compositionend.
    if (!format || event.isComposing) return;

    const raw       = el.value;
    const formatted = format(raw);
    if (formatted === raw) return;

    const keep            = el._maskKeep ?? DIGIT;
    const caret           = el.selectionStart ?? raw.length;
    const keptBeforeCaret = [...raw.slice(0, caret)].filter(ch => keep.test(ch)).length;

    el.value = formatted;

    if (el.ownerDocument?.activeElement === el) {
        const pos = caretAfterKept(formatted, keptBeforeCaret, keep);
        el.setSelectionRange(pos, pos);
    }

    // Reemite para o v-model gravar o valor formatado. Na reentrada,
    // formatted === raw e o handler sai cedo (sem loop).
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

function syncView(el) {
    const format = el._maskFormat;
    if (!format || !isFormattable(el._maskName, el.value)) return;

    const formatted = format(el.value);
    if (formatted !== el.value) el.value = formatted;
}

function bind(el, binding) {
    el._maskName   = binding.value;
    el._maskFormat = MASKS[binding.value] ?? null;
    el._maskKeep   = MASK_KEEP[binding.value] ?? DIGIT;
}

export default {
    mounted(el, binding) {
        bind(el, binding);
        el.addEventListener('input', onInput);
        // O v-model também preenche el.value no próprio `mounted`; a ordem entre
        // diretivas depende do template, então formata depois do ciclo atual.
        queueMicrotask(() => syncView(el));
    },
    updated(el, binding) {
        bind(el, binding);
        // Roda depois do beforeUpdate do v-model, que pode ter recolocado o valor cru.
        syncView(el);
    },
    unmounted(el) {
        el.removeEventListener('input', onInput);
        delete el._maskName;
        delete el._maskFormat;
        delete el._maskKeep;
    },
};
