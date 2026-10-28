import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

/**
 * Correções da revisão do checkout (front): GET só lê e a emissão é POST
 * (issue_required → issueCharge); SDKs descarregados ao sair; aviso de
 * renovação à vista no parcelado; troca de plano (upgrade com o valor
 * proporcional, downgrade agendado só com confirmação); Turnstile no cadastro
 * e o motivo na página de erro.
 */
const echoState = vi.hoisted(() => ({ channels: {}, status: null }));
vi.mock('@laravel/echo-vue', async () => {
    const { ref } = await import('vue');
    echoState.status = ref('connected');
    const fakeEcho = {
        private(name) {
            const channel = { name, handlers: {} };
            channel.subscribed = () => channel;
            channel.listen = (event, cb) => ((channel.handlers[event] = cb), channel);
            echoState.channels[name] = channel;
            return channel;
        },
        leave: () => {},
    };
    return { echo: () => fakeEcho, echoIsConfigured: () => true, useConnectionStatus: () => echoState.status };
});
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));

import axios from 'axios';
import CheckoutFlow from '@/Components/Billing/CheckoutFlow.vue';
import CardPaymentForm from '@/Components/Billing/CardPaymentForm.vue';
import TurnstileWidget from '@/Components/Security/TurnstileWidget.vue';
import ErrorPage from '@/Pages/Error.vue';
import MySubscription from '@/Pages/Panel/MySubscription/Index.vue';
import { checkoutError, createCheckoutApi, panelEndpoints } from '@/Support/billing/checkoutApi.js';
import { unloadCheckoutSdks } from '@/Support/billing/loadScript.js';
import { resetBillingRealtime } from '@/composables/useBillingRealtime.js';

const norm = (text) => String(text).replace(/\u00a0/g, ' ');

const t = {
    errors: { unknown: 'Erro.', card_attempts_exceeded: 'Texto local', already_active: 'Já ativo.' },
    methods: { pix: 'Pix', boleto: 'Boleto', credit_card: 'Cartão de crédito' },
    gateways: { mercadopago: 'Mercado Pago', pagbank: 'PagBank', default: 'o gateway' },
    invoice_status: { pending: 'Em aberto', overdue: 'Vencida', paid: 'Paga' },
    ui: {
        choose_method: 'Como você quer pagar?',
        amount: 'Valor',
        close: 'Fechar',
        boleto_title: 'Pague com boleto',
        boleto_line: 'Linha digitável',
        boleto_due: 'Vencimento: :date',
        pix_waiting: 'Aguardando.',
        card_renewal_in_full: 'O parcelamento vale para esta cobrança. As renovações no cartão são cobradas à vista.',
        installment_one: 'À vista — :amount',
        installment_many: ':count× de :amount sem juros',
        card_pay: 'Pagar :amount',
        card_secure_note: 'Direto para :gateway.',
    },
    page: {
        title: 'Minha assinatura',
        breadcrumb_home: 'Início',
        card_value: ':brand final :last4',
        no_card: 'Nenhum cartão salvo',
        pay: 'Pagar',
        due_on: 'Vence em :date',
        overdue_on: 'Venceu em :date',
        contract_cta: 'Contratar e pagar',
        contract_modal: ':plan — :cycle',
        upgrade_title: 'Upgrade imediato',
        upgrade_body:
            'Você paga agora :amount — a diferença proporcional aos :days dias que faltam do período atual (o plano atual vale :credit nesses dias). O novo plano vale assim que o pagamento for confirmado; a próxima cobrança, de :next_amount, será em :date.',
        upgrade_unpaid_note: 'Se o pagamento não for concluído, a assinatura atual continua como está.',
        scheduled_title: 'Mudança no fim do período pago',
        scheduled_body:
            'A mudança para :plan (:cycle) vale a partir de :date, quando termina o período já pago. Nada é cobrado agora; em :date cobraremos :amount.',
        scheduled_confirm: 'Confirmar a mudança',
        scheduled_done: 'Mudança agendada para :date.',
        scheduled_change: 'Mudança agendada',
        scheduled_change_value: ':plan (:cycle) a partir de :date — :amount',
        renewal_in_full: 'A renovação no cartão é cobrada à vista (o parcelamento vale só na contratação).',
        plan_change_invoice: 'Upgrade para :plan',
    },
};

let wrapper;

