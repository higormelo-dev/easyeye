import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Preço de modelo de IA em USD por 1 milhão de tokens, no idioma da tela.
 * Até 4 casas: modelos baratos custam centavos por milhão (US$ 0,0375).
 */
export function usePriceFormat() {
    const { locale, number } = useLocaleFormat();

    function usd(value) {
        if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '—';

        return new Intl.NumberFormat(locale.value, {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 4,
        }).format(Number(value));
    }

    return { usd, number };
}
