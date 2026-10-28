import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import CatalogSyncPanel from '@/Pages/Panel/Manager/AiProviders/CatalogSyncPanel.vue';
import { mockFetch } from './aiProvidersFixtures';

/**
 * Aba Sincronização: "Sincronizar agora", progresso em tempo real (WebSocket —
 * sem polling), resultado por provedor, cancelamento de sincronização
 * parada, o que mudou e o histórico (mesmo padrão das Importações de
 * Medicamentos).
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR', t_ui: { realtime_offline: 'Tempo real indisponível' } } }),
    router: { reload: vi.fn() },
}));

const live = vi.hoisted(() => ({ options: null, connected: true, resync: null, ref: null }));
vi.mock('@/composables/useImportProgress', async () => {
    const { computed } = await import('vue');
    return {
        useImportProgress: (syncRef, options) => {
            live.options = options;
            live.ref = syncRef;
            live.resync = vi.fn();
            return { realtimeConnected: computed(() => live.connected), resync: live.resync };
        },
    };
});

const t = {
    sync_auto_on: 'Verificação automática ligada.',
    sync_auto_off: 'Verificação automática desligada.',
    sync_stalled_pending: 'Fila parada.',
    details_limit: 'Até 50 itens.',
};

const payload = (over = {}) => ({
    id: 's1',
    status: 'done',
    status_label: 'Concluída',
    status_color: 'success',
    phase: null,
    phase_label: null,
    source: 'manual',
    source_label: 'Sincronização manual',
    total_providers: 8,
    processed_providers: 8,
    progress: 100,
    providers: [
        { code: 'openai', label: 'OpenAI', status: 'ok', listed: 42, message: null },
        { code: 'anthropic', label: 'Anthropic (Claude)', status: 'skipped', listed: 0, message: 'Sem chave' },
        { code: 'gemini', label: 'Google (Gemini)', status: 'failed', listed: 0, message: 'Chave recusada (HTTP 401)' },
    ],
    models_listed: 42,
    created_count: 3,
    updated_count: 2,
    unchanged_count: 10,
    locked_count: 0,
    unlisted_count: 1,
    missing_price_count: 0,
    suspicious_count: 1,
    notice: '1 preço com variação suspeita.',
    error: null,
    is_done: true,
    idle_seconds: 0,
    stall_after_seconds: null,
    channel: 'manager.ai-catalog-syncs.s1',
    created_at: '03/10/2026 03:40',
    finished_at: '03/10/2026 03:41',
    user: null,
    ...over,
});

function mountPanel(props = {}) {
    return mount(CatalogSyncPanel, { props: { syncs: [], t, ...props } });
}

beforeEach(() => {
    vi.clearAllMocks();
    live.connected = true;
    window.showSuccessToast = vi.fn();
    window.showErrorToast = vi.fn();
});

describe('CatalogSyncPanel', () => {
    it('mostra o resultado da última sincronização: números, provedores e aviso', () => {
        const wrapper = mountPanel({ syncs: [payload()] });
        const status = wrapper.find('[data-sync-status]');

        expect(status.text()).toContain('Sincronização manual');
        expect(status.find('[data-counter="created"]').text()).toContain('3');
        expect(status.find('[data-counter="suspicious"]').exists()).toBe(true);
        expect(status.find('[data-counter="locked"]').exists()).toBe(false);
        expect(status.find('[data-provider-result="openai"]').text()).toContain('42');
        expect(status.text()).toContain('Google (Gemini): Chave recusada (HTTP 401)');
        expect(status.text()).toContain('1 preço com variação suspeita.');
        expect(wrapper.find('[data-auto-sync]').text()).toBe('Verificação automática desligada.');
        expect(wrapper.emitted('update:running')[0]).toEqual([false]);
    });

    it('"Sincronizar agora" inicia (também pelo cabeçalho) e, ao terminar, relê catálogo e números', async () => {
        const fetchMock = mockFetch({
            message: 'Sincronização iniciada.',
            sync: payload({
                status: 'pending',
                status_label: 'Na fila',
                is_done: false,
                progress: 0,
                providers: [],
                stall_after_seconds: 90,
            }),
        });
        const wrapper = mountPanel({ autoSync: true });

        await wrapper.vm.startSync();
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toBe('/_routes/manager.ai-catalog-syncs.store');
        expect(window.showSuccessToast).toHaveBeenCalledWith('Sincronização iniciada.');
        expect(live.ref.value.status).toBe('pending');
        expect(wrapper.find('[data-sync-now]').attributes('disabled')).toBeDefined();
        expect(wrapper.emitted('update:running').at(-1)).toEqual([true]);
        expect(wrapper.find('[data-auto-sync]').text()).toBe('Verificação automática ligada.');

        live.options.onDone(payload());
        expect(router.reload).toHaveBeenCalledWith({
            only: ['prices', 'stats', 'modelOptions', 'providers', 'syncs', 'syncDetails', 'runningSync'],
        });
    });

    it('já existe uma em andamento: avisa e relê o estado', async () => {
        mockFetch({ message: 'Já existe uma sincronização em andamento.' }, false);
        const wrapper = mountPanel();

        await wrapper.find('[data-sync-now]').trigger('click');
        await flushPromises();

        expect(window.showErrorToast).toHaveBeenCalledWith('Já existe uma sincronização em andamento.');
        expect(router.reload).toHaveBeenCalledWith({ only: ['runningSync', 'syncs'] });
    });

    it('parada além do prazo: oferece cancelar; sem tempo real oferece atualizar o status', async () => {
        live.connected = false;
        const stuck = payload({
            status: 'pending',
            status_label: 'Na fila',
            is_done: false,
            idle_seconds: 120,
            stall_after_seconds: 90,
        });
        const fetchMock = mockFetch({
            message: 'Sincronização cancelada.',
            sync: payload({ status: 'cancelled', status_label: 'Cancelada', status_color: 'dark' }),
        });
        const wrapper = mountPanel({ runningSync: stuck, syncs: [stuck] });

        expect(wrapper.text()).toContain('Fila parada.');
        expect(wrapper.text()).toContain('Tempo real indisponível');

        await wrapper.find('[data-sync-refresh]').trigger('click');
        expect(live.resync).toHaveBeenCalled();

        await wrapper.find('[data-sync-cancel]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toBe('/_routes/manager.ai-catalog-syncs.cancel/s1');
        expect(live.ref.value.status).toBe('cancelled');
        expect(router.reload).toHaveBeenCalledWith({ only: ['syncs'] });
    });

    it('o que mudou (preço de/para em USD) e histórico sempre visível com quem disparou', () => {
        const wrapper = mountPanel({
            syncs: [payload(), payload({ id: 's0', user: 'Ana Admin', created_at: '02/10/2026 10:00' })],
            syncDetails: {
                id: 's1',
                finished_at: '03/10/2026 03:41',
                lists: {
                    updated: [
                        {
                            provider: 'openai',
                            provider_label: 'OpenAI',
                            model: 'gpt-4o',
                            old: { input: 5, output: 15 },
                            new: { input: 2.5, output: 10 },
                        },
                    ],
                    unlisted: [{ provider: 'openai', provider_label: 'OpenAI', model: 'gpt-4-turbo', in_use: true }],
                },
            },
        });

        const updated = wrapper.find('[data-detail-list="updated"]');
        expect(updated.text()).toContain('gpt-4o');
        expect(updated.text().replace(/ /g, ' ')).toContain('de US$ 5,00 / US$ 15,00 para US$ 2,50 / US$ 10,00');
        expect(wrapper.find('[data-detail-list="unlisted"]').text()).toContain('Em uso');

        const rows = wrapper.findAll('[data-history] tbody tr');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Sistema');
        expect(rows[1].text()).toContain('Ana Admin');
    });
});
