import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import ModelPriceTable from '@/Pages/Panel/Manager/AiProviders/ModelPriceTable.vue';
import { paginator, plain, priceRow } from './aiProvidersFixtures';

/** Tabela do catálogo: selos, preço em USD localizado, ordenação e ações. */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
    Link: { template: '<a><slot /></a>' },
}));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({ default: { template: '<div class="menu"><slot /></div>' } }));

const rows = [
    priceRow({ id: 'a', model: 'gpt-5-mini', in_use: true, input_usd_per_million: 0.0375 }),
    priceRow({
        id: 'b',
        provider: 'gemini',
        provider_label: 'Google (Gemini)',
        model: 'gemini-3.6-flash',
        price_locked: true,
        unlisted_at: '02/10/2026',
    }),
    priceRow({ id: 'c', model: 'gpt-5.5', active: false, source: 'sync' }),
];

function mountTable(data = rows) {
    return mount(ModelPriceTable, {
        props: {
            prices: paginator(data),
            filters: {},
            t: { action_lock: 'Travar preço', action_unlock: 'Destravar preço' },
        },
    });
}

const row = (wrapper, key) => wrapper.find(`[data-price-row="${key}"]`);

describe('ModelPriceTable', () => {
    it('selos de em uso, não listado e travado; preço com até 4 casas no idioma da tela', () => {
        const wrapper = mountTable();

        expect(row(wrapper, 'openai|gpt-5-mini').find('[data-badge-in-use]').exists()).toBe(true);
        expect(plain(row(wrapper, 'openai|gpt-5-mini'))).toContain('US$ 0,0375');
        expect(row(wrapper, 'gemini|gemini-3.6-flash').find('[data-badge-unlisted]').exists()).toBe(true);
        expect(row(wrapper, 'gemini|gemini-3.6-flash').find('[data-locked]').exists()).toBe(true);
        expect(row(wrapper, 'openai|gpt-5.5').text()).toContain('Inativo');
    });

    it('ações do menu: editar, travar/destravar e ativar/desativar', async () => {
        const wrapper = mountTable();
        const gemini = row(wrapper, 'gemini|gemini-3.6-flash');

        expect(gemini.find('[data-price-lock]').text()).toContain('Destravar preço');
        await gemini.find('[data-price-view]').trigger('click');
        await gemini.find('[data-price-edit]').trigger('click');
        await gemini.find('[data-price-lock]').trigger('click');
        await gemini.find('[data-price-toggle]').trigger('click');

        expect(wrapper.emitted('view')[0][0].id).toBe('b');
        expect(wrapper.emitted('edit')[0][0].id).toBe('b');
        expect(wrapper.emitted('toggleLock')[0][0].id).toBe('b');
        expect(wrapper.emitted('toggleActive')[0][0].id).toBe('b');
    });

    it('ordenar pede ao servidor; lista vazia mostra o aviso', async () => {
        const wrapper = mountTable();
        await wrapper
            .findAll('th .sortable-th-btn')
            .find((btn) => btn.text().includes('Entrada'))
            .trigger('click');

        expect(wrapper.emitted('sort')?.[0]?.[0]).toMatchObject({ sort: 'input' });
        expect(mountTable([]).text()).toContain('Nenhum modelo com esses filtros.');
    });
});
