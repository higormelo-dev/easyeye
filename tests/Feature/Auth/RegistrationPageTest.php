<?php

declare(strict_types=1);

use App\Models\{Plan, PlanPrice, Subscription, SubscriptionSetting, User};
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

it('[S1] com o teste grátis desligado (trial_days=0) a tela abre para os planos pagos: só some a opção gratuita', function () {
    SubscriptionSetting::setValue('trial_days', 0);
    $paid = Plan::factory()->create(['active' => true, 'sort_order' => 1, 'price' => 299.9]);
    Plan::factory()->create(['active' => true, 'sort_order' => 2, 'price' => 0]);

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->where('trialDays', 0)
            ->has('plans', 1)
            ->where('plans.0.id', $paid->id)
            ->where('plans.0.is_free', false)
            ->where('selectedPlanId', $paid->id));
});

it('[S1] trial_days=0 sem nenhum plano pago à venda continua encaminhando ao contato', function () {
    SubscriptionSetting::setValue('trial_days', 0);
    Plan::factory()->create(['active' => true, 'price' => 0]);
    Plan::factory()->create(['active' => false, 'price' => 299.9]); // pago, mas fora de venda

    $this->get(route('register'))->assertRedirect(route('site.home') . '#contato');
});

it('[S1] trial_days=0: contratar pagando (start_mode=checkout) cria a conta; o teste grátis segue recusado', function () {
    Event::fake([Registered::class]);
    SubscriptionSetting::setValue('trial_days', 0);
    $plan    = Plan::factory()->create(['active' => true, 'sort_order' => 1, 'price' => 299.9]);
    $payload = [
        'name'                  => 'Maria Souza',
        'email'                 => 'maria@clinica-s1.test',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'company_name'          => 'Clínica S1',
        'company_phone'         => '11988887777',
        'plan_id'               => $plan->id,
    ];

    $this->postJson(route('register'), $payload + ['start_mode' => 'checkout'])
        ->assertOk()
        ->assertJsonPath('checkout.contract', '/signup-checkout/contract');
    expect(User::query()->where('email', 'maria@clinica-s1.test')->exists())->toBeTrue();

    auth()->logout();
    $this->postJson(route('register'), ['email' => 'outra@clinica-s1.test'] + $payload)
        ->assertUnprocessable()
        ->assertJsonPath('errors.plan_id.0', __('auth.register.trial_unavailable'));
    expect(User::query()->where('email', 'outra@clinica-s1.test')->exists())->toBeFalse();
});

it('[S2] a checagem de e-mail do cadastro tem limite por IP (429) e não vaza a existência para quem passou do limite', function () {
    config(['billing.checkout.fraud.check_email_per_ip_minute' => 3]);
    User::factory()->create(['email' => 'existe@clinica.test']);

    foreach (range(1, 3) as $_) {
        $this->getJson(route('register.check-email', ['email' => 'existe@clinica.test']))
            ->assertOk()->assertJsonPath('available', false);
    }

    $response = $this->getJson(route('register.check-email', ['email' => 'existe@clinica.test']))->assertStatus(429);

    expect($response->json())->not->toHaveKey('available');
});

it('[S2] o limite da checagem de e-mail é por IP: outro IP não é afetado', function () {
    config(['billing.checkout.fraud.check_email_per_ip_minute' => 1]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson(route('register.check-email', ['email' => 'a@clinica.test']))->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->getJson(route('register.check-email', ['email' => 'a@clinica.test']))->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->getJson(route('register.check-email', ['email' => 'a@clinica.test']))->assertOk();
});

it('[S2] a checagem de e-mail valida e normaliza a entrada (422 para lixo; caixa alta e espaços valem como o mesmo e-mail)', function () {
    User::factory()->create(['email' => 'existe@clinica.test']);

    $this->getJson(route('register.check-email'))->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->getJson(route('register.check-email', ['email' => 'isto-nao-e-email']))->assertUnprocessable();
    $this->getJson(route('register.check-email', ['email' => str_repeat('a', 300) . '@x.test']))->assertUnprocessable();
    $this->getJson(route('register.check-email', ['email' => ['a@b.test']]))->assertUnprocessable();

    $this->getJson(route('register.check-email', ['email' => '  EXISTE@Clinica.TEST ']))
        ->assertOk()->assertJsonPath('available', false);
    $this->getJson(route('register.check-email', ['email' => 'livre@clinica.test']))
        ->assertOk()->assertJsonPath('available', true);
});

it('[S2-b] DOCUMENTA o comportamento atual: com trustProxies="*" (bootstrap/app.php) o limite por IP segue o X-Forwarded-For — quem rotaciona o cabeçalho escapa do 429', function () {
    config(['billing.checkout.fraud.check_email_per_ip_minute' => 1]);
    $call = fn (string $forwardedFor) => $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
        ->getJson(route('register.check-email', ['email' => 'a@clinica.test']), ['X-Forwarded-For' => $forwardedFor]);

    $call('203.0.113.1')->assertOk();
    $call('203.0.113.1')->assertStatus(429);   // mesmo IP anunciado: limitado
    $call('203.0.113.2')->assertOk();          // outro IP anunciado, MESMA conexão: passa (contorno)

    // Este teste fixa o estado de hoje. A decisão é de infraestrutura (restringir trustProxies ao nginx/CDN via
    // TRUSTED_PROXIES): quando ela for aplicada, o contorno some e o último assert passa a esperar 429.
});

it('[S1-b] trial_days=0 e plano de ciclos mistos (mensal 0, anual > 0): continua na tela, só com o ciclo pago', function () {
    SubscriptionSetting::setValue('trial_days', 0);
    $mixed = Plan::factory()->create(['active' => true, 'sort_order' => 1, 'price' => 0]);
    PlanPrice::query()->where('plan_id', $mixed->id)->delete();
    PlanPrice::create(['plan_id' => $mixed->id, 'billing_cycle' => 'monthly', 'price' => 0]);
    PlanPrice::create(['plan_id' => $mixed->id, 'billing_cycle' => 'yearly', 'price' => 2878.8]);
    $freeOnly = Plan::factory()->create(['active' => true, 'sort_order' => 2, 'price' => 0]);
    PlanPrice::query()->where('plan_id', $freeOnly->id)->delete();
    PlanPrice::create(['plan_id' => $freeOnly->id, 'billing_cycle' => 'monthly', 'price' => 0]);

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('plans', 1)
            ->where('plans.0.id', $mixed->id)
            ->has('plans.0.prices', 1)
            ->where('plans.0.prices.0.cycle', 'yearly')
            ->where('plans.0.is_free', false));
});
