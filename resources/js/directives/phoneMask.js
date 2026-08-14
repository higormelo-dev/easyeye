/**
 * v-phone-mask="'cellphone' | 'landline'"
 *
 * Máscara de telefone BR em tempo real — usuário digita só números, o
 * parênteses/espaço/hífen entram sozinhos conforme digita. Mostra o
 * esqueleto da máscara ao focar (comportamento padrão do Inputmask).
 *
 * Usa `Inputmask` (pacote `inputmask`, já dependência do projeto — ver
 * resources/js/vendor.js, onde é carregado como `window.Inputmask` junto
 * com jQuery/Bootstrap legados). Reaproveita a lib já instalada em vez de
 * adicionar uma nova dependência só pra isso.
 *
 * Puramente cosmético/UX: o backend (ex.: PatientRequest::prepareForValidation)
 * já limpa não-dígitos antes de gravar — a máscara nunca é a fonte de
 * verdade do dado armazenado.
 *
 * A lib "patcheia" o setter de `.value` do elemento mascarado especificamente
 * pra funcionar com frameworks reativos (Vue/React/Angular) — um `v-model`
 * setando `el.value` programaticamente (ex.: ao popular o form pra edição)
 * passa pelo setter da lib e é remascarado automaticamente. Por isso não
 * precisa de sincronização manual aqui além de aplicar a máscara 1x no mount.
 */
const PATTERNS = {
    cellphone: '(99) 99999-9999', // DDD + 9 dígitos — ex.: (61) 99999-9999
    landline:  '(99) 9999-9999',  // DDD + 8 dígitos — telefone fixo
};

function resolvePattern(value) {
    return PATTERNS[value] ?? PATTERNS.cellphone;
}

function apply(el, binding) {
    if (typeof window === 'undefined' || !window.Inputmask) return;

    el.inputmask?.remove();

    window.Inputmask({
        mask:            resolvePattern(binding.value),
        placeholder:     '_',
        showMaskOnFocus: true,
        showMaskOnHover: false,
        clearIncomplete: false,
    }).mask(el);
}

export default {
    mounted: apply,
    updated(el, binding) {
        if (binding.value !== binding.oldValue) apply(el, binding);
    },
    unmounted(el) {
        el.inputmask?.remove();
    },
};
