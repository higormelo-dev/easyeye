<?php

declare(strict_types=1);

use App\Enums\Billing\CancellationReason;
use App\Enums\{SaasRule, SubscriptionStatus};
use App\Models\Billing\{Cancellation, SubscriptionChange};
use App\Models\{Entity, Plan, Subscription, User};
use App\Services\Billing\{BillingCancellationService, SubscriptionManagementService};

/**
 * Histórico da empresa no manager: motivo legível (nunca o código cru
 * 'non_payment'/'admin_action'), autor certo no cancelamento feito pelo
 * manager (com a justificativa escrita) e a contratação aguardando o 1º
 * pagamento marcada nos snapshots (não "Em atraso").
 */
beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Ana Admin']);
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Histórico']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->plan = Plan::factory()->create(['active' => true, 'name' => 'Pro']);
});

function historyTextsAs(): mixed
{
    return test()->actingAs(test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => SaasRule::Admin->value,
    ]);
}

it('cancelamento pelo manager: histórico com quem cancelou e a justificativa escrita', function () {
    $subscription = Subscription::factory()->complimentary()->for($this->clinic)->for($this->plan)->create();
    $reason       = 'Cliente pediu cancelamento por telefone em 03/10 (ticket #812).';

    historyTextsAs()->postJson(route('manager.subscriptions.cancel'), [
        'entity_id' => $this->clinic->id,
        'reason'    => $reason,
    ])->assertOk();

    $event = collect(historyTextsAs()->getJson(route('manager.subscriptions.history', $subscription))->assertOk()->json('data.events'))
        ->firstWhere('type', 'cancelled');

    expect($event['actor'])->toBe($this->admin->name)
        ->and($event['source'])->toBe('manager')
        ->and($event['reason'])->toBe($reason)
        ->and(SubscriptionChange::query()->where('subscription_id', $subscription->id)->sole()->changed_by)->toBe($this->admin->id)
        ->and(Cancellation::query()->where('subscription_id', $subscription->id)->sole()->notes)->toBe($reason);
});

it('encerramento da régua (D+7): motivo traduzido, não o código cru', function () {
    $subscription = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'status'          => SubscriptionStatus::PastDue,
        'last_payment_at' => now()->subMonths(2),
        'past_due_at'     => now()->subDays(7),
        'ends_at'         => now()->subDays(7),
    ]);

    app(BillingCancellationService::class)->expire(
        subscription: $subscription,
        entity: $this->clinic,
        reason: CancellationReason::NonPayment,
        source: 'dunning',
        cancelAtGateway: false,
    );

    $event = collect(historyTextsAs()->getJson(route('manager.subscriptions.history', $subscription))->assertOk()->json('data.events'))
        ->firstWhere('type', 'expired');

    expect($event['reason'])->toBe(__('manager_subscriptions.history_reason_code.non_payment'))
        ->and($event['reason'])->not->toBe('non_payment')
        ->and($event['actor'])->toBeNull()
        ->and($event['source'])->toBe('dunning');
});

it('todos os motivos do sistema têm tradução nas duas línguas', function () {
    foreach (CancellationReason::cases() as $reason) {
        $key = "manager_subscriptions.history_reason_code.{$reason->value}";

        expect(__($key, [], 'pt_BR'))->not->toBe($key)
            ->and(__($key, [], 'en'))->not->toBe($key)
            ->and(__($key, [], 'en'))->not->toBe(__($key, [], 'pt_BR'));
    }

    foreach (['pt_BR', 'en'] as $locale) {
        expect(__('manager_subscriptions.history_by_manager', [], $locale))->not->toBe('manager_subscriptions.history_by_manager');
    }
});

it('snapshot do histórico marca a contratação aguardando o 1º pagamento', function () {
    $awaiting = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'status'          => SubscriptionStatus::PastDue,
        'billing_state'   => 'pending_activation',
        'last_payment_at' => null,
        'next_billing_at' => now()->addDays(3)->endOfDay(),
        'ends_at'         => now()->addDays(3)->endOfDay(),
    ]);

    $paying = Subscription::factory()->gateway()->for(Entity::factory()->create(['is_client' => true]))->for($this->plan)->create([
        'status'          => SubscriptionStatus::PastDue,
        'last_payment_at' => now()->subMonth(),
        'past_due_at'     => now()->subDay(),
        'ends_at'         => now()->subDay(),
    ]);

    expect(SubscriptionManagementService::snapshot($awaiting)['awaiting_first_payment'])->toBeTrue()
        ->and(SubscriptionManagementService::snapshot($paying)['awaiting_first_payment'])->toBeFalse();
});
