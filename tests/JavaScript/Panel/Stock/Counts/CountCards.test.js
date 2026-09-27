import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import CountCards from '@/Pages/Panel/Stock/Counts/CountCards.vue';

/**
 * Cards da contagem: mesmo paginator da tabela, campo "Contado" com rótulo
 * visível, diferença como status e a mesma ação da tabela.
 */

vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: { props: ['title', 'href', 'target'], template: '<a :title="title" :href="href" :target="target" />' },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_category: 'Categoria', col_qty_on_hand: 'Saldo do sistema', col_counted: 'Contado', requires_lot: 'Exige lote',
    difference_match: 'Confere', action_movements: 'Ver movimentações do produto', opens_new_tab: 'abre em nova aba',
    empty_list: 'Nenhum produto ativo encontrado para contar.',
};

let wrapper;

afterEach(() => wrapper?.unmount());

function product(overrides = {}) {
    return {
        id: 'p1', name: 'Lente IOL', code: 'PRD-7', unit: 'un', unit_label: 'Unidade', category_name: null,
        qty_on_hand: 3, requires_lot: false, movements_url: '/stock/movements?entity_product_id=p1', ...overrides,
    };
}

function mountCards({ rows = [product()], counted = {}, deltas = {}, attachTo } = {}) {
    wrapper = mount(CountCards, {
        props: { products: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] }, counted, deltas, t },
        attachTo,
    });

    return wrapper;
}

describe('CountCards', () => {
    it('renderiza um card por produto do paginator com os dados principais', () => {
        const w = mountCards({ rows: [product(), product({ id: 'p2', name: 'Soro', requires_lot: true })] });
        const cards = w.findAll('.card');
        const first = cards[0].text();
        const fields = Object.fromEntries(cards[0].findAll('dl > div').map((d) => [d.find('dt').text(), d.find('dd').text()]));

        expect(cards).toHaveLength(2);
        expect(first).toContain('Lente IOL');
        expect(first).toContain('PRD-7');
        expect(fields).toEqual({ 'Categoria:': '—', 'Saldo do sistema:': '3 Unidade' });
        // <dl> no padrão de PatientCards: linha flex com rótulo em negrito e valor sem margem.
        cards[0].findAll('dl > div').forEach((row) => {
            expect(row.classes()).toEqual(expect.arrayContaining(['d-flex', 'gap-1']));
            expect(row.find('dt').classes()).toContain('fw-semibold');
            expect(row.find('dd').classes()).toContain('mb-0');
        });
        expect(first).not.toContain('Exige lote');
        expect(cards[1].text()).toContain('Exige lote');
    });

    it('campo "Contado" tem rótulo associado e emite o valor digitado', async () => {
        const w = mountCards({ counted: { p1: '4' } });
        const input = w.find('input');

        expect(w.find(`label[for="${input.attributes('id')}"]`).text()).toBe('Contado');
        expect(input.element.value).toBe('4');

        await input.setValue('9');

        expect(w.emitted('count').at(-1)).toEqual(['p1', '9']);
    });

    it('Enter no campo "Contado" leva ao próximo card', async () => {
        const w = mountCards({ rows: [product(), product({ id: 'p2', name: 'Soro' })], attachTo: document.body });
        const inputs = w.findAll('input');

        inputs[0].element.focus();
        await inputs[0].trigger('keydown', { key: 'Enter' });

        expect(document.activeElement).toBe(inputs[1].element);
    });

    it('mostra a diferença como status e a mesma ação da tabela', () => {
        const w = mountCards({ deltas: { p1: 0 } });

        expect(w.find('.badge').text()).toBe('Confere');
        expect(w.find('a[title="Ver movimentações do produto (abre em nova aba)"]').attributes('target')).toBe('_blank');
    });

    it('mostra o estado vazio', () => {
        const w = mountCards({ rows: [] });

        expect(w.find('.card').exists()).toBe(false);
        expect(w.text()).toContain('Nenhum produto ativo encontrado para contar.');
    });
});
