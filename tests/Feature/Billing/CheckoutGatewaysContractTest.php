<?php

declare(strict_types=1);

use App\DTOs\Billing\{CardChargeDTO, CreateChargeDTO, CustomerDTO};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Services\Billing\GatewayRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, Http};

/**
 * Checkout transparente — contrato de cada gateway com a documentação
 * oficial (payloads de exemplo da doc):
 *  - Mercado Pago: Pix/boleto (point_of_interaction, transaction_details),
 *    cartão com token + Automatic Payments (card_tokens, CREDENTIAL_ON_FILE);
 *  - Asaas: pixQrCode e identificationField; cartão sem transparente;
 *  - Pagar.me: carteira do cliente (customers/{id}/cards) + pedido com
 *    card_id, recurrence_cycle e payment_origin;
 *  - PagBank: cartão criptografado (store, recurring INITIAL/SUBSEQUENT),
 *    chave pública por POST /public-keys, Pix v2 e boleto;
 *  - Stripe: PaymentIntent Pix/boleto (next_action), cartão com
 *    ConfirmationToken, 3DS, recusa 402 e SetupIntent;
 *  - InfinitePay: só link.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Cache::flush();

    config([
        'billing.gateways.mercadopago.base_url'   => 'https://api.mercadopago.com',
        'billing.gateways.mercadopago.secret'     => 'APP_USR-secret',
        'billing.gateways.mercadopago.public_key' => 'APP_USR-public-key',
        'billing.gateways.asaas.base_url'         => 'https://api.asaas.com',
        'billing.gateways.asaas.secret'           => '$aact_test',
        'billing.gateways.pagarme.base_url'       => 'https://api.pagar.me/core/v5',
        'billing.gateways.pagarme.secret'         => 'sk_test_easyeye',
        'billing.gateways.pagarme.public_key'     => 'pk_test_easyeye',
        'billing.gateways.pagbank.base_url'       => 'https://sandbox.api.pagseguro.com',
        'billing.gateways.pagbank.secret'         => 'tok_api_pagbank',
        'billing.gateways.pagbank.public_key'     => null,
        'billing.gateways.stripe_br.base_url'     => 'https://api.stripe.com',
        'billing.gateways.stripe_br.secret'       => 'sk_test_easyeye',
        'billing.gateways.stripe_br.public_key'   => 'pk_test_easyeye',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function ckgGateway(string $code): mixed
{
    return app(GatewayRegistry::class)->get($code);
}

function ckgPayer(): CustomerDTO
{
    return new CustomerDTO(
        entityId: 'ent-1',
        name: 'Clínica Olhar Ltda',
        email: 'financeiro@olhar.test',
        document: '11222333000181',
        phone: '11987654321',
        externalReference: 'ent-1',
        address: ['zipcode' => '01310100', 'street' => 'Av. Paulista', 'number' => '1000', 'complement' => null, 'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP'],
    );
}

function ckgCard(array $overrides = []): CardChargeDTO
{
    return new CardChargeDTO(...[
        'entityId'       => 'ent-1',
        'invoiceId'      => '9d6b0c1e-0000-4000-8000-0000000000aa',
        'subscriptionId' => 'sub-1',
        'customerId'     => 'cus_1',
        'amount'         => 2878.99,
        'currency'       => 'BRL',
        'description'    => 'Assinatura Pro',
        'payer'          => ckgPayer(),
        'cardToken'      => 'tok_1',
        'installments'   => 12,
        'saveCard'       => true,
        'idempotencyKey' => 'charge-1-sub',
        ...$overrides,
    ]);
}

describe('capacidades', function () {
    it('Pix, boleto e cartão transparentes onde a doc permite; Asaas sem cartão; InfinitePay só link', function () {
        expect(ckgGateway('mercadopago')->transparentMethods())->toBe(['pix', 'boleto', 'credit_card'])
            ->and(ckgGateway('pagarme')->transparentMethods())->toBe(['pix', 'boleto', 'credit_card'])
            ->and(ckgGateway('pagbank')->transparentMethods())->toBe(['pix', 'boleto', 'credit_card'])
            ->and(ckgGateway('stripe_br')->transparentMethods())->toBe(['pix', 'boleto', 'credit_card'])
            ->and(ckgGateway('asaas')->transparentMethods())->toBe(['pix', 'boleto'])
            ->and(ckgGateway('asaas')->cardCheckoutConfig())->toBeNull()
            ->and(ckgGateway('infinitepay')->transparentMethods())->toBe([])
            ->and(ckgGateway('infinitepay')->chargeCard(ckgCard())->success)->toBeFalse();
    });

    it('SDK oficial e chave pública por gateway (nunca a secreta)', function () {
        expect(ckgGateway('mercadopago')->cardCheckoutConfig()->toArray())
            ->toMatchArray(['public_key' => 'APP_USR-public-key', 'sdk_url' => 'https://sdk.mercadopago.com/js/v2', 'tokenization' => 'card_token', 'max_installments' => 12])
            ->and(ckgGateway('pagarme')->cardCheckoutConfig()->toArray())
            ->toMatchArray(['public_key' => 'pk_test_easyeye', 'sdk_url' => 'https://checkout.pagar.me/v1/tokenizecard.js'])
            ->and(ckgGateway('stripe_br')->cardCheckoutConfig()->toArray())
            ->toMatchArray(['public_key' => 'pk_test_easyeye', 'sdk_url' => 'https://js.stripe.com/basil/stripe.js', 'tokenization' => 'confirmation_token', 'max_installments' => 1]);

        Http::assertNothingSent();
    });
});

describe('Mercado Pago', function () {
    it('Pix: copia-e-cola, QR em base64 e expiração do pagamento guardado (sem nova consulta)', function () {
        $payment = [
            'id'                   => 5466310457,
            'status'               => 'pending',
            'payment_method_id'    => 'pix',
            'date_of_expiration'   => '2026-10-08T23:59:59.000-03:00',
            'point_of_interaction' => ['type' => 'PIX', 'transaction_data' => [
                'qr_code'        => '00020126600014br.gov.bcb.pix0117test@mercadopago.com',
                'qr_code_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB',
                'ticket_url'     => 'https://www.mercadopago.com.br/payments/5466310457/ticket?caller_id=1',
            ]],
        ];

        $dto = ckgGateway('mercadopago')->paymentInstructions('pix', '5466310457', $payment);

        expect($dto->pix)->toMatchArray([
            'copy_paste'     => '00020126600014br.gov.bcb.pix0117test@mercadopago.com',
            'qr_code_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB',
        ])->and($dto->paymentUrl)->toBe('https://www.mercadopago.com.br/payments/5466310457/ticket?caller_id=1')
            ->and(ckgGateway('mercadopago')->paymentInstructions('boleto', '5466310457', $payment))->toBeNull();

        Http::assertNothingSent();
    });

    it('boleto: GET /v1/payments/{id}, linha digitável e PDF em transaction_details', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments/5466310458' => Http::response([
            'id'                  => 5466310458,
            'status'              => 'pending',
            'payment_method_id'   => 'bolbradesco',
            'date_of_expiration'  => '2026-10-10T23:59:59.000-03:00',
            'transaction_details' => [
                'external_resource_url' => 'https://www.mercadopago.com.br/payments/5466310458/ticket?caller_id=1&payment_method_id=bolbradesco',
                'digitable_line'        => '23793381286008200000000000000000197890000287899',
                'barcode'               => ['content' => '23791978900002878993381260082000000000000000'],
            ],
        ])]);

        $dto = ckgGateway('mercadopago')->paymentInstructions('boleto', '5466310458');

        expect($dto->boleto)->toMatchArray([
            'digitable_line' => '23793381286008200000000000000000197890000287899',
            'barcode'        => '23791978900002878993381260082000000000000000',
            'pdf_url'        => 'https://www.mercadopago.com.br/payments/5466310458/ticket?caller_id=1&payment_method_id=bolbradesco',
            'due_date'       => '2026-10-10',
        ]);
    });

    it('cartão (1ª cobrança): token, parcelas, CREDENTIAL_ON_FILE e cartão salvo no cliente', function () {
        Http::fake([
            'https://api.mercadopago.com/v1/payments' => Http::response([
                'id'                 => 20792195335,
                'status'             => 'approved',
                'status_detail'      => 'accredited',
                'transaction_amount' => 2878.99,
                'installments'       => 12,
                'payment_method_id'  => 'master',
                'card'               => ['id' => null, 'first_six_digits' => '503143', 'last_four_digits' => '6351', 'expiration_month' => 11, 'expiration_year' => 2030],
                'expanded'           => ['gateway' => ['reference' => ['network_transaction_id' => 'n7w-c0d3-t7d']]],
            ], 201),
            'https://api.mercadopago.com/v1/customers/cus_1/cards' => Http::response([
                'id'               => '1493990563105',
                'last_four_digits' => '6351',
                'payment_method'   => ['id' => 'master', 'payment_type_id' => 'credit_card'],
            ], 201),
        ]);

        $result = ckgGateway('mercadopago')->chargeCard(ckgCard(['paymentMethodId' => 'master', 'issuerId' => '24']));

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('paid')
            ->and($result->externalPaymentId)->toBe('20792195335')
            ->and($result->savedCard?->id)->toBe('1493990563105')
            ->and($result->savedCard?->last4)->toBe('6351')
            ->and(data_get($result->rawResponse, 'easyeye_card.network_transaction_id'))->toBe('n7w-c0d3-t7d')
            // Validade e BIN nunca ficam no que é guardado.
            ->and(data_get($result->rawResponse, 'card.expiration_year'))->toBe('***REDACTED***')
            ->and(data_get($result->rawResponse, 'card.first_six_digits'))->toBe('***REDACTED***');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mercadopago.com/v1/payments'
            && $r['token'] === 'tok_1'
            && $r['installments'] === 12
            && $r['payment_method_id'] === 'master'
            && $r['issuer_id'] === '24'
            && $r['payer']['type'] === 'customer'
            && $r['point_of_interaction']['type'] === 'CREDENTIAL_ON_FILE'
            && $r['point_of_interaction']['transaction_data']['first_transaction'] === true
            && $r->hasHeader('X-Idempotency-Key', 'charge-1-sub')
            && $r->hasHeader('X-Expand-Response-Nodes', 'gateway.reference'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/customers/cus_1/cards') && $r['token'] === 'tok_1');
    });

    it('renovação (offSession): token do card_id sem CVV e cobrança iniciada pelo lojista com a referência da 1ª', function () {
        Http::fake([
            'https://api.mercadopago.com/v1/card_tokens' => Http::response(['id' => 'tok_from_card'], 201),
            'https://api.mercadopago.com/v1/payments'    => Http::response(['id' => 20792199999, 'status' => 'approved', 'transaction_amount' => 299.9, 'payment_method_id' => 'master'], 201),
        ]);

        $result = ckgGateway('mercadopago')->chargeCard(ckgCard([
            'cardToken'    => null,
            'savedCardId'  => '1493990563105',
            'offSession'   => true,
            'saveCard'     => false,
            'installments' => 12,
            'metadata'     => ['card_origin_payment_id' => '20792195335', 'card_network_transaction_id' => 'n7w-c0d3-t7d', 'card_payment_method_id' => 'master', 'card_sequence' => 2],
        ]));

        expect($result->status)->toBe('paid');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/card_tokens') && $r['card_id'] === '1493990563105');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/payments')
            && $r['token'] === 'tok_from_card'
            && $r['installments'] === 1
            && $r['point_of_interaction']['transaction_data']['transaction_initiator'] === 'merchant'
            && $r['point_of_interaction']['transaction_data']['first_transaction'] === false
            && $r['point_of_interaction']['transaction_data']['reference'] === ['id' => '20792195335']
            && $r['point_of_interaction']['transaction_data']['network_transaction_id'] === 'n7w-c0d3-t7d'
            && $r['point_of_interaction']['transaction_data']['subscription_sequence']['number'] === 2);
    });

    it('recusa (rejected/cc_rejected_*) é status failed com o motivo em texto; nada é salvo', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(['id' => 1, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount', 'payment_method_id' => 'visa'], 201)]);

        $result = ckgGateway('mercadopago')->chargeCard(ckgCard(['paymentMethodId' => 'visa']));

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('failed')
            ->and($result->errorMessage)->toBe('Cartão sem limite suficiente.')
            ->and($result->savedCard)->toBeNull();

        Http::assertSentCount(1);
    });

    it('boleto emitido vai com first_name e last_name separados (exigência do bolbradesco)', function () {
        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(['id' => 2, 'status' => 'pending', 'payment_method_id' => 'bolbradesco'], 201)]);

        ckgGateway('mercadopago')->createCharge(new CreateChargeDTO(
            entityId: 'ent-1',
            invoiceId: '9d6b0c1e-0000-4000-8000-0000000000aa',
            subscriptionId: 'sub-1',
            customerId: 'cus_1',
            amount: 299.9,
            currency: 'BRL',
            description: 'Fatura',
            dueDate: '2026-10-10',
            paymentMethod: 'boleto',
            metadata: ['customer_name' => 'Clínica Olhar Ltda', 'email' => 'f@olhar.test', 'document' => '11222333000181'],
            idempotencyKey: 'k1',
        ));

        Http::assertSent(fn (Request $r) => $r['payer']['first_name'] === 'Clínica' && $r['payer']['last_name'] === 'Olhar Ltda');
    });
});

describe('Asaas', function () {
    it('Pix: GET /v3/payments/{id}/pixQrCode (encodedImage, payload, expirationDate)', function () {
        Http::fake(['https://api.asaas.com/v3/payments/pay_080225913252/pixQrCode' => Http::response([
            'encodedImage'   => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB',
            'payload'        => '00020101021226820014br.gov.bcb.pix2560qrpix-h.bradesco.com.br',
            'expirationDate' => '2026-10-08 23:59:59',
        ])]);

        $dto = ckgGateway('asaas')->paymentInstructions('pix', 'pay_080225913252', ['invoiceUrl' => 'https://www.asaas.com/i/080225913252']);

        expect($dto->pix['copy_paste'])->toBe('00020101021226820014br.gov.bcb.pix2560qrpix-h.bradesco.com.br')
            ->and($dto->pix['qr_code_base64'])->toBe('iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB')
            ->and($dto->pix['expires_at'])->toStartWith('2026-10-08T23:59:59')
            ->and($dto->paymentUrl)->toBe('https://www.asaas.com/i/080225913252');

        Http::assertSent(fn (Request $r) => $r->hasHeader('access_token'));
    });

    it('boleto: GET /v3/payments/{id}/identificationField e o PDF (bankSlipUrl)', function () {
        Http::fake(['https://api.asaas.com/v3/payments/pay_080225913252/identificationField' => Http::response([
            'identificationField' => '00190000090275928800021932978170187890000005000',
            'nossoNumero'         => '6543',
            'barCode'             => '00191878900000050000000002759288002193297817',
        ])]);

        $dto = ckgGateway('asaas')->paymentInstructions('boleto', 'pay_080225913252', ['bankSlipUrl' => 'https://www.asaas.com/b/pdf/080225913252', 'dueDate' => '2026-10-08']);

        expect($dto->boleto)->toMatchArray([
            'digitable_line' => '00190000090275928800021932978170187890000005000',
            'barcode'        => '00191878900000050000000002759288002193297817',
            'pdf_url'        => 'https://www.asaas.com/b/pdf/080225913252',
            'due_date'       => '2026-10-08',
        ]);
    });

    it('400 (forma indisponível na cobrança) devolve null; 5xx lança', function () {
        Http::fake([
            'https://api.asaas.com/v3/payments/pay_1/pixQrCode' => Http::response(['errors' => [['code' => 'invalid_action', 'description' => 'Pix indisponível']]], 400),
            'https://api.asaas.com/v3/payments/pay_2/pixQrCode' => Http::response('erro', 503),
        ]);

        expect(ckgGateway('asaas')->paymentInstructions('pix', 'pay_1'))->toBeNull();

        ckgGateway('asaas')->paymentInstructions('pix', 'pay_2');
    })->throws(GatewayIntegrationException::class);
});

describe('Pagar.me', function () {
    it('cartão: token → carteira do cliente (verify_card) → pedido com card_id, parcelas e recurrence_cycle', function () {
        Http::fake([
            'https://api.pagar.me/core/v5/customers/cus_1/cards' => Http::response(['id' => 'card_xGz1', 'last_four_digits' => '0010', 'brand' => 'Visa', 'status' => 'active', 'exp_month' => 1, 'exp_year' => 30]),
            'https://api.pagar.me/core/v5/orders'                => Http::response([
                'id'      => 'or_56GXnk6T0eU88qMm',
                'status'  => 'paid',
                'amount'  => 287899,
                'charges' => [[
                    'id'               => 'ch_d22356Jf4WuGr8no',
                    'status'           => 'paid',
                    'amount'           => 287899,
                    'payment_method'   => 'credit_card',
                    'last_transaction' => ['status' => 'captured', 'installments' => 1, 'card' => ['id' => 'card_xGz1', 'last_four_digits' => '0010', 'brand' => 'Visa', 'exp_month' => 1, 'exp_year' => 30]],
                ]],
            ]),
        ]);

        $result = ckgGateway('pagarme')->chargeCard(ckgCard(['cardToken' => 'token_zVw7rqvHEPH8XlbE', 'installments' => 1]));

        expect($result->status)->toBe('paid')
            ->and($result->externalPaymentId)->toBe('ch_d22356Jf4WuGr8no')
            ->and($result->savedCard?->id)->toBe('card_xGz1')
            ->and($result->savedCard?->brand)->toBe('visa')
            ->and($result->savedCard?->last4)->toBe('0010');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/customers/cus_1/cards')
            && $r['token'] === 'token_zVw7rqvHEPH8XlbE'
            && $r['options'] === ['verify_card' => true]
            && $r['billing_address']['zip_code'] === '01310100');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/orders')
            && $r['payments'][0]['credit_card']['card_id'] === 'card_xGz1'
            && $r['payments'][0]['credit_card']['installments'] === 1
            && $r['payments'][0]['credit_card']['recurrence_cycle'] === 'first'
            && ! isset($r['payments'][0]['credit_card']['card_token'])
            && $r->hasHeader('Idempotency-Key', 'charge-1-sub'));
    });

    it('parcelado (12x) vai sem identificador de recorrência', function () {
        Http::fake([
            'https://api.pagar.me/core/v5/customers/cus_1/cards' => Http::response(['id' => 'card_xGz1', 'last_four_digits' => '0010', 'brand' => 'Visa']),
            'https://api.pagar.me/core/v5/orders'                => Http::response(['id' => 'or_1', 'status' => 'paid', 'charges' => [['id' => 'ch_1', 'status' => 'paid', 'amount' => 287899]]]),
        ]);

        ckgGateway('pagarme')->chargeCard(ckgCard(['cardToken' => 'token_abc', 'installments' => 12]));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/orders')
            && $r['payments'][0]['credit_card']['installments'] === 12
            && ! isset($r['payments'][0]['credit_card']['recurrence_cycle']));
    });

    it('renovação: card_id, 1 parcela, recurrence_cycle subsequent e payment_origin.charge_id da 1ª', function () {
        Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_2', 'status' => 'paid', 'charges' => [['id' => 'ch_2', 'status' => 'paid', 'amount' => 29990]]])]);

        $result = ckgGateway('pagarme')->chargeCard(ckgCard([
            'cardToken'   => null,
            'savedCardId' => 'card_xGz1',
            'offSession'  => true,
            'saveCard'    => false,
            'metadata'    => ['card_origin_payment_id' => 'ch_d22356Jf4WuGr8no'],
        ]));

        expect($result->status)->toBe('paid');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['payments'][0]['credit_card']['recurrence_cycle'] === 'subsequent'
            && $r['payments'][0]['credit_card']['payment_origin'] === ['charge_id' => 'ch_d22356Jf4WuGr8no']
            && $r['payments'][0]['credit_card']['installments'] === 1
            && ! isset($r['metadata']['card_origin_payment_id']));
    });

    it('recusa (not_authorized) é failed com a mensagem do adquirente', function () {
        Http::fake([
            'https://api.pagar.me/core/v5/customers/cus_1/cards' => Http::response(['id' => 'card_x', 'last_four_digits' => '0002', 'brand' => 'Visa']),
            'https://api.pagar.me/core/v5/orders'                => Http::response([
                'id'      => 'or_3',
                'status'  => 'failed',
                'charges' => [['id' => 'ch_3', 'status' => 'failed', 'last_transaction' => ['status' => 'not_authorized', 'acquirer_message' => 'Transação não autorizada']]],
            ]),
        ]);

        $result = ckgGateway('pagarme')->chargeCard(ckgCard(['cardToken' => 'token_abc', 'installments' => 1]));

        expect($result->status)->toBe('failed')->and($result->errorMessage)->toBe('Transação não autorizada');
    });

    it('instruções: Pix (qr_code/qr_code_url) e boleto (line/pdf) da cobrança do pedido', function () {
        $pix    = ['charges' => [['id' => 'ch_p', 'payment_method' => 'pix', 'last_transaction' => ['qr_code' => '00020101pix', 'qr_code_url' => 'https://api.pagar.me/core/v5/transactions/tran_1/qrcode?payment_method=pix', 'expires_at' => '2026-10-05T23:59:59Z']]]];
        $boleto = ['charges' => [['id' => 'ch_b', 'payment_method' => 'boleto', 'last_transaction' => ['line' => '34191.09065 44830.136706 00000.000000 1 00000000029990', 'pdf' => 'https://api.pagar.me/core/v5/transactions/tran_2/pdf', 'url' => 'https://api.pagar.me/core/v5/transactions/tran_2', 'due_at' => '2026-10-10T23:59:59Z']]]];

        expect(ckgGateway('pagarme')->paymentInstructions('pix', 'ch_p', $pix)->pix['copy_paste'])->toBe('00020101pix')
            ->and(ckgGateway('pagarme')->paymentInstructions('boleto', 'ch_b', $boleto)->boleto)->toMatchArray([
                'digitable_line' => '34191.09065 44830.136706 00000.000000 1 00000000029990',
                'pdf_url'        => 'https://api.pagar.me/core/v5/transactions/tran_2/pdf',
                'due_date'       => '2026-10-10',
            ])
            ->and(ckgGateway('pagarme')->paymentInstructions('boleto', 'ch_p', $pix))->toBeNull();

        Http::assertNothingSent();
    });

    it('troca de cartão: só guarda na carteira; token fora do formato é recusado sem chamada', function () {
        Http::fake(['https://api.pagar.me/core/v5/customers/cus_1/cards' => Http::response(['id' => 'card_new', 'last_four_digits' => '4242', 'brand' => 'Mastercard'])]);

        expect(ckgGateway('pagarme')->saveCard('cus_1', 'token_new', ckgPayer())->card?->id)->toBe('card_new')
            ->and(ckgGateway('pagarme')->saveCard('cus_1', '4111111111111111', ckgPayer())->success)->toBeFalse();

        Http::assertSentCount(1);
    });
});

describe('PagBank', function () {
    it('chave pública: POST /public-keys {"type":"card"} uma vez (cache)', function () {
        Http::fake(['https://sandbox.api.pagseguro.com/public-keys' => Http::response(['public_key' => 'MIIBIjANBgkqh', 'created_at' => 1580237849044])]);

        expect(ckgGateway('pagbank')->cardCheckoutConfig()->publicKey)->toBe('MIIBIjANBgkqh')
            ->and(ckgGateway('pagbank')->cardCheckoutConfig()->toArray())->toMatchArray([
                'sdk_url'      => 'https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js',
                'tokenization' => 'encrypted_card',
            ]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['type'] === 'card' && $r->hasHeader('Authorization', 'Bearer tok_api_pagbank'));
    });

    it('cartão criptografado: CREDIT_CARD, parcelas, store e recurring INITIAL; card.id volta salvo', function () {
        Http::fake(['https://sandbox.api.pagseguro.com/orders' => Http::response([
            'id'      => 'ORDE_A1B2',
            'charges' => [[
                'id'               => 'CHAR_1',
                'status'           => 'PAID',
                'amount'           => ['value' => 287899, 'currency' => 'BRL', 'summary' => ['total' => 287899, 'paid' => 287899, 'refunded' => 0]],
                'payment_response' => ['code' => '20000', 'message' => 'SUCESSO'],
                'payment_method'   => ['type' => 'CREDIT_CARD', 'installments' => 12, 'card' => ['id' => 'CARD_CCFE8D12', 'brand' => 'visa', 'first_digits' => '411111', 'last_digits' => '1111', 'exp_month' => '12', 'exp_year' => '2030', 'store' => true]],
            ]],
        ], 201)]);

        $result = ckgGateway('pagbank')->chargeCard(ckgCard(['cardToken' => 'ENCRYPTED==', 'installments' => 12]));

        expect($result->status)->toBe('paid')
            ->and($result->externalPaymentId)->toBe('ORDE_A1B2')
            ->and($result->savedCard?->id)->toBe('CARD_CCFE8D12')
            ->and($result->savedCard?->last4)->toBe('1111')
            ->and(data_get($result->rawResponse, 'charges.0.payment_method.card.exp_year'))->toBe('***REDACTED***');

        Http::assertSent(fn (Request $r) => $r['charges'][0]['payment_method']['type'] === 'CREDIT_CARD'
            && $r['charges'][0]['payment_method']['installments'] === 12
            && $r['charges'][0]['payment_method']['card'] === ['encrypted' => 'ENCRYPTED==', 'store' => true]
            && $r['charges'][0]['recurring'] === ['type' => 'INITIAL']
            && $r['customer']['tax_id'] === '11222333000181');
    });

    it('renovação: card.id sem CVV e recurring SUBSEQUENT; recusa DECLINED é failed com a mensagem', function () {
        Http::fake(['https://sandbox.api.pagseguro.com/orders' => Http::response([
            'id'      => 'ORDE_C3',
            'charges' => [['id' => 'CHAR_2', 'status' => 'DECLINED', 'amount' => ['value' => 29990], 'payment_response' => ['code' => '10002', 'message' => 'NAO AUTORIZADO PELO EMISSOR']]],
        ], 201)]);

        $result = ckgGateway('pagbank')->chargeCard(ckgCard(['cardToken' => null, 'savedCardId' => 'CARD_CCFE8D12', 'offSession' => true, 'saveCard' => false]));

        expect($result->status)->toBe('failed')->and($result->errorMessage)->toBe('NAO AUTORIZADO PELO EMISSOR');

        Http::assertSent(fn (Request $r) => $r['charges'][0]['payment_method']['card']['id'] === 'CARD_CCFE8D12'
            && ! isset($r['charges'][0]['payment_method']['card']['encrypted'])
            && $r['charges'][0]['payment_method']['installments'] === 1
            && $r['charges'][0]['recurring'] === ['type' => 'SUBSEQUENT']);
    });

    it('instruções: Pix v2 (charges[].qr_code.text + QRCODE.PNG) e boleto (formatted_barcode + PDF)', function () {
        $pix = ['id' => 'ORDE_P', 'charges' => [[
            'id'             => 'CHAR_P',
            'status'         => 'WAITING',
            'payment_method' => ['type' => 'PIX', 'pix' => ['expiration_date' => '2026-10-05T23:59:59-03:00']],
            'qr_code'        => ['id' => 'QRCO_1', 'text' => '00020101021226850014br.gov.bcb.pix'],
            'links'          => [['rel' => 'QRCODE.PNG', 'href' => 'https://sandbox.api.pagseguro.com/qrcode/QRCO_1/png', 'media' => 'image/png']],
        ]]];
        $boleto = ['id' => 'ORDE_B', 'charges' => [[
            'id'             => 'CHAR_B',
            'status'         => 'WAITING',
            'payment_method' => ['type' => 'BOLETO', 'boleto' => ['id' => 'BOLE_1', 'barcode' => '03399853012970000024227020901016278150000015630', 'formatted_barcode' => '03399.85301 29700.000242 27020.901016 2 78150000015630', 'due_date' => '2026-10-10']],
            'links'          => [['rel' => 'SELF', 'href' => 'https://boleto.sandbox.pagseguro.com.br/abc.pdf', 'media' => 'application/pdf', 'type' => 'GET']],
        ]]];

        expect(ckgGateway('pagbank')->paymentInstructions('pix', 'ORDE_P', $pix)->pix)->toMatchArray([
            'copy_paste'   => '00020101021226850014br.gov.bcb.pix',
            'qr_image_url' => 'https://sandbox.api.pagseguro.com/qrcode/QRCO_1/png',
        ])->and(ckgGateway('pagbank')->paymentInstructions('boleto', 'ORDE_B', $boleto)->boleto)->toMatchArray([
            'digitable_line' => '03399.85301 29700.000242 27020.901016 2 78150000015630',
            'pdf_url'        => 'https://boleto.sandbox.pagseguro.com.br/abc.pdf',
            'due_date'       => '2026-10-10',
        ]);

        Http::assertNothingSent();
    });

    it('sem troca de cartão avulsa (o cartão é salvo no próximo pagamento)', function () {
        expect(ckgGateway('pagbank')->saveCard('cus', 'ENC', ckgPayer())->success)->toBeFalse();

        Http::assertNothingSent();
    });
});

describe('Stripe', function () {
    it('Pix: PaymentIntent confirmado no servidor; next_action.pix_display_qr_code vira as instruções', function () {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response([
            'id'          => 'pi_3PixTest',
            'object'      => 'payment_intent',
            'status'      => 'requires_action',
            'amount'      => 29990,
            'next_action' => ['type' => 'pix_display_qr_code', 'pix_display_qr_code' => [
                'data'                    => '00020101021226880014br.gov.bcb.pix',
                'image_url_png'           => 'https://qr.stripe.com/test.png',
                'expires_at'              => 1791255599,
                'hosted_instructions_url' => 'https://payments.stripe.com/pix/instructions/test',
            ]],
            'client_secret' => 'pi_3PixTest_secret_abc',
        ])]);

        $charge = ckgGateway('stripe_br')->createCharge(new CreateChargeDTO(
            entityId: 'ent-1',
            invoiceId: '9d6b0c1e-0000-4000-8000-0000000000aa',
            subscriptionId: 'sub-1',
            customerId: 'cus_Stripe1',
            amount: 299.90,
            currency: 'BRL',
            description: 'Fatura INV-1',
            dueDate: '2026-10-05',
            paymentMethod: 'pix',
            metadata: ['customer_name' => 'Clínica Olhar', 'email' => 'f@olhar.test'],
            idempotencyKey: 'checkout:pix:inv:1',
        ));

        $dto = ckgGateway('stripe_br')->paymentInstructions('pix', (string) $charge->externalPaymentId, $charge->rawResponse);

        expect($charge->success)->toBeTrue()
            ->and($charge->externalPaymentId)->toBe('pi_3PixTest')
            ->and($charge->status)->toBe('pending')
            ->and($charge->rawResponse['client_secret'])->toBe('***REDACTED***')
            ->and($dto->pix['copy_paste'])->toBe('00020101021226880014br.gov.bcb.pix')
            ->and($dto->pix['qr_image_url'])->toBe('https://qr.stripe.com/test.png')
            ->and($dto->paymentUrl)->toBe('https://payments.stripe.com/pix/instructions/test');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['payment_method_types'] === ['pix']
            && $r['payment_method_data']['type'] === 'pix'
            && $r['confirm'] === 'true'
            && $r['metadata']['invoice_id'] === '9d6b0c1e-0000-4000-8000-0000000000aa'
            && $r['metadata']['easyeye_charge'] === 'payment_intent'
            && (int) $r['payment_method_options']['pix']['expires_after_seconds'] >= 3600
            && $r->hasHeader('Idempotency-Key'));
    });

    it('cartão: ConfirmationToken, setup_future_usage off_session e o cartão (pm_) guardado', function () {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response([
            'id'             => 'pi_card_ok',
            'status'         => 'succeeded',
            'amount'         => 287899,
            'payment_method' => ['id' => 'pm_1Card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]],
        ])]);

        $result = ckgGateway('stripe_br')->chargeCard(ckgCard(['cardToken' => 'ctoken_1Abc', 'customerId' => 'cus_Stripe1', 'installments' => 1]));

        expect($result->status)->toBe('paid')
            ->and($result->savedCard?->id)->toBe('pm_1Card')
            ->and($result->savedCard?->last4)->toBe('4242');

        Http::assertSent(fn (Request $r) => $r['confirmation_token'] === 'ctoken_1Abc'
            && $r['setup_future_usage'] === 'off_session'
            && $r['customer'] === 'cus_Stripe1'
            && $r['payment_method_types'] === ['card']);
    });

    it('3DS: requires_action devolve o client_secret para o Stripe.js (sem gravá-lo)', function () {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_3ds', 'status' => 'requires_action', 'amount' => 29990, 'client_secret' => 'pi_3ds_secret_xyz'])]);

        $result = ckgGateway('stripe_br')->chargeCard(ckgCard(['cardToken' => 'pm_1Card', 'customerId' => 'cus_Stripe1', 'installments' => 1]));

        expect($result->status)->toBe('pending')
            ->and($result->nextAction)->toBe(['type' => 'stripe_handle_next_action', 'client_secret' => 'pi_3ds_secret_xyz'])
            ->and($result->rawResponse['client_secret'])->toBe('***REDACTED***');
    });

    it('recusa: HTTP 402 card_error vira failed com a mensagem e o PaymentIntent', function () {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response(['error' => [
            'type'           => 'card_error',
            'code'           => 'card_declined',
            'decline_code'   => 'insufficient_funds',
            'message'        => 'Your card has insufficient funds.',
            'payment_intent' => ['id' => 'pi_declined', 'amount' => 29990, 'status' => 'requires_payment_method'],
        ]], 402)]);

        $result = ckgGateway('stripe_br')->chargeCard(ckgCard(['cardToken' => 'ctoken_x', 'customerId' => 'cus_Stripe1', 'installments' => 1]));

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('failed')
            ->and($result->externalPaymentId)->toBe('pi_declined')
            ->and($result->errorMessage)->toBe(__('checkout.decline.insufficient_funds'));
    });

    it('renovação off_session com o pm_ salvo', function () {
        Http::fake(['https://api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_ren', 'status' => 'succeeded', 'amount' => 29990, 'payment_method' => 'pm_1Card'])]);

        ckgGateway('stripe_br')->chargeCard(ckgCard(['cardToken' => null, 'savedCardId' => 'pm_1Card', 'offSession' => true, 'saveCard' => false]));

        Http::assertSent(fn (Request $r) => $r['payment_method'] === 'pm_1Card' && $r['off_session'] === 'true' && ! isset($r['setup_future_usage']));
    });

    it('troca de cartão: SetupIntent; 3DS devolve a referência seti_ e a 2ª chamada conclui', function () {
        Http::fake([
            'https://api.stripe.com/v1/setup_intents'         => Http::response(['id' => 'seti_1', 'status' => 'requires_action', 'client_secret' => 'seti_1_secret', 'customer' => 'cus_Stripe1']),
            'https://api.stripe.com/v1/setup_intents/seti_1*' => Http::response(['id' => 'seti_1', 'status' => 'succeeded', 'customer' => 'cus_Stripe1', 'payment_method' => ['id' => 'pm_new', 'card' => ['brand' => 'mastercard', 'last4' => '4444']]]),
        ]);

        $first  = ckgGateway('stripe_br')->saveCard('cus_Stripe1', 'ctoken_new', ckgPayer());
        $second = ckgGateway('stripe_br')->saveCard('cus_Stripe1', 'seti_1', ckgPayer());

        expect($first->success)->toBeFalse()
            ->and($first->nextAction)->toMatchArray(['client_secret' => 'seti_1_secret', 'reference' => 'seti_1'])
            ->and($second->success)->toBeTrue()
            ->and($second->card?->id)->toBe('pm_new')
            ->and($second->card?->brand)->toBe('mastercard');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['usage'] === 'off_session' && $r['confirmation_token'] === 'ctoken_new');
    });
});
