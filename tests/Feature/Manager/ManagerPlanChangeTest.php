<?php

declare(strict_types=1);

use App\Enums\Billing\InvoiceStatus;
use App\Enums\{BillingCycle, SaasRule, SubscriptionBillingMode, SubscriptionStatus};
use App\Jobs\Billing\RenewSubscriptionJob;
use App\Models\Billing\{Invoice, SubscriptionChange};
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Services\Billing\SubscriptionCycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Http, Queue};

/**
 * Manager → Assinaturas → cobrança automática numa clínica com plano PAGO
 * vigente: troca de plano pelo PlanChangeService (não substitui a paga).
 * Upgrade = fatura da diferença proporcional (muda quando paga); downgrade =
 * agendado para o fim do período (o manager pode desfazer antes da data).
 * Prévia antes de confirmar; histórico e auditoria com quem fez e por quê.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00'));
    Http::preventStrayRequests();
    Queue::fake();

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create(['name' => 'Ana Admin']);
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->pro     = pcPlan('Pro', ['monthly' => 300.00, 'yearly' => 3000.00]);
    $this->premium = pcPlan('Premium', ['monthly' => 600.00]);
    $this->basic   = pcPlan('Basic', ['monthly' => 150.00]);

    // Pro mensal pago até 01/11 (período de 31 dias, de 01/10 a 01/11; faltam 16).
    $this->subscription = Subscription::factory()->gateway('mercadopago')->for($this->clinic)->for($this->pro)->create([
        'gateway_subscription_id' => null,
        'gateway_customer_id'     => 'cus-1',
        'billing_cycle'           => BillingCycle::Monthly,
        'status'                  => SubscriptionStatus::Active,
        'billing_state'           => 'paid',
        'amount'                  => 300.00,
        'last_payment_at'         => '2026-10-01 10:00:00',
        'starts_at'               => '2026-09-01 10:00:00',
        'ends_at'                 => '2026-11-01 23:59:59',
        'next_billing_at'         => '2026-11-01 23:59:59',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function pcPlan(string $name, array $prices): Plan
{
    $default = array_key_first($prices);
    $plan    = Plan::factory()->create(['name' => $name, 'billing_cycle' => $default, 'price' => $prices[$default], 'active' => true]);

    foreach ($prices as $cycle => $price) {
        PlanPrice::create(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'price' => $price]);
    }

    return $plan->fresh('prices');
}

function pcAs(): mixed
{
    return test()->actingAs(test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ]);
}

function pcReason(): string
{
    return 'Clínica pediu a troca por telefone (chamado #4521).';
}

it('prévia: upgrade com o valor proporcional; downgrade com a data; mesmo plano = nada a mudar', function () {
    pcAs()->getJson(route('manager.subscriptions.change-preview', ['entity_id' => $this->clinic->id, 'plan_id' => $this->premium->id, 'billing_cycle' => 'monthly']))
        ->assertOk()
        ->assertJsonPath('data.change.type', 'upgrade')
        ->assertJsonPath('data.change.remaining_days', 16)
        ->assertJsonPath('data.change.amount_now', 154.84) // 300 × 16/31
        ->assertJsonPath('data.gateway', 'mercadopago');

    pcAs()->getJson(route('manager.subscriptions.change-preview', ['entity_id' => $this->clinic->id, 'plan_id' => $this->basic->id, 'billing_cycle' => 'monthly']))
        ->assertOk()
        ->assertJsonPath('data.change.type', 'scheduled')
        ->assertJsonPath('data.change.reason', 'downgrade')
        ->assertJsonPath('data.change.next_charge_at', '2026-11-01');

    pcAs()->getJson(route('manager.subscriptions.change-preview', ['entity_id' => $this->clinic->id, 'plan_id' => $this->pro->id, 'billing_cycle' => 'monthly']))
        ->assertOk()
        ->assertJsonPath('data.change.type', 'current');
});

it('upgrade: fatura da diferença para a clínica, assinatura paga intacta; o plano muda quando paga — histórico com quem pediu e por quê', function () {
    pcAs()->postJson(route('manager.subscriptions.store'), [
        'entity_id'     => $this->clinic->id,
        'plan_id'       => $this->premium->id,
        'mode'          => 'gateway',
        'billing_cycle' => 'monthly',
        'reason'        => pcReason(),
    ])->assertCreated()
        ->assertJsonPath('data.id', $this->subscription->id)
        ->assertJsonPath('data.change.type', 'upgrade');

    $invoice = Invoice::query()->where('subscription_id', $this->subscription->id)->where('billing_reason', Invoice::BILLING_REASON_PLAN_CHANGE)->firstOrFail();

    expect(Subscription::query()->forEntity((string) $this->clinic->id)->count())->toBe(1)
        ->and($this->subscription->fresh()->plan_id)->toBe($this->pro->id)
        ->and((float) $invoice->amount)->toBe(154.84)
        ->and($invoice->status)->toBe(InvoiceStatus::Pending)
        ->and(data_get($invoice->metadata, 'plan_change.source'))->toBe('manager')
        ->and(data_get($invoice->metadata, 'plan_change.justification'))->toBe(pcReason());

    $requested = SubscriptionChange::query()->where('change_type', 'upgrade_requested')->firstOrFail();
    expect($requested->changed_by)->toBe($this->admin->id)
        ->and($requested->metadata['source'])->toBe('manager')
        ->and($requested->metadata['reason_text'])->toBe(pcReason());

    // A clínica pagou a fatura (checkout/webhook): o plano muda.
    app(SubscriptionCycleService::class)->confirmPayment($this->subscription->fresh(), $invoice, null, now(), (string) Str::uuid(), 'webhook');

    $upgrade = SubscriptionChange::query()->where('change_type', 'upgrade')->firstOrFail();

    expect($this->subscription->fresh()->plan_id)->toBe($this->premium->id)
        ->and((float) $this->subscription->fresh()->amount)->toBe(600.0)
        ->and($upgrade->changed_by)->toBe($this->admin->id)
        ->and($upgrade->metadata['requested_via'])->toBe('manager')
        ->and($upgrade->metadata['reason_text'])->toBe(pcReason());

    $this->assertDatabaseHas('audit_logs', ['event' => 'manager.subscription.plan_change.upgrade']);

    Http::assertNothingSent();
});

it('downgrade: agendado para o fim do período, sem cobrar nem substituir; o manager desfaz antes da data (cobrança do vencimento volta ao valor atual)', function () {
    // Cobrança da renovação de 01/11 já emitida no valor atual.
    $next = Invoice::query()->create([
        'entity_id'       => $this->clinic->id,
        'subscription_id' => $this->subscription->id,
        'plan_id'         => $this->pro->id,
        'gateway_code'    => 'mercadopago',
        'reference'       => 'INV-20261101-NEXT',
        'period_start'    => '2026-11-01',
        'period_end'      => '2026-12-01',
        'due_at'          => '2026-11-01 23:59:59',
        'amount'          => 300.00,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Draft->value,
        'billing_reason'  => SubscriptionCycleService::BILLING_REASON_CYCLE,
    ]);

    pcAs()->postJson(route('manager.subscriptions.store'), [
        'entity_id'     => $this->clinic->id,
        'plan_id'       => $this->basic->id,
        'mode'          => 'gateway',
        'billing_cycle' => 'monthly',
        'reason'        => pcReason(),
    ])->assertCreated()
        ->assertJsonPath('data.change.type', 'scheduled');

    $subscription = $this->subscription->fresh();

    expect(Subscription::query()->forEntity((string) $this->clinic->id)->count())->toBe(1)
        ->and($subscription->plan_id)->toBe($this->pro->id)
        ->and($subscription->scheduledChange()['plan_id'])->toBe($this->basic->id)
        ->and($subscription->scheduledChange()['source'])->toBe('manager')
        ->and((float) $next->fresh()->amount)->toBe(150.0)
        ->and(SubscriptionChange::query()->where('change_type', 'downgrade_scheduled')->value('changed_by'))->toBe($this->admin->id);

    pcAs()->getJson(route('manager.subscriptions.show', $subscription))
        ->assertJsonPath('data.scheduled_change.plan_name', 'Basic')
        ->assertJsonPath('data.scheduled_change.can_cancel', true);

    // Justificativa obrigatória para desfazer.
    pcAs()->postJson(route('manager.subscriptions.scheduled-change.cancel', $subscription), ['reason' => 'curto'])
        ->assertStatus(422);

    pcAs()->postJson(route('manager.subscriptions.scheduled-change.cancel', $subscription), ['reason' => 'Clínica desistiu do downgrade (chamado #4522).'])
        ->assertOk();

    $subscription->refresh();
    $next->refresh();

    expect($subscription->scheduledChange())->toBeNull()
        ->and($subscription->plan_id)->toBe($this->pro->id)
        ->and((float) $next->amount)->toBe(300.0)
        ->and($next->plan_id)->toBe($this->pro->id)
        ->and($next->status)->toBe(InvoiceStatus::Draft)
        ->and(data_get($next->metadata, 'plan_change'))->toBeNull();

    $cancelled = SubscriptionChange::query()->where('change_type', 'downgrade_cancelled')->firstOrFail();
    expect($cancelled->changed_by)->toBe($this->admin->id)
        ->and($cancelled->metadata['reason_text'])->toBe('Clínica desistiu do downgrade (chamado #4522).');

    Queue::assertPushed(RenewSubscriptionJob::class);

    // Nada mais a desfazer.
    pcAs()->postJson(route('manager.subscriptions.scheduled-change.cancel', $subscription), ['reason' => 'Clínica desistiu do downgrade (chamado #4522).'])
        ->assertStatus(409);
});

it('mesmo plano e ciclo: 422 sem mexer em nada', function () {
    pcAs()->postJson(route('manager.subscriptions.store'), [
        'entity_id'     => $this->clinic->id,
        'plan_id'       => $this->pro->id,
        'mode'          => 'gateway',
        'billing_cycle' => 'monthly',
    ])->assertStatus(422);

    expect(Invoice::query()->count())->toBe(0)
        ->and(SubscriptionChange::query()->count())->toBe(0);
});

it('cortesia continua substituindo a paga (decisão manual do manager, com justificativa)', function () {
    pcAs()->postJson(route('manager.subscriptions.store'), [
        'entity_id' => $this->clinic->id,
        'plan_id'   => $this->premium->id,
        'mode'      => 'complimentary',
        'ends_at'   => now()->addMonths(3)->toDateString(),
        'reason'    => pcReason(),
    ])->assertCreated();

    $current = Subscription::query()->forEntity((string) $this->clinic->id)->currentFirst()->first();

    expect($current->id)->not->toBe($this->subscription->id)
        ->and($current->billing_mode)->toBe(SubscriptionBillingMode::Complimentary)
        ->and(Invoice::query()->where('billing_reason', Invoice::BILLING_REASON_PLAN_CHANGE)->count())->toBe(0);
});

it('troca de plano com OUTRO gateway escolhido: 422 explicando que o gateway da assinatura paga é mantido — nada muda', function () {
    pcAs()->postJson(route('manager.subscriptions.store'), [
        'entity_id'     => $this->clinic->id,
        'plan_id'       => $this->premium->id,
        'mode'          => 'gateway',
        'billing_cycle' => 'monthly',
        'gateway'       => 'asaas',
        'reason'        => pcReason(),
    ])->assertStatus(422)
        ->assertJsonPath('message', __('manager_subscriptions.errors.plan_change_gateway', ['gateway' => 'mercadopago']))
        ->assertJsonValidationErrors('gateway');

    expect(Invoice::query()->where('subscription_id', $this->subscription->id)->where('billing_reason', Invoice::BILLING_REASON_PLAN_CHANGE)->exists())->toBeFalse()
        ->and($this->subscription->fresh()->plan_id)->toBe($this->pro->id)
        ->and(SubscriptionChange::query()->count())->toBe(0);

    // O mesmo gateway da assinatura (ou nenhum) segue valendo.
    pcAs()->postJson(route('manager.subscriptions.store'), [
        'entity_id'     => $this->clinic->id,
        'plan_id'       => $this->premium->id,
        'mode'          => 'gateway',
        'billing_cycle' => 'monthly',
        'gateway'       => 'mercadopago',
        'reason'        => pcReason(),
    ])->assertCreated()->assertJsonPath('data.change.type', 'upgrade');
});
