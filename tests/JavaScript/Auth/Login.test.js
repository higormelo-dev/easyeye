import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Login from '@/Pages/Auth/Login.vue';

const form = vi.hoisted(() => ({ post: vi.fn(), reset: vi.fn() }));
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        Head: { render: () => null },
        usePage: () => ({ props: { t_ui: { password_show: 'Show password', password_hide: 'Hide password' } } }),
        useForm: (initial) => reactive(Object.assign(form, initial, { errors: {}, processing: false })),
    };
});
vi.mock('@/Layouts/GuestLayout.vue', () => ({
    default: { template: '<div><aside><slot name="left-panel" /></aside><main><slot /></main></div>' },
}));

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.clearAllMocks();
});

describe('Login — conteúdo público', () => {
    it('mantém o acesso funcional sem NPS ou depoimento fictício', async () => {
        wrapper = mount(Login, {
            props: {
                t: {
                    sign_in: 'Entrar',
                    panel: { feature_schedule: 'Agenda', feature_record: 'Prontuário' },
                    remember_me: 'Lembrar-me',
                    forget_password: 'Recuperar senha',
                    sign_up: 'Criar conta',
                },
            },
        });

        expect(wrapper.find('.ee-login-quote').exists()).toBe(false);
        expect(wrapper.findAll('.ee-login-trust-item').map((item) => item.text())).toEqual(['SSL', 'LGPD', 'CFM']);
        expect(wrapper.text()).not.toMatch(/97%|NPS|Ricardo Mendes/);
        expect(wrapper.get('a[href="/forgot-password"]').text()).toBe('Recuperar senha');
        expect(wrapper.get('a[href="/register"]').text()).toBe('Criar conta');

        await wrapper.get('input[type="email"]').setValue('pessoa@example.com');
        await wrapper.get('input[autocomplete="current-password"]').setValue('Password1!');
        await wrapper.get('#remember').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(form).toMatchObject({ email: 'pessoa@example.com', password: 'Password1!', remember: true });
        expect(form.post).toHaveBeenCalledWith('/login', { onFinish: expect.any(Function) });
        form.post.mock.calls[0][1].onFinish();
        expect(form.reset).toHaveBeenCalledWith('password');
    });
});

it('associa labels e erros aos controles reais', async () => {
    wrapper = mount(Login, { props: { t: { email: 'Email', password: 'Password' } } });
    wrapper.vm.form.errors.email = 'Email inválido';
    wrapper.vm.form.errors.password = 'Senha inválida';
    await wrapper.vm.$nextTick();
    expect(wrapper.get('label[for="login-email"]').text()).toBe('Email');
    expect(wrapper.get('label[for="login-password"]').text()).toBe('Password');
    for (const key of ['email', 'password']) {
        const input = wrapper.get(`#login-${key}`);
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(wrapper.get(`#${input.attributes('aria-describedby')}`).text()).toContain(form.errors[key]);
    }
});
it('mostrar senha é alcançável, traduzido e anuncia estado sem submeter', async () => {
    wrapper = mount(Login);
    const button = wrapper.get('button[aria-label="Show password"]');
    expect(button.attributes('tabindex')).not.toBe('-1');
    expect(button.attributes('aria-pressed')).toBe('false');
    expect(button.get('i').attributes('aria-hidden')).toBe('true');
    await button.trigger('click');
    expect(button.attributes('aria-label')).toBe('Hide password');
    expect(button.attributes('aria-pressed')).toBe('true');
    expect(wrapper.get('#login-password').attributes('type')).toBe('text');
    expect(form.post).not.toHaveBeenCalled();
});
