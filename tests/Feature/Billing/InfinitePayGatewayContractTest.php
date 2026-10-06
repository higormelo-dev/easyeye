<?php

use App\DTOs\Billing\{CancelSubscriptionDTO, CreateChargeDTO, CreateSubscriptionDTO, CustomerDTO, GatewayWebhookInputDTO};
use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\Billing\{GatewayIntegrationException, GatewayUnauthorizedException};
use App\Jobs\Billing\{ProcessBillingWebhookJob, RenewSubscriptionJob};
use App\Models\Billing\{Invoice, Payment};
use App\Models\{Entity, Plan, Subscription};
use App\Services\Billing\{GatewayCredentialResolver, WebhookIngestionService};
use App\Services\Billing\Gateways\InfinitePayGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\Http;

/**
 * Contrato do InfinitePay com a API pública do Checkout Integrado:
 *   POST https://api.checkout.infinitepay.io/links          → {"url": "..."}
 *   POST https://api.checkout.infinitepay.io/payment_check  → {"success", "paid", "amount", ...}
 *   webhook (só na aprovação): {invoice_slug, amount, paid_amount, installments,
 *   capture_method, transaction_nsu, order_nsu, receipt_url, items}
 * Fontes: https://www.infinitepay.io/checkout-documentacao e
 * https://ajuda.infinitepay.io/pt-BR/articles/10766888-como-usar-o-checkout-integrado-da-infinitepay
 */
const IPC_LINKS         = 'https://api.checkout.infinitepay.io/links';
const IPC_PAYMENT_CHECK = 'https://api.checkout.infinitepay.io/payment_check';
const IPC_CHECKOUT_URL  = 'https://checkout.infinitepay.com.br/easyeye?lenc=G7xQ2kP9';

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'app.key'                                     => 'base64:juDvxil36quACCVJwN9w7PXo4rdPcNfYnb1nlunUQgo=',
        'billing.gateways.infinitepay.handle'         => null,
        'billing.gateways.infinitepay.secret'         => null,
        'billing.gateways.infinitepay.webhook_secret' => null,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/** Gateway com credenciais controladas (sem banco). */
function ipcGateway(array $extras = ['handle' => 'easyeye'], ?string $secret = null): InfinitePayGateway
{
    $resolver = new class($extras, $secret) extends GatewayCredentialResolver {
        public function __construct(private array $extras, private ?string $secret)
        {
            parent::__construct(0);
        }

        public function resolveSecret(string $gatewayCode): ?string
        {
            return $this->secret;
        }

        public function resolveWebhookSecret(string $gatewayCode): ?string
        {
            return null;
        }

        public function resolveExtra(string $gatewayCode, string $key): ?string
        {
            return $this->extras[$key] ?? null;
        }
    };

    return new InfinitePayGateway($resolver);
}

function ipcCharge(array $overrides = []): CreateChargeDTO
{
    return new CreateChargeDTO(...[
        'entityId'       => (string) Str::uuid(),
        'invoiceId'      => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01',
        'subscriptionId' => (string) Str::uuid(),
        'customerId'     => 'cus_local',
        'amount'         => 299.90,
        'currency'       => 'BRL',
        'description'    => 'Fatura INV-2026-0001',
        'dueDate'        => '2026-10-08',
        'metadata'       => ['customer_name' => 'Clínica Olhar', 'email' => 'financeiro@olhar.com.br', 'document' => '12.345.678/0001-90', 'phone' => '(11) 99988-7766'],
        'idempotencyKey' => 'renew:sub:2026-10-08:1',
        ...$overrides,
    ]);
}

/** Cria um link (fake) e devolve o order_nsu que o gateway mandou. */
function ipcIssuedOrderNsu(array $overrides = []): string
{
    Http::fake([IPC_LINKS => Http::response(['url' => IPC_CHECKOUT_URL])]);

    return (string) ipcGateway()->createCharge(ipcCharge($overrides))->externalPaymentId;
}

/** Webhook no formato da documentação. */
function ipcWebhook(string $orderNsu, array $overrides = []): array
{
    return [
        'invoice_slug'    => 'abc123',
        'amount'          => 29990,
        'paid_amount'     => 29990,
        'installments'    => 1,
        'capture_method'  => 'pix',
        'transaction_nsu' => '5f1d8c2e-3a4b-4c5d-8e9f-0a1b2c3d4e5f',
        'order_nsu'       => $orderNsu,
        'receipt_url'     => 'https://comprovante.infinitepay.io/abc123',
        'items'           => [['quantity' => 1, 'price' => 29990, 'description' => 'Fatura INV-2026-0001']],
        ...$overrides,
    ];
}

