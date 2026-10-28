import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import PurchaseOrderTable from '@/Pages/Panel/Stock/PurchaseOrders/PurchaseOrderTable.vue';
import PurchaseOrderCards from '@/Pages/Panel/Stock/PurchaseOrders/PurchaseOrderCards.vue';

/**
 * Com o ActionDropdown REAL: "Mais ações" da linha/card usa o gatilho padrão
 * (aria-label = title, aria-haspopup, aria-expanded — sem #trigger nem texto
 * visually-hidden duplicado), o botão "Colunas" segue o tema (bg-body) e o
 * <dl> dos cards tem o mesmo layout de PatientCards.
 */

vi.mock('@/Components/Panel/TablePagination.vue', () => ({ default: { template: '<nav />' } }));

const t = { more_actions: 'More actions', columns_label: 'Columns', columns_customize: 'Customize columns' };
const items = {
    data: [
        {
            id: 'p1',
            code: 'PC-1',
            supplier_name: 'Alfa',
            status: 'draft',
            status_label: 'Draft',
            is_editable: true,
            order_date: '2026-09-01',
            expected_delivery_date: null,
            total_amount: 10,
        },
    ],
    total: 1,
};

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    window.localStorage.clear();
});

describe('PurchaseOrderTable — acessibilidade das ações', () => {
    it('"Mais ações" da linha usa o gatilho padrão com rótulo traduzido', () => {
        wrapper = mount(PurchaseOrderTable, { props: { items, filters: {}, t, pdfUrlTemplate: '/po/__ID__/pdf' } });

        const [trigger] = wrapper.findAll('tbody button[aria-haspopup="menu"]');
        expect(trigger.attributes('aria-label')).toBe('More actions');
        expect(trigger.attributes('title')).toBe('More actions');
        expect(trigger.attributes('aria-expanded')).toBe('false');
        expect(trigger.find('i.ti-dots-vertical').exists()).toBe(true);
        expect(wrapper.find('tbody .visually-hidden').exists()).toBe(false);
    });

    it('botão "Colunas" acompanha o tema (bg-body, não bg-white)', () => {
        wrapper = mount(PurchaseOrderTable, { props: { items, filters: {}, t, pdfUrlTemplate: '/po/__ID__/pdf' } });

        const columns = wrapper.find('button[aria-label="Customize columns"]');
        expect(columns.classes()).toContain('bg-body');
        expect(columns.classes()).not.toContain('bg-white');
    });
});

describe('PurchaseOrderCards — acessibilidade e layout', () => {
    it('"Mais ações" do card usa o gatilho padrão com rótulo traduzido', () => {
        wrapper = mount(PurchaseOrderCards, { props: { items, t, pdfUrlTemplate: '/po/__ID__/pdf' } });

        const [trigger] = wrapper.findAll('.card button[aria-haspopup="menu"]');
        expect(trigger.attributes('aria-label')).toBe('More actions');
        expect(wrapper.find('.card .visually-hidden').exists()).toBe(false);
    });

    it('<dl> no padrão de PatientCards: div.d-flex.gap-1 > dt.fw-semibold "Rótulo:" + dd.mb-0', () => {
        wrapper = mount(PurchaseOrderCards, { props: { items, t, pdfUrlTemplate: '/po/__ID__/pdf' } });

        const rows = wrapper.findAll('.card dl > div');
        expect(rows).toHaveLength(3);
        rows.forEach((row) => {
            expect(row.classes()).toEqual(expect.arrayContaining(['d-flex', 'gap-1']));
            expect(row.find('dt').classes()).toContain('fw-semibold');
            expect(row.find('dt').text()).toMatch(/:$/);
            expect(row.find('dd').classes()).toContain('mb-0');
        });
    });

    it('badge mostra o rótulo traduzido do backend (status_label)', () => {
        wrapper = mount(PurchaseOrderCards, { props: { items, t, pdfUrlTemplate: '/po/__ID__/pdf' } });

        expect(wrapper.find('.card .badge').text()).toBe('Draft');
    });
});
