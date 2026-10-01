import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import CatalogIndex from '@/Pages/Panel/Settings/Catalog/Index.vue';

/**
 * Página genérica dos catálogos (convênios, tipos de pele, lentes...) no
 * layout de Panel/Patients/Index: total no cabeçalho, alternância
 * tabela/cards persistida, busca que preserva a ordenação e tabela paginada
 * (CatalogTable + TablePagination reais).
 */

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'totalLabel', 'view'],
        emits: ['set-view'],
        template: `<div>
            <span class="total">{{ totalLabel }} {{ total }}</span>
            <span class="current-view">{{ view }}</span>
            <button class="to-cards" @click="$emit('set-view', 'cards')" />
            <button class="to-table" @click="$emit('set-view', 'table')" />
            <slot name="actions" />
        </div>`,
    },
}));
vi.mock('@/Components/Panel/SearchInput.vue', () => ({
    default: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template:
            '<input class="search" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
    },
}));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({ default: { template: '<button />' } }));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Pages/Panel/Settings/Catalog/CatalogCards.vue', () => ({
    default: { props: ['cardsUrl', 'search'], template: '<div class="cards-stub">{{ cardsUrl }}|{{ search }}</div>' },
}));
vi.mock('@/Pages/Panel/Settings/Catalog/CatalogFormModal.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Settings/Catalog/CatalogDetailDrawer.vue', () => ({ default: { template: '<div />' } }));

const t = {
    total_label: 'Total:',
    empty_list: 'Nenhum registro cadastrado.',
    pagination_showing: 'Exibindo',
    pagination_of: 'de',
    pagination_suffix: 'registros',
};

let wrapper;

beforeEach(() => {
    window.localStorage.clear();
    vi.mocked(router.get).mockClear();
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
});

function row(i) {
    return {
        id: `id-${i}`,
        code: `CVP-${i}`,
        name: `CONVÊNIO ${i}`,
        active: true,
        deleted: false,
        is_global: false,
        mode: 'full',
    };
}

function paginator(rows, overrides = {}) {
    return {
        data: rows,
        current_page: 1,
        last_page: 1,
        from: rows.length ? 1 : null,
        to: rows.length,
        total: rows.length,
        prev_page_url: null,
        next_page_url: null,
        links: [
            { url: null, label: '&laquo;', active: false },
            { url: '/catalog?page=1', label: '1', active: true },
            { url: null, label: '&raquo;', active: false },
        ],
        ...overrides,
    };
}

function mountWith(items, filters = { search: '', sort: 'name', dir: 'asc' }) {
    wrapper = mount(CatalogIndex, {
        props: {
            meta: { title: 'Convênios', storageKey: 'covenants_view', cardsUrl: '/catalog/cards' },
            columns: [
                { key: 'code', label: 'Código', type: 'code' },
                { key: 'name', label: 'Nome', type: 'text', sortable: true },
            ],
            fields: [],
            crudFields: { name: '' },
            routes: { index: '/catalog', store: '/catalog', cards: '/catalog/cards' },
            urlTemplates: { show: '', update: '', destroy: '', restore: '' },
            items,
            filters,
            sortable: ['name', 'code', 'created_at'],
            t,
        },
    });

    return wrapper;
}

describe('Catalog/Index — layout igual à tela de pacientes', () => {
    it('mostra o total geral no cabeçalho e só as linhas da página na tabela', () => {
        const rows = Array.from({ length: 15 }, (_, i) => row(i + 1));
        const w = mountWith(paginator(rows, { last_page: 55, to: 15, total: 812, next_page_url: '/catalog?page=2' }));

        expect(w.findAll('tbody tr')).toHaveLength(15);
        expect(w.find('.total').text()).toBe('Total: 812');
    });

    it('exibe a paginação traduzida quando há mais de uma página', () => {
        const rows = Array.from({ length: 15 }, (_, i) => row(i + 1));
        const w = mountWith(
            paginator(rows, {
                last_page: 55,
                to: 15,
                total: 812,
                next_page_url: '/catalog?page=2',
                links: [
                    { url: null, label: '&laquo;', active: false },
                    { url: '/catalog?page=1', label: '1', active: true },
                    { url: '/catalog?page=2', label: '2', active: false },
                    { url: '/catalog?page=2', label: '&raquo;', active: false },
                ],
            }),
        );

        expect(w.text()).toContain('Exibindo 1–15');
        expect(w.text()).toContain('de 812 registros');
    });

    it('mostra o estado vazio quando não há registros', () => {
        const w = mountWith(paginator([]));

        expect(w.text()).toContain('Nenhum registro cadastrado.');
        expect(w.find('.pagination').exists()).toBe(false);
    });

    it('alterna para cards e guarda a preferência do catálogo no navegador', async () => {
        const w = mountWith(paginator([row(1)]), { search: 'uni', sort: 'name', dir: 'asc' });

        await w.find('.to-cards').trigger('click');

        expect(w.find('table').exists()).toBe(false);
        expect(w.find('.cards-stub').text()).toBe('/catalog/cards|uni');
        expect(window.localStorage.getItem('covenants_view')).toBe('cards');
    });

    it('abre direto em cards quando essa foi a última escolha', () => {
        window.localStorage.setItem('covenants_view', 'cards');
        const w = mountWith(paginator([row(1)]));

        expect(w.find('.cards-stub').exists()).toBe(true);
        expect(w.find('.current-view').text()).toBe('cards');
    });

    it('a busca espera o usuário parar de digitar e preserva a ordenação atual', async () => {
        vi.useFakeTimers();
        const w = mountWith(paginator([row(1)]), { search: '', sort: 'code', dir: 'desc' });

        await w.find('.search').setValue('uni');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/catalog',
            { search: 'uni', sort: 'code', dir: 'desc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('clicar no cabeçalho ordena mantendo a busca', async () => {
        const w = mountWith(paginator([row(1)]), { search: 'uni', sort: 'name', dir: 'asc' });

        await w.findAll('thead th')[1].find('button').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/catalog',
            { search: 'uni', sort: 'name', dir: 'desc' },
            expect.objectContaining({ preserveState: true }),
        );
    });
});
