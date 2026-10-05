<?php

use App\Enums\{BillingCycle, SaasRule};
use App\Enums\FeatureKey;
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Support\Billing\PlanPricing;

/**
 * Manager → Planos: preços por ciclo de cobrança (o cliente escolhe no site),
 * slug estável, limites numéricos e ligação com as assinaturas.
 */
beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);
});

function plansAs(): mixed
{
    return test()->actingAs(test()->admin)->withSession([
        'selected_entity_id' => test()->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'admin',
    ]);
}

/** @return array<string, mixed> */
function planPayload(array $overrides = []): array
{
    return [
        'name'          => 'Pro',
        'description'   => 'Plano para clínicas em crescimento',
        'billing_cycle' => 'monthly',
        'prices'        => [
            ['billing_cycle' => 'monthly', 'price' => 299.90],
            ['billing_cycle' => 'yearly', 'price' => 2878.99],
        ],
        'active'      => true,
        'is_featured' => true,
        'sort_order'  => 2,
        'features'    => ['max_users' => 10, 'max_doctors' => 3, 'has_inventory_module' => '1'],
        ...$overrides,
    ];
}

function plansClinic(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

it('cria o plano com preço por ciclo; o preço de referência é o do ciclo padrão', function () {
    plansAs()->postJson(route('manager.plans.store'), planPayload())->assertCreated();

    $plan = Plan::with('prices')->sole();

    expect($plan->price)->toEqual('299.90')
        ->and($plan->is_featured)->toBeTrue()
        ->and($plan->cyclePrices())->toBe(['monthly' => 299.9, 'yearly' => 2878.99])
        ->and($plan->priceFor(BillingCycle::Yearly))->toBe(2878.99)
        ->and($plan->featureValue(FeatureKey::MaxUsers))->toBe('10');
});

it('valida ciclos: padrão precisa ser oferecido e sem repetir', function () {
    plansAs()->postJson(route('manager.plans.store'), planPayload(['billing_cycle' => 'quarterly']))
        ->assertUnprocessable()->assertJsonValidationErrors('billing_cycle');

    plansAs()->postJson(route('manager.plans.store'), planPayload(['prices' => [
        ['billing_cycle' => 'monthly', 'price' => 10],
        ['billing_cycle' => 'monthly', 'price' => 20],
    ]]))->assertUnprocessable()->assertJsonValidationErrors('prices.1.billing_cycle');

    plansAs()->postJson(route('manager.plans.store'), planPayload(['prices' => [
        ['billing_cycle' => 'monthly', 'price' => 10],
        ['billing_cycle' => 'weekly', 'price' => 5],
    ]]))->assertUnprocessable()->assertJsonValidationErrors('prices.1.billing_cycle');

    plansAs()->postJson(route('manager.plans.store'), planPayload(['prices' => []]))
        ->assertUnprocessable()->assertJsonValidationErrors('prices');

    expect(Plan::count())->toBe(0);
});

it('vitalício não é ciclo à venda: recusado no preço e como ciclo padrão', function () {
    plansAs()->postJson(route('manager.plans.store'), planPayload([
        'prices' => [
            ['billing_cycle' => 'monthly', 'price' => 299.90],
            ['billing_cycle' => 'lifetime', 'price' => 9900],
        ],
    ]))->assertUnprocessable()->assertJsonValidationErrors('prices.1.billing_cycle');

    plansAs()->postJson(route('manager.plans.store'), planPayload([
        'name'          => 'Vitalício',
        'billing_cycle' => 'lifetime',
        'prices'        => [['billing_cycle' => 'lifetime', 'price' => 12000]],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['billing_cycle', 'prices.0.billing_cycle']);

    expect(Plan::count())->toBe(0);

    plansAs()->get(route('manager.plans.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('billingCycles', 4)
            ->where('billingCycles', fn ($cycles) => ! collect($cycles)->contains('value', 'lifetime')));
});

it('limites chegam como número do formulário; vazio mostra erro no campo', function () {
    plansAs()->postJson(route('manager.plans.store'), planPayload(['features' => ['max_users' => 0, 'has_ai_consensus' => '0']]))
        ->assertCreated();

    plansAs()->postJson(route('manager.plans.store'), planPayload(['name' => 'Outro', 'features' => ['max_users' => '', 'max_doctors' => -1]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['features.max_users', 'features.max_doctors']);
});

it('renomear não muda o slug; nome repetido ganha slug único', function () {
    plansAs()->postJson(route('manager.plans.store'), planPayload(['name' => 'Premium']))->assertCreated();
    plansAs()->postJson(route('manager.plans.store'), planPayload(['name' => 'Premium']))->assertCreated();

    // Criados no mesmo segundo: compara o conjunto, não a ordem.
    expect(Plan::pluck('slug')->sort()->values()->all())->toBe(['premium', 'premium-2']);

    $plan = Plan::where('slug', 'premium')->sole();

    plansAs()->putJson(route('manager.plans.update', $plan), planPayload([
        'name'          => 'Premium Plus',
        'billing_cycle' => 'yearly',
        'prices'        => [['billing_cycle' => 'yearly', 'price' => 19000]],
    ]))->assertOk();

    $plan->refresh();
    expect($plan->slug)->toBe('premium')
        ->and($plan->name)->toBe('Premium Plus')
        ->and($plan->billing_cycle)->toBe(BillingCycle::Yearly)
        ->and((float) $plan->price)->toBe(19000.0)
        ->and(PlanPrice::where('plan_id', $plan->id)->pluck('billing_cycle')->map->value->all())->toBe(['yearly']);
});

it('liga/desliga o plano sem exigir os demais campos', function () {
    plansAs()->postJson(route('manager.plans.store'), planPayload())->assertCreated();
    $plan = Plan::sole();

    plansAs()->putJson(route('manager.plans.update', $plan), ['active' => false])->assertOk();

    expect($plan->fresh()->active)->toBeFalse()
        ->and($plan->fresh()->prices)->toHaveCount(2);
});

it('lista preços com economia sobre o mensal e quantas empresas usam o plano agora', function () {
    plansAs()->postJson(route('manager.plans.store'), planPayload())->assertCreated();
    $plan = Plan::sole();

    // Atual e com acesso conta; histórico substituído e expirado não.
    $clinicA = plansClinic();
    Subscription::factory()->cancelled()->for($clinicA)->for($plan)->create(['created_at' => now()->subYear()]);
    Subscription::factory()->for($clinicA)->for($plan)->create();
    Subscription::factory()->trial()->for(plansClinic())->for($plan)->create();
    Subscription::factory()->complimentary()->for(plansClinic())->for($plan)->create();
    Subscription::factory()->expired()->for(plansClinic())->for($plan)->create();

    plansAs()->get(route('manager.plans.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Panel/Manager/Plans/Index')
            ->where('plans.data.0.subscribers', 3)
            ->where('plans.data.0.prices.1.cycle', 'yearly')
            ->where('plans.data.0.prices.1.savings_percent', 20)
            ->where('plans.data.0.prices.1.monthly_equivalent', 239.92)
            ->has('billingCycles', 4));

    plansAs()->getJson(route('manager.plans.show', $plan))->assertOk()
        ->assertJsonPath('data.subscribers.total', 3)
        ->assertJsonPath('data.subscribers.trial', 1)
        ->assertJsonPath('data.subscribers.gateway', 1)
        ->assertJsonPath('data.subscribers.complimentary', 1)
        ->assertJsonMissingPath('data.subscribers.lifetime')
        ->assertJsonPath('data.is_featured', true);
});

it('plano antigo sem preços por ciclo continua oferecendo o ciclo de referência', function () {
    $legacy = Plan::factory()->create(['price' => 450, 'billing_cycle' => BillingCycle::Quarterly]);

    expect($legacy->cyclePrices())->toBe(['quarterly' => 450.0])
        ->and($legacy->defaultCycle())->toBe(BillingCycle::Quarterly)
        ->and($legacy->offersCycle(BillingCycle::Monthly))->toBeFalse();

    // Plano antigo só vitalício não tem o que vender (nem preço vitalício que sobrou).
    $lifetime = Plan::factory()->lifetime()->create(['price' => 5000]);
    PlanPrice::create(['plan_id' => $lifetime->id, 'billing_cycle' => 'lifetime', 'price' => 5000]);
    $lifetime->load('prices');

    expect($lifetime->cyclePrices())->toBe([])
        ->and($lifetime->defaultCycle())->toBeNull()
        ->and($lifetime->isSellable())->toBeFalse()
        ->and(PlanPricing::cycles($lifetime))->toBe([])
        ->and($legacy->isSellable())->toBeTrue();
});
