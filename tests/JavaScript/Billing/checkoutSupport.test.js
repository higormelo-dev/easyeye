import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    checkoutError,
    createCheckoutApi,
    newIdempotencyKey,
    panelEndpoints,
    signupEndpoints,
} from '@/Support/billing/checkoutApi.js';
import { isAllowedSdkUrl, loadScript, resetLoadedScripts } from '@/Support/billing/loadScript.js';
import { CardSdkError, createCardAdapter } from '@/Support/billing/cardGateways.js';

/**
 * Peças do checkout transparente sem rede: cliente da API (Idempotency-Key,
 * erros do contrato), carregador de SDK (só hosts oficiais) e os adaptadores
 * de cartão com os SDKs dos gateways simulados (nada sai do navegador além
 * do token).
 */
const t = {
    errors: {
        busy: 'Já existe um pagamento em andamento.',
        charge_pending: 'A cobrança ainda está sendo gerada.',
        card_declined_generic: 'Pagamento recusado pelo emissor.',
        rate_limited: 'Muitas tentativas.',
        session_expired: 'Sessão expirada.',
        network: 'Sem conexão.',
        unknown: 'Algo deu errado.',
    },
};

describe('checkoutApi', () => {
    it('monta as rotas do painel e do cadastro (sem troca de cartão no cadastro)', () => {
        const panel = panelEndpoints();
        expect(panel.instructions('inv-1', 'pix')).toBe(
            '/_routes/panel.my-subscription.instructions?invoice=inv-1&method=pix',
        );
        expect(panel.card('inv-1')).toBe('/_routes/panel.my-subscription.card?invoice=inv-1');
        expect(createCheckoutApi(panel).canReplaceCard).toBe(true);

        const signup = signupEndpoints();
        expect(signup.contract()).toBe('/_routes/signup-checkout.contract');
        expect(createCheckoutApi(signup).canReplaceCard).toBe(false);
    });

    it('POST do cartão leva só o token e a chave de idempotência no header', async () => {
        const http = { post: vi.fn().mockResolvedValue({ data: { data: { status: 'paid' } } }) };
        const api = createCheckoutApi(panelEndpoints(), http);

        const result = await api.payInvoice('inv-1', { card_token: 'tok_1', installments: 3 }, 'key-123');

        expect(result).toEqual({ status: 'paid' });
        const [url, body, config] = http.post.mock.calls[0];
        expect(url).toContain('panel.my-subscription.card');
        expect(body).toEqual({ card_token: 'tok_1', installments: 3 });
        expect(config.headers['Idempotency-Key']).toBe('key-123');
        expect(config.headers.Accept).toBe('application/json');
    });

    it('gera chaves de idempotência diferentes a cada pedido', () => {
        expect(newIdempotencyKey()).not.toBe(newIdempotencyKey());
    });

    it.each([
        [{ response: { status: 409, data: { code: 'busy', message: 'srv' } } }, 'busy', t.errors.busy, true],
        [
            { response: { status: 409, data: { code: 'charge_pending' } } },
            'charge_pending',
            t.errors.charge_pending,
            true,
        ],
        [
            {
                response: {
                    status: 422,
                    data: { code: 'card_declined', message: 'Pagamento recusado: saldo insuficiente' },
                },
            },
            'card_declined',
            'Pagamento recusado: saldo insuficiente',
            false,
        ],
        [{ response: { status: 429, data: {} } }, 'rate_limited', t.errors.rate_limited, true],
        [{ response: { status: 419, data: {} } }, 'session_expired', t.errors.session_expired, false],
        [{ message: 'Network Error' }, 'network', t.errors.network, true],
        [
            { response: { status: 422, data: { message: 'x', errors: { card_token: ['Token inválido.'] } } } },
            'validation',
            'Token inválido.',
            false,
        ],
        [
            { response: { status: 403, data: { code: 'forbidden', message: 'Só o admin.' } } },
            'forbidden',
            'Só o admin.',
            false,
        ],
    ])('erro do contrato → mensagem no idioma (%#)', (error, code, message, retryable) => {
        expect(checkoutError(error, t)).toMatchObject({ code, message, retryable });
    });
});

