import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ProviderSetupHelp from '@/Pages/Panel/Manager/AiProviders/ProviderSetupHelp.vue';

/** "Como configurar": só NOMES de variáveis do .env — nunca valores de chave. */
describe('ProviderSetupHelp', () => {
    it('mostra a variável da chave, onde gerar e os opcionais com o padrão atual', () => {
        const wrapper = mount(ProviderSetupHelp, {
            props: {
                provider: {
                    code: 'mistral',
                    label: 'Mistral AI',
                    key_env: 'MISTRAL_API_KEY',
                    key_hint: '••••1234',
                    model: 'mistral-small-latest',
                    model_env: 'AI_MISTRAL_MODEL',
                    base_url: 'https://api.mistral.ai/v1',
                    base_url_env: 'AI_MISTRAL_BASE_URL',
                    keys_url: 'https://console.mistral.ai/api-keys',
                },
                t: { setup_title: 'Configurar :provider' },
            },
        });

        expect(wrapper.text()).toContain('Configurar Mistral AI');
        expect(wrapper.find('code').text()).toBe('MISTRAL_API_KEY=');
        expect(wrapper.text()).not.toContain('1234');
        expect(wrapper.text()).toContain('AI_MISTRAL_MODEL=mistral-small-latest');
        expect(wrapper.text()).toContain('AI_MISTRAL_BASE_URL=https://api.mistral.ai/v1');

        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe('https://console.mistral.ai/api-keys');
        expect(link.attributes('rel')).toBe('noopener noreferrer');
    });
});
