<?php

use App\DTOs\Billing\{CancelSubscriptionDTO, CancelSubscriptionResultDTO, CreateChargeDTO, CreateSubscriptionDTO, CustomerDTO, GatewayWebhookInputDTO};
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Services\Billing\Gateways\AsaasGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * AsaasGateway contra a API v3 documentada (docs.asaas.com): headers de
 * autenticação, base de sandbox, cliente sem duplicar, POST /v3/subscriptions,
 * DELETE /v3/subscriptions/{id}, formato de erro e mapa de eventos do webhook.
 * Respostas nos formatos dos exemplos da doc.
 */
beforeEach(function () {
    config([
        'billing.gateways.asaas.base_url'       => 'https://api.asaas.com',
        'billing.gateways.asaas.secret'         => '$aact_prod_000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OjAwMDAwMDAwMDAwMDAwMDAwMDA6OiRhYWNoXzAwMDAwMDAw',
        'billing.gateways.asaas.webhook_secret' => 'whsec_asaas_token_com_mais_de_32_caracteres_ok',
        'billing.gateways.asaas.billing_type'   => null,
    ]);
    Http::preventStrayRequests();
});

function agwGateway(): AsaasGateway
{
    return app(AsaasGateway::class);
}

function agwSubscriptionDto(string $interval = 'month', int $count = 1, ?string $description = 'Assinatura Pro'): CreateSubscriptionDTO
{
    return new CreateSubscriptionDTO(
        entityId: '9f1c2d3e-0000-4000-8000-000000000001',
        subscriptionId: '9f1c2d3e-0000-4000-8000-0000000000aa',
        planId: '9f1c2d3e-0000-4000-8000-0000000000bb',
        customerId: 'cus_000005219613',
        amount: 299.90,
        currency: 'BRL',
        interval: $interval,
        intervalCount: $count,
        description: $description,
        firstDueDate: '2026-10-08',
    );
}

/** Resposta de POST /v3/subscriptions (exemplo da doc). */
function agwSubscriptionResponse(Request $request): array
{
    return [
        'object'            => 'subscription',
        'id'                => 'sub_VXJBYgP2u0eO',
        'dateCreated'       => '2026-10-04',
        'customer'          => $request['customer'],
        'paymentLink'       => null,
        'billingType'       => $request['billingType'],
        'cycle'             => $request['cycle'],
        'value'             => $request['value'],
        'nextDueDate'       => $request['nextDueDate'],
        'endDate'           => null,
        'description'       => $request['description'],
        'status'            => 'ACTIVE',
        'discount'          => ['value' => 0, 'dueDateLimitDays' => 0, 'type' => 'FIXED'],
        'fine'              => ['value' => 0, 'type' => 'FIXED'],
        'interest'          => ['value' => 0, 'type' => 'PERCENTAGE'],
        'deleted'           => false,
        'maxPayments'       => null,
        'externalReference' => $request['externalReference'],
    ];
}

function agwWebhook(string $event, array $headers = ['asaas-access-token' => ['whsec_asaas_token_com_mais_de_32_caracteres_ok']]): GatewayWebhookInputDTO
{
    $payload = [
        'id'          => 'evt_05b708f961d739ea7eba7e4db318f621&368604920',
        'event'       => $event,
        'dateCreated' => '2026-10-04 10:00:00',
        'account'     => ['id' => '5d8a8f0e-0000-4000-8000-000000000000', 'ownerId' => null],
        'payment'     => [
            'object'            => 'payment',
            'id'                => 'pay_080225913252',
            'customer'          => 'cus_000005219613',
            'subscription'      => 'sub_VXJBYgP2u0eO',
            'value'             => 299.9,
            'billingType'       => 'UNDEFINED',
            'status'            => 'PENDING',
            'dueDate'           => '2026-10-08',
            'invoiceUrl'        => 'https://www.asaas.com/i/080225913252',
            'bankSlipUrl'       => null,
            'externalReference' => '9f1c2d3e-0000-4000-8000-0000000000aa',
            'deleted'           => false,
        ],
    ];

    return new GatewayWebhookInputDTO(
        gatewayCode: 'asaas',
        headers: $headers,
        body: json_encode($payload),
        payload: $payload,
        externalEventId: $payload['id'],
    );
}

// ── Autenticação e base URL ──────────────────────────────────────────────────

