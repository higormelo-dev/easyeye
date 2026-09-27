import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import CountsIndex from '@/Pages/Panel/Stock/Counts/Index.vue';

/**
 * Página de contagem no layout de Panel/Patients/Index: total, alternância
 * tabela/cards persistida, textos via `t`, busca/categoria/ordenação que se
 * preservam — e o fluxo de contagem: tudo o que foi digitado (inclusive em
 * outra página/busca) vai no envio, item em branco nunca vai.
 */

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
        props: ['modelValue', 'placeholder'],
        emits: ['update:modelValue'],
        template: '<input class="search" :placeholder="placeholder" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
    },
}));
vi.mock('@/Pages/Panel/Stock/Counts/CountTable.vue', () => ({
    default: {
        name: 'CountTableStub',
        props: ['products', 'counted', 'deltas'],
        emits: ['sort', 'count'],
        template: '<div class="table-stub">{{ JSON.stringify(deltas) }}</div>',
    },
}));
vi.mock('@/Pages/Panel/Stock/Counts/CountCards.vue', () => ({
    default: { name: 'CountCardsStub', props: ['products', 'counted', 'deltas'], emits: ['count'], template: '<div class="cards-stub">{{ products.data.length }}</div>' },
}));

const t = {
    page_title: 'Stock count', total_label: 'Total:', btn_movements: 'Movements', opens_new_tab: 'opens in a new tab', close: 'Close',
    btn_apply: 'Apply count (:count)',
    search_placeholder: 'Search by name...', filter_category_all: 'All categories',
    touched_summary: ':touched of :total product(s) with a typed count',
    result_applied: ':count product(s) adjusted', apply_error: 'Could not apply the count.',
};

const routes = { index: '/stock/counts', store: '/stock/counts', movements_index: '/stock/movements' };

const page1 = { data: [{ id: 'p1', name: 'A', qty_on_hand: 10 }, { id: 'p2', name: 'B', qty_on_hand: 5 }], total: 60 };
const page2 = { data: [{ id: 'p3', name: 'C', qty_on_hand: 1 }], total: 60 };

let wrapper;

beforeEach(() => {
    window.localStorage.clear();
    vi.mocked(router.get).mockClear();
    vi.mocked(router.reload).mockClear();
    window.axios = { post: vi.fn() };
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    delete window.axios;
});

function mountPage(filters = { search: '', category_id: '', sort: 'name', direction: 'asc' }, products = page1) {
    wrapper = mount(CountsIndex, {
        props: { products, categories: [{ id: 'c1', name: 'Colírios' }], filters, routes, t },
    });

    return wrapper;
}

const table = (w) => w.findComponent({ name: 'CountTableStub' });
const applyButtons = (w) => w.findAll('button.apply-count');

