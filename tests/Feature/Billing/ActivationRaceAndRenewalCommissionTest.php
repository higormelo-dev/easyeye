<?php

declare(strict_types=1);

use App\DTOs\Billing\SubscriptionTerms;
use App\Enums\Billing\{InvoiceStatus, SubscriptionCancelledReason};
use App\Enums\{BillingCycle, PartnerType, ReferralEventType, SaasRule, SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\Billing\SubscriptionSupersededException;
use App\Models\{Entity, Partner, PartnerCommission, Plan, PlanPrice, ReferralEvent, Subscription, User};
use App\Services\Billing\{BillingSubscriptionOrchestrator, SubscriptionCycleService, SubscriptionManagementService};
use App\Services\{ReferralService, SubscriptionService};
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Http, Queue};

/**
 * Pendências da etapa 1:
 *  - P2: a renovação paga depois de um atraso volta a assinatura para Active,
 *    mas só a 1ª ativação paga gera comissão de parceiro e recompensa de
 *    indicação;
 *  - P3: se, enquanto o gateway responde, outra alteração (cortesia, trial ou
 *    outra contratação) assume o lugar da contratação, a fase 3 não substitui
 *    nada e desfaz a recorrência recém-criada no gateway.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Queue::fake();

    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    $this->plan = $this->plan->fresh('prices');
});

afterEach(fn () => Carbon::setTestNow());

function raceClinic(array $attributes = []): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true, ...$attributes]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

/**
 * Asaas real (cliente + assinatura). `$duringSubscription` roda no meio da
 * chamada que cria a assinatura — simula a alteração concorrente do manager.
 */
function raceFakeAsaas(?Closure $duringSubscription = null): void
{
    Http::fake(function (Request $request) use ($duringSubscription) {
        $url = $request->url();

        if (str_starts_with($url, 'https://api.asaas.com/v3/customers')) {
            return Http::response($request->method() === 'GET' ? ['data' => []] : ['object' => 'customer', 'id' => 'cus_000005219613']);
        }

        if ($url === 'https://api.asaas.com/v3/subscriptions' && $request->method() === 'POST') {
            if ($duringSubscription) {
                $duringSubscription();
            }

            return Http::response([
                'object'            => 'subscription',
                'id'                => 'sub_RACE0001',
                'customer'          => 'cus_000005219613',
                'billingType'       => 'BOLETO',
                'cycle'             => 'MONTHLY',
                'value'             => $request['value'],
                'nextDueDate'       => $request['nextDueDate'],
                'status'            => 'ACTIVE',
                'deleted'           => false,
                'externalReference' => $request['externalReference'],
            ]);
        }

        if (str_starts_with($url, 'https://api.asaas.com/v3/subscriptions/') && $request->method() === 'DELETE') {
            return Http::response(['deleted' => true, 'id' => basename($url)]);
        }

        return Http::response(['errors' => [['code' => 'not_faked']]], 404);
    });
}