function ipcInput(array $payload): GatewayWebhookInputDTO
{
    return new GatewayWebhookInputDTO(
        gatewayCode: 'infinitepay',
        headers: [],
        body: json_encode($payload),
        payload: $payload,
    );
}

// ── Link de pagamento (createCharge) ─────────────────────────────────────────

it('cria o link no checkout com handle, item em centavos, order_nsu assinado e webhook_url, e devolve o link pagável', function () {
    Http::fake([IPC_LINKS => Http::response(['url' => IPC_CHECKOUT_URL])]);

    $result = ipcGateway()->createCharge(ipcCharge());

    expect($result->success)->toBeTrue()
        ->and($result->paymentUrl)->toBe(IPC_CHECKOUT_URL)
        ->and($result->status)->toBe('pending')
        ->and($result->amount)->toBe(299.90)
        ->and($result->externalPaymentId)->toMatch('/^0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01\.29990\.[0-9a-f]{8}\.[0-9a-f]{16}$/');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->url() === IPC_LINKS
        && $r->method() === 'POST'
        && $r['handle'] === 'easyeye'
        && $r['items'] === [['quantity' => 1, 'price' => 29990, 'description' => 'Fatura INV-2026-0001']]
        && $r['order_nsu'] === $result->externalPaymentId
        && $r['webhook_url'] === route('billing.webhooks', ['gateway' => 'infinitepay'])
        && $r['customer'] === ['name' => 'Clínica Olhar', 'email' => 'financeiro@olhar.com.br', 'phone_number' => '+5511999887766']
        // Só os campos da documentação do checkout (nada de amount/due_date/metadata).
        && array_keys($r->data()) === ['handle', 'items', 'order_nsu', 'webhook_url', 'customer']
        // A documentação não usa token: nenhum segredo no header.
        && ! $r->hasHeader('Authorization'));
});

it('a InfiniteTag aceita "$" na frente e cai para o campo secret quando não há handle', function () {
    Http::fake([IPC_LINKS => Http::response(['url' => IPC_CHECKOUT_URL])]);

    ipcGateway(extras: [], secret: '$minha_clinica')->createCharge(ipcCharge());

    Http::assertSent(fn (Request $r) => $r['handle'] === 'minha_clinica');
});

it('sem InfiniteTag (ou inválida) falha clara antes de chamar a InfinitePay', function (array $extras, ?string $secret) {
    Http::fake();

    expect(fn () => ipcGateway($extras, $secret)->createCharge(ipcCharge()))
        ->toThrow(GatewayIntegrationException::class, 'handle');

    Http::assertNothingSent();
})->with([
    'ausente'        => [[], null],
    'com espaço'     => [['handle' => 'minha clinica'], null],
    'token no lugar' => [[], 'eyJhbGciOiJIUzI1NiJ9 com espaço'],
]);

it('valor zero, moeda diferente de BRL e fatura sem UUID não viram link', function (array $overrides) {
    Http::fake();

    expect(fn () => ipcGateway()->createCharge(ipcCharge($overrides)))->toThrow(GatewayIntegrationException::class);

    Http::assertNothingSent();
})->with([
    'valor zero'   => [['amount' => 0.0]],
    'USD'          => [['currency' => 'USD']],
    'fatura texto' => [['invoiceId' => 'INV-1']],
]);

it('erro HTTP da InfinitePay volta como falha com o status', function () {
    Http::fake([IPC_LINKS => Http::response(['message' => 'handle not found'], 422)]);

    $result = ipcGateway()->createCharge(ipcCharge());

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('422')
        ->and($result->errorMessage)->toContain('handle not found')
        ->and($result->paymentUrl)->toBeNull();
});

it('2xx sem link https utilizável é falha, nunca cobrança pendente sem link', function (array $body) {
    Http::fake([IPC_LINKS => Http::response($body)]);

    $result = ipcGateway()->createCharge(ipcCharge());

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('invalid_response')
        ->and($result->externalPaymentId)->toBeNull();
})->with([
    'sem url'     => [['id' => 'qualquer']],
    'javascript:' => [['url' => 'javascript:alert(1)']],
    'http'        => [['url' => 'http://checkout.infinitepay.com.br/easyeye?lenc=x']],
]);

it('a mesma chave de idempotência gera o mesmo order_nsu; outra tentativa, outro', function () {
    $first  = ipcIssuedOrderNsu();
    $replay = ipcIssuedOrderNsu();
    $retry  = ipcIssuedOrderNsu(['idempotencyKey' => 'renew:sub:2026-10-08:2']);

    expect($replay)->toBe($first)
        ->and($retry)->not->toBe($first)
        ->and(Str::before($retry, '.'))->toBe(Str::before($first, '.'));
});

// ── Webhook ──────────────────────────────────────────────────────────────────

