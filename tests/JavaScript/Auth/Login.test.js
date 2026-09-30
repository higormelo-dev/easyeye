import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Login from '@/Pages/Auth/Login.vue';

const form = vi.hoisted(() => ({ post: vi.fn(), reset: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    useForm: (initial) => Object.assign(form, initial, { errors: {}, processing: false }),
}));
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
                    sign_in: 'Entrar', panel: { feature_schedule: 'Agenda', feature_record: 'Prontuário' },
                    remember_me: 'Lembrar-me', forget_password: 'Recuperar senha', sign_up: 'Criar conta',
                },
            },
        });

        expect(wrapper.find('.ee-login-quote').exists()).toBe(false);
        expect(wrapper.findAll('.ee-login-trust-item').map(item => item.text())).toEqual(['SSL', 'LGPD', 'CFM']);
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
