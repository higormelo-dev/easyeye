import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ProductTable from '@/Pages/Panel/Stock/Products/ProductTable.vue';

/**
 * Tabela de produtos no padrão de PatientTable: colunas na ordem de sempre
 * (personalizável e persistida), cabeçalhos ordenáveis só nas chaves da
 * whitelist do backend (SortableTh real), valores no idioma do usuário,
 * ações (movimentações + editar/ativar/desativar/excluir) e estado vazio.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: {
        props: ['title', 'btnClass'],
        template: '<div class="dd" :data-title="title" :data-btn-class="btnClass"><span class="dd-trigger"><slot name="trigger" /></span><slot /></div>',
    },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant', 'inertiaHref'],
        emits: ['click'],
        template: '<button type="button" class="icon-btn" :title="title" :data-href="inertiaHref" :data-variant="variant" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_code: 'Código', col_name: 'Nome', col_category: 'Categoria', col_unit: 'Unidade',
    col_qty_on_hand: 'Saldo', col_cost_avg: 'Custo médio', col_sale_price: 'Preço',
    col_status: 'Status', col_actions: 'Ações', sort_by: 'Ordenar por :column',
    status_active: 'Ativo', status_inactive: 'Inativo', below_minimum: 'Abaixo do mínimo',
    badge_opm: 'OPM', badge_expiring_lot: 'Lote vencendo', expiring_lot_title: 'Vence em :date',
    action_movements: 'Movimentações do produto', action_edit: 'Editar', action_activate: 'Ativar',
    action_deactivate: 'Desativar', action_delete: 'Excluir', more_actions: 'Mais ações',
    empty_list: 'Nenhum produto encontrado.', columns_label: 'Colunas', columns_customize: 'Personalizar colunas',
    pagination_showing: 'Exibindo', pagination_of: 'de', pagination_suffix: 'produtos',
};

const nbsp = (s) => s.replace(/ /g, ' ');

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function product(overrides = {}) {
    return {
        id: 'p1', code: 'PRD-0000000001', name: 'Colírio X', category_name: 'Colírios', unit: 'un',
        unit_label: 'Unidade', qty_on_hand: 12, cost_avg: 1234.5, sale_price: 25.9, below_minimum: false,
        is_opm: false, has_expiring_lot: false, nearest_expiry: null, active: true, ...overrides,
    };
}

function paginator(rows, extra = {}) {
    return { data: rows, total: rows.length, from: 1, to: rows.length, last_page: 1, current_page: 1, links: [], ...extra };
}

function mountTable(rows = [product()], filters = { sort: 'name', direction: 'asc' }, extra = {}) {
    wrapper = mount(ProductTable, {
        props: { items: paginator(rows, extra), filters, t, movementsIndexUrl: '/stock/movements' },
    });

    return wrapper;
}

const headerLabels = (w) => w.findAll('thead th').map((th) => th.text());

describe('ProductTable', () => {
    it('mantém a ordem de colunas de sempre, com Status e Ações fixos no fim', () => {
        const w = mountTable();

        expect(headerLabels(w)).toEqual(['Código', 'Nome', 'Categoria', 'Unidade', 'Saldo', 'Custo médio', 'Preço', 'Status', 'Ações']);
    });

    it('respeita a ordem de colunas salva no navegador', () => {
        window.localStorage.setItem(
            'stock_products_columns_order',
            JSON.stringify(['nome', 'preco', 'codigo', 'categoria', 'unidade', 'saldo', 'custo']),
        );
        const w = mountTable();

        expect(headerLabels(w).slice(0, 3)).toEqual(['Nome', 'Preço', 'Código']);
        expect(headerLabels(w).slice(-2)).toEqual(['Status', 'Ações']);
    });

    it('só oferece ordenação nas colunas da whitelist do backend', () => {
        const w = mountTable();
        const ths = w.findAll('thead th');
        const sortable = ths.filter((th) => th.find('button').exists()).map((th) => th.text());

        expect(sortable).toEqual(['Código', 'Nome', 'Saldo', 'Custo médio', 'Preço']);
        expect(ths[1].attributes('aria-sort')).toBe('ascending');
        expect(ths[0].attributes('aria-sort')).toBe('none');
        expect(ths[1].find('button').attributes('title')).toBe('Ordenar por Nome');
    });

    it('emite a ordenação: inverte a coluna atual e começa ascendente nas outras', async () => {
        const w = mountTable();
        const ths = w.findAll('thead th');

        await ths[1].find('button').trigger('click');   // Nome (atual asc)
        await ths[4].find('button').trigger('click');   // Saldo

        expect(w.emitted('sort')).toEqual([
            [{ sort: 'name', direction: 'desc' }],
            [{ sort: 'qty_on_hand', direction: 'asc' }],
        ]);
    });

    it('formata custo, preço e saldo no idioma do usuário (sem "R$" fixo nem toFixed)', () => {
        const w = mountTable([product({ qty_on_hand: 2.5, cost_avg: 1234.5, sale_price: null })]);
        const cells = w.findAll('tbody td').map((td) => nbsp(td.text()));

        expect(cells[4]).toBe('2,5');
        expect(cells[5]).toBe('R$ 1.234,50');
        expect(cells[6]).toBe('—');
    });

    it('destaca saldo abaixo do mínimo com indicador acessível e mostra OPM/lote vencendo', () => {
        // nearest_expiry chega em ISO e é formatado no idioma do usuário.
        const w = mountTable([product({ below_minimum: true, is_opm: true, has_expiring_lot: true, nearest_expiry: '2026-09-30' })]);

        expect(w.find('[aria-label="Abaixo do mínimo"]').exists()).toBe(true);
        expect(w.text()).toContain('OPM');
        expect(w.find('[title="Vence em 30/09/2026"]').text()).toContain('Lote vencendo');
    });

    it('saldo usa o formatador compartilhado: até 3 casas, sem zeros à direita', () => {
        const w = mountTable([
            product({ id: 'p1', qty_on_hand: 2.125 }),
            product({ id: 'p2', qty_on_hand: 7 }),
            product({ id: 'p3', qty_on_hand: 0.5 }),
        ]);
        const saldo = w.findAll('tbody tr').map((tr) => nbsp(tr.findAll('td')[4].text()));

        expect(saldo).toEqual(['2,125', '7', '0,5']);
    });

    it('botão "Colunas" acompanha o tema (bg-body) e o "Mais ações" da linha usa o gatilho padrão', () => {
        const w = mountTable();

        const columns = w.find('[data-title="Personalizar colunas"]');
        expect(columns.attributes('data-btn-class')).toContain('bg-body');
        expect(columns.attributes('data-btn-class')).not.toContain('bg-white');
        expect(columns.find('.dd-trigger').text()).toBe('Colunas');

        const more = w.find('[data-title="Mais ações"]');
        expect(more.find('.dd-trigger').text()).toBe('');
        expect(more.find('.visually-hidden').exists()).toBe(false);
    });

    it('mostra badge de status como em pacientes', () => {
        const w = mountTable([product({ active: true }), product({ id: 'p2', active: false })]);
        const badges = w.findAll('tbody .badge.border');

        expect(badges[0].text()).toBe('Ativo');
        expect(badges[0].classes()).toContain('text-success');
        expect(badges[1].text()).toBe('Inativo');
        expect(badges[1].classes()).toContain('text-danger');
    });

    it('atalho de movimentações filtra pelo produto e o menu emite editar/desativar/excluir com o produto', async () => {
        const p = product();
        const w = mountTable([p]);

        const shortcut = w.find('button[title="Movimentações do produto"]');
        expect(shortcut.attributes('data-href')).toBe('/stock/movements?entity_product_id=p1');
        expect(shortcut.attributes('data-variant')).toBe('info');
        expect(w.find('[data-title="Mais ações"]').exists()).toBe(true);

        const items = w.findAll('.dropdown-item');
        expect(items.map((b) => b.text())).toEqual(['Editar', 'Desativar', 'Excluir']);

        await items[0].trigger('click');
        await items[1].trigger('click');
        await items[2].trigger('click');

        expect(w.emitted('edit')[0]).toEqual([p]);
        expect(w.emitted('toggleActive')[0]).toEqual([p]);
        expect(w.emitted('delete')[0]).toEqual([p]);
    });

    it('produto inativo oferece "Ativar"', () => {
        const w = mountTable([product({ active: false })]);

        expect(w.findAll('.dropdown-item')[1].text()).toBe('Ativar');
    });

    it('mostra o estado vazio ocupando todas as colunas', () => {
        const w = mountTable([]);
        const td = w.find('tbody td');

        expect(td.text()).toBe('Nenhum produto encontrado.');
        expect(td.attributes('colspan')).toBe('9');
    });

    it('pagina com os rótulos traduzidos', () => {
        const w = mountTable([product()], { sort: 'name', direction: 'asc' }, { last_page: 2, total: 20, to: 15 });

        expect(w.text()).toContain('Exibindo 1–15 de 20 produtos');
    });
});
