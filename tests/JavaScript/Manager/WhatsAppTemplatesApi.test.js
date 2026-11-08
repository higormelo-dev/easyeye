import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

import WhatsAppTemplates from '@/Pages/Panel/Manager/WhatsApp/WhatsAppTemplates.vue';

/**
 * Manager → WhatsApp → Modelos, conta comum da Gupshup: estado de cada modelo no app e criação por API,
 * um por chamada ("Criar os que faltam" encadeia com 7 s de intervalo e para no primeiro erro).
 */
const ROUTES = {
    templates_status: '/manager/whatsapp/templates/status',
    templates_create: '/manager/whatsapp/templates',
};

const t = {
    templates_title: 'Modelos do WhatsApp',
    templates_hint: 'Prévia',
    template_groups: { patients: 'Pacientes' },
    template_categories: { UTILITY: 'Utilidade' },
    template_preview_language: 'Idioma da prévia',
    template_languages: { pt_BR: 'Português', en: 'Inglês' },
    template_language_off: 'Inglês ainda não liberado',
    template_variables: 'Variáveis',
    template_no_variables: 'Sem variáveis.',
    template_copy_meta: 'Copiar texto para a Meta',
    template_copy_name: 'Copiar nome',
    template_copied: 'Copiado!',
    templates_api: {
        hint: 'Estado de cada modelo na Gupshup.',
        check: 'Verificar na Gupshup',
        checking: 'Verificando…',
        create: 'Criar na Gupshup',
        creating: 'Enviando…',
        create_missing: 'Criar os que faltam (:count)',
        confirm_one: 'Enviar o modelo :name (:language)?',
        confirm_all: 'Enviar :count modelos?',
        progress: 'Enviando :current de :total…',
        stopped: 'Parei no primeiro erro.',
        done: ':count modelo(s) enviado(s).',
        load_failed: 'Não foi possível ler os modelos.',
        create_failed: 'Não foi possível criar o modelo.',
        reason: 'Motivo da Meta:',
        status: { APPROVED: 'Aprovado', PENDING: 'Em análise', FAILED: 'Falhou', MISSING: 'Não criado' },
    },
};

function template(key) {
    return {
        key,
        name: `easyeye_${key}`,
        category: 'UTILITY',
        group: 'patients',
        texts: {
            pt_BR: { meta: 'Olá {{1}}', preview: 'Olá Maria', footer: null, buttons: [], params: [] },
            en: { meta: 'Hi {{1}}', preview: 'Hi Mary', footer: null, buttons: [], params: [] },
        },
    };
}

const TEMPLATES = ['aprovado', 'recusado', 'faltando', 'faltando2'].map(template);

function remote(extra = {}) {
    return {
        data: {
            ok: true,
            templates: {
                'easyeye_aprovado|pt_BR': { status: 'APPROVED', reason: '' },
                'easyeye_recusado|pt_BR': {
                    status: 'FAILED',
                    reason: 'does not have permission to create message template',
                },
                ...extra,
            },
        },
    };
}

function mountTemplates(props = {}) {
    return mount(WhatsAppTemplates, {
        props: {
            templates: TEMPLATES,
            enabledLanguages: ['pt_BR'],
            api: true,
            active: true,
            routes: ROUTES,
            t,
            ...props,
        },
    });
}

function card(wrapper, key) {
    return wrapper.get(`[data-template="${key}"]`);
}

beforeEach(() => {
    window.axios = { get: vi.fn().mockResolvedValue(remote()), post: vi.fn() };
    window.confirm = vi.fn(() => true);
});

afterEach(() => {
    vi.useRealTimers();
    delete window.axios;
});

