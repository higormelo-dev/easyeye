<?php

declare(strict_types=1);

use App\Enums\{BillingCycle, SubscriptionStatus};
use App\Models\{Plan, PlanPrice, Subscription, SubscriptionSetting, User};
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\{Cache, Event};
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Site e cadastro: o cliente escolhe o ciclo de cobrança (mensal, anual...)
 * e a escolha chega até a assinatura em trial — o manager ativa a mesma
 * modalidade depois.
 */
function cyclePricingPlan(array $prices, string $name = 'Pro'): Plan
{
    $default = array_key_first($prices);
    $plan    = Plan::factory()->create([
        'name' => $name, 'active' => true, 'billing_cycle' => $default, 'price' => $prices[$default],
    ]);

    foreach ($prices as $cycle => $price) {
        PlanPrice::create(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'price' => $price]);
    }

    return $plan;
}

function cyclePricingTrialDays(int $days): void
{
    Cache::forget('subscription_setting:trial_days');
    SubscriptionSetting::setValue('trial_days', $days);
    Cache::forget('subscription_setting:trial_days');
}

function cyclePricingRegister(array $overrides): TestResponse
{
    return test()->postJson('/register', [
        'name'                  => 'Maria Souza',
        'email'                 => 'maria' . uniqid() . '@example.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'company_name'          => 'Clínica Souza',
        'company_phone'         => '11988887777',
        ...$overrides,
    ]);
}

it('a home entrega os ciclos de cada plano com o mensal equivalente e a economia', function () {
    cyclePricingPlan(['monthly' => 299.90, 'semiannual' => 1619.46, 'yearly' => 2878.99]);

    $this->get(route('site.home'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('plans.0.default_cycle', 'monthly')
            ->has('plans.0.prices', 3)
            ->where('plans.0.prices.0.cycle', 'monthly')
            ->where('plans.0.prices.0.savings_percent', 0)
            ->where('plans.0.prices.1.cycle', 'semiannual')
            ->where('plans.0.prices.1.savings_percent', 10)
            ->where('plans.0.prices.2.cycle', 'yearly')
            ->where('plans.0.prices.2.monthly_equivalent', 239.92)
            ->where('plans.0.prices.2.savings_percent', 20)
            ->where('plans.0.prices.2.period_label', '/ano'));
});

it('o cadastro abre no ciclo escolhido na landing e ignora ciclo inválido', function () {
    cyclePricingTrialDays(7);
    $plan = cyclePricingPlan(['monthly' => 299.90, 'yearly' => 2878.99]);

    $this->get(route('register', ['plan' => $plan->id, 'cycle' => 'yearly']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedPlanId', $plan->id)
            ->where('selectedCycle', 'yearly')
            ->has('plans.0.prices', 2));

    $this->get(route('register', ['plan' => $plan->id, 'cycle' => 'weekly']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('selectedCycle', null));

    // Vitalício saiu da estratégia: link antigo com ?cycle=lifetime abre sem ciclo.
    PlanPrice::create(['plan_id' => $plan->id, 'billing_cycle' => 'lifetime', 'price' => 9900]);

    $this->get(route('register', ['plan' => $plan->id, 'cycle' => 'lifetime']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selectedCycle', null)
            ->has('plans.0.prices', 2));
});

it('site e cadastro escondem plano antigo que só era vendido como vitalício', function () {
    $pro = cyclePricingPlan(['monthly' => 299.90]);
    Plan::factory()->lifetime()->create(['name' => 'Fundador', 'active' => true, 'price' => 9900]);

    $this->get(route('site.home'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('plans', 1)
            ->where('plans.0.id', $pro->id));

    $this->get(route('register'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('plans', 1)
            ->where('plans.0.id', $pro->id));
});

describe('cadastro com ciclo', function () {
    beforeEach(function () {
        Event::fake([Registered::class]);
        cyclePricingTrialDays(14);
    });

    it('guarda no trial o ciclo e o valor escolhidos', function () {
        $plan = cyclePricingPlan(['monthly' => 299.90, 'yearly' => 2878.99]);

        cyclePricingRegister(['plan_id' => $plan->id, 'billing_cycle' => 'yearly'])->assertOk();

        $trial = Subscription::sole();
        expect($trial->status)->toBe(SubscriptionStatus::Trial)
            ->and($trial->billing_mode)->toBeNull()
            ->and($trial->billing_cycle)->toBe(BillingCycle::Yearly)
            ->and((float) $trial->amount)->toBe(2878.99);
    });

    it('ciclo que o plano não vende cai no ciclo padrão do plano', function () {
        $plan = cyclePricingPlan(['monthly' => 299.90]);

        cyclePricingRegister(['plan_id' => $plan->id, 'billing_cycle' => 'quarterly'])->assertOk();

        expect(Subscription::sole()->billing_cycle)->toBe(BillingCycle::Monthly);
    });

    it('recusa vitalício no cadastro, mesmo com preço antigo no plano', function () {
        $plan = cyclePricingPlan(['monthly' => 299.90]);
        PlanPrice::create(['plan_id' => $plan->id, 'billing_cycle' => 'lifetime', 'price' => 9900]);

        cyclePricingRegister(['plan_id' => $plan->id, 'billing_cycle' => 'lifetime'])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_cycle');

        expect(User::count())->toBe(0)
            ->and(Subscription::count())->toBe(0);
    });

    it('recusa ciclo que não existe', function () {
        $plan = cyclePricingPlan(['monthly' => 299.90]);

        cyclePricingRegister(['plan_id' => $plan->id, 'billing_cycle' => 'weekly'])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_cycle');

        expect(User::count())->toBe(0);
    });
});
