<?php

use App\Enums\Billing\GatewayCode;
use App\Enums\{BillingCycle, SaasRule, SubscriptionBillingMode, SubscriptionStatus};
use App\Enums\PartnerType;
use App\Jobs\Billing\CancelGatewaySubscriptionJob;
use App\Models\Billing\SubscriptionChange;
use App\Models\{Entity, Partner, PartnerCommission, Plan, PlanPrice, Subscription, User};
use App\Services\Billing\{BillingSubscriptionOrchestrator, SubscriptionManagementService};
use App\Services\ManagerDashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, Http, Queue};
use Illuminate\Validation\ValidationException;

/**
 * Manager → Assinaturas: nova assinatura (trial, cobrança automática ou
 * cortesia), adicionar período, alterar condições e histórico.
 */
beforeEach(function () {
    Http::preventStrayRequests();

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Ana Admin']);
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->clinic = subMgrClinic(['name' => 'Clínica Visão']);
    $this->plan   = subMgrPlan('Pro', ['monthly' => 300.00, 'yearly' => 3000.00]);
});

/** Clínica sem o trial automático do EntityObserver (o teste controla as assinaturas). */
function subMgrClinic(array $attributes = []): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true, ...$attributes]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

function subMgrPlan(string $name, array $prices): Plan
{
    $default = array_key_first($prices);
    $plan    = Plan::factory()->create([
        'name'          => $name,
        'billing_cycle' => $default,
        'price'         => $prices[$default],
        'active'        => true,
    ]);

    foreach ($prices as $cycle => $price) {
        PlanPrice::create(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'price' => $price]);
    }

    return $plan->fresh('prices');
}

function subMgrAs(?User $user = null): mixed
{
    return test()->actingAs($user ?? test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ]);
}

function subMgrReason(): string
{
    return 'Cortesia negociada na implantação da clínica (ticket #123).';
}

