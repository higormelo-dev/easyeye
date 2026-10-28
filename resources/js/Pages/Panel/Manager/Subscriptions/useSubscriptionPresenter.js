import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { choice } from '@/utils/billingPeriods.js';

/**
 * Textos de uma assinatura na lista, nos cards e no drawer — valor por
 * ciclo, equivalente mensal, quanto falta para acabar e por que uma ação
 * está indisponível. Moeda e datas no idioma do usuário.
 *
 * @param {() => object} getT          traduções (props.t)
 * @param {() => Array}  getCycles     ciclos [{ value, period_label, months }]
 */
export function useSubscriptionPresenter(getT, getCycles) {
    const { money } = useLocaleFormat();
    const { tx } = useTrans(getT);

    function cycleOf(row) {
        return (getCycles() ?? []).find((c) => c.value === row?.billing_cycle) ?? null;
    }

    /** "R$ 2.878,99/ano" ou "Sem cobrança". */
    function amountText(row) {
        if (row?.amount === null || row?.amount === undefined) return getT().amount_none;

        return tx('amount_per_cycle', { amount: money(row.amount), period: cycleOf(row)?.period_label ?? '' });
    }

    /** "≈ R$ 239,92/mês" para ciclos maiores que um mês. */
    function monthlyText(row) {
        const cycle = cycleOf(row);
        if (row?.amount === null || row?.amount === undefined || !cycle || cycle.months <= 1) return '';

        return tx('amount_monthly_equivalent', { amount: money(row.amount / cycle.months) });
    }

    /** "Faltam 12 dias" / "Termina hoje" / "Venceu há 3 dias"; vazio sem término. */
    function accessText(row) {
        if (!row || row.open_ended || row.days_left === null || row.days_left === undefined) return '';

        const t = getT();
        const days = row.days_left;

        if (days === 0) return t.days_left_today;
        if (days > 0) return choice(t.days_left, days, { days });

        return choice(t.days_overdue, Math.abs(days), { days: Math.abs(days) });
    }

    /** "Em atraso há 4 dias · Acesso limitado" (régua de cobrança); vazio fora dela. */
    function dunningText(row) {
        if (!row || row.days_overdue === null || row.days_overdue === undefined) return '';

        const t = getT();
        const days = Number(row.days_overdue);
        const overdue = days > 0 ? choice(t.overdue_for, days, { days }) : t.overdue_today;

        return row.dunning_stage_label ? `${overdue} · ${row.dunning_stage_label}` : overdue;
    }

    function extendDisabledReason(row) {
        if (!row || row.can_extend) return '';

        const t = getT();
        if (!row.is_current) return t.not_current_hint;
        // Cobrança automática: o período acompanha os pagamentos.
        if (row.billing_mode === 'gateway' && row.status !== 'cancelled') return t.extend_disabled_gateway;
        if (row.open_ended) return t.extend_disabled_no_end;

        return t.extend_disabled_status;
    }

    function changeDisabledReason(row) {
        if (!row || row.can_change_terms) return '';

        const t = getT();

        return row.is_current ? t.change_disabled_status : t.not_current_hint;
    }

    return { amountText, monthlyText, accessText, dunningText, extendDisabledReason, changeDisabledReason };
}