it('aceita o webhook no formato da documentação com o order_nsu emitido', function () {
    $orderNsu = ipcIssuedOrderNsu();

    expect(ipcGateway()->validateWebhookSignature(ipcInput(ipcWebhook($orderNsu))))->toBeTrue();
});

it('rejeita webhook forjado: valor diferente, order_nsu adulterado, campos faltando, formato antigo ou sem handle', function () {
    $orderNsu = ipcIssuedOrderNsu();
    $gateway  = ipcGateway();
    $tampered = Str::replace('.29990.', '.100.', $orderNsu);

    expect($gateway->validateWebhookSignature(ipcInput(ipcWebhook($orderNsu, ['amount' => 100]))))->toBeFalse()
        ->and($gateway->validateWebhookSignature(ipcInput(ipcWebhook($tampered, ['amount' => 100]))))->toBeFalse()
        ->and($gateway->validateWebhookSignature(ipcInput(ipcWebhook('0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01'))))->toBeFalse()
        ->and($gateway->validateWebhookSignature(ipcInput(ipcWebhook($orderNsu, ['transaction_nsu' => '']))))->toBeFalse()
        ->and($gateway->validateWebhookSignature(ipcInput(ipcWebhook($orderNsu, ['invoice_slug' => null]))))->toBeFalse()
        ->and($gateway->validateWebhookSignature(ipcInput(['event' => 'charge.approved', 'data' => ['id' => 'ch_1', 'status' => 'approved', 'amount' => 299.90]])))->toBeFalse()
        ->and(ipcGateway(extras: [])->validateWebhookSignature(ipcInput(ipcWebhook($orderNsu))))->toBeFalse();
});

it('confirma no payment_check antes de normalizar como pago', function () {
    $orderNsu = ipcIssuedOrderNsu();

    Http::fake([IPC_PAYMENT_CHECK => Http::response([
        'success'        => true,
        'paid'           => true,
        'amount'         => 29990,
        'paid_amount'    => 30510,
        'installments'   => 3,
        'capture_method' => 'credit_card',
    ])]);

    $event = ipcGateway()->parseWebhook(ipcInput(ipcWebhook($orderNsu, ['capture_method' => 'credit_card', 'installments' => 3, 'paid_amount' => 30510])));

    expect($event->eventType)->toBe('paid')
        ->and($event->status)->toBe('paid')
        ->and($event->externalPaymentId)->toBe($orderNsu)
        ->and($event->externalSubscriptionId)->toBeNull()
        ->and($event->amount)->toBe(299.90)
        ->and($event->currency)->toBe('BRL')
        ->and($event->paymentUrl)->toBeNull()
        ->and($event->metadata)->toMatchArray([
            'invoice_id'      => '0199b0a4-7c11-7d2e-9f3a-5b6c7d8e9f01',
            'transaction_nsu' => '5f1d8c2e-3a4b-4c5d-8e9f-0a1b2c3d4e5f',
            'invoice_slug'    => 'abc123',
            'capture_method'  => 'credit_card',
            'installments'    => 3,
            'paid_amount'     => 305.10,
            'receipt_url'     => 'https://comprovante.infinitepay.io/abc123',
        ]);

    Http::assertSent(fn (Request $r) => $r->url() === IPC_PAYMENT_CHECK
        && $r->data() === [
            'handle'          => 'easyeye',
            'order_nsu'       => $orderNsu,
            'transaction_nsu' => '5f1d8c2e-3a4b-4c5d-8e9f-0a1b2c3d4e5f',
            'slug'            => 'abc123',
        ]);
});

it('sem confirmação no payment_check o processamento falha (e é retentado), nunca vira pago', function (mixed $check) {
    $orderNsu = ipcIssuedOrderNsu();

    Http::fake([IPC_PAYMENT_CHECK => is_int($check) ? Http::response(['message' => 'erro'], $check) : Http::response($check)]);

    expect(fn () => ipcGateway()->parseWebhook(ipcInput(ipcWebhook($orderNsu))))->toThrow(GatewayIntegrationException::class);
})->with([
    'não pago'         => [['success' => true, 'paid' => false, 'amount' => 29990]],
    'não encontrado'   => [['success' => false, 'paid' => false]],
    'valor diferente'  => [['success' => true, 'paid' => true, 'amount' => 100]],
    'InfinitePay fora' => [503],
]);

it('chave do webhook: o reenvio repete a chave; outra transação do mesmo pedido, outra chave', function () {
    $orderNsu = ipcIssuedOrderNsu();
    $gateway  = ipcGateway();

    $key = $gateway->webhookEventKey(ipcWebhook($orderNsu));

    expect($key)->toBe($gateway->webhookEventKey(ipcWebhook($orderNsu)))
        ->and($key)->toContain($orderNsu)
        ->and($gateway->webhookEventKey(ipcWebhook($orderNsu, ['transaction_nsu' => 'outra-transacao'])))->not->toBe($key)
        ->and($gateway->webhookEventKey(['amount' => 29990]))->toBeNull();
});

