import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AiAssistantPanel from '@/Components/Panel/AiAssistantPanel.vue';

/**
 * Cobre F3 (Onda 2): diff visual entre rascunho da IA e edição médica.
 *
 * O rascunho original é capturado em ingestResult; durante review, hasEdits
 * compara reviewText com originalDraft. Aprovar com edits anexa original_draft
 * ao payload (auditoria CFM).
 */
describe('AiAssistantPanel — diff visual', () => {
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
            title:     'Assistente de IA',
            show_diff: 'Ver edições',
            hide_diff: 'Ocultar edições',
            no_changes: 'Sem alterações pelo médico.',
            approve:   'Aprovar',
        },
        workflow_labels: { record_assist: 'Análise do prontuário' },
    };

    const baseContext = {
        workflow_default:  'record_assist',
        patient_id:        'p1',
        medical_record_id: 'r1',
    };

    function mountPanel() {
        return mount(AiAssistantPanel, {
            global: {
                stubs: {
                    OffcanvasPanel: {
                        template: '<div data-test="offcanvas"><slot name="header" /><slot /><slot name="footer" /></div>',
                        props: ['open', 'width'],
                    },
                },
            },
            props: { open: true, ai: baseAi, context: baseContext },
        });
    }

    beforeEach(() => {
        globalThis.window = globalThis.window ?? {};
        globalThis.window.axios = {
            post: vi.fn(() => Promise.resolve({ data: { run_id: 'r-abc' } })),
            get:  vi.fn(() => Promise.resolve({ data: { data: [] } })),
        };
    });

    it('hasEdits = false quando reviewText === originalDraft', async () => {
        const wrapper = mountPanel();
        const vm = wrapper.vm;
        vm.step = 'review';
        vm.originalDraft = 'rascunho da IA';
        vm.reviewText = 'rascunho da IA';
        await wrapper.vm.$nextTick();

        // hasEdits é computed; quando false, botão diff deve estar disabled OU mostrar "Sem alterações"
        const html = wrapper.html();
        expect(html).toContain('Sem alterações pelo médico');
    });

    it('hasEdits = true quando reviewText !== originalDraft mostra botão Ver edições', async () => {
        const wrapper = mountPanel();
        const vm = wrapper.vm;
        vm.step = 'review';
        vm.originalDraft = 'Paciente tem H40.1.';
        vm.reviewText = 'Paciente tem hipótese de H40.1.';
        await wrapper.vm.$nextTick();

        expect(wrapper.html()).toContain('Ver edições');
    });

    it('aprovar com edits envia original_draft no payload', async () => {
        const wrapper = mountPanel();
        const vm = wrapper.vm;
        vm.runId = 'r-abc';
        vm.step  = 'review';
        vm.originalDraft = 'rascunho original';
        vm.reviewText    = 'texto editado pelo médico';

        await vm.approve();
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledTimes(1);
        const [calledUrl, payload] = window.axios.post.mock.calls[0];
        expect(calledUrl).toContain('approve');
        expect(payload.final_output).toBe('texto editado pelo médico');
        expect(payload.original_draft).toBe('rascunho original');
    });

    it('aprovar sem edits NÃO envia original_draft no payload', async () => {
        const wrapper = mountPanel();
        const vm = wrapper.vm;
        vm.runId = 'r-abc';
        vm.step  = 'review';
        vm.originalDraft = 'mesmo texto';
        vm.reviewText    = 'mesmo texto';

        await vm.approve();
        await flushPromises();

        const [, payload] = window.axios.post.mock.calls[0];
        expect(payload.final_output).toBe('mesmo texto');
        expect(payload.original_draft).toBeUndefined();
    });

    it('toggleable: clicar "Ver edições" abre o bloco de diff', async () => {
        const wrapper = mountPanel();
        const vm = wrapper.vm;
        vm.step = 'review';
        vm.originalDraft = 'Paciente tem H40.1.';
        vm.reviewText = 'Paciente tem hipótese de H40.1.';
        await wrapper.vm.$nextTick();

        vm.showDiff = true;
        await wrapper.vm.$nextTick();

        const html = wrapper.html();
        // Pelo menos uma das palavras adicionadas aparece envolta em <ins>
        expect(html).toMatch(/<ins/);
    });
});
