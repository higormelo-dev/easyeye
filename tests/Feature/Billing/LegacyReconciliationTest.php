<?php

declare(strict_types=1);

use App\Enums\Billing\{DunningStep, InvoiceStatus, SubscriptionCancelledReason};
use App\Enums\{BillingCycle, ClientRule, SaasRule, SubscriptionAccessLevel, SubscriptionBillingMode, SubscriptionStatus};
use App\Jobs\Billing\RenewSubscriptionJob;
use App\Models\Billing\{Invoice, Payment, SubscriptionChange};
use App\Models\{Entity, Plan, PlanPrice, Subscription, User};
use App\Notifications\SubscriptionDunningNotification;
use App\Services\Billing\{SubscriptionCycleService, SubscriptionNoticeService};
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\{Artisan, DB, Http, Notification, Queue};

/**
 * Assinaturas de cobrança automática do código anterior (next_billing_at
 * descartado, ends_at parado no fim do 1º ciclo, expiradas pelo job antigo,
 * sem ciclo/valor): marcadas para conciliação no deploy, com acesso total e
 * fora da régua/expiração/renovação até o billing:reconcile-legacy conferir
 * no Asaas o que ele de fato cobra.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 10:00:00'));
    Http::preventStrayRequests();

    // Ciclo padrão do plano hoje é mensal; o gateway pode cobrar outro.
    $this->plan = Plan::factory()->create(['name' => 'Pro', 'billing_cycle' => 'monthly', 'price' => 299.90, 'active' => true]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly', 'price' => 299.90]);
    PlanPrice::create(['plan_id' => $this->plan->id, 'billing_cycle' => 'yearly', 'price' => 2878.99]);

    // Recorrências no Asaas (GET /v3/subscriptions/{id} e /payments) e as
    // achadas pela referência (GET /v3/subscriptions?externalReference=).
    $this->asaas = (object) ['subscriptions' => [], 'byReference' => []];
    legacyFakeAsaas($this->asaas);
});

afterEach(fn () => Carbon::setTestNow());

function legacyClinic(string $name = 'Clínica Legada'): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => $name]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

/**
 * Linha no formato deixado pelo código anterior: paga todo mês no Asaas, mas
 * com ends_at no fim do 1º ciclo, sem next_billing_at, ciclo nem valor.
 */
function legacySubscription(array $attributes = [], ?Entity $entity = null): Subscription
{
    return Subscription::factory()->for($entity ?? legacyClinic())->for(test()->plan)->create([
        'billing_mode'                 => SubscriptionBillingMode::Gateway,
        'status'                       => SubscriptionStatus::Active,
        'billing_state'                => 'paid',
        'gateway'                      => 'asaas',
        'pinned_gateway'               => 'asaas',
        'gateway_customer_id'          => 'cus_000005219613',
        'gateway_subscription_id'      => 'sub_legado_' . fake()->unique()->numerify('####'),
        'billing_cycle'                => null,
        'amount'                       => null,
        'starts_at'                    => '2026-06-10 09:00:00',
        'ends_at'                      => '2026-07-10 09:00:00',
        'next_billing_at'              => null,
        'last_payment_at'              => '2026-09-10 11:00:00',
        'needs_billing_reconciliation' => true,
        ...$attributes,
    ]);
}

/** @param list<array<string, mixed>> $payments */
function legacyRecurrence(Subscription $subscription, array $recurrence, array $payments = [], int $status = 200): void
{
    test()->asaas->subscriptions[$subscription->gateway_subscription_id] = [
        'status'       => $status,
        'subscription' => [
            'object'            => 'subscription',
            'id'                => $subscription->gateway_subscription_id,
            'customer'          => 'cus_000005219613',
            'billingType'       => 'BOLETO',
            'status'            => 'ACTIVE',
            'deleted'           => false,
            'externalReference' => $subscription->id,
            ...$recurrence,
        ],
        'payments' => array_map(fn (array $p) => [
            'object'            => 'payment',
            'customer'          => 'cus_000005219613',
            'subscription'      => $subscription->gateway_subscription_id,
            'billingType'       => 'BOLETO',
            'paymentDate'       => null,
            'clientPaymentDate' => null,
            'deleted'           => false,
            ...$p,
        ], $payments),
    ];
}

function legacyFakeAsaas(object $asaas): void
{
    Http::fake(function (Request $request) use ($asaas) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && $path === '/v3/subscriptions') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $ids = $asaas->byReference[$query['externalReference'] ?? ''] ?? [];

            return Http::response(['object' => 'list', 'hasMore' => false, 'totalCount' => count($ids), 'limit' => 100, 'offset' => 0,
                'data'                      => array_map(fn (string $id) => ['object' => 'subscription', 'id' => $id, 'status' => 'ACTIVE', 'deleted' => false], $ids)]);
        }

        if ($request->method() === 'DELETE' && preg_match('#^/v3/subscriptions/([^/]+)$#', $path, $m)) {
            return Http::response(['deleted' => true, 'id' => $m[1]]);
        }

        if ($request->method() === 'GET' && preg_match('#^/v3/subscriptions/([^/]+)(/payments)?$#', $path, $m)) {
            $entry = $asaas->subscriptions[$m[1]] ?? null;

            if ($entry === null) {
                return Http::response(['errors' => [['code' => 'not_found']]], 404);
            }

            if ($entry['status'] !== 200) {
                return Http::response(['errors' => [['code' => 'unavailable']]], $entry['status']);
            }

            return isset($m[2])
                ? Http::response(['object' => 'list', 'hasMore' => false, 'totalCount' => count($entry['payments']), 'limit' => 100, 'offset' => 0, 'data' => $entry['payments']])
                : Http::response($entry['subscription']);
        }

        return Http::response(['errors' => [['code' => 'not_faked']]], 404);
    });
}

function legacyManager(): mixed
{
    $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $admin = User::factory()->create();
    createEntityUser($saas, $admin, SaasRule::Admin->value);

    return test()->actingAs($admin)->withSession([
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => SaasRule::Admin->value,
    ]);
}

