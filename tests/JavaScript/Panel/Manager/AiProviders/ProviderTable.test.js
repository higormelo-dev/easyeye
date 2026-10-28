import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ProviderTable from '@/Pages/Panel/Manager/AiProviders/ProviderTable.vue';
import { provider, ready } from './aiProvidersFixtures';

/** Tabela de provedores: situação mais importante, papel, chave só pelos 4 últimos caracteres. */
const t = {
    status_no_key: 'Sem chave',
    status_no_price: 'Sem preço',
    status_unlisted: 'Modelo descontinuado',
    status_ready: 'Pronto',
    role_primary_short: 'Principal',
    insecure_url: 'Sem HTTPS.',
};

const providers = [
    ready({ code: 'openai', label: 'OpenAI', role: 'primary', key_hint: '••••1234' }),
    ready({ code: 'gemini', label: 'Google (Gemini)', price_ok: false }),
    ready({ code: 'xai', label: 'xAI (Grok)', compatible: true, model_unlisted_at: '02/10/2026' }),
    provider({
        code: 'mistral',
        label: 'Mistral AI',
        compatible: true,
        key_env: 'MISTRAL_API_KEY',
        base_url: 'http://localhost:4000/v1',
        base_url_secure: false,
    }),
];

function mountTable(testing = null) {
    return mount(ProviderTable, { props: { providers, testing, t } });
}

const row = (wrapper, code) => wrapper.find(`[data-provider-row="${code}"]`);

describe('ProviderTable', () => {
    it('mostra a situação mais importante de cada provedor', () => {
        const wrapper = mountTable();

        expect(row(wrapper, 'openai').find('[data-status]').attributes('data-status')).toBe('ready');
        expect(row(wrapper, 'gemini').find('[data-status]').text()).toBe('Sem preço');
        expect(row(wrapper, 'xai').find('[data-status]').text()).toBe('Modelo descontinuado');
        expect(row(wrapper, 'mistral').find('[data-status]').text()).toBe('Sem chave');
    });

    it('papel, 4 últimos caracteres da chave e aviso de endereço sem HTTPS', () => {
        const wrapper = mountTable();

        expect(row(wrapper, 'openai').find('[data-role-badge]').text()).toBe('Principal');
        expect(row(wrapper, 'openai').find('[data-key-hint]').text()).toBe('••••1234');
        expect(row(wrapper, 'gemini').find('[data-role-badge]').exists()).toBe(false);
        expect(row(wrapper, 'mistral').find('[data-insecure-url]').exists()).toBe(true);
        expect(row(wrapper, 'mistral').text()).toContain('MISTRAL_API_KEY');
    });

    it('ações: ver detalhes sempre; testar só configurado e fora de teste', async () => {
        const wrapper = mountTable('gemini');

        await wrapper.find('[data-provider-view="mistral"]').trigger('click');
        await wrapper.find('[data-provider-test="openai"]').trigger('click');
        await wrapper.find('[data-provider-test="mistral"]').trigger('click');
        await wrapper.find('[data-provider-test="gemini"]').trigger('click');

        expect(wrapper.emitted('view')).toEqual([['mistral']]);
        expect(wrapper.emitted('test')).toEqual([['openai']]);
    });
});
