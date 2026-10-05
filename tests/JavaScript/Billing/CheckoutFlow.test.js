import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

/**
 * Checkout transparente (componente reutilizável): Pix, boleto, cartão
 * (SDK do gateway simulado — só o token sai), link externo, cobrança ainda
 * sendo gerada (novas tentativas), erros do contrato e a confirmação em
 * tempo real (`.invoice.paid` no canal billing.{entityId}) — sem rede.
 */
const echoState = vi.hoisted(() => ({ channels: {}, left: [], status: null }));
vi.mock('@laravel/echo-vue', async () => {
    const { ref } = await import('vue');
    echoState.status = ref('connected');
    const fakeEcho = {
        private(name) {
            const channel = { name, handlers: {}, onSubscribed: null };
            channel.subscribed = (cb) => ((channel.onSubscribed = cb), channel);
            channel.listen = (event, cb) => ((channel.handlers[event] = cb), channel);
            echoState.channels[name] = channel;
            return channel;
        },
        leave: (name) => echoState.left.push(name),
    };
    return { echo: () => fakeEcho, echoIsConfigured: () => true, useConnectionStatus: () => echoState.status };
});

import CheckoutFlow from '@/Components/Billing/CheckoutFlow.vue';
import { resetBillingRealtime } from '@/composables/useBillingRealtime.js';

const t = {
    errors: {
        gateway_error: 'Não foi possível falar com o meio de pagamento agora.',
        charge_pending: 'A cobrança ainda está sendo gerada.',
        card_declined_generic: 'Pagamento recusado pelo emissor do cartão.',
        unknown: 'Algo deu errado.',
    },
    methods: { pix: 'Pix', boleto: 'Boleto', credit_card: 'Cartão de crédito' },
    gateways: {
        mercadopago: 'Mercado Pago',
        pagbank: 'PagBank',
        infinitepay: 'InfinitePay',
        stripe_br: 'Stripe',
        default: 'o gateway',
    },
    ui: {
        choose_method: 'Como você quer pagar?',
        method_hint_pix: 'Na hora',
        method_hint_boleto: 'Até 3 dias',
        method_hint_card: 'Renovação automática.',
        method_hint_card_split: 'Em até :count× sem juros.',
        method_external: 'Concluído no site de :gateway',
        amount: 'Valor',
        invoice_ref: 'Fatura :reference',
        loading: 'Preparando…',
        pix_title: 'Pague com Pix',
        pix_qr_alt: 'QR Code do Pix para pagar :amount',
        pix_copy_label: 'Pix copia e cola',
        pix_expires_at: 'Este código vale até :date.',
        pix_waiting: 'Aguardando a confirmação.',
        boleto_title: 'Pague com boleto',
        boleto_line: 'Linha digitável',
        boleto_due: 'Vencimento: :date',
        boleto_download: 'Baixar o boleto (PDF)',
        copy: 'Copiar',
        copied: 'Copiado!',
        opens_new_tab: '(abre em nova aba)',
        card_title: 'Pague com cartão de crédito',
        card_holder: 'Nome impresso no cartão',
        card_number: 'Número do cartão',
        card_exp_month: 'Mês',
        card_exp_year: 'Ano',
        card_cvv: 'CVV',
        card_installments: 'Parcelas',
        installment_one: 'À vista — :amount',
        installment_many: ':count× de :amount sem juros',
        card_secure_note: 'Os dados vão direto para :gateway.',
        card_saved_note: 'Fica guardado em :gateway.',
        card_pay: 'Pagar :amount',
        card_invalid: 'Confira os dados do cartão.',
        card_3ds: 'Confirme no seu banco.',
        processing: 'Processando o pagamento…',
        processing_hint: 'Assim que :gateway confirmar, a tela atualiza.',
        paid_title: 'Pagamento confirmado!',
        paid_body: 'Obrigado!',
        continue: 'Continuar',
        link_title: 'Pagar no site de :gateway',
        link_body: 'Concluído no site de :gateway, em nova aba.',
        link_button: 'Abrir a página de pagamento',
        retry: 'Tentar de novo',
        retrying: 'Nova tentativa em :seconds s…',
        realtime_off: 'Sem atualização automática.',
        check_status: 'Já paguei — atualizar',
        still_pending: 'Ainda não confirmado.',
        back_to_methods: 'Escolher outra forma',
    },
};