describe('loadScript', () => {
    beforeEach(() => resetLoadedScripts());
    afterEach(() => document.head.querySelectorAll('script[src]').forEach((s) => s.remove()));

    it('aceita só os SDKs oficiais por https', () => {
        expect(isAllowedSdkUrl('https://sdk.mercadopago.com/js/v2')).toBe(true);
        expect(isAllowedSdkUrl('https://js.stripe.com/basil/stripe.js')).toBe(true);
        expect(isAllowedSdkUrl('https://checkout.pagar.me/v1/tokenizecard.js')).toBe(true);
        expect(
            isAllowedSdkUrl('https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js'),
        ).toBe(true);
        expect(isAllowedSdkUrl('http://js.stripe.com/v3')).toBe(false);
        expect(isAllowedSdkUrl('https://evil.example.com/stripe.js')).toBe(false);
        expect(isAllowedSdkUrl('javascript:alert(1)')).toBe(false);
    });

    it('recusa script de host desconhecido sem tocar no DOM', async () => {
        await expect(loadScript('https://evil.example.com/x.js')).rejects.toThrow('sdk_not_allowed');
        expect(document.head.querySelector('script[src*="evil"]')).toBeNull();
    });

    it('insere o script uma vez, com os atributos pedidos', () => {
        const appendSpy = vi.spyOn(document.head, 'appendChild').mockImplementation((el) => {
            setTimeout(() => el.onload?.());
            return el;
        });

        const first = loadScript('https://checkout.pagar.me/v1/tokenizecard.js', {
            'data-pagarmecheckout-app-id': 'pk_test_1',
        });
        const second = loadScript('https://checkout.pagar.me/v1/tokenizecard.js', {
            'data-pagarmecheckout-app-id': 'pk_test_1',
        });

        expect(first).toBe(second);
        expect(appendSpy).toHaveBeenCalledTimes(1);
        expect(appendSpy.mock.calls[0][0].getAttribute('data-pagarmecheckout-app-id')).toBe('pk_test_1');
        appendSpy.mockRestore();

        return first;
    });
});

