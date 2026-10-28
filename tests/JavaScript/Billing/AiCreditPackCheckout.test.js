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
        ai_title: 'Saldo de IA',
        ai_choose_package: 'Escolha seu saldo de IA',
        ai_custom_label: 'Personalizado',
        ai_in_balance: 'em saldo de IA',
        ai_custom_amount: 'Valor personalizado em reais',
        ai_custom_hint: 'De :min a :max, em reais inteiros.',
        ai_custom_error: 'Informe um valor inteiro entre :min e :max.',
        ai_receives: 'Você recebe :amount em saldo',
        ai_balance_modal_title: ':package — :amount em saldo de IA',
        ai_balance_paid_notice: ':amount adicionados ao saldo de IA',
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
            instructions: vi.fn().mockResolvedValue({
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
            code: 'basic',
            name: 'Básico',
            credits: 987654,
            unit_price_formatted: 'USD markup custo do provedor',
            raw_cost_usd: 654321,
            value_cents: 10000,
            featured: false,
            custom: false,
        },
        { code: 'premium', name: 'Premium', value_cents: 49900, featured: true, custom: false },
        { code: 'enterprise', name: 'Enterprise', value_cents: 99900, featured: false, custom: false },
        {
            code: 'custom',
            name: 'Personalizado',
            value_cents: null,
            featured: false,
            custom: true,
            custom_min_cents: 100000,
            custom_max_cents: 1000000,
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

        await wrapper.get('[data-test="ai-pack-buy"]').trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenLastCalledWith(
            '/_routes/panel.my-subscription.ai-credits.options?package_code=premium',
            expect.anything(),
        );
        expect(wrapper.get('[data-test="modal"]').text()).toContain('Premium — R$\u00a0499,00 em saldo de IA');

        await wrapper.get('[data-method="pix"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/_routes/panel.my-subscription.ai-credits.purchase',
            { package_code: 'premium', method: 'pix' },
            expect.objectContaining({ headers: expect.objectContaining({ 'Idempotency-Key': expect.any(String) }) }),
        );

        echoState.channels['billing.ent-1'].handlers['.invoice.paid']({ invoice_id: 'inv-pack', status: 'paid' });
        await flushPromises();

        expect(wrapper.emitted('paid')?.[0]?.[0]).toEqual({ package_code: 'premium', wallet_credit_cents: 49900 });
        expect(wrapper.get('[data-test="ai-pack-paid"]').text()).toContain('R$\u00a0499,00 adicionados ao saldo de IA');
    });
    function renderPacks() {
        wrapper = mount(AiCreditPackCheckout, {
            props: { t, initial: { allowed: true, packages, purchases: [] } },
            global: { stubs: { CenteredModal: ModalStub } },
            attachTo: document.body,
        });
        return wrapper;
    }

    it('quatro rádios com legend, selo e resumo; não mostra preço unitário nem dados privados', () => {
        renderPacks();
        expect(wrapper.findAll('fieldset input[type="radio"]')).toHaveLength(4);
        expect(wrapper.get('legend').text()).toBe('Escolha seu saldo de IA');
        expect(wrapper.get('input[value="premium"]').element.checked).toBe(true);
        const radio = wrapper.get('input[value="premium"]');
        radio.element.focus();
        expect(document.activeElement).toBe(radio.element);
        expect(radio.element.closest('label').textContent).toContain('Premium');
        expect(document.getElementById(radio.attributes('aria-describedby'))).not.toBeNull();
        expect(wrapper.get('[data-test="ai-pack-summary"]').text()).toBe('Você recebe R$\u00a0499,00 em saldo');
        expect(wrapper.text()).toContain('Básico');
        expect(wrapper.text()).toContain('R$\u00a0100,00');
        expect(wrapper.text()).toContain('R$\u00a0999,00');
        expect(wrapper.text()).not.toMatch(
            /USD|markup|margin|exchange|por crédito|custo bruto|price_cents|raw_cost|987654|654321/,
        );
    });

    it.each(['', '999', '10001', '1000.5'])(
        'personalizado inválido %s não abre checkout e descreve o erro',
        async (value) => {
            renderPacks();
            await wrapper.get('input[value="custom"]').setValue();
            await wrapper.get('[data-test="ai-pack-custom"]').setValue(value);
            await wrapper.get('[data-test="ai-pack-buy"]').trigger('click');
            expect(axios.get).not.toHaveBeenCalled();
            const input = wrapper.get('[data-test="ai-pack-custom"]');
            expect(input.attributes('aria-invalid')).toBe('true');
            expect(input.attributes('aria-describedby')).toContain('custom-error');
            expect(wrapper.text()).toContain('Informe um valor inteiro');
            expect(wrapper.find('[data-test="modal"]').exists()).toBe(false);
        },
    );

    it.each([1000, 10000])('personalizado %s reais: GET e POST enviam centavos, resumo acompanha', async (amount) => {
        renderPacks();
        axios.get.mockResolvedValue({ data: { data: { allowed: true, payment, realtime } } });
        axios.post.mockResolvedValue({ data: { data: pixResponse } });
        await wrapper.get('input[value="custom"]').setValue();
        const input = wrapper.get('[data-test="ai-pack-custom"]');
        expect(input.attributes()).toMatchObject({ min: '1000', max: '10000', step: '1' });
        await input.setValue(String(amount));
        expect(wrapper.get('[data-test="ai-pack-summary"]').text()).toContain(
            amount === 1000 ? '1.000,00' : '10.000,00',
        );
        await wrapper.get('[data-test="ai-pack-buy"]').trigger('click');
        await flushPromises();
        expect(axios.get).toHaveBeenLastCalledWith(
            `/_routes/panel.my-subscription.ai-credits.options?package_code=custom&custom_amount_cents=${amount * 100}`,
            expect.anything(),
        );
        await wrapper.get('[data-method="pix"]').trigger('click');
        await flushPromises();
        expect(axios.post).toHaveBeenCalledWith(
            '/_routes/panel.my-subscription.ai-credits.purchase',
            { package_code: 'custom', custom_amount_cents: amount * 100, method: 'pix' },
            expect.anything(),
        );
    });

    it.each([true, false])(
        'erro 422 do pedido aparece no cartão e no modal sem fechar o pagamento (errors=%s)',
        async (withErrors) => {
            renderPacks();
            axios.get.mockResolvedValue({ data: { data: { allowed: true, payment } } });
            axios.post.mockRejectedValue({
                response: {
                    status: 422,
                    data: {
                        message: 'Valor indisponível.',
                        ...(withErrors ? { errors: { custom_amount_cents: ['Valor indisponível.'] } } : {}),
                    },
                },
            });
            await wrapper.get('[data-test="ai-pack-buy"]').trigger('click');
            await flushPromises();
            await wrapper.get('[data-method="pix"]').trigger('click');
            await flushPromises();
            expect(wrapper.get('[data-test="ai-pack-card-error"]').text()).toBe('Valor indisponível.');
            expect(wrapper.get('[data-test="ai-pack-modal-error"]').text()).toBe('Valor indisponível.');
        },
    );

    it('abrindo: desabilita cartões e impede pedidos duplicados', async () => {
        renderPacks();
        let resolve;
        axios.get.mockReturnValue(
            new Promise((r) => {
                resolve = r;
            }),
        );
        await wrapper.get('[data-test="ai-pack-buy"]').trigger('click');
        expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
        await wrapper.get('[data-test="ai-pack-buy"]').trigger('click');
        expect(axios.get).toHaveBeenCalledTimes(1);
        resolve({ data: { data: { allowed: true, payment } } });
        await flushPromises();
    });
    it('limites personalizados vêm do servidor e compras recentes exibem saldo, não contagem', async () => {
        wrapper = mount(AiCreditPackCheckout, {
            props: {
                t,
                initial: {
                    allowed: true,
                    packages: packages.map((pkg) =>
                        pkg.custom ? { ...pkg, custom_min_cents: 200000, custom_max_cents: 300000 } : pkg,
                    ),
                    purchases: [
                        {
                            id: 'p',
                            wallet_credit_cents: 49900,
                            amount_cents: 49900,
                            credits: 987654,
                            raw_cost_usd: 654321,
                            status_label: 'Confirmado',
                        },
                    ],
                },
            },
            global: { stubs: { CenteredModal: ModalStub } },
        });
        await wrapper.get('input[value="custom"]').setValue();
        expect(wrapper.get('[data-test="ai-pack-custom"]').attributes()).toMatchObject({ min: '2000', max: '3000' });
        await wrapper.get('[data-test="ai-pack-buy"]').trigger('click');
        expect(axios.get).not.toHaveBeenCalled();
        expect(wrapper.get('[data-test="ai-pack-purchase"]').text()).toContain('R$\u00a0499,00');
        expect(wrapper.get('[data-test="ai-pack-purchase"]').text()).not.toMatch(/créditos|USD|987654|654321/);
    });
});
