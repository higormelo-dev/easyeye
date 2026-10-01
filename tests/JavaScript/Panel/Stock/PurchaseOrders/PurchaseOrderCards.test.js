import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import PurchaseOrderCards from '@/Pages/Panel/Stock/PurchaseOrders/PurchaseOrderCards.vue';

/**
 * Cards de pedidos de compra: MESMO paginator da tabela (sem endpoint
 * extra), dados formatados no idioma do usuário e as mesmas ações por status.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'href'],
        emits: ['click'],
        template:
            '<button type="button" class="icon-btn" :title="title" :data-href="href" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({
    default: {
        props: ['data', 'showingSuffix'],
        template: '<nav class="pager">{{ data.total }} {{ showingSuffix }}</nav>',
    },
}));

const t = {
    col_order_date: 'Data',
    col_expected_delivery: 'Previsão de entrega',
    col_total: 'Total',
    action_pdf: 'Baixar PDF',
    action_send: 'Enviar ao fornecedor',
    action_receive: 'Receber',
    action_edit: 'Editar',
    action_cancel: 'Cancelar pedido',
    action_delete: 'Excluir',
    empty_list: 'Nenhum pedido de compra encontrado.',
    pagination_suffix: 'pedidos',
};

let wrapper;

afterEach(() => wrapper?.unmount());

function po(overrides = {}) {
    return {
        id: 'p1',
        code: 'PC-0000000001',
        supplier_name: 'Alfa Óptica',
        status: 'draft',
        status_label: 'Rascunho',
        is_editable: true,
        order_date: '2026-09-01',
        expected_delivery_date: '2026-09-15',
        total_amount: 250,
        ...overrides,
    };
}

function mountCards(rows, total = rows.length) {
    wrapper = mount(PurchaseOrderCards, {
        props: {
            items: { data: rows, total, last_page: 3, current_page: 1, links: [] },
            t,
            pdfUrlTemplate: '/po/__ID__/pdf',
        },
    });

    return wrapper;
}

const normalizeSpaces = (s) => s.replace(/\s/g, ' ');

describe('PurchaseOrderCards', () => {
    it('renderiza um card por linha do paginator com fornecedor, código, status, datas e total', () => {
        const w = mountCards(
            [
                po(),
                po({ id: 'p2', status: 'received', status_label: 'Recebido', is_editable: false, supplier_name: null }),
            ],
            31,
        );
        const cards = w.findAll('.card');

        expect(cards).toHaveLength(2);
        expect(cards[0].find('h6').text()).toBe('Alfa Óptica');
        expect(cards[0].find('code').text()).toBe('PC-0000000001');
        expect(cards[0].find('.badge').text()).toBe('Rascunho');
        expect(normalizeSpaces(cards[0].find('dl').text())).toContain('01/09/2026');
        expect(normalizeSpaces(cards[0].find('dl').text())).toContain('15/09/2026');
        expect(normalizeSpaces(cards[0].find('dl').text())).toContain('R$ 250,00');
        expect(cards[1].find('h6').text()).toBe('—');
        expect(cards[1].find('.badge').classes()).toContain('badge-soft-success');
        expect(w.find('.pager').text()).toBe('31 pedidos');
    });

    it('usa o mesmo gating por status da tabela', () => {
        const w = mountCards([
            po(),
            po({ id: 'p2', status: 'sent', is_editable: false }),
            po({ id: 'p3', status: 'received', is_editable: false }),
        ]);
        const cards = w.findAll('.card');
        const titles = (card) => card.findAll('.icon-btn').map((b) => b.attributes('title'));
        const menu = (card) => card.findAll('.dd .dropdown-item').map((b) => b.text());

        expect(titles(cards[0])).toEqual(['Baixar PDF', 'Enviar ao fornecedor']);
        expect(menu(cards[0])).toEqual(['Editar', 'Cancelar pedido', 'Excluir']);
        expect(titles(cards[1])).toEqual(['Baixar PDF', 'Receber']);
        expect(menu(cards[1])).toEqual(['Cancelar pedido']);
        expect(titles(cards[2])).toEqual(['Baixar PDF']);
        expect(cards[2].find('.dd').exists()).toBe(false);
    });

    it('as ações emitem o pedido', async () => {
        const row = po({ status: 'sent', is_editable: false });
        const w = mountCards([row]);

        await w.find('button[title="Receber"]').trigger('click');
        await w.find('.dd .dropdown-item').trigger('click');

        expect(w.emitted('receive')[0]).toEqual([row]);
        expect(w.emitted('cancel')[0]).toEqual([row]);
        expect(w.find('button[title="Baixar PDF"]').attributes('data-href')).toBe('/po/p1/pdf');
    });

    it('mostra o estado vazio', () => {
        expect(mountCards([]).text()).toContain('Nenhum pedido de compra encontrado.');
    });
});
