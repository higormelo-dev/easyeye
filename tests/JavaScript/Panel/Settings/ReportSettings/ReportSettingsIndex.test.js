import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import ReportSettingsIndex from '@/Pages/Panel/Settings/ReportSettings/Index.vue';

/**
 * Modelos de documentação no layout de Panel/Patients/Index: total do
 * paginator, textos via `t`, alternância tabela/cards persistida, busca +
 * categoria + status server-side que preservam a ordenação, flash visível e
 * excluir/reimportar pelo router do Inertia (antes: fetch sem tratar erro).
 */

const inertia = vi.hoisted(() => ({ pageProps: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.pageProps = reactive({ flash: {}, errors: {} });

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn() },
        Link: { template: '<a class="link" :href="href"><slot /></a>', props: ['href'] },
        Head: { template: '<div><slot /></div>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'totalLabel', 'view'],
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
        props: ['modelValue', 'placeholder', 'clearLabel', 'wrapperClass', 'maxWidth'],
        emits: ['update:modelValue'],
        template: '<input class="search" :placeholder="placeholder" :data-clear-label="clearLabel" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
    },
}));
vi.mock('@/Pages/Panel/Settings/ReportSettings/ReportSettingTable.vue', () => ({
    default: {
        props: ['items', 'filters', 't', 'emptyText'],
        emits: ['sort', 'reimport', 'delete'],
        template: `<div class="table-stub" :data-empty="emptyText">
            <button class="sort" @click="$emit('sort', { sort: 'updated_at', direction: 'desc' })" />
            <button class="delete-row" @click="$emit('delete', items.data[0])" />
            <button class="reimport-row" @click="$emit('reimport', items.data[1])" />
            <button class="reimport-own" @click="$emit('reimport', items.data[0])" />
        </div>`,
    },
}));
vi.mock('@/Pages/Panel/Settings/ReportSettings/ReportSettingCards.vue', () => ({
    default: { props: ['items', 't', 'emptyText'], template: '<div class="cards-stub">{{ items.data.map((i) => i.title).join(",") }}</div>' },
}));

const t = {
    page_title: 'Document templates', total_label: 'Total:', btn_new: 'New template',
    search_placeholder: 'Search by title or description...', search_clear: 'Clear search', close: 'Close',
    filter_category_label: 'Filter by category', filter_category_all: 'All categories',
    filter_status_label: 'Filter by status', filter_status_all: 'All', filter_status_active: 'Active', filter_status_inactive: 'Inactive',
    empty_list: 'No templates yet.', empty_search: 'No templates match these filters.',
    confirm_delete: 'Delete the template ":title"?',
    confirm_reimport: 'Re-import the current global template version into ":title"?',
};

const items = {
    data: [
        { id: 'r1', title: 'Receita', destroy_url: '/rs/r1', reimport_url: null },
        { id: 'r2', title: 'Atestado', destroy_url: '/rs/r2', reimport_url: '/rs/r2/reimport' },
    ],
    total: 17,
};

const categories = [{ id: 'c1', name: 'Receitas' }, { id: 'c2', name: 'Laudos' }];
const baseFilters = { search: '', category: '', status: 'all', sort: 'title', direction: 'asc' };
const urls = { index: '/panel/setting/report-settings', create: '/panel/setting/report-settings/create' };

let wrapper;

beforeEach(() => {
    inertia.pageProps.flash = {};
    window.localStorage.clear();
    Object.values(router).forEach((fn) => fn.mockClear?.());
});
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

function mountPage(props = {}) {
    wrapper = mount(ReportSettingsIndex, { props: { items, categories, filters: baseFilters, urls, t, ...props } });

    return wrapper;
}

