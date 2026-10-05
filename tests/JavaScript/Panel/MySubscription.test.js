import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { router } from '@inertiajs/vue3';

/**
 * Minha assinatura: plano, ciclo, valor, situação, cartão da renovação,
 * faturas em aberto com "Pagar" (checkout em modal), histórico, contratar
 * outro plano/ciclo e a confirmação em tempo real relendo o resumo.
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

import MySubscription from '@/Pages/Panel/MySubscription/Index.vue';
import { resetBillingRealtime } from '@/composables/useBillingRealtime.js';

const t = {
    errors: { already_active: 'A clínica já tem este plano e ciclo ativos.', unknown: 'Erro.' },
    methods: { pix: 'Pix', boleto: 'Boleto', credit_card: 'Cartão de crédito' },
    gateways: { mercadopago: 'Mercado Pago', default: 'o gateway' },
    invoice_status: { pending: 'Em aberto', overdue: 'Vencida', paid: 'Paga' },
    ui: { choose_method: 'Como você quer pagar?', amount: 'Valor', close: 'Fechar' },
    page: {
        title: 'Minha assinatura',
        breadcrumb_home: 'Início',
        plan: 'Plano',
        card_value: ':brand final :last4',
        card_installments: ':count× sem juros',
        no_card: 'Nenhum cartão salvo',
        change_card: 'Trocar cartão',
        open_title: 'Faturas em aberto',
        no_open: 'Nenhuma fatura em aberto.',
        pay: 'Pagar',
        pay_title: 'Pagar a fatura :reference',
        due_on: 'Vence em :date',
        overdue_on: 'Venceu em :date',
        contract_cta: 'Contratar e pagar',
        contract_modal: ':plan — :cycle',
        installments_hint: 'No cartão em até :count× sem juros.',
        paid_notice: 'Pagamento confirmado. Obrigado!',
        ai_pack_invoice: 'Créditos de IA — :credits créditos',
        ai_pack_open_title: 'Pedidos de créditos de IA aguardando pagamento',
        ai_pack_open_hint: 'Não é cobrança da assinatura.',
        ai_pack_open_created: 'Pedido de :date',
        ai_pack_discard: 'Descartar',
        ai_pack_discard_keep: 'Manter pedido',
        ai_pack_discard_confirm: 'Descartar este pedido?',
        ai_pack_discarded: 'Pedido de créditos descartado.',
    },
};

const plans = [
    {
        id: 'plan-pro',
        name: 'Pro',
        default_cycle: 'monthly',
        prices: [
            { cycle: 'monthly', label: 'Mensal', price: 299.9, months: 1, period_label: '/mês' },
            {
                cycle: 'yearly',
                label: 'Anual',
                price: 2990,
                months: 12,
                period_label: '/ano',
                monthly_equivalent: 249.17,
                savings_percent: 16,
            },
        ],
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
            next_billing_at: '2026-11-04',
            payment_method: 'credit_card',
            card: { brand: 'visa', last4: '4242' },
            card_installments: 1,
            can_pay: true,
            can_change_card: true,
        },
        open_invoices: [
            {
                id: 'inv-1',
                reference: 'FAT-001',
                amount: 299.9,
                status: 'overdue',
                due_date: '2026-10-01',
                can_pay: true,
            },
        ],
        invoices: [
            {
                id: 'inv-1',
                reference: 'FAT-001',
                amount: 299.9,
                status: 'overdue',
                due_date: '2026-10-01',
                can_pay: true,
            },
            {
                id: 'inv-0',
                reference: 'FAT-000',
                amount: 299.9,
                status: 'paid',
                due_date: '2026-09-01',
                paid_at: '2026-09-01T10:00:00-03:00',
                payment_method: 'pix',
                can_pay: false,
            },
        ],
        payment: {
            gateway: 'mercadopago',
            methods: [{ method: 'pix', label: 'Pix', mode: 'transparent' }],
            mode: 'transparent',
            card: { gateway: 'mercadopago', public_key: 'APP_USR-1', sdk_url: 'https://sdk.mercadopago.com/js/v2' },
        },
        plans,
        checkout: { max_installments: 12, installment_cycles: ['yearly'] },
        realtime: { channel: 'billing.ent-1', event: '.invoice.paid' },
        ...overrides,
    };
}

let wrapper;

function render(checkout = summary()) {
    wrapper = mount(MySubscription, {
        // Pacotes de IA já vêm do servidor (sem pedido extra ao abrir a tela).
        props: {
            checkout,
            t,
            aiCredits: { allowed: false, reason: 'ai_pack_unavailable', packages: [], purchases: [] },
        },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                CenteredModal: {
                    props: ['open'],
                    template: '<div v-if="open" data-test="modal"><slot name="header" /><slot /></div>',
                },
                CheckoutFlow: {
                    props: ['invoice', 'contract', 'payment', 'amount', 'realtime'],
                    template: '<div data-test="checkout-flow-stub" />',
                },
            },
        },
    });

    return wrapper;
}

const norm = (text) => String(text).replace(/\u00a0/g, ' ');

beforeEach(() => {
    echoState.channels = {};
    resetBillingRealtime();
    vi.clearAllMocks();
    window.history.replaceState({}, '', '/panel/my-subscription');
});

afterEach(() => wrapper?.unmount());

describe('Minha assinatura', () => {
    it('mostra plano, valor, situação, cartão salvo, faturas em aberto e o histórico', () => {
        render();

        expect(wrapper.get('[data-test="my-subscription-plan"]').text()).toBe('Pro');
        expect(norm(wrapper.get('[data-test="my-subscription-amount"]').text())).toBe('R$ 299,90');
        expect(wrapper.get('[data-test="my-subscription-saved-card"]').text()).toBe('visa final 4242');
        expect(wrapper.find('[data-test="my-subscription-change-card"]').exists()).toBe(true);

        const open = wrapper.get('[data-test="open-invoice"]');
        expect(open.text()).toContain('Vencida');
        expect(open.text()).toContain('Venceu em 01/10/2026');

        const rows = wrapper.findAll('[data-test="history-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[1].text()).toContain('Paga');
        expect(rows[1].text()).toContain('Pix');
    });

    it('"Pagar" abre o checkout da fatura no próprio sistema', async () => {
        render();

        await wrapper.get('[data-test="open-invoice-pay"]').trigger('click');

        expect(wrapper.get('[data-test="modal"]').text()).toContain('Pagar a fatura FAT-001');
        const flow = wrapper.getComponent('[data-test="checkout-flow-stub"]');
        expect(flow.props('invoice').id).toBe('inv-1');
        expect(flow.props('realtime').channel).toBe('billing.ent-1');
    });

    it('link do aviso (?invoice=) já abre o pagamento', async () => {
        window.history.replaceState({}, '', '/panel/my-subscription?invoice=inv-1');
        render();
        await flushPromises();

        expect(wrapper.find('[data-test="modal"]').exists()).toBe(true);
        expect(wrapper.getComponent('[data-test="checkout-flow-stub"]').props('invoice').id).toBe('inv-1');
    });

    it('pagamento confirmado (tempo real): avisa e relê o resumo', async () => {
        render();

        echoState.channels['billing.ent-1'].handlers['.invoice.paid']({ invoice_id: 'inv-1', status: 'paid' });
        await flushPromises();

        expect(router.reload).toHaveBeenCalledWith({ only: ['checkout'] });
        expect(wrapper.get('[data-test="my-subscription-paid"]').text()).toContain('Pagamento confirmado. Obrigado!');
    });

    it('mudar para o anual: parcelas sem juros e contratação com as opções do gateway', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    plan: { id: 'plan-pro', name: 'Pro' },
                    cycle: 'yearly',
                    amount: 2990,
                    methods: [],
                    realtime: {},
                },
            },
        });
        render();

        const button = wrapper.get('[data-test="my-subscription-contract"]');
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('title')).toBe('A clínica já tem este plano e ciclo ativos.');

        await wrapper.get('input[data-cycle="yearly"]').setValue(true);
        expect(wrapper.get('[data-test="my-subscription-installments"]').text()).toContain('12× sem juros');

        await wrapper.get('[data-test="my-subscription-contract"]').trigger('click');
        await flushPromises();

        expect(axios.get.mock.calls[0][0]).toContain('panel.my-subscription.options');
        expect(axios.get.mock.calls[0][0]).toContain('billing_cycle=yearly');
        expect(wrapper.get('[data-test="modal"]').text()).toContain('Pro — Anual');
        expect(wrapper.getComponent('[data-test="checkout-flow-stub"]').props('contract')).toEqual({
            plan_id: 'plan-pro',
            billing_cycle: 'yearly',
        });
    });

    it('sem faturas em aberto e sem troca de cartão no gateway', () => {
        const data = summary({ open_invoices: [] });
        data.subscription.can_change_card = false;
        render(data);

        expect(wrapper.find('[data-test="my-subscription-no-open"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="my-subscription-change-card"]').exists()).toBe(false);
    });
});

describe('Minha assinatura — pedidos de créditos de IA não pagos', () => {
    const pack = {
        id: 'inv-pack',
        reference: 'IA-20260920-X',
        amount: 249.9,
        currency: 'BRL',
        status: 'pending',
        // venceu há dias, mas não é dívida da assinatura
        due_date: '2026-09-23',
        created_at: '2026-09-20T10:00:00-03:00',
        can_pay: true,
        can_discard: true,
        kind: 'ai_credit_pack',
        ai_credit_pack: { credits: 100 },
        payment: { gateway: 'mercadopago', methods: [{ method: 'pix', label: 'Pix', mode: 'transparent' }] },
    };

    it('aparecem à parte das faturas da assinatura, sem "Vencida" nem "Venceu em"', () => {
        render(summary({ open_invoices: [], open_ai_packs: [pack] }));

        expect(wrapper.find('[data-test="my-subscription-no-open"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-test="open-invoice"]')).toHaveLength(0);

        const row = wrapper.get('[data-test="open-ai-pack"]');
        expect(wrapper.get('[data-test="open-ai-packs"]').text()).toContain(
            'Pedidos de créditos de IA aguardando pagamento',
        );
        expect(row.text()).toContain('Créditos de IA — 100 créditos');
        expect(row.text()).toContain('Pedido de 20/09/2026');
        expect(row.text()).not.toContain('Vencida');
        expect(row.text()).not.toContain('Venceu em');
        expect(row.find('[data-test="open-ai-pack-pay"]').exists()).toBe(true);
    });

    it('?invoice= que não está em aberto nunca abre o pedido de créditos (vai para a fatura da assinatura ou nada)', async () => {
        window.history.replaceState({}, '', '/panel/my-subscription?invoice=inv-desconhecida');
        render(summary({ open_ai_packs: [pack] }));
        await flushPromises();
        expect(wrapper.getComponent('[data-test="checkout-flow-stub"]').props('invoice').id).toBe('inv-1');
        wrapper.unmount();

        window.history.replaceState({}, '', '/panel/my-subscription?invoice=outra');
        render(summary({ open_invoices: [], open_ai_packs: [pack] }));
        await flushPromises();
        expect(wrapper.find('[data-test="modal"]').exists()).toBe(false);
        wrapper.unmount();

        // Mesmo que um pedido de créditos venha na lista da assinatura (resposta antiga), o fallback o ignora.
        window.history.replaceState({}, '', '/panel/my-subscription?invoice=mais-uma');
        render(summary({ open_invoices: [pack] }));
        await flushPromises();
        expect(wrapper.find('[data-test="modal"]').exists()).toBe(false);
        wrapper.unmount();

        // O próprio pedido, pedido pelo id, abre.
        window.history.replaceState({}, '', '/panel/my-subscription?invoice=inv-pack');
        render(summary({ open_invoices: [], open_ai_packs: [pack] }));
        await flushPromises();
        expect(wrapper.getComponent('[data-test="checkout-flow-stub"]').props('invoice').id).toBe('inv-pack');
    });

    it('"Descartar" pede confirmação, chama o DELETE do pedido e relê o resumo', async () => {
        axios.delete.mockResolvedValue({ data: { data: { invoice: { ...pack, status: 'cancelled' } } } });
        render(summary({ open_invoices: [], open_ai_packs: [pack] }));

        await wrapper.get('[data-test="open-ai-pack-discard"]').trigger('click');
        expect(wrapper.get('[data-test="ai-pack-discard-confirm"]').text()).toContain('Descartar este pedido?');
        expect(axios.delete).not.toHaveBeenCalled();

        await wrapper.get('[data-test="ai-pack-discard-yes"]').trigger('click');
        await flushPromises();

        expect(axios.delete.mock.calls[0][0]).toBe(
            '/_routes/panel.my-subscription.ai-credits.discard?invoice=inv-pack',
        );
        expect(router.reload).toHaveBeenCalledWith({ only: ['checkout'] });
        expect(wrapper.get('[data-test="ai-pack-discarded"]').text()).toContain('Pedido de créditos descartado.');
    });

    it('erro ao descartar (ex.: já pago): mostra a mensagem e mantém os botões', async () => {
        axios.delete.mockRejectedValue({
            response: { status: 409, data: { code: 'ai_pack_not_discardable', message: 'Não pode mais.' } },
        });
        render(summary({ open_invoices: [], open_ai_packs: [pack] }));

        await wrapper.get('[data-test="open-ai-pack-discard"]').trigger('click');
        await wrapper.get('[data-test="ai-pack-discard-yes"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="open-ai-pack"] [role="alert"]').text()).toBe('Não pode mais.');
        expect(wrapper.find('[data-test="open-ai-pack-pay"]').exists()).toBe(true);
    });
});
