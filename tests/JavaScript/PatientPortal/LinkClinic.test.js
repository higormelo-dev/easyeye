import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import LinkClinic from '@/Pages/PatientPortal/Auth/LinkClinic.vue';

/**
 * Portal do Paciente — convite de outra clínica aberto por quem já tem conta:
 * a tela mostra a clínica e a conta, pede a senha e avisa quando o convite foi
 * para outro e-mail. Textos vêm do servidor (lang/<locale>/patient_portal.php).
 */
const T = {
    page_title: 'Adicionar clínica — Portal do Paciente',
    title: 'Adicionar clínica à sua conta',
    intro: 'Documentos liberados por:',
    clinic_fallback: 'Clínica',
    account: 'Sua conta',
    email_mismatch: 'O convite foi enviado para :email.',
    submit: 'Adicionar clínica',
    not_you: 'Não é sua conta? Sair',
};

function mountPage(props = {}) {
    return mount(LinkClinic, {
        props: { clinics: ['CLÍNICA B'], accountEmail: 'maria@example.com', inviteEmail: 'maria@example.com', emailMatches: true, t: T, ...props },
    });
}

describe('PatientPortal/Auth/LinkClinic', () => {
    it('mostra a clínica e a conta; um botão adiciona (sem pedir senha de novo)', () => {
        const wrapper = mountPage();

        expect(wrapper.find('h1').text()).toBe(T.title);
        expect(wrapper.text()).toContain('CLÍNICA B');
        expect(wrapper.text()).toContain('maria@example.com');
        expect(wrapper.find('input[type="password"]').exists()).toBe(false);
        expect(wrapper.find('button[type="submit"]').text()).toBe(T.submit);
    });

    it('convite para OUTRO e-mail: explica e não oferece o formulário de vínculo (o servidor decide)', () => {
        expect(mountPage().find('[role="alert"]').exists()).toBe(false);
        expect(mountPage().find('form').exists()).toBe(true);

        const other = mountPage({ inviteEmail: 'outra@example.com', emailMatches: false });
        expect(other.find('[role="alert"]').text()).toContain('O convite foi enviado para outra@example.com.');
        expect(other.find('form').exists()).toBe(false);
        expect(other.find('button[type="submit"]').exists()).toBe(false);
    });

    it('sem clínica identificada usa o texto genérico traduzido', () => {
        expect(mountPage({ clinics: [] }).find('ul').text()).toBe(T.clinic_fallback);
    });

    it('"não é sua conta" encerra a sessão do portal', async () => {
        const wrapper = mountPage();

        await wrapper.findAll('button').find((b) => b.text() === T.not_you).trigger('click');

        expect(router.post).toHaveBeenCalledWith('/_routes/patient-portal.logout');
    });
});
