<?php

declare(strict_types=1);

use App\Broadcasting\ClinicBillingChannel;
use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentStatus, SubscriptionCancelledReason, WebhookEventStatus};
use App\Enums\{BillingCycle, ClientRule, SubscriptionBillingMode, SubscriptionStatus};
use App\Enums\SaasRule;
use App\Events\Billing\InvoicePaid;
use App\Jobs\Billing\{ProcessBillingWebhookJob, RenewSubscriptionJob};
use App\Models\Billing\{FinancialEvent, HostedCheckout, Invoice, Payment, PaymentAttempt};
use App\Models\Billing\WebhookEvent;
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Models\EntityUser;
use App\Services\Billing\{CheckoutService, CircuitBreakerService, ProcessWebhookEventService};
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, DB, Event, Http, Queue, RateLimiter};

/**
 * Checkout transparente pelos endpoints do painel (/panel/my-subscription)
 * e do cadastro (/signup-checkout): autorização (admin/financeiro/dono),
 * isolamento por clínica, Pix/boleto (instruções e reemissão), cartão
 * (aprovado, recusado, parcelas), contratação pagando, troca de cartão,
 * idempotência, limite de tentativas, renovação com o cartão salvo e o
 * aviso em tempo real (InvoicePaid no canal billing.{entityId}).
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();

    config([
        'billing.enforce_subscription_access'     => true,
        'billing.gateways.mercadopago.base_url'   => 'https://api.mercadopago.com',
        'billing.gateways.mercadopago.secret'     => 'APP_USR-secret',
        'billing.gateways.mercadopago.public_key' => 'APP_USR-public',
        'billing.gateways.pagarme.base_url'       => 'https://api.pagar.me/core/v5',
        'billing.gateways.pagarme.secret'         => 'sk_test_easyeye',
        'billing.gateways.pagarme.public_key'     => 'pk_test_easyeye',
        'billing.gateways.asaas.base_url'         => 'https://api.asaas.com',
        'billing.gateways.asaas.secret'           => '$aact_test',
    ]);

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'yearly', 'price' => 2878.80]);
    $this->plan = $this->plan->fresh('prices');

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Olhar Ltda', 'email' => 'financeiro@olhar.test', 'national_registration' => '11222333000181', 'cellphone' => '11987654321']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value, isOwner: true);
});

afterEach(fn () => Carbon::setTestNow());

function ckoAs(?User $user = null, $member = null): mixed
{
    return test()->actingAs($user ?? test()->user)->withSession(panelSession($member ?? test()->member));
}

/** Cliente pagante no Mercado Pago (renovação local) com a fatura de 01/10 vencida em Pix. */
function ckoOverdueMp(array $subscription = [], array $invoice = []): array
{
    $sub = Subscription::factory()->gateway('mercadopago')->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => null,
        'gateway_customer_id'     => '1234567-cus',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::PastDue,
        'billing_state'           => 'past_due',
        'amount'                  => 299.90,
        'last_payment_at'         => '2026-09-01 10:00:00',
        'starts_at'               => '2026-08-01 10:00:00',
        'ends_at'                 => '2026-10-01 23:59:59',
        'next_billing_at'         => '2026-10-01 23:59:59',
        'past_due_at'             => '2026-10-01 23:59:59',
        ...$subscription,
    ]);

    $inv = Invoice::query()->create([
        'entity_id'           => $sub->entity_id,
        'subscription_id'     => $sub->id,
        'plan_id'             => $sub->plan_id,
        'gateway_code'        => 'mercadopago',
        'reference'           => 'INV-20261001-ABC',
        'period_start'        => '2026-10-01',
        'period_end'          => '2026-11-01',
        'due_at'              => '2026-10-01 23:59:59',
        'amount'              => 299.90,
        'currency'            => 'BRL',
        'status'              => InvoiceStatus::Overdue->value,
        'external_invoice_id' => '111',
        'payment_url'         => 'https://www.mercadopago.com.br/payments/111/ticket?caller_id=1',
        'raw_gateway_payload' => [
            'id'                   => 111,
            'status'               => 'pending',
            'payment_method_id'    => 'pix',
            'date_of_expiration'   => '2026-10-31T23:59:59.000-03:00',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020101pix-111', 'qr_code_base64' => 'iVBORw0KGgo=', 'ticket_url' => 'https://www.mercadopago.com.br/payments/111/ticket?caller_id=1']],
        ],
        ...$invoice,
    ]);

    Payment::query()->create([
        'entity_id'           => $sub->entity_id,
        'invoice_id'          => $inv->id,
        'subscription_id'     => $sub->id,
        'gateway_code'        => 'mercadopago',
        'external_payment_id' => '111',
        'status'              => PaymentStatus::Pending->value,
        'amount'              => 299.90,
        'currency'            => 'BRL',
        'idempotency_key'     => 'payment-renew-111',
    ]);

    $sub->update(['current_invoice_id' => $inv->id]);

    return [$sub->fresh(), $inv->fresh()];
}

function ckoMpCustomerSearch(): array
{
    return ['https://api.mercadopago.com/v1/customers/search*' => Http::response(['results' => [['id' => '1234567-cus']]])];
}

/** Asaas no cadastro: cliente, recorrência (1ª parcela), QR Pix, linha do boleto e Asaas Checkout (sandbox falso). */
function ckoSignupAsaasFake(?callable $checkoutResponse = null): void
{
    Http::fake(function (Request $request) use ($checkoutResponse) {
        $url    = $request->url();
        $method = $request->method();

        if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
            return $method === 'GET' ? Http::response(['data' => []]) : Http::response(['id' => 'cus_signup1']);
        }

        if ($url === 'https://api.asaas.com/v3/subscriptions' && $method === 'POST') {
            return Http::response(['id' => 'sub_signup1', 'customer' => 'cus_signup1', 'status' => 'ACTIVE', 'billingType' => 'UNDEFINED', 'cycle' => $request['cycle'], 'value' => $request['value'], 'nextDueDate' => $request['nextDueDate'], 'externalReference' => $request['externalReference']]);
        }

        if (str_starts_with($url, 'https://api.asaas.com/v3/subscriptions/sub_signup1/payments')) {
            return Http::response(['hasMore' => false, 'data' => [ckoSignupAsaasPayment()]]);
        }

        if ($url === 'https://api.asaas.com/v3/subscriptions/sub_signup1') {
            return Http::response(['id' => 'sub_signup1', 'status' => 'ACTIVE', 'cycle' => 'MONTHLY', 'value' => 299.9]);
        }

        if ($url === 'https://api.asaas.com/v3/payments/pay_signup1/pixQrCode') {
            return Http::response(['encodedImage' => 'iVBORw0KGgoAAAA=', 'payload' => '00020101021226pix-signup-1', 'expirationDate' => '2026-10-08 23:59:59']);
        }

        if ($url === 'https://api.asaas.com/v3/payments/pay_signup1/identificationField') {
            return Http::response(['identificationField' => '23793.38128 60082.000006 00000.000000 1 97890000029990', 'nossoNumero' => '1', 'barCode' => '23791978900000299903812860082000000000000000']);
        }

        if ($url === 'https://api.asaas.com/v3/payments/pay_signup1') {
            return Http::response(ckoSignupAsaasPayment());
        }

        if ($url === 'https://api.asaas.com/v3/checkouts' && $method === 'POST') {
            return $checkoutResponse !== null
                ? $checkoutResponse($request)
                : Http::response(['id' => 'chk_signup1', 'status' => 'ACTIVE', 'minutesToExpire' => 60, 'link' => 'https://asaas.com/checkoutSession/show?id=chk_signup1']);
        }

        return Http::response(['errors' => [['code' => 'not_faked', 'description' => $method . ' ' . $url]]], 404);
    });
}

