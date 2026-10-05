import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { Link } from '@inertiajs/vue3';
import AiPaywallNotice from '@/Components/Panel/AiPaywallNotice.vue';

/**
 * Aviso de "sem créditos de IA": mensagem por situação (data formatada no
 * idioma) e o próximo passo — "Comprar créditos" para quem pode comprar,
 * "peça ao administrador" para os demais; acesso limitado orienta a
 * regularizar o pagamento.
 */
describe('AiPaywallNotice', () => {
    const texts = {
        title: 'Sem créditos de IA disponíveis',
        message: 'A franquia de IA deste mês acabou. A franquia renova em :date.',
        buy: 'Comprar créditos',
        ask_admin: 'Peça ao administrador da clínica para comprar créditos de IA.',
        limited: 'O pagamento da assinatura está em atraso. Regularize o pagamento para voltar a usar a IA.',
    };

    it('admin: mensagem com a data no idioma e botão "Comprar créditos" para os pacotes', () => {
        const wrapper = mount(AiPaywallNotice, {
            props: {
                paywall: {
                    reason: 'quota_exhausted',
                    can_purchase: true,
                    purchase_url: '/panel/ai/usage#ai-credit-packages',
                    renews_on: '2026-11-04',
                    texts,
                },
            },
        });

        expect(wrapper.text()).toContain('Sem créditos de IA disponíveis');
        expect(wrapper.find('[data-test="ai-paywall-message"]').text()).toBe(
            'A franquia de IA deste mês acabou. A franquia renova em 04/11/2026.',
        );
        expect(wrapper.findComponent(Link).props('href')).toBe('/panel/ai/usage#ai-credit-packages');
        expect(wrapper.find('[data-test="ai-paywall-buy"]').text()).toContain('Comprar créditos');
        expect(wrapper.find('[data-test="ai-paywall-ask-admin"]').exists()).toBe(false);
    });

    it('na própria tela de compra (onBuy): o botão abre os pacotes/checkout em vez de navegar', async () => {
        let bought = 0;
        const wrapper = mount(AiPaywallNotice, {
            props: {
                paywall: {
                    reason: 'complimentary',
                    can_purchase: true,
                    purchase_url: '/panel/ai/usage#ai-credit-packages',
                    texts,
                },
                onBuy: () => bought++,
            },
        });

        expect(wrapper.findComponent(Link).exists()).toBe(false);
        await wrapper.get('[data-test="ai-paywall-buy"]').trigger('click');
        expect(bought).toBe(1);
    });

    it('quem não pode comprar: "peça ao administrador", sem botão de compra', () => {
        const wrapper = mount(AiPaywallNotice, {
            props: {
                paywall: {
                    reason: 'complimentary',
                    can_purchase: false,
                    purchase_url: null,
                    renews_on: null,
                    texts: { ...texts, message: 'A cortesia não inclui franquia mensal de IA.' },
                },
            },
        });

        expect(wrapper.find('[data-test="ai-paywall-message"]').text()).toBe(
            'A cortesia não inclui franquia mensal de IA.',
        );
        expect(wrapper.find('[data-test="ai-paywall-ask-admin"]').text()).toContain('Peça ao administrador');
        expect(wrapper.find('[data-test="ai-paywall-buy"]').exists()).toBe(false);
    });

    it('cota que sobrou e acabou: a data é até quando ela valia (expires_on)', () => {
        const wrapper = mount(AiPaywallNotice, {
            props: {
                paywall: {
                    reason: 'complimentary',
                    can_purchase: false,
                    purchase_url: null,
                    renews_on: null,
                    expires_on: '2026-11-15',
                    texts: {
                        ...texts,
                        message: 'A cota de IA que restava, válida até :date, acabou e não será renovada.',
                    },
                },
            },
        });

        expect(wrapper.find('[data-test="ai-paywall-message"]').text()).toBe(
            'A cota de IA que restava, válida até 15/11/2026, acabou e não será renovada.',
        );
    });

    it('acesso limitado: pede para regularizar o pagamento, mesmo para quem compraria', () => {
        const wrapper = mount(AiPaywallNotice, {
            props: {
                limited: true,
                paywall: { can_purchase: true, purchase_url: '/panel/ai/usage', texts },
            },
        });

        expect(wrapper.find('[data-test="ai-paywall-message"]').text()).toContain('Regularize o pagamento');
        expect(wrapper.find('[data-test="ai-paywall-buy"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="ai-paywall-ask-admin"]').exists()).toBe(false);
    });

    it('sem payload do paywall, usa a mensagem do servidor', () => {
        const wrapper = mount(AiPaywallNotice, {
            props: { paywall: null, limited: true, fallbackMessage: 'Acesso limitado.' },
        });

        expect(wrapper.find('[data-test="ai-paywall-message"]').text()).toBe('Acesso limitado.');
    });

    // IA-2: cliente em atraso — a renovação depende do pagamento.
    it('renovação condicionada ao pagamento: a data vem de renews_if_paid_on', () => {
        const wrapper = mount(AiPaywallNotice, {
            props: {
                paywall: {
                    reason: 'quota_exhausted',
                    can_purchase: false,
                    renews_on: null,
                    renews_if_paid_on: '2026-11-19',
                    texts: {
                        ...texts,
                        message: 'A franquia só renova em :date se o pagamento estiver confirmado até lá.',
                    },
                },
            },
        });

        expect(wrapper.find('[data-test="ai-paywall-message"]').text()).toBe(
            'A franquia só renova em 19/11/2026 se o pagamento estiver confirmado até lá.',
        );
    });

    // FT-1 e FT-9: contraste e anúncio ao leitor de tela.
    it('texto na cor de ênfase; vindo da tela é status, vindo da resposta (422/402) é alerta', () => {
        const props = { paywall: { reason: 'complimentary', can_purchase: false, texts } };

        const fromPage = mount(AiPaywallNotice, { props });
        const notice = fromPage.get('[data-test="ai-paywall"]');
        expect(notice.classes()).toContain('text-warning-emphasis');
        expect(notice.attributes('role')).toBe('status');

        const fromResponse = mount(AiPaywallNotice, { props: { ...props, urgent: true } });
        expect(fromResponse.get('[data-test="ai-paywall"]').attributes('role')).toBe('alert');
    });
});
