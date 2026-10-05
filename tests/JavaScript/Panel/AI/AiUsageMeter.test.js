import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AiIndex from '@/Pages/Panel/AI/Index.vue';

/**
 * Painel da IA (/panel/ai/usage) — medidor da carteira:
 *  - números no idioma do usuário (1.500, não 1500);
 *  - cliente em atraso: a renovação da franquia aparece condicionada ao
 *    pagamento (renews_if_paid_on), sem prometer "Renova em" seco;
 *  - paywall vindo das props é status (não alerta) para o leitor de tela.
 */
const labels = {
    credits_available: 'Créditos disponíveis',
    credits_reserved: 'Reservados',
    credits_total: 'Total',
    quota_title: 'Franquia mensal de IA',
    quota_credits: ':used de :quota créditos',
    quota_renews_on: 'Renova em :date',
    quota_renews_if_paid: 'Renova em :date se o pagamento em atraso for confirmado',
    quota_expires_on: 'Vale até :date',
    quota_spillover:
        'Franquia do mês esgotada. As próximas execuções usam seus créditos avulsos (saldo avulso: :available).',
};

let wrapper;

afterEach(() => wrapper?.unmount());

function render({ balance, quota, paywall = null }) {
    wrapper = mount(AiIndex, {
        props: {
            balance,
            paywall,
            runs: { data: [], links: [] },
            analytics: { period: { label: 'outubro', start: '01/10/2026', end: '31/10/2026' }, quota },
            labels,
        },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                PageHeader: true,
                SearchSelect: true,
            },
        },
    });

    return wrapper;
}

describe('painel da IA — medidor', () => {
    it('saldo e franquia com os números no idioma', () => {
        render({
            balance: { available: 1500, reserved: 1200, total: 2700, balance: 1500 },
            quota: { monthly_quota: 2000, consumed_credits: 2000, usage_percent: 100, renews_on: '2026-11-04' },
        });

        expect(wrapper.get('[data-test="ai-balance-available"]').text()).toBe('1.500');
        expect(wrapper.get('[data-test="ai-quota-text"]').text()).toContain('2.000 de 2.000 créditos');
        expect(wrapper.get('[data-test="ai-quota-spillover"]').text()).toContain('(saldo avulso: 1.500)');
    });

    it('cliente em atraso: renovação condicionada ao pagamento', () => {
        render({
            balance: { available: 70, reserved: 0, total: 70, balance: 0 },
            quota: {
                monthly_quota: 80,
                consumed_credits: 10,
                usage_percent: 12.5,
                renews_on: null,
                renews_if_paid_on: '2026-11-19',
            },
        });

        expect(wrapper.get('[data-test="ai-quota-text"]').text()).toContain(
            'Renova em 19/11/2026 se o pagamento em atraso for confirmado',
        );
    });

    it('paywall das props: status, não alerta', () => {
        render({
            balance: { available: 0, reserved: 0, total: 0, balance: 0 },
            quota: { monthly_quota: 0 },
            paywall: { reason: 'complimentary', can_purchase: false, texts: { message: 'A cortesia não inclui.' } },
        });

        expect(wrapper.get('[data-test="ai-paywall"]').attributes('role')).toBe('status');
    });
});