describe('estado dos modelos na Gupshup', () => {
    it('lê ao abrir a aba e mostra aprovado, recusado com o motivo da Meta e não criado', async () => {
        const wrapper = mountTemplates();
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledTimes(1);
        expect(window.axios.get).toHaveBeenCalledWith(ROUTES.templates_status);

        expect(card(wrapper, 'aprovado').get('[data-status]').attributes('data-status')).toBe('APPROVED');
        expect(card(wrapper, 'aprovado').get('[data-status]').classes()).toContain('badge-soft-success');
        expect(card(wrapper, 'recusado').get('[data-status]').attributes('data-status')).toBe('FAILED');
        expect(card(wrapper, 'recusado').get('[data-status]').classes()).toContain('badge-soft-danger');
        expect(card(wrapper, 'recusado').get('[data-test="wa-reason"]').text()).toContain('does not have permission');
        expect(card(wrapper, 'faltando').get('[data-status]').attributes('data-status')).toBe('MISSING');

        // Só quem não existe ganha o botão de criar; aprovado e recusado não são recriados.
        expect(card(wrapper, 'faltando').find('[data-test="wa-create"]').exists()).toBe(true);
        expect(card(wrapper, 'aprovado').find('[data-test="wa-create"]').exists()).toBe(false);
        expect(card(wrapper, 'recusado').find('[data-test="wa-create"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="wa-create-missing"]').text()).toContain('Criar os que faltam (2)');
    });

    it('aba oculta não lê; ao abrir lê uma vez só', async () => {
        const wrapper = mountTemplates({ active: false });
        await flushPromises();
        expect(window.axios.get).not.toHaveBeenCalled();

        await wrapper.setProps({ active: true });
        await flushPromises();
        await wrapper.setProps({ active: false });
        await wrapper.setProps({ active: true });
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledTimes(1);
    });

    it('sem a conta comum (mock ou parceiro): não lê nem mostra a barra de criação', async () => {
        const wrapper = mountTemplates({ api: false });
        await flushPromises();

        expect(window.axios.get).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="wa-api-bar"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="wa-status"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="wa-create"]').exists()).toBe(false);
    });

    it('erro ao ler: mostra o motivo no aviso e não inventa estado', async () => {
        window.axios.get.mockRejectedValue({
            response: { data: { error: 'Gupshup HTTP 401: Authentication Failed' } },
        });
        const wrapper = mountTemplates();
        await flushPromises();

        expect(wrapper.get('[data-test="wa-notice"]').text()).toContain('Authentication Failed');
        expect(wrapper.find('[data-test="wa-status"]').exists()).toBe(false);
    });

    it('"Verificar na Gupshup" lê de novo', async () => {
        const wrapper = mountTemplates();
        await flushPromises();

        window.axios.get.mockResolvedValue(remote({ 'easyeye_faltando|pt_BR': { status: 'PENDING', reason: '' } }));
        await wrapper.get('[data-test="wa-check"]').trigger('click');
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledTimes(2);
        expect(card(wrapper, 'faltando').get('[data-status]').attributes('data-status')).toBe('PENDING');
    });
});

describe('criar um modelo', () => {
    it('confirma, envia chave e idioma com o CSRF e passa a "Em análise"', async () => {
        window.axios.post.mockResolvedValue({
            data: { ok: true, status: 'PENDING', message: 'Modelo enviado à Gupshup.' },
        });
        const wrapper = mountTemplates();
        await flushPromises();

        await card(wrapper, 'faltando').get('[data-test="wa-create"]').trigger('click');
        await flushPromises();

        expect(window.confirm).toHaveBeenCalledWith('Enviar o modelo easyeye_faltando (pt_BR)?');
        expect(window.axios.post).toHaveBeenCalledWith(
            ROUTES.templates_create,
            { key: 'faltando', language: 'pt_BR' },
            { headers: { 'X-CSRF-TOKEN': 'test-csrf-token' } },
        );
        expect(card(wrapper, 'faltando').get('[data-status]').attributes('data-status')).toBe('PENDING');
        expect(card(wrapper, 'faltando').find('[data-test="wa-create"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="wa-notice"]').text()).toContain('Modelo enviado');
    });

    it('cancelar a confirmação não envia nada', async () => {
        window.confirm.mockReturnValue(false);
        const wrapper = mountTemplates();
        await flushPromises();

        await card(wrapper, 'faltando').get('[data-test="wa-create"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).not.toHaveBeenCalled();
    });

    it('erro da Gupshup aparece no aviso e o modelo continua "Não criado"', async () => {
        window.axios.post.mockRejectedValue({
            response: { status: 422, data: { error: 'Gupshup HTTP 400: sem permissão' } },
        });
        const wrapper = mountTemplates();
        await flushPromises();

        await card(wrapper, 'faltando').get('[data-test="wa-create"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="wa-notice"]').text()).toContain('sem permissão');
        expect(wrapper.get('[data-test="wa-notice"]').classes()).toContain('alert-danger');
        expect(card(wrapper, 'faltando').get('[data-status]').attributes('data-status')).toBe('MISSING');
    });

    it('idioma fora de WHATSAPP_TEMPLATE_LANGUAGES: não deixa criar', async () => {
        const wrapper = mountTemplates();
        await flushPromises();

        await wrapper.get('[data-test="wa-lang-en"]').trigger('click');

        expect(wrapper.get('[data-test="wa-lang-off"]').exists()).toBe(true);
        expect(card(wrapper, 'faltando').get('[data-test="wa-create"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-test="wa-create-missing"]').attributes('disabled')).toBeDefined();
    });
});

