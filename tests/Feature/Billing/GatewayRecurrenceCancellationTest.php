<?php

use App\Enums\Billing\CancellationReason;
use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Jobs\Billing\CancelGatewaySubscriptionJob;
use App\Models\{Entity, Plan, Subscription};
use App\Services\Billing\{BillingCancellationService, BillingLogService, BillingSubscriptionOrchestrator, FinancialEventService, GatewayRegistry};
use Illuminate\Support\Facades\{Http, Queue};

beforeEach(fn () => Http::preventStrayRequests());

/**
 * Cancelar no gateway nunca funcionava: o CancelSubscriptionDTO recebia um
 * argumento nomeado que não existe (externalCustomerId) — Error na chamada
 * síncrona e em todas as tentativas do job, e o cliente seguia sendo cobrado.
 */
function gwCancelClinic(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

it('cancelamento pelo manager chama o DELETE do gateway na hora, sem cair no job', function () {
    Queue::fake();
    Http::fake(['https://api.asaas.com/v3/subscriptions/*' => Http::response(['deleted' => true], 200)]);

    $entity       = gwCancelClinic();
    $subscription = Subscription::factory()->gateway()->for($entity)->create(['gateway_subscription_id' => 'sub_abc123']);

    app(BillingCancellationService::class)->cancel($subscription, $entity, CancellationReason::AdminAction, 'manager');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v3/subscriptions/sub_abc123'));
    Queue::assertNotPushed(CancelGatewaySubscriptionJob::class);
});

it('o job de cancelamento (retentativa) também chega ao gateway', function () {
    Http::fake(['https://api.asaas.com/v3/subscriptions/*' => Http::response(['deleted' => true], 200)]);

    $subscription = Subscription::factory()->gateway()->for(gwCancelClinic())->create(['gateway_subscription_id' => 'sub_job789']);

    (new CancelGatewaySubscriptionJob((string) $subscription->id, 'asaas', (string) str()->uuid()))
        ->handle(app(GatewayRegistry::class), app(FinancialEventService::class), app(BillingLogService::class));

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v3/subscriptions/sub_job789'));
});

it('trocar o plano de uma assinatura cobrada cancela a recorrência antiga no gateway', function () {
    $entity = gwCancelClinic();
    $plan   = Plan::factory()->create(['price' => 99.90]);
    $old    = Subscription::factory()->gateway()->for($entity)->create(['gateway_subscription_id' => 'sub_old_1']);

    Http::fake([
        // Wildcard: a busca do cliente é GET /v3/customers?cpfCnpj=… (sem ele, chamada real).
        'https://api.asaas.com/v3/customers*'          => Http::response(['id' => 'cus_new'], 200),
        'https://api.asaas.com/v3/subscriptions'       => Http::response(['id' => 'sub_new_1', 'status' => 'active', 'customer' => 'cus_new'], 200),
        'https://api.asaas.com/v3/payments'            => Http::response(['id' => 'pay_new', 'status' => 'paid', 'amount' => 99.90], 200),
        'https://api.asaas.com/v3/subscriptions/sub_*' => Http::response(['deleted' => true], 200),
    ]);

    $new = app(BillingSubscriptionOrchestrator::class)->activateWithGateway($entity, $plan, BillingCycle::Monthly, 'asaas');

    expect($old->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($new->gateway_subscription_id)->toBe('sub_new_1');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v3/subscriptions/sub_old_1'));
    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v3/subscriptions/sub_new_1'));
});
