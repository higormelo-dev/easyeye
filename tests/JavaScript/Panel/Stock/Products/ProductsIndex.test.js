import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import ProductsIndex from '@/Pages/Panel/Stock/Products/Index.vue';

/**
 * Página de produtos no layout de Panel/Patients/Index: total no cabeçalho,
 * alternância tabela/cards persistida (cards com o mesmo paginator), textos
 * via `t`, busca com debounce que preserva ordenação e filtros do estoque, e
 * ações de ativar/desativar (com os dados ATUAIS do produto, via routes.show)
 * e excluir.
 */

// usePage reativo (o mock global de setup.js devolve props fixas): o alerta
// de flash precisa reagir a uma visita nova.
const inertia = vi.hoisted(() => ({ pageProps: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.pageProps = reactive({ flash: {}, errors: {} });

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn() },
        Link: { template: '<a><slot /></a>', props: ['href'] },
        Head: { template: '<div><slot /></div>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'totalLabel', 'view', 'showViewToggle'],
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
        props: ['modelValue', 'placeholder', 'clearLabel', 'wrapperClass', 'maxWidth'],
        emits: ['update:modelValue'],
        template: '<input class="search" :placeholder="placeholder" :data-clear-label="clearLabel" :data-wrapper-class="wrapperClass" :data-max-width="maxWidth" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
    },
}));
vi.mock('@/Pages/Panel/Stock/Products/ProductTable.vue', () => ({
    default: {
        props: ['items', 'filters', 't', 'movementsIndexUrl'],
        emits: ['sort', 'edit', 'toggleActive', 'delete'],
        template: `<div class="table-stub">{{ items.data.length }}
            <button class="sort" @click="$emit('sort', { sort: 'qty_on_hand', direction: 'desc' })" />
            <button class="toggle-row" @click="$emit('toggleActive', items.data[0])" />
            <button class="delete-row" @click="$emit('delete', items.data[0])" />
        </div>`,
    },
}));
vi.mock('@/Pages/Panel/Stock/Products/ProductCards.vue', () => ({
    default: { props: ['items', 't'], template: '<div class="cards-stub">{{ items.data.map((p) => p.name).join(",") }}</div>' },
}));
vi.mock('@/Pages/Panel/Stock/Products/ProductFormModal.vue', () => ({ default: { template: '<div />' } }));

const t = {
    page_title: 'Products', total_label: 'Total:', btn_movements: 'Movements', btn_import: 'Import',
    btn_new: 'New product', search_placeholder: 'Search by name...', filter_status_all: 'All',
    filter_status_active: 'Active', filter_status_inactive: 'Inactive', category_all: 'All categories',
    filter_low_stock: 'Below minimum only', filter_expiring_lots: 'Lot expiring only (30d)',
    confirm_delete: 'Delete the product ":name"?', search_clear: 'Clear search',
    toggle_error: 'Could not load the current product data.', close: 'Close',
};

const routes = {
    index: '/stock/products',
    show: '/stock/products/__ID__/show',
    update: '/stock/products/__ID__',
    destroy: '/stock/products/__ID__',
    movements_index: '/stock/movements',
    import_index: '/stock/products/import',
};

const baseFilters = { search: '', status: 'all', category_id: '', low_stock: false, expiring_lots: false, sort: 'name', direction: 'asc' };

let wrapper;

