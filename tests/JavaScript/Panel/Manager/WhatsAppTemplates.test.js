import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import WhatsAppTemplates from '@/Pages/Panel/Manager/WhatsApp/WhatsAppTemplates.vue';

/**
 * Manager → WhatsApp: modelos com prévia no visual do WhatsApp, texto para a
 * Meta ({{n}}), variáveis e botões, em pt_BR e en.
 */
const t = {
    templates_title: 'Modelos do WhatsApp',
    templates_hint: 'Prévia',
    template_categories: { UTILITY: 'Utilidade', AUTHENTICATION: 'Autenticação' },
    template_groups: {
        patients: 'Mensagens aos pacientes',
        registration: 'Cadastro e teste',
        saas: 'Avisos do EasyEye à clínica',
    },
    template_preview_language: 'Idioma da prévia',
    template_languages: { pt_BR: 'Português', en: 'Inglês' },
    template_language_off: 'Inglês ainda não liberado',
    template_variables: 'Variáveis',
    template_example: 'ex.: :value',
    template_copy_meta: 'Copiar texto para a Meta',
    template_copy_name: 'Copiar nome',
    template_copied: 'Copiado!',
    template_no_variables: 'Sem variáveis.',
    template_auth_note: 'Texto padrão da Meta',
    template_url_note: 'Abre o EasyEye',
};

const confirmation = {
    key: 'appointment_confirmation',
    name: 'easyeye_confirmacao_consulta',
    category: 'UTILITY',
    group: 'patients',
    texts: {
        pt_BR: {
            meta: 'Olá, {{1}}! Lembrete de {{2}}.',
            preview: 'Olá, Maria! Lembrete de Clínica Visão.',
            footer: 'Para não receber mais avisos, responda SAIR.',
            buttons: [
                { type: 'quick_reply', label: 'Confirmar' },
                { type: 'quick_reply', label: 'Cancelar' },
            ],
            params: [
                { placeholder: '{{1}}', key: 'first_name', label: 'Primeiro nome do paciente', example: 'Maria' },
                { placeholder: '{{2}}', key: 'clinic', label: 'Nome da clínica', example: 'Clínica Visão' },
            ],
        },
        en: {
            meta: 'Hello, {{1}}! Reminder from {{2}}.',
            preview: 'Hello, Maria! Reminder from Vision Clinic.',
            footer: 'To stop receiving notices, reply STOP.',
            buttons: [
                { type: 'quick_reply', label: 'Confirm' },
                { type: 'quick_reply', label: 'Cancel' },
            ],
            params: [],
        },
    },
};

const code = {
    key: 'verification_code',
    name: 'easyeye_codigo_verificacao',
    category: 'AUTHENTICATION',
    group: 'registration',
    texts: {
        pt_BR: {
            meta: '*{{1}}* é seu código',
            preview: '*482913* é seu código',
            footer: null,
            buttons: [{ type: 'otp', label: 'Copiar código' }],
            params: [],
        },
    },
};

const dunning = {
    key: 'saas_dunning_reminder',
    name: 'easyeye_cobranca_lembrete',
    category: 'UTILITY',
    group: 'saas',
    texts: {
        pt_BR: {
            meta: 'EasyEye: {{1}}',
            preview: 'EasyEye: Clínica Visão',
            footer: null,
            buttons: [{ type: 'url', label: 'Abrir o EasyEye' }],
            params: [],
        },
    },
};

const mountIt = (props = {}) =>
    mount(WhatsAppTemplates, { props: { templates: [confirmation, code, dunning], t, ...props } });

afterEach(() => vi.restoreAllMocks());

describe('Modelos do WhatsApp', () => {
    it('agrupa por público e mostra prévia com exemplo, rodapé, botões e variáveis', () => {
        const wrapper = mountIt();

        expect(wrapper.findAll('section').map((s) => s.attributes('data-group'))).toEqual([
            'patients',
            'registration',
            'saas',
        ]);

        const card = wrapper.find('[data-template="appointment_confirmation"]');
        expect(card.text()).toContain('easyeye_confirmacao_consulta');
        expect(card.text()).toContain('Utilidade');
        expect(card.find('[data-test="wa-preview"]').text()).toContain('Olá, Maria! Lembrete de Clínica Visão.');
        expect(card.find('[data-test="wa-preview"]').text()).toContain('responda SAIR');
        expect(card.findAll('[data-test="wa-buttons"] .wa-button').map((b) => b.text())).toEqual([
            'Confirmar',
            'Cancelar',
        ]);
        expect(card.text()).toContain('{{1}} Primeiro nome do paciente');
        expect(card.text()).toContain('ex.: Maria');
    });

    it('autenticação: nota da Meta, botão "Copiar código" e sem "copiar texto para a Meta"; URL: nota do link', () => {
        const wrapper = mountIt();

        const auth = wrapper.find('[data-template="verification_code"]');
        expect(auth.text()).toContain('Texto padrão da Meta');
        expect(auth.find('.wa-button i').classes()).toContain('ti-copy');
        expect(auth.find('[data-test="wa-copy-meta"]').exists()).toBe(false);

        const url = wrapper.find('[data-template="saas_dunning_reminder"]');
        expect(url.text()).toContain('Abre o EasyEye');
        expect(url.find('.wa-button i').classes()).toContain('ti-external-link');
    });

    it('troca o idioma da prévia e avisa quando o inglês não está liberado para envio', async () => {
        const wrapper = mountIt({ enabledLanguages: ['pt_BR'] });
        expect(wrapper.find('[data-test="wa-lang-off"]').exists()).toBe(false);

        await wrapper.find('[data-test="wa-lang-en"]').trigger('click');

        const card = wrapper.find('[data-template="appointment_confirmation"]');
        expect(card.find('[data-test="wa-preview"]').text()).toContain('Hello, Maria!');
        expect(card.findAll('.wa-button').map((b) => b.text())).toEqual(['Confirm', 'Cancel']);
        expect(wrapper.find('[data-test="wa-lang-off"]').exists()).toBe(true);
        // Modelo sem versão em inglês cai no português.
        expect(wrapper.find('[data-template="verification_code"] [data-test="wa-preview"]').text()).toContain('482913');
    });

    it('copia o texto no formato da Meta ({{n}}) e mostra "Copiado!"', async () => {
        const writeText = vi.fn().mockResolvedValue();
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        const wrapper = mountIt();

        await wrapper.find('[data-template="appointment_confirmation"] [data-test="wa-copy-meta"]').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('Olá, {{1}}! Lembrete de {{2}}.');
        expect(wrapper.find('[data-template="appointment_confirmation"] [data-test="wa-copy-meta"]').text()).toContain(
            'Copiado!',
        );
    });
});