beforeEach(() => {
    echoState.channels = {};
    resetBillingRealtime();
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/panel/my-subscription');
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    delete window.turnstile;
    delete window.__eeTurnstile;
});

describe('GET só lê; a emissão é POST', () => {
    it('instructions com issue_required → issueCharge (POST com Idempotency-Key) e mostra o boleto', async () => {
        const api = {
            summary: vi.fn(),
            instructions: vi
                .fn()
                .mockResolvedValue({ mode: 'transparent', method: 'boleto', instructions: null, issue_required: true }),
            issueCharge: vi.fn().mockResolvedValue({
                mode: 'transparent',
                method: 'boleto',
                instructions: { boleto: { digitable_line: '23793.38128', due_date: '2026-10-20' } },
            }),
        };

        wrapper = mount(CheckoutFlow, {
            props: {
                t,
                api,
                payment: {
                    gateway: 'mercadopago',
                    methods: [{ method: 'boleto', label: 'Boleto', mode: 'transparent' }],
                },
                invoice: { id: 'inv-1', reference: 'FAT-1', amount: 299.9 },
                amount: 299.9,
                realtime: { channel: 'billing.ent-1', event: '.invoice.paid' },
            },
        });

        await wrapper.get('[data-method="boleto"]').trigger('click');
        await flushPromises();

        expect(api.instructions).toHaveBeenCalledWith('inv-1', 'boleto');
        const [invoiceId, method, key] = api.issueCharge.mock.calls[0];
        expect([invoiceId, method]).toEqual(['inv-1', 'boleto']);
        expect(typeof key).toBe('string');
        expect(wrapper.get('textarea').element.value).toContain('23793.38128');
    });

    it('api.issueCharge faz POST na rota charge só com a forma; mensagens de antifraude vêm do servidor', async () => {
        const http = { post: vi.fn().mockResolvedValue({ data: { data: { ok: true } } }) };
        const api = createCheckoutApi(panelEndpoints(), http);

        await api.issueCharge('inv-9', 'pix', 'key-1');

        expect(http.post.mock.calls[0][0]).toContain('panel.my-subscription.charge');
        expect(http.post.mock.calls[0][1]).toEqual({ method: 'pix' });
        expect(http.post.mock.calls[0][2].headers['Idempotency-Key']).toBe('key-1');

        const err = checkoutError(
            { response: { status: 429, data: { code: 'card_attempts_exceeded', message: 'Mensagem do servidor' } } },
            t,
        );
        expect(err.message).toBe('Mensagem do servidor');
    });
});

describe('SDKs do cartão descarregados ao sair do checkout', () => {
    it('unloadCheckoutSdks tira os scripts dos gateways e as globais; scripts do app ficam', () => {
        // type inerte: o ambiente de teste não tenta baixar os scripts.
        const sdk = document.createElement('script');
        sdk.type = 'text/x-inert';
        sdk.src = 'https://js.stripe.com/basil/stripe.js';
        const own = document.createElement('script');
        own.type = 'text/x-inert';
        own.src = 'https://app.example.com/build/panel.js';
        document.head.append(sdk, own);
        window.Stripe = () => ({});
        window.MercadoPago = function MercadoPago() {};

        unloadCheckoutSdks();

        expect(document.querySelector('script[src="https://js.stripe.com/basil/stripe.js"]')).toBeNull();
        expect(document.querySelector('script[src="https://app.example.com/build/panel.js"]')).not.toBeNull();
        expect(window.Stripe).toBeUndefined();
        expect(window.MercadoPago).toBeUndefined();
        own.remove();
    });

    it('CheckoutFlow desmontado descarrega os SDKs', async () => {
        window.PagSeguro = { encryptCard: vi.fn() };
        wrapper = mount(CheckoutFlow, {
            props: { t, api: { summary: vi.fn() }, payment: { gateway: 'pagbank', methods: [] }, amount: 10 },
        });
        wrapper.unmount();
        wrapper = null;

        expect(window.PagSeguro).toBeUndefined();
    });
});

