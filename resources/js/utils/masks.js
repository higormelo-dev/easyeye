/**
 * Máscaras BR (CPF, CNPJ, telefone, CEP) — funções puras: recebem qualquer
 * string (com ou sem pontuação, parcial) e devolvem o valor formatado até onde
 * houver caracteres válidos. Servem tanto para input (via diretiva v-mask)
 * quanto para exibição de valores gravados sem pontuação.
 *
 * O backend normaliza em prepareForValidation dos FormRequests (contraparte PHP:
 * App\Support\BrazilianFormat), então a máscara é apresentação/UX, nunca a
 * fonte de verdade do dado gravado.
 */

function onlyDigits(value, max) {
    return String(value ?? '').replace(/\D/g, '').slice(0, max);
}

/**
 * CNPJ alfanumérico (IN RFB 2.229/2024, emitido desde jul/2026): as 12 primeiras
 * posições aceitam letras e números; os 2 dígitos verificadores são numéricos.
 * CNPJ só numérico continua válido — é o caso particular sem letras.
 */
function cnpjChars(value) {
    const chars = String(value ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '');

    return chars.slice(0, 12) + chars.slice(12).replace(/\D/g, '').slice(0, 2);
}

/** 000.000.000-00 */
export function maskCpf(value) {
    const d = onlyDigits(value, 11);

    if (d.length <= 3) return d;
    if (d.length <= 6) return `${d.slice(0, 3)}.${d.slice(3)}`;
    if (d.length <= 9) return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6)}`;

    return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}`;
}

/** 00.000.000/0000-00 (também AB.CDE.FGH/IJKL-00) */
export function maskCnpj(value) {
    const c = cnpjChars(value);

    if (c.length <= 2)  return c;
    if (c.length <= 5)  return `${c.slice(0, 2)}.${c.slice(2)}`;
    if (c.length <= 8)  return `${c.slice(0, 2)}.${c.slice(2, 5)}.${c.slice(5)}`;
    if (c.length <= 12) return `${c.slice(0, 2)}.${c.slice(2, 5)}.${c.slice(5, 8)}/${c.slice(8)}`;

    return `${c.slice(0, 2)}.${c.slice(2, 5)}.${c.slice(5, 8)}/${c.slice(8, 12)}-${c.slice(12)}`;
}

/**
 * Campo que aceita pessoa física ou jurídica: CPF até 11 dígitos; CNPJ acima
 * disso ou quando há letra (CNPJ alfanumérico — CPF nunca tem letra).
 */
export function maskCpfCnpj(value) {
    const chars = String(value ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '');

    return /[A-Z]/.test(chars) || chars.length > 11 ? maskCnpj(value) : maskCpf(value);
}

/**
 * Telefone BR:
 * - fixo (00) 0000-0000 com até 10 dígitos; celular (00) 00000-0000 com 11 —
 *   dinâmico porque "celular" legado/importado às vezes guarda fixo e vice-versa;
 * - não geográficos, sem DDD: 0800 000 0000 (0800/0300/0500/0900) e 4004-0000
 *   (3003/4004/4020…). Não existe DDD começando por 0, 30 ou 40, então o
 *   prefixo é inequívoco;
 * - DDI 55 descartado quando vem com "+55" (colado ou digitado) ou quando o
 *   valor tem 12/13 dígitos começando por 55 (legado gravado com DDI). Valor já
 *   no formato "(DD) ..." não passa por isso: é digitação além do limite, e o
 *   excedente é descartado;
 * - DDI estrangeiro ("+" seguido de outro código): sem máscara BR, só "+" e
 *   dígitos (até 15, E.164) — nunca vira um número brasileiro de mentira.
 */
