import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { usePage } from '@inertiajs/vue3';
import SuppliersIndex from '@/Pages/Panel/Stock/Suppliers/Index.vue';

/**
 * Aviso de sucesso (flash.message compartilhado pelo HandleInertiaRequests) e
 * barra de busca da listagem de fornecedores:
 *  - fechar o aviso é estado local do Vue (sem data-bs-dismiss — o Bootstrap
 *    removeria o nó que o Vue controla) e ele volta numa nova resposta com
 *    flash, mesmo com o texto repetido;
 *  - o SearchInput recebe wrapper-class="" (sem o `mb-3` padrão nem classe
 *    de tamanho própria — mesma barra de Produtos/Lentes).
 */

// usePage() reativo (o mock global devolve um objeto fixo) para simular a
// resposta seguinte do Inertia trocando page.props.flash.
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
vi.mock('@/Pages/Panel/Stock/Suppliers/SupplierTable.vue', () => ({
    default: { template: '<div class="table-stub" />' },
}));
vi.mock('@/Pages/Panel/Stock/Suppliers/SupplierCards.vue', () => ({
    default: { template: '<div class="cards-stub" />' },
}));
vi.mock('@/Pages/Panel/Stock/Suppliers/SupplierFormModal.vue', () => ({ default: { template: '<div />' } }));

const routes = { index: '/s', store: '/s', update: '/s/__ID__', destroy: '/s/__ID__', purchase_orders_index: '/po' };
const t = { close: 'Close', search_placeholder: 'Search suppliers', search_clear: 'Clear search' };

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    usePage().props.flash = {};
    window.localStorage.clear();
});

function mountPage() {
    wrapper = mount(SuppliersIndex, {
        props: {
            items: { data: [], total: 0 },
            filters: { search: '', status: 'all', sort: 'name', direction: 'asc' },
            routes,
            t,
        },
    });

    return wrapper;
}

describe('Suppliers/Index — aviso de sucesso', () => {
    it('mostra o flash e fecha por estado local, sem data-bs-dismiss', async () => {
        usePage().props.flash = { message: 'Fornecedor cadastrado.' };
        const w = mountPage();

        const alert = w.find('.alert-success');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('Fornecedor cadastrado.');

        const close = alert.find('button.btn-close');
        expect(close.attributes('aria-label')).toBe('Close');
        expect(close.attributes('data-bs-dismiss')).toBeUndefined();

        await close.trigger('click');
        expect(w.find('.alert-success').exists()).toBe(false);
    });

    it('volta a mostrar numa nova resposta com flash — mesmo com o texto repetido', async () => {
        usePage().props.flash = { message: 'Fornecedor atualizado.' };
        const w = mountPage();

        await w.find('.alert-success button.btn-close').trigger('click');
        expect(w.find('.alert-success').exists()).toBe(false);

        // Segunda edição: o Inertia entrega um novo objeto flash com o mesmo texto.
        usePage().props.flash = { message: 'Fornecedor atualizado.' };
        await nextTick();
        expect(w.find('.alert-success').text()).toContain('Fornecedor atualizado.');

        await w.find('.alert-success button.btn-close').trigger('click');
        usePage().props.flash = { message: 'Fornecedor excluído.' };
        await nextTick();
        expect(w.find('.alert-success').text()).toContain('Fornecedor excluído.');
    });

    it('sem flash não renderiza aviso', () => {
        expect(mountPage().find('.alert-success').exists()).toBe(false);
    });
});

describe('Suppliers/Index — barra de busca', () => {
    it('SearchInput usa wrapper-class vazio (sem mb-3) e rótulo acessível', () => {
        const input = mountPage().find('input[aria-label="Search suppliers"]');
        expect(input.exists()).toBe(true);

        // input → .input-group → wrapper do SearchInput
        const wrapperDiv = input.element.parentElement.parentElement;
        expect(input.element.parentElement.classList.contains('input-group')).toBe(true);
        expect(wrapperDiv.className).toBe('');
        expect(input.element.parentElement.style.maxWidth).toBe('280px');
    });
});
