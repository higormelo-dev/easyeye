import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import AiProvidersIndex from '@/Pages/Panel/Manager/AiProviders/Index.vue';
import { blockedLgpd, lgpd, mockFetch, paginator, priceRow, provider, ready } from './aiProvidersFixtures';

/**
 * Manager → Provedores de IA no padrão das telas do manager: números no
 * topo, abas, filtros do catálogo no servidor, drawers e ações em ícone.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
    router: { reload: vi.fn(), get: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: { props: ['title', 'subtitle'], template: '<header><h1>{{ title }}</h1><slot name="actions" /></header>' },
}));

const sync = vi.hoisted(() => ({ startSync: vi.fn() }));
vi.mock('@/Pages/Panel/Manager/AiProviders/CatalogSyncPanel.vue', () => ({
    default: {
        props: ['runningSync', 'syncs', 'syncDetails', 'autoSync', 't'],
        emits: ['update:running'],
        setup(_, { expose }) {
            expose({ startSync: sync.startSync });
            return {};
        },
        template: `<div class="sync-stub"><button class="sync-running" @click="$emit('update:running', true)" /></div>`,
    },
}));
vi.mock('@/Pages/Panel/Manager/AiProviders/ProviderDetailDrawer.vue', () => ({
    default: {
        props: ['open', 'provider', 'modelOptions', 'testing', 'testResult'],
        emits: ['close', 'test', 'saved'],
        template: `<div class="provider-drawer" :data-open="String(open)" :data-code="provider?.code ?? ''"
            :data-options="(modelOptions ?? []).join(',')" :data-result="testResult?.message ?? ''" />`,
    },
}));
vi.mock('@/Pages/Panel/Manager/AiProviders/ModelPriceFormModal.vue', () => ({
    default: {
        props: ['open', 'price', 'defaultProvider'],
        emits: ['close', 'saved'],
        template: `<div class="price-form" :data-open="String(open)" :data-price="price?.id ?? ''"
            :data-default="defaultProvider" @click="$emit('saved', 'Catálogo de modelos atualizado.')" />`,
    },
}));
vi.mock('@/Pages/Panel/Manager/AiProviders/ModelPriceDrawer.vue', () => ({
    default: { props: ['open', 'price'], template: '<div class="price-drawer" :data-open="String(open)" />' },
}));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({ default: { template: '<div class="menu"><slot /></div>' } }));

const t = { stat_providers_value: ':count de :total', stat_never: 'Nunca', test_failed: 'Falha ao conectar.' };

const baseProps = (over = {}) => ({
    providers: [
        ready({ code: 'openai', label: 'OpenAI', role: 'primary', enabled: true }),
        provider({ code: 'groq', label: 'Groq' }),
    ],
    roles: { primary: 'openai', reviewer: null, adjudicator: null },
    modelOptions: { openai: ['gpt-4o', 'gpt-5-mini'] },
    prices: paginator([priceRow({ id: 'a', model: 'gpt-4o' })]),
    filters: { search: '', provider: '', status: 'active', source: '', flag: '', sort: '', direction: 'asc' },
    stats: {
        providers_configured: 1,
        providers_total: 8,
        models_active: 16,
        models_inactive: 74,
        best_mode: 'Economia',
        last_sync_at: null,
    },
    modes: [{ value: 'economy', label: 'Economia', needs: 1 }],
    t,
    ...over,
});

function mountIndex(over = {}) {
    return mount(AiProvidersIndex, { props: baseProps(over) });
}

beforeEach(() => {
    vi.clearAllMocks();
    vi.useRealTimers();
    window.showSuccessToast = vi.fn();
    window.showErrorToast = vi.fn();
});

describe('AiProviders Index', () => {
    it('números do topo e abas no padrão do manager; abre em Provedores', () => {
        const wrapper = mountIndex();

        expect(wrapper.find('[data-stat="providers"]').text()).toContain('1 de 8');
        expect(wrapper.find('[data-stat="models"]').text()).toContain('16');
        expect(wrapper.find('[data-stat="mode"]').text()).toContain('Economia');
        expect(wrapper.find('[data-stat="sync"]').text()).toContain('Nunca');
        expect(wrapper.find('[data-tab="providers"]').classes()).toContain('active');
        expect(wrapper.find('[data-provider-row="openai"]').exists()).toBe(true);
    });

    it('[LGPD] avisa provedor em uso sem registro e bloqueado que sobrou num papel', () => {
        expect(mountIndex().find('[data-lgpd-pending]').exists()).toBe(false);

        const wrapper = mountIndex({
            providers: [
                ready({
                    code: 'openai',
                    label: 'OpenAI',
                    role: 'primary',
                    enabled: true,
                    lgpd: lgpd({ pending: true, in_role: true }),
                }),
                ready({
                    code: 'gemini',
                    label: 'Google (Gemini)',
                    lgpd: blockedLgpd('gemini_terms', { in_role: true, blocked_in_role: true }),
                }),
            ],
            t: {
                ...t,
                lgpd_pending_alert: 'Sem registro: :providers.',
                lgpd_blocked_alert: 'Bloqueado em papel: :providers.',
            },
        });

        expect(wrapper.find('[data-lgpd-pending]').text()).toBe('Sem registro: OpenAI.');
        expect(wrapper.find('[data-lgpd-blocked]').text()).toBe('Bloqueado em papel: Google (Gemini).');
    });

    it('filtro do catálogo na URL abre em Modelos; sincronização rodando abre em Sincronização', () => {
        expect(
            mountIndex({ filters: { ...baseProps().filters, provider: 'openai' } })
                .find('[data-tab="models"]')
                .classes(),
        ).toContain('active');
        expect(
            mountIndex({ runningSync: { id: 's1', is_done: false } })
                .find('[data-tab="sync"]')
                .classes(),
        ).toContain('active');
    });

    it('filtros e busca do catálogo vão ao servidor (padrão fora da URL)', async () => {
        vi.useFakeTimers();
        const wrapper = mountIndex();

        await wrapper.find('[data-filter-provider]').setValue('openai');
        expect(router.get).toHaveBeenLastCalledWith(
            '/_routes/manager.ai-providers.index',
            expect.objectContaining({ provider: 'openai', status: undefined, sort: undefined }),
            expect.objectContaining({ only: ['prices', 'filters'], preserveState: true }),
        );

        await wrapper.find('[data-filter-status]').setValue('inactive');
        expect(router.get.mock.lastCall[1]).toMatchObject({ provider: 'openai', status: 'inactive' });

        await wrapper.find('input[type="text"]').setValue('gpt');
        vi.advanceTimersByTime(400);
        expect(router.get.mock.lastCall[1]).toMatchObject({ search: 'gpt' });
    });

    it('ver provedor abre o drawer com os modelos dele; testar mostra o resultado', async () => {
        mockFetch({ ok: false, message: 'Chave recusada.' }, false);
        const wrapper = mountIndex();

        await wrapper.find('[data-provider-view="openai"]').trigger('click');
        const drawer = wrapper.find('.provider-drawer');
        expect(drawer.attributes('data-open')).toBe('true');
        expect(drawer.attributes('data-options')).toBe('gpt-4o,gpt-5-mini');

        await wrapper.find('[data-provider-test="openai"]').trigger('click');
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toBe('/_routes/manager.ai-providers.test');
        expect(window.showErrorToast).toHaveBeenCalledWith('OpenAI: Chave recusada.');
        expect(wrapper.find('.provider-drawer').attributes('data-result')).toBe('Chave recusada.');
    });

    it('travar preço pelo menu manda os mesmos preços + a trava e relê o catálogo', async () => {
        const fetchMock = mockFetch({ message: 'Catálogo de modelos atualizado.' });
        const wrapper = mountIndex();

        await wrapper.find('[data-price-row="openai|gpt-4o"] [data-price-lock]').trigger('click');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/_routes/manager.ai-model-prices.update/a');
        expect(JSON.parse(init.body)).toEqual({
            input_usd_per_million: 2.5,
            output_usd_per_million: 10,
            reasoning_usd_per_million: null,
            tool_call_usd: null,
            active: true,
            price_locked: true,
        });
        expect(router.reload).toHaveBeenCalledWith({ only: ['prices', 'modelOptions', 'providers', 'stats'] });
    });

    it('cabeçalho: "Novo modelo" abre o cadastro; "Sincronizar" vai para a aba e inicia', async () => {
        const wrapper = mountIndex({ filters: { ...baseProps().filters } });

        await wrapper.find('[data-header-new-model]').trigger('click');
        expect(wrapper.find('.price-form').attributes('data-open')).toBe('true');
        expect(wrapper.find('.price-form').attributes('data-price')).toBe('');

        await wrapper.find('.price-form').trigger('click'); // salvou
        expect(window.showSuccessToast).toHaveBeenCalledWith('Catálogo de modelos atualizado.');
        expect(router.reload).toHaveBeenCalledWith({ only: ['prices', 'modelOptions', 'providers', 'stats'] });

        await wrapper.find('[data-header-sync]').trigger('click');
        expect(wrapper.find('[data-tab="sync"]').classes()).toContain('active');
        expect(sync.startSync).toHaveBeenCalled();

        await wrapper.find('.sync-running').trigger('click');
        expect(wrapper.find('[data-header-sync]').attributes('disabled')).toBeDefined();
    });
});