describe('cardGateways (SDKs simulados)', () => {
    afterEach(() => {
        delete window.MercadoPago;
        delete window.Stripe;
        delete window.PagarmeCheckout;
        delete window.PagSeguro;
    });

    it('sem chave pública ou gateway sem formulário no navegador → sem adaptador', () => {
        expect(
            createCardAdapter({ gateway: 'stripe_br', public_key: null, sdk_url: 'https://js.stripe.com/x' }),
        ).toBeNull();
        expect(createCardAdapter({ gateway: 'asaas', public_key: 'k', sdk_url: 'https://x' })).toBeNull();
    });

    it('PagBank: criptografa no navegador e devolve só o encryptedCard', async () => {
        const encryptCard = vi.fn(() => ({ encryptedCard: 'ENC==', hasErrors: false }));
        window.PagSeguro = { encryptCard };
        const adapter = createCardAdapter({
            gateway: 'pagbank',
            public_key: 'PUBKEY',
            sdk_url: 'https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js',
        });

        await adapter.mount();
        const token = await adapter.tokenize({
            holder: ' Maria Silva ',
            number: '4111 1111 1111 1111',
            exp_month: '3',
            exp_year: '2030',
            cvv: '123',
        });

        expect(token).toEqual({ card_token: 'ENC==' });
        expect(encryptCard).toHaveBeenCalledWith({
            publicKey: 'PUBKEY',
            holder: 'Maria Silva',
            number: '4111111111111111',
            expMonth: '03',
            expYear: '2030',
            securityCode: '123',
        });
    });

    it('PagBank: erro do SDK vira "confira os dados" só com os códigos', async () => {
        window.PagSeguro = { encryptCard: () => ({ hasErrors: true, errors: [{ code: 'INVALID_NUMBER' }] }) };
        const adapter = createCardAdapter({
            gateway: 'pagbank',
            public_key: 'K',
            sdk_url: 'https://assets.pagseguro.com.br/a.js',
        });
        await adapter.mount();

        await expect(adapter.tokenize({ number: '1' })).rejects.toMatchObject({
            code: 'invalid',
            detail: 'INVALID_NUMBER',
        });
    });

    it('Stripe: Payment Element + ConfirmationToken; 3DS pelo handleNextAction', async () => {
        const element = { mount: vi.fn(), on: vi.fn(), destroy: vi.fn() };
        const elements = { create: vi.fn(() => element), submit: vi.fn().mockResolvedValue({}) };
        const stripe = {
            elements: vi.fn(() => elements),
            createConfirmationToken: vi.fn().mockResolvedValue({ confirmationToken: { id: 'ctoken_123' } }),
            handleNextAction: vi.fn().mockResolvedValue({ paymentIntent: { status: 'succeeded' } }),
        };
        window.Stripe = vi.fn(() => stripe);
        const adapter = createCardAdapter({
            gateway: 'stripe_br',
            public_key: 'pk_test_x',
            sdk_url: 'https://js.stripe.com/basil/stripe.js',
            elements_options: {
                mode: 'payment',
                currency: 'brl',
                setupFutureUsage: 'off_session',
                paymentMethodTypes: ['card'],
            },
        });
        const target = document.createElement('div');

        await adapter.mount(target, { amount: 299.9, mode: 'payment', locale: 'pt-BR' });
        expect(window.Stripe).toHaveBeenCalledWith('pk_test_x', { locale: 'pt-BR' });
        expect(stripe.elements).toHaveBeenCalledWith(
            expect.objectContaining({
                mode: 'payment',
                amount: 29990,
                currency: 'brl',
                setupFutureUsage: 'off_session',
            }),
        );
        expect(element.mount).toHaveBeenCalledWith(target);

        await expect(adapter.tokenize()).resolves.toEqual({ card_token: 'ctoken_123' });
        await expect(adapter.handleNextAction({ client_secret: 'pi_secret' })).resolves.toEqual({
            ok: true,
            status: 'succeeded',
        });
        expect(stripe.handleNextAction).toHaveBeenCalledWith({ clientSecret: 'pi_secret' });
    });

    it('Stripe na troca de cartão: modo setup, sem valor', async () => {
        const elements = { create: vi.fn(() => ({ mount: vi.fn(), on: vi.fn() })) };
        const stripe = { elements: vi.fn(() => elements) };
        window.Stripe = vi.fn(() => stripe);
        const adapter = createCardAdapter({
            gateway: 'stripe_br',
            public_key: 'pk',
            sdk_url: 'https://js.stripe.com/basil/stripe.js',
        });

        await adapter.mount(document.createElement('div'), { amount: 10, mode: 'setup' });

        const options = stripe.elements.mock.calls[0][0];
        expect(options.mode).toBe('setup');
        expect(options).not.toHaveProperty('amount');
    });

    it('Mercado Pago: Card Payment Brick entrega token + payment_method_id + issuer_id', async () => {
        let settings;
        const create = vi.fn(async (_name, _id, s) => {
            settings = s;
            return { unmount: vi.fn() };
        });
        window.MercadoPago = vi.fn(function MercadoPago() {
            this.bricks = () => ({ create });
        });
        const adapter = createCardAdapter({
            gateway: 'mercadopago',
            public_key: 'APP_USR-1',
            sdk_url: 'https://sdk.mercadopago.com/js/v2',
        });
        const onSubmit = vi.fn().mockResolvedValue();

        await adapter.mount('brick-1', { amount: 1200, maxInstallments: 12, locale: 'pt-BR', onSubmit });

        expect(window.MercadoPago).toHaveBeenCalledWith('APP_USR-1', { locale: 'pt-BR' });
        expect(create).toHaveBeenCalledWith('cardPayment', 'brick-1', expect.any(Object));
        expect(settings.initialization.amount).toBe(1200);
        expect(settings.customization.paymentMethods.maxInstallments).toBe(12);

        await settings.callbacks.onSubmit({
            token: 'mp_tok',
            installments: 6,
            payment_method_id: 'visa',
            issuer_id: 25,
        });
        expect(onSubmit).toHaveBeenCalledWith({
            card_token: 'mp_tok',
            installments: 6,
            payment_method_id: 'visa',
            issuer_id: '25',
        });
    });

    it('Pagar.me: tokenizecard.js chama o success e o token segue (o formulário não é enviado)', async () => {
        let success;
        window.PagarmeCheckout = { init: vi.fn((ok) => (success = ok)) };
        const adapter = createCardAdapter({
            gateway: 'pagarme',
            public_key: 'pk_test',
            sdk_url: 'https://checkout.pagar.me/v1/tokenizecard.js',
        });

        await adapter.mount();
        const pending = adapter.tokenize();
        expect(success({ pagarmetoken: 'token_abc' })).toBe(false);

        await expect(pending).resolves.toEqual({ card_token: 'token_abc' });
    });

    it('erro de SDK é um CardSdkError com código', () => {
        expect(new CardSdkError('sdk_load_failed').code).toBe('sdk_load_failed');
    });
});
