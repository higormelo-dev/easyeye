<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, Plan, Subscription, SubscriptionSetting, User};
use App\Support\PanelNavigation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * O que o front do checkout transparente recebe do servidor: item "Minha
 * assinatura" no menu só para quem paga (admin, financeiro, dono), o aviso
 * do trial com o caminho de contratar no sistema, as traduções da página e
 * do cadastro no site, e a paridade pt_BR/en das telas.
 */
beforeEach(function () {
    config(['billing.enforce_subscription_access' => true]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 09:00:00'));

    $this->plan = Plan::factory()->create(['active' => true, 'name' => 'Pro']);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Checkout']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();
});

afterEach(fn () => Carbon::setTestNow());

function checkoutFrontMember(ClientRule $rule, bool $isOwner = false): array
{
    $user   = User::factory()->create();
    $member = createEntityUser(test()->clinic, $user, $rule->value, isOwner: $isOwner);

    return [$user, $member];
}

function checkoutFrontNavKeys(): array
{
    return collect(PanelNavigation::build())->pluck('key')->filter()->values()->all();
}

it('menu: "Minha assinatura" para admin, financeiro e dono; nunca para os demais perfis', function (ClientRule $rule, bool $isOwner, bool $visible) {
    [$user, $member] = checkoutFrontMember($rule, $isOwner);
    test()->actingAs($user);
    session(panelSession($member));

    $keys = checkoutFrontNavKeys();

    expect(in_array('my-subscription', $keys, true))->toBe($visible);

    if ($visible) {
        $item = collect(PanelNavigation::build())->firstWhere('key', 'my-subscription');
        expect($item['route'])->toBe('panel.my-subscription.index')
            ->and(fn () => route($item['route']))->not->toThrow(Throwable::class);
    }
})->with([
    'admin'             => [ClientRule::Admin, false, true],
    'financeiro'        => [ClientRule::Financial, false, true],
    'dono (secretária)' => [ClientRule::Secretary, true, true],
    'médico'            => [ClientRule::Doctor, false, false],
    'secretária'        => [ClientRule::Secretary, false, false],
]);

it('trial terminando: quem paga recebe o checkout no sistema; os demais, não', function () {
    Subscription::factory()->trial(2)->for($this->clinic)->for($this->plan)->create();

    [$admin, $adminMember] = checkoutFrontMember(ClientRule::Admin);
    $this->actingAs($admin)->withSession(panelSession($adminMember))
        ->get(route('panel.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('subscriptionBanner.kind', 'trial_ending')
            ->where('subscriptionBanner.checkout_url', route('panel.my-subscription.index'))
            ->where('subscriptionBanner.t.subscribe_now', __('subscriptions.banner.subscribe_now')));

    [$doctor, $doctorMember] = checkoutFrontMember(ClientRule::Doctor);
    $this->actingAs($doctor)->withSession(panelSession($doctorMember))
        ->get(route('panel.dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('subscriptionBanner.kind', 'trial_ending')
            ->missing('subscriptionBanner.checkout_url'));
});

it('Minha assinatura: página Vue com o resumo e as traduções da tela e do checkout', function () {
    Subscription::factory()->trial(10)->for($this->clinic)->for($this->plan)->create();
    [$admin, $member] = checkoutFrontMember(ClientRule::Admin);

    $this->actingAs($admin)->withSession(panelSession($member))
        ->get(route('panel.my-subscription.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Panel/MySubscription/Index')
            ->has('checkout.realtime.channel')
            ->where('t.page.title', __('checkout.page.title'))
            ->where('t.ui.pix_title', __('checkout.ui.pix_title'))
            ->where('t.gateways.mercadopago', 'Mercado Pago'));
});

it('cadastro no site recebe as traduções do checkout ("Contratar agora")', function () {
    SubscriptionSetting::setValue('trial_days', 14, 'trial');

    $this->get(route('register'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Register')
            ->where('tCheckout.ui.choose_method', __('checkout.ui.choose_method'))
            ->where('tAuth.register.mode_checkout', __('auth.register.mode_checkout')));
});

it('pt_BR e en têm as mesmas chaves nas traduções novas do checkout', function () {
    $keys = function (array $array, string $prefix = '') use (&$keys): array {
        $out = [];

        foreach ($array as $key => $value) {
            $out = is_array($value) ? [...$out, ...$keys($value, "{$prefix}{$key}.")] : [...$out, "{$prefix}{$key}"];
        }

        return $out;
    };

    foreach (['checkout', 'auth', 'gateways', 'manager_plans', 'subscriptions'] as $file) {
        $pt = $keys(require lang_path("pt_BR/{$file}.php"));
        $en = $keys(require lang_path("en/{$file}.php"));

        $newKeys = array_filter($pt, fn ($k) => $file === 'checkout'
            || str_contains($k, 'public_key')
            || str_contains($k, 'checkout')
            || str_contains($k, 'mode_')
            || str_contains($k, 'subscribe_now')
            || str_contains($k, 'pay_later')
            || str_contains($k, 'go_to_panel'));

        expect(array_values(array_diff($newKeys, $en)))->toBe([], "{$file}: faltam em en");
    }

    expect(__('actions.sidemenu.my_subscription', [], 'en'))->toBe('My subscription')
        ->and(__('actions.sidemenu.my_subscription', [], 'pt_BR'))->toBe('Minha assinatura');
});
