import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Formatação da contagem de estoque — compartilhada por Index, CountTable e
 * CountCards. Quantidades vêm do useLocaleFormat compartilhado (idioma do
 * usuário, até 3 casas — decimal:3); a unidade vem traduzida do backend
 * (`unit_label`, lang/{locale}/stock_enums.php).
 */
const BADGE = 'badge rounded fs-13 fw-medium border';

/** @param {() => object} getT textos traduzidos (prop `t`) */
export function useCountFormat(getT) {
    const { quantity } = useLocaleFormat();

    function signedQuantity(delta) {
        if (delta === null || delta === undefined) return '—';

        return `${delta > 0 ? '+' : delta < 0 ? '−' : ''}${quantity(Math.abs(delta))}`;
    }

    function unitLabel(product) {
        return product.unit_label ?? product.unit ?? '';
    }

    /**
     * Diferença contado − sistema como status: confere (verde), sobra (azul),
     * falta (vermelho); `null` = ainda não contado (sem badge).
     */
    function differenceBadge(delta) {
        if (delta === null || delta === undefined) return null;
        if (delta === 0) {
            return {
                text: getT()?.difference_match ?? 'Confere',
                class: `${BADGE} badge-soft-success text-success border-success`,
            };
        }

        const variant = delta > 0 ? 'info' : 'danger';

        return {
            text: signedQuantity(delta),
            class: `${BADGE} badge-soft-${variant} text-${variant} border-${variant}`,
        };
    }

    return { quantity, signedQuantity, unitLabel, differenceBadge };
}
