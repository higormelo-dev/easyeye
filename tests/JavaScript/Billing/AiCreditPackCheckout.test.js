import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';

/**
 * Pacote de créditos de IA pago no checkout do sistema: o CheckoutFlow no
 * modo `aiPack` (1º pedido compra o pacote; depois paga a fatura aberta) e a
 * lista de pacotes (AiCreditPackCheckout) — bloqueada com o motivo do
 * servidor quando a clínica não pode comprar.
 */
const echoState = vi.hoisted(() => ({ channels: {} }));
vi.mock('@laravel/echo-vue', async () => {
    const { ref } = await import('vue');
    const fakeEcho = {
        private(name) {
            const channel = { handlers: {} };
            channel.subscribed = () => channel;
            channel.listen = (event, cb) => ((channel.handlers[event] = cb), channel);
            echoState.channels[name] = channel;
            return channel;
        },
        leave: () => {},
    };
    return { echo: () => fakeEcho, echoIsConfigured: () => true, useConnectionStatus: () => ref('connected') };
});
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));

import CheckoutFlow from '@/Components/Billing/CheckoutFlow.vue';
import AiCreditPackCheckout from '@/Components/Billing/AiCreditPackCheckout.vue';
import { resetBillingRealtime } from '@/composables/useBillingRealtime.js';

const t = {
    errors: { unknown: 'Erro.' },
    methods: { pix: 'Pix', boleto: 'Boleto', credit_card: 'Cartão de crédito' },
    gateways: { mercadopago: 'Mercado Pago', default: 'o gateway' },
    invoice_status: { pending: 'Em aberto', paid: 'Paga' },
    ui: {
        choose_method: 'Como você quer pagar?',
        method_hint_pix: 'Na hora',
        method_hint_boleto: 'Até 3 dias',
        method_hint_card: 'Cartão.',
        amount: 'Valor',
        loading: 'Preparando…',
        pix_title: 'Pague com Pix',
        pix_qr_alt: 'QR :amount',
        pix_copy_label: 'Pix copia e cola',
        pix_waiting: 'Aguardando a confirmação.',
        boleto_title: 'Pague com boleto',
        boleto_line: 'Linha digitável',
        copy: 'Copiar',
        paid_title: 'Pagamento confirmado!',
        paid_body: 'Obrigado!',
        continue: 'Continuar',
        back_to_methods: 'Escolher outra forma',
        check_status: 'Já paguei',
        close: 'Fechar',
    },
    page: {
        ai_title: 'Créditos de IA',
        ai_intro: 'Pacotes avulsos.',
        ai_credits: ':count créditos',
        ai_unit_price: ':price por crédito',
        ai_featured: 'Mais escolhido',
        ai_buy: 'Comprar',
        ai_modal_title: ':package — :credits créditos',
        ai_card_in_full: 'À vista.',
        ai_paid_notice: 'Pagamento confirmado — :credits créditos de IA adicionados.',
        ai_recent: 'Compras recentes',
        ai_unavailable: 'Indisponível.',
    },
};

const realtime = { channel: 'billing.ent-1', event: '.invoice.paid' };
const payment = {
    gateway: 'mercadopago',
    methods: [
        { method: 'pix', label: 'Pix', mode: 'transparent' },
        { method: 'boleto', label: 'Boleto', mode: 'transparent' },
    ],
};
const packInvoice = { id: 'inv-pack', reference: 'IA-1', amount: 249.9, kind: 'ai_credit_pack' };
const pixResponse = {
    invoice: packInvoice,
    mode: 'transparent',
    method: 'pix',
    instructions: { pix: { copy_paste: '00020101PACK', qr_code_base64: 'iVBOR=', expires_at: '2099-01-01T00:00:00Z' } },
};

const ModalStub = {
    props: ['open'],
    template: '<div v-if="open" data-test="modal"><slot name="header" /><slot /></div>',
};

let wrapper;

beforeEach(() => {
    vi.clearAllMocks();
    echoState.channels = {};
    resetBillingRealtime();
});

afterEach(() => wrapper?.unmount());

