import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import CountTable from '@/Pages/Panel/Stock/Counts/CountTable.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';

/**
 * Tabela da contagem no padrão de PatientTable: cabeçalhos ordenáveis
 * (SortableTh), menu "Colunas" persistido, Contado/Diferença/Ações fixos no
 * fim, campo acessível que emite o valor e diferença como status.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { template: '<div class="dd"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant', 'href', 'target'],
        template: '<a :title="title" :href="href" :target="target" :data-variant="variant" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_product: 'Produto', col_code: 'Código', col_category: 'Categoria', col_qty_on_hand: 'Saldo do sistema',
    col_counted: 'Contado', col_difference: 'Diferença', col_actions: 'Ações', sort_by: 'Ordenar por :column',
    requires_lot: 'Exige lote', counted_label: 'Quantidade contada de :product', difference_match: 'Confere',
    action_movements: 'Ver movimentações do produto', opens_new_tab: 'abre em nova aba',
    empty_list: 'Nenhum produto ativo encontrado para contar.',
};

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function product(overrides = {}) {
    return {
        id: 'p1', name: 'Colírio', code: 'PRD-1', unit: 'fr', unit_label: 'Frasco', category_name: 'Colírios',
        qty_on_hand: 10, requires_lot: true, movements_url: '/stock/movements?entity_product_id=p1', ...overrides,
    };
}

function mountTable({ rows = [product()], counted = {}, deltas = {}, filters = { sort: 'name', direction: 'asc' }, attachTo } = {}) {
    wrapper = mount(CountTable, {
        props: { products: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] }, counted, deltas, filters, t },
        attachTo,
    });

    return wrapper;
}

const headerLabels = (w) => w.findAll('thead th').map((th) => th.text());

describe('CountTable', () => {
    it('mostra as colunas na ordem padrão com Contado, Diferença e Ações fixos no fim', () => {
        const w = mountTable();

        expect(headerLabels(w)).toEqual(['Produto', 'Código', 'Categoria', 'Saldo do sistema', 'Contado', 'Diferença', 'Ações']);
    });

    it('respeita e grava a ordem de colunas no navegador', async () => {
        window.localStorage.setItem('stock_counts_columns_order', JSON.stringify(['qty_on_hand', 'product', 'code', 'category']));
        const w = mountTable();

        expect(headerLabels(w).slice(0, 2)).toEqual(['Saldo do sistema', 'Produto']);

        w.findComponent(ColumnOrderMenu).vm.$emit('reset');
        await w.vm.$nextTick();

        expect(headerLabels(w).slice(0, 2)).toEqual(['Produto', 'Código']);
        expect(JSON.parse(window.localStorage.getItem('stock_counts_columns_order'))).toEqual(['product', 'code', 'category', 'qty_on_hand']);
    });

    it('ordena pelos cabeçalhos com as chaves da whitelist do backend', async () => {
        const w = mountTable({ filters: { sort: 'name', direction: 'asc' } });
        const ths = w.findAll('thead th');

        expect(ths[0].attributes('aria-sort')).toBe('ascending');
        expect(ths[4].attributes('aria-sort')).toBeUndefined(); // Contado não ordena

        await ths[0].find('button').trigger('click');
        await ths[2].find('button').trigger('click');
        await ths[3].find('button').trigger('click');

        expect(w.emitted('sort')).toEqual([
            [{ sort: 'name', direction: 'desc' }],
            [{ sort: 'category', direction: 'asc' }],
            [{ sort: 'qty_on_hand', direction: 'asc' }],
        ]);
    });

    it('mostra saldo com a unidade traduzida pelo backend (unit_label) e o selo "Exige lote"', () => {
        const w = mountTable({ rows: [product({ qty_on_hand: 2.5 })] });
        const cells = w.findAll('tbody td');

        expect(cells[0].text()).toContain('Exige lote');
        expect(cells[3].text().replace(/\s+/g, ' ')).toBe('2,5 Frasco');
    });

    it('campo "Contado" acessível exibe o valor digitado e emite a mudança', async () => {
        const w = mountTable({ counted: { p1: '7' } });
        const input = w.find('tbody input');

        expect(input.attributes('aria-label')).toBe('Quantidade contada de Colírio');
        expect(input.element.value).toBe('7');

        await input.setValue('12');

        expect(w.emitted('count').at(-1)).toEqual(['p1', '12']);
    });

    it('Enter no campo "Contado" leva ao próximo produto (digitação em sequência)', async () => {
        const w = mountTable({ rows: [product(), product({ id: 'p2', name: 'Soro' })], attachTo: document.body });
        const inputs = w.findAll('tbody input');

        inputs[0].element.focus();
        await inputs[0].trigger('keydown', { key: 'Enter' });
        expect(document.activeElement).toBe(inputs[1].element);

        // Último campo: Enter não sai do lugar nem emite nada.
        await inputs[1].trigger('keydown', { key: 'Enter' });
        expect(document.activeElement).toBe(inputs[1].element);
        expect(w.emitted('count')).toBeUndefined();

        // O atalho da linha continua acessível por teclado (sem tabindex negativo).
        expect(w.find('a[title="Ver movimentações do produto (abre em nova aba)"]').attributes('tabindex')).toBeUndefined();
    });

    it('diferença como status: confere, sobra, falta e não contado', () => {
        const rows = [product(), product({ id: 'p2' }), product({ id: 'p3' }), product({ id: 'p4' })];
        const w = mountTable({ rows, deltas: { p1: 0, p2: 5, p3: -4, p4: null } });
        const diff = w.findAll('tbody tr').map((tr) => tr.findAll('td')[5]);

        expect(diff[0].text()).toBe('Confere');
        expect(diff[0].find('.badge').classes()).toContain('badge-soft-success');
        expect(diff[1].text()).toBe('+5');
        expect(diff[1].find('.badge').classes()).toContain('badge-soft-info');
        expect(diff[2].text()).toBe('−4');
        expect(diff[2].find('.badge').classes()).toContain('badge-soft-danger');
        expect(diff[3].text()).toBe('—');
        expect(diff[3].find('.badge').exists()).toBe(false);
    });

    it('atalho de movimentações abre o extrato do produto em nova aba (não perde a contagem)', () => {
        const w = mountTable();
        const link = w.find('a[title="Ver movimentações do produto (abre em nova aba)"]');

        expect(link.attributes('href')).toBe('/stock/movements?entity_product_id=p1');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('data-variant')).toBe('info');
    });

    it('mostra o estado vazio ocupando todas as colunas', () => {
        const w = mountTable({ rows: [] });
        const cell = w.find('tbody td');

        expect(cell.text()).toContain('Nenhum produto ativo encontrado para contar.');
        expect(cell.attributes('colspan')).toBe('7');
    });
});
