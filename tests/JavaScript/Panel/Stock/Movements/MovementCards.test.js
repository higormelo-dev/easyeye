import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import MovementCards from '@/Pages/Panel/Stock/Movements/MovementCards.vue';

/**
 * Cards do extrato: mesmo paginator da tabela (sem requisição extra), tipo
 * como badge, dados principais formatados e a mesma ação da tabela.
 */

vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant', 'disabled'],
        emits: ['click'],
        template: '<button type="button" :title="title" :disabled="disabled" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_occurred_at: 'Data', col_quantity: 'Quantidade', col_unit_cost: 'Custo unit.', col_balance_after: 'Saldo após',
    col_lot: 'Lote', col_created_by: 'Por', col_note: 'Observação',
    action_filter_product: 'Ver extrato deste produto', empty_list: 'Nenhuma movimentação encontrada.',
    pagination_label: 'Paginação', pagination_suffix: 'movimentações',
};

let wrapper;

afterEach(() => wrapper?.unmount());

function movement(overrides = {}) {
    return {
        id: 'm1', entity_product_id: 'p1', product_name: 'Lente IOL', product_code: 'PRD-9',
        type: 'purchase_in', type_label: 'Entrada por compra', direction: 1, quantity: 3, unit_cost: 450, balance_after: 7,
        lot_number: null, note: null, created_by_name: 'Bia', occurred_at: '01/09/2026 08:30', occurred_at_iso: '2026-09-01T08:30:00',
        ...overrides,
    };
}

function mountCards(rows, paginator = {}) {
    wrapper = mount(MovementCards, {
        props: {
            items: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [], ...paginator },
            filters: {},
            t,
        },
    });

    return wrapper;
}

describe('MovementCards', () => {
    it('renderiza um card por linha do paginator com os dados formatados', () => {
        const w = mountCards([movement(), movement({ id: 'm2', product_name: 'Soro' })]);
        const cards = w.findAll('.card');
        const first = cards[0].text().replace(/\s+/g, ' ');

        expect(cards).toHaveLength(2);
        expect(first).toContain('Lente IOL');
        expect(first).toContain('PRD-9');
        expect(first).toContain('Entrada por compra');
        expect(first).toContain('01/09/2026, 08:30'); // useLocaleFormat::dateTime
        expect(first).toContain('+3');
        expect(first).toContain('R$ 450,00');
        expect(first).not.toContain('Observação'); // sem observação, sem a linha
        // <dl> no padrão de PatientCards: linha flex com rótulo em negrito e valor sem margem.
        cards[0].findAll('dl > div').forEach((row) => {
            expect(row.classes()).toEqual(expect.arrayContaining(['d-flex', 'gap-1']));
            expect(row.find('dt').classes()).toContain('fw-semibold');
            expect(row.find('dt').text()).toMatch(/:$/);
            expect(row.find('dd').classes()).toContain('mb-0');
        });
    });

    it('a ação do card emite o produto da movimentação', async () => {
        const w = mountCards([movement()]);

        await w.find('button[title="Ver extrato deste produto"]').trigger('click');

        expect(w.emitted('filterProduct')[0]).toEqual(['p1']);
    });

    it('mostra a paginação traduzida quando há mais de uma página', () => {
        const w = mountCards([movement()], {
            last_page: 2, from: 1, to: 1, total: 2, next_page_url: '/p?page=2',
            links: [{ label: '&laquo;', url: null }, { label: '1', url: '/p?page=1', active: true }, { label: '2', url: '/p?page=2' }, { label: '&raquo;', url: null }],
        });

        expect(w.find('nav').attributes('aria-label')).toBe('Paginação');
        expect(w.text()).toContain('movimentações');
    });

    it('mostra o estado vazio', () => {
        const w = mountCards([]);

        expect(w.find('.card').exists()).toBe(false);
        expect(w.text()).toContain('Nenhuma movimentação encontrada.');
    });
});
