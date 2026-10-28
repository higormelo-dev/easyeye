import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';
import AiUsageRunDrawer from '@/Pages/Panel/Manager/AiUsage/AiUsageRunDrawer.vue';

/**
 * Detalhe de uma execução: metadados, custo em R$ e US$ e as chamadas aos
 * provedores (com erro já saneado pelo servidor). Resposta de uma execução
 * anterior (usuário trocou de linha) é descartada.
 */
vi.mock('axios', () => ({ default: { get: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { locale: 'pt_BR' } }) }));
vi.mock('@/Components/Panel/OffcanvasPanel.vue', () => ({
    default: {
        props: ['open', 'loading'],
        template:
            '<aside v-if="open" :data-loading="String(loading)"><header><slot name="header" /></header><slot /></aside>',
    },
}));

const t = new Proxy(
    {
        internal: 'Uso interno (plataforma)',
        drawer: new Proxy(
            { tokens_value: ':in entrada · :out saída' },
            { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
        ),
        columns: {},
    },
    { get: (o, k) => (typeof k === 'string' ? (o[k] ?? k) : o[k]) },
);

const detail = {
    id: 'run-2',
    workflow_label: 'Análise do caso (prontuário)',
    status: 'failed',
    status_label: 'Falhou',
    is_internal: false,
    is_escalation: false,
    error: 'Não foi possível concluir a análise.',
    entity_name: 'CLÍNICA ALFA',
    user_name: 'DRA. ANA',
    created_at: '2026-10-03T09:00:00-03:00',
    updated_at: '2026-10-03T09:00:05-03:00',
    mode_label: 'Validated',
    estimated_credits: 3,
    reserved_credits: 3,
    consumed_credits: 0,
    cost_usd: 0,
    cost_brl: 0,
    calls: [
        {
            role_label: 'Geração',
            provider_label: 'ChatGPT',
            model: 'gpt-4.1',
            status: 'failed',
            status_label: 'Falhou',
            tokens_in: 0,
            tokens_out: 0,
            tokens_reasoning: 0,
            latency_ms: 1200,
            cost_usd: null,
            cost_brl: null,
            error: 'OpenAI request failed [401]: [REDACTED:KEY]',
        },
    ],
};

const text = (wrapper) => wrapper.text().replace(/ /g, ' ');

describe('Manager → Uso de IA: detalhe da execução', () => {
    beforeEach(() => vi.clearAllMocks());

    it('carrega e mostra metadados, motivo da falha e as chamadas', async () => {
        axios.get.mockResolvedValue({ data: { data: detail } });
        const wrapper = mount(AiUsageRunDrawer, { props: { open: true, runId: 'run-2', t } });
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith('/_routes/manager.ai-usage.runs.show/run-2');
        expect(wrapper.text()).toContain('Análise do caso (prontuário)');
        expect(wrapper.text()).toContain('Não foi possível concluir a análise.');
        expect(wrapper.text()).toContain('CLÍNICA ALFA');
        expect(wrapper.findAll('[data-test="run-call"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('[REDACTED:KEY]');
        expect(text(wrapper)).toContain('1,2 s');
    });

    it('falha ao carregar mostra aviso', async () => {
        axios.get.mockRejectedValue(new Error('500'));
        const wrapper = mount(AiUsageRunDrawer, { props: { open: true, runId: 'run-2', t } });
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('load_error');
    });

    it('resposta atrasada de outra execução é descartada', async () => {
        let resolveFirst;
        axios.get.mockReturnValueOnce(new Promise((resolve) => (resolveFirst = resolve))).mockResolvedValueOnce({
            data: { data: { ...detail, id: 'run-3', workflow_label: 'Assistente virtual', calls: [] } },
        });

        const wrapper = mount(AiUsageRunDrawer, { props: { open: true, runId: 'run-2', t } });
        await wrapper.setProps({ runId: 'run-3' });
        await flushPromises();
        resolveFirst({ data: { data: detail } });
        await flushPromises();

        expect(wrapper.text()).toContain('Assistente virtual');
        expect(wrapper.text()).not.toContain('Análise do caso');
    });
});
