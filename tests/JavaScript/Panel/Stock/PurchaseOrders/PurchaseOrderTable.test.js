import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import PurchaseOrderTable from '@/Pages/Panel/Stock/PurchaseOrders/PurchaseOrderTable.vue';

/**
 * Tabela de pedidos de compra no padrão de PatientTable: colunas/ordem
 * persistida, ordenação via SortableTh só nas chaves da whitelist do
 * backend, moeda/data no idioma do usuário e ações com EXATAMENTE o mesmo
 * gating por status da tela anterior (App\Enums\PurchaseOrderStatus).
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: {
        props: ['title'],
        template: '<div class="dd" :data-title="title"><slot name="trigger" /><slot /></div>',
    },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'href', 'variant'],
        emits: ['click'],
        template:
            '<button type="button" class="icon-btn" :title="title" :data-href="href" :data-variant="variant" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_code: 'Código',
    col_supplier: 'Fornecedor',
    col_order_date: 'Data',
    col_expected_delivery: 'Previsão de entrega',
    col_total: 'Total',
    col_status: 'Status',
    col_actions: 'Ações',
    sort_by: 'Ordenar por :column',
    action_pdf: 'Baixar PDF',
    action_send: 'Enviar ao fornecedor',
    action_receive: 'Receber',
    action_edit: 'Editar',
    action_cancel: 'Cancelar pedido',
    action_delete: 'Excluir',
    more_actions: 'Mais ações',
    empty_list: 'Nenhum pedido de compra encontrado.',
};

let wrapper;

beforeEach(() => window.localStorage.clear());
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
        expected_delivery_date: null,
        total_amount: 1234.5,
        ...overrides,
    };
}

function mountTable(rows = [po()], filters = { sort: 'order_date', direction: 'desc' }) {
    wrapper = mount(PurchaseOrderTable, {
        props: {
            items: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] },
            filters,
            t,
            pdfUrlTemplate: '/po/__ID__/pdf',
        },
    });

    return wrapper;
}

const headerLabels = (w) => w.findAll('thead th').map((th) => th.text());
const normalizeSpaces = (s) => s.replace(/\s/g, ' ');
const actionTitles = (w) => w.findAll('tbody .icon-btn').map((b) => b.attributes('title'));
const menuItems = (w) => w.findAll('tbody .dd .dropdown-item').map((b) => b.text());

describe('PurchaseOrderTable', () => {
    it('mostra as colunas na ordem padrão com Status/Ações no fim', () => {
        expect(headerLabels(mountTable())).toEqual([
            'Código',
            'Fornecedor',
            'Data',
            'Previsão de entrega',
            'Total',
            'Status',
            'Ações',
        ]);
    });

    it('respeita a ordem de colunas salva no navegador', () => {
        window.localStorage.setItem(
            'stock_purchase_orders_columns_order',
            JSON.stringify(['fornecedor', 'codigo', 'data', 'previsao', 'total']),
        );

        expect(headerLabels(mountTable()).slice(0, 2)).toEqual(['Fornecedor', 'Código']);
    });

    it('ordena via SortableTh só nas colunas da whitelist', async () => {
        const w = mountTable();
        const ths = w.findAll('thead th');

        expect(ths[2].attributes('aria-sort')).toBe('descending');
        expect(ths[0].attributes('aria-sort')).toBe('none');
        expect(ths[3].find('button').exists()).toBe(false); // previsão: não ordenável
        expect(ths[4].find('button').attributes('title')).toBe('Ordenar por Total');

        await ths[2].find('button').trigger('click');
        await ths[4].find('button').trigger('click');
        await ths[1].find('button').trigger('click');

        expect(w.emitted('sort')).toEqual([
            [{ sort: 'order_date', direction: 'asc' }],
            [{ sort: 'total_amount', direction: 'asc' }],
            [{ sort: 'supplier_name', direction: 'asc' }],
        ]);
    });

    it('formata moeda e datas no idioma do usuário (sem R$/toFixed fixos)', () => {
        const w = mountTable([po({ expected_delivery_date: '2026-09-15' }), po({ id: 'p2', total_amount: 0 })]);
        const rows = w.findAll('tbody tr');

        expect(normalizeSpaces(rows[0].find('[data-col="total"]').text())).toBe('R$ 1.234,50');
        expect(rows[0].find('[data-col="data"]').text()).toBe('01/09/2026');
        expect(rows[0].find('[data-col="previsao"]').text()).toBe('15/09/2026');
        expect(rows[1].find('[data-col="previsao"]').text()).toBe('—');
        expect(normalizeSpaces(rows[1].find('[data-col="total"]').text())).toBe('R$ 0,00');
    });

    it.each([
        ['draft', 'badge-soft-secondary', 'Rascunho'],
        ['sent', 'badge-soft-info', 'Enviado ao fornecedor'],
        ['partially_received', 'badge-soft-warning', 'Recebido parcialmente'],
        ['received', 'badge-soft-success', 'Recebido'],
        ['cancelled', 'badge-soft-danger', 'Cancelado'],
    ])(
        'status %s usa badge semântico com o rótulo traduzido do backend (status_label)',
        (status, badgeClass, label) => {
            const badge = mountTable([po({ status, status_label: label })]).find('tbody .badge');

            expect(badge.text()).toBe(label);
            expect(badge.classes()).toEqual(expect.arrayContaining([badgeClass, 'border', 'rounded']));
        },
    );

    it.each([
        ['draft', true, ['Baixar PDF', 'Enviar ao fornecedor'], ['Editar', 'Cancelar pedido', 'Excluir']],
        ['sent', false, ['Baixar PDF', 'Receber'], ['Cancelar pedido']],
        ['partially_received', false, ['Baixar PDF', 'Receber'], ['Cancelar pedido']],
        ['received', false, ['Baixar PDF'], []],
        ['cancelled', false, ['Baixar PDF'], []],
    ])('status %s: mesmas ações liberadas que antes', (status, isEditable, icons, menu) => {
        const w = mountTable([po({ status, is_editable: isEditable })]);

        expect(actionTitles(w)).toEqual(icons);
        expect(menuItems(w)).toEqual(menu);
        expect(w.find('tbody .dd').exists()).toBe(menu.length > 0);
    });

    it('PDF aponta para a rota do pedido e as ações emitem a linha', async () => {
        const row = po();
        const w = mountTable([row]);

        expect(w.find('button[title="Baixar PDF"]').attributes('data-href')).toBe('/po/p1/pdf');
        expect(w.find('button[title="Enviar ao fornecedor"]').attributes('data-variant')).toBe('info');
        expect(w.find('tbody .dd').attributes('data-title')).toBe('Mais ações');

        await w.find('button[title="Enviar ao fornecedor"]').trigger('click');
        const [edit, cancel, remove] = w.findAll('tbody .dd .dropdown-item');
        await edit.trigger('click');
        await cancel.trigger('click');
        await remove.trigger('click');

        expect(w.emitted('send')[0]).toEqual([row]);
        expect(w.emitted('edit')[0]).toEqual([row]);
        expect(w.emitted('cancel')[0]).toEqual([row]);
        expect(w.emitted('delete')[0]).toEqual([row]);
    });

    it('receber emite a linha', async () => {
        const row = po({ status: 'sent', is_editable: false });
        const w = mountTable([row]);

        await w.find('button[title="Receber"]').trigger('click');

        expect(w.emitted('receive')[0]).toEqual([row]);
    });

    it('mostra o estado vazio ocupando todas as colunas', () => {
        const td = mountTable([]).find('tbody td');

        expect(td.text()).toBe('Nenhum pedido de compra encontrado.');
        expect(td.attributes('colspan')).toBe('7');
    });
});
