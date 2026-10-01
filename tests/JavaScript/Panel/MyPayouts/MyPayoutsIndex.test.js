import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import MyPayoutsIndex from '@/Pages/Panel/MyPayouts/Index.vue';
import { t, paginator, brl } from '../Financial/DoctorPayouts/fixtures.js';

/**
 * "Meus repasses" (médico): só fechamentos próprios — período, código,
 * produção (valor + atos), repasse, situação ("Pago em…" / "Aguardando
 * pagamento"), demonstrativo e PDF; estado vazio sem nenhuma lista.
 */

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    const pageProps = reactive({ locale: 'pt_BR', flash: {}, errors: {}, t_ui: { close: 'Close' } });

    return {
        usePage: () => ({ props: pageProps }),
        router: { get: vi.fn() },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'totalLabel'],
        template: '<div><span class="total">{{ totalLabel }} {{ total }}</span></div>',
    },
}));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({
    default: { props: ['data'], template: '<nav class="pagination-stub" />' },
}));

const routes = { index: '/my-payouts', show: '/my-payouts/__ID__', pdf: '/my-payouts/__ID__/pdf' };

const PAYOUTS = [
    {
        id: 'po2',
        code: 'RM-000002',
        period_start: '2026-09-01',
        period_end: '2026-09-30',
        items_count: 14,
        gross_amount: 4200,
        total_amount: 2520,
        status: 'closed',
        paid_at: null,
    },
    {
        id: 'po1',
        code: 'RM-000001',
        period_start: '2026-08-01',
        period_end: '2026-08-31',
        items_count: 1,
        gross_amount: 300,
        total_amount: 180,
        status: 'paid',
        paid_at: '2026-09-05',
    },
];

let wrapper;

function mountPage(props = {}) {
    wrapper = mount(MyPayoutsIndex, {
        props: { breadcrumbs: [], payouts: paginator(PAYOUTS), routes, t, ...props },
    });

    return wrapper;
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('MyPayouts/Index', () => {
    it('título, total e introdução', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('My payouts');
        expect(w.find('.total').text()).toBe('Total: 2');
        expect(w.find('[data-test="my-intro"]').text()).toBe('Payout closings made by the clinic.');
    });

    it('linhas: período, código, produção, repasse e situação', () => {
        const w = mountPage();

        const [awaiting, paid] = w.findAll('[data-test="my-row"]');
        expect(awaiting.text()).toContain('01/09/2026 – 30/09/2026');
        expect(awaiting.text()).toContain('RM-000002');
        expect(awaiting.text()).toContain(brl(4200));
        expect(awaiting.text()).toContain('14 acts in the period');
        expect(awaiting.text()).toContain(brl(2520));
        expect(awaiting.find('[data-test="my-status"]').text()).toBe('Awaiting payment');

        expect(paid.find('[data-test="my-status"]').text()).toBe('Paid on 05/09/2026');
        expect(paid.find('[data-test="my-view"]').attributes('href')).toBe('/my-payouts/po1');
        expect(paid.find('[data-test="my-pdf"]').attributes('href')).toBe('/my-payouts/po1/pdf');
        expect(paid.find('[data-test="my-pdf"]').attributes('aria-label')).toBe('Download PDF: RM-000001');
    });

    it('pago em parte: quanto já recebeu do total', () => {
        const w = mountPage({
            payouts: paginator([{ ...PAYOUTS[0], status: 'partially_paid', paid_at: '2026-09-05', paid_amount: 1000 }]),
        });

        const status = w.find('[data-test="my-row"] [data-test="my-status"]');
        expect(status.text()).toBe(`Partly paid: ${brl(1000)} of ${brl(2520)}`);
        expect(status.classes()).toContain('badge-soft-primary');
    });

    it('celular: os mesmos fechamentos em cards', () => {
        const w = mountPage();

        const cards = w.findAll('[data-test="my-card"]');
        expect(cards).toHaveLength(2);
        expect(cards[0].text()).toContain('Awaiting payment');
        expect(cards[1].text()).toContain('Paid on 05/09/2026');
    });

    it('sem fechamentos: mensagem de vazio, sem tabela nem cards', () => {
        const w = mountPage({ payouts: paginator([]) });

        expect(w.find('[data-test="my-empty"]').text()).toBe('No payout closed yet.');
        expect(w.find('[data-test="my-row"]').exists()).toBe(false);
        expect(w.find('[data-test="my-card"]').exists()).toBe(false);
    });

    it('coluna "Produção" com o que ela é (recebido no regime atual) e o singular de 1 ato', () => {
        const w = mountPage({
            payouts: paginator([
                { ...PAYOUTS[0], basis: 'receipt' },
                { ...PAYOUTS[1], basis: 'production' },
            ]),
        });

        expect(w.find('thead').text()).toContain('Production');
        const [receipt, production] = w.findAll('[data-test="my-production-hint"]');
        expect(receipt.text()).toBe('Received (installments base) · 14 acts in the period');
        expect(production.text()).toBe('Charged amount (production) · 1 act in the period');
        expect(w.find('[data-test="my-card-production"]').text()).toContain('Production:');
    });
});