beforeEach(() => {
    inertia.pageProps.flash = {};
    window.localStorage.clear();
    vi.mocked(router.get).mockClear();
    vi.mocked(router.put).mockClear();
    vi.mocked(router.delete).mockClear();
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

function mountPage(filters = baseFilters) {
    wrapper = mount(ProductsIndex, {
        props: {
            items: { data: [{ id: 'p1', name: 'Colírio', unit: 'un', active: true }, { id: 'p2', name: 'Seringa', unit: 'un', active: false }], total: 42 },
            categories: [{ id: 'c1', name: 'Colírios' }],
            filters,
            routes,
            t,
        },
    });

    return wrapper;
}

describe('Stock/Products/Index', () => {
    it('usa os textos traduzidos, o total do paginator e mantém o link de importação', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Products');
        expect(w.find('.total').text()).toBe('Total: 42');
        expect(w.text()).toContain('Movements');
        expect(w.text()).toContain('Import');
        expect(w.text()).toContain('New product');
        expect(w.find('.search').attributes('placeholder')).toBe('Search by name...');
        expect(w.find('.search').attributes('data-clear-label')).toBe('Clear search');
        expect(w.text()).toContain('All categories');
        expect(w.text()).toContain('Below minimum only');
    });

    it('mostra o flash de sucesso e o fecha com estado local (sem data-bs-dismiss); um flash novo reaparece', async () => {
        inertia.pageProps.flash = { message: 'Produto atualizado com sucesso!' };
        const w = mountPage();

        const alert = w.find('[role="status"]');
        expect(alert.text()).toContain('Produto atualizado com sucesso!');
        const close = alert.find('button[aria-label="Close"]');
        expect(close.attributes('data-bs-dismiss')).toBeUndefined();

        await close.trigger('click');
        expect(w.find('[role="status"]').exists()).toBe(false);

        // Outra visita com o MESMO texto (ex.: duas edições seguidas) traz outro objeto flash.
        inertia.pageProps.flash = { message: 'Produto atualizado com sucesso!' };
        await nextTick();
        expect(w.find('[role="status"]').text()).toContain('Produto atualizado com sucesso!');
    });

    it('sem flash não mostra alerta de sucesso', () => {
        const w = mountPage();

        expect(w.find('[role="status"]').exists()).toBe(false);
    });

    it('a busca divide a linha com os filtros sem a margem própria do SearchInput', () => {
        const w = mountPage();

        expect(w.find('.search').attributes('data-wrapper-class')).toBe('');
        expect(w.find('.search').attributes('data-max-width')).toBe('280px');
        expect(w.findAll('select')[0].findAll('option').map((o) => o.text())).toEqual(['All', 'Active', 'Inactive']);
    });

    it('começa na tabela e alterna para cards (mesmo paginator), guardando a preferência', async () => {
        const w = mountPage();

        expect(w.find('.table-stub').exists()).toBe(true);
        await w.find('.to-cards').trigger('click');

        expect(w.find('.table-stub').exists()).toBe(false);
        expect(w.find('.cards-stub').text()).toBe('Colírio,Seringa');
        expect(window.localStorage.getItem('stock_products_view')).toBe('cards');
    });

    it('abre em cards quando essa é a preferência salva', () => {
        window.localStorage.setItem('stock_products_view', 'cards');
        const w = mountPage();

        expect(w.find('.cards-stub').exists()).toBe(true);
    });

    it('a busca espera parar de digitar e preserva ordenação e filtros', async () => {
        vi.useFakeTimers();
        const w = mountPage({ ...baseFilters, status: 'active', category_id: 'c1', low_stock: true, sort: 'qty_on_hand', direction: 'desc' });

        await w.find('.search').setValue('colirio');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledTimes(1);
        expect(router.get).toHaveBeenCalledWith(
            '/stock/products',
            { search: 'colirio', status: 'active', category_id: 'c1', low_stock: 1, expiring_lots: 0, sort: 'qty_on_hand', direction: 'desc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('trocar um filtro aplica na hora, mantendo a ordenação', async () => {
        const w = mountPage({ ...baseFilters, sort: 'sale_price', direction: 'desc' });

        await w.find('select').setValue('inactive');
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/stock/products',
            expect.objectContaining({ status: 'inactive', sort: 'sale_price', direction: 'desc' }),
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('ordenar pela tabela mantém busca e filtros atuais', async () => {
        const w = mountPage({ ...baseFilters, search: 'col', expiring_lots: true });

        await w.find('.sort').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/stock/products',
            { search: 'col', status: 'all', category_id: '', low_stock: 0, expiring_lots: 1, sort: 'qty_on_hand', direction: 'desc' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('ativar/desativar busca o produto atual e envia só nome, unidade e o status inverso ao da linha', async () => {
        // Outra pessoa renomeou o produto (e até já o desativou) depois que a página abriu.
        const get = vi.fn().mockResolvedValue({ data: { data: { id: 'p1', name: 'Colírio 10ml', unit: 'fr', active: false } } });
        vi.stubGlobal('axios', { get });
        const w = mountPage();

        await w.find('.toggle-row').trigger('click');
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/stock/products/p1/show');
        expect(router.put).toHaveBeenCalledWith(
            '/stock/products/p1',
            { name: 'Colírio 10ml', unit: 'fr', active: false },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    it('ativar/desativar não envia nada e mostra o erro traduzido se não conseguir ler o produto atual', async () => {
        vi.stubGlobal('axios', { get: vi.fn().mockRejectedValue(new Error('404')) });
        const w = mountPage();

        await w.find('.toggle-row').trigger('click');
        await flushPromises();

        expect(router.put).not.toHaveBeenCalled();
        const alert = w.find('[role="alert"]');
        expect(alert.text()).toContain('Could not load the current product data.');

        await alert.find('button[aria-label="Close"]').trigger('click');
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    it('excluir pede confirmação com o texto traduzido e só exclui se confirmado', async () => {
        const confirmSpy = vi.fn().mockReturnValueOnce(false).mockReturnValueOnce(true);
        vi.stubGlobal('confirm', confirmSpy);
        const w = mountPage();

        await w.find('.delete-row').trigger('click');
        expect(confirmSpy).toHaveBeenCalledWith('Delete the product "Colírio"?');
        expect(router.delete).not.toHaveBeenCalled();

        await w.find('.delete-row').trigger('click');
        expect(router.delete).toHaveBeenCalledWith('/stock/products/p1', expect.objectContaining({ preserveScroll: true }));
    });
});