/** 1ª parcela da recorrência do cadastro, como o Asaas devolve na listagem e no PAYMENT_*. */
function ckoSignupAsaasPayment(array $overrides = []): array
{
    return array_merge([
        'object'            => 'payment',
        'id'                => 'pay_signup1',
        'customer'          => 'cus_signup1',
        'subscription'      => 'sub_signup1',
        'value'             => 299.9,
        'netValue'          => 297.91,
        'billingType'       => 'UNDEFINED',
        'status'            => 'PENDING',
        'dueDate'           => '2026-10-08',
        'originalDueDate'   => '2026-10-08',
        'paymentDate'       => null,
        'clientPaymentDate' => null,
        'invoiceUrl'        => 'https://www.asaas.com/i/signup1',
        'bankSlipUrl'       => 'https://www.asaas.com/b/pdf/signup1',
        'deleted'           => false,
    ], $overrides);
}

/** Cliente do cadastro: usuário que ainda não confirmou e-mail/WhatsApp, na clínica recém-criada. */
function ckoSignupUser(): array
{
    $user   = User::factory()->unverified()->create();
    $member = createEntityUser(test()->clinic, $user, ClientRule::Admin->value, isOwner: true);

    return [$user, $member];
}

/** POST do webhook do Asaas pela rota real (token no header); devolve o WebhookEvent gravado. */
function ckoSignupAsaasWebhook(string $event, array $payment, string $eventId): WebhookEvent
{
    $response = test()->postJson('/api/billing/webhooks/asaas', [
        'id'          => $eventId,
        'event'       => $event,
        'dateCreated' => now()->format('Y-m-d H:i:s'),
        'payment'     => ckoSignupAsaasPayment($payment),
    ], ['asaas-access-token' => 'whsec_asaas_signup'])->assertOk();

    return WebhookEvent::query()->findOrFail($response->json('event_id'));
}

describe('autorização e isolamento', function () {
    it('secretária (sem ser dona) recebe 403; outra clínica não enxerga a fatura (404)', function () {
        [, $invoice] = ckoOverdueMp();

        $secretary = User::factory()->create();
        $member    = createEntityUser($this->clinic, $secretary, ClientRule::Secretary->value);

        ckoAs($secretary, $member)->getJson(route('panel.my-subscription.summary'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');

        $other                = Entity::factory()->make(['is_client' => true, 'active' => true]);
        $other->skipAutoTrial = true;
        $other->save();
        $otherUser   = User::factory()->create();
        $otherMember = createEntityUser($other, $otherUser, ClientRule::Admin->value, isOwner: true);

        ckoAs($otherUser, $otherMember)
            ->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'pix']))
            ->assertNotFound();

        Http::assertNothingSent();
    });

    it('financeiro paga; o checkout fica aberto mesmo com o acesso bloqueado (CheckSubscription)', function () {
        ckoOverdueMp(['past_due_at' => '2026-09-20 23:59:59', 'next_billing_at' => '2026-09-20 23:59:59', 'ends_at' => '2026-09-20 23:59:59']);

        $financial = User::factory()->create();
        $member    = createEntityUser($this->clinic, $financial, ClientRule::Financial->value);

        ckoAs($financial, $member)->getJson(route('panel.dashboard'))->assertStatus(402);

        ckoAs($financial, $member)->getJson(route('panel.my-subscription.summary'))
            ->assertOk()
            ->assertJsonPath('data.subscription.can_pay', true)
            ->assertJsonPath('data.open_invoices.0.reference', 'INV-20261001-ABC')
            ->assertJsonPath('data.payment.gateway', 'mercadopago')
            ->assertJsonPath('data.payment.card.public_key', 'APP_USR-public')
            ->assertJsonPath('data.payment.card.sdk_url', 'https://sdk.mercadopago.com/js/v2')
            ->assertJsonPath('data.realtime.channel', 'billing.' . $this->clinic->id)
            ->assertJsonPath('data.realtime.event', '.invoice.paid');
    });

    it('canal billing.{entityId}: só contato de cobrança da clínica da sessão', function () {
        $channel   = new ClinicBillingChannel();
        $secretary = User::factory()->create();
        createEntityUser($this->clinic, $secretary, ClientRule::Secretary->value);

        session(['selected_entity_id' => (string) $this->clinic->id]);

        expect($channel->join($this->user, (string) $this->clinic->id))->toBeTrue()
            ->and($channel->join($secretary, (string) $this->clinic->id))->toBeFalse()
            ->and($channel->join($this->user, (string) str()->uuid()))->toBeFalse();
    });
});

