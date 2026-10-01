import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Formatação de moeda/número/data no idioma do usuário (prop Inertia
 * `locale`, ex.: 'pt_BR' → 'pt-BR'), em vez de `R$ ${v.toFixed(2)}` fixo.
 *
 * Valores nulos/vazios/não numéricos viram '—' (mesmo placeholder das tabelas).
 * A moeda padrão é BRL (clínicas no Brasil); passe outra quando o dado tiver.
 *
 * Uso:
 *   const { money, number, date } = useLocaleFormat();
 *   money(1234.5)          // "R$ 1.234,50" (pt-BR)
 *   number(3.5, 1)         // "3,5"
 *   date('2026-09-26')     // "26/09/2026"
 *   quantity(2.5)          // "2,5" (0 a 3 casas, sem zeros à direita)
 *   dateTime('2026-09-26T14:30:00') // "26/09/2026 14:30"
 */
export function useLocaleFormat() {
    const page = usePage();

    const locale = computed(() => String(page?.props?.locale ?? 'pt_BR').replace('_', '-'));

    function isBlank(value) {
        return value === null || value === undefined || value === '' || Number.isNaN(Number(value));
    }

    function money(value, currency = 'BRL') {
        if (isBlank(value)) return '—';

        return new Intl.NumberFormat(locale.value, { style: 'currency', currency }).format(Number(value));
    }

    function number(value, fractionDigits = 0) {
        if (isBlank(value)) return '—';

        return new Intl.NumberFormat(locale.value, {
            minimumFractionDigits: fractionDigits,
            maximumFractionDigits: fractionDigits,
        }).format(Number(value));
    }

    /** Moeda com sinal explícito (+R$ 10,00 / −R$ 5,00), para receitas × despesas sem depender só de cor. */
    function signedMoney(value, currency = 'BRL') {
        if (isBlank(value)) return '—';

        return new Intl.NumberFormat(locale.value, { style: 'currency', currency, signDisplay: 'exceptZero' }).format(
            Number(value),
        );
    }

    /** Quantidade de estoque: até `maxDigits` casas, sem zeros à direita (2 → "2", 2.5 → "2,5"). */
    function quantity(value, maxDigits = 3) {
        if (isBlank(value)) return '—';

        return new Intl.NumberFormat(locale.value, {
            minimumFractionDigits: 0,
            maximumFractionDigits: maxDigits,
        }).format(Number(value));
    }

    /** Data/hora ISO → data curta + hora local. Texto não ISO volta como veio. */
    function dateTime(value) {
        if (!value) return '—';

        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) return String(value);

        return new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(parsed);
    }

    /** Data ISO (YYYY-MM-DD ou datetime) → data curta local; sem fuso para datas puras. */
    function date(value) {
        if (!value) return '—';

        const onlyDate = /^\d{4}-\d{2}-\d{2}$/.test(String(value));
        const parsed = onlyDate ? new Date(`${value}T00:00:00`) : new Date(value);
        if (Number.isNaN(parsed.getTime())) return String(value);

        return new Intl.DateTimeFormat(locale.value, { dateStyle: 'short' }).format(parsed);
    }

    return { locale, money, signedMoney, number, quantity, date, dateTime };
}
