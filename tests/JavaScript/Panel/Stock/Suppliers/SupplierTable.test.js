import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import SupplierTable from '@/Pages/Panel/Stock/Suppliers/SupplierTable.vue';

/**
 * Tabela de fornecedores no padrão de PatientTable: colunas na ordem padrão
 * (ou na salva no navegador), cabeçalhos ordenáveis via SortableTh (acessível,
 * com aria-sort), status/ações fixos no fim e estado vazio.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dd" :data-title="title"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'href', 'inertiaHref', 'variant'],
        emits: ['click'],
        template: '<button type="button" class="icon-btn" :title="title" :data-href="inertiaHref ?? href" :data-variant="variant" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    col_name: 'Nome', col_phone: 'Telefone', col_document: 'Documento', col_contact: 'Contato',
    col_email: 'E-mail', col_code: 'Código', col_status: 'Status', col_actions: 'Ações',
    sort_by: 'Ordenar por :column', status_active: 'Ativo', status_inactive: 'Inativo',
    action_purchase_orders: 'Pedidos de compra deste fornecedor', action_edit: 'Editar',
    action_delete: 'Excluir', more_actions: 'Mais ações', empty_list: 'Nenhum fornecedor encontrado.',
};

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function supplier(overrides = {}) {
    return {
        id: 's1', code: 'FOR-0000000001', name: 'Alfa Óptica', active: true,
        document: '12345678000199', document_display: '12.345.678/0001-99',
        phone: '6133334444', phone_display: '(61) 3333-4444',
        contact_name: 'Ana', email: 'contato@alfa.test', ...overrides,
    };
}

function mountTable(rows = [supplier()], filters = { sort: 'name', direction: 'asc' }) {
    wrapper = mount(SupplierTable, {
        props: {
            items: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] },
            filters,
            t,
            purchaseOrdersUrl: '/po',
        },
    });

    return wrapper;
}

const headerLabels = (w) => w.findAll('thead th').map((th) => th.text());

describe('SupplierTable', () => {
    it('mostra as colunas na ordem padrão (Nome e Telefone primeiro, como em pacientes) e Status/Ações no fim', () => {
        expect(headerLabels(mountTable())).toEqual(['Nome', 'Telefone', 'Documento', 'Contato', 'E-mail', 'Código', 'Status', 'Ações']);
    });

    it('respeita a ordem de colunas salva no navegador', () => {
        window.localStorage.setItem(
            'stock_suppliers_columns_order',
            JSON.stringify(['codigo', 'nome', 'telefone', 'documento', 'contato', 'email']),
        );

        expect(headerLabels(mountTable()).slice(0, 2)).toEqual(['Código', 'Nome']);
    });

    it('ordena pelo cabeçalho via SortableTh: inverte a coluna atual e começa ascendente nas outras', async () => {
        const w = mountTable([supplier()], { sort: 'name', direction: 'asc' });
        const ths = w.findAll('thead th');

        expect(ths[0].attributes('aria-sort')).toBe('ascending');
        expect(ths[2].attributes('aria-sort')).toBe('none');
        expect(ths[0].find('button').attributes('title')).toBe('Ordenar por Nome');

        await ths[0].find('button').trigger('click');
        await ths[2].find('button').trigger('click');

        expect(w.emitted('sort')).toEqual([
            [{ sort: 'name', direction: 'desc' }],
            [{ sort: 'document', direction: 'asc' }],
        ]);
    });

    it('status e ações não são ordenáveis', () => {
        const ths = mountTable().findAll('thead th');

        expect(ths[6].attributes('aria-sort')).toBeUndefined();
        expect(ths[7].find('button').exists()).toBe(false);
    });

    it('imprime documento/telefone formatados pelo backend e travessão quando vazio', () => {
        const w = mountTable([
            supplier(),
            supplier({ id: 's2', document_display: null, phone_display: null, contact_name: null, email: null }),
        ]);
        const rows = w.findAll('tbody tr');

        expect(rows[0].find('[data-col="documento"]').text()).toBe('12.345.678/0001-99');
        expect(rows[0].find('[data-col="telefone"]').text()).toBe('(61) 3333-4444');
        expect(rows[1].find('[data-col="documento"]').text()).toBe('—');
        expect(rows[1].find('[data-col="contato"]').text()).toBe('—');
        expect(rows[1].find('[data-col="email"]').text()).toBe('—');
    });

    it('status com badge no padrão de pacientes', () => {
        const w = mountTable([supplier(), supplier({ id: 's2', active: false })]);
        const badges = w.findAll('tbody .badge');

        expect(badges[0].text()).toBe('Ativo');
        expect(badges[0].classes()).toEqual(expect.arrayContaining(['badge-soft-success', 'text-success', 'border-success']));
        expect(badges[1].text()).toBe('Inativo');
        expect(badges[1].classes()).toEqual(expect.arrayContaining(['badge-soft-danger', 'text-danger']));
    });

    it('atalho para os pedidos do fornecedor e menu com editar/excluir emitindo a linha', async () => {
        const row = supplier();
        const w = mountTable([row]);

        const shortcut = w.find('button[title="Pedidos de compra deste fornecedor"]');
        expect(shortcut.attributes('data-href')).toBe('/po?supplier_id=s1');
        expect(shortcut.attributes('data-variant')).toBe('info');
        expect(w.find('tbody .dd').attributes('data-title')).toBe('Mais ações');

        const [edit, remove] = w.findAll('tbody .dd .dropdown-item');
        await edit.trigger('click');
        await remove.trigger('click');

        expect(edit.text()).toBe('Editar');
        expect(remove.text()).toBe('Excluir');
        expect(w.emitted('edit')[0]).toEqual([row]);
        expect(w.emitted('delete')[0]).toEqual([row]);
    });

    it('mostra o estado vazio ocupando todas as colunas', () => {
        const td = mountTable([]).find('tbody td');

        expect(td.text()).toBe('Nenhum fornecedor encontrado.');
        expect(td.attributes('colspan')).toBe('8');
    });
});