describe('renovação no cartão à vista', () => {
    const card = {
        gateway: 'pagbank',
        public_key: 'PUB',
        sdk_url: 'https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js',
        renewal_in_full: true,
        installments: [
            { count: 1, amount: 2990, total: 2990 },
            { count: 12, amount: 249.17, total: 2990 },
        ],
    };

    it('parcelado no anual: avisa antes de pagar que as renovações são à vista', async () => {
        window.PagSeguro = { encryptCard: vi.fn() };
        wrapper = mount(CardPaymentForm, {
            props: { config: card, amount: 2990, ui: t.ui, gatewayName: 'PagBank', submitCard: vi.fn() },
        });
        await flushPromises();

        expect(wrapper.get('[data-test="card-renewal-in-full"]').text()).toContain('cobradas à vista');
    });

    it('troca de cartão (setup) e à vista: sem o aviso', async () => {
        window.PagSeguro = { encryptCard: vi.fn() };
        wrapper = mount(CardPaymentForm, {
            props: { config: card, amount: 0, mode: 'setup', ui: t.ui, gatewayName: 'PagBank', submitCard: vi.fn() },
        });
        await flushPromises();

        expect(wrapper.find('[data-test="card-renewal-in-full"]').exists()).toBe(false);
    });
});

describe('Minha assinatura — troca de plano de quem já paga', () => {
    const plans = [
        {
            id: 'plan-pro',
            name: 'Pro',
            default_cycle: 'monthly',
            prices: [{ cycle: 'monthly', label: 'Mensal', price: 299.9, months: 1, period_label: '/mês' }],
        },
        {
            id: 'plan-plus',
            name: 'Plus',
            default_cycle: 'monthly',
            prices: [{ cycle: 'monthly', label: 'Mensal', price: 499.9, months: 1, period_label: '/mês' }],
        },
    ];

    function summary(overrides = {}) {
        return {
            subscription: {
                id: 's1',
                plan: { id: 'plan-pro', name: 'Pro' },
                cycle: 'monthly',
                cycle_label: 'Mensal',
                amount: 299.9,
                status: 'active',
                status_label: 'Ativa',
                is_awaiting_first_payment: false,
                next_billing_at: '2026-10-20',
                payment_method: 'credit_card',
                card: { brand: 'visa', last4: '4242' },
                card_installments: 12,
                renewal_in_full: true,
                can_pay: true,
                can_change_card: false,
                scheduled_change: null,
                ...overrides.subscription,
            },
            open_invoices: overrides.open_invoices ?? [],
            invoices: [],
            payment: { gateway: 'mercadopago', methods: [], mode: 'transparent', card: null },
            plans,
            checkout: { max_installments: 12, installment_cycles: ['yearly'] },
            realtime: { channel: 'billing.ent-1', event: '.invoice.paid' },
        };
    }

    function render(checkout) {
        wrapper = mount(MySubscription, {
            props: { checkout, t },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                    CenteredModal: { props: ['open'], template: '<div v-if="open" data-test="modal"><slot /></div>' },
                    CheckoutFlow: {
                        props: ['invoice', 'contract', 'payment', 'amount', 'realtime'],
                        template: '<div data-test="checkout-flow-stub" />',
                    },
                },
            },
        });
    }

    async function chooseAndOpen(planId) {
        await wrapper.get(`input[name="my-sub-plan"][value="${planId}"]`).setValue(true);
        await wrapper.get('[data-test="my-subscription-contract"]').trigger('click');
        await flushPromises();
    }

    it('upgrade: mostra o valor proporcional e a data antes de pagar; o checkout cobra a diferença', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    plan: { id: 'plan-plus', name: 'Plus' },
                    cycle: 'monthly',
                    amount: 100,
                    methods: [{ method: 'pix', label: 'Pix', mode: 'transparent' }],
                    change: {
                        type: 'upgrade',
                        plan: { id: 'plan-plus', name: 'Plus' },
                        cycle: 'monthly',
                        current: { cycle: 'monthly' },
                        amount_now: 100,
                        credit: 149.95,
                        remaining_days: 15,
                        new_amount: 499.9,
                        next_charge_at: '2026-10-20',
                    },
                    realtime: {},
                },
            },
        });
        render(summary());

        expect(wrapper.get('[data-test="my-subscription-renewal-in-full"]').text()).toContain('cobrada à vista');

        await chooseAndOpen('plan-plus');

        const box = wrapper.get('[data-test="plan-change-summary"]');
        expect(box.attributes('data-type')).toBe('upgrade');
        expect(norm(box.text())).toContain('Você paga agora R$ 100,00');
        expect(norm(box.text())).toContain('15 dias');
        expect(norm(box.text())).toContain('20/10/2026');
        expect(box.text()).toContain('a assinatura atual continua como está');
        expect(wrapper.getComponent('[data-test="checkout-flow-stub"]').props('amount')).toBe(100);
    });

    it('downgrade: mostra a data em que vale, confirma sem forma de pagamento e não abre o checkout', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    plan: { id: 'plan-pro', name: 'Pro' },
                    cycle: 'monthly',
                    amount: 299.9,
                    methods: [],
                    change: {
                        type: 'scheduled',
                        reason: 'downgrade',
                        plan: { id: 'plan-pro', name: 'Pro' },
                        cycle: 'monthly',
                        new_amount: 299.9,
                        effective_at: '2026-10-20T23:59:59-03:00',
                    },
                },
            },
        });
        axios.post.mockResolvedValue({
            data: { data: { status: 'scheduled', change: { effective_at: '2026-10-20T23:59:59-03:00' } } },
        });
        const checkout = summary({ subscription: { plan: { id: 'plan-plus', name: 'Plus' } } });
        render(checkout);

        await chooseAndOpen('plan-pro');

        expect(norm(wrapper.get('[data-test="plan-change-summary"]').text())).toContain(
            'A mudança para Pro (Mensal) vale a partir de 20/10/2026',
        );
        expect(wrapper.find('[data-test="checkout-flow-stub"]').exists()).toBe(false);

        await wrapper.get('[data-test="plan-change-confirm"]').trigger('click');
        await flushPromises();

        expect(axios.post.mock.calls[0][0]).toContain('panel.my-subscription.contract');
        expect(axios.post.mock.calls[0][1]).toEqual({ plan_id: 'plan-pro', billing_cycle: 'monthly' });
        expect(wrapper.get('[data-test="plan-change-scheduled"]').text()).toContain('Mudança agendada para 20/10/2026');
    });

    it('mudança agendada e fatura do upgrade aparecem na tela', () => {
        render(
            summary({
                subscription: {
                    scheduled_change: {
                        plan: { id: 'plan-pro', name: 'Pro' },
                        cycle_label: 'Mensal',
                        amount: 299.9,
                        effective_at: '2026-10-20T23:59:59-03:00',
                    },
                },
                open_invoices: [
                    {
                        id: 'inv-up',
                        reference: 'UPG-1',
                        amount: 100,
                        status: 'pending',
                        due_date: '2026-10-08',
                        can_pay: true,
                        kind: 'plan_change',
                        plan_change: { plan: { id: 'plan-plus', name: 'Plus' } },
                    },
                ],
            }),
        );

        expect(norm(wrapper.get('[data-test="my-subscription-scheduled"]').text())).toBe(
            'Pro (Mensal) a partir de 20/10/2026 — R$ 299,90',
        );
        expect(wrapper.get('[data-test="open-invoice"]').text()).toContain('Upgrade para Plus');
    });
});