describe('Pix e boleto', function () {
    it('Pix da cobrança vigente sai do pagamento guardado (sem chamar o gateway) e fica em cache na fatura', function () {
        [, $invoice] = ckoOverdueMp();

        ckoAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'pix']))
            ->assertOk()
            ->assertJsonPath('data.mode', 'transparent')
            ->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-111')
            ->assertJsonPath('data.instructions.pix.qr_code_base64', 'iVBORw0KGgo=');

        expect($invoice->fresh()->payment_instructions['pix']['charge_id'])->toBe('111');

        Http::assertNothingSent();
    });

    it('boleto numa fatura em Pix: o GET só lê (issue_required); o POST emite no MP, desliga e cancela o Pix antigo sem instruções guardadas; webhook dele não mexe na fatura', function () {
        [$subscription, $invoice] = ckoOverdueMp();

        Http::fake([
            'https://api.mercadopago.com/v1/payments/111' => Http::response(['id' => 111, 'status' => 'cancelled']),
            'https://api.mercadopago.com/v1/payments'     => Http::response([
                'id'                  => 222,
                'status'              => 'pending',
                'payment_method_id'   => 'bolbradesco',
                'transaction_amount'  => 299.90,
                'date_of_expiration'  => '2026-10-06T23:59:59.000-03:00',
                'transaction_details' => ['external_resource_url' => 'https://www.mercadopago.com.br/payments/222/ticket?caller_id=1&payment_method_id=bolbradesco', 'digitable_line' => '23793381286008200000000000000000197890000029990'],
            ], 201),
        ]);

        // GET nunca emite: sem boleto na fatura, pede a emissão.
        ckoAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'boleto']))
            ->assertOk()
            ->assertJsonPath('data.issue_required', true)
            ->assertJsonPath('data.instructions', null);

        Http::assertNothingSent();

        ckoAs()->postJson(route('panel.my-subscription.charge', ['invoice' => $invoice->id]), ['method' => 'boleto'])
            ->assertOk()
            ->assertJsonPath('data.instructions.boleto.digitable_line', '23793381286008200000000000000000197890000029990')
            ->assertJsonPath('data.invoice.payment_method', 'boleto');

        $invoice->refresh();

        expect($invoice->external_invoice_id)->toBe('222')
            ->and($invoice->status)->toBe(InvoiceStatus::Pending)
            ->and($invoice->metadata['detached_charges'])->toBe(['111'])
            ->and(Payment::query()->where('external_payment_id', '111')->value('status'))->toBe(PaymentStatus::Cancelled)
            ->and(Payment::query()->where('external_payment_id', '222')->first()->payment_method)->toBe('boleto')
            ->and(PaymentAttempt::query()->where('invoice_id', $invoice->id)->where('trigger', 'checkout')->count())->toBe(1);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mercadopago.com/v1/payments'
            && $r['payment_method_id'] === 'bolbradesco'
            && $r->hasHeader('X-Idempotency-Key', "checkout:boleto:{$invoice->id}:1"));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/v1/payments/111'));

        // O cancelamento do Pix antigo chega pelo webhook: fatura intacta.
        Http::fake(['https://api.mercadopago.com/v1/payments/111' => Http::response(['id' => 111, 'status' => 'cancelled', 'transaction_amount' => 299.90, 'external_reference' => $invoice->id])]);

        $event = WebhookEvent::query()->create([
            'gateway_code'      => 'mercadopago',
            'external_event_id' => 'evt-111-cancelled',
            'payload'           => ['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => '111']],
            'headers'           => [],
            'status'            => 'received',
            'received_at'       => now(),
            'event_hash'        => hash('sha256', 'evt-111'),
        ]);

        app(ProcessWebhookEventService::class)->process($event);

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Pending)
            ->and($event->fresh()->normalized_payload['outcome'])->toBe('ignored_replaced_charge')
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
    });

    it('Asaas (recorrência): liga a cobrança da recorrência à fatura e devolve o QR do pixQrCode — nunca reemite', function () {
        $subscription = Subscription::factory()->gateway('asaas')->for($this->clinic)->for($this->plan)->create([
            'gateway_subscription_id' => 'sub_VXJBYgP2u0eO',
            'billing_cycle'           => BillingCycle::Monthly,
            'status'                  => SubscriptionStatus::PastDue,
            'amount'                  => 299.90,
            'next_billing_at'         => '2026-10-08 23:59:59',
            'ends_at'                 => '2026-10-08 23:59:59',
        ]);
        $invoice = Invoice::query()->create([
            'entity_id'       => $subscription->entity_id,
            'subscription_id' => $subscription->id,
            'plan_id'         => $subscription->plan_id,
            'gateway_code'    => 'asaas',
            'reference'       => 'INV-ASAAS-1',
            'period_start'    => '2026-10-08',
            'period_end'      => '2026-11-08',
            'due_at'          => '2026-10-08 23:59:59',
            'amount'          => 299.90,
            'currency'        => 'BRL',
            'status'          => InvoiceStatus::Pending->value,
        ]);
        $subscription->update(['current_invoice_id' => $invoice->id]);

        Http::fake([
            'https://api.asaas.com/v3/subscriptions/sub_VXJBYgP2u0eO/payments*' => Http::response(['hasMore' => false, 'data' => [['id' => 'pay_080225913252', 'status' => 'PENDING', 'dueDate' => '2026-10-08', 'value' => 299.9, 'invoiceUrl' => 'https://www.asaas.com/i/080225913252']]]),
            'https://api.asaas.com/v3/subscriptions/sub_VXJBYgP2u0eO'           => Http::response(['id' => 'sub_VXJBYgP2u0eO', 'status' => 'ACTIVE', 'cycle' => 'MONTHLY', 'value' => 299.9]),
            'https://api.asaas.com/v3/payments/pay_080225913252/pixQrCode'      => Http::response(['encodedImage' => 'iVBOR=', 'payload' => '00020101pix-asaas', 'expirationDate' => '2027-10-08 23:59:59']),
        ]);

        ckoAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'pix']))
            ->assertOk()
            ->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-asaas');

        expect($invoice->fresh()->external_invoice_id)->toBe('pay_080225913252')
            ->and($invoice->fresh()->payment_url)->toBe('https://www.asaas.com/i/080225913252');

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        // Cartão no Asaas: ambiente seguro do Asaas (Asaas Checkout). A leitura
        // (GET) não abre o checkout — pede o POST (issue_required).
        ckoAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'credit_card']))
            ->assertOk()
            ->assertJsonPath('data.mode', 'hosted')
            ->assertJsonPath('data.issue_required', true)
            ->assertJsonPath('data.recurrent', true);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    });

    it('Pix pago pelo webhook: fatura paga e InvoicePaid no canal da clínica (a tela libera sozinha)', function () {
        Event::fake([InvoicePaid::class]);
        [$subscription, $invoice] = ckoOverdueMp();

        Http::fake(['https://api.mercadopago.com/v1/payments/111' => Http::response(['id' => 111, 'status' => 'approved', 'transaction_amount' => 299.90, 'external_reference' => $invoice->id])]);

        $event = WebhookEvent::query()->create([
            'gateway_code'      => 'mercadopago',
            'external_event_id' => 'evt-111-approved',
            'payload'           => ['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => '111']],
            'headers'           => [],
            'status'            => 'received',
            'received_at'       => now(),
            'event_hash'        => hash('sha256', 'evt-111-approved'),
        ]);

        app(ProcessWebhookEventService::class)->process($event);

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

        Event::assertDispatched(InvoicePaid::class, fn (InvoicePaid $e) => $e->entityId === (string) $this->clinic->id
            && $e->broadcastWith()['invoice_id'] === $invoice->id
            && $e->broadcastWith()['status'] === 'paid');
    });

    it('fatura que não está em aberto: 409', function () {
        [, $invoice] = ckoOverdueMp([], ['status' => InvoiceStatus::Paid->value, 'paid_at' => now()]);

        ckoAs()->getJson(route('panel.my-subscription.instructions', ['invoice' => $invoice->id, 'method' => 'pix']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'invoice_not_payable');
    });
});

