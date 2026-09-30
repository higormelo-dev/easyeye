import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import ContactForm from '@/Components/Site/ContactForm.vue';
import mask from '@/directives/mask';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

const t = {
    title: 'Envie sua mensagem', subtitle: 'Preencha o formulário.', name: 'Nome', name_ph: 'Seu nome',
    email: 'E-mail', email_ph: 'voce@exemplo.com', phone: 'WhatsApp', phone_ph: '(00) 00000-0000',
    is_client: 'Cliente?', is_client_opts: ['Sim', 'Não'], role: 'Cargo', role_opts: ['Outro'],
    segment: 'Estabelecimento', segment_opts: ['Outro'], select: 'Selecione', message: 'Mensagem',
    optional: 'opcional', details_title: 'Mais detalhes (opcional)', details_hint: 'Ajude a personalizar o atendimento.',
    message_ph: 'Como podemos ajudar?', message_hint: 'Até 5.000 caracteres.', terms: 'Concordo com os termos.',
    submit: 'Enviar mensagem', sending: 'Enviando...', success_title: 'Mensagem enviada!', success_body: 'Entraremos em contato.',
    errors: Object.fromEntries(['required', 'email', 'terms', 'invalid', 'validation', 'server', 'network', 'timeout', 'session', 'rate_limit']
        .map(key => [key, `Tradução do erro: ${key}`])),
};

const values = { name: 'José 山田 👁', email: 'visitor@example.test', phone: '(11) 99999-9999', message: 'Quero conhecer o EasyEye.\nPodemos conversar?', terms: true };
let wrapper;

function render(translations = t) {
    wrapper = mount(ContactForm, { attachTo: document.body, props: { t: translations, action: '/contato' }, global: { directives: { mask } } });
    return wrapper;
}

async function fill() {
    for (const [field, value] of Object.entries(values)) await wrapper.get(`[name="${field}"]`).setValue(value);
}

async function submit() {
    await wrapper.get('form').trigger('submit');
    await flushPromises();
}

beforeEach(() => vi.clearAllMocks());
afterEach(() => wrapper?.unmount());