describe('P3 — ativação concorrente', function () {
    it('cortesia criada enquanto o gateway respondia: ela vale e a recorrência nova é desfeita', function () {
        $clinic = raceClinic();
        $trial  = Subscription::factory()->trial(5)->for($clinic)->for($this->plan)->create();

        raceFakeAsaas(function () use ($clinic) {
            app(SubscriptionManagementService::class)->create(
                $clinic,
                test()->plan,
                SubscriptionTerms::complimentary(CarbonImmutable::today(), CarbonImmutable::today()->addMonth()->endOfDay()),
                'Cortesia concedida pelo comercial durante a contratação (ticket #900).',
            );
        });

        expect(fn () => app(BillingSubscriptionOrchestrator::class)->activateWithGateway($clinic, $this->plan, BillingCycle::Monthly, 'asaas'))
            ->toThrow(SubscriptionSupersededException::class, __('manager_subscriptions.errors.activation_superseded'));

        $activation = Subscription::query()->forEntity($clinic->id)->where('gateway_subscription_id', 'sub_RACE0001')->sole();
        $courtesy   = Subscription::query()->forEntity($clinic->id)->where('billing_mode', SubscriptionBillingMode::Complimentary->value)->sole();

        expect($activation->status)->toBe(SubscriptionStatus::Cancelled)
            ->and($activation->cancelled_reason)->toBe(SubscriptionCancelledReason::Replaced->value)
            ->and($activation->hasAccess())->toBeFalse()
            ->and($activation->currentInvoice->status)->toBe(InvoiceStatus::Cancelled)
            // A cortesia segue vigente e com acesso; o trial foi substituído por ela.
            ->and($courtesy->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($trial->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
            ->and(app(SubscriptionService::class)->currentAccess($clinic)->id)->toBe($courtesy->id);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/v3/subscriptions/sub_RACE0001'));
    });

    it('outra contratação mais recente em andamento: esta não substitui o trial e desfaz a recorrência', function () {
        $clinic = raceClinic();
        $trial  = Subscription::factory()->trial(5)->for($clinic)->for($this->plan)->create();

        raceFakeAsaas(function () use ($clinic) {
            // Fase 1 de uma 2ª contratação (mais recente), ainda no gateway.
            test()->travel(1)->seconds();
            Subscription::factory()->for($clinic)->for(test()->plan)->create([
                'status'          => SubscriptionStatus::PastDue,
                'billing_state'   => 'pending_activation',
                'gateway'         => null,
                'last_payment_at' => null,
                'next_billing_at' => now()->addDays(3)->endOfDay(),
                'ends_at'         => now()->addDays(3)->endOfDay(),
            ]);
        });

        expect(fn () => app(BillingSubscriptionOrchestrator::class)->activateWithGateway($clinic, $this->plan, BillingCycle::Monthly, 'asaas'))
            ->toThrow(SubscriptionSupersededException::class);

        expect($trial->fresh()->status)->toBe(SubscriptionStatus::Trial)
            ->and(Subscription::query()->where('gateway_subscription_id', 'sub_RACE0001')->sole()->status)->toBe(SubscriptionStatus::Cancelled);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/v3/subscriptions/sub_RACE0001'));
    });

    it('sem concorrência a ativação segue normal (substitui o trial e não desfaz nada)', function () {
        $clinic = raceClinic();
        $trial  = Subscription::factory()->trial(5)->for($clinic)->for($this->plan)->create();
        raceFakeAsaas();

        $activation = app(BillingSubscriptionOrchestrator::class)->activateWithGateway($clinic, $this->plan, BillingCycle::Monthly, 'asaas');

        expect($activation->status)->toBe(SubscriptionStatus::PastDue)
            ->and($activation->hasAccess())->toBeTrue()
            ->and($trial->fresh()->status)->toBe(SubscriptionStatus::Cancelled);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    });

    it('o manager recebe 409 com a explicação', function () {
        $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $admin = User::factory()->create();
        createEntityUser($saas, $admin, SaasRule::Admin->value);

        $clinic = raceClinic();
        Subscription::factory()->trial(5)->for($clinic)->for($this->plan)->create();

        raceFakeAsaas(function () use ($clinic) {
            app(SubscriptionManagementService::class)->create(
                $clinic,
                test()->plan,
                SubscriptionTerms::complimentary(CarbonImmutable::today(), CarbonImmutable::today()->addMonth()->endOfDay()),
                'Cortesia concedida pelo comercial durante a contratação (ticket #901).',
            );
        });

        $this->actingAs($admin)->withSession([
            'selected_entity_id'        => $saas->id,
            'selected_entity_is_client' => false,
            'selected_entity_user_rule' => SaasRule::Admin->value,
        ])->postJson(route('manager.subscriptions.store'), [
            'entity_id'     => $clinic->id,
            'plan_id'       => $this->plan->id,
            'mode'          => 'gateway',
            'billing_cycle' => 'monthly',
            'gateway'       => 'asaas',
        ])->assertStatus(409)
            ->assertJsonPath('message', __('manager_subscriptions.errors.activation_superseded'));
    });
});

describe('P2 — comissão e indicação só na 1ª ativação paga', function () {
    it('renovação paga depois do atraso não gera nova comissão nem nova recompensa de indicação', function () {
        $partner = Partner::create([
            'name'            => 'Parceiro Régua',
            'email'           => 'parceiro-regua@example.com',
            'type'            => PartnerType::Distributor,
            'commission_rate' => 10.0,
            'token'           => 'tok-' . uniqid(),
            'status'          => 'active',
        ]);

        // Quem indicou está em trial: a recompensa estende o trial dele.
        $referrer      = raceClinic(['name' => 'Clínica Indicadora']);
        $referrerTrial = Subscription::factory()->trial(10)->for($referrer)->for($this->plan)->create();
        $code          = app(ReferralService::class)->generate($referrer, rewardValue: 30);

        $clinic = raceClinic(['partner_id' => $partner->id]);
        $clinic->forceFill(['referral_code_id' => $code->id])->save();

        $subscription = Subscription::factory()->gateway()->for($clinic)->for($this->plan)->create([
            'status'          => SubscriptionStatus::PastDue,
            'billing_state'   => 'pending_activation',
            'billing_cycle'   => BillingCycle::Monthly,
            'amount'          => 299.90,
            'last_payment_at' => null,
            'next_billing_at' => '2026-10-08 23:59:59',
            'ends_at'         => '2026-10-08 23:59:59',
        ]);

        $cycles = app(SubscriptionCycleService::class);
        $pay    = function (string $dueDate, string $correlationId) use ($cycles, $subscription): void {
            DB::transaction(function () use ($cycles, $subscription, $dueDate, $correlationId): void {
                $fresh   = $subscription->fresh();
                $invoice = $cycles->periodInvoice($fresh, CarbonImmutable::parse($dueDate), $correlationId);
                $cycles->confirmPayment($fresh, $invoice, null, CarbonImmutable::parse($dueDate), $correlationId, 'webhook');
            });
        };

        // 1º pagamento (contratação): comissão + indicação.
        $pay('2026-10-08', (string) str()->uuid());

        $trialEndAfterReward = $referrerTrial->fresh()->trial_ends_at;

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and(PartnerCommission::where('subscription_id', $subscription->id)->count())->toBe(1)
            ->and(ReferralEvent::where('referred_entity_id', $clinic->id)->where('event_type', ReferralEventType::PlanActivated->value)->count())->toBe(1)
            ->and($trialEndAfterReward->toDateString())->toBe('2026-11-14');

        // Novembro: a renovação não foi paga no vencimento → em atraso.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 00:10:00'));
        app(SubscriptionService::class)->markLapsedAsPastDue();
        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);

        // Pago no D+2: volta para Active, mas não é venda nova.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 14:00:00'));
        $pay('2026-11-08', (string) str()->uuid());

        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->fresh()->ends_at->toDateTimeString())->toBe('2026-12-08 23:59:59')
            ->and(PartnerCommission::where('subscription_id', $subscription->id)->count())->toBe(1)
            ->and($referrerTrial->fresh()->trial_ends_at->equalTo($trialEndAfterReward))->toBeTrue();
    });
});