// ── O que a API pública não tem ──────────────────────────────────────────────

it('sem clientes, recorrência nem consulta por id na API: renovação local e falha clara na consulta', function () {
    Http::fake();
    $gateway = ipcGateway();

    $subscription = $gateway->createSubscription(new CreateSubscriptionDTO(
        entityId: (string) Str::uuid(),
        subscriptionId: (string) Str::uuid(),
        planId: (string) Str::uuid(),
        customerId: 'cus_local',
        amount: 299.90,
        currency: 'BRL',
        interval: 'month',
    ));

    expect($gateway->upsertCustomer(new CustomerDTO(entityId: 'ent-1', name: 'Clínica', email: null, document: null, phone: null)))->toBe('ent-1')
        ->and($subscription->success)->toBeTrue()
        ->and($subscription->externalSubscriptionId)->toBeNull()
        ->and($gateway->subscriptionIssuesFirstCharge())->toBeFalse()
        ->and($gateway->cancelSubscription(new CancelSubscriptionDTO(entityId: 'ent-1', subscriptionId: 'sub-1', externalSubscriptionId: 'x'))->success)->toBeTrue()
        ->and(fn () => $gateway->fetchPayment('qualquer'))->toThrow(GatewayIntegrationException::class)
        ->and($gateway->healthCheck()->healthy)->toBeTrue()
        ->and(ipcGateway(extras: [])->healthCheck()->healthy)->toBeFalse();

    Http::assertNothingSent();
});

// ── Ponta a ponta: renovação local → link na fatura → webhook → renovada ─────

it('renovação emite o link (fatura com payment_url) e o webhook confirmado no payment_check renova o período', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 01:00:00'));
    config(['billing.gateways.infinitepay.handle' => 'easyeye']);

    $plan                  = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 349.90, 'active' => true]);
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $subscription = Subscription::factory()->for($entity)->for($plan)->create([
        'billing_mode'            => SubscriptionBillingMode::Gateway,
        'status'                  => SubscriptionStatus::Active,
        'billing_state'           => 'paid',
        'gateway'                 => 'infinitepay',
        'pinned_gateway'          => 'infinitepay',
        'gateway_subscription_id' => null,
        'billing_cycle'           => BillingCycle::Monthly,
        'amount'                  => 349.90,
        'starts_at'               => '2026-09-08 00:00:00',
        'ends_at'                 => '2026-10-08 23:59:59',
        'next_billing_at'         => '2026-10-08 23:59:59',
        'last_payment_at'         => '2026-09-07 15:00:00',
    ]);

    Http::fake([
        IPC_LINKS         => Http::response(['url' => IPC_CHECKOUT_URL]),
        IPC_PAYMENT_CHECK => Http::response(['success' => true, 'paid' => true, 'amount' => 34990, 'paid_amount' => 34990, 'installments' => 1, 'capture_method' => 'pix']),
    ]);

    RenewSubscriptionJob::dispatchSync((string) $subscription->id);

    $invoice  = Invoice::sole();
    $orderNsu = (string) Payment::sole()->external_payment_id;

    expect($invoice->payment_url)->toBe(IPC_CHECKOUT_URL)
        ->and($invoice->status)->toBe(InvoiceStatus::Pending)
        ->and($invoice->external_invoice_id)->toBe($orderNsu)
        ->and(Str::before($orderNsu, '.'))->toBe($invoice->id);

    $ingest = fn (array $payload) => app(WebhookIngestionService::class)
        ->ingest('infinitepay', ['content-type' => ['application/json']], json_encode($payload));

    // Link criado por terceiros com o nosso handle e outro valor: rejeitado na entrada.
    expect(fn () => $ingest(ipcWebhook($orderNsu, ['amount' => 100, 'paid_amount' => 100])))
        ->toThrow(GatewayUnauthorizedException::class);

    $event = $ingest(ipcWebhook($orderNsu, ['amount' => 34990, 'paid_amount' => 34990]));
    ProcessBillingWebhookJob::dispatchSync((string) $event->id);

    $subscription->refresh();

    expect($event->fresh()->normalized_payload['outcome'])->toBe('renewed')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Paid)
        ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-11-08 23:59:59');

    // Reenvio da mesma notificação: o mesmo evento, nada reprocessado.
    expect($ingest(ipcWebhook($orderNsu, ['amount' => 34990, 'paid_amount' => 34990]))->id)->toBe($event->id);
});
