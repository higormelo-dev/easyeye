import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';

/**
 * Rodada 5 — A4: na tela de IA, o pedido de créditos ainda não pago aparece
 * com "Continuar pagamento" (abre o checkout da fatura do pedido) e
 * "Descartar" (com confirmação — DELETE ai-credits/{invoice}). Em Minha
 * assinatura (sem `showPending`) fica de fora: lá os pedidos já têm seção
 * própria.
 */
vi.mock('@laravel/echo-vue', async () => {
    const { ref } = await import('vue');
    const fakeEcho = {
        private() {
            const channel = {};
            channel.subscribed = () => channel;
            channel.listen = () => channel;
            return channel;
        },
        leave: () => {},
    };
    return { echo: () => fakeEcho, echoIsConfigured: () => true, useConnectionStatus: () => ref('connected') };
});
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));

import AiCreditPackCheckout from '@/Components/Billing/AiCreditPackCheckout.vue';
import { resetBillingRealtime } from '@/composables/useBillingRealtime.js';

const t = {
    errors: { unknown: 'Erro.', ai_pack_not_discardable: 'Este pedido não pode mais ser descartado.' },
    methods: { pix: 'Pix', boleto: 'Boleto' },
    invoice_status: { pending: 'Em aberto' },
    ui: { loading: 'Carregando…', choose_method: 'Como você quer pagar?', amount: 'Valor', close: 'Fechar' },
    page: {
        ai_title: 'Créditos de IA',
        ai_credits: ':count créditos',
        ai_unit_price: ':price por crédito',
        ai_buy: 'Comprar',
        ai_modal_title: ':package — :credits créditos',
        ai_pending_title: 'Pedido de créditos aguardando pagamento',
        ai_pending_hint: 'Só vira créditos depois do pagamento.',
        ai_pending_continue: 'Continuar pagamento',
        ai_pending_value: ':credits créditos · :amount',
        ai_pack_open_created: 'Pedido de :date',
        ai_pack_discard: 'Descartar',
        ai_pack_discard_keep: 'Manter pedido',
        ai_pack_discard_confirm: 'Descartar este pedido de créditos?',
        ai_pack_discarded: 'Pedido de créditos descartado.',
    },
};

const payment = { gateway: 'mercadopago', methods: [{ method: 'pix', label: 'Pix', mode: 'transparent' }] };
const order = {
    id: 'inv-pending',
    reference: 'IA-20261005-ABC',
    amount: 249.9,
    currency: 'BRL',
    status: 'pending',
    created_at: '2026-10-05T10:00:00-03:00',
    can_pay: true,
    can_discard: true,
    kind: 'ai_credit_pack',
    ai_credit_pack: { credits: 100, package_code: 'operational', package_name: 'Operacional' },
    payment,
};
const packages = [
    { code: 'operational', name: 'Operacional', credits: 100, price_cents: 24990, price_formatted: 'R$ 249,90' },
];
const state = (openOrders) => ({
    data: {
        data: {
            allowed: true,
            packages,
            purchases: [],
            open_orders: openOrders,
            realtime: { channel: 'billing.e1', event: '.invoice.paid' },
        },
    },
});

const ModalStub = {
    props: ['open'],
    template: '<div v-if="open" data-test="modal"><slot name="header" /><slot /></div>',
};
const FlowStub = {
    name: 'CheckoutFlow',
    props: ['invoice', 'aiPack', 'payment', 'amount', 'realtime'],
    template: '<div data-test="flow-stub" />',
};

let wrapper;

beforeEach(() => {
    vi.clearAllMocks();
    resetBillingRealtime();
});

afterEach(() => wrapper?.unmount());

function mountPending(props = {}) {
    wrapper = mount(AiCreditPackCheckout, {
        props: { t, showPending: true, ...props },
        global: { stubs: { CenteredModal: ModalStub, CheckoutFlow: FlowStub } },
    });
}

describe('pedido de créditos pendente (tela de IA)', () => {
    it('mostra o pedido com valor, créditos e as duas ações', async () => {
        axios.get.mockResolvedValue(state([order]));
        mountPending();
        await flushPromises();

        const row = wrapper.get('[data-test="ai-pack-pending-order"]');
        expect(row.text().replace(/\u00a0/g, ' ')).toContain('100 créditos · R$ 249,90');
        expect(row.find('[data-test="ai-pack-pending-continue"]').exists()).toBe(true);
        expect(row.find('[data-test="ai-pack-pending-discard"]').exists()).toBe(true);
    });

    it('"Continuar pagamento" abre o checkout da fatura do pedido (não compra outro pacote)', async () => {
        axios.get.mockResolvedValue(state([order]));
        mountPending();
        await flushPromises();

        await wrapper.get('[data-test="ai-pack-pending-continue"]').trigger('click');
        await flushPromises();

        const flow = wrapper.getComponent(FlowStub);
        expect(flow.props('invoice').id).toBe('inv-pending');
        expect(flow.props('aiPack')).toBeNull();
        expect(flow.props('payment')).toEqual(payment);
        expect(flow.props('amount')).toBe(249.9);
        expect(wrapper.get('[data-test="modal"]').text()).toContain('Operacional — 100 créditos');
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('"Descartar" pede confirmação; confirmado, chama o DELETE e recarrega sem o pedido', async () => {
        axios.get.mockResolvedValueOnce(state([order])).mockResolvedValueOnce(state([]));
        axios.delete.mockResolvedValue({ data: { data: { invoice: { ...order, status: 'cancelled' } } } });
        mountPending();
        await flushPromises();

        await wrapper.get('[data-test="ai-pack-pending-discard"]').trigger('click');
        expect(wrapper.get('[data-test="ai-pack-pending-confirm"]').text()).toContain(
            'Descartar este pedido de créditos?',
        );
        expect(axios.delete).not.toHaveBeenCalled();

        // "Manter pedido" desfaz a pergunta sem chamar nada.
        await wrapper.get('[data-test="ai-pack-pending-keep"]').trigger('click');
        expect(wrapper.find('[data-test="ai-pack-pending-confirm"]').exists()).toBe(false);

        await wrapper.get('[data-test="ai-pack-pending-discard"]').trigger('click');
        await wrapper.get('[data-test="ai-pack-pending-discard-yes"]').trigger('click');
        await flushPromises();

        expect(axios.delete).toHaveBeenCalledWith(
            '/_routes/panel.my-subscription.ai-credits.discard?invoice=inv-pending',
            expect.anything(),
        );
        expect(wrapper.find('[data-test="ai-pack-pending"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="ai-pack-discarded"]').text()).toBe('Pedido de créditos descartado.');
    });

    it('pedido que não pode mais ser descartado: mostra o motivo', async () => {
        axios.get.mockResolvedValue(state([order]));
        axios.delete.mockRejectedValue({ response: { status: 409, data: { code: 'ai_pack_not_discardable' } } });
        mountPending();
        await flushPromises();

        await wrapper.get('[data-test="ai-pack-pending-discard"]').trigger('click');
        await wrapper.get('[data-test="ai-pack-pending-discard-yes"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="ai-pack-pending-error"]').text()).toBe(
            'Este pedido não pode mais ser descartado.',
        );
    });

    it('em Minha assinatura (sem showPending) os pedidos não se repetem aqui', async () => {
        axios.get.mockResolvedValue(state([order]));
        mountPending({ showPending: false });
        await flushPromises();

        expect(wrapper.find('[data-test="ai-pack-pending"]').exists()).toBe(false);
    });
});