it('autentica com access_token, Content-Type JSON e User-Agent (docs.asaas.com/docs/authentication-2)', function () {
    Http::fake(['https://api.asaas.com/v3/subscriptions' => fn (Request $r) => Http::response(agwSubscriptionResponse($r))]);

    agwGateway()->createSubscription(agwSubscriptionDto());

    Http::assertSent(fn (Request $r) => $r->hasHeader('access_token', config('billing.gateways.asaas.secret'))
        && $r->hasHeader('Content-Type', 'application/json')
        && filled($r->header('User-Agent')[0] ?? null)
        && ! str_starts_with((string) ($r->header('User-Agent')[0] ?? ''), 'GuzzleHttp'));
});

it('chave colada com espaço ou quebra de linha vai sem eles no header', function () {
    config(['billing.gateways.asaas.secret' => "  \$aact_hmlg_chave_de_teste\n"]);
    Http::fake(['https://api.asaas.com/v3/subscriptions' => fn (Request $r) => Http::response(agwSubscriptionResponse($r))]);

    agwGateway()->createSubscription(agwSubscriptionDto());

    Http::assertSent(fn (Request $r) => $r->hasHeader('access_token', '$aact_hmlg_chave_de_teste'));
});

it('base do sandbox copiada da doc com /v3 não vira /v3/v3', function (string $baseUrl) {
    config(['billing.gateways.asaas.base_url' => $baseUrl]);
    Http::fake(['https://api-sandbox.asaas.com/v3/subscriptions' => fn (Request $r) => Http::response(agwSubscriptionResponse($r))]);

    $result = agwGateway()->createSubscription(agwSubscriptionDto());

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api-sandbox.asaas.com/v3/subscriptions');
})->with([
    'sem /v3'       => 'https://api-sandbox.asaas.com',
    'com /v3'       => 'https://api-sandbox.asaas.com/v3',
    'com /v3 e "/"' => 'https://api-sandbox.asaas.com/v3/',
]);

// ── POST /v3/subscriptions ───────────────────────────────────────────────────

it('cria a assinatura com os campos obrigatórios da doc e billingType UNDEFINED (cliente escolhe boleto, Pix ou cartão)', function () {
    Http::fake(['https://api.asaas.com/v3/subscriptions' => fn (Request $r) => Http::response(agwSubscriptionResponse($r))]);

    $result = agwGateway()->createSubscription(agwSubscriptionDto());

    expect($result->success)->toBeTrue()
        ->and($result->externalSubscriptionId)->toBe('sub_VXJBYgP2u0eO')
        ->and($result->externalCustomerId)->toBe('cus_000005219613')
        ->and($result->status)->toBe('active');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.asaas.com/v3/subscriptions'
        && $r->data() === [
            'customer'          => 'cus_000005219613',
            'billingType'       => 'UNDEFINED',
            'value'             => 299.90,
            'nextDueDate'       => '2026-10-08',
            'cycle'             => 'MONTHLY',
            'description'       => 'Assinatura Pro',
            'externalReference' => '9f1c2d3e-0000-4000-8000-0000000000aa',
        ]);
});

it('billing_type do config força uma forma de pagamento; valor fora da doc cai em UNDEFINED', function (?string $configured, string $sent) {
    config(['billing.gateways.asaas.billing_type' => $configured]);
    Http::fake(['https://api.asaas.com/v3/subscriptions' => fn (Request $r) => Http::response(agwSubscriptionResponse($r))]);

    agwGateway()->createSubscription(agwSubscriptionDto());

    Http::assertSent(fn (Request $r) => $r['billingType'] === $sent);
})->with([
    ['BOLETO', 'BOLETO'],
    ['pix', 'PIX'],
    ['CREDIT_CARD', 'CREDIT_CARD'],
    ['DEBIT_CARD', 'UNDEFINED'],
    [null, 'UNDEFINED'],
]);

it('mapeia o ciclo para o enum da doc', function (string $interval, int $count, string $cycle) {
    Http::fake(['https://api.asaas.com/v3/subscriptions' => fn (Request $r) => Http::response(agwSubscriptionResponse($r))]);

    agwGateway()->createSubscription(agwSubscriptionDto($interval, $count));

    Http::assertSent(fn (Request $r) => $r['cycle'] === $cycle);
})->with([
    ['month', 1, 'MONTHLY'],
    ['month', 3, 'QUARTERLY'],
    ['month', 6, 'SEMIANNUALLY'],
    ['month', 12, 'YEARLY'],
    ['year', 1, 'YEARLY'],
]);

