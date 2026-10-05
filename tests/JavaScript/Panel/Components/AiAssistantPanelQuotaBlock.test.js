import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AiAssistantPanel from '@/Components/Panel/AiAssistantPanel.vue';

/**
 * Medidor da franquia (lido da carteira) + paywall no painel do assistente:
 *  - "Analisar" só trava quando a carteira não libera mais nenhum crédito
 *    (franquia da janela esgotada e sem avulso) — mesma conta da reserva;
 *  - sem créditos, o aviso mostra a situação e "Comprar créditos" (quem pode
 *    comprar) ou "peça ao administrador";
 *  - 422 ai_insufficient_credits e 402 de acesso limitado viram o aviso.
 */
describe('AiAssistantPanel — franquia da carteira e paywall', () => {
    const baseUrls = {
        estimate: '/_routes/panel.ai-runs.estimate',
        store: '/_routes/panel.ai-runs.store',
        show: '/_routes/panel.ai-runs.show/__ID__',
        approve: '/_routes/panel.ai-runs.approve/__ID__',
        reject: '/_routes/panel.ai-runs.reject/__ID__',
        cancel: '/_routes/panel.ai-runs.cancel/__ID__',
    };

    const paywall = (overrides = {}) => ({
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
        ...overrides,
    });

    function mountPanel({ quota, available, paywallProps = paywall() }) {
        return mount(AiAssistantPanel, {
            global: {
                stubs: {
                    OffcanvasPanel: {
                        template:
                            '<div data-test="offcanvas"><slot name="header" /><slot /><slot name="footer" /></div>',
                        props: ['open', 'width'],
                    },
                },
            },
            props: {
                open: true,
                ai: {
                    urls: baseUrls,
                    balance: { available, balance: quota?.purchased_balance ?? 0 },
                    quota,
                    paywall: paywallProps,
                    modes: [{ value: 'validated' }],
                    workflows: ['record_assist'],
                    default_workflow: 'record_assist',
                    assistant: {
                        title: 'Assistente de IA',
                        analyze: 'Analisar com IA',
                        quota_label: 'Franquia mensal',
                        quota_used: ':consumed/:quota créditos usados (:percent%)',
                        quota_renews_on: 'Renova em :date',
                        quota_renews_if_paid: 'Renova em :date se o pagamento em atraso for confirmado',
                        quota_expires_on: 'Vale até :date',
                        quota_spillover: 'Franquia atingida (:consumed/:quota). Saldo avulso: :available.',
                        quota_warning: 'Atenção: :percent% consumido',
                        quota_critical: 'Franquia quase no limite (:percent%)',
                        quota_exhausted: 'Franquia atingida (:consumed/:quota).',
                        quota_exhausted_hint: 'Franquia atingida.',
                    },
                    workflow_labels: { record_assist: 'Análise do prontuário' },
                },
                context: {
                    workflow_default: 'record_assist',
                    patient_id: 'p1',
                    medical_record_id: 'r1',
                },
            },
        });
    }

    const analyzeButton = (wrapper) => wrapper.findAll('button').find((b) => b.text().includes('Analisar'));

    beforeEach(() => {
        globalThis.window = globalThis.window ?? {};
        globalThis.window.axios = {
            post: vi.fn(() => Promise.resolve({ data: { run_id: 'r-abc' } })),
            get: vi.fn(() => Promise.resolve({ data: { data: [] } })),
        };
    });

    it('carteira sem crédito → Analisar bloqueado e paywall com "peça ao administrador"', () => {
        const wrapper = mountPanel({
            quota: { monthly_quota: 80, consumed_credits: 80, usage_percent: 100, renews_on: '2026-11-04' },
            available: 0,
        });

        expect(wrapper.vm.quotaExhausted).toBe(true);
        expect(analyzeButton(wrapper).attributes('disabled')).toBeDefined();

        const notice = wrapper.find('[data-test="ai-paywall"]');
        expect(notice.exists()).toBe(true);
        expect(notice.text()).toContain('04/11/2026');
        expect(wrapper.find('[data-test="ai-paywall-ask-admin"]').text()).toContain('Peça ao administrador');
        expect(wrapper.find('[data-test="ai-paywall-buy"]').exists()).toBe(false);
    });

    it('franquia quase no fim mas ainda com crédito → segue liberado (o medidor é a carteira)', () => {
        const wrapper = mountPanel({
            quota: { monthly_quota: 100, consumed_credits: 96, usage_percent: 96 },
            available: 4,
        });

        expect(wrapper.vm.quotaExhausted).toBe(false);
        expect(wrapper.vm.showQuotaAlert).toBe(true);
        expect(wrapper.find('[data-test="ai-paywall"]').exists()).toBe(false);
    });

    it('franquia esgotada com avulso → segue liberado consumindo o avulso', () => {
        const wrapper = mountPanel({
            quota: { monthly_quota: 80, consumed_credits: 80, usage_percent: 100, purchased_balance: 25 },
            available: 25,
        });

        expect(wrapper.vm.quotaExhausted).toBe(false);
        expect(wrapper.vm.usingPurchasedCredits).toBe(true);
    });

    it('mostra a data de renovação da janela no idioma', () => {
        const wrapper = mountPanel({
            quota: { monthly_quota: 80, consumed_credits: 10, usage_percent: 12.5, renews_on: '2026-11-04' },
            available: 70,
        });

        expect(wrapper.find('[data-test="ai-quota-renews"]').text()).toContain('Renova em 04/11/2026');
    });

    it('cota que sobrou (assinatura sem franquia) → "Vale até", sem prometer renovação', () => {
        const wrapper = mountPanel({
            quota: {
                monthly_quota: 80,
                consumed_credits: 30,
                usage_percent: 37.5,
                renews_on: null,
                expires_on: '2026-11-15',
            },
            available: 50,
        });

        const text = wrapper.find('[data-test="ai-quota-renews"]').text();
        expect(text).toContain('Vale até 15/11/2026');
        expect(text).not.toContain('Renova');
    });

    it('422 ai_insufficient_credits → paywall da resposta com "Comprar créditos" (admin)', async () => {
        window.axios.post = vi.fn(() =>
            Promise.reject({
                response: {
                    status: 422,
                    data: {
                        message: 'Saldo insuficiente de créditos IA para executar este fluxo.',
                        code: 'ai_insufficient_credits',
                        details: { requested: 5, available: 2 },
                        paywall: paywall({
                            reason: 'complimentary',
                            can_purchase: true,
                            purchase_url: '/panel/ai/usage#ai-credit-packages',
                            renews_on: null,
                            texts: {
                                ...paywall().texts,
                                message: 'A cortesia não inclui franquia mensal de IA.',
                            },
                        }),
                    },
                },
            }),
        );

        const wrapper = mountPanel({ quota: { monthly_quota: 0, consumed_credits: 0 }, available: 2 });
        wrapper.vm.form.user_prompt = 'Resumir o caso clínico do paciente';
        await wrapper.vm.analyze();
        await flushPromises();

        expect(wrapper.vm.step).toBe('idle');
        const notice = wrapper.find('[data-test="ai-paywall"]');
        expect(notice.text()).toContain('A cortesia não inclui franquia mensal de IA.');
        expect(wrapper.find('[data-test="ai-paywall-buy"]').text()).toContain('Comprar créditos');
    });

    it('402 de acesso limitado → orienta a regularizar o pagamento, sem compra', async () => {
        window.axios.post = vi.fn(() =>
            Promise.reject({
                response: { status: 402, data: { message: 'Acesso limitado.', access_level: 'limited' } },
            }),
        );

        const wrapper = mountPanel({
            quota: { monthly_quota: 80, consumed_credits: 0 },
            available: 80,
            paywallProps: paywall({ can_purchase: true, purchase_url: '/panel/ai/usage#ai-credit-packages' }),
        });
        wrapper.vm.form.user_prompt = 'Resumir o caso clínico do paciente';
        await wrapper.vm.analyze();
        await flushPromises();

        const notice = wrapper.find('[data-test="ai-paywall"]');
        expect(notice.text()).toContain('Regularize o pagamento');
        expect(wrapper.find('[data-test="ai-paywall-buy"]').exists()).toBe(false);
    });

    // IA-2: cliente em atraso — "renova em" só com o pagamento confirmado.
    it('em atraso: a renovação aparece condicionada ao pagamento', () => {
        const wrapper = mountPanel({
            quota: {
                monthly_quota: 80,
                consumed_credits: 10,
                usage_percent: 12.5,
                renews_on: null,
                renews_if_paid_on: '2026-11-19',
            },
            available: 70,
        });

        expect(wrapper.find('[data-test="ai-quota-renews"]').text()).toBe(
            '· Renova em 19/11/2026 se o pagamento em atraso for confirmado',
        );
    });

    // FT-10: números no idioma (1.500, não 1500).
    it('saldo e franquia com os números no idioma', () => {
        const wrapper = mountPanel({
            quota: { monthly_quota: 2000, consumed_credits: 2000, usage_percent: 100, purchased_balance: 1500 },
            available: 1500,
        });

        expect(wrapper.vm.usingPurchasedText).toBe('Franquia atingida (2.000/2.000). Saldo avulso: 1.500.');
        expect(wrapper.text()).toContain('1.500');
    });

    // FT-9: o aviso das props não interrompe o leitor de tela; o da resposta, sim.
    it('paywall das props é status; o da resposta 422 é alerta', async () => {
        const wrapper = mountPanel({
            quota: { monthly_quota: 80, consumed_credits: 80, usage_percent: 100, renews_on: '2026-11-04' },
            available: 0,
        });
        expect(wrapper.get('[data-test="ai-paywall"]').attributes('role')).toBe('status');

        window.axios.post = vi.fn(() =>
            Promise.reject({
                response: {
                    status: 422,
                    data: { code: 'ai_insufficient_credits', message: 'Saldo insuficiente.', paywall: paywall() },
                },
            }),
        );
        const fromResponse = mountPanel({ quota: { monthly_quota: 0, consumed_credits: 0 }, available: 2 });
        fromResponse.vm.form.user_prompt = 'Resumir o caso clínico do paciente';
        await fromResponse.vm.analyze();
        await flushPromises();

        expect(fromResponse.get('[data-test="ai-paywall"]').attributes('role')).toBe('alert');
    });
});
