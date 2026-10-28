import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { renderIndex } from './examManagerTestHelpers.js';

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
});

describe('Gerenciador de exames — saldo e estimativa em BRL', () => {
    it('estimate recebe cents e exibe apenas valor público em reais', async () => {
        ({ wrapper } = await renderIndex({
            ai: {
                enabled: true,
                labels: { credits_available: 'Saldo de IA disponível', estimated_credits: 'Valor estimado' },
                workflows: ['record_assist'],
                modes: [{ value: 'validated' }],
            },
        }));
        window.axios = {
            post: vi.fn().mockResolvedValue({
                data: {
                    balance: { available_cents: 12345, reserved_cents: 550, available: 987654 },
                    estimate: {
                        charge_cents: 125,
                        workflow: 'record_assist',
                        normalized_credits: 987654,
                        raw_cost_usd: 654321,
                        margin_multiplier: 100,
                        exchange_rate: 100,
                    },
                },
            }),
        };
        wrapper.vm.aiModalOpen = true;
        wrapper.vm.aiForm.workflow = 'record_assist';
        wrapper.vm.aiForm.user_prompt = 'Resumo de um cenário fictício para teste';
        await flushPromises();
        await wrapper.vm.estimateAiRun();
        await flushPromises();
        const dialog = wrapper.get('[role="dialog"]');
        expect(dialog.text()).toContain('R$\u00a0123,45');
        expect(dialog.text()).toContain('R$\u00a05,50');
        expect(dialog.text()).toContain('R$\u00a01,25');
        expect(dialog.text()).not.toMatch(/987654|654321|USD|markup|exchange_rate|custo do provedor/);
    });
});
