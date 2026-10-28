import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

/**
 * Cartão no Asaas pelo ambiente seguro (Asaas Checkout): o checkout mostra a
 * forma "hosted", pede a abertura (POST) e leva a clínica à página do Asaas
 * na mesma aba; a volta para Minha assinatura (?checkout_return=) mostra
 * "aguardando confirmação" (sucesso) ou o aviso de não concluído/expirado.
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
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));

import CheckoutFlow from '@/Components/Billing/CheckoutFlow.vue';
import MySubscription from '@/Pages/Panel/MySubscription/Index.vue';
import { resetBillingRealtime } from '@/composables/useBillingRealtime.js';

const t = {
    errors: { gateway_error: 'Não foi possível falar com o meio de pagamento agora.', unknown: 'Algo deu errado.' },
    methods: { pix: 'Pix', boleto: 'Boleto', credit_card: 'Cartão de crédito' },
    gateways: { asaas: 'Asaas', default: 'o gateway' },
    invoice_status: { pending: 'Em aberto', overdue: 'Vencida', paid: 'Paga' },
    ui: {
        choose_method: 'Como você quer pagar?',
        method_hint_pix: 'Na hora',
        method_hint_boleto: 'Até 3 dias',
        method_hint_card: 'Renovação automática.',
        method_external: 'Concluído no site de :gateway',
        method_hosted: 'Ambiente seguro de :gateway',
        method_hint_card_hosted: 'Você digita o cartão no ambiente seguro de :gateway e volta para cá.',
        hosted_title: 'Pagar no ambiente seguro de :gateway',
        hosted_body: 'O cartão é digitado na página de :gateway.',
        hosted_recurrent: 'O cartão fica cadastrado em :gateway para as renovações.',
        hosted_button: 'Ir para o pagamento seguro',
        hosted_expires: 'Este link vale até :date.',
        hosted_charge_on: 'O cartão é cobrado em :date.',
        hosted_missing: 'Não foi possível abrir o pagamento seguro agora.',
        amount: 'Valor',
        invoice_ref: 'Fatura :reference',
        loading: 'Preparando…',
        retry: 'Tentar de novo',
        back_to_methods: 'Escolher outra forma',
        close: 'Fechar',
    },
    page: {
        title: 'Minha assinatura',
        breadcrumb_home: 'Início',
        open_title: 'Faturas em aberto',
        no_open: 'Nenhuma fatura em aberto.',
        pay: 'Pagar',
        due_on: 'Vence em :date',
        overdue_on: 'Venceu em :date',
        no_card: 'Nenhum cartão salvo',
        card_value: ':brand final :last4',
        paid_notice: 'Pagamento confirmado. Obrigado!',
        checkout_return_success: 'Pagamento enviado pelo ambiente seguro. Aguardando a confirmação.',
        checkout_return_cancel: 'O pagamento no ambiente seguro não foi concluído.',
        checkout_return_expired: 'O link de pagamento expirou.',
        awaiting_confirmation: 'Aguardando confirmação',
        card_reregister: 'Com a troca de plano, a cobrança automática no cartão foi refeita sem o cartão.',
    },
};

const invoice = { id: 'inv-1', reference: 'FAT-001', amount: 299.9, due_date: '2026-10-13', can_pay: true };
const realtime = { channel: 'billing.ent-1', event: '.invoice.paid' };
const asaasPayment = {
    gateway: 'asaas',
    methods: [
        { method: 'pix', label: 'Pix', mode: 'transparent' },
        { method: 'boleto', label: 'Boleto', mode: 'transparent' },
        { method: 'credit_card', label: 'Cartão de crédito', mode: 'hosted' },
    ],
    mode: 'hosted',
    card: null,
};

let wrapper;
let assign;

beforeEach(() => {
    echoState.channels = {};
    resetBillingRealtime();
    vi.clearAllMocks();
    assign = vi.fn();
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: {
            ...window.location,
            assign,
            href: 'http://localhost/panel/my-subscription',
            pathname: '/panel/my-subscription',
            search: '',
        },
    });
});

afterEach(() => wrapper?.unmount());

describe('CheckoutFlow — cartão no ambiente seguro do Asaas', () => {
    it('mostra o cartão como "ambiente seguro do Asaas" (não como link externo)', () => {
        wrapper = mount(CheckoutFlow, {
            props: { t, api: {}, payment: asaasPayment, invoice, amount: 299.9, realtime },
            attachTo: document.body,
        });

        const card = wrapper.get('[data-method="credit_card"]');
        expect(card.attributes('data-mode')).toBe('hosted');
        expect(card.get('[data-test="method-hosted"]').text()).toBe('Ambiente seguro de Asaas');
        expect(card.text()).toContain('Você digita o cartão no ambiente seguro de Asaas e volta para cá.');
        expect(wrapper.find('[data-test="method-external"]').exists()).toBe(false);
    });

    it('a leitura pede a abertura (POST) e o botão leva à página do Asaas na mesma aba', async () => {
        const api = {
            instructions: vi
                .fn()
                .mockResolvedValue({ invoice, mode: 'hosted', method: 'credit_card', issue_required: true }),
            issueCharge: vi.fn().mockResolvedValue({
                invoice,
                mode: 'hosted',
                method: 'credit_card',
                checkout_url: 'https://asaas.com/checkoutSession/show?id=chk_1',
                recurrent: true,
                expires_at: '2099-10-05T11:00:00-03:00',
                first_charge_on: '2099-10-08',
            }),
            summary: vi.fn(),
        };
        wrapper = mount(CheckoutFlow, {
            props: { t, api, payment: asaasPayment, invoice, amount: 299.9, realtime },
            attachTo: document.body,
        });

        await wrapper.get('[data-method="credit_card"]').trigger('click');
        await flushPromises();

        expect(api.instructions).toHaveBeenCalledWith('inv-1', 'credit_card');
        expect(api.issueCharge).toHaveBeenCalledWith('inv-1', 'credit_card', expect.any(String));
        expect(wrapper.attributes('data-step')).toBe('hosted');
        expect(wrapper.text()).toContain('Pagar no ambiente seguro de Asaas');
        expect(wrapper.get('[data-test="checkout-hosted-recurrent"]').text()).toContain('para as renovações');
        // 1ª cobrança da assinatura no cartão no vencimento futuro da fatura: avisa a data.
        expect(wrapper.get('[data-test="checkout-hosted-charge-on"]').text()).toBe('O cartão é cobrado em 08/10/2099.');

        await wrapper.get('[data-test="checkout-hosted-go"]').trigger('click');

        expect(assign).toHaveBeenCalledWith('https://asaas.com/checkoutSession/show?id=chk_1');
    });

    it('link inseguro (não https) não vira botão', async () => {
        const api = {
            instructions: vi.fn().mockResolvedValue({ mode: 'hosted', checkout_url: 'javascript:alert(1)' }),
            summary: vi.fn(),
        };
        wrapper = mount(CheckoutFlow, {
            props: { t, api, payment: asaasPayment, invoice, amount: 299.9, realtime },
            attachTo: document.body,
        });

        await wrapper.get('[data-method="credit_card"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="checkout-hosted-go"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Não foi possível abrir o pagamento seguro agora.');
        expect(assign).not.toHaveBeenCalled();
    });

    it('contratar no cartão (Asaas): o pedido de contratação já devolve o ambiente seguro', async () => {
        const api = {
            contract: vi.fn().mockResolvedValue({
                invoice,
                mode: 'hosted',
                checkout_url: 'https://asaas.com/checkoutSession/show?id=chk_2',
                recurrent: true,
            }),
            summary: vi.fn(),
        };
        wrapper = mount(CheckoutFlow, {
            props: {
                t,
                api,
                payment: asaasPayment,
                contract: { plan_id: 'plan-pro', billing_cycle: 'monthly' },
                amount: 299.9,
                realtime,
            },
            attachTo: document.body,
        });

        await wrapper.get('[data-method="credit_card"]').trigger('click');
        await flushPromises();

        expect(api.contract).toHaveBeenCalledWith(
            { plan_id: 'plan-pro', billing_cycle: 'monthly', method: 'credit_card' },
            expect.any(String),
        );
        expect(wrapper.attributes('data-step')).toBe('hosted');
    });
});

function summary(openInvoices) {
    return {
        subscription: {
            id: 's1',
            plan: { id: 'plan-pro', name: 'Pro' },
            cycle: 'monthly',
            amount: 299.9,
            status: 'active',
            is_awaiting_first_payment: false,
            can_pay: true,
        },
        open_invoices: openInvoices,
        open_ai_packs: [],
        invoices: openInvoices,
        payment: asaasPayment,
        plans: [],
        checkout: { max_installments: 1, installment_cycles: [] },
        realtime,
    };
}

function renderPage(checkout) {
    return mount(MySubscription, {
        props: { checkout, t, aiCredits: { allowed: false, packages: [], purchases: [] } },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                CenteredModal: { props: ['open'], template: '<div v-if="open"><slot /></div>' },
                CheckoutFlow: { template: '<div />' },
            },
        },
    });
}

describe('Minha assinatura — volta do ambiente seguro do Asaas', () => {
    function returnTo(query) {
        window.location.search = query;
        window.location.href = `http://localhost/panel/my-subscription${query}`;
        window.history.replaceState({}, '', `/panel/my-subscription${query}`);
    }

    it('sucesso: "aguardando confirmação" até o webhook confirmar (tempo real), sem abrir o pagamento de novo', async () => {
        returnTo('?checkout_return=success&checkout_invoice=inv-1');
        wrapper = renderPage(summary([{ ...invoice, status: 'pending' }]));
        await flushPromises();

        expect(wrapper.get('[data-test="checkout-return-awaiting"]').text()).toContain('Aguardando a confirmação');
        expect(wrapper.find('[data-test="checkout-return-cancel"]').exists()).toBe(false);

        echoState.channels['billing.ent-1'].handlers['.invoice.paid']({ invoice_id: 'inv-1', status: 'paid' });
        await flushPromises();

        expect(wrapper.find('[data-test="checkout-return-awaiting"]').exists()).toBe(false);
    });

    it('fatura com o checkout pago (CHECKOUT_PAID) mostra "Aguardando confirmação" mesmo sem o parâmetro', () => {
        wrapper = renderPage(summary([{ ...invoice, status: 'pending', awaiting_confirmation: true }]));

        expect(wrapper.get('[data-test="open-invoice-awaiting"]').text()).toBe('Aguardando confirmação');
        expect(wrapper.find('[data-test="checkout-return-awaiting"]').exists()).toBe(true);
    });

    it('fatura paga no cartão aguardando confirmação: sem "Pagar" (outro pagamento cobraria o cartão de novo), nem pelo link ?invoice=', async () => {
        returnTo('?invoice=inv-1');
        wrapper = renderPage(summary([{ ...invoice, status: 'pending', awaiting_confirmation: true }]));
        await flushPromises();

        expect(wrapper.get('[data-test="open-invoice-awaiting"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="open-invoice-pay"]').exists()).toBe(false);
        expect(wrapper.findAll('button').some((b) => b.text().startsWith('Pagar'))).toBe(false);
    });

    it('troca de plano refez a recorrência sem o cartão: aviso para pagar a próxima fatura no cartão', () => {
        const checkout = summary([{ ...invoice, status: 'pending' }]);
        wrapper = renderPage({
            ...checkout,
            subscription: { ...checkout.subscription, card_reregister_required: true },
        });

        expect(wrapper.get('[data-test="my-subscription-card-reregister"]').text()).toContain('refeita sem o cartão');
        wrapper.unmount();

        wrapper = renderPage(summary([{ ...invoice, status: 'pending' }]));
        expect(wrapper.find('[data-test="my-subscription-card-reregister"]').exists()).toBe(false);
    });

    it('cancelado ou expirado: avisa que não foi concluído', async () => {
        returnTo('?checkout_return=cancel&checkout_invoice=inv-1');
        wrapper = renderPage(summary([{ ...invoice, status: 'pending' }]));

        expect(wrapper.get('[data-test="checkout-return-cancel"]').text()).toContain('não foi concluído');
        wrapper.unmount();

        returnTo('?checkout_return=expired&checkout_invoice=inv-1');
        wrapper = renderPage(summary([{ ...invoice, status: 'pending' }]));

        expect(wrapper.get('[data-test="checkout-return-expired"]').text()).toContain('expirou');
    });
});
