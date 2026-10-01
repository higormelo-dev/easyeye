import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Formatação de uma linha do extrato de estoque — compartilhada por
 * MovementTable e MovementCards (mesmo dado, dois layouts). Números e datas
 * vêm do useLocaleFormat compartilhado (idioma do usuário); o rótulo do tipo
 * vem traduzido do backend (`type_label`, lang/{locale}/stock_enums.php).
 *
 * Cor do badge por tipo (App\Enums\StockMovementType): entradas em verde,
 * ajustes em azul/amarelo, perda em vermelho, demais saídas neutras. A seta
 * acompanha a cor para não depender só dela (acessibilidade).
 */
const TYPE_VARIANTS = {
    purchase_in: 'success',
    manual_in: 'success',
    adjustment_in: 'info',
    consumption_out: 'primary',
    manual_out: 'secondary',
    adjustment_out: 'warning',
    loss: 'danger',
    return_out: 'secondary',
};

export function useMovementFormat() {
    const { money, quantity, dateTime } = useLocaleFormat();

    function signedQuantity(movement) {
        const formatted = quantity(movement.quantity);
        if (formatted === '—') return formatted;

        return `${movement.direction === 1 ? '+' : '−'}${formatted}`;
    }

    function typeLabel(movement) {
        return movement.type_label ?? movement.type;
    }

    function typeBadgeClass(type) {
        const variant = TYPE_VARIANTS[type] ?? 'secondary';

        return `badge badge-soft-${variant} rounded text-${variant} border border-${variant} fs-13 fw-medium`;
    }

    function directionIcon(movement) {
        return movement.direction === 1 ? 'ti ti-arrow-up' : 'ti ti-arrow-down';
    }

    return { money, quantity, dateTime, signedQuantity, typeLabel, typeBadgeClass, directionIcon };
}
