import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import MovementsIndex from '@/Pages/Panel/Stock/Movements/Index.vue';

/**
 * Página do extrato no layout de Panel/Patients/Index: total no cabeçalho,
 * alternância tabela/cards persistida, textos via `t`, busca/filtros/ordenação
 * que preservam uns aos outros e o alerta de flash com fechar local.
 */

// usePage reativo (o mock global devolve um objeto novo e inerte a cada chamada):
// o alerta de flash precisa reagir a uma nova resposta do servidor.
const inertia = vi.hoisted(() => ({ page: null }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.page = reactive({ props: { flash: {} } });

    return {
        usePage: () => inertia.page,
        router: { get: vi.fn(), reload: vi.fn() },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'totalLabel', 'view', 'showViewToggle'],
        emits: ['set-view'],
        template: `<div>
            <span class="total">{{ totalLabel }} {{ total }}</span>
            <button class="to-cards" @click="$emit('set-view', 'cards')" />
            <slot name="actions" />
        </div>`,
    },
}));
vi.mock('@/Components/Panel/SearchInput.vue', () => ({
    default: {
        props: ['modelValue', 'placeholder', 'wrapperClass'],
        emits: ['update:modelValue'],
        template: '<input class="search" :placeholder="placeholder" :data-wrapper="wrapperClass" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
    },
}));
vi.mock('@/Pages/Panel/Stock/Movements/MovementTable.vue', () => ({
    default: { name: 'MovementTableStub', props: ['items', 't'], emits: ['sort', 'filterProduct'], template: '<div class="table-stub">{{ items.data.length }}</div>' },
}));
vi.mock('@/Pages/Panel/Stock/Movements/MovementCards.vue', () => ({
    default: { name: 'MovementCardsStub', props: ['items'], emits: ['filterProduct'], template: '<div class="cards-stub">{{ items.data.length }}</div>' },
}));
vi.mock('@/Pages/Panel/Stock/Movements/MovementFormModal.vue', () => ({
    default: { name: 'MovementFormModalStub', props: ['open'], emits: ['close', 'saved'], template: '<div />' },
}));

const t = {
    page_title: 'Stock movements', total_label: 'Total:', btn_products: 'Products', btn_new: 'New movement',
    search_placeholder: 'Search by product...', filter_product_all: 'All products', filter_type_all: 'All types',
    close: 'Close',
};

const routes = { index: '/stock/movements', store: '/stock/movements', products_index: '/stock/products', scan_barcode: '/scan' };

let wrapper;

beforeEach(() => {
    inertia.page.props = { flash: {} };
    window.localStorage.clear();
    vi.mocked(router.get).mockClear();
    vi.mocked(router.reload).mockClear();
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
});

function mountPage(filters = { search: '', entity_product_id: '', type: '', sort: 'occurred_at', direction: 'desc' }, extra = {}) {
    wrapper = mount(MovementsIndex, {
        props: {
            items: { data: [{ id: 'm1', entity_product_id: 'p1' }, { id: 'm2', entity_product_id: 'p2' }], total: 42 },
            products: [{ id: 'p1', name: 'Colírio' }],
            movementTypes: [{ value: 'manual_in', label: 'Manual entry', direction: 1 }],
            filterTypes: [
                { value: 'purchase_in', label: 'Purchase receipt', direction: 1 },
                { value: 'manual_in', label: 'Manual entry', direction: 1 },
                { value: 'consumption_out', label: 'Procedure consumption', direction: -1 },
            ],
            filters,
            routes,
            t,
            ...extra,
        },
    });

    return wrapper;
}

