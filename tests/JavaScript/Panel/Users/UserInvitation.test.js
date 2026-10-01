import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';

/**
 * Convite a quem já usa o EasyEye (tela de usuários): o painel envia só
 * e-mail + perfil; a lista de pendentes mostra o e-mail digitado e cancela
 * com confirmação. A resposta do servidor é sempre a mesma (testada no Pest).
 */
const forms = [];

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        router: { delete: vi.fn() },
        usePage: () => ({ props: {} }),
        useForm: (data) => {
            const initial = { ...data };
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                reset: () => Object.assign(form, initial),
                clearErrors: () => {
                    form.errors = {};
                },
                post: vi.fn(),
            });
            forms.push(form);

            return form;
        },
    };
});

const { router } = await import('@inertiajs/vue3');
const UserInviteModal = (await import('@/Pages/Panel/Users/UserInviteModal.vue')).default;
const UserInvitationsPending = (await import('@/Pages/Panel/Users/UserInvitationsPending.vue')).default;

const T = {
    invitation: {
        title: 'Convidar quem já usa o EasyEye',
        intro: 'Informe o e-mail.',
        email: 'E-mail',
        rule: 'Perfil nesta clínica',
        rule_hint: 'Médicos pelo cadastro de médicos.',
        submit: 'Enviar convite',
        close: 'Fechar',
        pending_title: 'Convites pendentes',
        pending_hint: 'Aguardando.',
        col_email: 'E-mail',
        col_rule: 'Perfil',
        col_sent_at: 'Enviado em',
        col_expires_at: 'Expira em',
        cancel: 'Cancelar convite',
        confirm_cancel: 'Cancelar?',
    },
};

beforeEach(() => {
    forms.length = 0;
    vi.clearAllMocks();
});

describe('UserInviteModal', () => {
    async function mountModal() {
        const wrapper = mount(UserInviteModal, {
            props: { open: false, roles: { financial: 'Financeiro', secretary: 'Secretária' }, t: T },
            attachTo: document.body,
            global: {
                stubs: {
                    teleport: true,
                    SearchSelect: {
                        props: ['options'],
                        template: '<div class="roles">{{ options.map(o => o.label).join(",") }}</div>',
                    },
                },
            },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        await nextTick();

        return wrapper;
    }

    it('oferece só os perfis convidáveis (sem médico) e campo de e-mail com rótulo', async () => {
        const wrapper = await mountModal();

        expect(wrapper.find('label[for="invite-email"]').text()).toContain('E-mail');
        expect(wrapper.find('#invite-email').attributes('type')).toBe('email');
        expect(wrapper.find('.roles').text()).toBe('Financeiro,Secretária');
        wrapper.unmount();
    });

    it('envia e-mail + perfil para a rota de convite', async () => {
        const wrapper = await mountModal();
        const form = forms.at(-1);
        form.email = 'maria@example.com';
        form.rule = 'financial';

        await wrapper.find('form').trigger('submit');

        expect(form.post).toHaveBeenCalledWith(
            '/_routes/panel.accesscontrol.users.invitations.store',
            expect.objectContaining({ preserveScroll: true }),
        );
        wrapper.unmount();
    });
});

describe('UserInvitationsPending', () => {
    const invitations = [
        {
            id: 'inv-9',
            email: 'maria@example.com',
            rule: 'Financeiro',
            sent_at: '2026-09-30T10:00:00-03:00',
            expires_at: '2026-10-07T10:00:00-03:00',
        },
    ];

    it('lista o e-mail digitado e o perfil', () => {
        const wrapper = mount(UserInvitationsPending, { props: { invitations, t: T } });

        expect(wrapper.text()).toContain('maria@example.com');
        expect(wrapper.text()).toContain('Financeiro');
        expect(wrapper.findAll('th').map((th) => th.text())).toEqual(
            expect.arrayContaining(['E-mail', 'Perfil', 'Enviado em', 'Expira em']),
        );
    });

    it('cancelar pede confirmação', async () => {
        const wrapper = mount(UserInvitationsPending, { props: { invitations, t: T } });
        window.confirm = vi.fn().mockReturnValueOnce(false).mockReturnValueOnce(true);

        await wrapper.find('button').trigger('click');
        expect(router.delete).not.toHaveBeenCalled();

        await wrapper.find('button').trigger('click');
        expect(router.delete).toHaveBeenCalledWith('/_routes/panel.accesscontrol.users.invitations.destroy/inv-9', {
            preserveScroll: true,
        });
    });
});
