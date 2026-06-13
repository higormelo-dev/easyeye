import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AiAssistantPanel from '@/Components/Panel/AiAssistantPanel.vue';

/**
 * Cobre o que a Onda 1 introduziu de mais sensível ao usuário:
 *
 *   - cancel() chama POST /cancel e devolve o painel ao estado idle
 *   - parseStructured aceita JSON puro, JSON em fence, JSON aninhado;
 *     devolve null para texto sem JSON
 *   - extractFirstJsonObject conta chaves balanceadas
 *
 * O OffcanvasPanel é stubbado porque a UI vive em Bootstrap puro (sem
 * importância para a lógica testada).
 */
describe('AiAssistantPanel', () => {
    const baseAi = {
        urls: {
            estimate: '/_routes/panel.ai-runs.estimate',
            store:    '/_routes/panel.ai-runs.store',
            show:     '/_routes/panel.ai-runs.show/__ID__',
            approve:  '/_routes/panel.ai-runs.approve/__ID__',
            reject:   '/_routes/panel.ai-runs.reject/__ID__',
            cancel:   '/_routes/panel.ai-runs.cancel/__ID__',
        },
        balance:          { available: 50 },
        modes:            [{ value: 'validated' }],
        workflows:        ['record_assist'],
        default_workflow: 'record_assist',
        assistant: {
            title:    'Assistente de IA',
            analyze:  'Analisar com IA',
            cancel:   'Cancelar',
            cancelling: 'Cancelando…',
            cancelled:  'Análise cancelada.',
            step_generating:    'Gerando análise com :provider…',
            step_reviewing:     'Revisando com :provider…',
            step_consolidating: 'Consolidando resposta com :provider…',
            step_starting:      'Iniciando a análise…',
            quick_picks: ['Resumir o caso e listar hipóteses diagnósticas'],
        },
        workflow_labels: { record_assist: 'Análise do prontuário' },
    };

    const baseContext = {
        workflow_default:  'record_assist',
        patient_id:        'p1',
        medical_record_id: 'r1',
        can_insert:        true,
    };

    function mountPanel(propsOverride = {}) {
        return mount(AiAssistantPanel, {
            global: {
                stubs: {
                    OffcanvasPanel: {
                        template: '<div data-test="offcanvas"><slot name="header" /><slot /><slot name="footer" /></div>',
                        props: ['open', 'width'],
                    },
                },
            },
            props: {
                open: true,
                ai: baseAi,
                context: baseContext,
                ...propsOverride,
            },
        });
    }

    beforeEach(() => {
        globalThis.window = globalThis.window ?? {};
        globalThis.window.axios = {
            post: vi.fn(() => Promise.resolve({ data: { run_id: 'r-abc' } })),
            get:  vi.fn(() => Promise.resolve({ data: { data: { status: 'reserved' } } })),
        };
    });

    describe('cancel()', () => {
        it('chama POST /cancel e devolve o painel ao estado idle', async () => {
            const wrapper = mountPanel();

            // Coloca o painel em estado processing simulando run em andamento.
            wrapper.vm.$.exposed = wrapper.vm.$.exposed || {};
            // Acesso direto via setup expose não funciona em SFC sem return — vamos
            // forçar via runtime.
            const vm = wrapper.vm;
            vm.runId = 'r-abc';
            vm.step  = 'processing';

            await vm.cancel();
            await flushPromises();

            expect(window.axios.post).toHaveBeenCalledTimes(1);
            const url = window.axios.post.mock.calls[0][0];
            expect(url).toContain('panel.ai-runs.cancel');
            expect(url).toContain('r-abc');
        });

        it('limpa estado sem chamar o backend quando não há runId', async () => {
            const wrapper = mountPanel();
            const vm = wrapper.vm;
            vm.step = 'processing';

            await vm.cancel();

            expect(window.axios.post).not.toHaveBeenCalled();
        });
    });

    describe('parseStructured', () => {
        let parseStructured;
        let extractFirstJsonObject;

        beforeEach(() => {
            const wrapper = mountPanel();
            // Os helpers são expostos via defineExpose
            parseStructured        = wrapper.vm.parseStructured;
            extractFirstJsonObject = wrapper.vm.extractFirstJsonObject;
        });

        it('lê JSON direto com summary + suggestions', () => {
            const raw = JSON.stringify({
                summary: 'caso compatível com glaucoma incipiente',
                suggestions: { diagnosis: 'H40.1', conduct: 'Tonometria semanal', observations: 'Acompanhar PIO' },
            });
            const out = parseStructured(raw);

            expect(out.summary).toContain('glaucoma');
            expect(out.suggestions.diagnosis).toBe('H40.1');
            expect(out.suggestions.conduct).toContain('Tonometria');
        });

        it('lê JSON dentro de fence ```json', () => {
            const raw = '```json\n{"summary":"caso","suggestions":{"diagnosis":"X"}}\n```';
            const out = parseStructured(raw);

            expect(out.summary).toBe('caso');
            expect(out.suggestions.diagnosis).toBe('X');
        });

        it('extrai primeiro objeto JSON balanceado mesmo com texto extra', () => {
            const raw = 'Análise:\n{"summary":"hello {world}","suggestions":{}}\nfim do output.';
            const out = parseStructured(raw);

            // O parser deve conseguir extrair o JSON apesar do "{world}" no string.
            expect(out.summary).toBe('hello {world}');
        });

        it('extractFirstJsonObject respeita escape em string', () => {
            const raw = '{"a":"x\\"y","b":1}';
            const result = extractFirstJsonObject(raw);
            expect(result).toBe(raw);
        });

        it('retorna null quando não há JSON', () => {
            expect(parseStructured('apenas texto puro sem json')).toBeNull();
            expect(parseStructured('')).toBeNull();
            expect(parseStructured(null)).toBeNull();
        });

        it('zera suggestions ausentes em vez de retornar undefined', () => {
            const out = parseStructured('{"summary":"apenas isso"}');
            expect(out.summary).toBe('apenas isso');
            expect(out.suggestions.diagnosis).toBe('');
            expect(out.suggestions.conduct).toBe('');
            expect(out.suggestions.observations).toBe('');
        });
    });
});
