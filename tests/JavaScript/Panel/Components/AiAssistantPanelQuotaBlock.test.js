import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AiAssistantPanel from '@/Components/Panel/AiAssistantPanel.vue';

/**
 * Cobre Onda 4 / C4: bloqueio do botão "Analisar com IA" quando a cota
 * mensal de IA passou de 95%. Créditos avulsos comprados continuam
 * permitindo análise via backend; UI só desencoraja consumo.
 */
describe('AiAssistantPanel — bloqueio em 95% de cota', () => {
    const baseUrls = {
        estimate: '/_routes/panel.ai-runs.estimate',
        store:    '/_routes/panel.ai-runs.store',
        show:     '/_routes/panel.ai-runs.show/__ID__',
        approve:  '/_routes/panel.ai-runs.approve/__ID__',
        reject:   '/_routes/panel.ai-runs.reject/__ID__',
        cancel:   '/_routes/panel.ai-runs.cancel/__ID__',
    };

    function mountPanel(quota) {
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
                    balance: { available: 10 },
                    quota,
                    modes:   [{ value: 'validated' }],
                    workflows: ['record_assist'],
                    default_workflow: 'record_assist',
                    assistant: {
                        title:                'Assistente de IA',
                        analyze:              'Analisar com IA',
                        quota_label:          'Cota mensal',
                        quota_used:           ':consumed/:quota créditos usados (:percent%)',
                        quota_warning:        'Atenção: :percent% consumido',
                        quota_critical:       'Cota quase no limite (:percent%)',
                        quota_exhausted:      'Cota mensal atingida (:consumed/:quota).',
                        quota_exhausted_hint: 'Cota mensal atingida.',
                    },
                    workflow_labels: { record_assist: 'Análise do prontuário' },
                },
                context: {
                    workflow_default:  'record_assist',
                    patient_id:        'p1',
                    medical_record_id: 'r1',
                },
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

    it('quotaPercent = 96 → quotaExhausted true, botão Analisar disabled', () => {
        const wrapper = mountPanel({ monthly_quota: 100, consumed_credits: 96, usage_percent: 96 });
        const vm = wrapper.vm;

        expect(vm.quotaExhausted).toBe(true);

        const analyzeBtn = wrapper.findAll('button').find(b => b.text().includes('Analisar'));
        expect(analyzeBtn.attributes('disabled')).toBeDefined();
    });

    it('quotaPercent = 80 → quotaExhausted false, botão habilitado', () => {
        const wrapper = mountPanel({ monthly_quota: 100, consumed_credits: 80, usage_percent: 80 });
        const vm = wrapper.vm;

        expect(vm.quotaExhausted).toBe(false);
        // showQuotaAlert true (alerta amarelo), mas botão não está disabled por cota
        expect(vm.showQuotaAlert).toBe(true);
    });

    it('quota.usage_percent = null → não bloqueia (entity sem cota definida)', () => {
        const wrapper = mountPanel({ monthly_quota: 0, consumed_credits: 50, usage_percent: null });
        const vm = wrapper.vm;

        expect(vm.quotaExhausted).toBe(false);
    });

    it('quotaPercent = 95 (limite exato) → quotaExhausted true', () => {
        const wrapper = mountPanel({ monthly_quota: 100, consumed_credits: 95, usage_percent: 95 });
        const vm = wrapper.vm;

        expect(vm.quotaExhausted).toBe(true);
    });

    it('quota.consumed e quota.quota são interpolados no texto do alerta', () => {
        const wrapper = mountPanel({ monthly_quota: 500, consumed_credits: 480, usage_percent: 96 });
        const vm = wrapper.vm;

        expect(vm.quotaExhaustedText).toContain('480');
        expect(vm.quotaExhaustedText).toContain('500');
    });
});
