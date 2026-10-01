import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import maskDirective from '@/directives/mask.js';

/**
 * Convite a médico que já tem login no EasyEye (outra clínica):
 *  - modal de cadastro: servidor devolve `existing_doctor` → aviso + botão
 *    "Enviar convite" (POST para panel.doctors.invitations.store);
 *  - listagem: convites pendentes com cancelamento confirmado;
 *  - tela do médico: aceitar/recusar pelos links assinados do servidor.
 */
const forms = [];

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        router: { reload: vi.fn(), delete: vi.fn(), visit: vi.fn() },
        usePage: () => ({ props: {} }),
        Head: { template: '<div />' },
        useForm: (data) => {
            const initial = { ...data };
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                reset: () => Object.assign(form, initial),
                clearErrors: () => { form.errors = {}; },
                post: vi.fn(),
                put: vi.fn(),
            });
            forms.push(form);

            return form;
        },
    };
});

vi.mock('@/Layouts/GuestLayout.vue', () => ({
    default: { props: ['title', 'subtitle'], template: '<div><h4 class="title">{{ title }}</h4><p class="subtitle">{{ subtitle }}</p><slot /></div>' },
}));

const { router } = await import('@inertiajs/vue3');
const DoctorFormModal = (await import('@/Pages/Panel/Doctors/DoctorFormModal.vue')).default;
const DoctorInvitationsPending = (await import('@/Pages/Panel/Doctors/DoctorInvitationsPending.vue')).default;
const DoctorInvitationPage = (await import('@/Pages/Auth/ClinicInvitation.vue')).default;

const T = {
    col_name: 'Nome', col_record: 'CRM',
    invitation: {
        send_button: 'Enviar convite', pending_title: 'Convites pendentes', pending_hint: 'Aguardando.',
        col_sent_at: 'Enviado em', col_expires_at: 'Expira em', cancel: 'Cancelar convite', confirm_cancel: 'Cancelar?',
    },
};

beforeEach(() => {
    forms.length = 0;
    vi.clearAllMocks();
});

async function mountModal(props = {}) {
    const wrapper = mount(DoctorFormModal, {
        props: { open: false, doctorId: null, t: T, ...props },
        attachTo: document.body,
        global: { directives: { mask: maskDirective }, stubs: { teleport: true, SearchSelect: true } },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
    await nextTick();

    return wrapper;
}

describe('DoctorFormModal — médico que já tem login no EasyEye', () => {
    it('sem o aviso do servidor não mostra convite', async () => {
        const wrapper = await mountModal();

        expect(wrapper.text()).not.toContain('Enviar convite');
        wrapper.unmount();
    });

    it('com existing_doctor mostra a mensagem do servidor e o botão envia o convite com os dados digitados', async () => {
        const wrapper = await mountModal();
        const form = forms.at(-1);
        form.errors = { existing_doctor: 'Este médico já possui cadastro no EasyEye, mas ainda não nesta clínica.' };
        await nextTick();

        const alert = wrapper.find('[role="status"]');
        expect(alert.text()).toContain('já possui cadastro no EasyEye');

        await alert.find('button').trigger('click');
        expect(form.post).toHaveBeenCalledWith('/_routes/panel.doctors.invitations.store', expect.objectContaining({ preserveScroll: true }));
        wrapper.unmount();
    });
});

describe('DoctorInvitationsPending', () => {
    const invitations = [{ id: 'inv-1', name: 'JOAO', record: 'CRM-1', sent_at: '2026-09-30T10:00:00-03:00', expires_at: '2026-10-07T10:00:00-03:00' }];

    it('lista nome/CRM digitados pela clínica e datas no formato do idioma', () => {
        const wrapper = mount(DoctorInvitationsPending, { props: { invitations, t: T } });

        expect(wrapper.find('h2').text()).toContain('Convites pendentes');
        expect(wrapper.text()).toContain('JOAO');
        expect(wrapper.text()).toContain('CRM-1');
        expect(wrapper.findAll('th').map((th) => th.text())).toEqual(expect.arrayContaining(['Nome', 'CRM', 'Enviado em', 'Expira em']));
    });

    it('cancelar pede confirmação; só apaga quando confirmado', async () => {
        const wrapper = mount(DoctorInvitationsPending, { props: { invitations, t: T } });
        const confirm = vi.fn();
        window.confirm = confirm;

        confirm.mockReturnValueOnce(false);
        await wrapper.find('button').trigger('click');
        expect(router.delete).not.toHaveBeenCalled();

        confirm.mockReturnValueOnce(true);
        await wrapper.find('button').trigger('click');
        expect(router.delete).toHaveBeenCalledWith('/_routes/panel.doctors.invitations.destroy/inv-1', { preserveScroll: true });
    });
});

describe('Auth/ClinicInvitation — resposta do convidado', () => {
    const page = { title: 'Convite para atender em :clinic', intro: ':clinic convidou você.', note: 'Nada muda.', accept: 'Aceitar convite', decline: 'Recusar', closed: 'Indisponível.', back: 'Minhas clínicas' };
    const mountPage = (props = {}) => mount(DoctorInvitationPage, {
        props: { clinicName: 'CLÍNICA B', open: true, acceptUrl: '/aceitar?signature=a', declineUrl: '/recusar?signature=b', t: page, ...props },
    });

    it('mostra só o nome da clínica e envia aceitar/recusar para os links assinados', async () => {
        const wrapper = mountPage();

        expect(wrapper.find('.title').text()).toBe('Convite para atender em CLÍNICA B');
        const [accept, decline] = wrapper.findAll('button');

        await accept.trigger('click');
        expect(forms.at(-1).post).toHaveBeenCalledWith('/aceitar?signature=a');

        await decline.trigger('click');
        expect(forms.at(-1).post).toHaveBeenCalledWith('/recusar?signature=b');
    });

    it('convite indisponível: explica e oferece voltar, sem botões de aceite', () => {
        const wrapper = mountPage({ open: false });

        expect(wrapper.find('[role="alert"]').text()).toBe('Indisponível.');
        expect(wrapper.text()).not.toContain('Aceitar convite');
    });
});
