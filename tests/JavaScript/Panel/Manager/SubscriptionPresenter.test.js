import { describe, expect, it } from 'vitest';
import { useSubscriptionPresenter } from '@/Pages/Panel/Manager/Subscriptions/useSubscriptionPresenter.js';

/**
 * Manager → Assinaturas: "em atraso há N dias" e a etapa da régua de
 * cobrança na linha, nos cards e no drawer.
 */
const t = {
    overdue_for: 'Em atraso há :days dia|Em atraso há :days dias',
    overdue_today: 'Em atraso desde hoje',
};

const { dunningText } = useSubscriptionPresenter(
    () => t,
    () => [],
);

describe('useSubscriptionPresenter.dunningText', () => {
    it('dias de atraso com a etapa da régua', () => {
        expect(dunningText({ days_overdue: 4, dunning_stage_label: 'Acesso limitado' })).toBe(
            'Em atraso há 4 dias · Acesso limitado',
        );
        expect(dunningText({ days_overdue: 1, dunning_stage_label: 'Atraso com aviso' })).toBe(
            'Em atraso há 1 dia · Atraso com aviso',
        );
        expect(dunningText({ days_overdue: 0, dunning_stage_label: null })).toBe('Em atraso desde hoje');
    });

    it('fora da régua não mostra nada', () => {
        expect(dunningText({ days_overdue: null })).toBe('');
        expect(dunningText(null)).toBe('');
    });
});

describe('useSubscriptionPresenter.extendDisabledReason', () => {
    const reasons = {
        not_current_hint: 'Histórico',
        extend_disabled_no_end: 'Sem término',
        extend_disabled_status: 'Cancelada',
        extend_disabled_gateway: 'Na cobrança automática o período acompanha os pagamentos.',
    };
    const { extendDisabledReason } = useSubscriptionPresenter(
        () => reasons,
        () => [],
    );

    it('cobrança automática explica que o período acompanha os pagamentos', () => {
        expect(
            extendDisabledReason({ can_extend: false, is_current: true, billing_mode: 'gateway', status: 'active' }),
        ).toBe(reasons.extend_disabled_gateway);
        expect(
            extendDisabledReason({ can_extend: false, is_current: true, billing_mode: 'gateway', status: 'past_due' }),
        ).toBe(reasons.extend_disabled_gateway);
    });

    it('cortesia e trial seguem os motivos de antes; liberado não mostra motivo', () => {
        expect(
            extendDisabledReason({
                can_extend: false,
                is_current: true,
                billing_mode: 'complimentary',
                open_ended: true,
            }),
        ).toBe(reasons.extend_disabled_no_end);
        expect(
            extendDisabledReason({ can_extend: false, is_current: true, billing_mode: 'gateway', status: 'cancelled' }),
        ).toBe(reasons.extend_disabled_status);
        expect(extendDisabledReason({ can_extend: false, is_current: false, billing_mode: 'gateway' })).toBe(
            reasons.not_current_hint,
        );
        expect(extendDisabledReason({ can_extend: true, billing_mode: 'complimentary' })).toBe('');
    });
});