describe('criar os que faltam', () => {
    it('um por vez, com 7 s entre as chamadas, e relê o estado no fim', async () => {
        vi.useFakeTimers();
        window.axios.post.mockResolvedValue({ data: { ok: true, status: 'PENDING', message: 'Enviado.' } });
        const wrapper = mountTemplates();
        await flushPromises();

        await wrapper.get('[data-test="wa-create-missing"]').trigger('click');
        await flushPromises();

        // Primeiro sai na hora; o segundo espera os 7 s e a barra mostra o andamento.
        expect(window.axios.post).toHaveBeenCalledTimes(1);
        expect(window.axios.post).toHaveBeenLastCalledWith(
            ROUTES.templates_create,
            { key: 'faltando', language: 'pt_BR' },
            expect.anything(),
        );
        expect(wrapper.get('[data-test="wa-progress"]').text()).toBe('Enviando 2 de 2…');
        expect(wrapper.get('[data-test="wa-create-missing"]').attributes('disabled')).toBeDefined();

        await vi.advanceTimersByTimeAsync(6999);
        expect(window.axios.post).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(1);
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledTimes(2);
        expect(window.axios.post).toHaveBeenLastCalledWith(
            ROUTES.templates_create,
            { key: 'faltando2', language: 'pt_BR' },
            expect.anything(),
        );
        expect(window.axios.get).toHaveBeenCalledTimes(2); // leitura inicial + a do fim
        expect(wrapper.find('[data-test="wa-progress"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="wa-notice"]').text()).toContain('2 modelo(s) enviado(s)');
    });

    it('para no primeiro erro: os seguintes não são enviados', async () => {
        vi.useFakeTimers();
        window.axios.post.mockRejectedValue({
            response: { status: 422, data: { error: 'Gupshup HTTP 400: sem permissão' } },
        });
        const wrapper = mountTemplates();
        await flushPromises();

        await wrapper.get('[data-test="wa-create-missing"]').trigger('click');
        await flushPromises();
        await vi.advanceTimersByTimeAsync(20000);
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledTimes(1);
        expect(wrapper.get('[data-test="wa-notice"]').text()).toContain('sem permissão');
        expect(wrapper.get('[data-test="wa-notice"]').text()).toContain('Parei no primeiro erro.');
        expect(wrapper.find('[data-test="wa-progress"]').exists()).toBe(false);
    });

    it('sem nada faltando o botão fica desabilitado', async () => {
        window.axios.get.mockResolvedValue(
            remote({
                'easyeye_faltando|pt_BR': { status: 'PENDING', reason: '' },
                'easyeye_faltando2|pt_BR': { status: 'APPROVED', reason: '' },
            }),
        );
        const wrapper = mountTemplates();
        await flushPromises();

        expect(wrapper.get('[data-test="wa-create-missing"]').attributes('disabled')).toBeDefined();
    });
});