const invoice = { id: 'inv-1', reference: 'FAT-001', amount: 299.9, due_date: '2026-10-13', can_pay: true };
const realtime = { channel: 'billing.ent-1', event: '.invoice.paid' };

function payment(overrides = {}) {
    return {
        gateway: 'pagbank',
        methods: [
            { method: 'pix', label: 'Pix', mode: 'transparent' },
            { method: 'boleto', label: 'Boleto', mode: 'transparent' },
            { method: 'credit_card', label: 'Cartão de crédito', mode: 'transparent' },
        ],
        ...overrides,
    };
}

function fakeApi(overrides = {}) {
    return {
        summary: vi.fn().mockResolvedValue({ invoices: [] }),
        options: vi.fn(),
        instructions: vi.fn(),
        payInvoice: vi.fn(),
        replaceCard: vi.fn(),
        contract: vi.fn(),
        ...overrides,
    };
}

// Intl usa espaço não separável entre R$ e o valor.
const norm = (text) => String(text).replace(/\u00a0/g, ' ');

let wrapper;

function render(props) {
    wrapper = mount(CheckoutFlow, {
        props: { t, payment: payment(), invoice, amount: invoice.amount, realtime, ...props },
        attachTo: document.body,
    });

    return wrapper;
}

const pick = async (method) => {
    await wrapper.get(`[data-method="${method}"]`).trigger('click');
    await flushPromises();
};

const emitPaid = async (payload) => {
    echoState.channels['billing.ent-1'].handlers['.invoice.paid'](payload);
    await flushPromises();
};

beforeEach(() => {
    echoState.channels = {};
    echoState.left = [];
    echoState.status.value = 'connected';
    resetBillingRealtime();
});

afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    delete window.PagSeguro;
    delete window.Stripe;
});

describe('CheckoutFlow — escolha e Pix', () => {
    it('lista as formas com dicas e o valor da fatura no idioma', () => {
        const api = fakeApi();
        render({ api, payment: payment({ card: { installments: [{ count: 1 }, { count: 12 }] } }) });

        expect(norm(wrapper.get('[data-test="checkout-amount"]').text())).toBe('R$ 299,90');
        expect(wrapper.text()).toContain('Fatura FAT-001');
        expect(wrapper.get('[data-method="credit_card"]').text()).toContain('Em até 12× sem juros.');
        expect(wrapper.find('[data-test="method-external"]').exists()).toBe(false);
    });

    it('Pix: QR em base64, copia-e-cola e validade; libera sozinho com o evento da fatura', async () => {
        const api = fakeApi({
            instructions: vi.fn().mockResolvedValue({
                invoice,
                mode: 'transparent',
                method: 'pix',
                instructions: {
                    pix: {
                        copy_paste: '00020126PIXCODE',
                        qr_code_base64: 'iVBORw0KGgo=',
                        qr_image_url: null,
                        expires_at: '2099-10-13T15:00:00-03:00',
                    },
                },
            }),
        });
        render({ api });

        await pick('pix');

        expect(api.instructions).toHaveBeenCalledWith('inv-1', 'pix');
        const qr = wrapper.get('[data-test="pix-qr"]');
        expect(qr.attributes('src')).toBe('data:image/png;base64,iVBORw0KGgo=');
        expect(norm(qr.attributes('alt'))).toBe('QR Code do Pix para pagar R$ 299,90');
        expect(wrapper.get('textarea').element.value).toBe('00020126PIXCODE');
        expect(wrapper.get('[data-test="pix-expires"]').text()).toContain('13/10/2099');
        expect(document.activeElement?.tagName).toBe('H3');
        expect(wrapper.get('[role="status"]').text()).toBe('Aguardando a confirmação.');

        // Evento de outra fatura não libera esta.
        await emitPaid({ invoice_id: 'other', status: 'paid' });
        expect(wrapper.attributes('data-step')).toBe('pix');

        await emitPaid({ invoice_id: 'inv-1', status: 'paid' });
        expect(wrapper.attributes('data-step')).toBe('paid');
        expect(wrapper.get('[data-test="checkout-paid"]').text()).toContain('Obrigado!');
        expect(wrapper.emitted('paid')[0][0]).toEqual({ invoice_id: 'inv-1' });
    });

    it('copiar o código Pix anuncia "Copiado!"', async () => {
        const writeText = vi.fn().mockResolvedValue();
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        const api = fakeApi({
            instructions: vi.fn().mockResolvedValue({
                mode: 'transparent',
                instructions: { pix: { copy_paste: 'PIXCODE', qr_code_base64: 'AAA=' } },
            }),
        });
        render({ api });
        await pick('pix');

        await wrapper.get('[data-test="copy-button"]').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('PIXCODE');
        expect(wrapper.get('[aria-live="polite"].small').text()).toBe('Copiado!');
    });

    it('sem tempo real: "Já paguei — atualizar" relê o resumo sob demanda', async () => {
        echoState.status.value = 'disconnected';
        const api = fakeApi({
            instructions: vi.fn().mockResolvedValue({
                mode: 'transparent',
                instructions: { pix: { copy_paste: 'PIX', qr_code_base64: 'AAA=' } },
            }),
            summary: vi
                .fn()
                .mockResolvedValueOnce({ invoices: [{ id: 'inv-1', status: 'pending' }] })
                .mockResolvedValueOnce({ invoices: [{ id: 'inv-1', status: 'paid' }] }),
        });
        render({ api });
        await pick('pix');

        expect(wrapper.find('[data-test="checkout-realtime-off"]').exists()).toBe(true);
        await wrapper.get('[data-test="checkout-check"]').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Ainda não confirmado.');

        await wrapper.get('[data-test="checkout-check"]').trigger('click');
        await flushPromises();
        expect(wrapper.attributes('data-step')).toBe('paid');
    });
});