describe('marcação no deploy (migração 200100)', function () {
    it('marca a vigente e as antigas que ainda podem ter recorrência viva; a antiga nunca libera acesso pela marcação', function () {
        $paying    = legacySubscription();
        $expired   = legacySubscription(['status' => SubscriptionStatus::Expired]);
        $notIssued = legacySubscription(['status' => SubscriptionStatus::PastDue, 'billing_state' => 'error', 'gateway' => null, 'gateway_subscription_id' => null, 'last_payment_at' => null]);

        // Substituída por uma cortesia depois, com a recorrência do Asaas
        // ainda cadastrada: marcada (fora da régua), mas sem acesso.
        $replacedEntity = legacyClinic('Clínica Cortesia');
        $replaced       = legacySubscription(['status' => SubscriptionStatus::Expired, 'created_at' => now()->subYear()], $replacedEntity);
        $courtesyNow    = Subscription::factory()->complimentary()->for($replacedEntity)->for($this->plan)->create(['created_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);

        // Código anterior: "Ativar" criou G2 e deixou G1 em atraso (nunca
        // cancelava em atraso na troca) e G1b ativa (o webhook antigo a
        // ressuscitou) — as três com recorrência no Asaas.
        $swapEntity = legacyClinic('Clínica Troca');
        $oldPastDue = legacySubscription(['status' => SubscriptionStatus::PastDue, 'billing_state' => 'past_due', 'created_at' => now()->subMonths(5)], $swapEntity);
        $oldActive  = legacySubscription(['created_at' => now()->subMonths(4)], $swapEntity);
        $swapLatest = legacySubscription(['created_at' => now()->subMonths(3)], $swapEntity);

        // Antiga expirada sem recorrência no gateway (renovação local): nada vivo, fica de fora.
        $localEntity  = legacyClinic('Clínica Local');
        $oldLocal     = legacySubscription(['status' => SubscriptionStatus::Expired, 'gateway' => 'infinitepay', 'gateway_subscription_id' => null, 'created_at' => now()->subMonths(6)], $localEntity);
        $localCurrent = legacySubscription(['gateway' => 'infinitepay', 'gateway_subscription_id' => null, 'created_at' => now()->subMonths(2)], $localEntity);

        // Tentativa recusada mais nova não tira a vez da que vale.
        $withFailedAttempt = legacySubscription(['created_at' => now()->subMonths(3)]);
        Subscription::factory()->for($withFailedAttempt->entity)->for($this->plan)->create([
            'status'           => SubscriptionStatus::Cancelled,
            'cancelled_reason' => SubscriptionCancelledReason::ActivationFailed->value,
            'next_billing_at'  => null,
        ]);

        // Fluxo novo (next_billing_at gravado), cortesia e cancelada: fora.
        $newFlow   = legacySubscription(['next_billing_at' => now()->addDays(20)->endOfDay(), 'ends_at' => now()->addDays(20)->endOfDay()]);
        $courtesy  = Subscription::factory()->complimentary()->for(legacyClinic('Clínica Cortesia 2'))->for($this->plan)->create(['next_billing_at' => null]);
        $cancelled = legacySubscription(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()->subMonth()]);

        // Trial cancelado pelo manager no código anterior (billing_state
        // 'cancelled', sem gateway) que a 230100 promoveu a cobrança automática.
        $trial = Subscription::factory()->expiredTrial()->for(legacyClinic('Clínica Trial'))->for($this->plan)->create();
        DB::table('subscriptions')->where('id', $trial->id)->update(['status' => 'cancelled', 'billing_state' => 'cancelled', 'billing_mode' => 'gateway']);

        DB::table('subscriptions')->update(['needs_billing_reconciliation' => false]);

        (require database_path('migrations/2026_10_04_200100_flag_legacy_gateway_subscriptions_for_reconciliation.php'))->up();

        $flagged = Subscription::query()->where('needs_billing_reconciliation', true)->pluck('id')->sort()->values()->all();

        expect($flagged)->toEqualCanonicalizing([
            $paying->id, $expired->id, $notIssued->id, $withFailedAttempt->id,
            $replaced->id, $oldPastDue->id, $oldActive->id, $swapLatest->id, $localCurrent->id,
        ])
            ->and($oldLocal->fresh()->needs_billing_reconciliation)->toBeFalse()
            // Marcação da linha antiga não libera acesso: só a da vigente.
            ->and($replaced->fresh()->hasAccess())->toBeFalse()
            ->and(app(SubscriptionService::class)->hasAccess($replacedEntity))->toBeFalse()
            ->and($courtesyNow->fresh()->hasAccess())->toBeFalse()
            ->and($oldPastDue->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::None)
            ->and($swapLatest->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Full)
            ->and(app(SubscriptionService::class)->currentAccess($swapEntity)?->id)->toBe($swapLatest->id)
            ->and($newFlow->fresh()->needs_billing_reconciliation)->toBeFalse()
            ->and($courtesy->fresh()->needs_billing_reconciliation)->toBeFalse()
            ->and($cancelled->fresh()->needs_billing_reconciliation)->toBeFalse()
            ->and($trial->fresh()->billing_mode)->toBeNull()
            ->and($paying->fresh()->billing_mode)->toBe(SubscriptionBillingMode::Gateway);
    });
});

describe('enquanto aguarda conciliação', function () {
    it('pagante do código anterior usa o painel (acesso total), sem aviso e fora da expiração e da renovação local', function () {
        config(['billing.enforce_subscription_access' => true]);
        Queue::fake();

        // Expirada pelo job antigo, com o Asaas cobrando todo mês.
        $expired = legacySubscription(['status' => SubscriptionStatus::Expired]);
        $user    = User::factory()->create();
        $member  = createEntityUser($expired->entity, $user, ClientRule::Admin->value);

        $this->actingAs($user)->withSession(panelSession($member))->get(route('panel.dashboard'))->assertOk();

        expect($expired->accessLevel())->toBe(SubscriptionAccessLevel::Full)
            ->and($expired->dunningStage())->toBeNull()
            ->and(app(SubscriptionService::class)->currentAccess($expired->entity)?->id)->toBe($expired->id);

        // Ativa com ends_at parado em julho: a expiração da madrugada não a
        // põe em atraso desde julho.
        $active = legacySubscription();

        expect(app(SubscriptionService::class)->markLapsedAsPastDue())->toBe(0)
            ->and($active->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and($active->fresh()->past_due_at)->toBeNull();

        // Renovação local antiga (InfinitePay): não emite a cobrança de julho.
        $local = legacySubscription(['gateway' => 'infinitepay', 'gateway_subscription_id' => null]);

        expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(0);
        Queue::assertNotPushed(RenewSubscriptionJob::class);

        expect($local->isDueForLocalRenewal())->toBeFalse();
        Http::assertNothingSent();
    });

    it('manager: "Revisar cobrança" com contagem e filtro, fora de "Em atraso"; nova assinatura tira a marcação da antiga e para a recorrência dela', function () {
        $pastDue = legacySubscription(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-07-10 23:59:59']);
        $expired = legacySubscription(['status' => SubscriptionStatus::Expired]);

        legacyManager()->get(route('manager.subscriptions.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.needs_review', 2)
                ->where('summary.past_due', 0)
                ->where('summary.awaiting_payment', 0)
                ->where('summary.without_access', 0)
                ->where('t.needs_review_badge', __('manager_subscriptions.needs_review_badge')));

        legacyManager()->get(route('manager.subscriptions.index', ['status' => 'needs_review']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 2)
                ->where('subscriptions.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['needs_reconciliation'] === true)));

        legacyManager()->get(route('manager.subscriptions.index', ['status' => 'past_due']))->assertOk()
            ->assertInertia(fn ($page) => $page->has('subscriptions.data', 0));

        // Cortesia nova para a empresa da expirada: a antiga deixa de liberar acesso.
        legacyManager()->postJson(route('manager.subscriptions.store'), [
            'entity_id' => $expired->entity_id,
            'plan_id'   => $this->plan->id,
            'mode'      => 'complimentary',
            'ends_at'   => now()->addMonth()->toDateString(),
            'reason'    => 'Cortesia de 1 mês enquanto a cobrança antiga é revisada.',
        ])->assertCreated();

        expect($expired->fresh()->needs_billing_reconciliation)->toBeFalse()
            ->and($expired->fresh()->hasAccess())->toBeFalse()
            ->and($pastDue->fresh()->needs_billing_reconciliation)->toBeTrue();

        // A expirada seguia cobrando no Asaas: a recorrência dela para (só a dela).
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/v3/subscriptions/' . $expired->gateway_subscription_id));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/v3/subscriptions/' . $pastDue->gateway_subscription_id));
    });
});

describe('billing:reconcile-legacy', function () {
    it('simulação não grava; --apply concilia o pagante em dia com o ciclo e o valor que o Asaas cobra (anual num plano mensal)', function () {
        $subscription = legacySubscription(['starts_at' => '2025-11-10 09:00:00', 'ends_at' => '2026-11-10 09:00:00', 'last_payment_at' => '2025-11-08 15:00:00']);
        legacyRecurrence($subscription, ['cycle' => 'YEARLY', 'value' => 2878.99, 'nextDueDate' => '2026-11-10'], [
            ['id' => 'pay_ano_1', 'status' => 'RECEIVED', 'dueDate' => '2025-11-10', 'paymentDate' => '2025-11-08', 'value' => 2878.99],
            ['id' => 'pay_ano_2', 'status' => 'PENDING', 'dueDate' => '2026-11-10', 'value' => 2878.99, 'invoiceUrl' => 'https://www.asaas.com/i/ano_2'],
        ]);

        expect(Artisan::call('billing:reconcile-legacy'))->toBe(0)
            ->and(Artisan::output())
            ->toContain($subscription->id)
            ->toContain(__('billing_console.reconcile.outcome.active'))
            ->toContain('2026-11-10')
            ->toContain(__('billing_console.reconcile.dry_run'));

        expect($subscription->fresh()->needs_billing_reconciliation)->toBeTrue()
            ->and($subscription->fresh()->next_billing_at)->toBeNull()
            ->and(SubscriptionChange::query()->count())->toBe(0)
            ->and(Invoice::query()->count())->toBe(0);

        expect(Artisan::call('billing:reconcile-legacy', ['--apply' => true]))->toBe(0)
            ->and(Artisan::output())->toContain(__('billing_console.reconcile.applied', ['count' => 1]));

        $subscription->refresh();
        $invoice = Invoice::query()->sole();
        $change  = SubscriptionChange::query()->where('subscription_id', $subscription->id)->sole();

        expect($subscription->needs_billing_reconciliation)->toBeFalse()
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->billing_cycle)->toBe(BillingCycle::Yearly)
            ->and((float) $subscription->amount)->toBe(2878.99)
            ->and($subscription->ends_at->toDateTimeString())->toBe('2026-11-10 23:59:59')
            ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-11-10 23:59:59')
            ->and($subscription->last_payment_at->toDateString())->toBe('2025-11-08')
            ->and($subscription->accessLevel())->toBe(SubscriptionAccessLevel::Full)
            // O próximo pagamento estende um ano (o ciclo cobrado), não um mês.
            ->and($subscription->periodEndFrom(CarbonImmutable::parse('2026-11-10'))->toDateString())->toBe('2027-11-10')
            ->and($invoice->external_invoice_id)->toBe('pay_ano_2')
            ->and($invoice->payment_url)->toBe('https://www.asaas.com/i/ano_2')
            ->and($invoice->status)->toBe(InvoiceStatus::Pending)
            ->and($invoice->period_start->toDateString())->toBe('2026-11-10')
            ->and($invoice->period_end->toDateString())->toBe('2027-11-10')
            ->and((float) $invoice->amount)->toBe(2878.99)
            ->and($change->change_type)->toBe('legacy_reconciled')
            ->and($change->metadata['outcome'])->toBe('active')
            ->and($change->metadata['previous']['next_billing_at'])->toBeNull()
            ->and($change->metadata['new']['next_billing_at'])->toStartWith('2026-11-10')
            ->and($change->metadata['recurrence']['cycle'])->toBe('YEARLY');

        // Idempotente: nada mais a conciliar.
        expect(Artisan::call('billing:reconcile-legacy', ['--apply' => true]))->toBe(0)
            ->and(Artisan::output())->toContain(__('billing_console.reconcile.nothing'));

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    });

    it('expirada só pelo job antigo, com a recorrência em dia no Asaas, volta a ativa', function () {
        $subscription = legacySubscription(['status' => SubscriptionStatus::Expired]);
        legacyRecurrence($subscription, ['cycle' => 'MONTHLY', 'value' => 299.90, 'nextDueDate' => '2026-11-10'], [
            ['id' => 'pay_set', 'status' => 'CONFIRMED', 'dueDate' => '2026-09-10', 'paymentDate' => '2026-09-09', 'value' => 299.90],
            ['id' => 'pay_out', 'status' => 'RECEIVED', 'dueDate' => '2026-10-10', 'paymentDate' => '2026-10-04', 'value' => 299.90],
        ]);

        Artisan::call('billing:reconcile-legacy', ['--apply' => true]);

        $subscription->refresh();

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->needs_billing_reconciliation)->toBeFalse()
            ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-11-10 23:59:59')
            ->and($subscription->last_payment_at->toDateString())->toBe('2026-10-04')
            ->and($subscription->billing_cycle)->toBe(BillingCycle::Monthly)
            ->and($subscription->hasAccess())->toBeTrue()
            ->and(SubscriptionChange::query()->sole()->metadata['reason_key'])->toBe('reactivated');
    });

    it('cobrança vencida: em atraso com a régua contando da conciliação (avisos antes de qualquer bloqueio); vencimento real no relatório e no histórico', function () {
        // 20 dias de atraso no Asaas, nenhum aviso nosso até hoje.
        $subscription = legacySubscription();
        legacyRecurrence($subscription, ['cycle' => 'MONTHLY', 'value' => 299.90, 'nextDueDate' => '2026-10-15'], [
            ['id' => 'pay_ago', 'status' => 'RECEIVED', 'dueDate' => '2026-08-15', 'paymentDate' => '2026-08-14', 'value' => 299.90],
            ['id' => 'pay_out', 'status' => 'OVERDUE', 'dueDate' => '2026-09-15', 'value' => 299.90, 'invoiceUrl' => 'https://www.asaas.com/i/out'],
        ]);

        // Simulação: vencimento real e dias de atraso reais na tabela, com a nota da régua.
        Artisan::call('billing:reconcile-legacy');

        expect(Artisan::output())->toContain('2026-09-15')
            ->toContain(__('billing_console.reconcile.note_past_due'));

        Artisan::call('billing:reconcile-legacy', ['--apply' => true]);

        $subscription->refresh();
        $change = SubscriptionChange::query()->where('subscription_id', $subscription->id)->sole();

        expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
            ->and($subscription->past_due_at->toDateTimeString())->toBe('2026-10-05 10:00:00')
            ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-09-15 23:59:59')
            ->and($subscription->isInDunning())->toBeTrue()
            ->and($subscription->daysOverdue())->toBe(0)
            ->and($subscription->unpaidDueDate()->toDateString())->toBe('2026-09-15')
            // Nada de bloqueio na hora: acesso total, com o aviso.
            ->and($subscription->accessLevel())->toBe(SubscriptionAccessLevel::Full)
            ->and($subscription->payableInvoice()?->payment_url)->toBe('https://www.asaas.com/i/out')
            ->and($subscription->payableInvoice()?->status)->toBe(InvoiceStatus::Overdue)
            ->and($change->metadata['original_due_date'])->toBe('2026-09-15')
            ->and($change->reason)->toBeNull();

        // D+3 da conciliação: limitado; D+7: sem acesso (a régua encerra).
        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00'));
        expect($subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Limited);

        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00'));
        expect($subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::None);
    });

    it('régua da linha conciliada em atraso: o 1º aviso sai na conciliação, com o vencimento real no e-mail e as datas de bloqueio contadas dela', function () {
        Notification::fake();
        config(['billing.dunning.enabled' => true]);

        $subscription = legacySubscription();
        $admin        = User::factory()->create();
        $member       = createEntityUser($subscription->entity, $admin, ClientRule::Admin->value);
        legacyRecurrence($subscription, ['cycle' => 'MONTHLY', 'value' => 299.90, 'nextDueDate' => '2026-10-15'], [
            ['id' => 'pay_ago', 'status' => 'RECEIVED', 'dueDate' => '2026-08-15', 'paymentDate' => '2026-08-14', 'value' => 299.90],
            ['id' => 'pay_out', 'status' => 'OVERDUE', 'dueDate' => '2026-09-15', 'value' => 299.90, 'invoiceUrl' => 'https://www.asaas.com/i/out'],
        ]);

        Artisan::call('billing:reconcile-legacy', ['--apply' => true]);
        Artisan::call('billing:dunning');

        $notification = Notification::sent($admin, SubscriptionDunningNotification::class)->sole();
        $mail         = $notification->toMail($admin);

        expect($notification->step)->toBe(DunningStep::Overdue)
            ->and(implode(' ', $mail->introLines))->toContain('15/09/2026')
            ->toContain('08/10/2026')
            ->toContain('12/10/2026');

        // No painel: venceu há 20 dias, bloqueios contados da conciliação.
        $banner = app(SubscriptionNoticeService::class)->banner($subscription->fresh(), $admin);

        expect($banner['kind'])->toBe('overdue')
            ->and($banner['due_date'])->toBe('2026-09-15')
            ->and($banner['days_overdue'])->toBe(20)
            ->and($banner['limited_date'])->toBe('2026-10-08')
            ->and($banner['blocked_date'])->toBe('2026-10-12');

        $this->actingAs($admin)->withSession(panelSession($member))->get(route('panel.dashboard'))->assertOk();
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    });

    it('contratação nunca paga com a cobrança vencida: o prazo recomeça como numa contratação feita na conciliação (nada de bloqueio na hora)', function () {
        $subscription = legacySubscription(['status' => SubscriptionStatus::PastDue, 'billing_state' => 'past_due', 'last_payment_at' => null]);
        legacyRecurrence($subscription, ['cycle' => 'MONTHLY', 'value' => 299.90, 'nextDueDate' => '2026-10-20'], [
            ['id' => 'pay_1a', 'status' => 'OVERDUE', 'dueDate' => '2026-09-20', 'value' => 299.90, 'invoiceUrl' => 'https://www.asaas.com/i/1a'],
        ]);

        Artisan::call('billing:reconcile-legacy', ['--apply' => true]);

        $subscription->refresh();

        expect($subscription->isAwaitingFirstPayment())->toBeTrue()
            ->and($subscription->firstChargeAccessEndsAt()?->toDateTimeString())->toBe('2026-10-08 23:59:59')
            ->and($subscription->hasAccess())->toBeTrue()
            ->and($subscription->dunningStage())->toBeNull()
            ->and(SubscriptionChange::query()->sole()->metadata['original_due_date'])->toBe('2026-09-20')
            ->and(SubscriptionChange::query()->sole()->metadata['outcome'])->toBe('awaiting_payment');
    });

    it('contratação do código anterior nunca paga, com a 1ª cobrança a vencer: aguardando o 1º pagamento (acesso até o vencimento)', function () {
        $subscription = legacySubscription(['status' => SubscriptionStatus::PastDue, 'billing_state' => 'past_due', 'last_payment_at' => null, 'past_due_at' => '2026-10-01 10:00:00']);
        legacyRecurrence($subscription, ['cycle' => 'MONTHLY', 'value' => 299.90, 'nextDueDate' => '2026-11-07'], [
            ['id' => 'pay_1a', 'status' => 'PENDING', 'dueDate' => '2026-10-07', 'value' => 299.90, 'invoiceUrl' => 'https://www.asaas.com/i/1a'],
        ]);

        Artisan::call('billing:reconcile-legacy', ['--apply' => true]);

        $subscription->refresh();

        expect($subscription->isAwaitingFirstPayment())->toBeTrue()
            ->and($subscription->past_due_at)->toBeNull()
            ->and($subscription->firstChargeAccessEndsAt()?->toDateTimeString())->toBe('2026-10-07 23:59:59')
            ->and($subscription->hasAccess())->toBeTrue();
    });

    it('recorrência inativa, removida, inexistente, gateway sem consulta, cobrança nunca emitida ou pagamento posterior à vencida: não mexe e fica para revisão', function () {
        $inactive = legacySubscription();
        legacyRecurrence($inactive, ['status' => 'INACTIVE', 'cycle' => 'MONTHLY', 'value' => 299.90]);

        $deleted = legacySubscription();
        legacyRecurrence($deleted, ['deleted' => true, 'cycle' => 'MONTHLY', 'value' => 299.90]);

        $missing     = legacySubscription();
        $mercadoPago = legacySubscription(['gateway' => 'mercadopago', 'gateway_subscription_id' => null]);
        $notIssued   = legacySubscription(['status' => SubscriptionStatus::PastDue, 'gateway' => null, 'gateway_subscription_id' => null, 'last_payment_at' => null]);

        $paidLater = legacySubscription();
        legacyRecurrence($paidLater, ['cycle' => 'MONTHLY', 'value' => 299.90, 'nextDueDate' => '2026-11-10'], [
            ['id' => 'pay_esquecida', 'status' => 'OVERDUE', 'dueDate' => '2026-08-10', 'value' => 299.90],
            ['id' => 'pay_paga', 'status' => 'RECEIVED', 'dueDate' => '2026-09-10', 'paymentDate' => '2026-09-10', 'value' => 299.90],
        ]);

        $gatewayDown = legacySubscription();
        legacyRecurrence($gatewayDown, [], [], status: 503);

        $before = Subscription::query()->orderBy('id')->get()->map->only(['id', 'status', 'ends_at', 'next_billing_at', 'past_due_at'])->all();

        expect(Artisan::call('billing:reconcile-legacy', ['--apply' => true]))->toBe(0);

        $output = Artisan::output();

        foreach (['recurrence_inactive', 'recurrence_not_found', 'no_query_api', 'never_issued', 'overdue_with_later_payment', 'gateway_error'] as $reason) {
            expect($output)->toContain(__("billing_console.reconcile.reason.{$reason}"));
        }

        expect(Subscription::query()->orderBy('id')->get()->map->only(['id', 'status', 'ends_at', 'next_billing_at', 'past_due_at'])->all())->toEqual($before)
            ->and(Subscription::query()->needingBillingReconciliation()->count())->toBe(7)
            ->and(SubscriptionChange::query()->count())->toBe(0)
            ->and(Invoice::query()->count())->toBe(0);

        // Gateway sem API de consulta: nenhuma chamada a ele.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'mercadopago'));

        // Seguem com acesso total até a revisão.
        foreach ([$inactive, $deleted, $missing, $mercadoPago, $notIssued, $paidLater, $gatewayDown] as $row) {
            expect($row->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Full);
        }
    });

    it('--close sem --action (ou sem motivo) recusa com mensagem clara e não mexe em nada', function () {
        $subscription = legacySubscription(['last_payment_at' => '2026-06-10 11:00:00']);

        expect(Artisan::call('billing:reconcile-legacy', ['--close' => $subscription->id, '--reason' => 'Recorrência cancelada no Asaas em agosto (ticket #812).']))->toBe(2)
            ->and(Artisan::output())->toContain(__('billing_console.reconcile.action_required'));

        expect(Artisan::call('billing:reconcile-legacy', ['--close' => $subscription->id, '--action' => 'apagar', '--reason' => 'Recorrência cancelada no Asaas em agosto (ticket #812).']))->toBe(2)
            ->and(Artisan::call('billing:reconcile-legacy', ['--close' => $subscription->id, '--action' => 'cancel']))->toBe(2)
            ->and(Artisan::call('billing:reconcile-legacy', ['--close' => $subscription->id, '--action' => 'paid-until', '--reason' => 'Pago por fora até novembro (ticket #813).']))->toBe(2)
            ->and(Artisan::output())->toContain(__('billing_console.reconcile.until_required'))
            ->and(Artisan::call('billing:reconcile-legacy', ['--close' => $subscription->id, '--action' => 'paid-until', '--until' => '2026-02-30', '--reason' => 'Pago por fora até novembro (ticket #813).']))->toBe(2);

        $fresh = $subscription->fresh();

        expect($fresh->needs_billing_reconciliation)->toBeTrue()
            ->and($fresh->status)->toBe(SubscriptionStatus::Active)
            ->and($fresh->accessLevel())->toBe(SubscriptionAccessLevel::Full)
            ->and(SubscriptionChange::query()->count())->toBe(0);

        Http::assertNothingSent();
    });

    it('--action=cancel cancela, para a recorrência no gateway e registra antes/depois', function () {
        $subscription = legacySubscription(['status' => SubscriptionStatus::Expired]);

        expect(Artisan::call('billing:reconcile-legacy', [
            '--close'  => $subscription->id,
            '--action' => 'cancel',
            '--reason' => 'Cliente saiu em agosto; recorrência ainda ativa no Asaas (ticket #812).',
        ]))->toBe(0);

        $subscription->refresh();
        $change = SubscriptionChange::query()->where('subscription_id', $subscription->id)->where('change_type', 'legacy_review_closed')->sole();

        expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)
            ->and($subscription->needs_billing_reconciliation)->toBeFalse()
            ->and($subscription->hasAccess())->toBeFalse()
            ->and($change->metadata['action'])->toBe('cancel')
            ->and($change->metadata['previous']['status'])->toBe('expired')
            ->and($change->metadata['new']['status'])->toBe('cancelled')
            ->and($change->metadata['gateway_recurrence_cancelled'])->toBeTrue()
            ->and($change->metadata['reason_text'])->toBe('Cliente saiu em agosto; recorrência ainda ativa no Asaas (ticket #812).');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/v3/subscriptions/' . $subscription->gateway_subscription_id));
    });

    it('--action=paid-until ativa até o fim do dia informado e segue o fluxo novo (sem bloqueio, sem expiração, renovação no vencimento)', function () {
        Queue::fake();

        // Pagante do código anterior na InfinitePay (renovação local, sem consulta).
        $subscription = legacySubscription(['gateway' => 'infinitepay', 'gateway_subscription_id' => null, 'last_payment_at' => '2026-09-10 11:00:00']);

        expect(Artisan::call('billing:reconcile-legacy', [
            '--close'  => $subscription->id,
            '--action' => 'paid-until',
            '--until'  => '2026-10-20',
            '--reason' => 'Pago por PIX direto até 20/10 (comprovante no ticket #820).',
        ]))->toBe(0);

        $subscription->refresh();
        $change = SubscriptionChange::query()->where('subscription_id', $subscription->id)->sole();

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->needs_billing_reconciliation)->toBeFalse()
            ->and($subscription->ends_at->toDateTimeString())->toBe('2026-10-20 23:59:59')
            ->and($subscription->next_billing_at->toDateTimeString())->toBe('2026-10-20 23:59:59')
            ->and($subscription->last_payment_at->toDateString())->toBe('2026-09-10')
            ->and($subscription->isInDunning())->toBeFalse()
            ->and($subscription->accessLevel())->toBe(SubscriptionAccessLevel::Full)
            ->and($change->metadata['action'])->toBe('paid-until')
            ->and($change->metadata['paid_until'])->toBe('2026-10-20')
            ->and($change->metadata['previous']['ends_at'])->toStartWith('2026-07-10')
            ->and($change->metadata['new']['ends_at'])->toStartWith('2026-10-20');

        // Nada de cobrar julho nem de pôr em atraso: a renovação local só
        // emite a cobrança do vencimento novo, na antecedência dela.
        expect(app(SubscriptionService::class)->markLapsedAsPastDue())->toBe(0)
            ->and(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(0);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 01:00:00'));
        expect(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(1);

        Http::assertNothingSent();
    });

    it('--action=paid-until sem recorrência ativa nem renovação local vira cortesia até a data (nada a cobrar, sem régua)', function () {
        Notification::fake();
        config(['billing.dunning.enabled' => true]);

        $subscription = legacySubscription();
        legacyRecurrence($subscription, ['status' => 'INACTIVE']);

        expect(Artisan::call('billing:reconcile-legacy', [
            '--close'  => $subscription->id,
            '--action' => 'paid-until',
            '--until'  => '2026-10-20',
            '--reason' => 'Pago por fora até 20/10; recorrência já inativa no Asaas (ticket #830).',
        ]))->toBe(0);

        $subscription->refresh();
        $change = SubscriptionChange::query()->where('subscription_id', $subscription->id)->sole();

        expect($subscription->billing_mode)->toBe(SubscriptionBillingMode::Complimentary)
            ->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->ends_at->toDateTimeString())->toBe('2026-10-20 23:59:59')
            ->and($subscription->next_billing_at)->toBeNull()
            ->and($subscription->amount)->toBeNull()
            ->and($subscription->needs_billing_reconciliation)->toBeFalse()
            ->and($subscription->isInDunning())->toBeFalse()
            ->and($change->metadata['as_complimentary'])->toBeTrue();

        // Depois da data: bloqueio do fim da cortesia (D3), sem régua nem e-mail.
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00:00'));
        Artisan::call('billing:dunning');

        expect($subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::None);
        Notification::assertNothingSent();
    });

    it('--action=paid-until recusa data passada e linha que não é a vigente da empresa', function () {
        $subscription = legacySubscription();

        expect(Artisan::call('billing:reconcile-legacy', [
            '--close'  => $subscription->id, '--action' => 'paid-until', '--until' => '2026-10-04',
            '--reason' => 'Pago por PIX direto até ontem (ticket #821).',
        ]))->toBe(1)
            ->and(Artisan::output())->toContain(__('billing_console.reconcile.until_past', ['date' => '2026-10-04']));

        $old = legacySubscription(['status' => SubscriptionStatus::PastDue, 'created_at' => now()->subYear()], $subscription->entity);

        expect(Artisan::call('billing:reconcile-legacy', [
            '--close'  => $old->id, '--action' => 'paid-until', '--until' => '2026-11-10',
            '--reason' => 'Tentativa de reativar a linha antiga (ticket #822).',
        ]))->toBe(1)
            ->and(Artisan::output())->toContain(__('billing_console.reconcile.not_current', ['id' => $old->id, 'current' => $subscription->id]));

        expect($subscription->fresh()->needs_billing_reconciliation)->toBeTrue()
            ->and($old->fresh()->needs_billing_reconciliation)->toBeTrue()
            ->and($old->fresh()->status)->toBe(SubscriptionStatus::PastDue)
            ->and(SubscriptionChange::query()->count())->toBe(0);
    });
});

