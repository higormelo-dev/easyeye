import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AiFloatingAssistant from '@/Components/Panel/AiFloatingAssistant.vue';

/**
 * Assistente flutuante: medidor da carteira (créditos disponíveis + franquia
 * da janela) e paywall quando a mensagem é recusada por falta de créditos
 * (422 ai_insufficient_credits) ou pelo acesso limitado (402).
 */
describe('AiFloatingAssistant — medidor e paywall', () => {
    const paywall = {
        code: 'ai_insufficient_credits',
        reason: 'quota_exhausted',
        can_purchase: false,
        purchase_url: null,
        renews_on: '2026-11-04',
        texts: {
            title: 'Sem créditos de IA disponíveis',
            message: 'A franquia de IA deste mês acabou. A franquia renova em :date.',
            buy: 'Comprar créditos',
            ask_admin: 'Peça ao administrador da clínica para comprar créditos de IA.',
            limited: 'O pagamento da assinatura está em atraso. Regularize o pagamento para voltar a usar a IA.',
        },
    };

    function mountWidget(overrides = {}) {
        return mount(AiFloatingAssistant, {
            attachTo: document.body,
            global: { stubs: { Teleport: true } },
            props: {
                ai: {
                    enabled: true,
                    mode: 'validated',
                    balance: { available: 12 },
                    quota: { monthly_quota: 80, consumed_credits: 70, renews_on: '2026-11-04' },
                    paywall,
                    urls: {
                        store: '/_routes/panel.ai-runs.store',
                        show: '/_routes/panel.ai-runs.show/__ID__',
                        approve: '/_routes/panel.ai-runs.approve/__ID__',
                    },
                    t: {
                        title: 'Assistente virtual',
                        error_generic: 'Não foi possível obter resposta. Tente novamente.',
                        credits_available: ':count créditos disponíveis',
                        quota_status: 'Franquia: :used/:quota · renova em :date',
                        quota_status_undated: 'Franquia: :used/:quota',
                        quota_status_expiring: 'Franquia: :used/:quota · vale até :date',
                    },
                    ...overrides,
                },
            },
        });
    }

    async function openAndSend(wrapper) {
        await wrapper.find('button.ai-fab').trigger('click');
        wrapper.vm.userPrompt = 'Qual a dose usual de timolol para glaucoma?';
        await wrapper.vm.sendMessage();
        await flushPromises();
    }

    beforeEach(() => {
        globalThis.window.axios = {
            post: vi.fn(),
            get: vi.fn(),
        };
    });

    it('mostra créditos disponíveis e a franquia da janela com a renovação', async () => {
        const wrapper = mountWidget();
        await wrapper.find('button.ai-fab').trigger('click');

        const meter = wrapper.find('[data-test="ai-chat-meter"]').text();
        expect(meter).toContain('12 créditos disponíveis');
        expect(meter).toContain('Franquia: 70/80 · renova em 04/11/2026');
    });

    it('cota que sobrou (assinatura sem franquia) → mostra até quando vale, sem prometer renovação', async () => {
        const wrapper = mountWidget({
            quota: { monthly_quota: 80, consumed_credits: 30, renews_on: null, expires_on: '2026-11-15' },
        });
        await wrapper.find('button.ai-fab').trigger('click');

        const meter = wrapper.find('[data-test="ai-chat-meter"]').text();
        expect(meter).toContain('Franquia: 30/80 · vale até 15/11/2026');
        expect(meter).not.toContain('renova');
    });

    it('422 sem créditos → aviso com a situação e "peça ao administrador" (médico)', async () => {
        window.axios.post.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    message: 'Saldo insuficiente de créditos IA para executar este fluxo.',
                    code: 'ai_insufficient_credits',
                    details: { requested: 4, available: 0 },
                    paywall,
                },
            },
        });

        const wrapper = mountWidget();
        await openAndSend(wrapper);

        const notice = wrapper.find('[data-test="ai-paywall"]');
        expect(notice.exists()).toBe(true);
        expect(notice.text()).toContain('renova em 04/11/2026');
        expect(wrapper.find('[data-test="ai-paywall-ask-admin"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Tente novamente');
    });

    it('402 acesso limitado → pede para regularizar o pagamento', async () => {
        window.axios.post.mockRejectedValue({
            response: { status: 402, data: { message: 'Acesso limitado.', access_level: 'limited' } },
        });

        const wrapper = mountWidget();
        await openAndSend(wrapper);

        expect(wrapper.find('[data-test="ai-paywall-message"]').text()).toContain('Regularize o pagamento');
    });

    // FT-10: "1 créditos disponíveis" e "1500" sem separador.
    it('medidor: singular/plural e números no idioma', async () => {
        const t = {
            credits_available: ':count crédito disponível|:count créditos disponíveis',
            quota_status: 'Franquia: :used/:quota · renova em :date',
            quota_status_undated: 'Franquia: :used/:quota',
        };

        const one = mountWidget({ balance: { available: 1 }, t });
        await one.find('button.ai-fab').trigger('click');
        expect(one.find('[data-test="ai-chat-meter"]').text()).toContain('1 crédito disponível');
        one.unmount();

        const many = mountWidget({
            balance: { available: 1500 },
            quota: { monthly_quota: 2000, consumed_credits: 1200, renews_on: '2026-11-04' },
            t,
        });
        await many.find('button.ai-fab').trigger('click');
        const meter = many.find('[data-test="ai-chat-meter"]').text();
        expect(meter).toContain('1.500 créditos disponíveis');
        expect(meter).toContain('Franquia: 1.200/2.000 · renova em 04/11/2026');
        many.unmount();
    });

    // IA-2: em atraso, a renovação depende do pagamento.
    it('medidor: cliente em atraso vê a renovação condicionada ao pagamento', async () => {
        const wrapper = mountWidget({
            quota: { monthly_quota: 80, consumed_credits: 70, renews_on: null, renews_if_paid_on: '2026-11-19' },
            t: {
                credits_available: ':count créditos disponíveis',
                quota_status: 'Franquia: :used/:quota · renova em :date',
                quota_status_if_paid:
                    'Franquia: :used/:quota · renova em :date se o pagamento em atraso for confirmado',
                quota_status_undated: 'Franquia: :used/:quota',
                quota_status_expiring: 'Franquia: :used/:quota · vale até :date',
            },
        });
        await wrapper.find('button.ai-fab').trigger('click');

        expect(wrapper.find('[data-test="ai-chat-meter"]').text()).toContain(
            'Franquia: 70/80 · renova em 19/11/2026 se o pagamento em atraso for confirmado',
        );
    });

    it('outros erros continuam com a mensagem genérica', async () => {
        window.axios.post.mockRejectedValue({ response: { status: 500, data: {} } });

        const wrapper = mountWidget();
        await openAndSend(wrapper);

        expect(wrapper.find('[data-test="ai-paywall"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Não foi possível obter resposta. Tente novamente.');
    });
});
