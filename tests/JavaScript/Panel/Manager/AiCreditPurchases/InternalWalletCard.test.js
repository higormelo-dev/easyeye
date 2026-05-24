import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import InternalWalletCard from '@/Pages/Panel/Manager/AiCreditPurchases/InternalWalletCard.vue';

/**
 * Testes do card de carteira interna (entity-mãe SaaS).
 *
 * Cobre:
 *   - Renderização condicional via prop `wallet`
 *   - Exibição de saldo unificado (cota + balance)
 *   - Cálculo de quotaPct (barra de progresso)
 *   - Cálculo de providerPct (% de consumo por provedor)
 *   - Indicador de cota expirada
 *   - Exibição condicional do bloco "consumo por provedor" (só se houve consumo)
 *   - Botão "Adicionar créditos" só renderiza se permission permitir
 *   - Emit add-credit com entity_id
 */
describe('InternalWalletCard', () => {
    const baseWallet = {
        entity_id: 'ent-internal-123',
        entity_name: 'Easyeye Internal',
        balance: {
            available: 500,
            balance: 350,
            quota_remaining: 150,
            quota_total: 200,
            quota_used: 50,
            quota_expired: false,
            quota_period_ends_at: '2026-06-30T23:59:59Z',
            reserved: 10,
            total: 510,
            lifetime_purchased: 1000,
            lifetime_consumed: 850,
            consumed_by_provider: {
                openai: 500,
                anthropic: 250,
                gemini: 100,
            },
        },
    };

    const tStubs = {
        manual:          { badge_internal: 'Sua empresa' },
        internal_wallet: {
            title:                'Sua empresa — consumo interno',
            subtitle:             'Saldo da entidade administrativa',
            available:            'Disponível agora',
            includes_quota:       'cota + comprado',
            quota:                'Cota mensal',
            quota_expired:        'expirada',
            resets_at:            'Reseta em',
            balance:              'Comprados (não expiram)',
            reserved:             'reservados',
            consumed_by_provider: 'Consumo histórico por provedor',
            add_credit:           'Adicionar créditos',
        },
    };

    function mountCard(propsOverride = {}) {
        return mount(InternalWalletCard, {
            props: {
                wallet:      baseWallet,
                permissions: { create_manual_for_internal: true },
                t:           tStubs,
                ...propsOverride,
            },
        });
    }

    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('não renderiza nada quando wallet=null', () => {
        const wrapper = mountCard({ wallet: null });
        expect(wrapper.find('.internal-wallet-card').exists()).toBe(false);
    });

    it('renderiza card com nome da entidade e badge "Sua empresa"', () => {
        const wrapper = mountCard();
        const text = wrapper.text();

        expect(text).toContain('Easyeye Internal');
        expect(text).toContain('Sua empresa');
    });

    it('exibe disponível, cota restante e balance comprado formatados', () => {
        const wrapper = mountCard();
        const text = wrapper.text();

        expect(text).toContain('500');                                  // available
        expect(text).toContain('150');                                  // quota_remaining
        expect(text).toContain('350');                                  // balance
    });

    it('quotaPct = (used / total) * 100 = 25% no caso 50/200', () => {
        const wrapper = mountCard();
        const bar = wrapper.find('.progress-bar');
        expect(bar.attributes('style')).toContain('width: 25%');
    });

    it('quotaPct clampa em 100% se used > total', () => {
        const wrapper = mountCard({
            wallet: {
                ...baseWallet,
                balance: { ...baseWallet.balance, quota_used: 300, quota_total: 200 },
            },
        });
        const bar = wrapper.find('.progress-bar');
        expect(bar.attributes('style')).toContain('width: 100%');
    });

    it('quotaPct = 0 quando quota_total = 0', () => {
        const wrapper = mountCard({
            wallet: {
                ...baseWallet,
                balance: { ...baseWallet.balance, quota_used: 0, quota_total: 0 },
            },
        });
        const bar = wrapper.find('.progress-bar');
        expect(bar.attributes('style')).toContain('width: 0%');
    });

    it('mostra badge "expirada" quando quota_expired=true', () => {
        const wrapper = mountCard({
            wallet: {
                ...baseWallet,
                balance: { ...baseWallet.balance, quota_expired: true },
            },
        });
        expect(wrapper.text()).toContain('expirada');
    });

    it('NÃO mostra badge "expirada" quando quota_expired=false', () => {
        const wrapper = mountCard();
        // Não deve aparecer fora do contexto do data dictionary
        const badges = wrapper.findAll('.badge.bg-danger-subtle');
        expect(badges.length).toBe(0);
    });

    it('renderiza bloco "consumo por provedor" quando totalConsumed > 0', () => {
        const wrapper = mountCard();
        const analytics = wrapper.find('.provider-analytics');
        expect(analytics.exists()).toBe(true);
        expect(wrapper.text()).toContain('Consumo histórico por provedor');
    });

    it('NÃO renderiza bloco analytics quando todos consumos = 0', () => {
        const wrapper = mountCard({
            wallet: {
                ...baseWallet,
                balance: {
                    ...baseWallet.balance,
                    consumed_by_provider: { openai: 0, anthropic: 0, gemini: 0 },
                },
            },
        });
        expect(wrapper.find('.provider-analytics').exists()).toBe(false);
    });

    it('calcula providerPct correto: openai 500 / 850 = 59%', () => {
        const wrapper = mountCard();
        const text = wrapper.text();
        // 500/850 = 58.82... → arredondado 59%
        expect(text).toContain('59%');
        // 250/850 = 29.4 → 29%
        expect(text).toContain('29%');
        // 100/850 = 11.76 → 12%
        expect(text).toContain('12%');
    });

    it('mostra botão "Adicionar créditos" quando permission=true', () => {
        const wrapper = mountCard();
        const buttons = wrapper.findAll('button');
        const addBtn = buttons.find((b) => b.text().includes('Adicionar créditos'));
        expect(addBtn).toBeTruthy();
    });

    it('OCULTA botão quando create_manual_for_internal=false', () => {
        const wrapper = mountCard({
            permissions: { create_manual_for_internal: false },
        });
        const buttons = wrapper.findAll('button');
        const addBtn = buttons.find((b) => b.text().includes('Adicionar créditos'));
        expect(addBtn).toBeFalsy();
    });

    it('emite add-credit com entity_id ao clicar no botão', async () => {
        const wrapper = mountCard();
        const addBtn = wrapper.findAll('button').find((b) => b.text().includes('Adicionar créditos'));

        await addBtn.trigger('click');

        expect(wrapper.emitted('add-credit')).toBeTruthy();
        expect(wrapper.emitted('add-credit')[0]).toEqual(['ent-internal-123']);
    });

    it('renderiza os 3 provedores na ordem fixa: openai → anthropic → gemini', () => {
        const wrapper = mountCard();
        const tiles = wrapper.findAll('.provider-mini-tile');
        expect(tiles.length).toBe(3);

        // Verifica que cada tile tem o label correto
        expect(tiles[0].text()).toContain('ChatGPT');
        expect(tiles[1].text()).toContain('Claude');
        expect(tiles[2].text()).toContain('Gemini');
    });

    it('formata data quota_period_ends_at via toLocaleDateString pt-BR', () => {
        const wrapper = mountCard({
            wallet: {
                ...baseWallet,
                balance: { ...baseWallet.balance, quota_period_ends_at: '2026-06-15T00:00:00Z' },
            },
        });
        const text = wrapper.text();
        // pt-BR formato dd/mm/aaaa → 15/06/2026 (pode variar por timezone — testa que tem o dia)
        expect(text).toMatch(/\d{2}\/\d{2}\/\d{4}/);
    });

    it('mostra "—" quando quota_period_ends_at é null', () => {
        const wrapper = mountCard({
            wallet: {
                ...baseWallet,
                balance: { ...baseWallet.balance, quota_period_ends_at: null },
            },
        });
        expect(wrapper.text()).toContain('—');
    });
});
