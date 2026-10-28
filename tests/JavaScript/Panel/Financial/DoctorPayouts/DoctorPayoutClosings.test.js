import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import DoctorPayoutClosings from '@/Pages/Panel/Financial/DoctorPayouts/Closings.vue';
import { t, doctors, payoutSummary, paginator, brl } from './fixtures.js';

/**
 * Fechamentos de repasse: tabela/cards (mesmo paginator, preferência
 * persistida), selo "Complementar", demonstrativo e PDF por linha, filtros de
 * médico/status na URL e estado vazio.
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
        props: ['title', 'total', 'totalLabel', 'view', 'viewTableTitle', 'viewCardsTitle'],
        emits: ['set-view'],
        template: `<div><span class="total">{{ totalLabel }} {{ total }}</span>
            <button type="button" class="to-cards" :aria-label="viewCardsTitle" @click="$emit('set-view', 'cards')" /></div>`,
    },
}));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({
    default: { props: ['data'], template: '<nav class="pagination-stub" />' },
}));

const routes = {
    index: '/doctor-payouts/closings',
    show: '/doctor-payouts/closings/__ID__',
    pdf: '/doctor-payouts/closings/__ID__/pdf',
};

const PAYOUTS = [
    { ...payoutSummary, is_complementary: false },
    {
        ...payoutSummary,
        id: 'po2',
        code: 'RM-000002',
        status: 'paid',
        paid_at: '2026-09-05',
        total_amount: 90,
        items_count: 1,
        is_complementary: true,
    },
];

let wrapper;

function mountPage(props = {}) {
    wrapper = mount(DoctorPayoutClosings, {
        props: {
            breadcrumbs: [],
            tabs: { apuracao: '/doctor-payouts', closings: '/doctor-payouts/closings', rules: '/doctor-payouts/rules' },
            payouts: paginator(PAYOUTS),
            filters: { doctor: '', status: '' },
            options: { doctors, statuses: ['closed', 'paid', 'cancelled'] },
            routes,
            t,
            shared: {},
            ...props,
        },
    });

    return wrapper;
}

beforeEach(() => {
    window.localStorage.clear();
    vi.mocked(router.get).mockReset();
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('Financial/DoctorPayouts/Closings', () => {
    it('tabela: código, médico, período, itens, total, status, pagamento e ações', () => {
        const w = mountPage();

        expect(w.find('.total').text()).toBe('Total: 2');
        expect(w.find('[data-test="tab-closings"]').attributes('aria-current')).toBe('page');

        const [closed, paid] = w.findAll('[data-test="closing-row"]');
        expect(closed.text()).toContain('RM-000001');
        expect(closed.text()).toContain('Dra. Ana Lima');
        expect(closed.text()).toContain('License (CRM) 12345/SP');
        expect(closed.text()).toContain('01/08/2026 – 31/08/2026');
        expect(closed.text()).toContain(brl(150));
        expect(closed.find('[data-status="closed"]').text()).toBe('Closed');
        expect(closed.find('[data-test="complementary"]').exists()).toBe(false);

        expect(paid.find('[data-status="paid"]').text()).toBe('Paid');
        expect(paid.text()).toContain('05/09/2026');
        const complementary = paid.find('[data-test="complementary"]');
        expect(complementary.text()).toContain('Complementary');
        expect(complementary.attributes('title')).toBe('Another closing overlaps this period.');

        expect(paid.find('[data-test="closing-view"]').attributes('href')).toBe('/doctor-payouts/closings/po2');
        expect(paid.find('[data-test="closing-view"]').attributes('aria-label')).toBe('View statement: RM-000002');
        expect(paid.find('[data-test="closing-pdf"]').attributes('href')).toBe('/doctor-payouts/closings/po2/pdf');
    });

    it('alterna para cards (mesmos dados) e guarda a preferência', async () => {
        const w = mountPage();

        await w.find('.to-cards').trigger('click');

        const cards = w.findAll('[data-test="closing-card"]');
        expect(cards).toHaveLength(2);
        expect(cards[1].text()).toContain('RM-000002');
        expect(cards[1].find('[data-test="complementary"]').exists()).toBe(true);
        expect(cards[1].find('[data-test="closing-pdf"]').attributes('href')).toBe('/doctor-payouts/closings/po2/pdf');
        expect(window.localStorage.getItem('doctor_payout_closings_view')).toBe('cards');
    });

    it('filtros de médico e status visitam a URL mantendo o outro filtro', async () => {
        const w = mountPage({ filters: { doctor: '', status: 'paid' } });

        expect(w.findAll('[data-test="filter-doctor"] option').map((o) => o.text())).toEqual([
            'All doctors',
            'Dra. Ana Lima',
            'Dr. Beto Reis (inactive)',
        ]);
        expect(w.findAll('[data-test="filter-status"] option').map((o) => o.text())).toEqual([
            'All statuses',
            'Closed',
            'Paid',
            'Cancelled',
        ]);

        await w.find('[data-test="filter-doctor"]').setValue('d1');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts/closings',
            { doctor: 'd1', status: 'paid' },
            expect.objectContaining({ preserveState: true, preserveScroll: true }),
        );

        await w.find('[data-test="filters-clear"]').trigger('click');
        expect(router.get).toHaveBeenLastCalledWith(
            '/doctor-payouts/closings',
            { doctor: '', status: '' },
            expect.any(Object),
        );
    });

    it('sem fechamentos: mensagem de vazio nas duas vistas', async () => {
        const w = mountPage({ payouts: paginator([]) });

        expect(w.find('[data-test="closings-empty"]').text()).toBe('No closing found.');

        await w.find('.to-cards').trigger('click');
        expect(w.find('[data-test="closings-empty"]').text()).toBe('No closing found.');
    });

    it('pago em parte: mostra o pago, o saldo e o último pagamento; cancelado sem valores', () => {
        const w = mountPage({
            payouts: paginator([
                {
                    ...payoutSummary,
                    id: 'pp',
                    status: 'partially_paid',
                    total_amount: 300,
                    paid_amount: 100,
                    remaining_amount: 200,
                    paid_at: '2026-09-05',
                },
                {
                    ...payoutSummary,
                    id: 'cc',
                    status: 'cancelled',
                    total_amount: 300,
                    paid_amount: null,
                    remaining_amount: 0,
                    paid_at: null,
                },
            ]),
        });
        const [partial, cancelled] = w.findAll('[data-test="closing-row"]');

        expect(partial.find('[data-test="closing-paid"]').text()).toContain(brl(100));
        expect(partial.find('[data-test="closing-balance"]').text()).toBe(`Balance ${brl(200)}`);
        expect(partial.find('[data-test="closing-paid"]').text()).toContain('Last payment on 05/09/2026');
        expect(cancelled.find('[data-test="closing-paid"]').text()).toBe('—');
    });
});
