<?php

use App\Domains\AI\Models\{AiCreditLedgerEntry, AiCreditWallet, AiRun};
use App\Domains\AI\Services\{AiAssistantWidgetPropsBuilder, AiCreditWalletService, AiPaywallService, AiQuotaService};
use App\Enums\AI\AiLedgerEntryType;
use App\Enums\{ClientRule, FeatureKey, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, Subscription, User};
use App\Services\Billing\AiCreditPackCheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
 * Paywall da IA: saldo insuficiente responde 422 com código estável
 * (ai_insufficient_credits) e o que mostrar — mensagem por situação e se o
 * usuário pode comprar créditos ("Comprar créditos") ou deve pedir ao
 * administrador. Acesso limitado pela régua orienta a regularizar o pagamento.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-04 10:00:00');

    $this->entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->entity->skipAutoTrial = true;
    $this->entity->save();

    $this->plan = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasAiReportDrafting)->for($this->plan)->create();
    PlanFeature::factory()->enabled(FeatureKey::HasAiChatAssistant)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 80)->for($this->plan)->create();

    $this->admin    = User::factory()->create();
    $this->adminEU  = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
    $this->doctor   = User::factory()->create();
    $this->doctorEU = createEntityUser($this->entity, $this->doctor, ClientRule::Doctor->value);
});

afterEach(fn () => Carbon::setTestNow());

function paywallSubscription(Entity $entity, Plan $plan, array $attributes = []): Subscription
{
    return Subscription::factory()->create([
        'entity_id' => $entity->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'gateway'   => 'asaas',
        'starts_at' => now(),
        'ends_at'   => now()->addMonth(),
        ...$attributes,
    ]);
}

test('cortesia sem créditos: 422 com código estável e oferta de compra para o admin', function () {
    paywallSubscription($this->entity, $this->plan, [
        'billing_mode' => SubscriptionBillingMode::Complimentary,
        'gateway'      => null,
    ]);

    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->postJson(route('panel.ai-runs.store'), baseRunPayload());

    $response->assertStatus(422)
        ->assertJsonPath('code', 'ai_insufficient_credits')
        ->assertJsonPath('details.available', 0)
        ->assertJsonPath('paywall.code', 'ai_insufficient_credits')
        ->assertJsonPath('paywall.reason', 'complimentary')
        ->assertJsonPath('paywall.can_purchase', true)
        ->assertJsonPath('paywall.texts.message', __('ai.paywall.reasons.complimentary'))
        ->assertJsonPath('paywall.texts.buy', __('ai.paywall.buy'));

    expect($response->json('paywall.purchase_url'))->toEndWith('/panel/ai/usage#ai-credit-packages')
        ->and(AiRun::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
});

test('franquia do mês esgotada: médico não compra, é orientado a pedir ao administrador', function () {
    paywallSubscription($this->entity, $this->plan, ['last_payment_at' => now()]);
    app(AiCreditWalletService::class)->reserve($this->entity->id, 80);

    $response = $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEU))
        ->postJson(route('panel.ai-runs.store'), baseRunPayload());

    $response->assertStatus(422)
        ->assertJsonPath('code', 'ai_insufficient_credits')
        ->assertJsonPath('paywall.reason', 'quota_exhausted')
        ->assertJsonPath('paywall.can_purchase', false)
        ->assertJsonPath('paywall.purchase_url', null)
        ->assertJsonPath('paywall.renews_on', '2026-11-04')
        ->assertJsonPath('paywall.texts.ask_admin', __('ai.paywall.ask_admin'));

    // A data vai crua para o front formatar no idioma.
    expect($response->json('paywall.texts.message'))->toContain(':date');
});

test('trial sem franquia: mensagem própria do período de teste', function () {
    paywallSubscription($this->entity, $this->plan, [
        'status'        => SubscriptionStatus::Trial,
        'billing_mode'  => null,
        'gateway'       => null,
        'trial_ends_at' => now()->addDays(7),
        'ends_at'       => null,
    ]);

    $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->postJson(route('panel.ai-runs.store'), baseRunPayload())
        ->assertStatus(422)
        ->assertJsonPath('paywall.reason', 'trial')
        ->assertJsonPath('paywall.texts.message', __('ai.paywall.reasons.trial'));
});