it('descrição acima de 500 caracteres é cortada (limite da doc)', function () {
    Http::fake(['https://api.asaas.com/v3/subscriptions' => fn (Request $r) => Http::response(agwSubscriptionResponse($r))]);

    agwGateway()->createSubscription(agwSubscriptionDto(description: str_repeat('a', 700)));

    Http::assertSent(fn (Request $r) => mb_strlen($r['description']) === 500);
});

it('recusa (4xx) devolve o erro no formato errors[].code/description e o status HTTP', function () {
    Http::fake(['https://api.asaas.com/v3/subscriptions' => Http::response([
        'errors' => [['code' => 'invalid_customer', 'description' => 'Cliente inválido ou não informado.']],
    ], 400)]);

    $result = agwGateway()->createSubscription(agwSubscriptionDto());

    expect($result->success)->toBeFalse()
        ->and($result->httpStatus)->toBe(400)
        ->and($result->errorMessage)->toBe('HTTP 400 invalid_customer: Cliente inválido ou não informado.');
});

it('2xx sem id não conta como criada e deixa o status nulo (o orquestrador confere pela referência)', function () {
    Http::fake(['https://api.asaas.com/v3/subscriptions' => Http::response(['object' => 'subscription'])]);

    $result = agwGateway()->createSubscription(agwSubscriptionDto());

    expect($result->success)->toBeFalse()
        ->and($result->externalSubscriptionId)->toBeNull()
        ->and($result->httpStatus)->toBeNull();
});

it('429 (limite de requisições) volta como falha 4xx — não é tratado como "pode ter criado"', function () {
    Http::fake(['https://api.asaas.com/v3/subscriptions' => Http::response(['errors' => [['code' => 'too_many_requests', 'description' => 'Limite atingido.']]], 429, ['RateLimit-Remaining' => '0', 'RateLimit-Reset' => '30'])]);

    $result = agwGateway()->createSubscription(agwSubscriptionDto());

    expect($result->success)->toBeFalse()->and($result->httpStatus)->toBe(429);
});

// ── Cobrança avulsa ──────────────────────────────────────────────────────────

