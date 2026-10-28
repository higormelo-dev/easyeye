import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import RolesCard from '@/Pages/Panel/Manager/AiProviders/RolesCard.vue';
import { mockFetch, provider, ready } from './aiProvidersFixtures';

/** Papéis do assistente: só configurados entram; validação viva; salvar só os papéis. */
const modes = [
    { value: 'economy', label: 'Economia', needs: 1 },
    { value: 'validated', label: 'Validado', needs: 2 },
    { value: 'consensus', label: 'Consenso', needs: 3 },
];

const providers = [
    ready({ code: 'openai', label: 'OpenAI', model: 'gpt-4o' }),
    ready({ code: 'mistral', label: 'Mistral AI', model: 'mistral-large', price_ok: false }),
    ready({ code: 'xai', label: 'xAI (Grok)', model: 'grok-4.5' }),
    provider({ code: 'anthropic', label: 'Anthropic (Claude)' }),
];

function mountCard(roles = { primary: 'openai', reviewer: null, adjudicator: null }) {
    return mount(RolesCard, { props: { roles, providers, modes, t: { error_duplicate_role: 'Papel repetido.' } } });
}

beforeEach(() => {
    window.showSuccessToast = vi.fn();
    window.showErrorToast = vi.fn();
});

describe('RolesCard', () => {
    it('só provedores configurados entram na escolha, com o modelo', () => {
        const options = mountCard()
            .findAll('[data-role="reviewer"] option')
            .map((o) => o.text());

        expect(options).toEqual([
            '— Nenhum —',
            'OpenAI · gpt-4o',
            'Mistral AI · mistral-large',
            'xAI (Grok) · grok-4.5',
        ]);
    });

    it('validação viva: papel repetido bloqueia; provedor sem preço avisa; modos acompanham', async () => {
        const wrapper = mountCard();
        expect(wrapper.find('[data-mode="validated"]').attributes('data-available')).toBe('false');

        await wrapper.find('[data-role="reviewer"]').setValue('openai');
        expect(wrapper.find('[data-role-problems]').text()).toContain('Papel repetido.');
        expect(wrapper.find('[data-roles-save]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-role="reviewer"]').setValue('mistral');
        expect(wrapper.find('[data-role-problems]').text()).toContain('Mistral AI');
        expect(wrapper.find('[data-mode="validated"]').attributes('data-available')).toBe('true');
        expect(wrapper.find('[data-roles-save]').attributes('disabled')).toBeUndefined();
    });

    it('limpar o revisor limpa o árbitro junto (consenso exige os três)', async () => {
        const fetchMock = mockFetch({ message: 'ok' });
        const wrapper = mountCard({ primary: 'openai', reviewer: 'mistral', adjudicator: 'xai' });
        expect(wrapper.find('[data-mode="consensus"]').attributes('data-available')).toBe('true');

        await wrapper.find('[data-role="reviewer"]').setValue(null);
        await wrapper.find('[data-roles-save]').trigger('click');
        await flushPromises();

        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
            primary: 'openai',
            reviewer: null,
            adjudicator: null,
        });
    });

    it('salva SÓ os papéis e avisa a página para recarregar', async () => {
        const fetchMock = mockFetch({ message: 'Provedores de IA atualizados.' });
        const wrapper = mountCard();

        await wrapper.find('[data-role="reviewer"]').setValue('mistral');
        await wrapper.find('[data-roles-save]').trigger('click');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/_routes/manager.ai-providers.update');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(init.body)).toEqual({ primary: 'openai', reviewer: 'mistral', adjudicator: null });
        expect(window.showSuccessToast).toHaveBeenCalledWith('Provedores de IA atualizados.');
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });

    it('erro do servidor vira aviso e não recarrega', async () => {
        mockFetch({ message: 'Não é possível habilitar provedor(es) sem credencial.' }, false);
        const wrapper = mountCard();

        await wrapper.find('[data-roles-save]').trigger('click');
        await flushPromises();

        expect(window.showErrorToast).toHaveBeenCalledWith('Não é possível habilitar provedor(es) sem credencial.');
        expect(wrapper.emitted('saved')).toBeUndefined();
    });
});
