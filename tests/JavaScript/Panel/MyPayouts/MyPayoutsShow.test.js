import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import MyPayoutsShow from '@/Pages/Panel/MyPayouts/Show.vue';
import { t, statement, brl } from '../Financial/DoctorPayouts/fixtures.js';

/**
 * Demonstrativo do médico (só leitura): o mesmo StatementView da clínica —
 * grupos, ajustes e totais — sem ajustar, pagar, estornar ou reabrir; só PDF
 * e voltar para "Meus repasses".
 */

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    const pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {}, t_ui: { close: 'Close' } });

    return {
        usePage: () => ({ props: pageProps }),
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: { props: ['title'], template: '<div><h4>{{ title }}</h4><slot name="actions" /></div>' },
}));

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('MyPayouts/Show', () => {
    it('demonstrativo completo, só leitura, com PDF e voltar', () => {
        wrapper = mount(MyPayoutsShow, {
            props: { breadcrumbs: [], statement, routes: { index: '/my-payouts', pdf: '/my-payouts/po1/pdf' }, t },
        });
        const w = wrapper;

        expect(w.find('.layout-title').text()).toBe('Payout statement RM-000001');
        expect(w.find('[data-test="statement-code"]').text()).toBe('RM-000001');
        expect(w.findAll('[data-test="statement-group"]')).toHaveLength(2);
        expect(w.findAll('[data-test="adjustment-row"]')).toHaveLength(1);
        expect(w.find('[data-test="total-net"]').text()).toBe(brl(150));

        expect(w.find('[data-test="statement-pdf"]').attributes('href')).toBe('/my-payouts/po1/pdf');
        expect(w.find('[data-test="back-my-payouts"]').attributes('href')).toBe('/my-payouts');

        expect(w.find('[data-test="adjustment-form"]').exists()).toBe(false);
        expect(w.find('[data-test="adjustment-remove"]').exists()).toBe(false);
        expect(w.find('[data-test="payment-panel"]').exists()).toBe(false);
        expect(w.find('[data-test="admin-actions"]').exists()).toBe(false);
        expect(w.find('[data-test="my-payments"]').exists()).toBe(false);
    });

    it('pago em parte: cada pagamento (data, valor, forma) e o saldo a receber, sem estornar', () => {
        wrapper = mount(MyPayoutsShow, {
            props: {
                breadcrumbs: [],
                statement: {
                    ...statement,
                    payout: { ...statement.payout, status: 'partially_paid', paid_amount: 50, remaining_amount: 100 },
                    payments: [{ id: 'pay1', paid_at: '2026-09-05', amount: 50, payment_method: 'transfer' }],
                },
                routes: { index: '/my-payouts', pdf: '/my-payouts/po1/pdf' },
                t,
            },
        });
        const w = wrapper;

        const section = w.find('[data-test="my-payments"]');
        expect(section.find('h3').text()).toBe('Payments');
        expect(section.find('[data-test="my-balance"]').text()).toBe(`Balance to pay: ${brl(100)}`);

        const [row] = section.findAll('[data-test="payment-row"]');
        expect(row.find('[data-test="payment-row-amount"]').text()).toBe(brl(50));
        expect(row.text()).toContain('05/09/2026');
        expect(row.text()).toContain('Bank transfer');
        expect(row.find('[data-test="payment-row-reverse"]').exists()).toBe(false);
        expect(row.find('[data-test="payment-row-cash-flow"]').exists()).toBe(false);
    });
});