describe('cartão numa fatura em aberto', function () {
    it('aprovado: fatura paga, assinatura ativa, cartão salvo, Pix antigo cancelado e InvoicePaid transmitido', function () {
        Event::fake([InvoicePaid::class]);
        [$subscription, $invoice] = ckoOverdueMp();

        Http::fake([
            'https://api.mercadopago.com/v1/payments/111'                => Http::response(['id' => 111, 'status' => 'cancelled']),
            'https://api.mercadopago.com/v1/customers/1234567-cus/cards' => Http::response(['id' => '9876543210', 'last_four_digits' => '6351', 'payment_method' => ['id' => 'master']], 201),
            'https://api.mercadopago.com/v1/payments'                    => Http::response([
                'id'                 => 333,
                'status'             => 'approved',
                'status_detail'      => 'accredited',
                'transaction_amount' => 299.90,
                'payment_method_id'  => 'master',
                'expanded'           => ['gateway' => ['reference' => ['network_transaction_id' => 'NTID-1']]],
            ], 201),
        ]);

        ckoAs()->postJson(route('panel.my-subscription.card', $invoice->id), [
            'card_token'        => 'ff8080814c11e237014c1ff593b57b4d',
            'installments'      => 1,
            'payment_method_id' => 'master',
            'issuer_id'         => '24',
        ])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.card.last4', '6351');

        $subscription->refresh();

        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->payment_method)->toBe('credit_card')
            ->and($subscription->gateway_card_id)->toBe('9876543210')
            ->and($subscription->card_last4)->toBe('6351')
            ->and($subscription->gateway_payload['card'])->toMatchArray(['origin_payment_id' => '333', 'network_transaction_id' => 'NTID-1'])
            // período conta do vencimento da fatura (01/10), não do pagamento
            ->and($subscription->ends_at->toDateString())->toBe('2026-11-01')
            ->and(json_encode($subscription->toArray()))->not->toContain('9876543210');

        Event::assertDispatched(InvoicePaid::class, fn (InvoicePaid $e) => $e->invoiceId === $invoice->id
            && $e->broadcastOn()[0]->name === 'private-billing.' . $this->clinic->id
            && $e->broadcastAs() === 'invoice.paid');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/v1/payments/111'));
    });

    it('recusado: 422 com o motivo; fatura e cobrança vigente intactas; webhook da recusa não falha a fatura', function () {
        [, $invoice] = ckoOverdueMp();

        Http::fake(['https://api.mercadopago.com/v1/payments' => Http::response(['id' => 444, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount', 'payment_method_id' => 'visa'], 201)]);

        ckoAs()->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'tok', 'payment_method_id' => 'visa'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'card_declined')
            ->assertJsonPath('message', __('checkout.errors.card_declined', ['reason' => 'Cartão sem limite suficiente.']));

        $invoice->refresh();

        expect($invoice->external_invoice_id)->toBe('111')
            ->and($invoice->status)->toBe(InvoiceStatus::Overdue)
            ->and($invoice->metadata['detached_charges'])->toBe(['444'])
            ->and(PaymentAttempt::query()->where('invoice_id', $invoice->id)->value('error_code'))->toBe('declined');

        Http::assertSentCount(1);
    });

    it('número de cartão cru no lugar do token: 422 sem chamar o gateway', function () {
        [, $invoice] = ckoOverdueMp();

        ckoAs()->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => '4111 1111 1111 1111'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_token');

        Http::assertNothingSent();
    });

    it('parcelas no mensal: 422 (parcelado só no anual)', function () {
        [, $invoice] = ckoOverdueMp();

        ckoAs()->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'tok', 'installments' => 3])
            ->assertStatus(422)
            ->assertJsonPath('code', 'installments_invalid');

        Http::assertNothingSent();
    });

    it('mesma Idempotency-Key: o 2º pedido devolve o mesmo resultado sem nova cobrança', function () {
        [, $invoice] = ckoOverdueMp();

        Http::fake([
            'https://api.mercadopago.com/v1/payments/111'                => Http::response(['id' => 111, 'status' => 'cancelled']),
            'https://api.mercadopago.com/v1/customers/1234567-cus/cards' => Http::response(['id' => '1', 'last_four_digits' => '1111', 'payment_method' => ['id' => 'visa']], 201),
            'https://api.mercadopago.com/v1/payments'                    => Http::response(['id' => 555, 'status' => 'approved', 'transaction_amount' => 299.90, 'payment_method_id' => 'visa'], 201),
        ]);

        $first  = ckoAs()->withHeader('Idempotency-Key', 'k-123')->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'tok-a', 'payment_method_id' => 'visa'])->assertOk();
        $second = ckoAs()->withHeader('Idempotency-Key', 'k-123')->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'tok-a', 'payment_method_id' => 'visa'])->assertOk();

        expect($second->json())->toBe($first->json());

        Http::assertSentCount(3); // pagamento, cartão salvo, cancelamento do Pix — uma vez só
    });

    it('limite: a partir da 6ª tentativa de pagamento no minuto, 429', function () {
        [, $invoice] = ckoOverdueMp();
        RateLimiter::clear('checkout');

        for ($i = 0; $i < 5; $i++) {
            ckoAs()->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'tok', 'installments' => 3])->assertStatus(422);
        }

        ckoAs()->postJson(route('panel.my-subscription.card', $invoice->id), ['card_token' => 'tok', 'installments' => 3])->assertStatus(429);
    });
});

describe('contratar pagando', function () {
    it('Pix no Mercado Pago: trial substituído pela contratação aguardando o Pix, com as instruções na resposta', function () {
        config(['billing.default_gateway' => 'mercadopago']);

        $trial = Subscription::factory()->for($this->clinic)->for($this->plan)->create([
            'status'        => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(2),
            'billing_mode'  => null,
        ]);

        Http::fake([
            ...ckoMpCustomerSearch(),
            'https://api.mercadopago.com/v1/payments' => Http::response([
                'id'                   => 777,
                'status'               => 'pending',
                'payment_method_id'    => 'pix',
                'transaction_amount'   => 299.90,
                'date_of_expiration'   => '2026-10-08T23:59:59.000-03:00',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020101pix-777', 'qr_code_base64' => 'iVBOR=', 'ticket_url' => 'https://www.mercadopago.com.br/payments/777/ticket?caller_id=1']],
            ], 201),
        ]);

        $response = ckoAs()->postJson(route('panel.my-subscription.contract'), [
            'plan_id'       => $this->plan->id,
            'billing_cycle' => 'monthly',
            'method'        => 'pix',
        ])->assertOk()
            ->assertJsonPath('data.mode', 'transparent')
            ->assertJsonPath('data.instructions.pix.copy_paste', '00020101pix-777')
            ->assertJsonPath('data.subscription.is_awaiting_first_payment', true);

        $new = Subscription::query()->find($response->json('data.subscription.id'));

        expect($new->gateway)->toBe('mercadopago')
            ->and($new->currentInvoice->payment_method)->toBe('pix')
            ->and($trial->fresh()->status)->toBe(SubscriptionStatus::Cancelled);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mercadopago.com/v1/payments' && $r['payment_method_id'] === 'pix');
    });

    it('cartão anual em 12x no Pagar.me: ativa na hora, cartão salvo, sem fallback em recusa', function () {
        config(['billing.default_gateway' => 'pagarme']);

        Http::fake([
            'https://api.pagar.me/core/v5/customers'                => Http::response(['id' => 'cus_pgm1']),
            'https://api.pagar.me/core/v5/customers/cus_pgm1/cards' => Http::response(['id' => 'card_1', 'last_four_digits' => '0010', 'brand' => 'Visa']),
            'https://api.pagar.me/core/v5/orders'                   => Http::response(['id' => 'or_1', 'status' => 'paid', 'charges' => [['id' => 'ch_1', 'status' => 'paid', 'amount' => 287880, 'last_transaction' => ['status' => 'captured']]]]),
        ]);

        $response = ckoAs()->postJson(route('panel.my-subscription.contract'), [
            'plan_id'       => $this->plan->id,
            'billing_cycle' => 'yearly',
            'method'        => 'credit_card',
            'card_token'    => 'token_abc',
            'installments'  => 12,
        ])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.card.last4', '0010');

        $subscription = Subscription::query()->find($response->json('data.subscription.id'));

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->gateway)->toBe('pagarme')
            ->and($subscription->gateway_card_id)->toBe('card_1')
            ->and($subscription->card_installments)->toBe(12)
            ->and($subscription->gateway_payload['card']['origin_payment_id'])->toBe('ch_1')
            // cartão: o 1º período começa hoje
            ->and($subscription->ends_at->toDateString())->toBe('2027-10-05');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/orders') && $r['payments'][0]['credit_card']['installments'] === 12);
    });

    it('cartão recusado na contratação: 422, tentativa encerrada e nenhuma assinatura vigente nova', function () {
        config(['billing.default_gateway' => 'pagarme']);

        Http::fake([
            'https://api.pagar.me/core/v5/customers'                => Http::response(['id' => 'cus_pgm1']),
            'https://api.pagar.me/core/v5/customers/cus_pgm1/cards' => Http::response(['id' => 'card_1', 'last_four_digits' => '0002', 'brand' => 'Visa']),
            'https://api.pagar.me/core/v5/orders'                   => Http::response(['id' => 'or_2', 'status' => 'failed', 'charges' => [['id' => 'ch_2', 'status' => 'failed', 'last_transaction' => ['status' => 'not_authorized', 'acquirer_message' => 'Cartão sem saldo']]]]),
        ]);

        ckoAs()->postJson(route('panel.my-subscription.contract'), [
            'plan_id'       => $this->plan->id,
            'billing_cycle' => 'monthly',
            'method'        => 'credit_card',
            'card_token'    => 'token_abc',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'card_declined')
            ->assertJsonPath('message', __('checkout.errors.card_declined', ['reason' => 'Cartão sem saldo']));

        expect(Subscription::query()->forEntity((string) $this->clinic->id)->value('cancelled_reason'))->toBe(SubscriptionCancelledReason::ActivationFailed->value);
    });

    it('cartão no Asaas: sem transparente — a contratação abre o Asaas Checkout (assinatura no cartão), sem tokenizar nem guardar cartão aqui', function () {
        config(['billing.default_gateway' => 'asaas']);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
                return $request->method() === 'GET'
                    ? Http::response(['data' => []])
                    : Http::response(['id' => 'cus_000005219613']);
            }

            if ($url === 'https://api.asaas.com/v3/subscriptions') {
                return Http::response(['id' => 'sub_VXJBYgP2u0eO', 'customer' => 'cus_000005219613', 'status' => 'ACTIVE']);
            }

            if (str_starts_with($url, 'https://api.asaas.com/v3/subscriptions/sub_VXJBYgP2u0eO/payments')) {
                return Http::response(['hasMore' => false, 'data' => [['id' => 'pay_1', 'status' => 'PENDING', 'dueDate' => '2026-10-08', 'value' => 299.9, 'invoiceUrl' => 'https://www.asaas.com/i/pay_1']]]);
            }

            if ($url === 'https://api.asaas.com/v3/subscriptions/sub_VXJBYgP2u0eO') {
                return Http::response(['id' => 'sub_VXJBYgP2u0eO', 'status' => 'ACTIVE', 'cycle' => 'MONTHLY', 'value' => 299.9]);
            }

            if ($url === 'https://api.asaas.com/v3/checkouts') {
                return Http::response(['id' => 'chk_contrato', 'status' => 'ACTIVE', 'minutesToExpire' => 60, 'link' => 'https://asaas.com/checkoutSession/show?id=chk_contrato']);
            }

            return Http::response([], 404);
        });

        $response = ckoAs()->postJson(route('panel.my-subscription.contract'), [
            'plan_id'       => $this->plan->id,
            'billing_cycle' => 'monthly',
            'method'        => 'credit_card',
            'card_token'    => 'nao-usado',
        ])->assertOk()
            ->assertJsonPath('data.mode', 'hosted')
            ->assertJsonPath('data.recurrent', true)
            ->assertJsonPath('data.checkout_url', 'https://asaas.com/checkoutSession/show?id=chk_contrato');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.asaas.com/v3/checkouts'
            && $r['chargeTypes'] === ['RECURRENT'] && $r['billingTypes'] === ['CREDIT_CARD']
            && $r['subscription']['cycle'] === 'MONTHLY' && $r['customer'] === 'cus_000005219613');

        expect(Subscription::query()->find($response->json('data.subscription.id'))->gateway_card_id)->toBeNull();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'creditCard'));
    });
});