describe('CheckoutFlow — boleto, link e erros', () => {
    it('boleto: linha digitável copiável, PDF em nova aba e vencimento', async () => {
        const api = fakeApi({
            instructions: vi.fn().mockResolvedValue({
                mode: 'transparent',
                instructions: {
                    boleto: {
                        digitable_line: '23793.38128 60000.000003 00000.000400 1 84340000029990',
                        pdf_url: 'https://boleto.example.com/b.pdf',
                        due_date: '2026-10-20',
                    },
                },
            }),
        });
        render({ api });
        await pick('boleto');

        expect(wrapper.get('textarea').element.value).toContain('23793.38128');
        const pdf = wrapper.get('[data-test="boleto-pdf"]');
        expect(pdf.attributes('href')).toBe('https://boleto.example.com/b.pdf');
        expect(pdf.attributes('target')).toBe('_blank');
        expect(pdf.attributes('rel')).toContain('noopener');
        expect(wrapper.get('[data-test="boleto-due"]').text()).toBe('Vencimento: 20/10/2026');
    });

    it('gateway só com link (InfinitePay): avisa que abre o site do gateway', async () => {
        const api = fakeApi({
            instructions: vi
                .fn()
                .mockResolvedValue({ mode: 'link', method: 'pix', payment_url: 'https://pay.infinitepay.io/x' }),
        });
        render({
            api,
            payment: payment({ gateway: 'infinitepay', methods: [{ method: 'pix', label: 'Pix', mode: 'link' }] }),
        });

        expect(wrapper.get('[data-test="method-external"]').text()).toContain('Concluído no site de InfinitePay');
        await pick('pix');

        const link = wrapper.get('[data-test="checkout-external-link"]');
        expect(link.attributes('href')).toBe('https://pay.infinitepay.io/x');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.text()).toContain('(abre em nova aba)');
        expect(wrapper.text()).toContain('Concluído no site de InfinitePay, em nova aba.');
    });

    it('link externo inseguro (não https) não vira botão', async () => {
        const api = fakeApi({
            instructions: vi.fn().mockResolvedValue({ mode: 'link', payment_url: 'javascript:alert(1)' }),
        });
        render({ api });
        await pick('pix');

        expect(wrapper.find('[data-test="checkout-external-link"]').exists()).toBe(false);
    });

    it('erro do gateway: mensagem do contrato e "Tentar de novo"', async () => {
        const api = fakeApi({
            instructions: vi
                .fn()
                .mockRejectedValueOnce({ response: { status: 502, data: { code: 'gateway_error', message: 'x' } } })
                .mockResolvedValueOnce({ mode: 'transparent', instructions: { pix: { copy_paste: 'PIX' } } }),
        });
        render({ api });
        await pick('pix');

        const alert = wrapper.get('[data-test="checkout-error"]');
        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toContain('Não foi possível falar com o meio de pagamento agora.');

        await wrapper.get('[data-test="checkout-retry"]').trigger('click');
        await flushPromises();
        expect(wrapper.attributes('data-step')).toBe('pix');
    });

    it('contratar com Pix e a cobrança ainda sendo gerada (charge_pending): tenta de novo sozinho', async () => {
        vi.useFakeTimers();
        const newInvoice = { id: 'inv-9', reference: 'FAT-009', amount: 2990 };
        const api = fakeApi({
            contract: vi.fn().mockResolvedValue({
                subscription: { id: 's1' },
                invoice: newInvoice,
                mode: 'transparent',
                method: 'pix',
                status: 'pending',
                instructions: null,
                retry: 'charge_pending',
            }),
            instructions: vi.fn().mockResolvedValue({
                invoice: newInvoice,
                mode: 'transparent',
                instructions: { pix: { copy_paste: 'PIX-NOVO', qr_code_base64: 'AAA=' } },
            }),
        });
        render({ api, invoice: null, contract: { plan_id: 'p1', billing_cycle: 'yearly' }, amount: 2990 });

        await pick('pix');
        expect(api.contract).toHaveBeenCalledWith(
            { plan_id: 'p1', billing_cycle: 'yearly', method: 'pix' },
            expect.any(String),
        );
        expect(wrapper.emitted('invoice')[0][0]).toEqual(newInvoice);
        expect(wrapper.get('[data-test="checkout-loading"]').text()).toContain('Nova tentativa em 3 s');

        await vi.advanceTimersByTimeAsync(3000);
        await flushPromises();

        expect(api.instructions).toHaveBeenCalledWith('inv-9', 'pix');
        expect(wrapper.attributes('data-step')).toBe('pix');
        expect(wrapper.get('textarea').element.value).toBe('PIX-NOVO');
    });
});

