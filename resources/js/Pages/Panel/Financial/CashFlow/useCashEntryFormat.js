import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Rótulos, cores (badge-soft: seguras no tema escuro) e valores com sinal dos
 * lançamentos de caixa — compartilhado por tabela, cards e confirmação de
 * exclusão do Fluxo de Caixa. Textos vêm de `t` (lang/{locale}/financial_cash_flow.php).
 */
const TYPE_BADGE = {
    income:  'badge-soft-success border border-success',
    expense: 'badge-soft-danger border border-danger',
};

const STATUS_BADGE = {
    paid:      'badge-soft-success border border-success',
    pending:   'badge-soft-warning border border-warning',
    cancelled: 'badge-soft-secondary border border-secondary',
};

const ORIGIN_ICON = {
    schedule: 'ti ti-calendar-event',
    claim:    'ti ti-file-invoice',
    purchase: 'ti ti-shopping-cart',
    doctor_payout: 'ti ti-stethoscope',
    manual:   'ti ti-pencil',
};

const FALLBACK_BADGE = 'badge-soft-secondary border';

/** @param {() => object} getT getter das traduções (reativo a troca de idioma) */
export function useCashEntryFormat(getT) {
    const { money, signedMoney, date } = useLocaleFormat();

    const text = () => getT() ?? {};

    const typeLabel   = (type) => text().types?.[type] ?? type;
    const statusLabel = (status) => text().statuses?.[status] ?? status;
    const originLabel = (origin) => text().origins?.[origin] ?? origin;

    const typeBadge   = (type) => TYPE_BADGE[type] ?? FALLBACK_BADGE;
    const statusBadge = (status) => STATUS_BADGE[status] ?? FALLBACK_BADGE;
    const originIcon  = (origin) => ORIGIN_ICON[origin] ?? ORIGIN_ICON.manual;

    /** Receita com "+", despesa com "−": a diferença não depende só da cor. */
    function entryAmount(entry) {
        const amount = Math.abs(Number(entry?.amount ?? 0));

        return signedMoney(entry?.type === 'expense' ? -amount : amount);
    }

    function lockLabel(entry) {
        if (entry?.lock_reason === 'billing_claim') return text().lock_billing_claim;
        if (entry?.lock_reason === 'doctor_payout') return text().lock_doctor_payout;
        if (entry?.lock_reason === 'closed_period') return text().lock_closed_period;

        return '';
    }

    function lockHint(entry) {
        if (entry?.lock_reason === 'billing_claim') return text().lock_billing_claim_hint;
        if (entry?.lock_reason === 'doctor_payout') return text().lock_doctor_payout_hint;
        if (entry?.lock_reason === 'closed_period') return text().lock_closed_period_hint;

        return '';
    }

    return {
        money,
        signedMoney,
        date,
        typeLabel,
        statusLabel,
        originLabel,
        typeBadge,
        statusBadge,
        originIcon,
        entryAmount,
        lockLabel,
        lockHint,
    };
}