describe('troca de cartão e renovação no cartão salvo', function () {
    function ckoPagarmePaid(array $attributes = []): Subscription
    {
        return Subscription::factory()->gateway('pagarme')->for(test()->clinic)->for(test()->plan)->create([
            'gateway_subscription_id' => null,
            'gateway_customer_id'     => 'cus_pgm1',
            'billing_cycle'           => BillingCycle::Monthly,
            'status'                  => SubscriptionStatus::Active,
            'amount'                  => 299.90,
            'last_payment_at'         => '2026-09-05 10:00:00',
            'starts_at'               => '2026-09-05 10:00:00',
            'ends_at'                 => '2026-10-05 23:59:59',
            'next_billing_at'         => '2026-10-05 23:59:59',
            'payment_method'          => 'credit_card',
            'gateway_card_id'         => 'card_old',
            'card_brand'              => 'visa',
            'card_last4'              => '0010',
            'gateway_payload'         => ['card' => ['origin_payment_id' => 'ch_first', 'sequence' => 1]],
            ...$attributes,
        ]);
    }

    it('troca: guarda o novo cartão (sem cobrar) e zera a referência do anterior', function () {
        $subscription = ckoPagarmePaid();

        Http::fake(['https://api.pagar.me/core/v5/customers/cus_pgm1/cards' => Http::response(['id' => 'card_new', 'last_four_digits' => '4242', 'brand' => 'Mastercard'])]);

        ckoAs()->putJson(route('panel.my-subscription.replace-card'), ['card_token' => 'token_new'])
            ->assertOk()
            ->assertJsonPath('data.status', 'saved')
            ->assertJsonPath('data.card.last4', '4242');

        $subscription->refresh();

        expect($subscription->gateway_card_id)->toBe('card_new')
            ->and($subscription->card_brand)->toBe('mastercard')
            ->and($subscription->gateway_payload)->not->toHaveKey('card');

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/orders'));
    });

    it('renovação: no vencimento cobra o cartão salvo (subsequent) e estende; antes dele, nada', function () {
        Event::fake([InvoicePaid::class]);
        $subscription = ckoPagarmePaid(['next_billing_at' => '2026-10-07 23:59:59', 'ends_at' => '2026-10-07 23:59:59']);

        Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_r', 'status' => 'paid', 'charges' => [['id' => 'ch_renew', 'status' => 'paid', 'amount' => 29990, 'last_transaction' => ['status' => 'captured']]]])]);

        // Dentro da antecedência do boleto (05/10), mas cartão só no dia 07.
        app()->call([new RenewSubscriptionJob((string) $subscription->id), 'handle']);
        Http::assertNothingSent();
        expect($subscription->invoices()->count())->toBe(0);

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 08:00:00'));
        app()->call([new RenewSubscriptionJob((string) $subscription->id), 'handle']);

        $subscription->refresh();
        $invoice = $subscription->invoices()->first();

        expect($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->payment_method)->toBe('credit_card')
            ->and($subscription->ends_at->toDateString())->toBe('2026-11-07')
            ->and($subscription->gateway_payload['card']['sequence'])->toBe(2);

        Http::assertSent(fn (Request $r) => $r['payments'][0]['credit_card']['card_id'] === 'card_old'
            && $r['payments'][0]['credit_card']['recurrence_cycle'] === 'subsequent'
            && $r['payments'][0]['credit_card']['payment_origin'] === ['charge_id' => 'ch_first']);
        Event::assertDispatched(InvoicePaid::class);
    });

    it('renovação recusada: fatura em falha (régua segue) e o circuito do gateway não abre', function () {
        $subscription = ckoPagarmePaid();

        Http::fake(['https://api.pagar.me/core/v5/orders' => Http::response(['id' => 'or_x', 'status' => 'failed', 'charges' => [['id' => 'ch_x', 'status' => 'failed', 'last_transaction' => ['status' => 'not_authorized']]]])]);

        app()->call([new RenewSubscriptionJob((string) $subscription->id), 'handle']);

        expect($subscription->invoices()->first()->status)->toBe(InvoiceStatus::Failed)
            ->and($subscription->fresh()->billing_state)->toBe('error')
            ->and(app(CircuitBreakerService::class)->isOpen('pagarme', (string) $this->clinic->id))->toBeFalse();
    });
});

