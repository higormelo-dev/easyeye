import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import IolLensesIndex from '@/Pages/Panel/Stock/IolLenses/Index.vue';

/**
 * Página de lentes IOL no layout de Panel/Patients/Index: tabela como
 * padrão (antes só havia cards), alternância persistida, textos via `t`,
 * busca com debounce que preserva ordenação e status, e ações de
 * ativar/desativar (payload completo com os dados ATUAIS da lente, via
 * routes.show, sem imagem) e excluir.
 */

// usePage reativo (o mock global de setup.js devolve props fixas): o alerta
// de flash precisa reagir a uma visita nova.
const inertia = vi.hoisted(() => ({ pageProps: null }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    inertia.pageProps = reactive({ flash: {}, errors: {} });

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
            reload: vi.fn(),
            visit: vi.fn(),
        },
        Link: { template: '<a><slot /></a>', props: ['href'] },
        Head: { template: '<div><slot /></div>' },
    };
});

vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title'], template: '<div><h1 class="layout-title">{{ title }}</h1><slot /></div>' },
}));
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
        template:
            '<input class="search" :placeholder="placeholder" :data-clear-label="clearLabel" :data-wrapper-class="wrapperClass" :data-max-width="maxWidth" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
    },
}));
vi.mock('@/Pages/Panel/Stock/IolLenses/IolLensTable.vue', () => ({
    default: {
        props: ['items', 'filters', 't', 'movementsIndexUrl'],
        emits: ['sort', 'edit', 'toggleActive', 'delete'],
        template: `<div class="table-stub">{{ items.data.length }}
            <button class="sort" @click="$emit('sort', { sort: 'price', direction: 'desc' })" />
            <button class="toggle-row" @click="$emit('toggleActive', items.data[0])" />
            <button class="delete-row" @click="$emit('delete', items.data[0])" />
        </div>`,
    },
}));
vi.mock('@/Pages/Panel/Stock/IolLenses/IolLensCards.vue', () => ({
    default: {
        props: ['items', 't'],
        template: '<div class="cards-stub">{{ items.data.map((l) => l.model_name).join(",") }}</div>',
    },
}));
vi.mock('@/Pages/Panel/Stock/IolLenses/IolLensFormModal.vue', () => ({ default: { template: '<div />' } }));

const t = {
    page_title: 'Cataract lenses',
    total_label: 'Total:',
    btn_new: 'New lens',
    search_placeholder: 'Search by model or manufacturer...',
    filter_status_all: 'All',
    filter_status_active: 'Active',
    filter_status_inactive: 'Inactive',
    confirm_delete: 'Delete the lens ":name"?',
    search_clear: 'Clear search',
    toggle_error: 'Could not load the current lens data.',
    close: 'Close',
};

const routes = {
    index: '/stock/iollenses',
    show: '/stock/iollenses/__ID__/show',
    update: '/stock/iollenses/__ID__',
    destroy: '/stock/iollenses/__ID__',
    movements_index: '/stock/movements',
};

const baseFilters = { search: '', status: 'all', sort: 'manufacturer', direction: 'asc' };

const lensRow = {
    id: 'l1',
    manufacturer: 'Alcon',
    model_name: 'AcrySof IQ',
    category: 'Monofocal',
    diopter_min: 10,
    diopter_max: 30,
    price: 2500.5,
    active: true,
    image_url: '/x.jpg',
    iol_lens_model_id: 'm1',
};

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
    wrapper = mount(IolLensesIndex, {
        props: {
            items: { data: [lensRow, { ...lensRow, id: 'l2', model_name: 'Tecnis' }], total: 30 },
            filters,
            routes,
            t,
        },
    });

    return wrapper;
}

