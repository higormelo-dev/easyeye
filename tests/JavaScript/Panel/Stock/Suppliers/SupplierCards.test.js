import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import SupplierCards from '@/Pages/Panel/Stock/Suppliers/SupplierCards.vue';

/**
 * Cards de fornecedores: renderizam o MESMO paginator da tabela (sem
 * endpoint extra), com as mesmas ações e a paginação do Inertia.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'inertiaHref'],
        emits: ['click'],
        template: '<button type="button" :title="title" :data-href="inertiaHref" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({
    default: { props: ['data', 'showingSuffix'], template: '<nav class="pager">{{ data.total }} {{ showingSuffix }}</nav>' },
}));

const t = {
    col_code: 'Código', col_document: 'Documento', col_contact: 'Contato', col_phone: 'Telefone',
    col_email: 'E-mail', status_active: 'Ativo', status_inactive: 'Inativo',
    action_purchase_orders: 'Pedidos de compra deste fornecedor', action_edit: 'Editar',
    action_delete: 'Excluir', empty_list: 'Nenhum fornecedor encontrado.', pagination_suffix: 'fornecedores',
};

let wrapper;

afterEach(() => wrapper?.unmount());

function supplier(overrides = {}) {
    return {
        id: 's1', code: 'FOR-0000000001', name: 'Alfa Óptica', active: true,
        document_display: '12.345.678/0001-99', phone_display: '(61) 3333-4444',
        contact_name: 'Ana', email: 'contato@alfa.test', ...overrides,
    };
}

function mountCards(rows, total = rows.length) {
    wrapper = mount(SupplierCards, {
        props: { items: { data: rows, total, last_page: 2, current_page: 1, links: [] }, t, purchaseOrdersUrl: '/po' },
    });

    return wrapper;
}

describe('SupplierCards', () => {
    it('renderiza um card por linha do paginator com os dados principais', () => {
        const w = mountCards([supplier(), supplier({ id: 's2', name: 'Beta', active: false, email: null })], 40);
        const cards = w.findAll('.card');

        expect(cards).toHaveLength(2);
        expect(cards[0].find('h6').text()).toBe('Alfa Óptica');
        expect(cards[0].find('.badge').text()).toBe('Ativo');
        expect(cards[0].find('dl').text()).toContain('FOR-0000000001');
        expect(cards[0].find('dl').text()).toContain('12.345.678/0001-99');
        expect(cards[0].find('dl').text()).toContain('(61) 3333-4444');
        expect(cards[0].find('dl').text()).toContain('contato@alfa.test');
        expect(cards[1].find('.badge').text()).toBe('Inativo');
        expect(cards[1].find('dl').text()).toContain('—');
        expect(w.find('.pager').text()).toBe('40 fornecedores');
    });

    it('tem as mesmas ações da tabela', async () => {
        const row = supplier();
        const w = mountCards([row]);

        expect(w.find('button[title="Pedidos de compra deste fornecedor"]').attributes('data-href')).toBe('/po?supplier_id=s1');

        const [edit, remove] = w.findAll('.dd .dropdown-item');
        await edit.trigger('click');
        await remove.trigger('click');

        expect(w.emitted('edit')[0]).toEqual([row]);
        expect(w.emitted('delete')[0]).toEqual([row]);
    });

    it('mostra o estado vazio', () => {
        expect(mountCards([]).text()).toContain('Nenhum fornecedor encontrado.');
    });
});
