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
    awaiting:  'badge-soft-secondary border border-secondary',
    closed:    'badge-soft-info border border-info',
    partially_paid: 'badge-soft-primary border border-primary',
    paid:      'badge-soft-success border border-success',
    cancelled: 'badge-soft-secondary border border-secondary',
};

const STATUS_ICON = {
    pending:   'ti ti-clock',
    awaiting:  'ti ti-hourglass',
    closed:    'ti ti-lock',
    partially_paid: 'ti ti-progress-check',
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
    split_charge:     'ti ti-arrows-split-2',
    negative_adjustment: 'ti ti-arrow-back-up',
    act_removed:      'ti ti-user-x',
    late_receipt:     'ti ti-clock-exclamation',
    multiple_exam_types: 'ti ti-stack-2',
    procedure_cancelled: 'ti ti-circle-x',
};

/** Alertas que mexem no valor (bloqueio ou desconto) em vermelho; os demais pedem conferência. */
const DANGER_WARNINGS = ['no_rule', 'negative_adjustment', 'act_removed'];

const BASE_SOURCE_BADGE = {
    received: 'badge-soft-success border border-success',
    charged: 'badge-soft-secondary border',
    table:   'badge-soft-info border border-info',
    none:    'badge-soft-warning border border-warning',
};

/** Tipos de dedução antes de dividir (E4), na ordem da tela. */
export const DEDUCTION_KINDS = ['card_debit', 'card_credit', 'tax', 'admin'];

/** Recebimento da clínica (rastreio somente leitura), na ordem do filtro. */
export const RECEIPT_STATUSES = ['to_bill', 'awaiting', 'partial', 'received', 'denied', 'unconfirmed', 'no_charge', 'not_linked'];

const RECEIPT_BADGE = {
    not_linked:  'badge-soft-secondary border',
    no_charge:   'badge-soft-secondary border',
    to_bill:     'badge-soft-warning border border-warning',
    awaiting:    'badge-soft-warning border border-warning',
    partial:     'badge-soft-info border border-info',
    received:    'badge-soft-success border border-success',
    denied:      'badge-soft-danger border border-danger',
    unconfirmed: 'badge-soft-danger border border-danger',
};

const RECEIPT_ICON = {
    not_linked:  'ti ti-link-off',
    no_charge:   'ti ti-receipt-off',
    to_bill:     'ti ti-file-invoice',
    awaiting:    'ti ti-hourglass',
    partial:     'ti ti-progress',
    received:    'ti ti-circle-check',
    denied:      'ti ti-ban',
    unconfirmed: 'ti ti-alert-triangle',
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
    /** "Sem regra" bloqueia e ajustes para menos/estornos descontam (perigo); os demais pedem conferência. */
    const warningTone  = (code) => (DANGER_WARNINGS.includes(code) ? 'danger' : 'warning');

    const baseSourceLabel = (source) => text().base_sources?.[source] ?? source;
    const baseSourceHint  = (source) => text().base_source_hints?.[source] ?? '';
    const baseSourceBadge = (source) => BASE_SOURCE_BADGE[source] ?? FALLBACK_BADGE;

    const receiptStatusLabel = (status) => text().receipt_statuses?.[status] ?? status;
    const receiptStatusHint  = (status) => text().receipt_status_hints?.[status] ?? '';
    const receiptStatusBadge = (status) => RECEIPT_BADGE[status] ?? FALLBACK_BADGE;
    const receiptStatusIcon  = (status) => RECEIPT_ICON[status] ?? 'ti ti-point';

    /**
     * Regra aplicada ao item ({ calculation, percentage, fixed }) ou de uma
     * linha de regra (fixed_amount): "60% do recebido líquido" (regime por
     * recebimento), "60% do valor cobrado" (item do regime anterior,
     * basis = production), "R$ 80,00 fixo".
     */
    function ruleLabel(rule, basis = null) {
        if (!rule?.calculation) return text().rule_none;

        if (rule.calculation === 'percentage') {
            return tx(basis === 'production' ? 'rule_percentage_production' : 'rule_percentage', { value: quantity(rule.percentage, 2) });
        }

        return tx('rule_fixed', { value: money(rule.fixed ?? rule.fixed_amount) });
    }

    /** Texto no singular/plural pelas chaves `<key>_one` / `<key>_other` (useTrans não pluraliza). */
    function countText(key, count, params = {}) {
        const value = Number(count ?? 0);

        return tx(`${key}_${value === 1 ? 'one' : 'other'}`, { ...params, count: number(value) });
    }

    /** "Cartão R$ 3,00 · Imposto R$ 7,00" — deduções acumuladas do atendimento por tipo. */
    function deductionsBreakdownText(breakdown) {
        if (!breakdown) return '';

        return ['card', 'tax', 'admin']
            .filter((kind) => Number(breakdown[kind] ?? 0) > 0)
            .map((kind) => `${text().deduction_parts?.[kind] ?? kind} ${money(breakdown[kind])}`)
            .join(' · ');
    }

    const deductionKindLabel   = (kind) => text().deduction_kinds?.[kind] ?? kind;
    const beneficiaryRoleLabel = (role) => text().beneficiary_roles?.[role] ?? role;

    /**
     * Resumo da divisão de uma regra percentual (E4): "Grupo 60% · Clínica 40%"
     * e os participantes ("Dra. Ana 60% · Executor 40%"). Vazio sem participantes.
     */
    function splitSummary(rule, doctors = []) {
        if (rule?.calculation !== 'percentage' || !(rule.participants ?? []).length) return '';

        const names = new Map(doctors.map((doctor) => [doctor.id, doctor.name]));
        const parts = rule.participants.map((participant) => {
            const who = participant.role === 'executor' ? text().split_executor : (names.get(participant.doctor_id) ?? text().split_doctor);

            return `${who} ${quantity(participant.percentage, 2)}%`;
        });

        return `${tx('split_clinic_keeps', { value: quantity(100 - Number(rule.percentage ?? 0), 2) })} ${parts.join(' · ')}`;
    }

    /** Rótulo da base do fechamento: recebido (regime atual) ou cobrado (regime anterior). */
    const grossLabel = (payout) => (payout?.basis === 'receipt' ? text().statement_gross_receipt : text().statement_gross);

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
        quantity,
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
        receiptStatusLabel,
        receiptStatusHint,
        receiptStatusBadge,
        receiptStatusIcon,
        ruleLabel,
        countText,
        deductionsBreakdownText,
        grossLabel,
        deductionKindLabel,
        beneficiaryRoleLabel,
        splitSummary,
        periodText,
        doctorLabel,
        validityLabel,
    };
}