describe('Stock/IolLenses/Index', () => {
    it('usa os textos traduzidos e o total do paginator', () => {
        const w = mountPage();

        expect(w.find('.layout-title').text()).toBe('Cataract lenses');
        expect(w.find('.total').text()).toBe('Total: 30');
        expect(w.text()).toContain('New lens');
        expect(w.find('.search').attributes('placeholder')).toBe('Search by model or manufacturer...');
        expect(w.find('.search').attributes('data-clear-label')).toBe('Clear search');
    });

    it('mostra o flash de sucesso e o fecha com estado local (sem data-bs-dismiss); um flash novo reaparece', async () => {
        inertia.pageProps.flash = { message: 'Registro atualizado com sucesso!' };
        const w = mountPage();

        const alert = w.find('[role="status"]');
        expect(alert.text()).toContain('Registro atualizado com sucesso!');
        const close = alert.find('button[aria-label="Close"]');
        expect(close.attributes('data-bs-dismiss')).toBeUndefined();

        await close.trigger('click');
        expect(w.find('[role="status"]').exists()).toBe(false);

        // Outra visita com o MESMO texto (ex.: duas edições seguidas) traz outro objeto flash.
        inertia.pageProps.flash = { message: 'Registro atualizado com sucesso!' };
        await nextTick();
        expect(w.find('[role="status"]').text()).toContain('Registro atualizado com sucesso!');
    });

    it('sem flash não mostra alerta de sucesso', () => {
        const w = mountPage();

        expect(w.find('[role="status"]').exists()).toBe(false);
    });

    it('a busca divide a linha com os filtros sem a margem própria do SearchInput', () => {
        const w = mountPage();

        expect(w.find('.search').attributes('data-wrapper-class')).toBe('');
        expect(w.find('.search').attributes('data-max-width')).toBe('280px');
        expect(
            w
                .findAll('select')[0]
                .findAll('option')
                .map((o) => o.text()),
        ).toEqual(['All', 'Active', 'Inactive']);
    });

    it('abre em tabela por padrão e alterna para cards (mesmo paginator), guardando a preferência', async () => {
        const w = mountPage();

        expect(w.find('.table-stub').exists()).toBe(true);
        await w.find('.to-cards').trigger('click');

        expect(w.find('.cards-stub').text()).toBe('AcrySof IQ,Tecnis');
        expect(window.localStorage.getItem('stock_iollenses_view')).toBe('cards');
    });

    it('a busca espera parar de digitar e preserva ordenação e status', async () => {
        vi.useFakeTimers();
        const w = mountPage({ ...baseFilters, status: 'inactive', sort: 'price', direction: 'desc' });

        await w.find('.search').setValue('alcon');
        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(400);
        await nextTick();

        expect(router.get).toHaveBeenCalledWith(
            '/stock/iollenses',
            { search: 'alcon', status: 'inactive', sort: 'price', direction: 'desc' },
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('ordenar pela tabela mantém busca e status', async () => {
        const w = mountPage({ ...baseFilters, search: 'zeiss', status: 'active' });

        await w.find('.sort').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/stock/iollenses',
            { search: 'zeiss', status: 'active', sort: 'price', direction: 'desc' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('ativar/desativar reenvia os dados ATUAIS da lente (não os da linha, sem imagem nem modelo global) com o status invertido', async () => {
        // Outra pessoa trocou tipo/dioptrias/valor depois que a página abriu:
        // o clique em "Desativar" não pode desfazer essa edição.
        const get = vi.fn().mockResolvedValue({
            data: {
                data: {
                    ...lensRow,
                    category: 'Tórica',
                    diopter_min: 12,
                    diopter_max: 28,
                    price: 3100,
                    image_url: '/novo.jpg',
                },
            },
        });
        vi.stubGlobal('axios', { get });
        const w = mountPage();

        await w.find('.toggle-row').trigger('click');
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/stock/iollenses/l1/show');
        expect(router.put).toHaveBeenCalledWith(
            '/stock/iollenses/l1',
            {
                manufacturer: 'Alcon',
                model_name: 'AcrySof IQ',
                category: 'Tórica',
                diopter_min: 12,
                diopter_max: 28,
                price: 3100,
                active: false,
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    it('ativar/desativar não envia nada e mostra o erro traduzido se a lente atual não puder ser lida', async () => {
        vi.stubGlobal('axios', { get: vi.fn().mockRejectedValue(new Error('404')) });
        const w = mountPage();

        await w.find('.toggle-row').trigger('click');
        await flushPromises();

        expect(router.put).not.toHaveBeenCalled();
        const alert = w.find('[role="alert"]');
        expect(alert.text()).toContain('Could not load the current lens data.');

        await alert.find('button[aria-label="Close"]').trigger('click');
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    it('excluir pede confirmação com o texto traduzido', async () => {
        const confirmSpy = vi.fn(() => true);
        vi.stubGlobal('confirm', confirmSpy);
        const w = mountPage();

        await w.find('.delete-row').trigger('click');

        expect(confirmSpy).toHaveBeenCalledWith('Delete the lens "Alcon AcrySof IQ"?');
        expect(router.delete).toHaveBeenCalledWith(
            '/stock/iollenses/l1',
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