describe('CheckoutFlow — cartão', () => {
    const pagbankCard = {
        gateway: 'pagbank',
        public_key: 'PUBKEY',
        sdk_url: 'https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js',
        tokenization: 'encrypted_card',
        saves_card: true,
        installments: [
            { count: 1, amount: 2990, total: 2990, interest_free: true },
            { count: 12, amount: 249.17, total: 2990, interest_free: true },
        ],
    };

    async function fillCard() {
        const inputs = wrapper.findAll('[data-test="card-form"] input');
        await inputs[0].setValue('Maria Silva');
        await inputs[1].setValue('4111111111111111');
        await inputs[2].setValue('12');
        await inputs[3].setValue('2030');
        await inputs[4].setValue('123');
    }

    it('PagBank: cartão criptografado no navegador, 12× sem juros e Idempotency-Key; aprovado libera na hora', async () => {
        const encryptCard = vi.fn(() => ({ encryptedCard: 'ENC==', hasErrors: false }));
        window.PagSeguro = { encryptCard };
        const api = fakeApi({
            instructions: vi
                .fn()
                .mockResolvedValue({ invoice, mode: 'transparent', method: 'credit_card', card: pagbankCard }),
            payInvoice: vi.fn().mockResolvedValue({ status: 'paid', card: { brand: 'visa', last4: '1111' } }),
        });
        render({ api });
        await pick('credit_card');
        await flushPromises();

        const form = wrapper.get('[data-test="card-form"]');
        // Campos sem `name`: nunca entram num envio de formulário.
        form.findAll('input').forEach((input) => expect(input.attributes('name')).toBeUndefined());
        expect(wrapper.text()).toContain('Os dados vão direto para PagBank.');
        expect(wrapper.findAll('[data-test="card-installments"] option').map((o) => norm(o.text()))).toEqual([
            'À vista — R$ 2.990,00',
            '12× de R$ 249,17 sem juros',
        ]);

        await fillCard();
        const select = wrapper.get('[data-test="card-installments"]');
        select.element.options[1].selected = true;
        await select.trigger('change');
        await form.trigger('submit');
        await flushPromises();

        const [invoiceId, payload, key] = api.payInvoice.mock.calls[0];
        expect(invoiceId).toBe('inv-1');
        expect(payload).toEqual({ card_token: 'ENC==', installments: 12 });
        expect(JSON.stringify(payload)).not.toContain('4111');
        expect(typeof key).toBe('string');
        expect(key.length).toBeGreaterThan(8);
        expect(wrapper.attributes('data-step')).toBe('paid');
    });

    it('recusa: mostra o motivo do servidor e mantém o formulário (dados limpos)', async () => {
        window.PagSeguro = { encryptCard: () => ({ encryptedCard: 'ENC==', hasErrors: false }) };
        const api = fakeApi({
            instructions: vi.fn().mockResolvedValue({ mode: 'transparent', card: pagbankCard }),
            payInvoice: vi.fn().mockRejectedValue({
                response: {
                    status: 422,
                    data: { code: 'card_declined', message: 'Pagamento recusado: saldo insuficiente' },
                },
            }),
        });
        render({ api });
        await pick('credit_card');
        await flushPromises();
        await fillCard();
        await wrapper.get('[data-test="card-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-test="checkout-error"]').text()).toContain('Pagamento recusado: saldo insuficiente');
        expect(wrapper.attributes('data-step')).toBe('card');
        expect(wrapper.findAll('[data-test="card-form"] input')[1].element.value).toBe('');
        expect(wrapper.find('[data-test="checkout-retry"]').exists()).toBe(false);
    });

    it('Stripe com 3DS: handleNextAction e aguarda a confirmação do webhook', async () => {
        const stripe = {
            elements: vi.fn(() => ({
                create: vi.fn(() => ({ mount: vi.fn(), on: vi.fn(), destroy: vi.fn() })),
                submit: vi.fn().mockResolvedValue({}),
            })),
            createConfirmationToken: vi.fn().mockResolvedValue({ confirmationToken: { id: 'ctoken_1' } }),
            handleNextAction: vi.fn().mockResolvedValue({ paymentIntent: { status: 'processing' } }),
        };
        window.Stripe = vi.fn(() => stripe);
        const stripeCard = {
            gateway: 'stripe_br',
            public_key: 'pk_test_1',
            sdk_url: 'https://js.stripe.com/basil/stripe.js',
            installments: [{ count: 1, amount: 299.9, total: 299.9 }],
            saves_card: true,
        };
        const api = fakeApi({
            instructions: vi.fn().mockResolvedValue({ mode: 'transparent', card: stripeCard }),
            payInvoice: vi.fn().mockResolvedValue({
                status: 'requires_action',
                next_action: { type: 'stripe_handle_next_action', client_secret: 'pi_1_secret' },
            }),
        });
        render({ api, payment: payment({ gateway: 'stripe_br' }) });
        await pick('credit_card');
        await flushPromises();

        expect(wrapper.find('[data-test="card-installments"]').exists()).toBe(false);
        await wrapper.get('[data-test="card-form"]').trigger('submit');
        await flushPromises();

        expect(api.payInvoice.mock.calls[0][1]).toEqual({ card_token: 'ctoken_1', installments: 1 });
        expect(stripe.handleNextAction).toHaveBeenCalledWith({ clientSecret: 'pi_1_secret' });
        expect(wrapper.attributes('data-step')).toBe('processing');
        expect(wrapper.text()).toContain('Assim que Stripe confirmar');

        await emitPaid({ invoice_id: 'inv-1', status: 'paid' });
        expect(wrapper.attributes('data-step')).toBe('paid');
    });

    it('sem chave pública: avisa que o cartão está indisponível', async () => {
        const api = fakeApi({
            instructions: vi
                .fn()
                .mockResolvedValue({ mode: 'transparent', card: { ...pagbankCard, public_key: null } }),
        });
        render({ api });
        await pick('credit_card');
        await flushPromises();

        expect(wrapper.find('[data-test="card-form"]').exists()).toBe(false);
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    });
});