describe('Stock/Movements/Index', () => {
    it('usa os textos traduzidos e mostra o total no cabeçalho', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Stock movements');
        expect(w.find('.total').text()).toBe('Total: 42');
        expect(w.text()).toContain('Products');
        expect(w.text()).toContain('New movement');
        expect(w.find('.search').attributes('placeholder')).toBe('Search by product...');
        // Na barra quem espaça é a própria barra: sem o mb-3 padrão do SearchInput.
        expect(w.find('.search').attributes('data-wrapper')).toBe('');
    });

    it('alerta de flash fecha com estado local e volta numa nova resposta, mesmo com o mesmo texto', async () => {
        inertia.page.props = { flash: { message: 'Movement registered.' } };
        const w = mountPage();
        const alert = () => w.find('.alert-success');

        expect(alert().text()).toContain('Movement registered.');
        const close = alert().find('button.btn-close');
        expect(close.attributes('aria-label')).toBe('Close');
        expect(close.attributes('data-bs-dismiss')).toBeUndefined();

        await close.trigger('click');
        expect(alert().exists()).toBe(false);

        // Segundo lançamento: resposta nova (objeto flash novo) com a mesma mensagem.
        inertia.page.props = { flash: { message: 'Movement registered.' } };
        await nextTick();

        expect(alert().exists()).toBe(true);
    });

    it('o filtro de tipo lista todos os tipos do extrato (não só os manuais)', () => {
        const w = mountPage();
        const options = w.findAll('select')[1].findAll('option').map((o) => o.text());

        expect(options).toEqual(['All types', 'Purchase receipt', 'Manual entry', 'Procedure consumption']);
    });

    it('alterna para cards com os mesmos dados e guarda a preferência no navegador', async () => {
        const w = mountPage();

        await w.find('.to-cards').trigger('click');

        expect(w.find('.table-stub').exists()).toBe(false);
        expect(w.find('.cards-stub').text()).toBe('2');
        expect(window.localStorage.getItem('stock_movements_view')).toBe('cards');
    });

    it('a busca espera parar de digitar e preserva ordenação e filtros', async () => {
        vi.useFakeTimers();
        const w = mountPage({ search: '', entity_product_id: 'p1', type: 'loss', sort: 'quantity', direction: 'asc' });

        await w.find('.search').setValue('lote');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/stock/movements',
            { search: 'lote', entity_product_id: 'p1', type: 'loss', sort: 'quantity', direction: 'asc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('ordenar mantém busca e filtros', async () => {
        const w = mountPage({ search: 'ana', entity_product_id: '', type: 'manual_in', sort: 'occurred_at', direction: 'desc' });

        w.findComponent({ name: 'MovementTableStub' }).vm.$emit('sort', { sort: 'product', direction: 'asc' });
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/stock/movements',
            { search: 'ana', entity_product_id: '', type: 'manual_in', sort: 'product', direction: 'asc' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('o atalho da linha filtra pelo produto, mantendo a ordenação', async () => {
        const w = mountPage();

        w.findComponent({ name: 'MovementTableStub' }).vm.$emit('filterProduct', 'p1');
        await nextTick();

        expect(w.findAll('select')[0].element.value).toBe('p1');
        expect(router.get).toHaveBeenCalledWith(
            '/stock/movements',
            expect.objectContaining({ entity_product_id: 'p1', sort: 'occurred_at', direction: 'desc' }),
            expect.objectContaining({ replace: true }),
        );
    });

    it('depois de lançar só fecha o form: o store já volta com busca/filtros/ordem (sem visita extra)', async () => {
        const w = mountPage({ search: 'lote', entity_product_id: 'p1', type: 'manual_in', sort: 'quantity', direction: 'asc' });
        const form = () => w.findComponent({ name: 'MovementFormModalStub' });

        await w.find('button.new-movement').trigger('click');
        expect(form().props('open')).toBe(true);

        form().vm.$emit('saved');
        await nextTick();

        expect(form().props('open')).toBe(false);
        expect(router.get).not.toHaveBeenCalled();
        expect(router.reload).not.toHaveBeenCalled();
        expect(w.findAll('select')[0].element.value).toBe('p1');
        expect(w.find('.search').element.value).toBe('lote');
    });

    it('produto inativo filtrado (atalho de Produtos) aparece no select com o nome vindo do backend', () => {
        const w = mountPage(
            { search: '', entity_product_id: 'p9', type: '', sort: 'occurred_at', direction: 'desc' },
            { items: { data: [], total: 0 }, filteredProduct: { id: 'p9', name: 'Colírio descontinuado' } },
        );
        const select = w.findAll('select')[0];

        expect(select.element.value).toBe('p9');
        expect(select.find('option[value="p9"]').text()).toBe('Colírio descontinuado');
    });

    it('produto filtrado fora da lista de ativos continua visível no select', async () => {
        const w = mountPage({ search: '', entity_product_id: 'p2', type: '', sort: 'occurred_at', direction: 'desc' });

        expect(w.findAll('select')[0].findAll('option').map((o) => o.attributes('value'))).toContain('p2');
    });
});
