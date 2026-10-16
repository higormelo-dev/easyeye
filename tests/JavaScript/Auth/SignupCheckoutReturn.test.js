import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import SignupCheckoutReturn from '@/Pages/Auth/SignupCheckoutReturn.vue';

const echoState = vi.hoisted(() => ({ initialized: false, channels: {}, status: null }));
vi.mock('axios', () => ({ default: { get: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null } }));
vi.mock('@/Layouts/SiteLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Billing/SignupCheckout.vue', () => ({
    default: {
        name: 'SignupCheckout',
        props: ['planId', 'cycle'],
        template: '<div data-test="signup-checkout-stub" />',
    },
}));
vi.mock('@/echo.js', () => {
    echoState.initialized = true;
    return {};
});
vi.mock('@laravel/echo-vue', async () => {
    const { ref } = await import('vue');
    echoState.status = ref('connected');
    const fakeEcho = {
        private(name) {
            const channel = { handlers: {} };
            channel.subscribed = (callback) => ((channel.subscribedCallback = callback), channel);
            channel.listen = (event, callback) => ((channel.handlers[event] = callback), channel);
            echoState.channels[name] = channel;
            return channel;
        },
        leave: vi.fn(),
    };
    return {
        echo: () => fakeEcho,
        echoIsConfigured: () => echoState.initialized,
        useConnectionStatus: () => echoState.status,
    };
});

const labels = {
    page: {
        awaiting_confirmation: 'Aguardando confirmação',
        checkout_return_success:
            'Pagamento enviado pelo ambiente seguro. Aguardando a confirmação — esta tela atualiza sozinha.',
        checkout_return_cancel: 'O pagamento não foi concluído.',
        checkout_return_expired: 'O link expirou.',
    },
    ui: {
        paid_title: 'Pagamento confirmado.',
        processing: 'Processando o pagamento…',
        check_status: 'Já paguei — atualizar',
    },
    errors: { unknown: 'Não foi possível atualizar.' },
};
let wrapper;

function mountReturn(extra = {}) {
    wrapper = mount(SignupCheckoutReturn, {
        attachTo: document.body,
        props: {
            t: {},
            tAuth: { register: { go_to_panel: 'Entrar no painel' } },
            tCheckout: labels,
            status: 'success',
            invoice: { id: 'inv-1', status: 'pending' },
            subscription_active: false,
            next_step: 'verify_email',
            summary_url: '/signup-checkout/summary',
            ...extra,
        },
    });
}

beforeEach(() => vi.clearAllMocks());
beforeEach(() => {
    echoState.initialized = false;
    echoState.channels = {};
});
afterEach(() => {
    wrapper?.unmount();
    wrapper?.element?.remove();
});

describe('Retorno do checkout no cadastro', () => {
    it('foca a região de status e mostra a confirmação pendente pela fatura real', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    invoices: [{ id: 'inv-1', status: 'pending' }],
                    realtime: { channel: 'billing.entity-1', event: '.invoice.paid' },
                },
            },
        });
        mountReturn();
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(document.activeElement).toBe(wrapper.get('[data-testid="checkout-return-status"]').element);
        expect(wrapper.get('[data-testid="checkout-return-status"]').text()).toContain('Aguardando a confirmação');
        expect(echoState.initialized).toBe(true);
        expect(echoState.channels['billing.entity-1'].handlers['.invoice.paid']).toEqual(expect.any(Function));
    });

    it('libera o próximo passo somente depois de summary confirmar pagamento', async () => {
        axios.get.mockResolvedValue({ data: { data: { invoices: [{ id: 'inv-1', status: 'paid' }] } } });
        mountReturn({ next_step: 'verify_phone', next_url: '/verify-whatsapp' });
        await flushPromises();

        expect(wrapper.get('[data-testid="checkout-return-next"]').text()).toContain('Verificar WhatsApp');
        expect(wrapper.get('[data-testid="checkout-return-next"] a').attributes('href')).toBe('/verify-whatsapp');
    });

    it('em cancelado permite remontar o checkout com plano e ciclo do summary', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    invoices: [{ id: 'inv-1', status: 'pending' }],
                    subscription: { plan: { id: 'pro' }, cycle: 'monthly' },
                },
            },
        });
        mountReturn({ status: 'cancel' });
        await flushPromises();

        expect(wrapper.get('[data-testid="checkout-return-status"]').text()).toContain('não foi concluído');
        expect(wrapper.getComponent({ name: 'SignupCheckout' }).props()).toMatchObject({
            planId: 'pro',
            cycle: 'monthly',
        });
    });

    it('com fatura nula não busca summary, não mostra referência nem remonta checkout', async () => {
        mountReturn({ invoice: null, status: 'cancel' });
        await flushPromises();

        expect(axios.get).not.toHaveBeenCalled();
        expect(wrapper.find('.signup-return-reference').exists()).toBe(false);
        expect(wrapper.findComponent({ name: 'SignupCheckout' }).exists()).toBe(false);
    });
});
