import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import PurchaseOrdersIndex from '@/Pages/Panel/Stock/PurchaseOrders/Index.vue';

/**
 * Página de pedidos de compra no layout de Panel/Patients/Index: textos via
 * `t`, total, alternância tabela/cards persistida, busca + filtros de status/
 * fornecedor que preservam a ordenação, e as mesmas ações de antes
 * (enviar/cancelar/excluir com confirmação, editar rascunho, receber).
 */

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: { title: String, total: Number, totalLabel: String, view: String, showViewToggle: Boolean },
        emits: ['set-view'],
        template: `<div>
            <span class="total">{{ totalLabel }} {{ total }}</span>
            <span class="toggle" v-if="showViewToggle">{{ view }}</span>
            <button class="to-cards" @click="$emit('set-view', 'cards')" />
            <slot name="actions" />
        </div>`,
    },
}));
vi.mock('@/Components/Panel/SearchInput.vue', () => ({
    default: {
        props: ['modelValue', 'placeholder'],
        emits: ['update:modelValue'],
        template:
            '<input class="search" :placeholder="placeholder" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
    },
}));
const { listStub } = vi.hoisted(() => ({
    listStub: (cls) => ({
        default: {
            props: ['items', 't', 'filters', 'pdfUrlTemplate'],
            emits: ['sort', 'edit', 'send', 'receive', 'cancel', 'delete'],
            template: `<div class="${cls}">
            {{ items.data.length }}
            <button class="emit-sort" @click="$emit('sort', { sort: 'total_amount', direction: 'asc' })" />
            <button class="emit-edit" @click="$emit('edit', items.data[0])" />
            <button class="emit-send" @click="$emit('send', items.data[0])" />
            <button class="emit-receive" @click="$emit('receive', items.data[0])" />
            <button class="emit-cancel" @click="$emit('cancel', items.data[0])" />
            <button class="emit-delete" @click="$emit('delete', items.data[0])" />
        </div>`,
        },
    }),
}));
vi.mock('@/Pages/Panel/Stock/PurchaseOrders/PurchaseOrderTable.vue', () => listStub('table-stub'));
vi.mock('@/Pages/Panel/Stock/PurchaseOrders/PurchaseOrderCards.vue', () => listStub('cards-stub'));
vi.mock('@/Pages/Panel/Stock/PurchaseOrders/PurchaseOrderFormModal.vue', () => ({
    default: {
        props: ['open', 'item'],
        template: '<div class="form-modal" :data-open="String(open)">{{ item?.code }}</div>',
    },
}));
vi.mock('@/Pages/Panel/Stock/PurchaseOrders/ReceivePurchaseOrderModal.vue', () => ({
    default: {
        props: ['open', 'purchaseOrder'],
        template: '<div class="receive-modal" :data-open="String(open)">{{ purchaseOrder?.code }}</div>',
    },
}));

const t = {
    page_title: 'Purchase orders',
    total_label: 'Total:',
    btn_suppliers: 'Suppliers',
    btn_new: 'New order',
    search_placeholder: 'Search by code or supplier...',
    filter_status_label: 'Filter by status',
    filter_status_all: 'All statuses',
    filter_supplier_label: 'Filter by supplier',
    filter_supplier_all: 'All suppliers',
    filter_supplier_unlisted: 'Selected supplier',
    filter_supplier_inactive: ':name (inactive)',
    confirm_send: 'Send order :code to the supplier?',
    confirm_cancel: 'Cancel order :code?',
    confirm_delete: 'Delete draft :code?',
    load_error: 'Could not open the order.',
};

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

const suppliers = [{ id: '11111111-1111-4111-8111-111111111111', name: 'Alfa' }];

// Vem do backend já traduzido (PurchaseOrderStatus::label()).
const statuses = [
    { value: 'draft', label: 'Draft' },
    { value: 'sent', label: 'Sent to supplier' },
    { value: 'partially_received', label: 'Partially received' },
    { value: 'received', label: 'Received' },
    { value: 'cancelled', label: 'Cancelled' },
];

