import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans';

/**
 * Rótulos e formatos do Repasse médico, iguais na apuração, nos fechamentos,
 * nas regras, no demonstrativo e em "Meus repasses". Textos vêm de `t`
 * (lang/{locale}/financial_doctor_payouts.php); cores em badge-soft (seguras
 * no tema escuro) e sempre acompanhadas de texto — a cor nunca é o único sinal.
 */
const STATUS_BADGE = {
    pending:   'badge-soft-warning border border-warning',
    closed:    'badge-soft-info border border-info',
    paid:      'badge-soft-success border border-success',
    cancelled: 'badge-soft-secondary border border-secondary',
};

const STATUS_ICON = {
    pending:   'ti ti-clock',
    closed:    'ti ti-lock',
    paid:      'ti ti-circle-check',
    cancelled: 'ti ti-circle-x',
};

const SERVICE_ICON = {
    consultation: 'ti ti-stethoscope',
    exam:         'ti ti-eye',
    procedure:    'ti ti-first-aid-kit',
};

const WARNING_ICON = {
    no_rule:          'ti ti-alert-octagon',
    no_base_value:    'ti ti-currency-real',
    charge_cancelled: 'ti ti-receipt-off',
    doctor_mismatch:  'ti ti-user-exclamation',
    shared_charge:    'ti ti-arrows-split',
    late_item:        'ti ti-clock-exclamation',
};

const BASE_SOURCE_BADGE = {
    charged: 'badge-soft-secondary border',
    table:   'badge-soft-info border border-info',
    none:    'badge-soft-warning border border-warning',
};

const FALLBACK_BADGE = 'badge-soft-secondary border';

/** @param {() => object} getT getter das traduções (prop `t` da página) */
export function useDoctorPayoutFormat(getT) {
    const { tx } = useTrans(() => getT() ?? {});
    const { money, signedMoney, number, quantity, date, dateTime } = useLocaleFormat();

    const text = () => getT() ?? {};

    const statusLabel = (status) => text().statuses?.[status] ?? status;
    const statusBadge = (status) => STATUS_BADGE[status] ?? FALLBACK_BADGE;
    const statusIcon  = (status) => STATUS_ICON[status] ?? 'ti ti-point';

    const serviceTypeLabel  = (type) => text().service_types?.[type] ?? type;
    const serviceTypePlural = (type) => text().service_types_plural?.[type] ?? serviceTypeLabel(type);
    const serviceTypeIcon   = (type) => SERVICE_ICON[type] ?? 'ti ti-point';

    /** Pagador do item: nome do convênio ou "Particular". */
    const payerLabel = (row) => row?.covenant_name || text().particular;

    const warningLabel = (code) => text().warnings?.[code] ?? code;
    const warningHint  = (code) => text().warning_hints?.[code] ?? '';
    const warningIcon  = (code) => WARNING_ICON[code] ?? 'ti ti-alert-triangle';
    /** "Sem regra" bloqueia o fechamento (perigo); os demais pedem conferência. */
    const warningTone  = (code) => (code === 'no_rule' ? 'danger' : 'warning');

    const baseSourceLabel = (source) => text().base_sources?.[source] ?? source;
    const baseSourceHint  = (source) => text().base_source_hints?.[source] ?? '';
    const baseSourceBadge = (source) => BASE_SOURCE_BADGE[source] ?? FALLBACK_BADGE;

    /**
     * Regra aplicada ao item ({ calculation, percentage, fixed }) ou de uma
     * linha de regra (fixed_amount): "60% do valor cobrado", "R$ 80,00 fixo".
     */
    function ruleLabel(rule) {
        if (!rule?.calculation) return text().rule_none;

        return rule.calculation === 'percentage'
            ? tx('rule_percentage', { value: quantity(rule.percentage, 2) })
            : tx('rule_fixed', { value: money(rule.fixed ?? rule.fixed_amount) });
    }

    function periodText(start, end) {
        return `${date(start)} – ${date(end)}`;
    }

    /** Médico inativo/que saiu continua na lista (último repasse), com o aviso. */
    function doctorLabel(doctor) {
        if (!doctor) return '';

        return doctor.active === false ? `${doctor.name} (${text().inactive_doctor})` : doctor.name;
    }

    /** Vigência da regra: sempre / a partir de / até / de–até. */
    function validityLabel(from, until) {
        if (from && until) return tx('validity_range', { from: date(from), until: date(until) });
        if (from) return tx('validity_from', { date: date(from) });
        if (until) return tx('validity_until', { date: date(until) });

        return text().validity_always;
    }

    return {
        tx,
        money,
        signedMoney,
        number,
        date,
        dateTime,
        statusLabel,
        statusBadge,
        statusIcon,
        serviceTypeLabel,
        serviceTypePlural,
        serviceTypeIcon,
        payerLabel,
        warningLabel,
        warningHint,
        warningIcon,
        warningTone,
        baseSourceLabel,
        baseSourceHint,
        baseSourceBadge,
        ruleLabel,
        periodText,
        doctorLabel,
        validityLabel,
    };
}
