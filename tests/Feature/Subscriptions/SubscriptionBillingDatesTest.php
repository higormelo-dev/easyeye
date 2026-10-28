<?php

use App\Enums\{SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, Plan, Subscription};
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Datas do ciclo de cobrança: next_billing_at e past_due_at eram descartadas
 * em silêncio (fora do $fillable). E a regra de acesso da contratação
 * aguardando o 1º pagamento é a mesma no PHP (hasAccess) e no SQL
 * (scopeAccessible).
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    $this->plan = Plan::factory()->create(['active' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function datesClinic(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

it('grava next_billing_at e past_due_at por atribuição em massa, como datas', function () {
    $subscription = Subscription::create([
        'entity_id'       => datesClinic()->id,
        'plan_id'         => $this->plan->id,
        'billing_mode'    => SubscriptionBillingMode::Gateway,
        'status'          => SubscriptionStatus::PastDue,
        'starts_at'       => now(),
        'next_billing_at' => '2026-10-08 23:59:59',
        'past_due_at'     => '2026-10-08 23:59:59',
    ])->fresh();

    expect($subscription->next_billing_at)->toBeInstanceOf(Carbon::class)
        ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-10-08 23:59:59')
        ->and($subscription->past_due_at->toDateTimeString())->toBe('2026-10-08 23:59:59');

    $subscription->update(['next_billing_at' => '2026-11-08 23:59:59', 'past_due_at' => null]);

    expect($subscription->fresh()->next_billing_at->toDateTimeString())->toBe('2026-11-08 23:59:59')
        ->and($subscription->fresh()->past_due_at)->toBeNull();
});

it('acesso da contratação aguardando o 1º pagamento: PHP e SQL iguais, até o fim do dia do vencimento', function (string $at) {
    $pending = fn (array $attributes) => Subscription::factory()->for(datesClinic())->for($this->plan)->create([
        'billing_mode'    => SubscriptionBillingMode::Gateway,
        'status'          => SubscriptionStatus::PastDue,
        'billing_state'   => 'pending_activation',
        'gateway'         => 'asaas',
        'last_payment_at' => null,
        'next_billing_at' => '2026-10-08 23:59:59',
        'ends_at'         => '2026-10-08 23:59:59',
        ...$attributes,
    ]);

    $rows = collect([
        'emitida'          => $pending([]),
        'vence_no_inicio'  => $pending(['next_billing_at' => '2026-10-08 00:00:00']),
        'antes_da_emissao' => $pending(['gateway' => null]),
        'sem_vencimento'   => $pending(['next_billing_at' => null]),
        'ja_pagou'         => $pending(['last_payment_at' => '2026-09-08 10:00:00']),
        'cortesia'         => $pending(['billing_mode' => SubscriptionBillingMode::Complimentary]),
        'trial'            => Subscription::factory()->trial(4)->for(datesClinic())->for($this->plan)->create(),
        'ativa'            => Subscription::factory()->for(datesClinic())->for($this->plan)->create(['ends_at' => '2026-10-08 12:00:00']),
    ]);

    $this->travelTo(CarbonImmutable::parse($at));

    $sql = Subscription::query()->accessible()->pluck('id')->sort()->values()->all();
    $php = $rows->filter(fn (Subscription $s) => $s->fresh()->hasAccess())->map->id->sort()->values()->all();

    expect($sql)->toBe($php);

    $pendingWithAccess = $rows->only(['emitida', 'vence_no_inicio'])->filter(fn (Subscription $s) => $s->fresh()->hasAccess())->count();

    expect($pendingWithAccess)->toBe(CarbonImmutable::parse($at)->lessThan('2026-10-09 00:00:00') ? 2 : 0)
        ->and($rows['antes_da_emissao']->fresh()->hasAccess())->toBeFalse()
        ->and($rows['sem_vencimento']->fresh()->hasAccess())->toBeFalse()
        // Quem já pagou antes não é "aguardando o 1º pagamento": está na
        // régua de cobrança (atraso de menos de 7 dias ainda dá acesso).
        ->and($rows['ja_pagou']->fresh()->hasAccess())->toBeTrue()
        ->and($rows['cortesia']->fresh()->hasAccess())->toBeFalse();
})->with([
    'hoje'                 => ['2026-10-05 10:00:00'],
    'último minuto do dia' => ['2026-10-08 23:59:00'],
    'depois do vencimento' => ['2026-10-09 00:00:01'],
]);
