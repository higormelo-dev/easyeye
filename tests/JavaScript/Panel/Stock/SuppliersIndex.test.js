import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import SuppliersIndex from '@/Pages/Panel/Stock/Suppliers/Index.vue';

/**
 * Listagem de fornecedores: documento/telefone chegam já formatados do
 * SuppliersController (document_display/phone_display via BrazilianFormat).
 * A tela imprime como veio — sem re-mascarar, para não mutilar legado
 * (telefone com ramal, 4004-0001) nem sumir com documento em texto livre.
 *
 * A tabela agora vive em SupplierTable (padrão de pacientes, com ordem de
 * colunas personalizável) — as células são localizadas pela coluna
 * (`data-col`), não pela posição, que depende da preferência do usuário.
 */

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({ default: { template: '<div><slot name="actions" /></div>' } }));
vi.mock('@/Components/Panel/SearchInput.vue', () => ({ default: { template: '<input>' } }));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({ default: { template: '<button />' } }));
vi.mock('@/Pages/Panel/Stock/Suppliers/SupplierFormModal.vue', () => ({ default: { template: '<div />' } }));

const routes = { index: '/s', store: '/s', update: '/s/__ID__', destroy: '/s/__ID__', purchase_orders_index: '/po' };

let wrapper;

beforeEach(() => window.localStorage.clear());
afterEach(() => wrapper?.unmount());

function mountWith(rows) {
    wrapper = mount(SuppliersIndex, {
        props: { items: { data: rows, total: rows.length }, routes },
    });

    return wrapper;
}

function row(overrides) {
    return {
        id: '1', code: 'FOR-1', name: 'Fornecedor', contact_name: null, active: true,
        document: null, phone: null, document_display: null, phone_display: null,
        ...overrides,
    };
}

function cells(w) {
    return {
        document: w.find('tbody td[data-col="documento"]').text(),
        phone:    w.find('tbody td[data-col="telefone"]').text(),
    };
}

describe('Suppliers/Index — exibição de documento e telefone', () => {
    it('imprime os valores formatados pelo backend', () => {
        const w = mountWith([row({
            document: '12345678000199', document_display: '12.345.678/0001-99',
            phone: '6133334444', phone_display: '(61) 3333-4444',
        })]);

        expect(cells(w)).toEqual({ document: '12.345.678/0001-99', phone: '(61) 3333-4444' });
    });

    it('não mutila legado com quantidade de dígitos inesperada', () => {
        const w = mountWith([row({
            document: 'ISENTO', document_display: 'ISENTO',
            phone: '(61) 3333-4444 r.21', phone_display: '(61) 3333-4444 r.21',
        })]);

        expect(cells(w)).toEqual({ document: 'ISENTO', phone: '(61) 3333-4444 r.21' });
    });

    it('mostra travessão quando não há documento nem telefone', () => {
        const w = mountWith([row({})]);

        expect(cells(w)).toEqual({ document: '—', phone: '—' });
    });
});
