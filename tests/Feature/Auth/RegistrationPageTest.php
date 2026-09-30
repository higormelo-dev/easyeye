<?php

declare(strict_types=1);

use App\Models\{Plan, Subscription, SubscriptionSetting};
use App\Services\Auth\PhoneVerificationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

it('mantém o plano escolhido na landing até criar o trial com o prazo anunciado', function () {
    Event::fake([Registered::class]);
    $this->mock(PhoneVerificationService::class)->shouldReceive('sendCode')->once();
    $this->travelTo(now()->startOfSecond());
    SubscriptionSetting::setValue('trial_days', 9);
    Plan::factory()->create(['active' => true, 'sort_order' => 1]);
    $chosen = Plan::factory()->create(['active' => true, 'sort_order' => 2]);

    $this->get(route('site.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('trialDays', 9)
            ->where('plans.1.register_url', route('register', ['plan' => $chosen->id])));

    $this->get(route('register', ['plan' => $chosen->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->where('selectedPlanId', $chosen->id)
            ->where('trialDays', 9)
            ->where('plans.1.trial_days', 9));

    $this->postJson(route('register'), [
        'name'                  => 'Pessoa de Teste',
        'email'                 => 'plano@example.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'company_name'          => 'Clínica de Teste',
        'company_phone'         => '11988887777',
        'plan_id'               => $chosen->id,
    ])->assertOk();

    $subscription = Subscription::query()->sole();
    expect($subscription->plan_id)->toBe($chosen->id)
        ->and($subscription->trial_ends_at->equalTo(now()->addDays(9)))->toBeTrue();
});

it('usa o primeiro plano ativo quando a escolha recebida não pode ser usada', function (string $scenario) {
    $inactive = Plan::factory()->create(['active' => false, 'sort_order' => 0]);
    $first    = Plan::factory()->create(['active' => true, 'sort_order' => 1]);
    Plan::factory()->create(['active' => true, 'sort_order' => 2]);
    $deleted = Plan::factory()->create(['active' => true, 'sort_order' => 3]);
    $deleted->delete();

    $query = match ($scenario) {
        'missing'   => [],
        'inactive'  => ['plan' => $inactive->id],
        'deleted'   => ['plan' => $deleted->id],
        'unknown'   => ['plan' => '00000000-0000-0000-0000-000000000000'],
        'malformed' => ['plan' => 'not-a-uuid'],
        'array'     => ['plan' => [$first->id]],
    };

    $this->get(route('register', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('plans', 2)
            ->where('selectedPlanId', $first->id));
})->with(['missing', 'inactive', 'deleted', 'unknown', 'malformed', 'array']);

it('mantém seleção vazia quando não há plano ativo', function () {
    Plan::factory()->create(['active' => false]);

    $this->get(route('register', ['plan' => 'not-a-uuid']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('plans', [])
            ->where('selectedPlanId', null));
});

it('encaminha ao contato quando o período de teste está indisponível', function (int $days) {
    SubscriptionSetting::setValue('trial_days', $days);

    $this->get(route('register'))
        ->assertRedirect(route('site.home') . '#contato');
})->with([0, -1]);
