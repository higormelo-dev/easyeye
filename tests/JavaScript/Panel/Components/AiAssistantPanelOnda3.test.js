import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AiAssistantPanel from '@/Components/Panel/AiAssistantPanel.vue';

/**
 * Cobre os 3 fluxos novos da Onda 3 no painel:
 *   - P1: bloco "Meus prompts" e click aplica o texto
 *   - P2: canEscalate vira true em estados terminais com modo abaixo do topo
 *   - P5: feedbackPanel abre quando editRatio > 30% ao aprovar
 */
describe('AiAssistantPanel — Onda 3', () => {
    const baseUrls = {
        estimate:           '/_routes/panel.ai-runs.estimate',
        store:              '/_routes/panel.ai-runs.store',
        show:               '/_routes/panel.ai-runs.show/__ID__',
        approve:            '/_routes/panel.ai-runs.approve/__ID__',
        reject:             '/_routes/panel.ai-runs.reject/__ID__',
        cancel:             '/_routes/panel.ai-runs.cancel/__ID__',
        my_prompts_index:   '/_routes/panel.ai-runs.my-prompts.index',
        my_prompts_store:   '/_routes/panel.ai-runs.my-prompts.store',
        my_prompts_destroy: '/_routes/panel.ai-runs.my-prompts.destroy/__ID__',
        escalate:           '/_routes/panel.ai-runs.escalate/__ID__',
        feedback:           '/_routes/panel.ai-runs.feedback/__ID__',
    };

    const baseAi = {
        urls:    baseUrls,
        balance: { available: 50 },
        modes:   [{ value: 'validated' }],
        workflows: ['record_assist'],
        default_workflow: 'record_assist',
        assistant: {
            title:            'Assistente de IA',
            analyze:          'Analisar',
            approve:          'Aprovar',
            my_prompts:       'Meus prompts',
            save_as_my_prompt: 'Salvar como meu prompt',
            escalate_validated: 'Reanalisar com Validated',
            escalate_consensus: 'Reanalisar com Consensus',
            feedback_title:   'Você fez muitas alterações no rascunho.',
            feedback_skip:    'Pular feedback',
            feedback_submit_and_approve: 'Enviar e aprovar',
        },
        workflow_labels: { record_assist: 'Análise do prontuário' },
    };

    const baseContext = {
        workflow_default:  'record_assist',
        patient_id:        'p1',
        medical_record_id: 'r1',
        can_insert:        true,
    };

    function mountPanel(overrides = {}) {
        return mount(AiAssistantPanel, {
            global: {
                stubs: {
                    OffcanvasPanel: {
                        template: '<div data-test="offcanvas"><slot name="header" /><slot /><slot name="footer" /></div>',
                        props: ['open', 'width'],
                    },
                },
            },
            props: { open: true, ai: baseAi, context: baseContext, ...overrides },
        });
    }

    beforeEach(() => {
        globalThis.window = globalThis.window ?? {};
        globalThis.window.axios = {
            post:   vi.fn(() => Promise.resolve({ data: { run_id: 'r-abc' } })),
            get:    vi.fn((u) => {
                if (u.includes('my-prompts')) {
                    return Promise.resolve({ data: { data: [
                        { id: 'mp-1', label: 'Padrão consulta retorno', prompt: 'Resumir caso com base nas últimas 3 consultas.' },
                        { id: 'mp-2', label: 'Glaucoma',              prompt: 'Avaliar disco óptico e relação E/D do paciente.' },
                    ] } });
                }
                return Promise.resolve({ data: { data: [] } });
            }),
            delete: vi.fn(() => Promise.resolve({ data: {} })),
        };
        globalThis.window.confirm = vi.fn(() => true);
    });

    describe('P1 — Meus prompts inline', () => {
        it('carrega myPrompts ao abrir o painel', async () => {
            const wrapper = mountPanel();
            await flushPromises();

            expect(window.axios.get).toHaveBeenCalledWith(expect.stringContaining('my-prompts'));
            expect(wrapper.vm.myPrompts.length).toBe(2);
        });

        it('click em "Padrão consulta retorno" seta o form.user_prompt', async () => {
            const wrapper = mountPanel();
            await flushPromises();

            const promptBtn = wrapper.findAll('button').find(b => b.text() === 'Padrão consulta retorno');
            await promptBtn.trigger('click');

            expect(wrapper.vm.form.user_prompt).toContain('Resumir caso');
        });

        it('saveCurrentPrompt chama POST my-prompts/store', async () => {
            const wrapper = mountPanel();
            await flushPromises();
            const vm = wrapper.vm;
            vm.form.user_prompt = 'um prompt válido com mais de 12 caracteres';

            globalThis.window.prompt = vi.fn(() => 'Novo prompt');
            window.axios.post.mockResolvedValueOnce({ data: { id: 'mp-3', label: 'Novo prompt', prompt: vm.form.user_prompt } });

            await vm.saveCurrentPrompt();
            await flushPromises();

            const calls = window.axios.post.mock.calls.filter(c => c[0].includes('my-prompts'));
            expect(calls.length).toBe(1);
        });
    });

    describe('P2 — Escalate', () => {
        it('canEscalate true para run terminal aprovado em economy', async () => {
            const wrapper = mountPanel();
            const vm = wrapper.vm;
            vm.runId = 'r-abc';
            vm.runStatus = 'approved';
            vm.runMode = 'economy';
            await wrapper.vm.$nextTick();

            expect(vm.canEscalate).toBe(true);
            expect(vm.nextMode).toBe('validated');
        });

        it('canEscalate false em modo consensus (topo)', async () => {
            const wrapper = mountPanel();
            const vm = wrapper.vm;
            vm.runId = 'r-abc';
            vm.runStatus = 'approved';
            vm.runMode = 'consensus';
            await wrapper.vm.$nextTick();

            expect(vm.canEscalate).toBe(false);
        });

        it('canEscalate false em status não-terminal', async () => {
            const wrapper = mountPanel();
            const vm = wrapper.vm;
            vm.runId = 'r-abc';
            vm.runStatus = 'waiting_approval';
            vm.runMode = 'economy';
            await wrapper.vm.$nextTick();

            expect(vm.canEscalate).toBe(false);
        });

        it('escalate() chama POST e entra em processing', async () => {
            const wrapper = mountPanel();
            const vm = wrapper.vm;
            vm.runId = 'r-old';
            vm.runStatus = 'failed';
            vm.runMode = 'economy';

            window.axios.post.mockResolvedValueOnce({ data: { run_id: 'r-new', status: 'reserved', mode: 'validated' } });

            await vm.escalate();
            await flushPromises();

            expect(vm.runId).toBe('r-new');
            expect(vm.step).toBe('processing');
        });
    });

    describe('P5 — Feedback inline (edit ratio > 30%)', () => {
        it('editRatioPercent calcula proporção de palavras alteradas', async () => {
            const wrapper = mountPanel();
            const vm = wrapper.vm;
            vm.originalDraft = 'um dois três quatro cinco';
            vm.reviewText = 'um dois NOVO quatro cinco';
            await wrapper.vm.$nextTick();

            // 1 alterado / 5 totais = 20%
            expect(vm.editRatioPercent).toBeLessThan(50);
        });

        it('shouldAskFeedback true quando edit > 30%', async () => {
            const wrapper = mountPanel();
            const vm = wrapper.vm;
            vm.originalDraft = 'um dois três quatro cinco';
            vm.reviewText = 'A B C D cinco'; // 4/5 alterados = 80%
            await wrapper.vm.$nextTick();

            expect(vm.shouldAskFeedback).toBe(true);
        });

        it('maybeApprove abre feedbackPanel quando shouldAskFeedback', async () => {
            const wrapper = mountPanel();
            const vm = wrapper.vm;
            vm.runId = 'r-abc';
            vm.step  = 'review';
            vm.originalDraft = 'um dois três quatro cinco';
            vm.reviewText = 'A B C D cinco';
            await wrapper.vm.$nextTick();

            vm.maybeApprove();
            await wrapper.vm.$nextTick();

            expect(vm.feedbackPanel).toBe(true);
            // approve NÃO foi chamado (axios.post ainda não para /approve)
            const approveCalls = window.axios.post.mock.calls.filter(c => c[0].includes('approve'));
            expect(approveCalls.length).toBe(0);
        });

        it('toggleFeedbackTag adiciona/remove tag', async () => {
            const wrapper = mountPanel();
            const vm = wrapper.vm;

            vm.toggleFeedbackTag('language');
            expect(vm.feedbackTags).toEqual(['language']);

            vm.toggleFeedbackTag('language');
            expect(vm.feedbackTags).toEqual([]);
        });

        it('skipFeedbackAndApprove fecha o painel e chama approve direto', async () => {
            const wrapper = mountPanel();
            const vm = wrapper.vm;
            vm.runId = 'r-abc';
            vm.step  = 'review';
            vm.reviewText = 'algum texto';
            vm.feedbackPanel = true;

            vm.skipFeedbackAndApprove();
            await flushPromises();

            expect(vm.feedbackPanel).toBe(false);
            const approveCalls = window.axios.post.mock.calls.filter(c => c[0].includes('approve'));
            expect(approveCalls.length).toBeGreaterThanOrEqual(1);
        });
    });
});
