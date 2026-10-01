import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PurchaseOrdersIndex from '@/Pages/Panel/Stock/PurchaseOrders/Index.vue';

/**
 * Aviso de sucesso (flash.message compartilhado pelo HandleInertiaRequests),
 * barra de busca e filtro de status da listagem de pedidos de compra:
 *  - fechar o aviso é estado local do Vue (sem data-bs-dismiss) e ele volta
 *    numa nova resposta com flash, mesmo com o texto repetido;
 *  - o SearchInput recebe wrapper-class="" (sem o `mb-3` padrão nem classe
 *    de tamanho própria — mesma barra de Produtos/Lentes);
 *  - as opções de status vêm da prop `statuses` (rótulos do backend).
 */

// usePage() reativo (o mock global devolve um objeto fixo).
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    const page = reactive({ props: { flash: {}, errors: {} } });

    return {
        usePage: () => page,
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), reload: vi.fn() },
        Link: { template: '<a><slot /></a>', props: ['href'] },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({ default: { template: '<div><slot name="actions" /></div>' } }));
vi.mock('@/Pages/Panel/Stock/PurchaseOrders/PurchaseOrderTable.vue', () => ({
    default: { template: '<div class="table-stub" />' },
}));
vi.mock('@/Pages/Panel/Stock/PurchaseOrders/PurchaseOrderCards.vue', () => ({
    default: { template: '<div class="cards-stub" />' },
}));
vi.mock('@/Pages/Panel/Stock/PurchaseOrders/PurchaseOrderFormModal.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Stock/PurchaseOrders/ReceivePurchaseOrderModal.vue', () => ({
    default: { template: '<div />' },
}));

const routes = {
    index: '/po',
    show: '/po/__ID__',
    send: '/po/__ID__/send',
    cancel: '/po/__ID__/cancel',
    destroy: '/po/__ID__',
    pdf: '/po/__ID__/pdf',
    suppliers_index: '/s',
    store: '/po',
    update: '/po/__ID__',
    receive: '/po/__ID__/receive',
};
const t = { close: 'Close', search_placeholder: 'Search orders', filter_status_all: 'All statuses' };
const statuses = [
    { value: 'draft', label: 'Draft' },
    { value: 'sent', label: 'Sent to supplier' },
];

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    usePage().props.flash = {};
    window.localStorage.clear();
});

function mountPage() {
    wrapper = mount(PurchaseOrdersIndex, {
        props: {
            items: { data: [], total: 0 },
            statuses,
            filters: { search: '', status: 'all', supplier_id: '', sort: 'order_date', direction: 'desc' },
            routes,
            t,
        },
    });

    return wrapper;
}

describe('PurchaseOrders/Index — aviso de sucesso', () => {
    it('mostra o flash e fecha por estado local, sem data-bs-dismiss', async () => {
        usePage().props.flash = { message: 'Pedido enviado.' };
        const w = mountPage();

        const close = w.find('.alert-success button.btn-close');
        expect(w.find('.alert-success').text()).toContain('Pedido enviado.');
        expect(close.attributes('aria-label')).toBe('Close');
        expect(close.attributes('data-bs-dismiss')).toBeUndefined();

        await close.trigger('click');
        expect(w.find('.alert-success').exists()).toBe(false);
    });

    it('volta a mostrar numa nova resposta com flash — mesmo com o texto repetido', async () => {
        usePage().props.flash = { message: 'Pedido cancelado.' };
        const w = mountPage();

        await w.find('.alert-success button.btn-close').trigger('click');
        usePage().props.flash = { message: 'Pedido cancelado.' };
        await nextTick();

        expect(w.find('.alert-success').text()).toContain('Pedido cancelado.');
    });
});

describe('PurchaseOrders/Index — barra de filtros', () => {
    it('SearchInput usa wrapper-class vazio (sem mb-3) e rótulo acessível', () => {
        const input = mountPage().find('input[aria-label="Search orders"]');
        expect(input.exists()).toBe(true);

        // input → .input-group → wrapper do SearchInput
        const wrapperDiv = input.element.parentElement.parentElement;
        expect(input.element.parentElement.classList.contains('input-group')).toBe(true);
        expect(wrapperDiv.className).toBe('');
        expect(input.element.parentElement.style.maxWidth).toBe('280px');
    });

    it('opções de status vêm da prop `statuses` (rótulos traduzidos pelo backend)', () => {
        const options = mountPage().findAll('select')[0].findAll('option');

        expect(options.map((o) => o.text())).toEqual(['All statuses', 'Draft', 'Sent to supplier']);
        expect(options.map((o) => o.attributes('value'))).toEqual(['all', 'draft', 'sent']);
    });
});
