import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import MovementTable from '@/Pages/Panel/Stock/Movements/MovementTable.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';

/**
 * Tabela do extrato no padrão de PatientTable: ordenação pelos cabeçalhos
 * (SortableTh, só colunas da whitelist), menu "Colunas" persistido, badge de
 * tipo, valores no idioma do usuário e a única ação do ledger (extrato do produto).
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd" :data-title="title"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant', 'disabled'],
        emits: ['click'],
        template: '<button type="button" :title="title" :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_occurred_at: 'Data', col_product: 'Produto', col_lot: 'Lote', col_quantity: 'Quantidade',
    col_unit_cost: 'Custo unit.', col_balance_after: 'Saldo após', col_note: 'Observação', col_created_by: 'Por',
    col_type: 'Tipo', col_actions: 'Ações', sort_by: 'Ordenar por :column',
    action_filter_product: 'Ver extrato deste produto', empty_list: 'Nenhuma movimentação encontrada.',
};

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function movement(overrides = {}) {
    return {
        id: 'm1', entity_product_id: 'p1', product_name: 'Colírio', product_code: 'PRD-1',
        type: 'manual_in', type_label: 'Entrada manual', direction: 1,
        quantity: 10, unit_cost: 12.5, balance_after: 22.5, lot_number: 'L-01', note: 'doação',
        created_by_name: 'Ana', occurred_at: '03/09/2026 10:00', occurred_at_iso: '2026-09-03T10:00:00',
        ...overrides,
    };
}

function mountTable(rows = [movement()], filters = { sort: 'occurred_at', direction: 'desc' }) {
    wrapper = mount(MovementTable, {
        props: { items: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] }, filters, t },
    });

    return wrapper;
}

const headerLabels = (w) => w.findAll('thead th').map((th) => th.text());
const text = (el) => el.text().replace(/\s+/g, ' ').trim();

describe('MovementTable', () => {
    it('mostra as colunas na ordem padrão com Tipo e Ações fixos no fim', () => {
        const w = mountTable();

        expect(headerLabels(w)).toEqual([
            'Data', 'Produto', 'Lote', 'Quantidade', 'Custo unit.', 'Saldo após', 'Observação', 'Por', 'Tipo', 'Ações',
        ]);
    });

    it('respeita a ordem salva no navegador e grava a nova ordem', async () => {
        window.localStorage.setItem(
            'stock_movements_columns_order',
            JSON.stringify(['product', 'occurred_at', 'lot', 'quantity', 'unit_cost', 'balance_after', 'note', 'created_by']),
        );
        const w = mountTable();

        expect(headerLabels(w).slice(0, 2)).toEqual(['Produto', 'Data']);

        w.findComponent(ColumnOrderMenu).vm.$emit('move', 0, 1);
        await w.vm.$nextTick();

        expect(headerLabels(w).slice(0, 2)).toEqual(['Data', 'Produto']);
        expect(JSON.parse(window.localStorage.getItem('stock_movements_columns_order')).slice(0, 2)).toEqual(['occurred_at', 'product']);
    });

    it('ordena só pelas colunas da whitelist: inverte a atual e começa ascendente nas outras', async () => {
        const w = mountTable([movement()], { sort: 'occurred_at', direction: 'desc' });
        const ths = w.findAll('thead th');

        expect(ths[0].attributes('aria-sort')).toBe('descending');
        expect(ths[1].attributes('aria-sort')).toBe('none');
        expect(ths[2].find('button').exists()).toBe(false); // Lote não é ordenável
        expect(ths[1].find('button').attributes('title')).toBe('Ordenar por Produto');

        await ths[0].find('button').trigger('click');
        await ths[1].find('button').trigger('click');

        expect(w.emitted('sort')).toEqual([
            [{ sort: 'occurred_at', direction: 'asc' }],
            [{ sort: 'product', direction: 'asc' }],
        ]);
    });

    it('formata data/hora, quantidade com sinal e custo no idioma do usuário', () => {
        const w = mountTable([movement(), movement({ id: 'm2', type: 'loss', direction: -1, quantity: 2.5, unit_cost: null })]);
        const [first, second] = w.findAll('tbody tr');

        expect(text(first.findAll('td')[0])).toBe('03/09/2026, 10:00'); // useLocaleFormat::dateTime
        expect(text(first.findAll('td')[3])).toBe('+10');
        expect(text(first.findAll('td')[4])).toBe('R$ 12,50');
        expect(text(first.findAll('td')[5])).toBe('22,5');
        expect(text(second.findAll('td')[3])).toBe('−2,5');
        expect(text(second.findAll('td')[4])).toBe('—');
    });

    it('badge do tipo com o rótulo traduzido do backend (type_label) e cor semântica', () => {
        const w = mountTable([movement(), movement({ id: 'm2', type: 'loss', type_label: 'Perda/quebra', direction: -1 })]);
        const badges = w.findAll('tbody .badge');

        expect(badges[0].text()).toBe('Entrada manual');
        expect(badges[0].classes()).toContain('badge-soft-success');
        expect(badges[1].text()).toBe('Perda/quebra');
        expect(badges[1].classes()).toContain('badge-soft-danger');
    });

    it('única ação é o extrato do produto — sem editar/excluir (ledger imutável)', async () => {
        const w = mountTable();
        const action = w.find('button[title="Ver extrato deste produto"]');

        expect(action.attributes('data-variant')).toBe('info');
        expect(w.text()).not.toContain('Editar');
        expect(w.text()).not.toContain('Excluir');

        await action.trigger('click');

        expect(w.emitted('filterProduct')[0]).toEqual(['p1']);
    });

    it('desabilita o atalho quando a tela já está filtrada pelo produto da linha', () => {
        const w = mountTable([movement()], { sort: 'occurred_at', direction: 'desc', entity_product_id: 'p1' });

        expect(w.find('button[title="Ver extrato deste produto"]').attributes('disabled')).toBeDefined();
    });

    it('mostra o estado vazio ocupando todas as colunas', () => {
        const w = mountTable([]);
        const cell = w.find('tbody td');

        expect(cell.text()).toContain('Nenhuma movimentação encontrada.');
        expect(cell.attributes('colspan')).toBe('10');
    });
});
