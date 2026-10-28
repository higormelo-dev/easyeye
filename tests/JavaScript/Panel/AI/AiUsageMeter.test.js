import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AiIndex from '@/Pages/Panel/AI/Index.vue';

/**
 * Painel da IA (/panel/ai/usage) — medidor da carteira:
 *  - números no idioma do usuário (1.500, não 1500);
 *  - cliente em atraso: a renovação da franquia aparece condicionada ao
 *    pagamento (renews_if_paid_on), sem prometer "Renova em" seco;
 *  - paywall vindo das props é status (não alerta) para o leitor de tela.
 */
const labels = {
    credits_available: 'Saldo de IA disponível',
    details: 'Detalhes',
    balance_consumed: 'Saldo consumido',
    credits_reserved: 'Reservados',
    credits_total: 'Total',
    quota_title: 'Franquia mensal de IA',
    quota_credits: ':used de :quota',
    quota_renews_on: 'Renova em :date',
    quota_renews_if_paid: 'Renova em :date se o pagamento em atraso for confirmado',
    quota_expires_on: 'Vale até :date',
    quota_spillover:
        'Franquia do mês esgotada. As próximas execuções usam seus créditos avulsos (saldo avulso: :available).',
};

let wrapper;

afterEach(() => wrapper?.unmount());

function render({ balance, quota, paywall = null, analytics = {}, runs = [] }) {
    wrapper = mount(AiIndex, {
        props: {
            balance,
            paywall,
            runs: { data: runs, links: [] },
            analytics: { period: { label: 'outubro', start: '01/10/2026', end: '31/10/2026' }, quota, ...analytics },
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
            balance: { available_cents: 1500, reserved_cents: 1200, total_cents: 2700, balance_cents: 1500 },
            quota: {
                monthly_quota_cents: 2000,
                monthly_quota_used_cents: 2000,
                usage_percent: 100,
                renews_on: '2026-11-04',
            },
        });

        expect(wrapper.get('[data-test="ai-balance-available"]').text()).toBe('R$\u00a015,00');
        expect(wrapper.get('[data-test="ai-quota-text"]').text()).toContain('R$\u00a020,00 de R$\u00a020,00');
        expect(wrapper.get('[data-test="ai-quota-spillover"]').text()).toContain('(saldo avulso: R$\u00a015,00)');
    });

    it('cliente em atraso: renovação condicionada ao pagamento', () => {
        render({
            balance: { available_cents: 70, reserved_cents: 0, total_cents: 70, balance_cents: 0 },
            quota: {
                monthly_quota_cents: 80,
                monthly_quota_used_cents: 10,
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
            balance: { available_cents: 0, reserved_cents: 0, total_cents: 0, balance_cents: 0 },
            quota: { monthly_quota_cents: 0 },
            paywall: { reason: 'complimentary', can_purchase: false, texts: { message: 'A cortesia não inclui.' } },
        });

        expect(wrapper.get('[data-test="ai-paywall"]').attributes('role')).toBe('status');
    });
    it('analytics e histórico só exibem cobrança BRL, mesmo com campos antigos/privados no fixture', async () => {
        render({
            balance: { available_cents: 12345, reserved_cents: 205, total_cents: 12550 },
            quota: {},
            analytics: {
                consumed: { charge_cents: 3210, runs: 2, credits: 987654 },
                avg_charge_cents_per_record: 1605,
                by_workflow: [
                    { workflow: 'record_assist', runs_count: 2, charge_cents_total: 3210, credits_total: 987654 },
                ],
                by_mode: [{ mode: 'validated', runs_count: 2, charge_cents_total: 3210 }],
                by_doctor: [
                    {
                        doctor_id: 'synthetic-doctor',
                        doctor_name: 'Médico de teste',
                        approved: 2,
                        avg_charge_cents: 1605,
                        avg_credits: 987654,
                    },
                ],
                top_runs: [{ id: 'top', consumed_charge_cents: 3210, consumed_credits: 987654, raw_cost_usd: 654321 }],
                raw_cost_usd: 654321,
                exchange_rate: 987654,
                margin_multiplier: 987654,
            },
            runs: [
                {
                    id: 'run',
                    consumed_charge_cents: 150,
                    reserved_charge_cents: 200,
                    consumed_credits: 987654,
                    raw_cost_usd: 654321,
                },
            ],
        });
        expect(wrapper.text()).toContain('R$\u00a032,10');
        expect(wrapper.text()).toContain('R$\u00a016,05');
        expect(wrapper.text()).toContain('R$\u00a01,50/R$\u00a02,00');
        expect(wrapper.text()).not.toMatch(/987654|654321|USD|markup|exchange_rate|custo do provedor/);
        window.axios = {
            get: vi.fn().mockResolvedValue({
                data: {
                    data: {
                        id: 'run',
                        consumed_charge_cents: 150,
                        reserved_charge_cents: 200,
                        raw_cost_usd: 654321,
                    },
                },
            }),
        };
        await wrapper.get('button[aria-label="Detalhes"]').trigger('click');
        await flushPromises();
        const detail = wrapper.get('[data-test="ai-run-charge"]');
        expect(detail.text()).toContain('R$\u00a01,50');
        expect(detail.text()).toContain('R$\u00a02,00');
        expect(wrapper.text()).not.toMatch(/654321|USD/);
    });
});
