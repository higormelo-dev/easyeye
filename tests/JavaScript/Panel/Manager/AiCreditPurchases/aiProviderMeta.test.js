import { describe, it, expect } from 'vitest';
import { BASE_PROVIDERS, providerMeta, providerOrder } from '@/Pages/Panel/Manager/AiCreditPurchases/aiProviderMeta';

/** Cards de custo/consumo: provedor novo da lista pronta nunca some da tela. */
describe('aiProviderMeta', () => {
    it('os três de sempre aparecem; os demais só com movimento, na ordem do servidor', () => {
        const codes = ['openai', 'anthropic', 'gemini', 'xai', 'mistral', 'groq'];

        expect(providerOrder(codes, () => false)).toEqual(BASE_PROVIDERS);
        expect(providerOrder(codes, (c) => ['groq', 'xai'].includes(c))).toEqual([...BASE_PROVIDERS, 'xai', 'groq']);
    });

    it('provedor conhecido tem visual próprio; desconhecido cai no genérico com o rótulo do servidor', () => {
        expect(providerMeta('mistral')).toMatchObject({
            label: 'Mistral',
            billingUrl: 'https://console.mistral.ai/billing',
        });
        expect(providerMeta('openai', 'ChatGPT').bg).toBe('rgba(16,163,127,0.10)');

        const unknown = providerMeta('novo', 'Novo Provedor');
        expect(unknown).toMatchObject({ label: 'Novo Provedor', icon: 'ti ti-sparkles', billingUrl: null });
    });
});
