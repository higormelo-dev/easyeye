import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import SupplierTable from '@/Pages/Panel/Stock/Suppliers/SupplierTable.vue';
import SupplierCards from '@/Pages/Panel/Stock/Suppliers/SupplierCards.vue';

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
            id: 's1',
            name: 'Alfa',
            code: 'FOR-1',
            active: true,
            document_display: null,
            phone_display: null,
            contact_name: null,
            email: null,
        },
    ],
    total: 1,
};

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    window.localStorage.clear();
});

function rowTriggers(w, scope) {
    return w.findAll(`${scope} button[aria-haspopup="menu"]`);
}

describe('SupplierTable — acessibilidade das ações', () => {
    it('"Mais ações" da linha usa o gatilho padrão com rótulo traduzido', () => {
        wrapper = mount(SupplierTable, { props: { items, filters: {}, t, purchaseOrdersUrl: '/po' } });

        const [trigger] = rowTriggers(wrapper, 'tbody');
        expect(trigger.attributes('aria-label')).toBe('More actions');
        expect(trigger.attributes('title')).toBe('More actions');
        expect(trigger.attributes('aria-expanded')).toBe('false');
        expect(trigger.find('i.ti-dots-vertical').exists()).toBe(true);
        expect(wrapper.find('tbody .visually-hidden').exists()).toBe(false);
    });

    it('botão "Colunas" acompanha o tema (bg-body, não bg-white)', () => {
        wrapper = mount(SupplierTable, { props: { items, filters: {}, t, purchaseOrdersUrl: '/po' } });

        const columns = wrapper.find('button[aria-label="Customize columns"]');
        expect(columns.classes()).toContain('bg-body');
        expect(columns.classes()).not.toContain('bg-white');
        expect(columns.text()).toContain('Columns');
    });
});

describe('SupplierCards — acessibilidade e layout', () => {
    it('"Mais ações" do card usa o gatilho padrão com rótulo traduzido', () => {
        wrapper = mount(SupplierCards, { props: { items, t, purchaseOrdersUrl: '/po' } });

        const [trigger] = rowTriggers(wrapper, '.card');
        expect(trigger.attributes('aria-label')).toBe('More actions');
        expect(trigger.attributes('aria-haspopup')).toBe('menu');
        expect(wrapper.find('.card .visually-hidden').exists()).toBe(false);
    });

    it('<dl> no padrão de PatientCards: div.d-flex.gap-1 > dt.fw-semibold "Rótulo:" + dd.mb-0', () => {
        wrapper = mount(SupplierCards, { props: { items, t, purchaseOrdersUrl: '/po' } });

        const rows = wrapper.findAll('.card dl > div');
        expect(rows.length).toBeGreaterThan(0);
        rows.forEach((row) => {
            expect(row.classes()).toEqual(expect.arrayContaining(['d-flex', 'gap-1']));
            expect(row.find('dt').classes()).toContain('fw-semibold');
            expect(row.find('dt').text()).toMatch(/:$/);
            expect(row.find('dd').classes()).toContain('mb-0');
        });
    });
});
