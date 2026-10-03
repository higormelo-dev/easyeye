import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ProviderDetailDrawer from '@/Pages/Panel/Manager/AiProviders/ProviderDetailDrawer.vue';
import { mockFetch, provider, ready } from './aiProvidersFixtures';

/** Drawer do provedor: chave só por 4 caracteres, modelo salvo à parte, teste de conexão. */
vi.mock('@/Components/Panel/OffcanvasPanel.vue', () => ({
    default: {
        props: ['open'],
        emits: ['close'],
        template: `<aside v-if="open"><header><slot name="header" /></header><slot />
            <footer v-if="$slots.footer"><slot name="footer" /></footer></aside>`,
    },
}));

const t = { key_defined: 'Definida', model_no_options: 'Sem modelos com preço.', test_never: 'Não testado.' };

function mountDrawer(props = {}) {
    return mount(ProviderDetailDrawer, {
        props: {
            open: true,
            provider: ready({
                code: 'groq',
                label: 'Groq',
                compatible: true,
                key_hint: '••••9f2a',
                key_env: 'GROQ_API_KEY',
                model: 'llama-3.3-70b-versatile',
            }),
            modelOptions: ['llama-3.3-70b-versatile', 'openai/gpt-oss-120b'],
            t,
            ...props,
        },
    });
}

beforeEach(() => {
    window.showSuccessToast = vi.fn();
    window.showErrorToast = vi.fn();
});

describe('ProviderDetailDrawer', () => {
    it('[SEGURANÇA] chave: só se está definida, os 4 últimos caracteres e a variável', () => {
        const wrapper = mountDrawer();

        expect(wrapper.find('[data-drawer-key]').text()).toBe('Definida · ••••9f2a');
        expect(wrapper.text()).toContain('GROQ_API_KEY');
        expect(wrapper.text()).toContain('GROQ_API_KEY=');
    });

    it('salva o modelo escolhido (vazio = padrão do .env) e avisa a página', async () => {
        const fetchMock = mockFetch({ message: 'Modelo do provedor atualizado.' });
        const wrapper = mountDrawer();

        const save = wrapper.find('[data-drawer-model-save]');
        expect(save.attributes('disabled')).toBeDefined(); // nada mudou

        await wrapper.find('[data-drawer-model]').setValue('openai/gpt-oss-120b');
        await save.trigger('click');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/_routes/manager.ai-providers.model/groq');
        expect(JSON.parse(init.body)).toEqual({ model: 'openai/gpt-oss-120b' });
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });

    it('sem modelos com preço: explica; sem chave: nem mostra o seletor e não testa', () => {
        expect(mountDrawer({ modelOptions: [] }).text()).toContain('Sem modelos com preço.');

        const noKey = mountDrawer({ provider: provider({ code: 'groq', label: 'Groq', key_env: 'GROQ_API_KEY' }) });
        expect(noKey.find('[data-drawer-model]').exists()).toBe(false);
        expect(noKey.find('[data-drawer-test]').attributes('disabled')).toBeDefined();
    });

    it('testar conexão pede à página e mostra o último resultado', async () => {
        const wrapper = mountDrawer();
        expect(wrapper.text()).toContain('Não testado.');

        await wrapper.find('[data-drawer-test]').trigger('click');
        expect(wrapper.emitted('test')).toEqual([['groq']]);

        await wrapper.setProps({ testResult: { ok: false, message: 'Chave recusada.', latency_ms: 120 } });
        expect(wrapper.find('[data-drawer-test-result]').text()).toContain('Chave recusada. (120 ms)');
    });
});