describe('Settings/ReportSettings/Index', () => {
    it('usa os textos traduzidos, o total do paginator e o link de novo modelo', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Document templates');
        expect(w.find('.total').text()).toBe('Total: 17');
        expect(w.get('.link').text()).toBe('New template');
        expect(w.get('.link').attributes('href')).toBe('/panel/setting/report-settings/create');
        expect(w.find('.search').attributes('placeholder')).toBe('Search by title or description...');
    });

    it('filtros de categoria (quando há categorias) e status, traduzidos', () => {
        const w = mountPage();
        const [category, status] = w.findAll('select');

        expect(category.attributes('aria-label')).toBe('Filter by category');
        expect(category.findAll('option').map((o) => o.text())).toEqual(['All categories', 'Receitas', 'Laudos']);
        expect(status.findAll('option').map((o) => o.text())).toEqual(['All', 'Active', 'Inactive']);

        wrapper.unmount();
        expect(mountPage({ categories: [] }).findAll('select')).toHaveLength(1);
    });

    it('mostra o flash de retorno (message) e o fecha com estado local', async () => {
        inertia.pageProps.flash = { message: 'Template deleted successfully.' };
        const w = mountPage();

        expect(w.get('[role="status"]').text()).toContain('Template deleted successfully.');
        await w.get('[role="status"] button[aria-label="Close"]').trigger('click');
        expect(w.find('[role="status"]').exists()).toBe(false);

        inertia.pageProps.flash = { message: 'Template deleted successfully.' };
        await nextTick();
        expect(w.find('[role="status"]').exists()).toBe(true);
    });

    it('abre em tabela e alterna para cards (mesmo paginator), guardando a preferência', async () => {
        const w = mountPage();

        expect(w.find('.table-stub').exists()).toBe(true);
        await w.find('.to-cards').trigger('click');

        expect(w.find('.cards-stub').text()).toBe('Receita,Atestado');
        expect(window.localStorage.getItem('report_settings_view')).toBe('cards');
    });

    it('a busca espera parar de digitar e preserva categoria, status e ordenação', async () => {
        vi.useFakeTimers();
        const w = mountPage({ filters: { ...baseFilters, category: 'c2', status: 'inactive', sort: 'updated_at', direction: 'desc' } });

        await w.find('.search').setValue('laudo');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/panel/setting/report-settings',
            { search: 'laudo', category: 'c2', status: 'inactive', sort: 'updated_at', direction: 'desc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('trocar categoria filtra na hora, mantendo a busca', async () => {
        const w = mountPage({ filters: { ...baseFilters, search: 'rec' } });

        await w.findAll('select')[0].setValue('c1');

        expect(router.get).toHaveBeenCalledWith(
            '/panel/setting/report-settings',
            { search: 'rec', category: 'c1', status: 'all', sort: 'title', direction: 'asc' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('ordenar pela tabela mantém busca e filtros', async () => {
        const w = mountPage({ filters: { ...baseFilters, search: 'x', status: 'active' } });

        await w.find('.sort').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/panel/setting/report-settings',
            { search: 'x', category: '', status: 'active', sort: 'updated_at', direction: 'desc' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('estado vazio distingue "nenhum cadastrado" de "nada com estes filtros"', () => {
        expect(mountPage({ items: { data: [], total: 0 } }).get('.table-stub').attributes('data-empty')).toBe('No templates yet.');
        wrapper.unmount();

        expect(mountPage({ items: { data: [], total: 0 }, filters: { ...baseFilters, status: 'inactive' } })
            .get('.table-stub').attributes('data-empty')).toBe('No templates match these filters.');
    });

    it('excluir confirma com o título e usa router.delete; cancelado, nada acontece', async () => {
        const confirm = vi.fn(() => false);
        vi.stubGlobal('confirm', confirm);
        const w = mountPage();

        await w.find('.delete-row').trigger('click');
        expect(confirm).toHaveBeenCalledWith('Delete the template "Receita"?');
        expect(router.delete).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        await w.find('.delete-row').trigger('click');
        expect(router.delete).toHaveBeenCalledWith('/rs/r1', { preserveScroll: true });
    });

    it('reimportar só para adotados, com confirmação, via router.post', async () => {
        const confirm = vi.fn(() => true);
        vi.stubGlobal('confirm', confirm);
        const w = mountPage();

        await w.find('.reimport-own').trigger('click');
        expect(confirm).not.toHaveBeenCalled();
        expect(router.post).not.toHaveBeenCalled();

        await w.find('.reimport-row').trigger('click');
        expect(confirm).toHaveBeenCalledWith('Re-import the current global template version into "Atestado"?');
        expect(router.post).toHaveBeenCalledWith('/rs/r2/reimport', {}, { preserveScroll: true });
    });
});
