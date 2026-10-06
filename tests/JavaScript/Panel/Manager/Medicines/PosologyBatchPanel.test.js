import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import PosologyBatchPanel from '@/Pages/Panel/Manager/Medicines/PosologyBatchPanel.vue';

/**
 * Painel do lote "Gerar posologia com IA": progresso em tempo real (WebSocket,
 * sem polling), cancelar e histórico com falhas e custo real.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR', t_ui: { realtime_offline: 'Tempo real fora do ar' } } }),
    router: { post: vi.fn(), reload: vi.fn() },
}));
const live = vi.hoisted(() => ({ options: null, ref: null, connected: true, resync: null }));
vi.mock('@/composables/useImportProgress', async () => {
    const { computed } = await import('vue');
    return {
        useImportProgress: (importRef, options) => {
            live.ref = importRef;
            live.options = options;
            live.resync = vi.fn();
            return { realtimeConnected: computed(() => live.connected), resync: live.resync };
        },
    };
});

const t = new Proxy(
    {
        batch_progress_groups: ':processed de :total grupos',
        batch_remaining_notice: ':count grupo(s) ficaram de fora',
        batch_estimated: 'estimado :value',
        batch_eta_value: '≈ :value',
        batch_rate_hint: 'US$ 1 = :brl',
        batch_tile_cost_estimate: 'Estimativa do lote: :usd (≈ :brl)',
    },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);

const running = {
    id: 'b-1',
    status: 'processing',
    status_label: 'Processando',
    status_color: 'primary',
    phase_label: 'Gerando posologia',
    provider_label: 'OpenAI',
    total_groups: 200,
    processed_groups: 50,
    progress: 25,
    updated_count: 1200,
    skipped_count: 3,
    failed_groups: 1,
    ai_calls: 51,
    cost_usd: 0.0123,
    remaining_groups: 0,
    error: null,
    is_done: false,
    idle_seconds: 2,
    stall_after_seconds: 240,
    channel: 'manager.medicines.posology-batches.b-1',
};

function mountPanel(props = {}) {
    return mount(PosologyBatchPanel, { props: { running: null, batches: [], t, ...props } });
}

describe('PosologyBatchPanel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        live.connected = true;
    });

    it('mostra a barra com grupos, itens atualizados, falhas e custo real; avisa a página', () => {
        const wrapper = mountPanel({ running });
        const box = wrapper.find('[data-test="batch-progress"]');

        expect(box.find('.progress-bar').attributes('style')).toContain('width: 25%');
        expect(box.find('.progress-bar').classes()).toContain('progress-bar-animated');
        expect(box.find('[data-tile="groups"]').text()).toContain('50 / 200');
        expect(box.text()).toContain('1.200');
        expect(box.text()).toContain('Gerando posologia');
        expect(box.text()).toContain('US$');
        expect(wrapper.emitted('progress')[0]).toEqual([running]);
    });

    it('cards ao vivo: custo em dólar e real, estimativa, cotação, tempo restante e cancelar com ícone', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-05T12:10:00Z'));
        const wrapper = mountPanel({
            running: {
                ...running,
                cost_brl: 0.0664,
                estimated_cost_usd: 0.05,
                estimated_cost_brl: 0.27,
                usd_brl_rate: 5.4,
                usd_brl_is_fallback: false,
                // 50 grupos em 10 min → 150 restantes ≈ 30 min
                started_at: '2026-10-05T12:00:00Z',
            },
        });

        const cost = wrapper.find('[data-tile="cost"]').text().replace(/\s+/g, ' ');
        expect(cost).toContain('US$');
        expect(cost).toContain('R$');
        expect(cost).toContain('0,0664');
        expect(wrapper.find('[data-tile="eta"]').text()).toContain('30');
        expect(wrapper.find('[data-test="batch-rate"]').text()).toContain('5,40');
        expect(wrapper.find('[data-test="batch-estimate"]').text()).toContain('R$');

        const cancel = wrapper.find('[data-test="batch-cancel"]');
        expect(cancel.find('i.ti-circle-x').exists()).toBe(true);
        expect(cancel.classes()).toContain('d-inline-flex');
        vi.useRealTimers();
    });

    it('sem grupo processado ainda: tempo restante "calculando"; lote terminado não mostra tempo restante nem cancelar', () => {
        const pending = mountPanel({
            running: { ...running, processed_groups: 0, progress: 0, started_at: '2026-10-05T12:00:00Z' },
        });
        expect(pending.find('[data-tile="eta"]').text()).toContain('batch_eta_calculating');

        const done = mountPanel({ running: { ...running, is_done: true, status: 'done' } });
        expect(done.find('[data-tile="eta"]').exists()).toBe(false);
        expect(done.find('[data-test="batch-cancel"]').exists()).toBe(false);
    });

    it('acompanha o lote pelo canal do WebSocket e recarrega catálogo e histórico ao terminar', async () => {
        const wrapper = mountPanel({ running });
        expect(live.ref.value.channel).toBe('manager.medicines.posology-batches.b-1');

        live.ref.value = { ...running, processed_groups: 150, progress: 75 };
        await flushPromises();
        expect(wrapper.find('.progress-bar').attributes('style')).toContain('width: 75%');

        live.ref.value = { ...running, status: 'done', status_color: 'success', progress: 100, is_done: true };
        live.options.onDone();
        await flushPromises();

        expect(router.reload).toHaveBeenCalledWith({ only: ['posologyBatches', 'medicines', 'runningPosologyBatch'] });
        expect(wrapper.find('.progress-bar').classes()).toContain('bg-success');
        expect(wrapper.find('[data-test="batch-cancel"]').exists()).toBe(false);
        expect(wrapper.emitted('progress').at(-1)[0].is_done).toBe(true);
    });

    it('cancelar envia para a rota do lote', async () => {
        const wrapper = mountPanel({ running });

        await wrapper.find('[data-test="batch-cancel"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('posology-batches.cancel'),
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('na fila mostra "aguardando"; parado além do limite avisa', () => {
        const queued = { ...running, status: 'pending', idle_seconds: 5, stall_after_seconds: 90 };
        expect(mountPanel({ running: queued }).text()).toContain('batch_waiting_worker');

        const stalled = mountPanel({ running: { ...queued, idle_seconds: 300 } });
        expect(stalled.text()).toContain('batch_stalled');
        expect(stalled.find('[data-test="batch-progress"]').classes()).toContain('alert-warning');
    });

    it('sem tempo real: aviso e "Atualizar status" relê o estado', async () => {
        live.connected = false;
        const wrapper = mountPanel({ running });

        expect(wrapper.text()).toContain('Tempo real fora do ar');
        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('import_refresh_status'))
            .trigger('click');
        expect(live.resync).toHaveBeenCalledOnce();
    });

    it('terminou antes de conectar: o resultado vem do histórico', async () => {
        const wrapper = mountPanel({ running });
        await wrapper.setProps({
            running: null,
            batches: [{ ...running, status: 'done', status_color: 'success', progress: 100, is_done: true }],
        });

        expect(wrapper.find('.progress-bar').classes()).toContain('bg-success');
    });

    it('histórico: quem, filtros, IA, resultado, custo real/estimado, restante e falhas', () => {
        const wrapper = mountPanel({
            batches: [
                {
                    ...running,
                    status: 'done',
                    status_label: 'Concluída',
                    status_color: 'success',
                    is_done: true,
                    user: 'ADMIN',
                    created_at: '05/10/2026 10:00',
                    filters_summary: ['CMED/Anvisa', 'Só oftálmicos'],
                    cost_brl: 0.07,
                    estimated_cost_usd: 0.02,
                    remaining_groups: 30,
                    failures: [{ label: 'timolol 5 MG/ML · Solução oftálmica', error: 'A IA não encontrou.' }],
                },
            ],
        });
        const row = wrapper.find('[data-test="batch-row"]').text();

        expect(row).toContain('ADMIN');
        expect(row).toContain('CMED/Anvisa · Só oftálmicos');
        expect(row).toContain('OpenAI');
        expect(row).toContain('1.200');
        expect(row).toContain('R$');
        expect(row).toContain('estimado US$');
        expect(row).toContain('30 grupo(s) ficaram de fora');
        expect(row).toContain('timolol 5 MG/ML · Solução oftálmica');
    });

    it('histórico vazio', () => {
        expect(mountPanel().text()).toContain('batch_empty');
    });
});
