<?php

declare(strict_types=1);

use App\Enums\Billing\{CancellationReason, InvoiceStatus};
use App\Enums\{ClientRule, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\Billing\Invoice;
use App\Models\{Entity, Plan, Subscription, User};
use App\Services\Billing\{BillingCancellationService, SubscriptionNoticeService};
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Textos da tela de acesso bloqueado e do aviso do painel:
 *  - a contratação aguardando o 1º pagamento não aparece como "Em atraso" e
 *    não está "encerrada" (mostra o vencimento);
 *  - cancelada pelo manager antes do fim do período: "encerrada em" é a
 *    data do cancelamento, não o término contratual futuro;
 *  - sem o link da cobrança, o aviso dá o caminho do contato a quem pode
 *    pagar (admin, financeiro e dono).
 */
beforeEach(function () {
    config(['billing.enforce_subscription_access' => true]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 10:00:00'));

    $this->plan = Plan::factory()->create(['active' => true, 'name' => 'Pro']);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Textos']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->admin    = User::factory()->create();
    $this->adminEU  = createEntityUser($this->clinic, $this->admin, ClientRule::Admin->value);
    $this->doctor   = User::factory()->create();
    $this->doctorEU = createEntityUser($this->clinic, $this->doctor, ClientRule::Doctor->value);
});

afterEach(fn () => Carbon::setTestNow());

function noticeTextsExpiredPage(): mixed
{
    return test()->actingAs(test()->admin)->withSession(panelSession(test()->adminEU))->get(route('subscription.expired'));
}

it('contratação aguardando o 1º pagamento vencida: "Aguardando 1º pagamento" e "venceu em", não "encerrada"', function () {
    Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'status'          => SubscriptionStatus::PastDue,
        'billing_state'   => 'pending_activation',
        'last_payment_at' => null,
        'next_billing_at' => '2026-10-02 23:59:59',
        'ends_at'         => '2026-11-02 23:59:59',
    ]);

    noticeTextsExpiredPage()->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Panel/SubscriptionExpired')
            ->where('lastSubscription.status', 'past_due')
            ->where('lastSubscription.status_label', __('subscriptions.status_awaiting_first_payment'))
            ->where('lastSubscription.due_at', '2026-10-02')
            ->where('lastSubscription.ends_at', null)
            ->where('t.due_on', __('subscriptions.expired_page.due_on')));
});

it('cancelada pelo manager antes do fim do período: "encerrada em" é a data do cancelamento', function () {
    $subscription = Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create([
        'ends_at' => '2026-10-30 23:59:59',
    ]);

    app(BillingCancellationService::class)->cancel(
        subscription: $subscription,
        entity: $this->clinic,
        reason: CancellationReason::AdminAction,
        source: 'manager',
    );

    noticeTextsExpiredPage()->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('lastSubscription.status', 'cancelled')
            ->where('lastSubscription.due_at', null)
            ->where('lastSubscription.ends_at', fn (?string $endedAt) => str_starts_with((string) $endedAt, '2026-10-04')));
});

it('encerrada no fim do período (antes de a expiração rodar): "encerrada em" é o término', function () {
    Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create([
        'status'       => SubscriptionStatus::Expired,
        'ends_at'      => '2026-10-01 23:59:59',
        'cancelled_at' => '2026-10-02 00:30:00',
    ]);

    noticeTextsExpiredPage()->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lastSubscription.ends_at', fn (?string $endedAt) => str_starts_with((string) $endedAt, '2026-10-01')));
});

it('encerrada pela régua (pagante, D+7): "encerrada em" é o dia do encerramento, não o vencimento não pago', function () {
    // Venceu em 26/09; a régua encerrou em 03/10 (acesso até ali).
    Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'status'          => SubscriptionStatus::Expired,
        'billing_state'   => 'expired',
        'last_payment_at' => '2026-08-26 10:00:00',
        'past_due_at'     => '2026-09-26 23:59:59',
        'ends_at'         => '2026-09-26 23:59:59',
        'next_billing_at' => '2026-09-26 23:59:59',
        'cancelled_at'    => '2026-10-03 09:00:00',
    ]);

    noticeTextsExpiredPage()->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('lastSubscription.status', 'expired')
            ->where('lastSubscription.ends_at', fn (?string $endedAt) => str_starts_with((string) $endedAt, '2026-10-03')));
});

