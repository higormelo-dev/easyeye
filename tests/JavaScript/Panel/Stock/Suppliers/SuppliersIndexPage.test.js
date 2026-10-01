import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import SuppliersIndex from '@/Pages/Panel/Stock/Suppliers/Index.vue';

/**
 * Página de fornecedores no layout de Panel/Patients/Index: textos via `t`,
 * total no cabeçalho, alternância tabela/cards persistida e busca/filtro/
 * ordenação que preservam uns aos outros.
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
vi.mock('@/Pages/Panel/Stock/Suppliers/SupplierTable.vue', () => ({
    default: {
        props: ['items', 't', 'filters', 'purchaseOrdersUrl'],
        emits: ['sort', 'edit', 'delete'],
        template: `<div class="table-stub">
            {{ items.data.length }}
            <button class="emit-sort" @click="$emit('sort', { sort: 'document', direction: 'desc' })" />
            <button class="emit-delete" @click="$emit('delete', items.data[0])" />
        </div>`,
    },
}));
vi.mock('@/Pages/Panel/Stock/Suppliers/SupplierCards.vue', () => ({
    default: { props: ['items', 't'], template: '<div class="cards-stub">{{ items.data.length }}</div>' },
}));
vi.mock('@/Pages/Panel/Stock/Suppliers/SupplierFormModal.vue', () => ({
    default: { props: ['open', 'item'], template: '<div />' },
}));

const t = {
    page_title: 'Suppliers',
    total_label: 'Total:',
    btn_purchase_orders: 'Purchase orders',
    btn_new: 'New supplier',
    search_placeholder: 'Search by name...',
    filter_status_label: 'Filter by status',
    filter_status_all: 'All',
    filter_status_active: 'Active',
    filter_status_inactive: 'Inactive',
    confirm_delete: 'Delete supplier ":name"?',
};

const routes = { index: '/s', store: '/s', update: '/s/__ID__', destroy: '/s/__ID__', purchase_orders_index: '/po' };

let wrapper;

beforeEach(() => {
    window.localStorage.clear();
    vi.mocked(router.get).mockClear();
    vi.mocked(router.delete).mockClear();
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

function mountPage(filters = { search: '', status: 'all', sort: 'name', direction: 'asc' }) {
    wrapper = mount(SuppliersIndex, {
        props: {
            items: {
                data: [
                    { id: 's1', name: 'Alfa' },
                    { id: 's2', name: 'Beta' },
                ],
                total: 42,
            },
            filters,
            routes,
            t,
        },
    });

    return wrapper;
}

describe('Suppliers/Index', () => {
    it('usa os textos traduzidos, mostra o total geral e o seletor de status', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Suppliers');
        expect(w.find('.total').text()).toBe('Total: 42');
        expect(w.find('.toggle').exists()).toBe(true);
        expect(w.text()).toContain('Purchase orders');
        expect(w.text()).toContain('New supplier');
        expect(w.find('.search').attributes('placeholder')).toBe('Search by name...');
        expect(w.find('select').attributes('aria-label')).toBe('Filter by status');
        expect(w.findAll('select option').map((o) => o.text())).toEqual(['All', 'Active', 'Inactive']);
    });

    it('alterna para cards (mesmos dados) e guarda a preferência no navegador', async () => {
        const w = mountPage();

        await w.find('.to-cards').trigger('click');

        expect(w.find('.table-stub').exists()).toBe(false);
        expect(w.find('.cards-stub').text()).toBe('2');
        expect(window.localStorage.getItem('stock_suppliers_view')).toBe('cards');
    });

    it('abre em cards quando essa foi a última preferência', () => {
        window.localStorage.setItem('stock_suppliers_view', 'cards');

        expect(mountPage().find('.cards-stub').exists()).toBe(true);
    });

    it('a busca espera parar de digitar e preserva status e ordenação', async () => {
        vi.useFakeTimers();
        const w = mountPage({ search: '', status: 'active', sort: 'code', direction: 'desc' });

        await w.find('.search').setValue('alfa');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/s',
            { search: 'alfa', status: 'active', sort: 'code', direction: 'desc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('trocar o status preserva busca e ordenação', async () => {
        const w = mountPage({ search: 'alfa', status: 'all', sort: 'code', direction: 'desc' });

        await w.find('select').setValue('inactive');

        expect(router.get).toHaveBeenCalledWith(
            '/s',
            { search: 'alfa', status: 'inactive', sort: 'code', direction: 'desc' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('ordenar pela tabela preserva busca e status', async () => {
        const w = mountPage({ search: 'alfa', status: 'active', sort: 'name', direction: 'asc' });

        await w.find('.emit-sort').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/s',
            { search: 'alfa', status: 'active', sort: 'document', direction: 'desc' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('excluir pede confirmação com o texto traduzido e só remove se confirmado', async () => {
        const confirmSpy = vi.fn().mockReturnValueOnce(false).mockReturnValueOnce(true);
        vi.stubGlobal('confirm', confirmSpy);
        const w = mountPage();

        await w.find('.emit-delete').trigger('click');
        expect(router.delete).not.toHaveBeenCalled();

        await w.find('.emit-delete').trigger('click');

        expect(confirmSpy).toHaveBeenCalledWith('Delete supplier "Alfa"?');
        expect(router.delete).toHaveBeenCalledWith('/s/s1', expect.objectContaining({ preserveScroll: true }));
    });
});
