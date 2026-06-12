import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AiAssistantPanel from '@/Components/Panel/AiAssistantPanel.vue';

/**
 * Cobre F2 (Onda 2): quick picks aceitando tanto formato categorizado (dict
 * categoria → list<prompt>) quanto o legado list<prompt>.
 *
 * No formato categorizado a UI renderiza chips de categoria; click no chip
 * altera o conjunto de prompts visíveis. Click no prompt seta o user_prompt.
 */
describe('AiAssistantPanel — quick picks categorizados', () => {
    const baseUrls = {
        estimate: '/_routes/panel.ai-runs.estimate',
        store:    '/_routes/panel.ai-runs.store',
        show:     '/_routes/panel.ai-runs.show/__ID__',
        approve:  '/_routes/panel.ai-runs.approve/__ID__',
        reject:   '/_routes/panel.ai-runs.reject/__ID__',
        cancel:   '/_routes/panel.ai-runs.cancel/__ID__',
    };

    function mountPanel(quickPicks, overrides = {}) {
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
                ai: {
                    urls:    baseUrls,
                    balance: { available: 50 },
                    modes:   [{ value: 'validated' }],
                    workflows: ['record_assist'],
                    default_workflow: 'record_assist',
                    assistant: {
                        title:      'Assistente de IA',
                        analyze:    'Analisar',
                        quick_picks_label: 'Sugestões rápidas',
                        quick_picks: quickPicks,
                    },
                    workflow_labels: { record_assist: 'Análise do prontuário' },
                },
                context: {
                    workflow_default:  'record_assist',
                    patient_id:        'p1',
                    medical_record_id: 'r1',
                },
                ...overrides,
            },
        });
    }

    beforeEach(() => {
        globalThis.window = globalThis.window ?? {};
        globalThis.window.axios = {
            post: vi.fn(() => Promise.resolve({ data: { run_id: 'r-abc' } })),
            get:  vi.fn(() => Promise.resolve({ data: { data: [] } })),
        };
    });

    it('formato categorizado: renderiza chips de categoria', () => {
        const wrapper = mountPanel({
            'Geral':    ['prompt geral 1', 'prompt geral 2'],
            'Glaucoma': ['prompt glaucoma 1'],
        });

        // Chips (1 chip por categoria)
        const buttons = wrapper.findAll('button').map(b => b.text());
        expect(buttons).toContain('Geral');
        expect(buttons).toContain('Glaucoma');
    });

    it('formato categorizado: ativa a primeira categoria por default e mostra seus prompts', () => {
        const wrapper = mountPanel({
            'Geral':    ['prompt geral 1', 'prompt geral 2'],
            'Glaucoma': ['prompt glaucoma 1'],
        });

        const html = wrapper.html();
        expect(html).toContain('prompt geral 1');
        expect(html).toContain('prompt geral 2');
        expect(html).not.toContain('prompt glaucoma 1');
    });

    it('formato categorizado: trocar categoria muda a lista de prompts', async () => {
        const wrapper = mountPanel({
            'Geral':    ['prompt geral 1'],
            'Glaucoma': ['prompt glaucoma 1', 'prompt glaucoma 2'],
        });

        const glaucomaBtn = wrapper.findAll('button').find(b => b.text() === 'Glaucoma');
        await glaucomaBtn.trigger('click');
        await wrapper.vm.$nextTick();

        const html = wrapper.html();
        expect(html).toContain('prompt glaucoma 1');
        expect(html).toContain('prompt glaucoma 2');
        expect(html).not.toContain('prompt geral 1');
    });

    it('formato categorizado: click no prompt seta user_prompt', async () => {
        const wrapper = mountPanel({
            'Geral': ['prompt geral 1'],
        });

        const promptBtn = wrapper.findAll('button').find(b => b.text() === 'prompt geral 1');
        await promptBtn.trigger('click');
        await wrapper.vm.$nextTick();

        const textarea = wrapper.find('textarea');
        expect(textarea.element.value).toBe('prompt geral 1');
    });

    it('formato legado (string[]) renderiza lista plana sem chips de categoria', () => {
        const wrapper = mountPanel(['prompt 1', 'prompt 2', 'prompt 3']);

        const buttonsText = wrapper.findAll('button').map(b => b.text());
        expect(buttonsText).toContain('prompt 1');
        expect(buttonsText).toContain('prompt 2');
        expect(buttonsText).toContain('prompt 3');
        // Não há chips de categoria nesta forma
        expect(buttonsText).not.toContain('Geral');
        expect(buttonsText).not.toContain('Glaucoma');
    });
});
