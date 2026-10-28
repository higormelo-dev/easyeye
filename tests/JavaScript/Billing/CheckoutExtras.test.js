import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';

/**
 * Troca de cartão (sem cobrar; 3DS do Stripe concluído com a referência
 * seti_), checkout do cadastro no site e os controles do manager (parcelas
 * do checkout e chave pública do gateway).
 */
vi.mock('@laravel/echo-vue', async () => {
    const { ref } = await import('vue');
    const channel = { subscribed: () => channel, listen: () => channel };
    return {
        echo: () => ({ private: () => channel, leave: () => {} }),
        echoIsConfigured: () => true,
        useConnectionStatus: () => ref('connected'),
    };
});
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));

import ReplaceCardFlow from '@/Components/Billing/ReplaceCardFlow.vue';
import SignupCheckout from '@/Components/Billing/SignupCheckout.vue';
import CheckoutSettingsCard from '@/Pages/Panel/Manager/Plans/CheckoutSettingsCard.vue';
import GatewayCredentialsModal from '@/Pages/Panel/Manager/Gateways/GatewayCredentialsModal.vue';
import { resetBillingRealtime } from '@/composables/useBillingRealtime.js';

const t = {
    errors: {
        card_change_unsupported: 'Este meio de pagamento não troca o cartão sem um pagamento.',
        unknown: 'Erro.',
    },
    methods: { pix: 'Pix' },
    gateways: { stripe_br: 'Stripe', pagbank: 'PagBank', default: 'o gateway' },
    ui: {
        card_save: 'Salvar o novo cartão',
        card_saved: 'Cartão salvo: :brand final :last4.',
        card_3ds: 'Confirme no banco.',
        card_secure_note: 'Direto para :gateway.',
        choose_method: 'Como você quer pagar?',
        retry: 'Tentar de novo',
    },
};

let wrapper;

beforeEach(() => {
    vi.clearAllMocks();
    resetBillingRealtime();
});

afterEach(() => {
    wrapper?.unmount();
    delete window.Stripe;
    delete window.PagSeguro;
});

describe('ReplaceCardFlow', () => {
    function stripeSdk() {
        const stripe = {
            elements: vi.fn(() => ({
                create: vi.fn(() => ({ mount: vi.fn(), on: vi.fn(), destroy: vi.fn() })),
                submit: vi.fn().mockResolvedValue({}),
            })),
            createConfirmationToken: vi.fn().mockResolvedValue({ confirmationToken: { id: 'ctoken_9' } }),
            handleNextAction: vi.fn().mockResolvedValue({ setupIntent: { status: 'succeeded' } }),
        };
        window.Stripe = vi.fn(() => stripe);

        return stripe;
    }

    const stripeCard = {
        gateway: 'stripe_br',
        public_key: 'pk_test',
        sdk_url: 'https://js.stripe.com/basil/stripe.js',
        saves_card: true,
    };

    it('Stripe: modo setup (nada cobrado); 3DS confirmado e concluído com a referência seti_', async () => {
        const stripe = stripeSdk();
        const api = {
            replaceCard: vi
                .fn()
                .mockResolvedValueOnce({
                    status: 'requires_action',
                    next_action: {
                        type: 'stripe_handle_next_action',
                        client_secret: 'seti_1_secret',
                        reference: 'seti_1',
                    },
                })
                .mockResolvedValueOnce({ status: 'saved', card: { brand: 'mastercard', last4: '4444' } }),
        };
        wrapper = mount(ReplaceCardFlow, { props: { t, api, card: stripeCard, amount: 299.9 } });
        await flushPromises();

        expect(stripe.elements.mock.calls[0][0].mode).toBe('setup');
        expect(wrapper.get('[data-test="card-submit"]').text()).toBe('Salvar o novo cartão');

        await wrapper.get('[data-test="card-form"]').trigger('submit');
        await flushPromises();

        expect(api.replaceCard.mock.calls[0][0]).toEqual({ card_token: 'ctoken_9' });
        expect(stripe.handleNextAction).toHaveBeenCalledWith({ clientSecret: 'seti_1_secret' });
        expect(api.replaceCard.mock.calls[1][0]).toEqual({ card_token: 'seti_1' });
        expect(api.replaceCard.mock.calls[0][1]).not.toBe(api.replaceCard.mock.calls[1][1]);
        expect(wrapper.get('[data-test="replace-card-saved"]').text()).toBe('Cartão salvo: mastercard final 4444.');
        expect(wrapper.emitted('saved')[0][0]).toEqual({ brand: 'mastercard', last4: '4444' });
    });

    it('PagBank sem troca avulsa: mostra o aviso do contrato', async () => {
        window.PagSeguro = { encryptCard: () => ({ encryptedCard: 'ENC', hasErrors: false }) };
        const api = {
            replaceCard: vi.fn().mockRejectedValue({
                response: { status: 422, data: { code: 'card_change_unsupported', message: 'x' } },
            }),
        };
        wrapper = mount(ReplaceCardFlow, {
            props: {
                t,
                api,
                card: { gateway: 'pagbank', public_key: 'K', sdk_url: 'https://assets.pagseguro.com.br/a.js' },
            },
        });
        await flushPromises();
        await wrapper.get('[data-test="card-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-test="replace-card-error"]').text()).toContain(
            'Este meio de pagamento não troca o cartão sem um pagamento.',
        );
    });
});

