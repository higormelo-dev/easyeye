import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import IolLensCards from '@/Pages/Panel/Stock/IolLenses/IolLensCards.vue';

/**
 * Cards de lentes IOL: renderizam o MESMO paginator da tabela (sem fetch),
 * com foto/modelo/status, dados em linhas rotuladas e as mesmas ações.
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
    col_manufacturer: 'Fabricante',
    col_category: 'Tipo',
    col_diopters: 'Dioptrias',
    col_price: 'Valor',
    col_stock: 'Estoque',
    diopter_range: ':min a :max D',
    not_informed: 'Não informado',
    status_active: 'Ativa',
    status_inactive: 'Inativa',
    action_movements: 'Movimentações da lente',
    action_edit: 'Editar',
    action_activate: 'Ativar',
    action_deactivate: 'Desativar',
    action_delete: 'Excluir',
    empty_list: 'Nenhuma lente encontrada.',
    pagination_showing: 'Exibindo',
    pagination_of: 'de',
    pagination_suffix: 'lentes',
};

const nbsp = (s) => s.replace(/ /g, ' ');

let wrapper;
afterEach(() => wrapper?.unmount());

function lens(overrides = {}) {
    return {
        id: 'l1',
        manufacturer: 'Zeiss',
        model_name: 'AT LISA',
        category: 'Multifocal',
        diopter_min: 0,
        diopter_max: 32,
        price: 4100,
        image_url: '/storage/l1.jpg',
        active: true,
        entity_product_id: 'prod-1',
        stock: { id: 'prod-1', unit_label: 'Unidade', qty_on_hand: 1.5 },
        ...overrides,
    };
}

function mountCards(rows, extra = {}) {
    wrapper = mount(IolLensCards, {
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

function definitionList(card) {
    const labels = card.findAll('dt').map((dt) => dt.text());
    const values = card.findAll('dd').map((dd) => nbsp(dd.text()));

    return Object.fromEntries(labels.map((label, i) => [label, values[i]]));
}

describe('IolLensCards', () => {
    it('renderiza os itens do paginator recebido, sem buscar nada na rede', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        const w = mountCards([lens(), lens({ id: 'l2', model_name: 'Tecnis', image_url: null })]);

        expect(w.findAll('.card')).toHaveLength(2);
        expect(w.findAll('h6').map((h) => h.text())).toEqual(['AT LISA', 'Tecnis']);
        expect(w.findAll('.card img')).toHaveLength(1);
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('mostra os dados em linhas rotuladas e formatadas', () => {
        const w = mountCards([lens()]);

        expect(definitionList(w.find('.card'))).toEqual({
            'Fabricante:': 'Zeiss',
            'Tipo:': 'Multifocal',
            'Dioptrias:': '0,0 a +32,0 D',
            'Valor:': 'R$ 4.100,00',
            'Estoque:': '1,5 Unidade',
        });
        expect(w.text()).toContain('Ativa');
    });

    it('linhas no estilo de pacientes ("Rótulo: valor" lado a lado, sem alinhar à direita)', () => {
        const w = mountCards([lens()]);
        const rows = w.findAll('.card dl > div');

        expect(rows).toHaveLength(5);
        rows.forEach((row) => {
            expect(row.classes()).toEqual(['d-flex', 'gap-1']);
            expect(row.find('dt').classes()).toContain('fw-semibold');
            expect(row.find('dd').classes()).toContain('mb-0');
            expect(row.find('dd').classes()).not.toContain('text-end');
        });
    });

    it('tem as mesmas ações da tabela, emitindo a lente', async () => {
        const l = lens({ active: false });
        const w = mountCards([l]);

        expect(w.find('button[title="Movimentações da lente"]').attributes('data-href')).toBe(
            '/stock/movements?entity_product_id=prod-1',
        );

        const items = w.findAll('.dropdown-item');
        expect(items.map((b) => b.text())).toEqual(['Editar', 'Ativar', 'Excluir']);

        await items[1].trigger('click');
        await items[2].trigger('click');

        expect(w.emitted('toggleActive')[0]).toEqual([l]);
        expect(w.emitted('delete')[0]).toEqual([l]);
    });

    it('mostra o estado vazio', () => {
        const w = mountCards([]);

        expect(w.find('.card').exists()).toBe(false);
        expect(w.text()).toContain('Nenhuma lente encontrada.');
    });

    it('usa a mesma paginação da tabela', () => {
        const w = mountCards([lens()], { last_page: 2, total: 20, to: 12 });

        expect(w.text()).toContain('Exibindo 1–12 de 20 lentes');
    });
});