describe('CheckoutFlow — modo aiPack', () => {
    it('1º pedido compra o pacote (POST ai-credits); a forma seguinte paga a fatura aberta', async () => {
        const api = {
            summary: vi.fn(),
            aiPack: vi.fn().mockResolvedValue(pixResponse),
            contract: vi.fn(),
            instructions: vi
                .fn()
                .mockResolvedValue({
                    invoice: packInvoice,
                    mode: 'transparent',
                    method: 'boleto',
                    instructions: { boleto: { digitable_line: '2379' } },
                }),
            issueCharge: vi.fn(),
            payInvoice: vi.fn(),
        };
        wrapper = mount(CheckoutFlow, {
            props: { t, api, payment, aiPack: { package_code: 'operational' }, amount: 249.9, realtime },
            attachTo: document.body,
        });

        await wrapper.get('[data-method="pix"]').trigger('click');
        await flushPromises();

        expect(api.aiPack).toHaveBeenCalledWith({ package_code: 'operational', method: 'pix' }, expect.any(String));
        expect(api.contract).not.toHaveBeenCalled();
        expect(wrapper.get('textarea').element.value).toBe('00020101PACK');

        await wrapper.get('[data-test="checkout-back"]').trigger('click');
        await wrapper.get('[data-method="boleto"]').trigger('click');
        await flushPromises();

        expect(api.aiPack).toHaveBeenCalledTimes(1);
        expect(api.instructions).toHaveBeenCalledWith('inv-pack', 'boleto');
    });
});

describe('AiCreditPackCheckout', () => {
    const packages = [
        {
            code: 'starter',
            name: 'Inicial',
            credits: 25,
            price_cents: 6990,
            price_formatted: 'R$ 69,90',
            unit_price_formatted: 'R$ 2,80',
            featured: false,
        },
        {
            code: 'operational',
            name: 'Operacional',
            credits: 100,
            price_cents: 24990,
            price_formatted: 'R$ 249,90',
            unit_price_formatted: 'R$ 2,50',
            featured: true,
        },
    ];

    it('sem acesso total: mostra o motivo do servidor e não deixa comprar', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: {
                    allowed: false,
                    reason: 'ai_pack_requires_full_access',
                    message: 'Regularize a assinatura.',
                    packages,
                    purchases: [],
                },
            },
        });

        wrapper = mount(AiCreditPackCheckout, { props: { t }, global: { stubs: { CenteredModal: ModalStub } } });
        await flushPromises();

        expect(wrapper.get('[data-test="ai-pack-blocked"]').text()).toContain('Regularize a assinatura.');
        expect(wrapper.findAll('[data-test="ai-pack-buy"]').every((b) => b.attributes('disabled') !== undefined)).toBe(
            true,
        );
    });

    it('Comprar: busca as formas com o valor do pacote, abre o checkout e avisa quando pago', async () => {
        axios.get
            .mockResolvedValueOnce({ data: { data: { allowed: true, packages, purchases: [] } } })
            .mockResolvedValueOnce({ data: { data: { allowed: true, packages, payment, realtime } } });
        axios.post.mockResolvedValue({ data: { data: pixResponse } });

        wrapper = mount(AiCreditPackCheckout, {
            props: { t },
            global: { stubs: { CenteredModal: ModalStub } },
            attachTo: document.body,
        });
        await flushPromises();

        await wrapper.get('[data-code="operational"] [data-test="ai-pack-buy"]').trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenLastCalledWith(
            '/_routes/panel.my-subscription.ai-credits.options?package_code=operational',
            expect.anything(),
        );
        expect(wrapper.get('[data-test="modal"]').text()).toContain('Operacional — 100 créditos');

        await wrapper.get('[data-method="pix"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/_routes/panel.my-subscription.ai-credits.purchase',
            { package_code: 'operational', method: 'pix' },
            expect.objectContaining({ headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }) }),
        );

        echoState.channels['billing.ent-1'].handlers['.invoice.paid']({ invoice_id: 'inv-pack', status: 'paid' });
        await flushPromises();

        expect(wrapper.emitted('paid')?.[0]?.[0]).toEqual({ package_code: 'operational', credits: 100 });
        expect(wrapper.get('[data-test="ai-pack-paid"]').text()).toContain('100 créditos de IA');
    });
});