export function maskPhone(value) {
    const text = String(value ?? '').trim();
    let d = text.replace(/\D/g, '');

    if (text.startsWith('+')) {
        d = d.slice(0, 15);
        // Ainda digitando o DDI ("+", "+5", "+55"): mantém como está.
        if (d.length <= 2 && '55'.startsWith(d)) return `+${d}`;
        if (!d.startsWith('55')) return `+${d}`;
        d = d.slice(2);
    } else if (!text.startsWith('(') && /^55\d{10,11}$/.test(d)) {
        d = d.slice(2);
    }

    if (d.startsWith('0')) {
        d = d.slice(0, 11);
        if (d.length <= 4) return d;
        if (d.length <= 7) return `${d.slice(0, 4)} ${d.slice(4)}`;

        return `${d.slice(0, 4)} ${d.slice(4, 7)} ${d.slice(7)}`;
    }

    if (/^[34]0/.test(d)) {
        d = d.slice(0, 8);

        return d.length <= 4 ? d : `${d.slice(0, 4)}-${d.slice(4)}`;
    }

    d = d.slice(0, 11);

    if (d.length === 0)  return '';
    if (d.length <= 2)   return `(${d}`;
    if (d.length <= 6)   return `(${d.slice(0, 2)}) ${d.slice(2)}`;
    if (d.length <= 10)  return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;

    return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
}

/** 00000-000 */
export function maskCep(value) {
    const digits = onlyDigits(value, 8);

    return digits.length > 5 ? `${digits.slice(0, 5)}-${digits.slice(5)}` : digits;
}

/** Nome da máscara (usado em v-mask="'cpf'") → função formatadora. */
export const MASKS = Object.freeze({
    cpf:     maskCpf,
    cnpj:    maskCnpj,
    cpfCnpj: maskCpfCnpj,
    phone:   maskPhone,
    cep:     maskCep,
});

/**
 * Caracteres que "sobrevivem" a cada máscara — a diretiva conta esses
 * caracteres antes do cursor para reposicioná-lo após formatar.
 */
export const MASK_KEEP = Object.freeze({
    cnpj:    /[0-9A-Za-z]/,
    cpfCnpj: /[0-9A-Za-z]/,
    phone:   /[0-9+]/,
});

const SEPARATORS = /^[\s().\/+-]*$/;

function onlySeparatorsBesides(value, keep) {
    return SEPARATORS.test([...String(value)].filter(ch => !keep.test(ch)).join(''));
}

function isCompletePhone(value) {
    const text = String(value).trim();
    if (!onlySeparatorsBesides(text, /\d/)) return false; // ramal, "ou", dois números…
    if (text.startsWith('+') && !text.startsWith('+55')) return /^\+\d{8,15}$/.test(text.replace(/[^\d+]/g, ''));

    const d = text.replace(/\D/g, '').replace(/^55(\d{10,11})$/, '$1');

    return /^0\d{10}$/.test(d) || /^[34]0\d{6}$/.test(d) || /^[1-9]\d{9,10}$/.test(d);
}

function isCompleteCpf(value) {
    return onlySeparatorsBesides(value, /\d/) && /^\d{11}$/.test(String(value).replace(/\D/g, ''));
}

function isCompleteCnpj(value) {
    return onlySeparatorsBesides(value, /[0-9A-Za-z]/)
        && /^[A-Z0-9]{12}\d{2}$/.test(String(value).toUpperCase().replace(/[^A-Z0-9]/g, ''));
}

const COMPLETE = Object.freeze({
    cpf:     isCompleteCpf,
    cnpj:    isCompleteCnpj,
    cpfCnpj: v => isCompleteCpf(v) || isCompleteCnpj(v),
    phone:   isCompletePhone,
    cep:     v => onlySeparatorsBesides(v, /\d/) && /^\d{8}$/.test(String(v).replace(/\D/g, '')),
});

/**
 * Valor vindo do servidor só é reformatado quando está completo e num formato
 * reconhecido — mesma política de App\Support\BrazilianFormat no PHP. Legado
 * fora do padrão (ramal, dois números, 8 dígitos sem DDD, texto) aparece como
 * está, em vez de virar outro número truncado.
 */
export function isFormattable(maskName, value) {
    const check = COMPLETE[maskName];

    return Boolean(check && value && check(value));
}
