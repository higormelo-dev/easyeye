<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiCreditWallet;
use App\Domains\AI\Services\{AiCreditWalletService, AiPaywallService, AiQuotaService};
use App\Enums\{ClientRule, FeatureKey, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, Subscription, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
 * Paywall e medidor contam a mesma história:
 *  - IA-1: ainda há saldo, só que menos do que a solicitação pede — o aviso
 *    não diz que a franquia "acabou": diz quanto falta;
 *  - IA-2: cliente em atraso cuja renovação da franquia cai no acesso
 *    limitado da régua — "renova em" só se o pagamento for confirmado.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-04 10:00:00');

    $this->entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->entity->skipAutoTrial = true;
    $this->entity->save();

    $this->plan = Plan::factory()->create(['active' => true]);
    PlanFeature::factory()->enabled(FeatureKey::HasAiReportDrafting)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 80)->for($this->plan)->create();

    $this->admin   = User::factory()->create();
    $this->adminEU = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

afterEach(fn () => Carbon::setTestNow());

/** Carteira com a janela da franquia terminando em `$windowEnd` e toda usada. */
function shortfallExhaustedWindow(Subscription $subscription, string $windowEnd): void
{
    $wallets = app(AiCreditWalletService::class);
    $wallets->grantMonthlyQuota((string) $subscription->entity_id, 80, CarbonImmutable::parse($windowEnd), subscriptionId: $subscription->id);
    $wallets->reserve((string) $subscription->entity_id, 80);
}

/** Cliente pagante com a cobrança de 03/10 vencida: em 04/10 está no D+1 (acesso total). */
function shortfallOverdue(Entity $entity, Plan $plan): Subscription
{
    return Subscription::factory()->for($entity)->for($plan)->create([
        'status'          => SubscriptionStatus::PastDue,
        'billing_mode'    => SubscriptionBillingMode::Gateway,
        'gateway'         => 'asaas',
        'last_payment_at' => '2026-09-03 10:00:00',
        'past_due_at'     => '2026-10-03 23:59:59',
        'ends_at'         => '2026-10-03 23:59:59',
    ]);
}

test('ainda há franquia, só que menos do que a solicitação pede: 422 diz quanto falta, não que acabou', function () {
    Subscription::factory()->for($this->entity)->for($this->plan)->create([
        'status'          => SubscriptionStatus::Active,
        'gateway'         => 'asaas',
        'last_payment_at' => now(),
        'ends_at'         => now()->addMonth(),
    ]);
    // Franquia de 80: 79 usados, 1 restante; sem avulso.
    app(AiCreditWalletService::class)->reserve($this->entity->id, 79);

    $response = $this->actingAs($this->admin)
        ->withSession(panelSession($this->adminEU))
        ->postJson(route('panel.ai-runs.store'), baseRunPayload())
        ->assertStatus(422)
        ->assertJsonPath('details.available', 1)
        ->assertJsonPath('paywall.reason', 'insufficient_for_request')
        ->assertJsonPath('paywall.available', 1)
        ->assertJsonPath('paywall.can_purchase', true)
        ->assertJsonPath('paywall.texts.title', __('ai.paywall.title_insufficient'));

    $requested = (int) $response->json('details.requested');
    $message   = $response->json('paywall.texts.message');

    expect($requested)->toBeGreaterThan(1)
        ->and($response->json('paywall.requested'))->toBe($requested)
        ->and($message)->toContain("precisa de {$requested} créditos")
        ->and($message)->toContain('o saldo disponível é 1')
        ->and($message)->not->toContain('acabou')
        ->and($message)->not->toBe(__('ai.paywall.reasons.quota_exhausted'));
});

test('quanto falta: singular/plural e números no idioma', function () {
    Subscription::factory()->for($this->entity)->for($this->plan)->create(['last_payment_at' => now(), 'gateway' => 'asaas']);
    $paywall = app(AiPaywallService::class);

    expect($paywall->describe($this->entity->id, ClientRule::Admin->value, requested: 4, available: 3)['texts']['message'])
        ->toEndWith('Falta 1 crédito.')
        ->and($paywall->describe($this->entity->id, ClientRule::Admin->value, requested: 2000, available: 1500)['texts']['message'])
        ->toBe('Saldo de IA insuficiente para esta solicitação: ela precisa de 2.000 créditos e o saldo disponível é 1.500. Faltam 500 créditos.');

    app()->setLocale('en');

    expect($paywall->describe($this->entity->id, ClientRule::Admin->value, requested: 4, available: 3)['texts']['message'])
        ->toEndWith('You are 1 credit short.')
        ->and($paywall->describe($this->entity->id, ClientRule::Admin->value, requested: 2000, available: 1500)['texts']['message'])
        ->toContain('it needs 2,000 credits and the available balance is 1,500. You are 500 credits short.');
});

test('sem saldo nenhum continua a mensagem da situação (franquia acabou)', function () {
    Subscription::factory()->for($this->entity)->for($this->plan)->create(['last_payment_at' => now(), 'gateway' => 'asaas']);

    $paywall = app(AiPaywallService::class)->describe($this->entity->id, ClientRule::Admin->value, requested: 4, available: 0);

    expect($paywall['reason'])->toBe('quota_exhausted')
        ->and($paywall['requested'])->toBeNull()
        ->and($paywall['texts']['title'])->toBe(__('ai.paywall.title'));
});

test('em atraso com a renovação no acesso limitado: medidor e paywall condicionam ao pagamento', function () {
    $subscription = shortfallOverdue($this->entity, $this->plan);
    // D+1 (acesso total); o acesso limitado começa em 06/10 e a janela vira em 07/10.
    shortfallExhaustedWindow($subscription, '2026-10-07 00:00:00');

    expect(app(AiQuotaService::class)->snapshot($this->entity->id))
        ->toMatchArray(['monthly_quota' => 80, 'renews_on' => null, 'renews_if_paid_on' => '2026-10-07', 'expires_on' => null]);

    $paywall = app(AiPaywallService::class)->describe($this->entity->id, ClientRule::Admin->value);

    expect($paywall['reason'])->toBe('quota_exhausted')
        ->and($paywall['renews_on'])->toBeNull()
        ->and($paywall['renews_if_paid_on'])->toBe('2026-10-07')
        ->and($paywall['texts']['message'])->toBe(__('ai.paywall.reasons.quota_exhausted_if_paid'))
        ->and($paywall['texts']['message'])->toContain(':date')
        ->and(__('ai.paywall.reasons.quota_exhausted_if_paid', [], 'en'))->toContain('if the payment is confirmed');
});

test('em atraso com a renovação ainda no acesso total: a renovação acontece, sem condição', function () {
    $subscription = shortfallOverdue($this->entity, $this->plan);
    // A janela vira em 05/10 (D+2, acesso total): a concessão acontece.
    shortfallExhaustedWindow($subscription, '2026-10-05 00:00:00');

    expect(app(AiQuotaService::class)->snapshot($this->entity->id))
        ->toMatchArray(['renews_on' => '2026-10-05', 'renews_if_paid_on' => null]);

    expect(app(AiPaywallService::class)->describe($this->entity->id, ClientRule::Admin->value))
        ->toMatchArray(['renews_on' => '2026-10-05', 'renews_if_paid_on' => null])
        ->and(AiCreditWallet::query()->where('entity_id', $this->entity->id)->sole()->monthly_quota)->toBe(80);
});