it('cobrança avulsa sem forma de pagamento usa a mesma forma padrão (UNDEFINED) e devolve o invoiceUrl', function () {
    Http::fake(['https://api.asaas.com/v3/payments' => fn (Request $r) => Http::response([
        'object'            => 'payment', 'id' => 'pay_080225913252', 'customer' => $r['customer'], 'billingType' => $r['billingType'],
        'value'             => $r['value'], 'status' => 'PENDING', 'dueDate' => $r['dueDate'],
        'invoiceUrl'        => 'https://www.asaas.com/i/080225913252', 'bankSlipUrl' => null,
        'externalReference' => $r['externalReference'], 'deleted' => false,
    ])]);

    $result = agwGateway()->createCharge(new CreateChargeDTO(
        entityId: '9f1c2d3e-0000-4000-8000-000000000001',
        invoiceId: '9f1c2d3e-0000-4000-8000-0000000000cc',
        subscriptionId: '9f1c2d3e-0000-4000-8000-0000000000aa',
        customerId: 'cus_000005219613',
        amount: 299.90,
        currency: 'BRL',
        description: 'Fatura INV-1',
        dueDate: '2026-10-08',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe('pending')
        ->and($result->paymentUrl)->toBe('https://www.asaas.com/i/080225913252');
    Http::assertSent(fn (Request $r) => $r['billingType'] === 'UNDEFINED' && $r['externalReference'] === '9f1c2d3e-0000-4000-8000-0000000000cc');
});

// ── Clientes ─────────────────────────────────────────────────────────────────

function agwCustomer(?string $document = '12.345.678/0001-95', ?string $phone = '(11) 98765-4321'): CustomerDTO
{
    return new CustomerDTO(
        entityId: '9f1c2d3e-0000-4000-8000-000000000001',
        name: 'Clínica Olhar',
        email: 'financeiro@olhar.test',
        document: $document,
        phone: $phone,
        externalReference: '9f1c2d3e-0000-4000-8000-000000000001',
    );
}

/** @param list<array<string, mixed>> $found */
function agwFakeCustomers(array $found): void
{
    Http::fake(function (Request $r) use ($found) {
        if ($r->method() === 'GET' && str_starts_with($r->url(), 'https://api.asaas.com/v3/customers?')) {
            return Http::response(['object' => 'list', 'hasMore' => false, 'totalCount' => count($found), 'limit' => 10, 'offset' => 0, 'data' => $found]);
        }

        if ($r->method() === 'POST' && $r->url() === 'https://api.asaas.com/v3/customers') {
            return Http::response(['object' => 'customer', 'id' => 'cus_novo', 'name' => $r['name'], 'cpfCnpj' => $r->data()['cpfCnpj'] ?? null, 'deleted' => false]);
        }

        return Http::response(['errors' => [['code' => 'not_faked']]], 404);
    });
}

it('reaproveita o cliente achado pelo CPF/CNPJ, preferindo o que tem a nossa referência', function () {
    agwFakeCustomers([
        ['object' => 'customer', 'id' => 'cus_outro', 'cpfCnpj' => '12345678000195', 'externalReference' => null, 'deleted' => false],
        ['object' => 'customer', 'id' => 'cus_nosso', 'cpfCnpj' => '12345678000195', 'externalReference' => '9f1c2d3e-0000-4000-8000-000000000001', 'deleted' => false],
    ]);

    expect(agwGateway()->upsertCustomer(agwCustomer()))->toBe('cus_nosso');
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r['cpfCnpj'] === '12345678000195');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('cliente removido no Asaas não é reaproveitado: cria outro', function () {
    agwFakeCustomers([
        ['object' => 'customer', 'id' => 'cus_removido', 'cpfCnpj' => '12345678000195', 'deleted' => true],
    ]);

    expect(agwGateway()->upsertCustomer(agwCustomer()))->toBe('cus_novo');
});

it('sem documento procura pela externalReference antes de criar', function () {
    agwFakeCustomers([]);

    agwGateway()->upsertCustomer(agwCustomer(document: null));

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r['externalReference'] === '9f1c2d3e-0000-4000-8000-000000000001');
});

it('celular vai em mobilePhone e fixo em phone (fixo em mobilePhone é recusado)', function (?string $phone, array $expected) {
    agwFakeCustomers([]);

    agwGateway()->upsertCustomer(agwCustomer(phone: $phone));

    Http::assertSent(function (Request $r) use ($expected) {
        if ($r->method() !== 'POST') {
            return false;
        }

        $data = $r->data();

        return array_intersect_key($data, ['mobilePhone' => 1, 'phone' => 1]) === $expected
            && $data['cpfCnpj'] === '12345678000195'
            && $data['externalReference'] === '9f1c2d3e-0000-4000-8000-000000000001';
    });
})->with([
    'celular'         => ['(11) 98765-4321', ['mobilePhone' => '11987654321']],
    'celular com +55' => ['+55 11 98765-4321', ['mobilePhone' => '11987654321']],
    'fixo'            => ['(11) 3456-7890', ['phone' => '1134567890']],
    'inválido'        => ['12345', []],
    'sem telefone'    => [null, []],
]);

it('falha na criação do cliente vira exceção com a descrição do Asaas', function () {
    Http::fake(function (Request $r) {
        return $r->method() === 'GET'
            ? Http::response(['object' => 'list', 'hasMore' => false, 'totalCount' => 0, 'data' => []])
            : Http::response(['errors' => [['code' => 'invalid_cpfCnpj', 'description' => 'O CPF/CNPJ informado é inválido.']]], 400);
    });

    expect(fn () => agwGateway()->upsertCustomer(agwCustomer()))
        ->toThrow(GatewayIntegrationException::class, 'O CPF/CNPJ informado é inválido.');
});

// ── DELETE /v3/subscriptions/{id} ────────────────────────────────────────────

function agwCancel(): CancelSubscriptionResultDTO
{
    return agwGateway()->cancelSubscription(new CancelSubscriptionDTO(
        subscriptionId: '9f1c2d3e-0000-4000-8000-0000000000aa',
        externalSubscriptionId: 'sub_VXJBYgP2u0eO',
        entityId: '9f1c2d3e-0000-4000-8000-000000000001',
    ));
}

it('cancela com DELETE /v3/subscriptions/{id} e lê {"deleted": true}', function () {
    Http::fake(['https://api.asaas.com/v3/subscriptions/sub_VXJBYgP2u0eO' => Http::response(['deleted' => true, 'id' => 'sub_VXJBYgP2u0eO'])]);

    $result = agwCancel();

    expect($result->success)->toBeTrue()->and($result->status)->toBe('cancelled');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.asaas.com/v3/subscriptions/sub_VXJBYgP2u0eO');
});

it('404 no DELETE de recorrência já removida (consulta devolve deleted=true) conta como cancelada', function () {
    Http::fake(fn (Request $r) => $r->method() === 'DELETE'
        ? Http::response(['errors' => [['code' => 'not_found', 'description' => 'Assinatura não encontrada.']]], 404)
        : Http::response(['object' => 'subscription', 'id' => 'sub_VXJBYgP2u0eO', 'status' => 'INACTIVE', 'deleted' => true]));

    expect(agwCancel()->success)->toBeTrue();
});

it('404 no DELETE sem confirmação de que foi removida (ex.: URL errada) segue como falha', function () {
    Http::fake(fn () => Http::response(['errors' => [['code' => 'not_found', 'description' => 'Não encontrado.']]], 404));

    $result = agwCancel();

    expect($result->success)->toBeFalse()
        ->and($result->errorMessage)->toBe('HTTP 404 not_found: Não encontrado.');
});

it('5xx no DELETE é falha (o job tenta de novo)', function () {
    Http::fake(fn () => Http::response('<html>Bad Gateway</html>', 502));

    expect(agwCancel()->success)->toBeFalse();
});

// ── Webhook ──────────────────────────────────────────────────────────────────

it('valida o token do header asaas-access-token (fail-closed sem token configurado)', function () {
    expect(agwGateway()->validateWebhookSignature(agwWebhook('PAYMENT_RECEIVED')))->toBeTrue()
        ->and(agwGateway()->validateWebhookSignature(agwWebhook('PAYMENT_RECEIVED', ['asaas-access-token' => ['outro_token']])))->toBeFalse()
        ->and(agwGateway()->validateWebhookSignature(agwWebhook('PAYMENT_RECEIVED', [])))->toBeFalse();

    config(['billing.gateways.asaas.webhook_secret' => null]);

    expect(agwGateway()->validateWebhookSignature(agwWebhook('PAYMENT_RECEIVED')))->toBeFalse();
});

it('mapeia cada evento de cobrança e de assinatura documentado', function (string $event, string $normalized) {
    expect(agwGateway()->parseWebhook(agwWebhook($event))->eventType)->toBe($normalized);
})->with([
    ['PAYMENT_CREATED', 'created'],
    ['PAYMENT_UPDATED', 'created'],
    ['PAYMENT_RESTORED', 'created'],
    ['PAYMENT_AWAITING_RISK_ANALYSIS', 'unknown'],
    ['PAYMENT_APPROVED_BY_RISK_ANALYSIS', 'unknown'],
    ['PAYMENT_REPROVED_BY_RISK_ANALYSIS', 'failed'],
    ['PAYMENT_AUTHORIZED', 'unknown'],
    ['PAYMENT_CONFIRMED', 'paid'],
    ['PAYMENT_RECEIVED', 'paid'],
    ['PAYMENT_ANTICIPATED', 'paid'],
    ['PAYMENT_DUNNING_RECEIVED', 'paid'],
    ['PAYMENT_CREDIT_CARD_CAPTURE_REFUSED', 'failed'],
    ['PAYMENT_OVERDUE', 'overdue'],
    ['PAYMENT_DELETED', 'payment_cancelled'],
    ['PAYMENT_REFUNDED', 'refunded'],
    ['PAYMENT_PARTIALLY_REFUNDED', 'partially_refunded'],
    ['PAYMENT_REFUND_IN_PROGRESS', 'refund_in_progress'],
    ['PAYMENT_REFUND_DENIED', 'refund_denied'],
    ['PAYMENT_RECEIVED_IN_CASH_UNDONE', 'refunded'],
    ['PAYMENT_CHARGEBACK_REQUESTED', 'chargeback'],
    ['PAYMENT_CHARGEBACK_DISPUTE', 'chargeback'],
    ['PAYMENT_AWAITING_CHARGEBACK_REVERSAL', 'unknown'],
    ['PAYMENT_DUNNING_REQUESTED', 'unknown'],
    ['PAYMENT_BANK_SLIP_CANCELLED', 'instructions_invalidated'],
    ['PAYMENT_BANK_SLIP_VIEWED', 'unknown'],
    ['PAYMENT_CHECKOUT_VIEWED', 'unknown'],
    ['PAYMENT_SPLIT_CANCELLED', 'unknown'],
    ['PAYMENT_SPLIT_DIVERGENCE_BLOCK', 'unknown'],
    ['PAYMENT_SPLIT_DIVERGENCE_BLOCK_FINISHED', 'unknown'],
    ['SUBSCRIPTION_CREATED', 'subscription_created'],
    ['SUBSCRIPTION_UPDATED', 'subscription_updated'],
    ['SUBSCRIPTION_INACTIVATED', 'cancelled'],
    ['SUBSCRIPTION_DELETED', 'cancelled'],
    ['SUBSCRIPTION_SPLIT_DISABLED', 'unknown'],
    ['CHECKOUT_CREATED', 'checkout_created'],
    ['CHECKOUT_PAID', 'checkout_paid'],
    ['CHECKOUT_CANCELED', 'checkout_canceled'],
    ['CHECKOUT_EXPIRED', 'checkout_expired'],
    ['ACCESS_TOKEN_CREATED', 'unknown'],
    ['ACCESS_TOKEN_ENABLED', 'unknown'],
    ['ACCESS_TOKEN_DISABLED', 'access_token_alert'],
    ['ACCESS_TOKEN_DELETED', 'access_token_alert'],
    ['ACCESS_TOKEN_EXPIRING_SOON', 'access_token_alert'],
    ['ACCESS_TOKEN_EXPIRED', 'access_token_alert'],
    ['EVENTO_QUE_NAO_EXISTE', 'unknown'],
]);

it('evento de cobrança: ids, valor, vencimento, link da fatura e a referência da assinatura', function () {
    $n = agwGateway()->parseWebhook(agwWebhook('PAYMENT_CREATED'));

    expect($n->externalSubscriptionId)->toBe('sub_VXJBYgP2u0eO')
        ->and($n->externalPaymentId)->toBe('pay_080225913252')
        ->and($n->externalEventId)->toBe('evt_05b708f961d739ea7eba7e4db318f621&368604920')
        ->and($n->amount)->toBe(299.9)
        ->and($n->status)->toBe('pending')
        ->and($n->dueDate)->toBe('2026-10-08')
        ->and($n->paymentUrl)->toBe('https://www.asaas.com/i/080225913252')
        ->and($n->metadata)->toBe(['subscription_id' => '9f1c2d3e-0000-4000-8000-0000000000aa', 'billing_type' => 'UNDEFINED'])
        // occurredAt = dateCreated do evento (horário de Brasília), não a hora do processamento.
        ->and(CarbonImmutable::parse($n->occurredAt)->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'))->toBe('2026-10-04 10:00:00');
});

it('evento SUBSCRIPTION_* usa só o id da recorrência — nunca a externalReference', function () {
    $payload = [
        'id'           => 'evt_6561b631fa5580caadd00bbe3b858607&9193',
        'event'        => 'SUBSCRIPTION_DELETED',
        'dateCreated'  => '2026-10-04 11:11:04',
        'account'      => ['id' => '5d8a8f0e-0000-4000-8000-000000000000', 'ownerId' => null],
        'subscription' => [
            'object'            => 'subscription',
            'id'                => 'sub_duplicada',
            'status'            => 'INACTIVE',
            'deleted'           => true,
            'externalReference' => '9f1c2d3e-0000-4000-8000-0000000000aa',
        ],
    ];

    $n = agwGateway()->parseWebhook(new GatewayWebhookInputDTO('asaas', [], json_encode($payload), $payload, $payload['id']));

    expect($n->eventType)->toBe('cancelled')
        ->and($n->externalSubscriptionId)->toBe('sub_duplicada')
        ->and($n->externalPaymentId)->toBeNull()
        ->and($n->status)->toBeNull()
        // Só os dados da recorrência — nada de subscription_id/invoice_id pela referência.
        ->and($n->metadata)->toBe(['recurrence' => ['status' => 'INACTIVE', 'deleted' => true]]);
});