test('cortesia com a cota que sobrou da assinatura paga: medidor e paywall contam a mesma história', function () {
    // Convertida em cortesia pelas migrações (vale até 31/12), com a
    // concessão antiga (até 15/11) ainda na carteira.
    $courtesy = paywallSubscription($this->entity, $this->plan, [
        'billing_mode' => SubscriptionBillingMode::Complimentary,
        'gateway'      => null,
        'ends_at'      => '2026-12-31 23:59:59',
    ]);
    $wallet = app(AiCreditWalletService::class);
    $wallet->grantMonthlyQuota($this->entity->id, 80, CarbonImmutable::parse('2026-11-15 23:59:59'), subscriptionId: $courtesy->id);
    $wallet->reserve($this->entity->id, 80);

    // Medidor: sem "renova em" — a cota vale até 15/11.
    expect(app(AiQuotaService::class)->snapshot($this->entity->id))
        ->toMatchArray(['monthly_quota' => 80, 'consumed_credits' => 80, 'renews_on' => null, 'expires_on' => '2026-11-15']);

    // Paywall: a cota que restava (válida até 15/11) acabou e não renova.
    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->postJson(route('panel.ai-runs.store'), baseRunPayload());

    $response->assertStatus(422)
        ->assertJsonPath('paywall.reason', 'complimentary')
        ->assertJsonPath('paywall.renews_on', null)
        ->assertJsonPath('paywall.expires_on', '2026-11-15')
        ->assertJsonPath('paywall.can_purchase', true)
        ->assertJsonPath('paywall.texts.message', __('ai.paywall.reasons.residual_exhausted'));

    expect($response->json('paywall.texts.message'))->toContain(':date')
        ->and(__('ai.paywall.reasons.residual_exhausted', [], 'en'))->toContain(':date')->toContain('will not renew');

    // Passado o fim da cota que sobrou, volta a mensagem da cortesia, sem data.
    Carbon::setTestNow('2026-11-16 09:00:00');
    $paywall = app(AiPaywallService::class)->describe($this->entity->id, ClientRule::Admin->value);

    expect($paywall['expires_on'])->toBeNull()
        ->and($paywall['texts']['message'])->toBe(__('ai.paywall.reasons.complimentary'))
        ->and(app(AiQuotaService::class)->snapshot($this->entity->id)['expires_on'])->toBeNull();
});

test('contratação aguardando o 1º pagamento: a franquia começa quando o pagamento for confirmado', function () {
    paywallSubscription($this->entity, $this->plan, [
        'status'          => SubscriptionStatus::PastDue,
        'billing_mode'    => SubscriptionBillingMode::Gateway,
        'last_payment_at' => null,
        'ends_at'         => now()->addDays(3)->endOfDay(),
        'next_billing_at' => now()->addDays(3)->endOfDay(),
    ]);

    $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->postJson(route('panel.ai-runs.store'), baseRunPayload())
        ->assertStatus(422)
        ->assertJsonPath('paywall.reason', 'awaiting_payment')
        ->assertJsonPath('paywall.renews_on', null)
        ->assertJsonPath('paywall.can_purchase', true)
        ->assertJsonPath('paywall.texts.message', __('ai.paywall.reasons.awaiting_payment'));

    app()->setLocale('en');

    expect(app(AiPaywallService::class)->describe($this->entity->id, ClientRule::Admin->value)['texts']['message'])
        ->toBe('The monthly AI allowance starts once the first invoice payment is confirmed. Until then, the clinic needs extra credits to use AI.');
});