describe('ContactForm', () => {
    it('apresenta os detalhes opcionais fechados e identificados, depois da mensagem', () => {
        render();
        const details = wrapper.get('details');
        expect(details.element.open).toBe(false);
        expect(details.get('summary').text()).toBe(t.details_title);
        expect(details.get('summary').attributes('aria-describedby')).toBe('cf-optional-hint');
        expect(details.get('#cf-optional-hint').text()).toBe(t.details_hint);
        for (const field of ['is_client', 'role', 'segment']) {
            expect(details.get(`label[for="cf-${field}"]`).text()).toContain(t.optional);
            expect(details.get(`[name="${field}"]`).element.required).toBe(false);
        }
        const fields = wrapper.findAll('input, select, textarea').map(field => field.attributes('name'));
        expect(fields.indexOf('message')).toBeLessThan(fields.indexOf('is_client'));
    });

    it.each([true, false])('envia os valores opcionais preservados com a seção aberta = %s', async open => {
        axios.post.mockResolvedValueOnce({ data: { ok: true } });
        render();
        await fill();
        const details = wrapper.get('details').element;
        details.open = true;
        const optional = { is_client: 'Não', role: 'Outro', segment: 'Outro' };
        for (const [field, value] of Object.entries(optional)) {
            await wrapper.get(`[name="${field}"]`).setValue(value);
        }
        details.open = open;
        await submit();
        expect(axios.post).toHaveBeenCalledWith('/contato', { ...values, ...optional }, expect.any(Object));
        expect(wrapper.get('.cf-success').text()).toContain(t.success_title);
    });

    it.each(['is_client', 'role', 'segment'])('abre os detalhes para corrigir erro 422 em %s antes de focar o campo', async field => {
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { errors: { [field]: ['Revise esta opção.'] } } } });
        render();
        await fill();
        const details = wrapper.get('details').element;
        const input = wrapper.get(`[name="${field}"]`).element;
        const focus = input.focus.bind(input);
        const focusSpy = vi.spyOn(input, 'focus').mockImplementation(() => {
            expect(details.open).toBe(true);
            focus();
        });
        await submit();
        expect(details.open).toBe(true);
        expect(wrapper.get(`#cf-${field}-error`).text()).toBe('Revise esta opção.');
        expect(focusSpy).toHaveBeenCalledOnce();
        expect(document.activeElement).toBe(input);
        await wrapper.get(`[name="${field}"]`).setValue(field === 'is_client' ? 'Sim' : 'Outro');
        expect(wrapper.find(`#cf-${field}-error`).exists()).toBe(false);
        focusSpy.mockRestore();
    });

    it('mantém validação nativa e mostra campos obrigatórios sem chamar a API', async () => {
        render();
        await submit();
        expect(wrapper.get('form').attributes('novalidate')).toBeUndefined();
        expect(axios.post).not.toHaveBeenCalled();
        expect(wrapper.get('#cf-name-error').text()).toBe(t.errors.required);
        expect(wrapper.get('#cf-message').attributes('aria-describedby')).toBe('cf-message-hint cf-message-error');
        expect(wrapper.get('#cf-terms-error').text()).toBe(t.errors.terms);
    });

    it('impede e-mail inválido antes de enviar', async () => {
        render();
        await fill();
        await wrapper.get('#cf-email').setValue('invalido');
        await submit();
        expect(axios.post).not.toHaveBeenCalled();
        expect(wrapper.get('#cf-email-error').text()).toBe(t.errors.email);
    });

    it('mostra erros 422 acessíveis, mantém dados e permite correção e reenvio', async () => {
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { errors: { email: ['E-mail rejeitado.'], message: ['Revise a mensagem.'] } } } });
        render();
        await fill();
        await submit();
        expect(wrapper.find('.cf-success').exists()).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toBe(t.errors.validation);
        expect(wrapper.get('#cf-email-error').text()).toBe('E-mail rejeitado.');
        expect(wrapper.get('#cf-email').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('details').element.open).toBe(false);
        expect(document.activeElement).toBe(wrapper.get('#cf-email').element);
        for (const [field, value] of Object.entries(values).filter(([field]) => field !== 'terms')) {
            expect(wrapper.get(`[name="${field}"]`).element.value).toBe(value);
        }
        expect(wrapper.get('#cf-terms').element.checked).toBe(true);
        await wrapper.get('#cf-email').setValue('corrected@example.test');
        expect(wrapper.find('#cf-email-error').exists()).toBe(false);
        expect(wrapper.get('#cf-email').attributes('aria-invalid')).toBeUndefined();
        axios.post.mockResolvedValueOnce({ data: { ok: true } });
        await submit();
        expect(axios.post).toHaveBeenCalledTimes(2);
        expect(wrapper.get('.cf-success').text()).toContain(t.success_title);
        expect(document.activeElement).toBe(wrapper.get('.cf-success h3').element);
    });

    it.each([
        [503, 'server'], [500, 'server'], [403, 'server'], [419, 'session'], [429, 'rate_limit'], [422, 'validation'],
    ])('não confirma sucesso em HTTP %s e permite tentar novamente', async (status, key) => {
        axios.post.mockRejectedValue({ response: { status, data: {} } });
        render();
        await fill();
        await submit();
        expect(wrapper.find('.cf-success').exists()).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toBe(t.errors[key]);
        expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element);
        expect(wrapper.get('#cf-message').element.value).toBe(values.message);
        expect(wrapper.get('button').element.disabled).toBe(false);
        expect(wrapper.get('fieldset').element.disabled).toBe(false);
    });

    it.each([
        [{ code: 'ERR_NETWORK', request: {} }, 'network'],
        [{ code: 'ECONNABORTED', request: {} }, 'timeout'],
        [{ code: 'ETIMEDOUT', request: {} }, 'timeout'],
    ])('preserva a mensagem em falhas de conexão: %j', async (error, key) => {
        axios.post.mockRejectedValue(error);
        render();
        await fill();
        await submit();
        expect(wrapper.find('.cf-success').exists()).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toBe(t.errors[key]);
        expect(wrapper.get('#cf-message').element.value).toBe(values.message);
    });

    it.each([{ ok: false }, { ok: 'true' }, '<html>Login</html>', null])('não aceita resposta 200 sem confirmação explícita: %j', async data => {
        axios.post.mockResolvedValue({ data });
        render();
        await fill();
        await submit();
        expect(wrapper.find('.cf-success').exists()).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toBe(t.errors.server);
    });

    it('bloqueia envios concorrentes e só confirma após a resposta', async () => {
        let resolve;
        axios.post.mockImplementation(() => new Promise(done => { resolve = done; }));
        render();
        await fill();
        await wrapper.get('form').trigger('submit');
        await wrapper.get('form').trigger('submit');
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.post).toHaveBeenCalledWith('/contato', expect.objectContaining(values), expect.objectContaining({ timeout: 30000, headers: { Accept: 'application/json' } }));
        expect(wrapper.get('fieldset').element.disabled).toBe(true);
        expect(wrapper.get('form').attributes('aria-busy')).toBe('true');
        expect(wrapper.get('button').text()).toBe(t.sending);
        expect(wrapper.find('.cf-success').exists()).toBe(false);
        resolve({ data: { ok: true } });
        await flushPromises();
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.get('[role="status"]').text()).toContain(t.success_title);
    });

    it('usa a tradução recebida para erros em outro idioma', async () => {
        axios.post.mockRejectedValue({ response: { status: 503 } });
        render({ ...t, errors: { ...t.errors, server: 'Please try again shortly.' } });
        await fill();
        await submit();
        expect(wrapper.get('[role="alert"]').text()).toBe('Please try again shortly.');
    });

    it('cancela a requisição ao sair da página', async () => {
        axios.post.mockImplementation(() => new Promise(() => {}));
        render();
        await fill();
        await wrapper.get('form').trigger('submit');
        const signal = axios.post.mock.calls[0][2].signal;
        wrapper.unmount();
        expect(signal.aborted).toBe(true);
        wrapper = null;
    });
});