describe('Turnstile no cadastro', () => {
    it('renderiza com a site key, emite o token e o reset pede outro', async () => {
        const render = vi.fn((el, options) => {
            options.callback('tok-123');
            return 'w1';
        });
        window.turnstile = { render, reset: vi.fn(), remove: vi.fn() };

        wrapper = mount(TurnstileWidget, { props: { siteKey: '0x4AAA', label: 'Verificação de segurança' } });
        await flushPromises();

        expect(render.mock.calls[0][1].sitekey).toBe('0x4AAA');
        expect(wrapper.emitted('token')[0]).toEqual(['tok-123']);

        wrapper.vm.reset();
        expect(window.turnstile.reset).toHaveBeenCalledWith('w1');
        expect(wrapper.emitted('token').at(-1)).toEqual(['']);

        wrapper.unmount();
        wrapper = null;
        expect(window.turnstile.remove).toHaveBeenCalledWith('w1');
    });
});

describe('página de erro', () => {
    it('mostra o motivo específico enviado pelo servidor', () => {
        wrapper = mount(ErrorPage, {
            props: { status: 403, message: 'Só o administrador, o financeiro ou o dono da clínica podem pagar.' },
            global: { stubs: { Head: true } },
        });

        expect(wrapper.text()).toContain('Só o administrador, o financeiro ou o dono da clínica podem pagar.');
    });
});
