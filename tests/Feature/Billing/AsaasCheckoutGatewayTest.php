<?php

declare(strict_types=1);

use App\DTOs\Billing\{GatewayWebhookInputDTO, HostedCheckoutDTO};
use App\Enums\Billing\CheckoutMethod;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\Gateways\AsaasGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * AsaasGateway × Asaas Checkout (https://docs.asaas.com/reference/criar-novo-checkout)
 * e os campos do webhook que ligam cobrança/assinatura ao checkout
 * (checkoutSession, creditCard, refunds[]).
 */
beforeEach(function () {
    config([
        'billing.gateways.asaas.base_url'                   => 'https://api.asaas.com',
        'billing.gateways.asaas.secret'                     => '$aact_prod_chave_de_teste',
        'billing.gateways.asaas.hosted_checkout.enabled'    => true,
        'billing.gateways.asaas.hosted_checkout.item_image' => null,
    ]);
    Http::preventStrayRequests();
});

function acgDto(array $over = []): HostedCheckoutDTO
{
    return new HostedCheckoutDTO(...[
        'entityId'          => 'e1',
        'invoiceId'         => '9f1c2d3e-0000-4000-8000-0000000000ff',
        'subscriptionId'    => null,
        'customerId'        => 'cus_000005219613',
        'amount'            => 249.9,
        'itemName'          => 'Um nome de item bem comprido demais para o Asaas',
        'description'       => str_repeat('d', 200),
        'successUrl'        => 'https://app.easyeye.app/panel/my-subscription?checkout_return=success',
        'cancelUrl'         => 'https://app.easyeye.app/panel/my-subscription?checkout_return=cancel',
        'expiredUrl'        => 'https://app.easyeye.app/panel/my-subscription?checkout_return=expired',
        'externalReference' => 'easyeye:inv:9f1c2d3e-0000-4000-8000-0000000000ff',
        ...$over,
    ]);
}

it('DETACHED: só cartão, limites de tamanho da doc, sem subscription e com o cliente', function () {
    Http::fake(['https://api.asaas.com/v3/checkouts' => Http::response(['id' => 'chk_1', 'status' => 'ACTIVE', 'link' => 'https://asaas.com/checkoutSession/show?id=chk_1', 'minutesToExpire' => 60])]);

    $result = app(AsaasGateway::class)->createHostedCheckout(acgDto());

    expect($result->success)->toBeTrue()
        ->and($result->externalCheckoutId)->toBe('chk_1')
        ->and($result->url)->toBe('https://asaas.com/checkoutSession/show?id=chk_1');

    Http::assertSent(function (Request $r) {
        $item = $r['items'][0];

        return $r['billingTypes'] === ['CREDIT_CARD']
            && $r['chargeTypes'] === ['DETACHED']
            && ! array_key_exists('subscription', $r->data())
            && ! array_key_exists('installment', $r->data())
            && $r['customer'] === 'cus_000005219613'
            && mb_strlen($item['name']) === 30
            && mb_strlen($item['description']) === 150
            && ! array_key_exists('imageBase64', $item)
            && $r['callback']['expiredUrl'] === 'https://app.easyeye.app/panel/my-subscription?checkout_return=expired';
    });
});

it('minutesToExpire fica entre 10 e 1440 (doc)', function (int $asked, int $sent) {
    Http::fake(['https://api.asaas.com/v3/checkouts' => Http::response(['id' => 'chk_1'])]);

    app(AsaasGateway::class)->createHostedCheckout(acgDto(['minutesToExpire' => $asked]));

    Http::assertSent(fn (Request $r) => $r['minutesToExpire'] === $sent);
})->with([[5, 10], [5000, 1440], [90, 90]]);

it('sem "link" na resposta (ou link fora do Asaas): monta o formato documentado do ambiente', function (string $base, mixed $link, string $expected) {
    config(['billing.gateways.asaas.base_url' => $base]);
    Http::fake(["{$base}/v3/checkouts" => Http::response(array_filter(['id' => 'chk_9', 'link' => $link]))]);

    expect(app(AsaasGateway::class)->createHostedCheckout(acgDto())->url)->toBe($expected);
})->with([
    ['https://api.asaas.com', null, 'https://asaas.com/checkoutSession/show?id=chk_9'],
    ['https://api-sandbox.asaas.com', null, 'https://sandbox.asaas.com/checkoutSession/show?id=chk_9'],
    ['https://api.asaas.com', 'https://evil.example/pay', 'https://asaas.com/checkoutSession/show?id=chk_9'],
    ['https://api-sandbox.asaas.com', 'https://sandbox.asaas.com/checkoutSession/show/chk_9', 'https://sandbox.asaas.com/checkoutSession/show/chk_9'],
]);

it('imagem do item só com ASAAS_CHECKOUT_ITEM_IMAGE apontando um arquivo', function () {
    $file = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($file, 'PNGDATA');
    config(['billing.gateways.asaas.hosted_checkout.item_image' => $file]);
    Http::fake(['https://api.asaas.com/v3/checkouts' => Http::response(['id' => 'chk_1'])]);

    app(AsaasGateway::class)->createHostedCheckout(acgDto());

    Http::assertSent(fn (Request $r) => $r['items'][0]['imageBase64'] === base64_encode('PNGDATA'));
    unlink($file);
});

it('recusa da API: success false com o erro (sem lançar)', function () {
    Http::fake(['https://api.asaas.com/v3/checkouts' => Http::response(['errors' => [['code' => 'invalid_callback', 'description' => 'URL de callback inválida']]], 400)]);

    $result = app(AsaasGateway::class)->createHostedCheckout(acgDto());

    expect($result->success)->toBeFalse()
        ->and($result->httpStatus)->toBe(400)
        ->and($result->errorMessage)->toContain('invalid_callback');
});

it('ASAAS_HOSTED_CHECKOUT=false: o cartão volta ao link da fatura', function () {
    expect(CheckoutService::methodMode(app(AsaasGateway::class), CheckoutMethod::Card))->toBe('hosted');

    config(['billing.gateways.asaas.hosted_checkout.enabled' => false]);

    expect(app(AsaasGateway::class)->supportsHostedCardCheckout())->toBeFalse()
        ->and(CheckoutService::methodMode(app(AsaasGateway::class), CheckoutMethod::Card))->toBe('link')
        ->and(CheckoutService::methodMode(app(AsaasGateway::class), CheckoutMethod::Pix))->toBe('transparent');
});

it('webhook: checkoutSession, cartão (bandeira e 4 últimos, sem o token), estornos concluídos e a nossa referência do checkout', function () {
    $payload = [
        'id'          => 'evt_1',
        'event'       => 'PAYMENT_CONFIRMED',
        'dateCreated' => '2026-10-05 10:00:00',
        'payment'     => [
            'id'                => 'pay_1',
            'subscription'      => 'sub_new',
            'checkoutSession'   => 'chk_1',
            'value'             => 299.9,
            'status'            => 'CONFIRMED',
            'billingType'       => 'CREDIT_CARD',
            'externalReference' => 'easyeye:sub:9f1c2d3e-0000-4000-8000-0000000000aa:inv:9f1c2d3e-0000-4000-8000-0000000000ff',
            'creditCard'        => ['creditCardNumber' => '4242', 'creditCardBrand' => 'MASTERCARD', 'creditCardToken' => 'tok'],
            'refunds'           => [['status' => 'DONE', 'value' => 10], ['status' => 'PENDING', 'value' => 5], ['status' => 'CANCELLED', 'value' => 3]],
        ],
    ];

    $n = app(AsaasGateway::class)->parseWebhook(new GatewayWebhookInputDTO('asaas', [], json_encode($payload), $payload, 'evt_1'));

    // Cobrança de recorrência (payment.subscription) com a referência do
    // checkout: só a assinatura — as cobranças seguintes podem herdar a
    // referência, e a fatura que o checkout pagou não pode ser a delas (a 1ª
    // cobrança se liga à fatura pelo checkoutSession).
    expect($n->eventType)->toBe('paid')
        ->and($n->metadata)->toMatchArray([
            'subscription_id'  => '9f1c2d3e-0000-4000-8000-0000000000aa',
            'checkout_session' => 'chk_1',
            'card'             => ['brand' => 'mastercard', 'last4' => '4242'],
            'billing_type'     => 'CREDIT_CARD',
            'refunded_total'   => 10.0,
        ])
        ->and($n->metadata)->not->toHaveKey('invoice_id')
        ->and(json_encode($n->metadata))->not->toContain('tok');

    // Cobrança avulsa do checkout (DETACHED, sem recorrência): a fatura vem da referência.
    $payload['payment']['subscription'] = null;
    $detached                           = app(AsaasGateway::class)->parseWebhook(new GatewayWebhookInputDTO('asaas', [], json_encode($payload), $payload, 'evt_1'));

    expect($detached->metadata)->toMatchArray([
        'subscription_id' => '9f1c2d3e-0000-4000-8000-0000000000aa',
        'invoice_id'      => '9f1c2d3e-0000-4000-8000-0000000000ff',
    ]);
});

it('webhook CHECKOUT_*: o id do checkout vira checkout_session', function () {
    $payload = ['id' => 'evt_2', 'event' => 'CHECKOUT_PAID', 'checkout' => ['id' => 'chk_7', 'status' => 'PAID', 'customer' => 'cus_1']];

    $n = app(AsaasGateway::class)->parseWebhook(new GatewayWebhookInputDTO('asaas', [], json_encode($payload), $payload, 'evt_2'));

    expect($n->eventType)->toBe('checkout_paid')
        ->and($n->metadata['checkout_session'])->toBe('chk_7')
        ->and($n->metadata['checkout']['status'])->toBe('PAID');
});

it('cancelar cobrança já removida (404) conta como cancelada só se a consulta confirmar', function () {
    Http::fake([
        'https://api.asaas.com/v3/payments/pay_removida' => Http::sequence()
            ->push(['errors' => [['code' => 'not_found']]], 404)
            ->push(['id' => 'pay_removida', 'deleted' => true]),
        'https://api.asaas.com/v3/payments/pay_paga' => Http::response(['errors' => [['code' => 'invalid_action']]], 400),
    ]);

    expect(app(AsaasGateway::class)->cancelCharge('pay_removida'))->toBeTrue()
        ->and(app(AsaasGateway::class)->cancelCharge('pay_paga'))->toBeFalse();
});