describe('nova assinatura', function () {
    it('cortesia substitui o trial vigente e registra quem, quando e por quê', function () {
        $trial  = Subscription::factory()->trial()->for($this->clinic)->for($this->plan)->create();
        $endsAt = now()->addMonths(3)->toDateString();

        subMgrAs()->postJson(route('manager.subscriptions.store'), [
            'entity_id' => $this->clinic->id,
            'plan_id'   => $this->plan->id,
            'mode'      => 'complimentary',
            'ends_at'   => $endsAt,
            'reason'    => subMgrReason(),
        ])->assertCreated();

        $trial->refresh();
        $current = Subscription::forEntity($this->clinic->id)->currentFirst()->first();

        expect($trial->status)->toBe(SubscriptionStatus::Cancelled)
            ->and($trial->cancelled_reason)->toBe('replaced')
            ->and($current->id)->not->toBe($trial->id)
            ->and($current->status)->toBe(SubscriptionStatus::Active)
            ->and($current->billing_mode)->toBe(SubscriptionBillingMode::Complimentary)
            ->and($current->amount)->toBeNull()
            ->and($current->ends_at->toDateString())->toBe($endsAt)
            ->and($current->hasAccess())->toBeTrue();

        $change = SubscriptionChange::where('subscription_id', $current->id)->sole();
        expect($change->change_type)->toBe('subscription_created')
            ->and($change->changed_by)->toBe($this->admin->id)
            ->and($change->metadata['reason_text'])->toBe(subMgrReason())
            ->and($change->metadata['previous']['status'])->toBe('trial')
            ->and($change->metadata['replaced_subscription_ids'])->toBe([$trial->id]);

        $this->assertDatabaseHas('audit_logs', [
            'event'            => 'manager.subscription.create.complimentary',
            'target_entity_id' => $this->clinic->id,
            'reason'           => subMgrReason(),
        ]);
    });

    it('comissão de parceiro só quando vira receita: cobrança automática gera, cortesia não', function () {
        $partner = Partner::create([
            'name'            => 'Parceiro Comissão',
            'email'           => 'parceiro-comissao@example.com',
            'type'            => PartnerType::Distributor,
            'commission_rate' => 10.0,
            'token'           => 'tok-' . uniqid(),
            'status'          => 'active',
        ]);
        $paying = subMgrClinic(['partner_id' => $partner->id]);
        $gift   = subMgrClinic(['partner_id' => $partner->id]);

        // Cobrança automática anual aguardando o 1º pagamento; o webhook pago a torna ativa.
        $payingSub = Subscription::factory()->gateway()->for($paying)->for($this->plan)->create([
            'status'        => SubscriptionStatus::PastDue,
            'billing_cycle' => BillingCycle::Yearly,
            'amount'        => 3000,
        ]);
        $payingSub->update(['status' => SubscriptionStatus::Active]);

        $giftTrial = Subscription::factory()->trial()->for($gift)->for($this->plan)->create();
        subMgrAs()->putJson(route('manager.subscriptions.update', $giftTrial), [
            'plan_id'   => $this->plan->id,
            'mode'      => 'complimentary',
            'starts_at' => now()->toDateString(),
            'ends_at'   => now()->addYear()->toDateString(),
            'reason'    => subMgrReason(),
        ])->assertOk();

        expect(PartnerCommission::where('subscription_id', $payingSub->id)->value('amount'))->toEqual(300.0)
            ->and(PartnerCommission::where('subscription_id', $giftTrial->id)->exists())->toBeFalse();
    });

    it('"pago por fora" e "vitalícia" não existem: só trial, cobrança automática e cortesia', function (string $mode) {
        subMgrAs()->postJson(route('manager.subscriptions.store'), [
            'entity_id'     => $this->clinic->id,
            'plan_id'       => $this->plan->id,
            'mode'          => $mode,
            'billing_cycle' => 'yearly',
            'ends_at'       => now()->addYear()->toDateString(),
            'reason'        => subMgrReason(),
        ])->assertUnprocessable()->assertJsonValidationErrors('mode');

        $sub = Subscription::factory()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->putJson(route('manager.subscriptions.update', $sub), [
            'plan_id'   => $this->plan->id,
            'mode'      => $mode,
            'starts_at' => now()->toDateString(),
            'ends_at'   => now()->addMonth()->toDateString(),
            'reason'    => subMgrReason(),
        ])->assertUnprocessable()->assertJsonValidationErrors('mode');

        expect(Subscription::forEntity($this->clinic->id)->count())->toBe(1);
    })->with(['manual', 'lifetime']);

    it('assinatura criada sem gateway (liberada à mão) é cortesia e fica fora da receita', function () {
        $sub = Subscription::create([
            'entity_id' => $this->clinic->id,
            'plan_id'   => $this->plan->id,
            'status'    => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at'   => now()->addMonth(),
        ]);

        expect($sub->billing_mode)->toBe(SubscriptionBillingMode::Complimentary)
            ->and($sub->isBillable())->toBeFalse()
            ->and(app(ManagerDashboardService::class)->getFinancialKpis()['mrr'])->toBe(0.0);
    });

    it('exige justificativa para liberar acesso sem cobrança pelo sistema, mas não para trial', function () {
        subMgrAs()->postJson(route('manager.subscriptions.store'), [
            'entity_id' => $this->clinic->id,
            'plan_id'   => $this->plan->id,
            'mode'      => 'complimentary',
            'ends_at'   => now()->addMonth()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        subMgrAs()->postJson(route('manager.subscriptions.store'), [
            'entity_id'     => $this->clinic->id,
            'plan_id'       => $this->plan->id,
            'mode'          => 'trial',
            'trial_days'    => 10,
            'billing_cycle' => 'yearly',
        ])->assertCreated();

        $trial = Subscription::forEntity($this->clinic->id)->sole();
        expect($trial->status)->toBe(SubscriptionStatus::Trial)
            ->and($trial->billing_mode)->toBeNull()
            ->and($trial->billing_cycle)->toBe(BillingCycle::Yearly)
            ->and((float) $trial->amount)->toBe(3000.0)
            ->and($trial->trial_ends_at->toDateString())->toBe(now()->addDays(10)->toDateString());
    });

    it('não aceita a própria entity SaaS nem término no passado', function () {
        subMgrAs()->postJson(route('manager.subscriptions.store'), [
            'entity_id' => $this->saas->id,
            'plan_id'   => $this->plan->id,
            'mode'      => 'complimentary',
            'ends_at'   => now()->subDay()->toDateString(),
            'reason'    => subMgrReason(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['entity_id', 'ends_at']);

        expect(Subscription::count())->toBe(0);
    });

    it('cobrança automática só nos ciclos que o plano vende e cobra o preço do ciclo escolhido', function () {
        $monthlyOnly = subMgrPlan('Básico', ['monthly' => 150.00]);

        subMgrAs()->postJson(route('manager.subscriptions.store'), [
            'entity_id'     => $this->clinic->id,
            'plan_id'       => $monthlyOnly->id,
            'mode'          => 'gateway',
            'billing_cycle' => 'yearly',
        ])->assertUnprocessable()->assertJsonValidationErrors('billing_cycle');

        Http::fake([
            // Wildcard: a busca do cliente é GET /v3/customers?cpfCnpj=… (sem ele, chamada real).
            'https://api.asaas.com/v3/customers*'    => Http::response(['id' => 'cus_1'], 200),
            'https://api.asaas.com/v3/subscriptions' => Http::response(['id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_1'], 200),
            'https://api.asaas.com/v3/payments'      => Http::response(['id' => 'pay_1', 'status' => 'paid', 'amount' => 3000], 200),
        ]);

        subMgrAs()->postJson(route('manager.subscriptions.store'), [
            'entity_id'     => $this->clinic->id,
            'plan_id'       => $this->plan->id,
            'mode'          => 'gateway',
            'billing_cycle' => 'yearly',
            'gateway'       => GatewayCode::Asaas->value,
        ])->assertCreated();

        $current = Subscription::forEntity($this->clinic->id)->currentFirst()->first();

        expect($current->billing_mode)->toBe(SubscriptionBillingMode::Gateway)
            ->and($current->billing_cycle)->toBe(BillingCycle::Yearly)
            ->and((float) $current->amount)->toBe(3000.0)
            ->and((float) $current->currentInvoice->amount)->toBe(3000.0);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/subscriptions')
            && (float) $request['value'] === 3000.0
            && $request['cycle'] === 'YEARLY');
    });

    it('ciclo vitalício não é vendido: recusado na cobrança automática e no trial, mesmo com preço antigo no plano', function () {
        Http::fake();
        // Preço vitalício que sobrou de antes da mudança de estratégia: ignorado.
        PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'lifetime', 'price' => 9900]);

        expect($this->plan->fresh('prices')->offersCycle(BillingCycle::Lifetime))->toBeFalse();

        subMgrAs()->postJson(route('manager.subscriptions.store'), [
            'entity_id'     => $this->clinic->id,
            'plan_id'       => $this->plan->id,
            'mode'          => 'gateway',
            'billing_cycle' => 'lifetime',
            'gateway'       => GatewayCode::Asaas->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('billing_cycle');

        subMgrAs()->postJson(route('manager.subscriptions.store'), [
            'entity_id'     => $this->clinic->id,
            'plan_id'       => $this->plan->id,
            'mode'          => 'trial',
            'trial_days'    => 7,
            'billing_cycle' => 'lifetime',
        ])->assertUnprocessable()->assertJsonValidationErrors('billing_cycle');

        Http::assertNothingSent();
        expect(Subscription::forEntity($this->clinic->id)->exists())->toBeFalse();
    });

    it('cobrança automática nunca cobra o ciclo vitalício, mesmo chamada fora da tela', function () {
        Http::fake();
        PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'lifetime', 'price' => 9900]);

        expect(fn () => app(BillingSubscriptionOrchestrator::class)
            ->activateWithGateway($this->clinic, $this->plan->fresh('prices'), BillingCycle::Lifetime, GatewayCode::Asaas->value))
            ->toThrow(ValidationException::class);

        Http::assertNothingSent();
        expect(Subscription::forEntity($this->clinic->id)->exists())->toBeFalse();
    });

    it('substituir uma assinatura cobrada pelo gateway cancela a recorrência lá', function () {
        Queue::fake();
        Http::fake(['https://api.asaas.com/v3/subscriptions/*' => Http::response(['deleted' => true], 200)]);

        $old = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->postJson(route('manager.subscriptions.store'), [
            'entity_id' => $this->clinic->id,
            'plan_id'   => $this->plan->id,
            'mode'      => 'complimentary',
            'ends_at'   => now()->addMonths(6)->toDateString(),
            'reason'    => subMgrReason(),
        ])->assertCreated();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/v3/subscriptions/' . $old->gateway_subscription_id));
        Queue::assertNotPushed(CancelGatewaySubscriptionJob::class);
        expect($old->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
            ->and($old->fresh()->billing_state)->toBe('cancelled');
    });
});

describe('adicionar período', function () {
    it('soma ao término futuro e registra a extensão', function () {
        $endsAt = now()->addDays(10)->startOfSecond();
        $sub    = Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create(['ends_at' => $endsAt]);

        subMgrAs()->postJson(route('manager.subscriptions.extend', $sub), [
            'unit'     => 'months',
            'quantity' => 3,
            'reason'   => subMgrReason(),
        ])->assertOk();

        expect($sub->fresh()->ends_at->toDateTimeString())->toBe($endsAt->copy()->addMonthsNoOverflow(3)->toDateTimeString());

        $change = SubscriptionChange::where('subscription_id', $sub->id)->sole();
        expect($change->change_type)->toBe('period_extended')
            ->and($change->metadata['extension'])->toBe(['unit' => 'months', 'quantity' => 3]);
    });

    it('cortesia vencida volta a valer a partir de hoje', function () {
        $sub = Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create([
            'status'  => SubscriptionStatus::Expired,
            'ends_at' => now()->subMonth(),
        ]);

        subMgrAs()->postJson(route('manager.subscriptions.extend', $sub), [
            'unit' => 'days', 'quantity' => 30, 'reason' => subMgrReason(),
        ])->assertOk();

        $sub->refresh();
        expect($sub->status)->toBe(SubscriptionStatus::Active)
            ->and($sub->ends_at->toDateString())->toBe(now()->addDays(30)->toDateString())
            ->and($sub->hasAccess())->toBeTrue();
    });

    it('estende o trial — inclusive um trial que já expirou', function () {
        $trial = Subscription::factory()->expiredTrial()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->postJson(route('manager.subscriptions.extend', $trial), [
            'unit' => 'days', 'quantity' => 7, 'reason' => subMgrReason(),
        ])->assertOk();

        $trial->refresh();
        expect($trial->status)->toBe(SubscriptionStatus::Trial)
            ->and($trial->ends_at)->toBeNull()
            ->and($trial->trial_ends_at->toDateString())->toBe(now()->addDays(7)->toDateString());
    });

    it('recusa assinatura antiga sem término, cancelada e linhas de histórico', function () {
        $openEnded = Subscription::factory()->complimentary()->lifetime()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->postJson(route('manager.subscriptions.extend', $openEnded), [
            'unit' => 'months', 'quantity' => 1, 'reason' => subMgrReason(),
        ])->assertUnprocessable()->assertJsonValidationErrors('subscription');

        $other     = subMgrClinic();
        $cancelled = Subscription::factory()->cancelled()->for($other)->for($this->plan)->create();

        subMgrAs()->postJson(route('manager.subscriptions.extend', $cancelled), [
            'unit' => 'months', 'quantity' => 1, 'reason' => subMgrReason(),
        ])->assertUnprocessable()->assertJsonValidationErrors('subscription');

        // Linha antiga da empresa: só a mais recente muda.
        $old = Subscription::factory()->for($other)->for($this->plan)->create(['created_at' => now()->subYear()]);
        Subscription::factory()->for($other)->for($this->plan)->create();

        subMgrAs()->postJson(route('manager.subscriptions.extend', $old), [
            'unit' => 'months', 'quantity' => 1, 'reason' => subMgrReason(),
        ])->assertUnprocessable()->assertJsonValidationErrors('subscription');
    });

    it('cobrança automática não recebe período (o pagamento seguinte o absorveria); trial e cortesia seguem podendo', function () {
        $paying = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
            'billing_cycle'   => 'monthly',
            'amount'          => 300.00,
            'last_payment_at' => now()->subDays(5),
            'ends_at'         => now()->addDays(25)->endOfDay(),
            'next_billing_at' => now()->addDays(25)->endOfDay(),
        ]);
        $endsAt = $paying->ends_at->toDateTimeString();

        subMgrAs()->postJson(route('manager.subscriptions.extend', $paying), [
            'unit' => 'months', 'quantity' => 1, 'reason' => subMgrReason(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('subscription')
            ->assertJsonPath('errors.subscription.0', __('manager_subscriptions.errors.extend_gateway'));

        expect($paying->fresh()->ends_at->toDateTimeString())->toBe($endsAt)
            ->and(SubscriptionChange::where('subscription_id', $paying->id)->exists())->toBeFalse();

        // A listagem desabilita o botão (o motivo vem do presenter no front).
        subMgrAs()->get(route('manager.subscriptions.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('subscriptions.data.0.id', $paying->id)
                ->where('subscriptions.data.0.can_extend', false)
                ->where('t.extend_disabled_gateway', __('manager_subscriptions.extend_disabled_gateway')));

        // Em atraso também não: o caminho para dias grátis é virar cortesia.
        $paying->update(['status' => SubscriptionStatus::PastDue, 'past_due_at' => now()->subDay()]);

        subMgrAs()->postJson(route('manager.subscriptions.extend', $paying), [
            'unit' => 'days', 'quantity' => 10, 'reason' => subMgrReason(),
        ])->assertUnprocessable()->assertJsonPath('errors.subscription.0', __('manager_subscriptions.errors.extend_gateway'));
    });

    it('valida unidade, quantidade e justificativa', function () {
        $sub = Subscription::factory()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->postJson(route('manager.subscriptions.extend', $sub), [
            'unit' => 'weeks', 'quantity' => 0, 'reason' => 'curto',
        ])->assertUnprocessable()->assertJsonValidationErrors(['unit', 'quantity', 'reason']);

        subMgrAs()->postJson(route('manager.subscriptions.extend', $sub), [
            'unit' => 'years', 'quantity' => 11, 'reason' => subMgrReason(),
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    });

    it('meses não transbordam: 31/01 + 1 mês = último dia de fevereiro', function () {
        $now = CarbonImmutable::parse('2027-01-10 12:00:00');

        expect(SubscriptionManagementService::extendedEnd(CarbonImmutable::parse('2027-01-31 23:59:59'), 'months', 1, $now)->toDateString())
            ->toBe('2027-02-28')
            ->and(SubscriptionManagementService::extendedEnd(CarbonImmutable::parse('2026-12-01'), 'days', 5, $now)->toDateString())
            ->toBe('2027-01-15')
            ->and(SubscriptionManagementService::extendedEnd(null, 'years', 1, $now)->toDateString())
            ->toBe('2028-01-10');
    });
});

describe('alterar assinatura', function () {
    it('cobrança automática virando cortesia cancela a recorrência no gateway', function () {
        Http::fake(['https://api.asaas.com/v3/subscriptions/*' => Http::response(['deleted' => true], 200)]);

        $sub = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->putJson(route('manager.subscriptions.update', $sub), [
            'plan_id'   => $this->plan->id,
            'mode'      => 'complimentary',
            'starts_at' => now()->toDateString(),
            'ends_at'   => now()->addMonths(2)->toDateString(),
            'reason'    => 'Clínica parceira do piloto — 2 meses sem cobrança.',
        ])->assertOk();

        $sub->refresh();
        expect($sub->billing_mode)->toBe(SubscriptionBillingMode::Complimentary)
            ->and($sub->billing_state)->toBeNull()
            ->and($sub->amount)->toBeNull()
            ->and($sub->ends_at->toDateString())->toBe(now()->addMonths(2)->toDateString());

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/v3/subscriptions/' . $sub->gateway_subscription_id));

        $change = SubscriptionChange::where('subscription_id', $sub->id)->sole();
        expect($change->change_type)->toBe('terms_changed')
            ->and($change->metadata['gateway_recurrence_cancelled'])->toBeTrue()
            ->and($change->metadata['previous']['billing_mode'])->toBe('gateway');
    });

    it('trial vira cortesia em outro plano, sempre com término', function () {
        $basic = subMgrPlan('Básico', ['monthly' => 150.00]);
        $trial = Subscription::factory()->trial()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->putJson(route('manager.subscriptions.update', $trial), [
            'plan_id'   => $basic->id,
            'mode'      => 'complimentary',
            'starts_at' => now()->toDateString(),
            'ends_at'   => now()->addMonths(3)->toDateString(),
            'reason'    => 'Clínica piloto: 3 meses sem cobrança para validar o fluxo.',
        ])->assertOk();

        $trial->refresh();
        expect($trial->status)->toBe(SubscriptionStatus::Active)
            ->and($trial->plan_id)->toBe($basic->id)
            ->and($trial->billing_mode)->toBe(SubscriptionBillingMode::Complimentary)
            ->and($trial->billing_cycle)->toBeNull()
            ->and($trial->amount)->toBeNull();

        subMgrAs()->putJson(route('manager.subscriptions.update', $trial), [
            'plan_id'   => $basic->id,
            'mode'      => 'complimentary',
            'starts_at' => now()->toDateString(),
            'reason'    => 'Sem término: acesso liberado por tempo indeterminado.',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        expect($trial->fresh()->isOpenEnded())->toBeFalse();
    });

    it('assinatura antiga sem término ganha data de término ao ser alterada', function () {
        $legacy = Subscription::factory()->complimentary()->lifetime()->for($this->clinic)->for($this->plan)->create([
            'starts_at' => now()->subYear(),
        ]);

        expect($legacy->isOpenEnded())->toBeTrue();

        subMgrAs()->putJson(route('manager.subscriptions.update', $legacy), [
            'plan_id'   => $this->plan->id,
            'mode'      => 'complimentary',
            'starts_at' => now()->toDateString(),
            'ends_at'   => now()->addMonths(6)->toDateString(),
            'reason'    => 'Acesso sem término revisado: cortesia até o fim do semestre.',
        ])->assertOk();

        $legacy->refresh();
        expect($legacy->isOpenEnded())->toBeFalse()
            ->and($legacy->ends_at->toDateString())->toBe(now()->addMonths(6)->toDateString())
            ->and($legacy->billing_mode)->toBe(SubscriptionBillingMode::Complimentary);

        $change = SubscriptionChange::where('subscription_id', $legacy->id)->sole();
        expect($change->metadata['previous']['ends_at'])->toBeNull();
    });

    it('recusa assinatura cancelada e término antes do início', function () {
        $sub = Subscription::factory()->cancelled()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->putJson(route('manager.subscriptions.update', $sub), [
            'plan_id'   => $this->plan->id,
            'mode'      => 'complimentary',
            'starts_at' => now()->toDateString(),
            'ends_at'   => now()->addMonth()->toDateString(),
            'reason'    => subMgrReason(),
        ])->assertUnprocessable()->assertJsonValidationErrors('subscription');

        subMgrAs()->putJson(route('manager.subscriptions.update', $sub), [
            'plan_id'   => $this->plan->id,
            'mode'      => 'complimentary',
            'starts_at' => now()->toDateString(),
            'ends_at'   => now()->subDay()->toDateString(),
            'reason'    => subMgrReason(),
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    });
});

describe('listagem, histórico e busca de empresas', function () {
    it('mostra uma linha por empresa por padrão e o histórico completo sob demanda', function () {
        Subscription::factory()->cancelled()->for($this->clinic)->for($this->plan)->create(['created_at' => now()->subMonths(2)]);
        Subscription::factory()->complimentary()->lifetime()->for($this->clinic)->for($this->plan)->create();

        $other = subMgrClinic();
        Subscription::factory()->trial()->for($other)->for($this->plan)->create();

        subMgrAs()->get(route('manager.subscriptions.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Panel/Manager/Subscriptions/Index')
                ->has('subscriptions.data', 2)
                ->where('summary.complimentary', 1)
                ->where('summary.trial', 1)
                ->missing('summary.lifetime')
                ->where('canManagePlans', true));

        subMgrAs()->get(route('manager.subscriptions.index', ['scope' => 'all']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 3));

        // Linha antiga sem término: aparece como cortesia "sem término" e não dá para adicionar período.
        subMgrAs()->get(route('manager.subscriptions.index', ['mode' => 'complimentary']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.modality', 'complimentary')
                ->where('subscriptions.data.0.open_ended', true)
                ->where('subscriptions.data.0.is_current', true)
                ->where('subscriptions.data.0.can_extend', false));

        // Filtro "vitalícia" não existe mais: é ignorado.
        subMgrAs()->get(route('manager.subscriptions.index', ['mode' => 'lifetime']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 2)->where('filters.mode', ''));
    });

    it('"sem acesso" lista quem venceu — não há período de graça', function () {
        Subscription::factory()->for($this->clinic)->for($this->plan)->create();
        $gone = subMgrClinic(['name' => 'Clínica Encerrada']);
        Subscription::factory()->expired()->for($gone)->for($this->plan)->create();
        // Período de graça gravado antes da mudança não segura mais o acesso.
        $legacyGrace = subMgrClinic(['name' => 'Clínica Graça Antiga']);
        Subscription::factory()->expired()->for($legacyGrace)->for($this->plan)->create([
            'grace_period_ends_at' => now()->addDays(2),
        ]);

        subMgrAs()->get(route('manager.subscriptions.index', ['status' => 'no_access']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 2)
                ->where('summary.without_access', 2)
                ->missing('graceDays'));
    });

    it('filtra pelo plano vindo da tela de Planos', function () {
        $basic = subMgrPlan('Básico', ['monthly' => 150.00]);
        Subscription::factory()->for($this->clinic)->for($this->plan)->create();
        Subscription::factory()->for(subMgrClinic())->for($basic)->create();

        subMgrAs()->get(route('manager.subscriptions.index', ['plan' => $basic->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.plan_id', $basic->id)
                ->where('filters.plan', $basic->id));
    });

    it('histórico traz as assinaturas da empresa e as mudanças com autor e motivo', function () {
        // Trial: guarda o ciclo pretendido (o do plano) e recebe período.
        $sub = Subscription::factory()->trial(5)->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->postJson(route('manager.subscriptions.extend', $sub), [
            'unit' => 'months', 'quantity' => 1, 'reason' => subMgrReason(),
        ])->assertOk();

        subMgrAs()->getJson(route('manager.subscriptions.history', $sub))->assertOk()
            ->assertJsonCount(1, 'data.subscriptions')
            ->assertJsonPath('data.subscriptions.0.is_current', true)
            ->assertJsonPath('data.subscriptions.0.billing_cycle', 'monthly')
            ->assertJsonPath('data.events.0.type', 'period_extended')
            ->assertJsonPath('data.events.0.actor', $this->admin->name)
            ->assertJsonPath('data.events.0.reason', subMgrReason())
            ->assertJsonPath('data.events.0.extension.quantity', 1);
    });

    it('histórico não mostra ciclo de cobrança na cortesia', function () {
        $sub = Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->getJson(route('manager.subscriptions.history', $sub))->assertOk()
            ->assertJsonPath('data.subscriptions.0.modality', 'complimentary')
            ->assertJsonPath('data.subscriptions.0.billing_cycle', null)
            ->assertJsonPath('data.subscriptions.0.amount', null);
    });

    it('busca só empresas clientes e informa a assinatura atual de cada uma', function () {
        Subscription::factory()->trial()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->getJson(route('manager.subscriptions.entities', ['search' => 'visao']))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->clinic->id)
            ->assertJsonPath('data.0.current.modality', 'trial')
            ->assertJsonPath('data.0.current.plan_name', 'Pro');

        $names = collect(subMgrAs()->getJson(route('manager.subscriptions.entities'))->json('data'))->pluck('id');
        expect($names)->not->toContain($this->saas->id);
    });

    it('card "Sem assinatura": lista só as empresas clientes sem assinatura, no mesmo critério do contador', function () {
        // $this->clinic nasce sem assinatura; outra com trial; a empresa SaaS nunca aparece.
        $withSub = subMgrClinic(['name' => 'Clínica Com Plano']);
        Subscription::factory()->trial()->for($withSub)->for($this->plan)->create();
        $alsoNone = subMgrClinic(['name' => 'Clínica Nova']);

        $response = subMgrAs()->getJson(route('manager.subscriptions.entities', ['without_subscription' => 1]))->assertOk();
        $ids      = collect($response->json('data'))->pluck('id');

        expect($ids->sort()->values()->all())->toBe(collect([$this->clinic->id, $alsoNone->id])->sort()->values()->all())
            ->and($response->json('data.0.created_at'))->toBe($alsoNone->created_at->toDateString())
            ->and($response->json('data.0.current'))->toBeNull();

        // Mesmo número que o card do resumo mostra.
        subMgrAs()->get(route('manager.subscriptions.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.no_subscription', $ids->count()));
    });
});

describe('controle de acesso', function () {
    it('financeiro gerencia assinaturas; suporte não', function () {
        $sub = Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create();

        $financial = User::factory()->create();
        createEntityUser($this->saas, $financial, SaasRule::Financial->value);

        subMgrAs($financial)->postJson(route('manager.subscriptions.extend', $sub), [
            'unit' => 'days', 'quantity' => 5, 'reason' => subMgrReason(),
        ])->assertOk();

        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);

        subMgrAs($support)->postJson(route('manager.subscriptions.extend', $sub), [
            'unit' => 'days', 'quantity' => 5, 'reason' => subMgrReason(),
        ])->assertForbidden();

        subMgrAs($support)->postJson(route('manager.subscriptions.store'), [
            'entity_id' => $this->clinic->id, 'plan_id' => $this->plan->id, 'mode' => 'complimentary',
            'ends_at'   => now()->addMonth()->toDateString(), 'reason' => subMgrReason(),
        ])->assertForbidden();

        subMgrAs($financial)->get(route('manager.subscriptions.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManagePlans', false));
    });
});

describe('cancelar', function () {
    it('cancela a vigente em atraso (sem acesso no momento) e para a cobrança no gateway', function () {
        Http::fake(['https://api.asaas.com/v3/subscriptions/*' => Http::response(['deleted' => true], 200)]);

        // Atraso de 8 dias: passou do bloqueio total da régua (D+7).
        $pastDue = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
            'status'          => SubscriptionStatus::PastDue,
            'billing_state'   => 'past_due',
            'ends_at'         => now()->subDays(8),
            'past_due_at'     => now()->subDays(8),
            'last_payment_at' => now()->subMonths(2),
        ]);

        expect($pastDue->hasAccess())->toBeFalse();

        subMgrAs()->postJson(route('manager.subscriptions.cancel'), [
            'entity_id' => $this->clinic->id,
            'reason'    => 'Clínica pediu o encerramento do contrato (ticket #451).',
        ])->assertOk()->assertJsonPath('data.id', $pastDue->id);

        expect($pastDue->fresh()->status)->toBe(SubscriptionStatus::Cancelled);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/v3/subscriptions/' . $pastDue->gateway_subscription_id));
    });

    it('sem assinatura vigente devolve 404', function () {
        Subscription::factory()->expired()->for($this->clinic)->for($this->plan)->create();

        subMgrAs()->postJson(route('manager.subscriptions.cancel'), [
            'entity_id' => $this->clinic->id,
            'reason'    => 'Clínica pediu o encerramento do contrato (ticket #452).',
        ])->assertNotFound();
    });
});

describe('MRR', function () {
    it('não conta trial encerrado como receita e normaliza ciclos', function () {
        Subscription::factory()->expiredTrial()->for($this->clinic)->for($this->plan)->create(['starts_at' => now()->subMonths(2)]);
        Subscription::factory()->for(subMgrClinic())->for($this->plan)->create([
            'billing_cycle'   => BillingCycle::Yearly,
            'amount'          => 3000,
            'starts_at'       => now()->subMonths(2),
            'last_payment_at' => now()->subMonths(2),
        ]);
        Subscription::factory()->complimentary()->for(subMgrClinic())->for($this->plan)->create([
            'starts_at' => now()->subMonths(2),
        ]);

        $trend = app(ManagerDashboardService::class)->getMrrTrend();

        expect(end($trend['values']))->toBe(250.0)
            ->and(app(ManagerDashboardService::class)->getFinancialKpis()['mrr'])->toBe(250.0);
    });
});

describe('contratação aguardando o 1º pagamento', function () {
    beforeEach(function () {
        // Aguardando: cobrança emitida, vence em 3 dias, nunca pagou.
        $this->awaiting = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
            'status'          => SubscriptionStatus::PastDue,
            'billing_state'   => 'pending_activation',
            'last_payment_at' => null,
            'next_billing_at' => now()->addDays(3)->endOfDay(),
            'ends_at'         => now()->addDays(3)->endOfDay(),
        ]);

        // Em atraso de verdade: já pagou e a renovação venceu há 2 dias.
        $this->overdue = Subscription::factory()->gateway()->for(subMgrClinic(['name' => 'Clínica Atrasada']))->for($this->plan)->create([
            'status'          => SubscriptionStatus::PastDue,
            'billing_state'   => 'past_due',
            'last_payment_at' => now()->subMonth(),
            'past_due_at'     => now()->subDays(2)->endOfDay(),
            'ends_at'         => now()->subDays(2)->endOfDay(),
        ]);
    });

    it('aparece como "Aguardando 1º pagamento", não como em atraso', function () {
        subMgrAs()->get(route('manager.subscriptions.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.past_due', 1)
                ->where('summary.awaiting_payment', 1)
                ->where('t.summary_awaiting_payment', __('manager_subscriptions.summary_awaiting_payment')));

        subMgrAs()->get(route('manager.subscriptions.index', ['status' => 'awaiting_payment']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $this->awaiting->id)
                ->where('subscriptions.data.0.status_label', __('manager_subscriptions.status_awaiting_first_payment'))
                ->where('subscriptions.data.0.status_badge', 'badge-soft-info')
                ->where('filters.status', 'awaiting_payment'));

        subMgrAs()->get(route('manager.subscriptions.index', ['status' => 'past_due']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $this->overdue->id)
                ->where('subscriptions.data.0.status_label', SubscriptionStatus::PastDue->label()));

        subMgrAs()->getJson(route('manager.subscriptions.history', $this->awaiting))->assertOk()
            ->assertJsonPath('data.subscriptions.0.status_label', __('manager_subscriptions.status_awaiting_first_payment'));

        subMgrAs()->getJson(route('manager.subscriptions.entities', ['id' => $this->clinic->id]))->assertOk()
            ->assertJsonPath('data.0.current.status_label', __('manager_subscriptions.status_awaiting_first_payment'));
    });

    it('no painel do manager também não conta como "Em atraso" nos KPIs de assinatura', function () {
        $kpis = app(ManagerDashboardService::class)->getSubscriptionKpis();

        expect($kpis['subscriptionCounts']['past_due'])->toBe(1)
            ->and($kpis['subscriptionCounts']['awaiting_payment'])->toBe(1)
            ->and($kpis['totalSubscriptions'])->toBe(2);

        subMgrAs()->get(route('manager.dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('t.subscription_status.awaiting_payment', __('manager_dashboard.subscription_status.awaiting_payment')));

        expect(__('manager_dashboard.subscription_status.awaiting_payment', [], 'en'))
            ->not->toBe(__('manager_dashboard.subscription_status.awaiting_payment', [], 'pt_BR'));
    });

    it('KPIs do painel do manager: linhas do código anterior aguardando conciliação ficam só em "Revisar cobrança"', function () {
        // Pagante antigo em atraso (situação gravada não confiável) e contratação antiga nunca paga.
        foreach ([['last_payment_at' => now()->subMonths(3)], ['last_payment_at' => null]] as $i => $attributes) {
            Subscription::factory()->gateway()->for(subMgrClinic(['name' => "Clínica Legada {$i}"]))->for($this->plan)->create([
                'status'                       => SubscriptionStatus::PastDue,
                'billing_state'                => 'past_due',
                'next_billing_at'              => null,
                'ends_at'                      => now()->subMonths(2),
                'needs_billing_reconciliation' => true,
                ...$attributes,
            ]);
        }

        Cache::flush();
        $kpis = app(ManagerDashboardService::class)->getSubscriptionKpis();

        expect($kpis['subscriptionCounts']['past_due'])->toBe(1)
            ->and($kpis['subscriptionCounts']['awaiting_payment'])->toBe(1)
            ->and($kpis['subscriptionCounts']['needs_review'])->toBe(2)
            ->and($kpis['totalSubscriptions'])->toBe(4)
            // Receita em risco: só o atraso de verdade (o do código anterior aguarda conciliação).
            ->and((float) app(ManagerDashboardService::class)->getFinancialKpis()['revenueAtRisk'])
            ->toBe((float) ($this->overdue->amount ?? $this->plan->price));

        subMgrAs()->get(route('manager.dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('t.subscription_status.needs_review', __('manager_dashboard.subscription_status.needs_review')));

        expect(__('manager_dashboard.subscription_status.needs_review', [], 'en'))
            ->not->toBe(__('manager_dashboard.subscription_status.needs_review', [], 'pt_BR'));
    });

    it('o rótulo existe nas duas línguas', function () {
        foreach (['status_awaiting_first_payment', 'summary_awaiting_payment'] as $key) {
            expect(__("manager_subscriptions.{$key}", [], 'pt_BR'))->not->toBe("manager_subscriptions.{$key}")
                ->and(__("manager_subscriptions.{$key}", [], 'en'))->not->toBe("manager_subscriptions.{$key}")
                ->and(__("manager_subscriptions.{$key}", [], 'en'))->not->toBe(__("manager_subscriptions.{$key}", [], 'pt_BR'));
        }
    });
});
