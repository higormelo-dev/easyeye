<?php

use App\DTOs\Billing\CreateChargeDTO;
use App\Enums\Billing\{CancellationReason, InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\Billing\{BillingLog, Invoice, Payment};
use App\Models\{Entity, Plan, Subscription};
use App\Services\Billing\{BillingCancellationService, GatewayRegistry, SubscriptionCycleService};
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\Http;

/**
 * Cancelamento de cobrança em aberto (PaymentGatewayInterface::cancelCharge)
 * conforme a documentação de cada gateway, e o uso dele quando a assinatura
 * é cancelada ou substituída (renovação local: a cobrança avulsa do próximo
 * ciclo não pode ficar pagável).
 *  - Asaas: DELETE /v3/payments/{id} → {"deleted": true}
 *  - Mercado Pago: PUT /v1/payments/{id} {"status": "cancelled"}
 *  - Stripe: POST /v1/invoices/{id}/void → status "void"
 *  - Pagar.me: DELETE /charges/{id} → status "canceled"
 *  - PagBank e InfinitePay: sem cancelamento de cobrança pendente na API.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();

    config([
        'billing.gateways.asaas.secret'       => '$aact_prod_teste',
        'billing.gateways.mercadopago.secret' => 'APP_USR-teste',
        'billing.gateways.stripe_br.secret'   => 'sk_test_123',
        'billing.gateways.pagarme.secret'     => 'sk_test_easyeye',
        'billing.gateways.pagbank.secret'     => 'tok_pagbank',
        'billing.gateways.infinitepay.handle' => 'easyeye',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function gccGateway(string $code)
{
    return app(GatewayRegistry::class)->get($code);
}

it('Asaas: DELETE /v3/payments/{id}; deleted=true cancela, erro não', function () {
    Http::fake([
        'https://api.asaas.com/v3/payments/pay_080225913252' => Http::response(['deleted' => true, 'id' => 'pay_080225913252']),
        'https://api.asaas.com/v3/payments/pay_pago'         => Http::response(['errors' => [['code' => 'invalid_action', 'description' => 'Cobrança recebida não pode ser removida.']]], 400),
    ]);

    expect(gccGateway('asaas')->cancelCharge('pay_080225913252'))->toBeTrue()
        ->and(gccGateway('asaas')->cancelCharge('pay_pago'))->toBeFalse();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.asaas.com/v3/payments/pay_080225913252');
});

it('Mercado Pago: PUT /v1/payments/{id} com status cancelled; id não numérico não chama a API', function () {
    Http::fake(['https://api.mercadopago.com/v1/payments/5466310457' => Http::response(['id' => 5466310457, 'status' => 'cancelled'])]);

    expect(gccGateway('mercadopago')->cancelCharge('5466310457'))->toBeTrue()
        ->and(gccGateway('mercadopago')->cancelCharge('mp_invalido'))->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() === ['status' => 'cancelled']);
});

it('Stripe: POST /v1/invoices/{id}/void (fatura in_) e /v1/payment_intents/{id}/cancel (Pix do checkout pi_); outro id não', function () {
    Http::fake([
        'https://api.stripe.com/v1/invoices/in_1MtHbELkdIwHu7ixl4OzzPMv/void'          => Http::response(['id' => 'in_1MtHbELkdIwHu7ixl4OzzPMv', 'object' => 'invoice', 'status' => 'void']),
        'https://api.stripe.com/v1/payment_intents/pi_3MtwBwLkdIwHu7ix28a3tqPa/cancel' => Http::response(['id' => 'pi_3MtwBwLkdIwHu7ix28a3tqPa', 'object' => 'payment_intent', 'status' => 'canceled']),
    ]);

    expect(gccGateway('stripe_br')->cancelCharge('in_1MtHbELkdIwHu7ixl4OzzPMv'))->toBeTrue()
        ->and(gccGateway('stripe_br')->cancelCharge('pi_3MtwBwLkdIwHu7ix28a3tqPa'))->toBeTrue()
        ->and(gccGateway('stripe_br')->cancelCharge('ch_3MtwBwLkdIwHu7ix28a3tqPa'))->toBeFalse();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('Idempotency-Key') && str_ends_with($r->url(), '/cancel'));
});

it('Pagar.me: DELETE /charges/{id} devolve a cobrança canceled; pedido antigo (or_…) não', function () {
    Http::fake(['https://api.pagar.me/core/v5/charges/ch_d22356Jf4WuGr8no' => Http::response(['id' => 'ch_d22356Jf4WuGr8no', 'status' => 'canceled'])]);

    expect(gccGateway('pagarme')->cancelCharge('ch_d22356Jf4WuGr8no'))->toBeTrue()
        ->and(gccGateway('pagarme')->cancelCharge('or_ZdnB5BBCmYhk534R'))->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
});

it('PagBank e InfinitePay: sem cancelamento de cobrança pendente na API — false, sem chamada', function () {
    expect(gccGateway('pagbank')->cancelCharge('ORDE_F87334AC-BB8B-42E2-AA85-8579F70AA328'))->toBeFalse()
        ->and(gccGateway('infinitepay')->cancelCharge('0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01.29990.abcd1234.0123456789abcdef'))->toBeFalse();

    Http::assertNothingSent();
});

it('Mercado Pago: boleto leva payer.address do cadastro; endereço nunca vai no metadata', function () {
    Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(['id' => 5466310457, 'status' => 'pending', 'transaction_amount' => 299.9], 201)]);

    $address = ['zipcode' => '01310100', 'street' => 'AV. PAULISTA', 'number' => '1000', 'complement' => null, 'district' => 'BELA VISTA', 'city' => 'SÃO PAULO', 'state' => 'SP'];

    $charge = fn (string $method) => new CreateChargeDTO(
        entityId: (string) Str::uuid(),
        invoiceId: '9d1c7a52-6f0e-4a8e-9b0e-0d7f3b1c2a10',
        subscriptionId: 'sub-1',
        customerId: '1234567-cbXRL1mI2X',
        amount: 299.9,
        currency: 'BRL',
        description: 'Fatura',
        dueDate: '2026-10-15',
        paymentMethod: $method,
        metadata: ['email' => 'financeiro@olhar.com.br', 'customer_name' => 'Clínica Olhar', 'document' => '11222333000181', 'address' => $address],
        idempotencyKey: 'k:' . $method,
    );

    gccGateway('mercadopago')->createCharge($charge('boleto'));
    gccGateway('mercadopago')->createCharge($charge('pix'));

    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->values();

    expect($sent[0]['payer']['address'])->toBe([
        'zip_code'      => '01310100',
        'street_name'   => 'AV. PAULISTA',
        'street_number' => '1000',
        'neighborhood'  => 'BELA VISTA',
        'city'          => 'SÃO PAULO',
        'federal_unit'  => 'SP',
    ])
        ->and($sent[0]['metadata'])->not->toHaveKey('address')
        ->and($sent[1]['payer'])->not->toHaveKey('address')
        ->and($sent[1]['metadata'])->not->toHaveKey('address');
});

// ── Uso: cancelamento e substituição ─────────────────────────────────────────

/** Renovação local no Mercado Pago com a cobrança do próximo ciclo em aberto. */
function gccLocalSubscription(string $externalId = '5466310457'): Subscription
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $subscription = Subscription::factory()->for($entity)->for(Plan::factory()->create(['active' => true]))->create([
        'billing_mode'            => SubscriptionBillingMode::Gateway,
        'status'                  => SubscriptionStatus::Active,
        'billing_state'           => 'pending',
        'gateway'                 => 'mercadopago',
        'gateway_subscription_id' => null,
        'billing_cycle'           => BillingCycle::Monthly,
        'amount'                  => 299.90,
        'ends_at'                 => '2026-10-08 23:59:59',
        'next_billing_at'         => '2026-10-08 23:59:59',
        'last_payment_at'         => '2026-09-08 10:00:00',
    ]);

    $invoice = Invoice::query()->create([
        'entity_id'           => $entity->id,
        'subscription_id'     => $subscription->id,
        'plan_id'             => $subscription->plan_id,
        'gateway_code'        => 'mercadopago',
        'external_invoice_id' => $externalId,
        'reference'           => 'INV-GCC-' . Str::random(6),
        'period_start'        => '2026-10-08',
        'period_end'          => '2026-11-08',
        'due_at'              => '2026-10-08 23:59:59',
        'amount'              => 299.90,
        'currency'            => 'BRL',
        'status'              => InvoiceStatus::Pending->value,
    ]);

    Payment::query()->create([
        'entity_id'           => $entity->id,
        'invoice_id'          => $invoice->id,
        'subscription_id'     => $subscription->id,
        'gateway_code'        => 'mercadopago',
        'external_payment_id' => $externalId,
        'status'              => PaymentStatus::Pending->value,
        'amount'              => 299.90,
        'currency'            => 'BRL',
    ]);

    return $subscription;
}

