<?php

declare(strict_types=1);

/**
 * Com mais de uma assinatura acessível (trial automático ainda válido + plano
 * pago recém-contratado), vale a MAIS RECENTE — em FeatureGateService,
 * SubscriptionService::getCurrent e ApiCheckPlanAccess.
 *
 * Antes: `->first()` sem ordem dependia da ordem física no banco — o recurso do
 * plano podia ser liberado ou bloqueado conforme a consulta (e testes de IA
 * falhavam só em algumas ordens de execução da suíte).
 */

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Entity, Plan, PlanFeature, Subscription, User};
use App\Services\{FeatureGateService, SubscriptionService};

function csoEntity(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

function csoPlan(bool $withAi): Plan
{
    $plan = Plan::factory()->create(['active' => true]);

    $withAi
        ? PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($plan)->create()
        : PlanFeature::factory()->disabled(FeatureKey::HasAiExamAssistant)->for($plan)->create();

    return $plan;
}

/** Cria a assinatura com created_at explícito (a ordem física pode ser a inversa). */
function csoSubscription(Entity $entity, Plan $plan, SubscriptionStatus $status, string $createdAt): Subscription
{
    $subscription = Subscription::factory()->create([
        'entity_id'     => $entity->id,
        'plan_id'       => $plan->id,
        'status'        => $status,
        'starts_at'     => now()->subDays(10),
        'ends_at'       => now()->addMonth(),
        'trial_ends_at' => $status === SubscriptionStatus::Trial ? now()->addDays(5) : null,
    ]);

    $subscription->forceFill(['created_at' => $createdAt])->saveQuietly();

    return $subscription;
}

it('plano pago mais recente sem o recurso vence o trial antigo com o recurso (gravado DEPOIS no banco)', function (): void {
    $entity = csoEntity();

    // Ordem física invertida: o pago (mais recente) é gravado primeiro.
    $paid  = csoSubscription($entity, csoPlan(withAi: false), SubscriptionStatus::Active, now()->subHour()->toDateTimeString());
    $trial = csoSubscription($entity, csoPlan(withAi: true), SubscriptionStatus::Trial, now()->subDays(3)->toDateTimeString());

    expect(app(FeatureGateService::class)->can((string) $entity->id, FeatureKey::HasAiExamAssistant))->toBeFalse()
        ->and(app(SubscriptionService::class)->getCurrent($entity)?->id)->toBe($paid->id)
        ->and($trial->exists)->toBeTrue();
});

it('e o inverso: o mais recente com o recurso libera', function (): void {
    $entity = csoEntity();

    csoSubscription($entity, csoPlan(withAi: false), SubscriptionStatus::Trial, now()->subDays(3)->toDateTimeString());
    $paid = csoSubscription($entity, csoPlan(withAi: true), SubscriptionStatus::Active, now()->subHour()->toDateTimeString());

    expect(app(FeatureGateService::class)->can((string) $entity->id, FeatureKey::HasAiExamAssistant))->toBeTrue()
        ->and(app(SubscriptionService::class)->getCurrent($entity)?->id)->toBe($paid->id);
});

it('tela de assinatura expirada mostra a última assinatura mesmo com created_at empatado (desempate pelo id)', function (): void {
    $entity = csoEntity();
    $sameAt = now()->subMonth()->startOfSecond()->toDateTimeString();

    // UUIDv7 (HasUuids do Laravel 12): id maior = gravada depois. O menor id
    // entra primeiro, então a ordem física favorece a antiga.
    $rows = ['01920000-0000-7000-8000-000000000001' => 'PLANO ANTIGO', '01920000-0000-7000-8000-000000000002' => 'PLANO NOVO'];

    foreach ($rows as $id => $planName) {
        Subscription::factory()->create([
            'id'        => $id,
            'entity_id' => $entity->id,
            'plan_id'   => Plan::factory()->create(['active' => true, 'name' => $planName])->id,
            'status'    => SubscriptionStatus::Expired,
            'starts_at' => now()->subMonths(3),
            'ends_at'   => now()->subMonth(),
        ])->forceFill(['created_at' => $sameAt])->saveQuietly();
    }

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(panelSession(createEntityUser($entity, $user, ClientRule::Admin->value)))
        ->get(route('subscription.expired'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('lastSubscription.plan_name', 'PLANO NOVO'));
});