it('atraso conciliado: evento posterior da cobrança antiga (recusa/overdue) não antecipa a régua', function () {
    $subscription = legacySubscription([
        'status'                       => SubscriptionStatus::PastDue,
        'needs_billing_reconciliation' => false,
        'next_billing_at'              => '2026-09-15 23:59:59',
        'ends_at'                      => '2026-09-15 23:59:59',
        'past_due_at'                  => now(), // régua começou na conciliação
    ]);

    app(SubscriptionCycleService::class)->markPastDue(
        $subscription,
        CarbonImmutable::parse('2026-09-15 23:59:59'),
        'payment_failed',
        'CAPTURE_REFUSED',
        (string) Str::uuid(),
        'webhook',
    );

    $subscription->refresh();

    expect($subscription->past_due_at->toDateString())->toBe(now()->toDateString())
        ->and($subscription->accessLevel())->toBe(SubscriptionAccessLevel::Full);
});

describe('linha antiga (não vigente) do código anterior', function () {
    it('régua, expiração e renovação local só agem na vigente: a linha antiga nunca gera e-mail à clínica nem cancelamento no gateway', function () {
        Notification::fake();
        Queue::fake();
        config(['billing.dunning.enabled' => true]);

        // G1 já paga, em atraso desde julho (webhook antigo), substituída pela
        // G2 que a clínica paga em dia — mesmo sem a marcação (defesa geral).
        $entity = legacyClinic('Clínica em Dia');
        $admin  = User::factory()->create();
        createEntityUser($entity, $admin, ClientRule::Admin->value);

        $g1 = legacySubscription(['status' => SubscriptionStatus::PastDue, 'billing_state' => 'past_due', 'needs_billing_reconciliation' => false, 'created_at' => now()->subMonths(4)], $entity);
        $g2 = legacySubscription([
            'needs_billing_reconciliation' => false,
            'billing_cycle'                => BillingCycle::Monthly,
            'amount'                       => 299.90,
            'ends_at'                      => '2026-11-03 23:59:59',
            'next_billing_at'              => '2026-11-03 23:59:59',
            'last_payment_at'              => '2026-10-03 10:00:00',
            'created_at'                   => now()->subMonths(2),
        ], $entity);

        // Outra empresa: antiga ativa na InfinitePay com o período vencido,
        // substituída por uma cortesia em vigor.
        $localEntity = legacyClinic('Clínica Cortesia Local');
        $oldLocal    = legacySubscription(['gateway' => 'infinitepay', 'gateway_subscription_id' => null, 'needs_billing_reconciliation' => false, 'created_at' => now()->subMonths(3)], $localEntity);
        Subscription::factory()->complimentary()->for($localEntity)->for($this->plan)->create(['ends_at' => now()->addMonth()]);

        expect(Artisan::call('billing:dunning', ['--dry-run' => true]))->toBe(0)
            ->and(Artisan::output())->not->toContain($g1->id);

        Artisan::call('billing:dunning');

        expect($g1->fresh()->status)->toBe(SubscriptionStatus::PastDue)
            ->and($g1->fresh()->dunningSteps()->count())->toBe(0)
            ->and(app(SubscriptionService::class)->markLapsedAsPastDue())->toBe(0)
            ->and($oldLocal->fresh()->status)->toBe(SubscriptionStatus::Active)
            ->and(app(SubscriptionCycleService::class)->dispatchDueRenewals())->toBe(0)
            ->and($oldLocal->fresh()->isDueForLocalRenewal())->toBeFalse()
            ->and($g2->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Full);

        Notification::assertNothingSentTo($admin);
        Queue::assertNotPushed(RenewSubscriptionJob::class);
        Http::assertNothingSent();
    });

    it('billing:reconcile-legacy: linha antiga com a recorrência viva é "recorrência duplicada" (revisão manual, com a vigente), sem ação automática', function () {
        $current = legacySubscription(['created_at' => now()->subMonth()]);
        $old     = legacySubscription(['status' => SubscriptionStatus::PastDue, 'created_at' => now()->subMonths(6)], $current->entity);

        legacyRecurrence($current, ['cycle' => 'MONTHLY', 'value' => 299.90, 'nextDueDate' => '2026-11-10'], [
            ['id' => 'pay_cur', 'status' => 'RECEIVED', 'dueDate' => '2026-10-10', 'paymentDate' => '2026-10-04', 'value' => 299.90],
        ]);
        legacyRecurrence($old, ['cycle' => 'MONTHLY', 'value' => 199.90, 'nextDueDate' => '2026-10-20'], [
            ['id' => 'pay_old', 'status' => 'RECEIVED', 'dueDate' => '2026-09-20', 'paymentDate' => '2026-09-20', 'value' => 199.90],
        ]);

        expect(Artisan::call('billing:reconcile-legacy', ['--apply' => true]))->toBe(0);

        $output = Artisan::output();

        expect($output)->toContain(__('billing_console.reconcile.reason.duplicated_recurrence'))
            ->toContain(__('billing_console.reconcile.current_is', ['id' => $current->id]))
            ->toContain(__('billing_console.reconcile.note_superseded'));

        // A vigente foi conciliada; a antiga segue marcada, intacta e sem acesso.
        expect($current->fresh()->needs_billing_reconciliation)->toBeFalse()
            ->and($old->fresh()->needs_billing_reconciliation)->toBeTrue()
            ->and($old->fresh()->status)->toBe(SubscriptionStatus::PastDue)
            ->and($old->fresh()->next_billing_at)->toBeNull()
            ->and($old->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::None)
            ->and(SubscriptionChange::query()->where('subscription_id', $old->id)->count())->toBe(0);

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
    });

    it('"Cancelar" do manager alcança a linha marcada expirada (e a antiga em atraso) e para a recorrência de cada uma', function () {
        $entity = legacyClinic('Clínica Cancelar');
        $old    = legacySubscription(['status' => SubscriptionStatus::PastDue, 'billing_state' => 'past_due', 'needs_billing_reconciliation' => false, 'created_at' => now()->subMonths(6)], $entity);
        $marked = legacySubscription(['status' => SubscriptionStatus::Expired, 'created_at' => now()->subMonths(2)], $entity);

        legacyManager()->get(route('manager.subscriptions.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('subscriptions.data', fn ($rows) => collect($rows)
                ->contains(fn ($row) => $row['id'] === $marked->id && $row['is_current'] && $row['needs_reconciliation'])));

        legacyManager()->postJson(route('manager.subscriptions.cancel'), [
            'entity_id' => $entity->id,
            'reason'    => 'Clínica encerrou o contrato; recorrência antiga ainda cobrando (ticket #900).',
        ])->assertOk()->assertJsonPath('data.id', $marked->id);

        expect($marked->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
            ->and($marked->fresh()->needs_billing_reconciliation)->toBeFalse()
            ->and($old->fresh()->status)->toBe(SubscriptionStatus::Cancelled);

        foreach ([$marked, $old] as $row) {
            Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
                && str_ends_with($r->url(), '/v3/subscriptions/' . $row->gateway_subscription_id));
        }

        // Nada mais a cancelar: 404.
        legacyManager()->postJson(route('manager.subscriptions.cancel'), [
            'entity_id' => $entity->id,
            'reason'    => 'Segunda tentativa de cancelar a mesma clínica (ticket #900).',
        ])->assertNotFound();
    });
});