describe('Stock/Counts/Index', () => {
    it('usa os textos traduzidos e mostra o total no cabeçalho', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Stock count');
        expect(w.find('.total').text()).toBe('Total: 60');
        expect(w.text()).toContain('Movements');
        expect(applyButtons(w)[0].text()).toBe('Apply count (0)');
        expect(applyButtons(w)[0].attributes('disabled')).toBeDefined();
        expect(w.find('.search').attributes('placeholder')).toBe('Search by name...');
        expect(w.find('.touched-summary').text()).toBe('0 of 60 product(s) with a typed count');
    });

    it('o atalho "Movimentações" do cabeçalho abre em nova aba (não perde a contagem digitada)', async () => {
        const w = mountPage();
        table(w).vm.$emit('count', 'p1', '8');
        await nextTick();

        const link = w.find('a.movements-link');

        expect(link.attributes('href')).toBe('/stock/movements');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toBe('noopener noreferrer');
        expect(link.attributes('title')).toBe('Movements (opens in a new tab)');
        expect(link.find('.visually-hidden').text()).toBe('(opens in a new tab)');
        expect(router.get).not.toHaveBeenCalled();
        expect(applyButtons(w)[0].text()).toBe('Apply count (1)');
    });

    it('alterna para cards e guarda a preferência no navegador', async () => {
        const w = mountPage();

        await w.find('.to-cards').trigger('click');

        expect(w.find('.table-stub').exists()).toBe(false);
        expect(w.find('.cards-stub').text()).toBe('2');
        expect(window.localStorage.getItem('stock_counts_view')).toBe('cards');
    });

    it('a busca espera parar de digitar e preserva categoria e ordenação', async () => {
        vi.useFakeTimers();
        const w = mountPage({ search: '', category_id: 'c1', sort: 'qty_on_hand', direction: 'desc' });

        await w.find('.search').setValue('colirio');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/stock/counts',
            { search: 'colirio', category_id: 'c1', sort: 'qty_on_hand', direction: 'desc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('ordenar e trocar categoria mantêm os demais parâmetros', async () => {
        const w = mountPage({ search: 'ab', category_id: '', sort: 'name', direction: 'asc' });

        table(w).vm.$emit('sort', { sort: 'category', direction: 'asc' });
        await w.find('select').setValue('c1');

        expect(router.get).toHaveBeenNthCalledWith(1, '/stock/counts', { search: 'ab', category_id: '', sort: 'category', direction: 'asc' }, expect.any(Object));
        expect(router.get).toHaveBeenNthCalledWith(2, '/stock/counts', { search: 'ab', category_id: 'c1', sort: 'name', direction: 'asc' }, expect.any(Object));
    });

    it('calcula a diferença do que foi digitado e conta só itens preenchidos', async () => {
        const w = mountPage();

        table(w).vm.$emit('count', 'p1', '12.5');
        table(w).vm.$emit('count', 'p2', '');
        await nextTick();

        expect(table(w).props('deltas')).toEqual({ p1: 2.5, p2: null });
        expect(applyButtons(w)[0].text()).toBe('Apply count (1)');
        expect(w.find('.touched-summary').text()).toBe('1 of 60 product(s) with a typed count');
    });

    it('envia tudo o que foi digitado, inclusive em outra página, e recarrega os saldos', async () => {
        window.axios.post.mockResolvedValue({ data: { message: 'x', variances: [{ entity_product_id: 'p1', product_name: 'A', before: 10, counted: 8, delta: -2 }] } });
        const w = mountPage();

        table(w).vm.$emit('count', 'p1', '8');
        table(w).vm.$emit('count', 'p2', '');           // em branco: não vai
        await w.setProps({ products: page2 });           // trocou de página (estado preservado)
        table(w).vm.$emit('count', 'p3', '0');           // "contei zero" vai
        await nextTick();

        await applyButtons(w)[0].trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/stock/counts', {
            items: [
                { entity_product_id: 'p1', counted_qty: 8 },
                { entity_product_id: 'p3', counted_qty: 0 },
            ],
        });
        expect(router.reload).toHaveBeenCalledWith({ only: ['products'] });
        expect(w.find('[role="status"]').text()).toContain('1 product(s) adjusted');
        expect(w.find('[role="status"]').text().replace(/\s+/g, ' ')).toContain('A: 10 → 8 (−2)');
        expect(applyButtons(w)[0].text()).toBe('Apply count (0)');
    });

    it('o resultado da contagem fecha com estado local (sem data-bs-dismiss)', async () => {
        window.axios.post.mockResolvedValue({ data: { message: 'x', variances: [] } });
        const w = mountPage();

        table(w).vm.$emit('count', 'p1', '10');
        await nextTick();
        await applyButtons(w)[0].trigger('click');
        await flushPromises();

        const close = w.find('.alert-success button.btn-close');
        expect(close.attributes('aria-label')).toBe('Close');
        expect(close.attributes('data-bs-dismiss')).toBeUndefined();

        await close.trigger('click');

        expect(w.find('.alert-success').exists()).toBe(false);
    });

    it('mostra o erro do servidor (ou o texto traduzido) e mantém o que foi digitado', async () => {
        window.axios.post.mockRejectedValue({ response: { data: {} } });
        const w = mountPage();

        table(w).vm.$emit('count', 'p1', '3');
        await nextTick();
        await applyButtons(w)[1].trigger('click');
        await flushPromises();

        expect(w.find('[role="alert"]').text()).toBe('Could not apply the count.');
        expect(router.reload).not.toHaveBeenCalled();
        expect(applyButtons(w)[0].text()).toBe('Apply count (1)');
    });
});
