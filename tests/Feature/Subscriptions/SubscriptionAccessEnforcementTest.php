<?php

declare(strict_types=1);

use App\Enums\{ClientRule, SaasRule, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\Billing\{Cancellation, SubscriptionChange};
use App\Models\{Entity, Plan, Subscription, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Bloqueio do painel da clínica sem assinatura com acesso. Não há período de
 * graça: acabou o trial (ou o período), o painel manda para
 * /subscription/expired até a empresa contratar um plano. O phpunit.xml
 * desliga o bloqueio para o resto da suíte; aqui ele é ligado.
 */
beforeEach(function () {
    config(['billing.enforce_subscription_access' => true]);

    $this->plan = Plan::factory()->create(['active' => true, 'name' => 'Pro', 'sort_order' => 1]);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Bloqueio']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->user   = User::factory()->create();
    $this->member = createEntityUser($this->clinic, $this->user, ClientRule::Admin->value);
});

function blockedClinicAs(array $session = []): mixed
{
    return test()->actingAs(test()->user)->withSession([...panelSession(test()->member), ...$session]);
}

function blockedClinicSubscription(array $attributes): Subscription
{
    return Subscription::factory()->for(test()->clinic)->for(test()->plan)->create($attributes);
}

it('trial dentro do prazo usa o painel', function () {
    Subscription::factory()->trial(5)->for($this->clinic)->for($this->plan)->create();

    blockedClinicAs()->get(route('panel.dashboard'))->assertOk();
});

it('trial vencido bloqueia na hora, antes mesmo do job da madrugada', function () {
    blockedClinicSubscription([
        'status'        => SubscriptionStatus::Trial,
        'billing_mode'  => null,
        'trial_ends_at' => now()->subMinute(),
        'ends_at'       => null,
    ]);

    blockedClinicAs()->get(route('panel.dashboard'))
        ->assertRedirect(route('subscription.expired'));

    // Chamadas JSON (axios) recebem 402 com a mensagem traduzida.
    blockedClinicAs()->getJson(route('panel.dashboard'))
        ->assertStatus(402)
        ->assertJsonPath('message', __('subscriptions.access_blocked'));
});

it('bloqueia sem assinatura com acesso — não há período de graça', function (array $attributes) {
    if ($attributes !== []) {
        blockedClinicSubscription($attributes);
    }

    blockedClinicAs()->get(route('panel.dashboard'))
        ->assertRedirect(route('subscription.expired'));
})->with([
    // Closures: avaliadas na hora do teste, já no fuso da aplicação.
    'sem assinatura'   => [fn () => []],
    'trial expirado'   => [fn () => ['status' => SubscriptionStatus::Expired, 'billing_mode' => null, 'trial_ends_at' => now()->subDays(2), 'ends_at' => null]],
    'cortesia vencida' => [fn () => ['status' => SubscriptionStatus::Active, 'billing_mode' => SubscriptionBillingMode::Complimentary, 'ends_at' => now()->subMinute()]],
    'cancelada'        => [fn () => ['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now(), 'ends_at' => now()->addMonth()]],
    // Graça gravada antes da mudança não segura mais o acesso.
    'graça antiga no banco' => [fn () => ['status' => SubscriptionStatus::Expired, 'ends_at' => now()->subDay(), 'grace_period_ends_at' => now()->addDays(2)]],
    // Contratação cuja cobrança nem chegou a ser emitida no gateway (sem
    // gateway): não há o que pagar, logo não há acesso.
    'contratação sem cobrança emitida' => [fn () => ['status' => SubscriptionStatus::PastDue, 'billing_state' => 'pending_activation', 'ends_at' => now()->addMonth()]],
    // D5: a contratação vale até o fim do dia do 1º vencimento — vencido, bloqueia.
    'contratação com o 1º vencimento passado' => [fn () => ['status' => SubscriptionStatus::PastDue, 'billing_state' => 'pending_activation', 'gateway' => 'asaas', 'last_payment_at' => null, 'next_billing_at' => now()->subDay()->endOfDay(), 'ends_at' => now()->subDay()->endOfDay()]],
]);

it('assinatura vigente usa o painel', function (array $attributes) {
    blockedClinicSubscription($attributes);

    blockedClinicAs()->get(route('panel.dashboard'))->assertOk();
})->with([
    'cortesia'            => [fn () => ['status' => SubscriptionStatus::Active, 'billing_mode' => SubscriptionBillingMode::Complimentary, 'ends_at' => now()->addMonth()]],
    'cobrança automática' => [fn () => ['status' => SubscriptionStatus::Active, 'billing_mode' => SubscriptionBillingMode::Gateway, 'ends_at' => now()->addMonth()]],
    'antiga sem término'  => [fn () => ['status' => SubscriptionStatus::Active, 'billing_mode' => SubscriptionBillingMode::Complimentary, 'ends_at' => null]],
    // D5: contratação por boleto/Pix com a 1ª cobrança emitida e a vencer.
    'aguardando 1º pagamento (cobrança emitida, a vencer)' => [fn () => ['status' => SubscriptionStatus::PastDue, 'billing_mode' => SubscriptionBillingMode::Gateway, 'billing_state' => 'pending_activation', 'gateway' => 'asaas', 'last_payment_at' => null, 'next_billing_at' => now()->addDays(2)->endOfDay(), 'ends_at' => now()->addDays(2)->endOfDay()]],
    // D5: no próprio dia do vencimento ainda vale.
    'aguardando 1º pagamento (vence hoje)' => [fn () => ['status' => SubscriptionStatus::PastDue, 'billing_mode' => SubscriptionBillingMode::Gateway, 'billing_state' => 'pending_activation', 'gateway' => 'asaas', 'last_payment_at' => null, 'next_billing_at' => now()->endOfDay(), 'ends_at' => now()->endOfDay()]],
]);

it('cancelar pelo manager tira o acesso na hora, sem período de graça', function () {
    $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $admin = User::factory()->create();
    createEntityUser($saas, $admin, SaasRule::Admin->value);

    $sub = Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create();

    $this->actingAs($admin)->withSession([
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => SaasRule::Admin->value,
    ])->postJson(route('manager.subscriptions.cancel'), [
        'entity_id' => $this->clinic->id,
        'reason'    => 'Clínica encerrou o contrato no fim do mês (ticket #77).',
    ])->assertOk();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($sub->getRawOriginal('grace_period_ends_at'))->toBeNull()
        ->and($sub->hasAccess())->toBeFalse()
        ->and(SubscriptionChange::where('subscription_id', $sub->id)->sole()->metadata)->not->toHaveKey('grace_days')
        ->and(Cancellation::where('subscription_id', $sub->id)->sole()->metadata)->toBeNull();

    blockedClinicAs()->get(route('panel.dashboard'))->assertRedirect(route('subscription.expired'));
});

it('o perfil do próprio usuário continua aberto com o acesso bloqueado (LGPD)', function () {
    blockedClinicAs()->get(route('panel.profile.edit'))->assertOk();
});

it('equipe do SaaS não passa pelo bloqueio', function () {
    $saas   = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $admin  = User::factory()->create();
    $member = createEntityUser($saas, $admin, SaasRule::Admin->value);

    $this->actingAs($admin)
        ->withSession([...panelSession($member), 'selected_entity_is_client' => false])
        ->get(route('panel.dashboard'))
        ->assertRedirect(route('manager.dashboard'));
});

it('suporte impersonando a clínica bloqueada vê o painel', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->withSession([
        ...panelSession($this->member),
        'impersonating' => ['entity_user_id' => $this->member->id, 'original_user_id' => $staff->id],
    ])->get(route('panel.dashboard'))->assertOk();
});

it('a chave de emergência desliga o bloqueio', function () {
    config(['billing.enforce_subscription_access' => false]);

    blockedClinicAs()->get(route('panel.dashboard'))->assertOk();
});

describe('tela de acesso bloqueado', function () {
    it('explica que o teste grátis terminou', function () {
        Subscription::factory()->expiredTrial()->for($this->clinic)->for($this->plan)->create();

        blockedClinicAs()->get(route('subscription.expired'))->assertOk()
            // Mesmo HTML/CSS do painel (AppLayout); no 'app' a tela abria sem estilo.
            ->assertViewIs('panel-app')
            ->assertInertia(fn (Assert $page) => $page->component('Panel/SubscriptionExpired')
                ->where('lastSubscription.was_trial', true)
                ->where('lastSubscription.status', 'expired')
                ->where('t.heading_trial', __('subscriptions.expired_page.heading_trial'))
                ->missing('t.grace_period'));
    });

    it('avisa que o acesso volta quando o pagamento for confirmado', function () {
        blockedClinicSubscription([
            'status'        => SubscriptionStatus::PastDue,
            'billing_mode'  => SubscriptionBillingMode::Gateway,
            'billing_state' => 'pending_activation',
            'ends_at'       => now()->addMonth(),
        ]);

        blockedClinicAs()->get(route('subscription.expired'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('lastSubscription.status', 'past_due')
                ->where('lastSubscription.was_trial', false));
    });

    it('quem tem acesso volta para o painel', function () {
        Subscription::factory()->trial(3)->for($this->clinic)->for($this->plan)->create();

        blockedClinicAs()->get(route('subscription.expired'))->assertRedirect(route('panel.dashboard'));
    });
});