describe('ajustes da conciliação', function () {
    it('o 1º pagamento de uma linha antiga só encerra a conciliação das anteriores a ela — nunca a da vigente (nem para a recorrência dela)', function () {
        $entity  = legacyClinic('Clínica Duas Linhas');
        $older   = legacySubscription(['status' => SubscriptionStatus::Expired, 'created_at' => now()->subMonths(8)], $entity);
        $old     = legacySubscription(['status' => SubscriptionStatus::PastDue, 'last_payment_at' => null, 'created_at' => now()->subMonths(6)], $entity);
        $current = legacySubscription(['created_at' => now()->subMonth()], $entity);

        $stopped = DB::transaction(fn () => app(SubscriptionCycleService::class)->replacePrevious($old, (string) Str::uuid(), 'webhook'));

        expect($stopped->pluck('id')->all())->toBe([$older->id])
            ->and($older->fresh()->needs_billing_reconciliation)->toBeFalse()
            ->and($current->fresh()->needs_billing_reconciliation)->toBeTrue();
    });

    it('cobrança em aberto que já tem o Payment do código anterior: completa a fatura dele, sem criar outra', function () {
        $subscription  = legacySubscription();
        $legacyInvoice = Invoice::query()->create([
            'entity_id'       => $subscription->entity_id,
            'subscription_id' => $subscription->id,
            'plan_id'         => $this->plan->id,
            'reference'       => 'INV-LEGADO-1',
            'amount'          => 299.90,
            'currency'        => 'BRL',
            'status'          => InvoiceStatus::Pending->value,
            'billing_reason'  => 'subscription_create',
        ]);
        Payment::query()->create([
            'entity_id'           => $subscription->entity_id,
            'invoice_id'          => $legacyInvoice->id,
            'subscription_id'     => $subscription->id,
            'gateway_code'        => 'asaas',
            'external_payment_id' => 'pay_out',
            'status'              => 'pending',
            'amount'              => 299.90,
            'currency'            => 'BRL',
            'idempotency_key'     => 'legacy:pay_out',
        ]);

        legacyRecurrence($subscription, ['cycle' => 'MONTHLY', 'value' => 299.90, 'nextDueDate' => '2026-11-03'], [
            ['id' => 'pay_ago', 'status' => 'RECEIVED', 'dueDate' => '2026-09-03', 'paymentDate' => '2026-09-02', 'value' => 299.90],
            ['id' => 'pay_out', 'status' => 'OVERDUE', 'dueDate' => '2026-10-03', 'value' => 299.90, 'invoiceUrl' => 'https://www.asaas.com/i/out'],
        ]);

        Artisan::call('billing:reconcile-legacy', ['--apply' => true]);

        $invoice = $legacyInvoice->fresh();

        expect(Invoice::query()->count())->toBe(1)
            ->and($invoice->external_invoice_id)->toBe('pay_out')
            ->and($invoice->payment_url)->toBe('https://www.asaas.com/i/out')
            ->and($invoice->status)->toBe(InvoiceStatus::Overdue)
            ->and($invoice->period_start->toDateString())->toBe('2026-10-03')
            ->and($invoice->period_end->toDateString())->toBe('2026-11-03')
            ->and($subscription->fresh()->payableInvoice()?->id)->toBe($invoice->id);
    });

    it('link de pagamento fora de http(s) vindo do gateway não é gravado', function () {
        $subscription = legacySubscription();
        legacyRecurrence($subscription, ['cycle' => 'MONTHLY', 'value' => 299.90, 'nextDueDate' => '2026-10-10'], [
            ['id' => 'pay_ok', 'status' => 'RECEIVED', 'dueDate' => '2026-09-10', 'paymentDate' => '2026-09-10', 'value' => 299.90],
            ['id' => 'pay_js', 'status' => 'PENDING', 'dueDate' => '2026-10-10', 'value' => 299.90, 'invoiceUrl' => 'javascript:alert(1)'],
        ]);

        Artisan::call('billing:reconcile-legacy', ['--apply' => true]);

        expect(Invoice::query()->where('external_invoice_id', 'pay_js')->sole()->payment_url)->toBeNull()
            ->and($subscription->fresh()->payableInvoice())->toBeNull();
    });

    it('cobrança nunca emitida ou sem recorrência registrada: procura a recorrência órfã pela referência antes de liberar', function () {
        $notIssued                                = legacySubscription(['status' => SubscriptionStatus::PastDue, 'gateway' => null, 'gateway_subscription_id' => null, 'last_payment_at' => null]);
        $this->asaas->byReference[$notIssued->id] = ['sub_orfa_777'];

        expect(Artisan::call('billing:reconcile-legacy'))->toBe(0)
            ->and(Artisan::output())->toContain(__('billing_console.reconcile.reason.orphan_recurrence'))
            ->toContain('sub_orfa_777');

        expect(Artisan::call('billing:reconcile-legacy', [
            '--close'  => $notIssued->id, '--action' => 'cancel',
            '--reason' => 'Contratação que nunca foi emitida (ticket #830).',
        ]))->toBe(1)
            ->and(Artisan::output())->toContain(__('billing_console.reconcile.orphan_found', ['id' => $notIssued->id, 'ids' => 'sub_orfa_777']))
            ->and($notIssued->fresh()->needs_billing_reconciliation)->toBeTrue();

        // Cancelada à mão no Asaas: agora libera.
        $this->asaas->byReference[$notIssued->id] = [];

        expect(Artisan::call('billing:reconcile-legacy', [
            '--close'  => $notIssued->id, '--action' => 'cancel',
            '--reason' => 'Contratação que nunca foi emitida (ticket #830).',
        ]))->toBe(0)
            ->and($notIssued->fresh()->status)->toBe(SubscriptionStatus::Cancelled);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    });

    it('histórico do manager mostra o resultado da conciliação traduzido, nunca o nome do comando', function () {
        $subscription = legacySubscription();
        legacyRecurrence($subscription, ['cycle' => 'MONTHLY', 'value' => 299.90, 'nextDueDate' => '2026-11-10'], [
            ['id' => 'pay_set', 'status' => 'RECEIVED', 'dueDate' => '2026-10-10', 'paymentDate' => '2026-10-04', 'value' => 299.90],
        ]);

        Artisan::call('billing:reconcile-legacy', ['--apply' => true]);

        $event = collect(legacyManager()->getJson(route('manager.subscriptions.history', $subscription))->assertOk()->json('data.events'))
            ->firstWhere('type', 'legacy_reconciled');

        expect($event['reason'])->toBe(__('billing_console.reconcile.reason.in_good_standing'))
            ->and($event['reason'])->not->toContain('billing:reconcile-legacy');
    });
});