it('textos sem o link da cobrança não prometem o e-mail do meio de pagamento (nem todo gateway manda)', function () {
    foreach (['subscriptions.banner.no_link', 'subscriptions.expired_page.no_link', 'billing_dunning.no_link'] as $key) {
        expect(__($key, [], 'pt_BR'))->not->toContain('e-mail do meio de pagamento')
            ->not->toContain('chega pelo')
            ->and(__($key, [], 'en'))->not->toContain('provider')
            ->not->toContain('is sent');
    }
});

it('aviso sem o link da cobrança: contato para quem pode pagar; com o link, não', function () {
    // Cliente pagante no D+1 (acesso total), cobrança sem link de pagamento.
    $subscription = Subscription::factory()->gateway('pagbank')->for($this->clinic)->for($this->plan)->create([
        'status'          => SubscriptionStatus::PastDue,
        'billing_mode'    => SubscriptionBillingMode::Gateway,
        'last_payment_at' => '2026-09-03 10:00:00',
        'past_due_at'     => '2026-10-03 23:59:59',
        'ends_at'         => '2026-10-03 23:59:59',
        'amount'          => 299.90,
    ]);

    $notices = app(SubscriptionNoticeService::class);
    $contact = route('site.home') . '#contato';

    $forAdmin = $notices->banner($subscription, $this->admin);

    expect($forAdmin['kind'])->toBe('overdue')
        ->and($forAdmin['can_pay'])->toBeTrue()
        ->and($forAdmin['payment_url'])->toBeNull()
        ->and($forAdmin['contact_url'])->toBe($contact)
        ->and($forAdmin['t']['contact'])->toBe(__('subscriptions.banner.contact'))
        ->and($forAdmin['t']['no_link'])->toBe(__('subscriptions.banner.no_link'));

    // Quem não vê a cobrança é orientado a procurar o administrador.
    expect($notices->banner($subscription, $this->doctor)['contact_url'])->toBeNull();

    // Com o link: "Pagar agora", sem contato.
    Invoice::query()->create([
        'entity_id'       => $subscription->entity_id,
        'subscription_id' => $subscription->id,
        'plan_id'         => $subscription->plan_id,
        'gateway_code'    => 'pagbank',
        'reference'       => 'INV-20261003-TEXTOS01',
        'period_start'    => '2026-10-03',
        'period_end'      => '2026-11-03',
        'due_at'          => '2026-10-03 23:59:59',
        'amount'          => 299.90,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Pending->value,
        'payment_url'     => 'https://pagbank.test/pay/1',
    ]);

    $withLink = $notices->banner($subscription->fresh(), $this->admin);

    expect($withLink['payment_url'])->toBe('https://pagbank.test/pay/1')
        ->and($withLink['contact_url'])->toBeNull();
});

it('textos novos nas duas línguas e "Dúvidas?" sem dois-pontos sem destino', function () {
    $keys = [
        'subscriptions.status_awaiting_first_payment',
        'subscriptions.expired_page.due_on',
        'subscriptions.expired_page.contact_link',
        'subscriptions.expired_page.no_link',
        'subscriptions.banner.contact',
        'subscriptions.banner.no_link',
    ];

    foreach ($keys as $key) {
        expect(__($key, [], 'pt_BR'))->not->toBe($key)
            ->and(__($key, [], 'en'))->not->toBe($key)
            ->and(__($key, [], 'en'))->not->toBe(__($key, [], 'pt_BR'));
    }

    foreach (['pt_BR', 'en'] as $locale) {
        expect(__('subscriptions.expired_page.contact_support', [], $locale))->not->toEndWith(':');
    }
});