let wrapper;

beforeEach(() => {
    window.localStorage.clear();
    vi.mocked(router.get).mockClear();
    vi.mocked(router.post).mockClear();
    vi.mocked(router.delete).mockClear();
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    delete window.axios;
});

function mountPage(
    filters = { search: '', status: 'all', supplier_id: '', sort: 'order_date', direction: 'desc' },
    extraProps = {},
) {
    wrapper = mount(PurchaseOrdersIndex, {
        props: {
            items: { data: [{ id: 'p1', code: 'PC-1', status: 'draft', is_editable: true }], total: 7 },
            suppliers,
            statuses,
            ...extraProps,
            filters,
            routes,
            t,
        },
    });

    return wrapper;
}

const selects = (w) => w.findAll('select');

describe('PurchaseOrders/Index', () => {
    it('usa os textos traduzidos, mostra o total e os filtros de status/fornecedor', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Purchase orders');
        expect(w.find('.total').text()).toBe('Total: 7');
        expect(w.find('.toggle').exists()).toBe(true);
        expect(w.text()).toContain('Suppliers');
        expect(w.text()).toContain('New order');
        expect(w.find('.search').attributes('placeholder')).toBe('Search by code or supplier...');
        expect(selects(w)[0].attributes('aria-label')).toBe('Filter by status');
        expect(
            selects(w)[0]
                .findAll('option')
                .map((o) => o.text()),
        ).toEqual(['All statuses', 'Draft', 'Sent to supplier', 'Partially received', 'Received', 'Cancelled']);
        expect(
            selects(w)[1]
                .findAll('option')
                .map((o) => o.text()),
        ).toEqual(['All suppliers', 'Alfa']);
    });

    it('fornecedor filtrado fora da lista de ativos continua visível no seletor (rótulo neutro sem nome do backend)', () => {
        const w = mountPage({
            search: '',
            status: 'all',
            supplier_id: '22222222-2222-4222-8222-222222222222',
            sort: 'order_date',
            direction: 'desc',
        });

        expect(
            selects(w)[1]
                .findAll('option')
                .map((o) => o.text()),
        ).toEqual(['All suppliers', 'Selected supplier', 'Alfa']);
        expect(selects(w)[1].element.value).toBe('22222222-2222-4222-8222-222222222222');
    });

    it('fornecedor inativo filtrado aparece no seletor com o NOME enviado pelo backend', () => {
        const inactiveId = '33333333-3333-4333-8333-333333333333';
        const w = mountPage(
            { search: '', status: 'all', supplier_id: inactiveId, sort: 'order_date', direction: 'desc' },
            { selectedSupplier: { id: inactiveId, name: 'Gama Antigo' } },
        );

        expect(
            selects(w)[1]
                .findAll('option')
                .map((o) => o.text()),
        ).toEqual(['All suppliers', 'Gama Antigo (inactive)', 'Alfa']);
        expect(selects(w)[1].element.value).toBe(inactiveId);
    });

    it('nome do backend de OUTRO id não é usado para o fornecedor filtrado', () => {
        const w = mountPage(
            {
                search: '',
                status: 'all',
                supplier_id: '22222222-2222-4222-8222-222222222222',
                sort: 'order_date',
                direction: 'desc',
            },
            { selectedSupplier: { id: '33333333-3333-4333-8333-333333333333', name: 'Gama Antigo' } },
        );

        expect(
            selects(w)[1]
                .findAll('option')
                .map((o) => o.text()),
        ).toEqual(['All suppliers', 'Selected supplier', 'Alfa']);
    });

    it('alterna para cards (mesmos dados) e guarda a preferência no navegador', async () => {
        const w = mountPage();

        await w.find('.to-cards').trigger('click');

        expect(w.find('.table-stub').exists()).toBe(false);
        expect(w.find('.cards-stub').exists()).toBe(true);
        expect(window.localStorage.getItem('stock_purchase_orders_view')).toBe('cards');
    });

    it('a busca espera parar de digitar e preserva status, fornecedor e ordenação', async () => {
        vi.useFakeTimers();
        const w = mountPage({
            search: '',
            status: 'sent',
            supplier_id: suppliers[0].id,
            sort: 'total_amount',
            direction: 'asc',
        });

        await w.find('.search').setValue('PC-12');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/po',
            { search: 'PC-12', status: 'sent', supplier_id: suppliers[0].id, sort: 'total_amount', direction: 'asc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('trocar filtros preserva busca e ordenação; ordenar preserva busca e filtros', async () => {
        const w = mountPage({ search: 'alfa', status: 'all', supplier_id: '', sort: 'code', direction: 'desc' });

        await selects(w)[0].setValue('received');
        expect(router.get).toHaveBeenLastCalledWith(
            '/po',
            { search: 'alfa', status: 'received', supplier_id: '', sort: 'code', direction: 'desc' },
            expect.objectContaining({ preserveState: true }),
        );

        await selects(w)[1].setValue(suppliers[0].id);
        expect(router.get).toHaveBeenLastCalledWith(
            '/po',
            { search: 'alfa', status: 'received', supplier_id: suppliers[0].id, sort: 'code', direction: 'desc' },
            expect.objectContaining({ preserveState: true }),
        );

        await w.find('.emit-sort').trigger('click');
        expect(router.get).toHaveBeenLastCalledWith(
            '/po',
            {
                search: 'alfa',
                status: 'received',
                supplier_id: suppliers[0].id,
                sort: 'total_amount',
                direction: 'asc',
            },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('enviar, cancelar e excluir pedem confirmação traduzida com o código do pedido', async () => {
        const confirmSpy = vi.fn().mockReturnValue(true);
        vi.stubGlobal('confirm', confirmSpy);
        const w = mountPage();

        await w.find('.emit-send').trigger('click');
        await w.find('.emit-cancel').trigger('click');
        await w.find('.emit-delete').trigger('click');

        expect(confirmSpy.mock.calls.map((c) => c[0])).toEqual([
            'Send order PC-1 to the supplier?',
            'Cancel order PC-1?',
            'Delete draft PC-1?',
        ]);
        expect(router.post).toHaveBeenCalledWith('/po/p1/send', {}, expect.objectContaining({ preserveScroll: true }));
        expect(router.post).toHaveBeenCalledWith(
            '/po/p1/cancel',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(router.delete).toHaveBeenCalledWith('/po/p1', expect.objectContaining({ preserveScroll: true }));
    });

    it('não dispara a transição quando a confirmação é negada', async () => {
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(false));
        const w = mountPage();

        await w.find('.emit-send').trigger('click');
        await w.find('.emit-delete').trigger('click');

        expect(router.post).not.toHaveBeenCalled();
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('editar busca o pedido completo e abre o formulário; falha mostra aviso traduzido', async () => {
        window.axios = {
            get: vi.fn().mockResolvedValueOnce({ data: { data: { id: 'p1', code: 'PC-1', items: [] } } }),
        };
        const w = mountPage();

        await w.find('.emit-edit').trigger('click');
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledWith('/po/p1');
        expect(w.find('.form-modal').attributes('data-open')).toBe('true');
        expect(w.find('.form-modal').text()).toBe('PC-1');

        window.axios.get.mockRejectedValueOnce(new Error('500'));
        await w.find('.emit-edit').trigger('click');
        await flushPromises();

        expect(w.find('.alert-danger').text()).toContain('Could not open the order.');
    });

    it('receber abre o modal de recebimento com o pedido da linha', async () => {
        const w = mountPage();

        await w.find('.emit-receive').trigger('click');

        expect(w.find('.receive-modal').attributes('data-open')).toBe('true');
        expect(w.find('.receive-modal').text()).toBe('PC-1');
    });
});
