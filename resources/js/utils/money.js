/**
 * Valores monetários digitados no formato do idioma do usuário (pt-BR:
 * "1.234,56"; en: "1,234.56") ↔ número com 2 casas. Separadores vêm do Intl,
 * nunca fixos no código.
 */

const separatorsCache = new Map();

/** Separadores de milhar e decimal do idioma (ex.: pt-BR → { group: '.', decimal: ',' }). */
export function localeSeparators(locale) {
    if (!separatorsCache.has(locale)) {
        const parts = new Intl.NumberFormat(locale).formatToParts(1234567.8);

        separatorsCache.set(locale, {
            group:   parts.find((p) => p.type === 'group')?.value ?? '',
            decimal: parts.find((p) => p.type === 'decimal')?.value ?? '.',
        });
    }

    return separatorsCache.get(locale);
}

/** Símbolo da moeda no idioma (pt-BR + BRL → "R$"). */
export function currencySymbol(locale, currency = 'BRL') {
    const parts = new Intl.NumberFormat(locale, { style: 'currency', currency }).formatToParts(0);

    return parts.find((p) => p.type === 'currency')?.value ?? currency;
}

/** Número → texto para o campo (sem símbolo), sempre com 2 casas: 1234.5 → "1.234,50". */
export function formatMoneyInput(value, locale) {
    if (value === null || value === undefined || value === '') return '';

    const number = Number(value);
    if (!Number.isFinite(number)) return '';

    return new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(number);
}

function digitsOnly(text) {
    return text.replace(/\D/g, '');
}

/**
 * Texto digitado → número com 2 casas, ou null (vazio/ilegível).
 *
 * - Com o separador decimal do idioma: tudo antes dele é a parte inteira
 *   (milhares removidos): pt-BR "1.234,5" → 1234.5.
 * - Só com o separador de milhar: seguido de exatamente 3 dígitos é milhar
 *   ("1.234" → 1234); de 1–2 dígitos é tratado como decimal digitado com o
 *   separador "errado" ("10.5" → 10.5), comum no balcão.
 * - Símbolo da moeda, espaços e letras são ignorados; sinal só com allowNegative.
 */
export function parseMoneyInput(text, locale, { allowNegative = false } = {}) {
    if (text === null || text === undefined) return null;

    let raw = String(text).trim();
    if (raw === '') return null;

    const negative = allowNegative && /^\s*-|-\s*$|^\(.*\)$/.test(raw);
    const { group, decimal } = localeSeparators(locale);

    raw = raw.replace(/[^\d.,]/g, '');
    if (!/\d/.test(raw)) return null;

    let integer;
    let fraction = '';

    const decimalAt = raw.lastIndexOf(decimal);
    const groupAt   = group ? raw.lastIndexOf(group) : -1;

    if (decimalAt >= 0 && decimalAt > groupAt) {
        integer  = digitsOnly(raw.slice(0, decimalAt));
        fraction = digitsOnly(raw.slice(decimalAt + 1));
    } else if (groupAt >= 0) {
        const tail = raw.slice(groupAt + 1);

        if (/^\d{3}$/.test(tail)) {
            integer = digitsOnly(raw);
        } else {
            integer  = digitsOnly(raw.slice(0, groupAt));
            fraction = digitsOnly(tail);
        }
    } else {
        integer = digitsOnly(raw);
    }

    const number = Number(`${integer || '0'}.${fraction || '0'}`);
    if (!Number.isFinite(number)) return null;

    const rounded = Math.round(number * 100) / 100;

    return negative ? -rounded : rounded;
}