describe('SignupCheckout', () => {
    it('carrega as formas do plano/ciclo escolhido no cadastro e mostra o checkout', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    plan: { id: 'pro', name: 'Pro' },
                    cycle: 'yearly',
                    amount: 2990,
                    gateway: 'mercadopago',
                    methods: [{ method: 'pix', label: 'Pix', mode: 'transparent' }],
                    realtime: { channel: 'billing.e1', event: '.invoice.paid' },
                },
            },
        });
        wrapper = mount(SignupCheckout, {
            props: {
                t,
                labels: { pay_later: 'Pagar depois e entrar no painel' },
                planId: 'pro',
                cycle: 'yearly',
                redirect: '/panel/dashboard',
            },
        });
        await flushPromises();

        expect(axios.get.mock.calls[0][0]).toBe('/_routes/signup-checkout.options?plan_id=pro&billing_cycle=yearly');
        expect(wrapper.find('[data-test="checkout-methods"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="signup-pay-later"]').attributes('href')).toBe('/panel/dashboard');
    });

    it('falha ao carregar: mensagem e "Tentar de novo"', async () => {
        axios.get.mockRejectedValueOnce({
            response: { status: 403, data: { code: 'signup_only', message: 'Só no cadastro.' } },
        });
        wrapper = mount(SignupCheckout, { props: { t, labels: {}, planId: 'pro', cycle: 'monthly' } });
        await flushPromises();

        expect(wrapper.get('[data-test="signup-checkout-error"]').text()).toContain('Só no cadastro.');
    });
});

describe('Manager — parcelas do checkout', () => {
    const tPlans = {
        checkout_title: 'Parcelamento no cartão',
        checkout_max_installments: 'Máximo de parcelas sem juros',
        checkout_installments_unit: 'parcela|parcelas',
        checkout_save: 'Salvar',
        checkout_saved: 'Atualizado.',
        checkout_installments_hint: 'Só no anual.',
    };

    it('salva o teto (1–12) pelo PUT checkout-settings', async () => {
        window.axios = { put: vi.fn().mockResolvedValue({ data: { message: 'ok', data: { max_installments: 6 } } }) };
        wrapper = mount(CheckoutSettingsCard, { props: { maxInstallments: 12, t: tPlans } });

        const select = wrapper.get('[data-test="checkout-max-installments"]');
        expect(select.findAll('option')).toHaveLength(12);
        expect(select.findAll('option')[0].text()).toBe('1 parcela');
        expect(wrapper.get('[data-test="checkout-settings-save"]').attributes('disabled')).toBeDefined();

        select.element.options[5].selected = true;
        await select.trigger('change');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(window.axios.put).toHaveBeenCalledWith(
            '/_routes/manager.plans.checkout-settings',
            { max_installments: 6 },
            expect.any(Object),
        );
        delete window.axios;
    });
});

describe('Manager — chave pública do gateway', () => {
    it('Stripe: campo de chave pública; recusa a secreta (sk_) antes de enviar', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ data: [] }) });
        wrapper = mount(GatewayCredentialsModal, {
            props: {
                open: false,
                gateway: { code: 'stripe_br', name: 'Stripe', credentials_url: '/c', credentials_store_url: '/s' },
                t: {
                    js_error_public_key_secret: 'Esta parece ser a chave secreta.',
                    modal_cred_public_key: 'Chave pública',
                },
            },
            global: { stubs: { teleport: true, ConfirmationWithReasonModal: true } },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        const input = wrapper.get('[data-test="gateway-public-key"]');
        await wrapper.find('input[type="password"]').setValue('sk_live_secret');
        await input.setValue('sk_live_oops');
        expect(wrapper.get('[data-test="gateway-public-key"]').classes()).toContain('is-invalid');

        await wrapper.get('form').trigger('submit');
        expect(wrapper.text()).toContain('Esta parece ser a chave secreta.');
    });

    it('InfinitePay (só link): sem campo de chave pública', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ data: [] }) });
        wrapper = mount(GatewayCredentialsModal, {
            props: { open: true, gateway: { code: 'infinitepay', name: 'InfinitePay', credentials_url: '/c' }, t: {} },
            global: { stubs: { teleport: true, ConfirmationWithReasonModal: true } },
        });
        await flushPromises();

        expect(wrapper.find('[data-test="gateway-public-key"]').exists()).toBe(false);
    });
});