test('virada da janela antes do agendador: a execução recebe a janela vigente em vez do paywall', function () {
    Queue::fake();

    // Paga em 04/10: janela 04/10–04/11, toda usada.
    $subscription = paywallSubscription($this->entity, $this->plan, ['last_payment_at' => now(), 'ends_at' => '2026-11-04 23:59:59']);
    app(AiCreditWalletService::class)->reserve($this->entity->id, 80);

    // 00:05 do dia da virada — o ai:grant-monthly-quotas só roda às 00:20.
    Carbon::setTestNow('2026-11-04 00:05:00');

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEU))
        ->postJson(route('panel.ai-runs.store'), baseRunPayload())
        ->assertCreated();

    $wallet = AiCreditWallet::query()->where('entity_id', $this->entity->id)->sole();
    $grants = fn () => AiCreditLedgerEntry::query()->where('entity_id', $this->entity->id)->where('type', AiLedgerEntryType::Grant->value)->count();

    expect($wallet->monthly_quota)->toBe(80)
        ->and($wallet->monthly_quota_used)->toBeGreaterThan(0)
        ->and($wallet->quota_period_ends_at->toDateString())->toBe('2026-12-04')
        ->and($grants())->toBe(2);

    // O agendador (mesma chave da janela) não concede de novo nem zera o consumo.
    $used = $wallet->monthly_quota_used;
    Carbon::setTestNow('2026-11-04 00:20:00');
    $this->artisan('ai:grant-monthly-quotas')->assertSuccessful();

    expect($grants())->toBe(2)
        ->and($wallet->fresh()->monthly_quota_used)->toBe($used)
        ->and(AiCreditLedgerEntry::query()->where('idempotency_key', "ai-quota-window:{$subscription->id}:2026-11-04")->count())->toBe(1);
});

test('acesso limitado pela régua: orienta a regularizar o pagamento, sem compra', function () {
    paywallSubscription($this->entity, $this->plan, [
        'status'          => SubscriptionStatus::PastDue,
        'last_payment_at' => now()->subMonth(),
        'ends_at'         => now()->subDays(4)->endOfDay(),
        'past_due_at'     => now()->subDays(4)->endOfDay(),
    ]);

    $paywall = app(AiPaywallService::class)->describe($this->entity->id, ClientRule::Admin->value);

    expect($paywall['reason'])->toBe('limited')
        ->and($paywall['can_purchase'])->toBeFalse()
        ->and($paywall['texts']['limited'])->toBe(__('ai.paywall.reasons.limited'));

    config(['billing.enforce_subscription_access' => true]);

    $this->actingAs($this->doctor)
        ->withSession(panelSession($this->doctorEU))
        ->postJson(route('panel.ai-runs.store'), baseRunPayload())
        ->assertStatus(402)
        ->assertJsonPath('access_level', 'limited');
});

test('textos do paywall seguem o idioma do usuário', function () {
    paywallSubscription($this->entity, $this->plan, [
        'billing_mode' => SubscriptionBillingMode::Complimentary,
        'gateway'      => null,
    ]);

    app()->setLocale('en');
    $paywall = app(AiPaywallService::class)->describe($this->entity->id, ClientRule::Admin->value);

    expect($paywall['texts']['buy'])->toBe('Buy credits')
        ->and($paywall['texts']['message'])->toContain('complimentary');
});

test('assistente flutuante (médico) recebe medidor da carteira e paywall sem compra', function () {
    paywallSubscription($this->entity, $this->plan, ['last_payment_at' => now()]);
    app(AiCreditWalletService::class)->reserve($this->entity->id, 30);

    $props = app(AiAssistantWidgetPropsBuilder::class)->build($this->entity->id, ClientRule::Doctor->value);

    expect($props['enabled'])->toBeTrue()
        ->and($props['quota']['monthly_quota'])->toBe(80)
        ->and($props['quota']['consumed_credits'])->toBe(30)
        ->and($props['quota']['renews_on'])->toBe('2026-11-04')
        ->and($props['paywall']['can_purchase'])->toBeFalse();
});

test('compra de créditos aceita plano só com recurso de IA de imagem ou chat (mesmo critério do uso)', function () {
    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasAiChatAssistant)->for($plan)->create();
    paywallSubscription($this->entity, $plan, [
        'billing_mode' => SubscriptionBillingMode::Complimentary,
        'gateway'      => null,
    ]);

    expect(app(AiPaywallService::class)->describe($this->entity->id, ClientRule::Admin->value)['can_purchase'])->toBeTrue();

    // Mesma regra no checkout do pacote (a compra é por Minha assinatura).
    expect(app(AiCreditPackCheckoutService::class)->ineligibility($this->entity))->toBeNull();
});
