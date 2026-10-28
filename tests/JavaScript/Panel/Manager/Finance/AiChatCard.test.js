import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';
import AiChatCard from '@/Pages/Panel/Manager/Finance/AiChatCard.vue';

vi.mock('axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

/** "Converse com os dados": período da tela, markdown seguro, teclado, tentar de novo e conversa na aba. */
const t = {
    chat_title: 'Converse',
    chat_subtitle: '',
    chat_placeholder: '',
    chat_send: 'Enviar',
    chat_new: 'Nova conversa',
    chat_typing: 'Analisando…',
    chat_period_changed: 'Período alterado para :from – :to.',
    chat_counter: ':count/:max',
    chat_input_label: 'Pergunta',
    chat_hint: 'Enter envia',
    chat_you: 'Você',
    chat_ai: 'IA',
    chat_suggestions_title: 'Comece',
    chat_error: 'Falhou',
    chat_copy_answer: 'Copiar resposta',
    copy: 'Copiar',
    copied: 'Copiado!',
    retry: 'Tentar de novo',
    error_timeout: 'Demorou',
    period_chip: 'Período: :from – :to',
    chat_suggestions: ['Por que nosso lucro caiu?', 'Onde gastamos mais?'],
};
const period = { preset: '3m', from: '2026-07-03', to: '2026-10-03' };
const urls = { digest: '/d', chat: '/c', show: '/r/__ID__' };
const STORAGE_KEY = 'easyeye.manager.finance.chat';

function mountChat(props = {}, options = {}) {
    return mount(AiChatCard, { props: { t, period, urls, maxLength: 4000, ...props }, ...options });
}

function answer(finalOutput) {
    axios.get.mockResolvedValue({ data: { status: 'approved', final_output: finalOutput } });
}

beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
    axios.post.mockResolvedValue({ data: { run_id: 'r1' } });
});

describe('AiChatCard', () => {
    it('sugestão envia com um clique, com o período e a conversa; resposta em markdown seguro', async () => {
        answer('**Lucro** caiu <script>alert(1)</script>\n- item');
        const wrapper = mountChat();

        await wrapper.find('[data-chat-suggestion]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/c',
            expect.objectContaining({
                user_prompt: 'Por que nosso lucro caiu?',
                preset: '3m',
                from: '2026-07-03',
                to: '2026-10-03',
                conversation_id: expect.any(String),
            }),
        );
        const html = wrapper.find('[data-chat-answer]').html();
        expect(html).toContain('<strong>Lucro</strong>');
        expect(html).toContain('&lt;script&gt;');
        expect(html).toContain('<li>item</li>');
        expect(wrapper.find('[data-chat-answer] script').exists()).toBe(false);
    });

    it('Enter envia; Shift+Enter e composição (IME) não', async () => {
        answer('ok');
        const wrapper = mountChat();
        const input = wrapper.find('[data-chat-input]');

        await input.setValue('Qual plano dá mais lucro?');
        await input.trigger('keydown', { key: 'Enter', shiftKey: true });
        await input.trigger('keydown', { key: 'Enter', isComposing: true });
        expect(axios.post).not.toHaveBeenCalled();

        await input.trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(input.element.value).toBe('');
    });

    it('erro oferece "tentar de novo", que reenvia a mesma pergunta sem duplicar a do usuário', async () => {
        axios.get.mockResolvedValue({ data: { status: 'failed' } });
        const wrapper = mountChat();

        await wrapper.find('[data-chat-suggestion]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-chat-error]').text()).toBe('Falhou');

        answer('Agora sim.');
        await wrapper.find('[data-chat-retry]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledTimes(2);
        expect(axios.post.mock.calls[1][1].user_prompt).toBe('Por que nosso lucro caiu?');
        expect(wrapper.findAll('[data-chat-msg="user"]')).toHaveLength(1);
        expect(wrapper.find('[data-chat-answer]').text()).toBe('Agora sim.');
    });

    it('trocar o período com a conversa aberta avisa no histórico', async () => {
        answer('ok');
        const wrapper = mountChat();
        await wrapper.find('[data-chat-suggestion]').trigger('click');
        await flushPromises();

        await wrapper.setProps({ period: { preset: '12m', from: '2025-10-03', to: '2026-10-03' } });

        expect(wrapper.find('[data-chat-notice]').text()).toBe('Período alterado para 03/10/2025 – 03/10/2026.');
    });

    it('ask() da análise preenche a pergunta e foca a caixa (o admin revisa e envia)', async () => {
        const wrapper = mountChat({}, { attachTo: document.body });

        wrapper.vm.ask('Sobre "MRR subiu"');
        await flushPromises();

        const input = wrapper.find('[data-chat-input]').element;
        expect(input.value).toBe('Sobre "MRR subiu"');
        expect(document.activeElement).toBe(input);
        expect(axios.post).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('conversa sobrevive ao recarregar a aba; "Nova conversa" limpa', async () => {
        sessionStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({
                conversationId: 'c1',
                messages: [{ id: '1', role: 'user', content: 'Pergunta antiga', at: '2026-10-03T12:00:00Z' }],
            }),
        );
        const wrapper = mountChat();

        expect(wrapper.find('[data-chat-msg="user"]').text()).toContain('Pergunta antiga');

        await wrapper.find('[data-chat-new]').trigger('click');

        expect(wrapper.find('[data-chat-empty]').exists()).toBe(true);
        expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('guarda a conversa na aba depois de cada resposta, na mesma conversa', async () => {
        answer('Resposta');
        const wrapper = mountChat();

        await wrapper.find('[data-chat-suggestion]').trigger('click');
        await flushPromises();

        const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY));
        expect(saved.conversationId).toBe(axios.post.mock.calls[0][1].conversation_id);
        expect(saved.messages.map((m) => m.role)).toEqual(['user', 'assistant']);
    });
});
