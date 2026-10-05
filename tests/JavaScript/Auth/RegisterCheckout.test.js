import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import Register from '@/Pages/Auth/Register.vue';

/**
 * Cadastro no site: "Testar grátis" (trial) ou "Contratar agora" (sem trial,
 * segue para o pagamento no próprio site — signup-checkout.*).
 */
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('@/Layouts/SiteLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null } }));
vi.mock('@/echo.js', () => ({}));
vi.mock('@/Components/Security/TurnstileWidget.vue', () => ({
    default: {
        name: 'TurnstileWidget',
        props: ['siteKey', 'label', 'language'],
        emits: ['token'],
        template: '<div data-test="turnstile-stub" />',
        methods: { reset() {} },
    },
}));
vi.mock('@/Components/Billing/SignupCheckout.vue', () => ({
    default: {
        name: 'SignupCheckout',
        props: ['t', 'labels', 'planId', 'cycle', 'redirect'],
        template: '<div data-test="signup-checkout-stub" />',
    },
}));

const plans = [
    {
        id: 'pro',
        name: 'Pro',
        price: 299.9,
        price_period_label: '/mês',
        default_cycle: 'yearly',
        prices: [
            { cycle: 'monthly', label: 'Mensal', months: 1, price: 299.9, period_label: '/mês' },
            {
                cycle: 'yearly',
                label: 'Anual',
                months: 12,
                price: 2990,
                period_label: '/ano',
                monthly_equivalent: 249.17,
                savings_percent: 16,
            },
        ],
        features: [],
    },
];

const tAuth = {
    register: {
        start_mode_label: 'Como você quer começar?',
        mode_trial: 'Testar grátis por :days dias',
        mode_checkout: 'Contratar agora',
        create_and_pay: 'Criar conta e ir para o pagamento',
        start_trial: 'Iniciar período grátis',
        step_payment: 'Pagamento',
        checkout_title: 'Pagamento da assinatura',
        checkout_subtitle: ':plan — :cycle',
    },
};

let wrapper;

async function mountAtCompanyStep(extraProps = {}) {
    wrapper = mount(Register, {
        props: {
            ...extraProps,
            plans,
            selectedPlanId: 'pro',
            trialDays: 14,
            routes: { siteHome: '/' },
            t: { nav: {}, testimonials: { items: [] }, contact: {} },
            tAuth,
            tCheckout: { ui: {}, errors: {} },
        },
        global: { directives: { mask: {} }, stubs: { Transition: { template: '<div><slot /></div>' } } },
    });
    const fields = wrapper.findAll('input');
    await fields[0].setValue('Pessoa de Teste');
    await fields[1].setValue('pessoa@example.com');
    await fields[2].setValue('Password1!');
    await fields[3].setValue('Password1!');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const company = wrapper.findAll('input');
    await company[0].setValue('Clínica de Teste');
    await company[1].setValue('11988887777');
}

beforeEach(() => vi.clearAllMocks());
afterEach(() => wrapper?.unmount());

describe('Cadastro — como começar', () => {
    it('padrão é o teste grátis (start_mode=trial) e segue para o painel', async () => {
        const assign = vi.fn();
        Object.defineProperty(window, 'location', { value: { href: '', assign }, writable: true, configurable: true });
        axios.post.mockResolvedValue({ data: { redirect: '/panel/dashboard', checkout: null } });
        await mountAtCompanyStep();

        expect(wrapper.get('[data-mode="trial"]').attributes('aria-checked')).toBe('true');
        expect(wrapper.get('[data-mode="trial"]').text()).toContain('Testar grátis por 14 dias');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/register', expect.objectContaining({ start_mode: 'trial' }));
        expect(window.location.href).toBe('/panel/dashboard');
    });

    it('"Contratar agora": cria a conta sem trial e abre o pagamento no próprio site', async () => {
        axios.post.mockResolvedValue({
            data: {
                redirect: '/panel/dashboard',
                checkout: { contract: '/signup-checkout/contract', options: '/signup-checkout/options' },
            },
        });
        await mountAtCompanyStep();

        await wrapper.get('[data-mode="checkout"]').trigger('click');
        expect(wrapper.get('[data-mode="checkout"]').attributes('aria-checked')).toBe('true');
        expect(wrapper.find('[data-test="register-step-payment"]').exists()).toBe(true);
        expect(wrapper.get('.reg-btn-primary').text()).toContain('Criar conta e ir para o pagamento');
        expect(wrapper.find('.reg-quick-start').exists()).toBe(false);

        await wrapper.get('form').trigger('submit');
        await flushPromises();
        // Checkout carregado sob demanda (chunk separado, com o Echo).
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/register',
            expect.objectContaining({ start_mode: 'checkout', plan_id: 'pro', billing_cycle: 'yearly' }),
        );
        const checkout = wrapper.getComponent({ name: 'SignupCheckout' });
        expect(checkout.props()).toMatchObject({ planId: 'pro', cycle: 'yearly', redirect: '/panel/dashboard' });
        expect(wrapper.get('.reg-card-title').text()).toBe('Pagamento da assinatura');
        expect(wrapper.get('.reg-card-subtitle').text()).toBe('Pro — Anual');
    });
});

describe('Cadastro — captcha (Turnstile)', () => {
    it('com a site key: mostra o widget e envia o token no POST /register', async () => {
        axios.post.mockResolvedValue({ data: { redirect: '/panel/dashboard', checkout: null } });
        Object.defineProperty(window, 'location', { value: { href: '' }, writable: true, configurable: true });
        await mountAtCompanyStep({ turnstileSiteKey: '0x4AAA' });

        const widget = wrapper.getComponent({ name: 'TurnstileWidget' });
        expect(widget.props('siteKey')).toBe('0x4AAA');
        widget.vm.$emit('token', 'tok-abc');
        await flushPromises();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/register', expect.objectContaining({ turnstile_token: 'tok-abc' }));
    });

    it('sem a site key: nenhum widget nem campo extra', async () => {
        axios.post.mockResolvedValue({ data: { redirect: '/panel/dashboard', checkout: null } });
        Object.defineProperty(window, 'location', { value: { href: '' }, writable: true, configurable: true });
        await mountAtCompanyStep();

        expect(wrapper.find('[data-test="turnstile-stub"]').exists()).toBe(false);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axios.post.mock.calls[0][1]).not.toHaveProperty('turnstile_token');
    });
});
