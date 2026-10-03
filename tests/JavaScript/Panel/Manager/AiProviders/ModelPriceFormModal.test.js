import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ModelPriceFormModal from '@/Pages/Panel/Manager/AiProviders/ModelPriceFormModal.vue';
import { mockFetch, priceRow, provider } from './aiProvidersFixtures';

/** Cadastro/edição de preço: nasce travado, editar o preço trava, erros no campo. */
vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open'],
        emits: ['close'],
        template: `<section v-if="open"><slot name="header" /><slot /><footer><slot name="footer" /></footer></section>`,
    },
}));

const providers = [provider({ code: 'openai', label: 'OpenAI' }), provider({ code: 'groq', label: 'Groq' })];

async function mountModal(price = null, defaultProvider = '') {
    const wrapper = mount(ModelPriceFormModal, { props: { open: false, price, providers, defaultProvider, t: {} } });
    await wrapper.setProps({ open: true });
    return wrapper;
}

describe('ModelPriceFormModal', () => {
    it('novo: provedor do filtro, nasce travado e vai para o cadastro', async () => {
        const fetchMock = mockFetch({ message: 'Catálogo de modelos atualizado.' });
        const wrapper = await mountModal(null, 'groq');

        expect(wrapper.find('#mp-provider').element.value).toBe('groq');
        expect(wrapper.find('[data-price-lock-input]').element.checked).toBe(true);
        expect(wrapper.find('[data-price-save]').attributes('disabled')).toBeDefined();

        await wrapper.find('#mp-model').setValue('openai/gpt-4o-mini');
        await wrapper.find('#mp-input').setValue('0.15');
        await wrapper.find('#mp-output').setValue('0.6');
        await wrapper.find('[data-price-form]').trigger('submit');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/_routes/manager.ai-model-prices.store');
        expect(JSON.parse(init.body)).toMatchObject({
            provider: 'groq',
            model: 'openai/gpt-4o-mini',
            input_usd_per_million: 0.15,
            reasoning_usd_per_million: null,
            price_locked: true,
        });
        expect(wrapper.emitted('saved')[0]).toEqual(['Catálogo de modelos atualizado.']);
    });

    it('editar: mudar o preço marca a trava; desmarcar depois manda destravado', async () => {
        const fetchMock = mockFetch({ message: 'ok' });
        const wrapper = await mountModal(priceRow({ id: 'p9', price_locked: false, tool_call_usd: 0.01 }));

        expect(wrapper.find('#mp-model').attributes('disabled')).toBeDefined();
        await wrapper.find('#mp-input').setValue('2');
        expect(wrapper.find('[data-price-lock-input]').element.checked).toBe(true);

        await wrapper.find('[data-price-lock-input]').setValue(false);
        await wrapper.find('[data-price-form]').trigger('submit');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/_routes/manager.ai-model-prices.update/p9');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(init.body)).toMatchObject({
            input_usd_per_million: 2,
            price_locked: false,
            tool_call_usd: 0.01,
        });
    });

    it('erro de validação fica no campo; regra de negócio aparece no topo', async () => {
        mockFetch({ errors: { model: ['Formato inválido.'] } }, false);
        const wrapper = await mountModal();
        await wrapper.find('#mp-model').setValue('x y');
        await wrapper.find('#mp-input').setValue('1');
        await wrapper.find('#mp-output').setValue('1');
        await wrapper.find('[data-price-form]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('#mp-model').classes()).toContain('is-invalid');
        expect(wrapper.text()).toContain('Formato inválido.');
        expect(wrapper.emitted('saved')).toBeUndefined();

        mockFetch({ message: 'Este modelo já está cadastrado.' }, false);
        await wrapper.find('[data-price-form]').trigger('submit');
        await flushPromises();
        expect(wrapper.find('.alert-danger').text()).toBe('Este modelo já está cadastrado.');
    });
});
