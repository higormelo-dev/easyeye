<?php

declare(strict_types=1);

use App\Enums\{FeatureKey, SubscriptionAccessLevel, SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\FeatureDeniedException;
use App\Models\{Entity, Plan, PlanFeature, Subscription};
use App\Services\{FeatureGateService, SubscriptionService};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Nível de acesso por assinatura (full | limited | none) — régua de cobrança
 * do cliente pagante (D4) e fim seco do trial/cortesia (D3). A regra é a
 * mesma no PHP (Subscription::accessLevel) e no SQL (scopeAccessible = full
 * ou limited).
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 09:00:00'));
    $this->plan = Plan::factory()->create(['active' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function accessLvlClinic(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

/** Cliente pagante (já pagou antes) com a cobrança de 08/10 vencida sem pagamento. */
function accessLvlPaidOverdue(Entity $entity, array $attributes = []): Subscription
{
    return Subscription::factory()->gateway()->for($entity)->for(test()->plan)->create([
        'status'          => SubscriptionStatus::PastDue,
        'billing_state'   => 'past_due',
        'last_payment_at' => '2026-09-08 10:00:00',
        'ends_at'         => '2026-10-08 23:59:59',
        'next_billing_at' => '2026-10-08 23:59:59',
        'past_due_at'     => '2026-10-08 23:59:59',
        ...$attributes,
    ]);
}

it('nível de acesso ao longo do tempo: PHP e SQL iguais', function (Subscription $subscription, string $at, SubscriptionAccessLevel $expected) {
    $this->travelTo(CarbonImmutable::parse($at));

    $fresh     = $subscription->fresh();
    $inSql     = Subscription::query()->accessible()->whereKey($subscription->id)->exists();
    $entityLvl = app(SubscriptionService::class)->accessLevel((string) $subscription->entity_id);

    expect($fresh->accessLevel())->toBe($expected)
        ->and($fresh->hasAccess())->toBe($expected !== SubscriptionAccessLevel::None)
        ->and($inSql)->toBe($expected !== SubscriptionAccessLevel::None)
        ->and($entityLvl)->toBe($expected);
})->with([
    // Trial: total até trial_ends_at, depois nada (sem graça).
    'trial no prazo' => [fn () => Subscription::factory()->trial()->for(accessLvlClinic())->for(test()->plan)->create(['trial_ends_at' => '2026-10-12 10:00:00']), '2026-10-12 09:59:00', SubscriptionAccessLevel::Full],
    'trial vencido'  => [fn () => Subscription::factory()->trial()->for(accessLvlClinic())->for(test()->plan)->create(['trial_ends_at' => '2026-10-12 10:00:00']), '2026-10-12 10:01:00', SubscriptionAccessLevel::None],

    // Cortesia: total até ends_at, depois nada (sem régua).
    'cortesia no prazo' => [fn () => Subscription::factory()->complimentary()->for(accessLvlClinic())->for(test()->plan)->create(['ends_at' => '2026-10-20 23:59:59']), '2026-10-20 23:00:00', SubscriptionAccessLevel::Full],
    'cortesia vencida'  => [fn () => Subscription::factory()->complimentary()->for(accessLvlClinic())->for(test()->plan)->create(['ends_at' => '2026-10-20 23:59:59']), '2026-10-21 00:00:01', SubscriptionAccessLevel::None],

    // Contratação aguardando o 1º pagamento: total até o fim do dia do vencimento, sem régua.
    '1ª cobrança no prazo' => [fn () => Subscription::factory()->for(accessLvlClinic())->for(test()->plan)->create([
        'status'          => SubscriptionStatus::PastDue, 'billing_state' => 'pending_activation', 'gateway' => 'asaas',
        'last_payment_at' => null, 'next_billing_at' => '2026-10-13 23:59:59', 'ends_at' => '2026-10-13 23:59:59',
    ]), '2026-10-13 23:30:00', SubscriptionAccessLevel::Full],
    '1ª cobrança vencida' => [fn () => Subscription::factory()->for(accessLvlClinic())->for(test()->plan)->create([
        'status'          => SubscriptionStatus::PastDue, 'billing_state' => 'pending_activation', 'gateway' => 'asaas',
        'last_payment_at' => null, 'next_billing_at' => '2026-10-13 23:59:59', 'ends_at' => '2026-10-13 23:59:59',
    ]), '2026-10-14 00:00:01', SubscriptionAccessLevel::None],

    // Cliente pagante em atraso (venceu em 08/10): D+1 e D+2 total; D+3 a D+6 limitado; D+7 nada.
    'atraso D+1'       => [fn () => accessLvlPaidOverdue(accessLvlClinic()), '2026-10-09 09:00:00', SubscriptionAccessLevel::Full],
    'atraso D+2 (fim)' => [fn () => accessLvlPaidOverdue(accessLvlClinic()), '2026-10-10 23:59:00', SubscriptionAccessLevel::Full],
    'atraso D+3'       => [fn () => accessLvlPaidOverdue(accessLvlClinic()), '2026-10-11 00:00:01', SubscriptionAccessLevel::Limited],
    'atraso D+6 (fim)' => [fn () => accessLvlPaidOverdue(accessLvlClinic()), '2026-10-14 23:59:00', SubscriptionAccessLevel::Limited],
    'atraso D+7'       => [fn () => accessLvlPaidOverdue(accessLvlClinic()), '2026-10-15 00:00:01', SubscriptionAccessLevel::None],
    'atraso sem data'  => [fn () => accessLvlPaidOverdue(accessLvlClinic(), ['past_due_at' => null, 'ends_at' => null]), '2026-10-10 09:00:00', SubscriptionAccessLevel::None],

    // Ativa com o período pago vencido que a expiração ainda não marcou: mesma régua.
    'ativa vencida D+1' => [fn () => accessLvlPaidOverdue(accessLvlClinic(), ['status' => SubscriptionStatus::Active, 'past_due_at' => null]), '2026-10-09 09:00:00', SubscriptionAccessLevel::Full],
    'ativa vencida D+4' => [fn () => accessLvlPaidOverdue(accessLvlClinic(), ['status' => SubscriptionStatus::Active, 'past_due_at' => null]), '2026-10-12 09:00:00', SubscriptionAccessLevel::Limited],
    'ativa vencida D+8' => [fn () => accessLvlPaidOverdue(accessLvlClinic(), ['status' => SubscriptionStatus::Active, 'past_due_at' => null]), '2026-10-16 09:00:00', SubscriptionAccessLevel::None],

    // Ativa de cobrança automática que nunca pagou (linha antiga): sem régua.
    'ativa nunca paga vencida' => [fn () => Subscription::factory()->for(accessLvlClinic())->for(test()->plan)->create(['ends_at' => '2026-10-08 23:59:59']), '2026-10-09 09:00:00', SubscriptionAccessLevel::None],

    'expirada'  => [fn () => Subscription::factory()->expired()->for(accessLvlClinic())->for(test()->plan)->create(), '2026-10-10 09:00:00', SubscriptionAccessLevel::None],
    'cancelada' => [fn () => Subscription::factory()->cancelled()->for(accessLvlClinic())->for(test()->plan)->create(['ends_at' => '2026-11-10 09:00:00']), '2026-10-10 09:00:00', SubscriptionAccessLevel::None],
]);

it('os limiares da régua vêm da configuração', function () {
    config(['billing.dunning.soft_block_after_days' => 1, 'billing.dunning.hard_block_after_days' => 2]);

    $subscription = accessLvlPaidOverdue(accessLvlClinic());

    $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00:00'));
    expect($subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Limited);

    $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00'));
    expect($subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::None)
        ->and(Subscription::query()->accessible()->whereKey($subscription->id)->exists())->toBeFalse();
});

it('a empresa fica com o melhor nível entre as assinaturas', function () {
    $entity = accessLvlClinic();

    Subscription::factory()->complimentary()->for($entity)->for($this->plan)->create([
        'ends_at'    => '2026-10-30 23:59:59',
        'created_at' => '2026-09-01 10:00:00',
    ]);
    accessLvlPaidOverdue($entity, ['past_due_at' => '2026-10-05 23:59:59']);

    expect(app(SubscriptionService::class)->accessLevel($entity))->toBe(SubscriptionAccessLevel::Full)
        ->and(app(SubscriptionService::class)->currentAccess($entity)->billing_mode)->toBe(SubscriptionBillingMode::Complimentary);
});

describe('recursos de IA no acesso limitado', function () {
    beforeEach(function () {
        PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($this->plan)->create();
        PlanFeature::factory()->enabled(FeatureKey::HasAiChatAssistant)->for($this->plan)->create();
        PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 100)->for($this->plan)->create();
        PlanFeature::factory()->limit(FeatureKey::MaxPatients, 0)->for($this->plan)->create();
        PlanFeature::factory()->enabled(FeatureKey::HasInventoryModule)->for($this->plan)->create();
    });

    it('limitado: IA indisponível, o resto do plano segue', function () {
        $entity = accessLvlClinic();
        accessLvlPaidOverdue($entity);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00'));

        $gate   = app(FeatureGateService::class);
        $status = $gate->status((string) $entity->id, FeatureKey::HasAiExamAssistant);

        expect($status->allowed)->toBeFalse()
            ->and($status->deniedByAccessLevel)->toBe(SubscriptionAccessLevel::Limited)
            ->and($status->isBlockedByAccessLimit())->toBeTrue()
            ->and($status->toArray()['access_level'])->toBe('limited')
            ->and($gate->can((string) $entity->id, FeatureKey::HasAiChatAssistant))->toBeFalse()
            ->and($gate->can((string) $entity->id, FeatureKey::AiMonthlyCredits))->toBeFalse()
            ->and($gate->tryConsume((string) $entity->id, FeatureKey::AiMonthlyCredits))->toBeFalse()
            ->and($gate->can((string) $entity->id, FeatureKey::MaxPatients))->toBeTrue()
            ->and($gate->can((string) $entity->id, FeatureKey::HasInventoryModule))->toBeTrue();

        // A negação explica o motivo (acesso limitado), não "fora do plano",
        // com o mesmo status e corpo do CheckSubscription (402 + access_level).
        $response = (new FeatureDeniedException(FeatureKey::HasAiExamAssistant, $status))
            ->render(Request::create('/x', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));
        $body = json_decode($response->getContent(), true);

        expect($response->getStatusCode())->toBe(402)
            ->and($body['message'])->toBe(__('subscriptions.access_limited'))
            ->and($body['access_level'])->toBe(SubscriptionAccessLevel::Limited->value)
            ->and($body['feature']['feature'])->toBe(FeatureKey::HasAiExamAssistant->value);

        // Fora da régua (recurso fora do plano) segue 403.
        $notIncluded = $gate->status((string) $entity->id, FeatureKey::HasAiReportDrafting);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00:00'));
        $gate->forgetCache((string) $entity->id);
        $outOfPlan = $gate->status((string) $entity->id, FeatureKey::HasAiReportDrafting);

        expect($notIncluded->isBlockedByAccessLimit())->toBeTrue()
            ->and($outOfPlan->isBlockedByAccessLimit())->toBeFalse()
            ->and((new FeatureDeniedException(FeatureKey::HasAiReportDrafting, $outOfPlan))
                ->render(Request::create('/x', 'POST', server: ['HTTP_ACCEPT' => 'application/json']))->getStatusCode())->toBe(403);
    });

    it('em atraso ainda com acesso total (antes do D+3): IA liberada', function () {
        $entity = accessLvlClinic();
        accessLvlPaidOverdue($entity);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00:00'));

        expect(app(FeatureGateService::class)->can((string) $entity->id, FeatureKey::HasAiExamAssistant))->toBeTrue();
    });
});

it('API do integrador segue liberada no acesso limitado (fluxo clínico)', function () {
    $ctx = setupIntegrator();

    // Só a assinatura em atraso (sem o trial automático da empresa).
    Subscription::query()->where('entity_id', $ctx['entity']->id)->forceDelete();
    $plan = Plan::factory()->create();
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::HasApiIntegrator->value, 'value' => '1']);
    Subscription::factory()->gateway()->for($ctx['entity'])->for($plan)->create([
        'status'          => SubscriptionStatus::PastDue,
        'last_payment_at' => '2026-09-08 10:00:00',
        'ends_at'         => '2026-10-08 23:59:59',
        'past_due_at'     => '2026-10-08 23:59:59',
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00'));
    expect(app(SubscriptionService::class)->accessLevel((string) $ctx['entity']->id))->toBe(SubscriptionAccessLevel::Limited);
    $this->getJson('/api/integrators/v1/patients', $ctx['headers'])->assertOk();

    // D+7: bloqueio total também na API.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00'));
    $this->getJson('/api/integrators/v1/patients', $ctx['headers'])->assertForbidden();
});
