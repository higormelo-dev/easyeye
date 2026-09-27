import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import CatalogCards from '@/Pages/Panel/Settings/Catalog/CatalogCards.vue';

/**
 * Modo cards dos catálogos (padrão PatientCards): busca o endpoint JSON
 * paginado, mostra erro com "tentar de novo" em vez de falhar calado,
 * pagina com janela compacta e recarrega após visitas Inertia.
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { template: '<div class="dd"><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: { props: ['title', 'icon'], emits: ['click'], template: '<button type="button" :title="title" @click="$emit(\'click\')" />' },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const columns = [
    { key: 'code', label: 'Código', type: 'code' },
    { key: 'name', label: 'Nome', type: 'text' },
];
const t = { load_error: 'Falha ao carregar.', retry: 'Tentar novamente', action_view: 'Ver detalhes', action_edit: 'Editar', empty_list: 'Nada.' };

let wrapper;
let successHandler;

function card(overrides = {}) {
    return { id: 'c1', code: 'CVP-1', name: 'UNIMED', active: true, deleted: false, is_global: false, mode: 'full', ...overrides };
}

function respond(data, meta = { current_page: 1, last_page: 1, total: data.length }) {
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data, meta }) }));
}

beforeEach(() => {
    successHandler = null;
    router.on = vi.fn((event, cb) => {
        if (event === 'success') successHandler = cb;
        return () => {};
    });
});
afterEach(() => wrapper?.unmount());

async function mountCards(search = '') {
    wrapper = mount(CatalogCards, { props: { cardsUrl: '/catalog/cards', search, columns, t } });
    await flushPromises();

    return wrapper;
}

describe('CatalogCards', () => {
    it('busca a página 1 com a busca atual e renderiza os cards', async () => {
        respond([card(), card({ id: 'c2', name: 'BRADESCO' })]);
        const w = await mountCards('uni');

        const url = globalThis.fetch.mock.calls[0][0];
        expect(url).toContain('/catalog/cards?');
        expect(url).toContain('page=1');
        expect(url).toContain('search=uni');
        expect(w.findAll('.card')).toHaveLength(2);
        expect(w.text()).toContain('BRADESCO');
    });

    it('padrão do sistema (view_only) não oferece editar', async () => {
        respond([card({ mode: 'view_only', is_global: true })]);
        const w = await mountCards();

        expect(w.find('button[title="Ver detalhes"]').exists()).toBe(true);
        expect(w.text()).not.toContain('Editar');
    });

    it('erro no endpoint mostra alerta com tentar de novo (não fica vazio calado)', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }));
        const w = await mountCards();

        expect(w.find('[role="alert"]').text()).toContain('Falha ao carregar.');

        respond([card()]);
        await w.find('[role="alert"] button').trigger('click');
        await flushPromises();

        expect(w.find('[role="alert"]').exists()).toBe(false);
        expect(w.findAll('.card')).toHaveLength(1);
    });

    it('com muitas páginas mostra janela compacta (não 66 botões)', async () => {
        respond([card()], { current_page: 1, last_page: 66, total: 980 });
        const w = await mountCards();

        const labels = w.findAll('.pagination .page-link').map((el) => el.text()).filter(Boolean);

        expect(labels).toEqual(['1', '2', '3', '…', '66']);
    });

    it('busca nova volta para a página 1 mesmo com o prop já atualizado antes do evento (ordem real do Inertia 3)', async () => {
        respond([card()], { current_page: 3, last_page: 5, total: 60 });
        const w = await mountCards('');

        respond([card()]);
        await w.setProps({ search: 'brad' });
        successHandler({ detail: { page: { props: { filters: { search: 'brad' } } } } });
        await flushPromises();

        const url = globalThis.fetch.mock.calls[0][0];
        expect(url).toContain('search=brad');
        expect(url).toContain('page=1');
    });

    it('mesma busca (após editar/excluir) mantém a página atual', async () => {
        respond([card()], { current_page: 3, last_page: 5, total: 60 });
        await mountCards('uni');

        respond([card()], { current_page: 3, last_page: 5, total: 60 });
        successHandler({ detail: { page: { props: { filters: { search: 'uni' } } } } });
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toContain('page=3');
    });

    it('resposta antiga que chega depois não sobrescreve a mais nova', async () => {
        respond([card()]);
        const w = await mountCards('');

        let resolveSlow;
        globalThis.fetch = vi.fn()
            .mockImplementationOnce(() => new Promise((r) => { resolveSlow = r; }))
            .mockImplementationOnce(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: [card({ id: 'new', name: 'NOVO' })], meta: { current_page: 1, last_page: 1, total: 1 } }) }));

        successHandler({ detail: { page: { props: { filters: { search: 'a' } } } } });
        successHandler({ detail: { page: { props: { filters: { search: 'ab' } } } } });
        await flushPromises();

        resolveSlow({ ok: true, status: 200, json: () => Promise.resolve({ data: [card({ id: 'old', name: 'ANTIGO' })], meta: { current_page: 1, last_page: 1, total: 1 } }) });
        await flushPromises();

        expect(w.text()).toContain('NOVO');
        expect(w.text()).not.toContain('ANTIGO');
    });

    it('botão de tentar de novo tem nome acessível', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }));
        const w = await mountCards();

        expect(w.find('[role="alert"] button').attributes('aria-label')).toBe('Tentar novamente');
    });
});