describe('cadastro no site (signup-checkout)', function () {
    it('clínica recém-criada, antes de confirmar e-mail/WhatsApp: vê as formas de pagamento do plano', function () {
        $user   = User::factory()->unverified()->create();
        $member = createEntityUser($this->clinic, $user, ClientRule::Admin->value, isOwner: true);

        config(['billing.default_gateway' => 'mercadopago']);

        ckoAs($user, $member)->getJson(route('signup-checkout.options', ['plan_id' => $this->plan->id, 'billing_cycle' => 'yearly']))
            ->assertOk()
            ->assertJsonPath('data.gateway', 'mercadopago')
            ->assertJsonPath('data.amount', 2878.8)
            ->assertJsonPath('data.card.installments.11.count', 12)
            ->assertJsonPath('data.card.installments.11.amount', 239.9);
    });

    it('clínica que já pagou não usa o caminho do cadastro (403)', function () {
        ckoOverdueMp();

        ckoAs()->getJson(route('signup-checkout.options', ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly']))
            ->assertStatus(403)
            ->assertJsonPath('code', 'signup_only');
    });

    it('permite confirmar o primeiro pagamento no resumo sem reabrir a contratação', function () {
        [$subscription, $invoice] = ckoOverdueMp();
        $subscription->update(['status' => SubscriptionStatus::Active, 'last_payment_at' => now()]);
        $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);

        ckoAs()->getJson(route('signup-checkout.summary'))
            ->assertOk()
            ->assertJsonPath('data.invoices.0.status', 'paid');

        ckoAs()->postJson(route('signup-checkout.contract'), [
            'plan_id'       => $this->plan->id,
            'billing_cycle' => 'monthly',
        ])->assertForbidden()->assertJsonPath('code', 'signup_only');
    });

    it('[SEGURANÇA] depois de pagar, SÓ o resumo continua liberado: instruções, emissão de cobrança, cartão e contratação seguem 403 (signup_only)', function () {
        [$subscription, $invoice] = ckoOverdueMp();
        $subscription->update(['status' => SubscriptionStatus::Active, 'last_payment_at' => now()]);
        $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
        Http::fake(); // nada pode chegar ao gateway

        ckoAs()->getJson(route('signup-checkout.summary'))->assertOk();

        ckoAs()->getJson(route('signup-checkout.options', ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly']))
            ->assertForbidden()->assertJsonPath('code', 'signup_only');
        ckoAs()->getJson(route('signup-checkout.instructions', ['invoice' => $invoice->id, 'method' => 'pix']))
            ->assertForbidden()->assertJsonPath('code', 'signup_only');
        ckoAs()->postJson(route('signup-checkout.charge', ['invoice' => $invoice->id]), ['method' => 'pix'])
            ->assertForbidden()->assertJsonPath('code', 'signup_only');
        ckoAs()->postJson(route('signup-checkout.charge', ['invoice' => $invoice->id]), ['method' => 'boleto'])
            ->assertForbidden()->assertJsonPath('code', 'signup_only');
        ckoAs()->postJson(route('signup-checkout.card', ['invoice' => $invoice->id]), ['card_token' => 'token_abc', 'installments' => 1])
            ->assertForbidden()->assertJsonPath('code', 'signup_only');
        ckoAs()->postJson(route('signup-checkout.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly'])
            ->assertForbidden()->assertJsonPath('code', 'signup_only');

        Http::assertNothingSent();
        expect(PaymentAttempt::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
    });

    it('Pix no cadastro (Asaas): devolve o QR (pixQrCode), o copia-e-cola e a validade; só o webhook ativa a assinatura', function () {
        config(['billing.default_gateway' => 'asaas', 'billing.gateways.asaas.webhook_secret' => 'whsec_asaas_signup']);
        ckoSignupAsaasFake();
        [$user, $member] = ckoSignupUser();

        $response = ckoAs($user, $member)->postJson(route('signup-checkout.contract'), [
            'plan_id'       => $this->plan->id,
            'billing_cycle' => 'monthly',
            'method'        => 'pix',
        ])->assertOk()
            ->assertJsonPath('data.mode', 'transparent')
            ->assertJsonPath('data.method', 'pix')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.instructions.pix.copy_paste', '00020101021226pix-signup-1')
            ->assertJsonPath('data.instructions.pix.qr_code_base64', 'iVBORw0KGgoAAAA=')
            ->assertJsonPath('data.subscription.is_awaiting_first_payment', true);

        expect(Carbon::parse($response->json('data.instructions.pix.expires_at'))->toDateString())->toBe('2026-10-08');

        $subscription = Subscription::query()->find($response->json('data.subscription.id'));
        $invoice      = $subscription->currentInvoice;

        // Recorrência no Asaas com a nossa referência e o 1º vencimento; o Pix vem da 1ª parcela.
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/subscriptions'
            && $r['billingType'] === 'UNDEFINED' && $r['cycle'] === 'MONTHLY' && $r['nextDueDate'] === '2026-10-08'
            && $r['externalReference'] === $subscription->id);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.asaas.com/v3/payments/pay_signup1/pixQrCode');

        // Mostrar o QR não paga nada: a assinatura só vira ativa pelo webhook.
        expect($subscription->gateway)->toBe('asaas')
            ->and($subscription->status)->not->toBe(SubscriptionStatus::Active)
            ->and($subscription->last_payment_at)->toBeNull()
            ->and($invoice->status)->toBe(InvoiceStatus::Pending);

        $event = ckoSignupAsaasWebhook('PAYMENT_RECEIVED', ['status' => 'RECEIVED', 'billingType' => 'PIX', 'paymentDate' => '2026-10-05', 'externalReference' => $subscription->id], 'evt_signup_pix_received');
        Queue::assertPushed(ProcessBillingWebhookJob::class, 1);
        expect($subscription->fresh()->status)->not->toBe(SubscriptionStatus::Active); // o job ainda não rodou

        app(ProcessWebhookEventService::class)->process($event);

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->fresh()->last_payment_at)->not->toBeNull()
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('boleto no cadastro (Asaas): devolve a linha digitável, o PDF e o vencimento; só o webhook ativa a assinatura', function () {
        config(['billing.default_gateway' => 'asaas', 'billing.gateways.asaas.webhook_secret' => 'whsec_asaas_signup']);
        ckoSignupAsaasFake();
        [$user, $member] = ckoSignupUser();

        $response = ckoAs($user, $member)->postJson(route('signup-checkout.contract'), [
            'plan_id'       => $this->plan->id,
            'billing_cycle' => 'monthly',
            'method'        => 'boleto',
        ])->assertOk()
            ->assertJsonPath('data.mode', 'transparent')
            ->assertJsonPath('data.method', 'boleto')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.instructions.boleto.digitable_line', '23793.38128 60082.000006 00000.000000 1 97890000029990')
            ->assertJsonPath('data.instructions.boleto.pdf_url', 'https://www.asaas.com/b/pdf/signup1');

        expect(Carbon::parse($response->json('data.instructions.boleto.due_date'))->toDateString())->toBe('2026-10-08');

        $subscription = Subscription::query()->find($response->json('data.subscription.id'));

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.asaas.com/v3/payments/pay_signup1/identificationField');
        expect($subscription->status)->not->toBe(SubscriptionStatus::Active);

        $event = ckoSignupAsaasWebhook('PAYMENT_RECEIVED', ['status' => 'RECEIVED', 'billingType' => 'BOLETO', 'paymentDate' => '2026-10-07', 'externalReference' => $subscription->id], 'evt_signup_boleto_received');
        app(ProcessWebhookEventService::class)->process($event);

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->currentInvoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('cartão no cadastro (Asaas): abre o Asaas Checkout RECORRENTE com validade e retornos; o retorno do pagador não ativa nada — só o webhook', function () {
        config(['billing.default_gateway' => 'asaas', 'billing.gateways.asaas.webhook_secret' => 'whsec_asaas_signup']);
        ckoSignupAsaasFake();
        [$user, $member] = ckoSignupUser();

        $response = ckoAs($user, $member)->postJson(route('signup-checkout.contract'), [
            'plan_id'       => $this->plan->id,
            'billing_cycle' => 'monthly',
            'method'        => 'credit_card',
            'card_token'    => 'nao-usado',
        ])->assertOk()
            ->assertJsonPath('data.mode', 'hosted')
            ->assertJsonPath('data.recurrent', true)
            ->assertJsonPath('data.checkout_url', 'https://asaas.com/checkoutSession/show?id=chk_signup1');

        $subscription = Subscription::query()->find($response->json('data.subscription.id'));
        $invoice      = $subscription->currentInvoice;

        Http::assertSent(function (Request $r) use ($invoice, $subscription) {
            if ($r->url() !== 'https://api.asaas.com/v3/checkouts') {
                return false;
            }

            $callback = $r['callback'];

            return $r['chargeTypes'] === ['RECURRENT'] && $r['billingTypes'] === ['CREDIT_CARD']
                && $r['customer'] === 'cus_signup1'
                && $r['subscription']['cycle'] === 'MONTHLY'
                && $r['minutesToExpire'] >= 10 && $r['minutesToExpire'] <= 1440
                && str_contains((string) $callback['successUrl'], 'checkout_return=success')
                && str_contains((string) $callback['cancelUrl'], 'checkout_return=cancel')
                && str_contains((string) $callback['expiredUrl'], 'checkout_return=expired')
                && str_contains((string) $callback['successUrl'], 'checkout_invoice=' . $invoice->id)
                && str_starts_with((string) $callback['successUrl'], 'http')
                && str_contains((string) $r['externalReference'], $subscription->id);
        });

        $checkout = HostedCheckout::query()->where('invoice_id', $invoice->id)->sole();
        expect($checkout->kind)->toBe(HostedCheckout::KIND_RECURRENT)
            ->and($checkout->status)->toBe(HostedCheckout::STATUS_ACTIVE)
            ->and($checkout->external_checkout_id)->toBe('chk_signup1')
            ->and($checkout->expires_at->toDateTimeString())->toBe(now()->addMinutes(60)->toDateTimeString())
            // Nada de cartão no nosso servidor.
            ->and($subscription->gateway_card_id)->toBeNull();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'creditCard'));

        // O pagador voltou do Asaas (successUrl) mas o pagamento ainda não foi confirmado:
        // a assinatura não ativa pela volta — só pelo webhook.
        ckoAs($user, $member)->getJson(route('signup-checkout.summary'))->assertOk();
        expect($subscription->fresh()->status)->not->toBe(SubscriptionStatus::Active)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Pending);

        $event = ckoSignupAsaasWebhook('PAYMENT_CONFIRMED', ['status' => 'CONFIRMED', 'billingType' => 'CREDIT_CARD', 'checkoutSession' => 'chk_signup1', 'paymentDate' => '2026-10-05', 'externalReference' => $subscription->id], 'evt_signup_card_confirmed');
        app(ProcessWebhookEventService::class)->process($event);

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('entrega DUPLICADA do webhook no fluxo de cadastro: um só evento, um só pagamento, nenhum efeito duplo', function () {
        Event::fake([InvoicePaid::class]);
        config(['billing.default_gateway' => 'asaas', 'billing.gateways.asaas.webhook_secret' => 'whsec_asaas_signup']);
        ckoSignupAsaasFake();
        [$user, $member] = ckoSignupUser();

        $subscription = Subscription::query()->find(
            ckoAs($user, $member)->postJson(route('signup-checkout.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
                ->assertOk()->json('data.subscription.id'),
        );

        $payment = ['status' => 'RECEIVED', 'billingType' => 'PIX', 'paymentDate' => '2026-10-05', 'externalReference' => $subscription->id];

        // O Asaas reenvia o MESMO evento (mesmo id): mesma linha, dois jobs na fila.
        $first  = ckoSignupAsaasWebhook('PAYMENT_RECEIVED', $payment, 'evt_signup_dup');
        $second = ckoSignupAsaasWebhook('PAYMENT_RECEIVED', $payment, 'evt_signup_dup');

        expect($second->id)->toBe($first->id)
            ->and(WebhookEvent::query()->where('external_event_id', 'evt_signup_dup')->count())->toBe(1);
        Queue::assertPushed(ProcessBillingWebhookJob::class, 2);

        // Os dois jobs rodam (mesmo worker ou em paralelo, um depois do outro).
        app(ProcessWebhookEventService::class)->process($first->fresh());
        $endsAt = $subscription->fresh()->ends_at;
        app(ProcessWebhookEventService::class)->process($first->fresh());

        // O mesmo pagamento chega de novo com OUTRO id de evento (CONFIRMED seguido de RECEIVED, como o Asaas faz no cartão/Pix).
        $other = ckoSignupAsaasWebhook('PAYMENT_CONFIRMED', ['status' => 'CONFIRMED'] + $payment, 'evt_signup_dup_confirmed');
        app(ProcessWebhookEventService::class)->process($other);

        $subscription->refresh();
        $invoice = $subscription->currentInvoice;

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->ends_at->toDateTimeString())->toBe($endsAt->toDateTimeString()) // o período não foi estendido de novo
            ->and($invoice->status)->toBe(InvoiceStatus::Paid)
            ->and(Payment::query()->where('invoice_id', $invoice->id)->where('external_payment_id', 'pay_signup1')->count())->toBe(1)
            ->and(FinancialEvent::query()->where('event_type', BillingEventType::InvoicePaid->value)->where('invoice_id', $invoice->id)->count())->toBe(1)
            ->and(FinancialEvent::query()->where('event_type', BillingEventType::SubscriptionActivated->value)->where('subscription_id', $subscription->id)->count())->toBe(1)
            ->and($first->fresh()->status)->toBe(WebhookEventStatus::Processed);

        Event::assertDispatchedTimes(InvoicePaid::class, 1);
    });

    it('[S3] falha do Asaas na contratação do cadastro: log e billing_logs só com código/status/correlação — nunca CPF, CNPJ, e-mail, telefone, NOME nem ENDEREÇO do corpo (JSON ou bruto)', function (string $scenario) {
        config(['billing.default_gateway' => 'asaas', 'billing.gateways.asaas.webhook_secret' => 'whsec_asaas_signup']);
        [$user, $member] = ckoSignupUser();
        $pii             = ['123.456.789-09', '12345678909', '11.222.333/0001-81', 'maria.pessoal@clinica.test', '11987654321', 'Maria da Silva Pessoal', 'Rua das Flores, 123'];
        $echo            = "O CPF {$pii[0]} ({$pii[1]}), CNPJ {$pii[2]}, e-mail {$pii[3]}, telefone {$pii[4]}, nome {$pii[5]} e endereço {$pii[6]} informados são inválidos.";

        Http::fake(function (Request $request) use ($echo, $scenario) {
            $url = $request->url();

            if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => []]);
                }

                return $scenario === 'customer_json'
                    ? Http::response(['errors' => [['code' => 'invalid_cpfCnpj', 'description' => $echo]]], 400)
                    : Http::response(['id' => 'cus_signup1']);
            }

            if ($url === 'https://api.asaas.com/v3/subscriptions' && $request->method() === 'POST') {
                // Corpo BRUTO (não é o formato {"errors": …}): HTML/texto de um proxy ou do gateway, ecoando o que enviamos.
                return Http::response("<html><body>Bad Request: {$echo}</body></html>", 400);
            }

            return Http::response([], 404);
        });

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message . ' ' . json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });

        ckoAs($user, $member)->postJson(route('signup-checkout.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'pix'])
            ->assertStatus(502)->assertJsonPath('code', 'gateway_error');

        $failure = collect($logged)->first(fn (string $line) => str_contains($line, 'contratação falhou no gateway'));
        expect($failure)->not->toBeNull()
            ->and($failure)->toContain('"trigger":')
            ->and($failure)->toContain('correlation_id')
            ->and($failure)->not->toContain('"error":'); // a mensagem da exceção nunca vai ao log

        if ($scenario === 'customer_json') {
            expect($failure)->toContain('invalid_cpfCnpj'); // o código continua à vista
        }

        // Nenhum log da requisição, nenhum registro de cobrança no banco e nenhuma tentativa guardada carrega o dado.
        $everything = implode("\n", $logged)
            . "\n" . json_encode(DB::table('billing_logs')->get(), JSON_PARTIAL_OUTPUT_ON_ERROR)
            . "\n" . json_encode(DB::table('payment_attempts')->get(), JSON_PARTIAL_OUTPUT_ON_ERROR);

        foreach ($pii as $value) {
            expect(str_contains($everything, $value))->toBeFalse("o valor pessoal '{$value}' apareceu num log/registro");
        }
        expect(str_contains($everything, '<html>'))->toBeFalse('o corpo bruto da resposta foi gravado');
    })->with(['erro JSON do cliente' => ['customer_json'], 'corpo bruto na criação da assinatura' => ['subscription_raw']]);

    it('[L2] cartão Asaas no cadastro: repetir o POST da contratação SEM card_token (chave nova) reabre o mesmo checkout hospedado — nunca card_token_required', function () {
        config(['billing.default_gateway' => 'asaas', 'billing.gateways.asaas.webhook_secret' => 'whsec_asaas_signup']);
        ckoSignupAsaasFake();
        [$user, $member] = ckoSignupUser();
        $body            = ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'credit_card'];

        $first = ckoAs($user, $member)->postJson(route('signup-checkout.contract'), $body + ['card_token' => 'nao-usado', 'idempotency_key' => 'k-1'])
            ->assertOk()->assertJsonPath('data.mode', 'hosted');

        // A 1ª resposta se perdeu: o front repete, agora sem token (o cartão é digitado no ambiente do Asaas) e com chave nova.
        $second = ckoAs($user, $member)->postJson(route('signup-checkout.contract'), $body + ['idempotency_key' => 'k-2'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'hosted')
            ->assertJsonPath('data.recurrent', true)
            ->assertJsonPath('data.checkout_url', 'https://asaas.com/checkoutSession/show?id=chk_signup1');

        expect($second->json('data.subscription.id'))->toBe($first->json('data.subscription.id'))
            ->and(Subscription::query()->forEntity((string) $this->clinic->id)->count())->toBe(1)
            ->and(HostedCheckout::query()->count())->toBe(1);

        // Nada foi criado duas vezes no Asaas: uma recorrência, um checkout.
        expect(Http::recorded(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/subscriptions')->count())->toBe(1)
            ->and(Http::recorded(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/checkouts')->count())->toBe(1);
    });

    it('[L2] a abertura do checkout hospedado falhou DEPOIS de criar a assinatura (502): repetir sem token abre o hospedado e não pede card_token', function () {
        config(['billing.default_gateway' => 'asaas', 'billing.gateways.asaas.webhook_secret' => 'whsec_asaas_signup']);
        $opened = 0;
        ckoSignupAsaasFake(function () use (&$opened) {
            return ++$opened === 1
                ? Http::response(['errors' => [['code' => 'internal_error', 'description' => 'tente de novo']]], 500)
                : Http::response(['id' => 'chk_signup2', 'status' => 'ACTIVE', 'minutesToExpire' => 60, 'link' => 'https://asaas.com/checkoutSession/show?id=chk_signup2']);
        });
        [$user, $member] = ckoSignupUser();
        $body            = ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'credit_card'];

        ckoAs($user, $member)->postJson(route('signup-checkout.contract'), $body + ['card_token' => 'nao-usado', 'idempotency_key' => 'k-1'])
            ->assertStatus(502)->assertJsonPath('code', 'gateway_error');

        ckoAs($user, $member)->postJson(route('signup-checkout.contract'), $body + ['idempotency_key' => 'k-2'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'hosted')
            ->assertJsonPath('data.checkout_url', 'https://asaas.com/checkoutSession/show?id=chk_signup2');

        expect(Subscription::query()->forEntity((string) $this->clinic->id)->count())->toBe(1);
    });

    it('[L2] gateway com cartão transparente (Pagar.me) continua exigindo o token na repetição de uma contratação pendente', function () {
        config(['billing.default_gateway' => 'pagarme']);
        [$user, $member] = ckoSignupUser();
        $trialLess       = Subscription::factory()->gateway('pagarme')->for($this->clinic)->for($this->plan)->create([
            'status'                  => SubscriptionStatus::PastDue,
            'billing_mode'            => SubscriptionBillingMode::Gateway,
            'billing_cycle'           => BillingCycle::Monthly,
            'amount'                  => 299.90,
            'last_payment_at'         => null,
            'next_billing_at'         => '2026-10-08 23:59:59',
            'gateway_subscription_id' => null,
        ]);
        $invoice = Invoice::query()->create([
            'entity_id' => $this->clinic->id, 'subscription_id' => $trialLess->id, 'plan_id' => $this->plan->id, 'gateway_code' => 'pagarme',
            'reference' => 'INV-L2', 'period_start' => '2026-10-05', 'period_end' => '2026-11-05', 'due_at' => '2026-10-08 23:59:59',
            'amount'    => 299.90, 'currency' => 'BRL', 'status' => InvoiceStatus::Pending->value,
        ]);
        $trialLess->update(['current_invoice_id' => $invoice->id]);
        Http::fake();

        ckoAs($user, $member)->postJson(route('signup-checkout.contract'), ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'method' => 'credit_card'])
            ->assertStatus(422)->assertJsonPath('code', 'card_token_required');
    });

    it('o resumo do cadastro continua bloqueado para clínica fora da janela inicial', function () {
        $this->clinic->forceFill(['created_at' => now()->subDays(8)])->save();

        ckoAs()->getJson(route('signup-checkout.summary'))
            ->assertForbidden()
            ->assertJsonPath('code', 'signup_only');
    });

    it('cadastro com start_mode=checkout: cria a clínica sem trial e devolve as rotas do checkout', function () {
        Event::fake([Registered::class]);
        Http::fake();

        $this->postJson('/register', [
            'name'                  => 'Maria Souza',
            'email'                 => 'maria@clinica-nova.test',
            'password'              => 'Password1!',
            'password_confirmation' => 'Password1!',
            'company_name'          => 'Clínica Nova',
            'company_phone'         => '11988887777',
            'plan_id'               => $this->plan->id,
            'billing_cycle'         => 'yearly',
            'start_mode'            => 'checkout',
        ])->assertOk()
            ->assertJsonPath('checkout.contract', '/signup-checkout/contract');

        $user     = User::query()->where('email', 'maria@clinica-nova.test')->firstOrFail();
        $entityId = EntityUser::query()->where('user_id', $user->id)->value('entity_id');

        expect($entityId)->not->toBeNull()
            ->and(Subscription::query()->forEntity((string) $entityId)->count())->toBe(0);
    });
});

describe('manager', function () {
    it('máximo de parcelas do checkout configurável (1 a 12) e aplicado às opções', function () {
        $admin = User::factory()->create();
        $saas  = Entity::factory()->create(['is_client' => false]);
        $link  = createEntityUser($saas, $admin, SaasRule::Admin->value);

        test()->actingAs($admin)->withSession([...panelSession($link), 'selected_entity_is_client' => false])
            ->putJson(route('manager.plans.checkout-settings'), ['max_installments' => 6])
            ->assertOk()
            ->assertJsonPath('data.max_installments', 6);

        expect(CheckoutService::maxInstallments())->toBe(6);

        test()->actingAs($admin)->withSession([...panelSession($link), 'selected_entity_is_client' => false])
            ->putJson(route('manager.plans.checkout-settings'), ['max_installments' => 24])
            ->assertStatus(422);
    });
});
