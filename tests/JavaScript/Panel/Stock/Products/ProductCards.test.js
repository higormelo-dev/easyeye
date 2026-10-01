import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ProductCards from '@/Pages/Panel/Stock/Products/ProductCards.vue';

/**
 * Cards de produtos: renderizam o MESMO paginator da tabela (sem fetch/
 * endpoint extra), com saldo/custo/preço em linhas rotuladas e formatados
 * no idioma do usuário, e as mesmas ações da tabela.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd" :data-title="title"><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'variant', 'inertiaHref'],
        emits: ['click'],
        template: '<button type="button" :title="title" :data-href="inertiaHref" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_code: 'Código',
    col_category: 'Categoria',
    col_unit: 'Unidade',
    col_qty_on_hand: 'Saldo',
    col_cost_avg: 'Custo médio',
    col_sale_price: 'Preço',
    status_active: 'Ativo',
    status_inactive: 'Inativo',
    below_minimum: 'Abaixo do mínimo',
    badge_opm: 'OPM',
    badge_expiring_lot: 'Lote vencendo',
    expiring_lot_title: 'Vence em :date',
    action_movements: 'Movimentações do produto',
    action_edit: 'Editar',
    action_activate: 'Ativar',
    action_deactivate: 'Desativar',
    action_delete: 'Excluir',
    empty_list: 'Nenhum produto encontrado.',
    pagination_showing: 'Exibindo',
    pagination_of: 'de',
    pagination_suffix: 'produtos',
};

const nbsp = (s) => s.replace(/ /g, ' ');

let wrapper;
afterEach(() => wrapper?.unmount());

function product(overrides = {}) {
    return {
        id: 'p1',
        code: 'PRD-1',
        name: 'Colírio X',
        category_name: 'Colírios',
        unit: 'un',
        unit_label: 'Unidade',
        qty_on_hand: 3,
        cost_avg: 10,
        sale_price: 1500.75,
        below_minimum: true,
        is_opm: true,
        has_expiring_lot: false,
        nearest_expiry: null,
        active: false,
        ...overrides,
    };
}

function mountCards(rows, extra = {}) {
    wrapper = mount(ProductCards, {
        props: {
            items: {
                data: rows,
                total: rows.length,
                from: 1,
                to: rows.length,
                last_page: 1,
                current_page: 1,
                links: [],
                ...extra,
            },
            t,
            movementsIndexUrl: '/stock/movements',
        },
    });

    return wrapper;
}

/** { rótulo: valor } das linhas <dt>/<dd> do card. */
function definitionList(card) {
    const labels = card.findAll('dt').map((dt) => dt.text());
    const values = card.findAll('dd').map((dd) => nbsp(dd.text()));

    return Object.fromEntries(labels.map((label, i) => [label, values[i]]));
}

describe('ProductCards', () => {
    it('renderiza os itens do paginator recebido, sem buscar nada na rede', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        const w = mountCards([product(), product({ id: 'p2', name: 'Seringa 5ml' })]);

        expect(w.findAll('.card')).toHaveLength(2);
        expect(w.findAll('h6').map((h) => h.text())).toEqual(['Colírio X', 'Seringa 5ml']);
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('mostra saldo, custo e preço em linhas rotuladas e formatadas', () => {
        const w = mountCards([product()]);

        expect(definitionList(w.find('.card'))).toEqual({
            'Código:': 'PRD-1',
            'Categoria:': 'Colírios',
            'Unidade:': 'Unidade',
            'Saldo:': '3',
            'Custo médio:': 'R$ 10,00',
            'Preço:': 'R$ 1.500,75',
        });
        expect(w.find('[aria-label="Abaixo do mínimo"]').exists()).toBe(true);
        expect(w.text()).toContain('OPM');
        expect(w.text()).toContain('Inativo');
    });

    it('linhas no estilo de pacientes ("Rótulo: valor" lado a lado, sem alinhar à direita)', () => {
        const w = mountCards([product()]);
        const rows = w.findAll('.card dl > div');

        expect(rows).toHaveLength(6);
        rows.forEach((row) => {
            expect(row.classes()).toEqual(['d-flex', 'gap-1']);
            expect(row.find('dt').classes()).toContain('fw-semibold');
            expect(row.find('dd').classes()).toContain('mb-0');
            expect(row.find('dd').classes()).not.toContain('text-end');
        });
    });

    it('saldo fracionado sem zeros à direita e lote vencendo com a data ISO formatada', () => {
        const w = mountCards([product({ qty_on_hand: 2.5, has_expiring_lot: true, nearest_expiry: '2026-10-05' })]);

        expect(definitionList(w.find('.card'))['Saldo:']).toBe('2,5');
        expect(w.find('[title="Vence em 05/10/2026"]').text()).toContain('Lote vencendo');
    });

    it('tem as mesmas ações da tabela, emitindo o produto', async () => {
        const p = product();
        const w = mountCards([p]);

        expect(w.find('button[title="Movimentações do produto"]').attributes('data-href')).toBe(
            '/stock/movements?entity_product_id=p1',
        );

        const items = w.findAll('.dropdown-item');
        expect(items.map((b) => b.text())).toEqual(['Editar', 'Ativar', 'Excluir']);

        await items[0].trigger('click');
        await items[1].trigger('click');
        await items[2].trigger('click');

        expect(w.emitted('edit')[0]).toEqual([p]);
        expect(w.emitted('toggleActive')[0]).toEqual([p]);
        expect(w.emitted('delete')[0]).toEqual([p]);
    });

    it('mostra o estado vazio', () => {
        const w = mountCards([]);

        expect(w.find('.card').exists()).toBe(false);
        expect(w.text()).toContain('Nenhum produto encontrado.');
    });

    it('usa a mesma paginação da tabela', () => {
        const w = mountCards([product()], { last_page: 3, total: 40, to: 15 });

        expect(w.text()).toContain('Exibindo 1–15 de 40 produtos');
    });
});