it('cancelar a assinatura (renovação local) cancela no gateway a cobrança em aberto e marca fatura e pagamento', function () {
    Http::fake(['https://api.mercadopago.com/v1/payments/5466310457' => Http::response(['id' => 5466310457, 'status' => 'cancelled'])]);
    $subscription = gccLocalSubscription();

    app(BillingCancellationService::class)->cancel($subscription, $subscription->entity, CancellationReason::AdminAction, 'manager');

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://api.mercadopago.com/v1/payments/5466310457');

    expect(Invoice::where('subscription_id', $subscription->id)->sole()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(Payment::where('subscription_id', $subscription->id)->sole()->status)->toBe(PaymentStatus::Cancelled)
        ->and(BillingLog::query()->where('subscription_id', $subscription->id)->where('level', 'critical')->exists())->toBeFalse();
});

it('substituída (nova contratação): a cobrança em aberto da antiga é cancelada; recusada no gateway, fica o alerta crítico', function () {
    Http::fake([
        'https://api.mercadopago.com/v1/payments/5466310457' => Http::response(['id' => 5466310457, 'status' => 'cancelled']),
        'https://api.mercadopago.com/v1/payments/7777777777' => Http::response(['message' => 'Payment already approved'], 400),
    ]);
    $cancelled = gccLocalSubscription();
    $refused   = gccLocalSubscription('7777777777');

    app(SubscriptionCycleService::class)->stopRecurrences(collect([$cancelled, $refused]), (string) Str::uuid());

    expect(Invoice::where('subscription_id', $cancelled->id)->sole()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(Invoice::where('subscription_id', $refused->id)->sole()->status)->toBe(InvoiceStatus::Pending)
        ->and(BillingLog::query()->where('subscription_id', $refused->id)->where('level', 'critical')->sole()->context['open_external_charges'])
        ->toBe(['7777777777']);
});
