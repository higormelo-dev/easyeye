<?php

declare(strict_types=1);

use App\Enums\Billing\{CancellationReason, DunningStep, InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, ClientRule, SaasRule, SubscriptionAccessLevel, SubscriptionStatus};
use App\Jobs\Billing\CancelGatewaySubscriptionJob;
use App\Models\Billing\{BillingLog, Cancellation, Invoice, Payment, SubscriptionChange, SubscriptionDunningStep};
use App\Models\{Entity, Plan, Subscription, User};
use App\Notifications\SubscriptionDunningNotification;
use App\Services\Billing\{DunningService, PlatformFinanceService};
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\{Factory, Request};
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Artisan, Http, Notification, Queue};
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Régua de cobrança (billing:dunning): D-5 lembrete, D+1 pagamento não
 * identificado com o link, D+3 acesso limitado, D+7 encerramento com a
 * cobrança parada no gateway. Contratação nunca paga: aviso no dia seguinte
 * ao vencimento e encerramento no D+7. Cada etapa sai uma vez, só para
 * admin/financeiro/dono da própria empresa, com e-mail verificado.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-01 09:00:00'));
    Notification::fake();
    Http::fake(['https://api.asaas.com/v3/subscriptions/*' => Http::response(['deleted' => true], 200)]);

    $this->plan   = Plan::factory()->create(['active' => true, 'name' => 'Pro']);
    $this->clinic = dunningClinic('Clínica Régua');

    // Destinatários: admin, financeiro e o dono (mesmo com outro perfil).
    $this->admin     = dunningMember($this->clinic, ClientRule::Admin);
    $this->financial = dunningMember($this->clinic, ClientRule::Financial);
    $this->owner     = dunningMember($this->clinic, ClientRule::Doctor, isOwner: true);

    // Fora: sem e-mail verificado, perfis clínicos, inativo, outra clínica.
    $this->unverified = dunningMember($this->clinic, ClientRule::Admin, verified: false);
    $this->secretary  = dunningMember($this->clinic, ClientRule::Secretary);
    $this->doctor     = dunningMember($this->clinic, ClientRule::Doctor);
    $this->inactive   = dunningMember($this->clinic, ClientRule::Admin, active: false);
    $this->outsider   = dunningMember(dunningClinic('Outra Clínica'), ClientRule::Admin);
});

afterEach(fn () => Carbon::setTestNow());

function dunningClinic(string $name): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => $name]);
    $entity->skipAutoTrial = true;
    $entity->save();

    return $entity;
}

function dunningMember(Entity $entity, ClientRule $rule, bool $isOwner = false, bool $verified = true, bool $active = true): User
{
    $user = $verified ? User::factory()->create() : User::factory()->unverified()->create();
    createEntityUser($entity, $user, $rule->value, $active, $isOwner);

    return $user;
}

/** Cliente pagante (Asaas, recorrência nativa) com a próxima cobrança em 08/10. */
function dunningPaying(array $attributes = []): Subscription
{
    $subscription = Subscription::factory()->gateway()->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => 'sub_regua_001',
        'billing_cycle'           => BillingCycle::Monthly,
        'amount'                  => 299.90,
        'last_payment_at'         => '2026-09-08 10:00:00',
        'ends_at'                 => '2026-10-08 23:59:59',
        'next_billing_at'         => '2026-10-08 23:59:59',
        ...$attributes,
    ]);

    Invoice::query()->create([
        'entity_id'       => $subscription->entity_id,
        'subscription_id' => $subscription->id,
        'plan_id'         => $subscription->plan_id,
        'gateway_code'    => 'asaas',
        'reference'       => 'INV-20261008-REGUA001',
        'period_start'    => '2026-10-08',
        'period_end'      => '2026-11-08',
        'due_at'          => '2026-10-08 23:59:59',
        'amount'          => 299.90,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Pending->value,
        'payment_url'     => 'https://www.asaas.com/i/pay_regua_001',
    ]);

    return $subscription;
}

function dunningRunAt(string $at): array
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(DunningService::class)->run();
}

/** @return list<string> e-mails que receberam a etapa */
function dunningRecipientsOf(DunningStep $step): array
{
    $emails = [];

    foreach ([test()->admin, test()->financial, test()->owner, test()->unverified, test()->secretary, test()->doctor, test()->inactive, test()->outsider] as $user) {
        if (Notification::sent($user, SubscriptionDunningNotification::class, fn ($n) => $n->step === $step)->isNotEmpty()) {
            $emails[] = $user->email;
        }
    }

    sort($emails);

    return $emails;
}

/** Linhas do e-mail num texto só (o Intl separa moeda e valor com espaço inquebrável). */
function dunningText(array $lines): string
{
    return str_replace("\u{00A0}", ' ', implode(' ', $lines));
}

function dunningExpectedRecipients(): array
{
    $emails = [test()->admin->email, test()->financial->email, test()->owner->email];
    sort($emails);

    return $emails;
}

it('cliente pagante: D-5, D+1, D+3 e D+7 — cada etapa uma vez, só para admin, financeiro e dono', function () {
    $subscription = dunningPaying();

    // D-6: fora da antecedência.
    expect(dunningRunAt('2026-10-02 09:00:00')[DunningStep::Reminder->value])->toBe(0);

    // D-5: lembrete com valor, data e link.
    expect(dunningRunAt('2026-10-03 09:00:00')[DunningStep::Reminder->value])->toBe(1)
        ->and(dunningRecipientsOf(DunningStep::Reminder))->toBe(dunningExpectedRecipients());

    $reminder = Notification::sent($this->admin, SubscriptionDunningNotification::class)->first();
    $mail     = $reminder->toMail($this->admin);

    expect($mail->subject)->toContain('08/10/2026')
        ->and(dunningText($mail->introLines))->toContain('R$ 299,90')->toContain($this->clinic->fresh()->name)
        // Paga dentro do sistema (fatura em Minha assinatura), não no link do gateway.
        ->and($mail->actionUrl)->toBe(route('panel.my-subscription.index', ['invoice' => $reminder->context['invoice_id']]))
        ->and($mail->actionUrl)->not->toContain('asaas.com');

    // Rodar de novo (mesmo dia ou nos seguintes) não repete.
    dunningRunAt('2026-10-03 15:00:00');
    dunningRunAt('2026-10-05 09:00:00');
    Notification::assertSentToTimes($this->admin, SubscriptionDunningNotification::class, 1);

    // Venceu sem pagamento: a expiração da madrugada põe em atraso; D+1 avisa com o link.
    $this->travelTo(CarbonImmutable::parse('2026-10-09 00:10:00'));
    app(SubscriptionService::class)->markLapsedAsPastDue();

    expect(dunningRunAt('2026-10-09 09:00:00')[DunningStep::Overdue->value])->toBe(1)
        ->and(dunningRecipientsOf(DunningStep::Overdue))->toBe(dunningExpectedRecipients());

    $overdue = Notification::sent($this->financial, SubscriptionDunningNotification::class, fn ($n) => $n->step === DunningStep::Overdue)->first();
    $mail    = $overdue->toMail($this->financial);

    expect($overdue->dueOn)->toBe('2026-10-08')
        ->and($mail->actionUrl)->toBe(route('panel.my-subscription.index', ['invoice' => $overdue->context['invoice_id']]))
        ->and(dunningText($mail->introLines))->toContain('11/10/2026')->toContain('15/10/2026');

    expect(dunningRunAt('2026-10-10 09:00:00')[DunningStep::Overdue->value])->toBe(0);

    // D+3: acesso limitado.
    expect(dunningRunAt('2026-10-11 09:00:00')[DunningStep::Limited->value])->toBe(1)
        ->and(dunningRecipientsOf(DunningStep::Limited))->toBe(dunningExpectedRecipients())
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    // D+7: encerra por inadimplência e para a cobrança no gateway.
    expect(dunningRunAt('2026-10-15 09:00:00')[DunningStep::Terminated->value])->toBe(1);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Expired)
        ->and($subscription->hasAccess())->toBeFalse()
        ->and($subscription->invoices()->sole()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(SubscriptionChange::where('subscription_id', $subscription->id)->where('change_type', 'expired')->sole()->reason)
        ->toBe(CancellationReason::NonPayment->value)
        ->and(dunningRecipientsOf(DunningStep::Terminated))->toBe(dunningExpectedRecipients());

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v3/subscriptions/sub_regua_001'));

    $terminated = Notification::sent($this->owner, SubscriptionDunningNotification::class, fn ($n) => $n->step === DunningStep::Terminated)->first();
    expect($terminated->context['payment_url'])->toBeNull();

    // Nada mais depois do encerramento.
    expect(array_sum(dunningRunAt('2026-10-16 09:00:00')))->toBe(0)
        ->and(SubscriptionDunningStep::where('subscription_id', $subscription->id)->pluck('step')->map->value->all())
        ->toEqualCanonicalizing(['reminder', 'overdue', 'limited', 'terminated'])
        ->and(SubscriptionDunningStep::where('subscription_id', $subscription->id)->where('step', 'reminder')->value('recipients_count'))->toBe(3);

    // Antes de limitar (D+3) e de encerrar (D+7) a régua conferiu no gateway
    // se a cobrança foi paga (só GET da recorrência); fora isso, só o
    // cancelamento da recorrência.
    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]);

    expect($sent->reject(fn ($r) => $r->method() === 'GET')->count())->toBe(1)
        ->and($sent->filter(fn ($r) => $r->method() === 'GET')->every(fn ($r) => str_contains($r->url(), '/v3/subscriptions/sub_regua_001')))->toBeTrue()
        ->and($sent->filter(fn ($r) => $r->method() === 'GET')->count())->toBeGreaterThan(0);
});

it('pagou no D+2: não avisa acesso limitado nem encerra; o aviso na fila deixa de valer', function () {
    $subscription = dunningPaying(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-10-08 23:59:59']);

    dunningRunAt('2026-10-09 09:00:00');
    $queued = Notification::sent($this->admin, SubscriptionDunningNotification::class)->first();

    // Pagamento confirmado (webhook): período renovado, atraso zerado.
    $this->travelTo(CarbonImmutable::parse('2026-10-10 11:00:00'));
    $subscription->update([
        'status'          => SubscriptionStatus::Active,
        'last_payment_at' => now(),
        'past_due_at'     => null,
        'ends_at'         => '2026-11-08 23:59:59',
        'next_billing_at' => '2026-11-08 23:59:59',
    ]);

    expect($queued->shouldSend($this->admin, 'mail'))->toBeFalse()
        ->and(array_sum(dunningRunAt('2026-10-11 09:00:00')))->toBe(0)
        ->and(array_sum(dunningRunAt('2026-10-15 09:00:00')))->toBe(0)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

    Http::assertNothingSent();
});

it('sem rodar no D+1, o D+3 manda só o aviso de acesso limitado', function () {
    dunningPaying(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-10-08 23:59:59']);

    $stats = dunningRunAt('2026-10-12 09:00:00');

    expect($stats[DunningStep::Limited->value])->toBe(1)
        ->and($stats[DunningStep::Overdue->value])->toBe(0);
});

it('contratação nunca paga: avisa no dia seguinte ao vencimento e encerra no D+7, parando a recorrência no gateway', function () {
    $pending = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'status'                  => SubscriptionStatus::PastDue,
        'billing_state'           => 'pending_activation',
        'gateway_subscription_id' => 'sub_first_001',
        'amount'                  => 299.90,
        'last_payment_at'         => null,
        'next_billing_at'         => '2026-10-04 23:59:59',
        'ends_at'                 => '2026-10-04 23:59:59',
    ]);
    Invoice::query()->create([
        'entity_id'       => $pending->entity_id,
        'subscription_id' => $pending->id,
        'plan_id'         => $pending->plan_id,
        'gateway_code'    => 'asaas',
        'reference'       => 'INV-20261004-FIRST001',
        'period_start'    => '2026-10-04',
        'due_at'          => '2026-10-04 23:59:59',
        'amount'          => 299.90,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Pending->value,
        'payment_url'     => 'https://www.asaas.com/i/first_001',
    ]);

    // Ainda no prazo: nada (sem lembrete de régua para quem nunca pagou).
    expect(array_sum(dunningRunAt('2026-10-04 09:00:00')))->toBe(0);

    // Dia seguinte ao vencimento: aviso (o acesso já acabou).
    expect(dunningRunAt('2026-10-05 09:00:00')[DunningStep::FirstChargeOverdue->value])->toBe(1)
        ->and($pending->fresh()->hasAccess())->toBeFalse()
        ->and(dunningRecipientsOf(DunningStep::FirstChargeOverdue))->toBe(dunningExpectedRecipients());

    $notice = Notification::sent($this->admin, SubscriptionDunningNotification::class)->first();
    expect($notice->toMail($this->admin)->actionUrl)->toBe(route('panel.my-subscription.index', ['invoice' => $notice->context['invoice_id']]));

    expect(array_sum(dunningRunAt('2026-10-08 09:00:00')))->toBe(0);
    Http::assertNothingSent();

    // D+7 do vencimento: encerra a pendente e cancela a recorrência no gateway.
    expect(dunningRunAt('2026-10-11 09:00:00')[DunningStep::FirstChargeTerminated->value])->toBe(1)
        ->and($pending->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($pending->invoices()->sole()->status)->toBe(InvoiceStatus::Cancelled);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v3/subscriptions/sub_first_001'));
});

it('renovação local (sem recorrência no gateway) encerra sem chamar o gateway', function () {
    $subscription = dunningPaying([
        'gateway'                 => 'mercadopago',
        'gateway_subscription_id' => null,
        'status'                  => SubscriptionStatus::PastDue,
        'past_due_at'             => '2026-10-08 23:59:59',
    ]);

    dunningRunAt('2026-10-15 09:00:00');

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Expired);
    Http::assertNothingSent();
});

it('trial, cortesia e outras empresas ficam fora da régua', function () {
    Subscription::factory()->trial(2)->for(dunningClinic('Clínica Trial'))->for($this->plan)->create();
    Subscription::factory()->complimentary()->for(dunningClinic('Clínica Cortesia'))->for($this->plan)->create(['ends_at' => '2026-10-03 23:59:59']);

    expect(array_sum(dunningRunAt('2026-10-02 09:00:00')))->toBe(0)
        ->and(array_sum(dunningRunAt('2026-10-12 09:00:00')))->toBe(0);

    Notification::assertNothingSent();
});

it('o e-mail sai no idioma do destinatário e sem dado de paciente', function () {
    $this->admin->update(['locale' => 'en']);
    dunningPaying();

    dunningRunAt('2026-10-03 09:00:00');

    $toAdmin     = Notification::sent($this->admin, SubscriptionDunningNotification::class)->first();
    $toFinancial = Notification::sent($this->financial, SubscriptionDunningNotification::class)->first();

    expect($toAdmin->locale)->toBe('en')
        ->and($toFinancial->locale)->toBe('pt_BR');

    app()->setLocale('en');
    $mail = $toAdmin->toMail($this->admin);
    app()->setLocale('pt_BR');

    expect($mail->subject)->toBe(__('billing_dunning.reminder.subject', ['app' => config('app.name'), 'date' => '10/08/2026'], 'en'))
        ->and(dunningText($mail->introLines))->toContain('R$')
        // Só dados da assinatura (due_date: vencimento real da cobrança em atraso;
        // invoice_id: link do WhatsApp para pagar dentro do sistema).
        ->and(array_keys($toAdmin->context))->toEqualCanonicalizing(['entity', 'due_date', 'amount', 'payment_url', 'invoice_id', 'limited_on', 'blocked_on']);
});

it('o comando roda pelo agendador em horário comercial', function () {
    dunningPaying();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));

    $this->artisan('billing:dunning')->assertSuccessful();

    expect(SubscriptionDunningStep::count())->toBe(1);

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'billing:dunning'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *');
});

it('envio de verdade (e-mail): reconfere a situação e entrega a mensagem', function () {
    $subscription = dunningPaying();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));

    $notification = (new SubscriptionDunningNotification($subscription, DunningStep::Reminder, '2026-10-08', [
        'entity'      => $this->clinic->name,
        'amount'      => 299.90,
        'payment_url' => 'https://www.asaas.com/i/pay_regua_001',
        'limited_on'  => '2026-10-11',
        'blocked_on'  => '2026-10-15',
    ]))->locale('pt_BR');

    $transport = app('mailer')->getSymfonyTransport();
    $before    = count($transport->messages());

    (new ChannelManager(app()))->sendNow($this->admin, $notification);

    expect(count($transport->messages()))->toBe($before + 1);

    // Pagou antes do envio: a mesma notificação não sai.
    $subscription->update(['ends_at' => '2026-11-08 23:59:59', 'next_billing_at' => '2026-11-08 23:59:59', 'last_payment_at' => now()]);
    (new ChannelManager(app()))->sendNow($this->admin, $notification);

    expect(count($transport->messages()))->toBe($before + 1);
});

it('manager vê "em atraso há N dias", a etapa da régua e os avisos enviados', function () {
    $saas    = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $manager = User::factory()->create();
    createEntityUser($saas, $manager, SaasRule::Admin->value);
    $asManager = fn () => $this->actingAs($manager)->withSession([
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => SaasRule::Admin->value,
    ]);

    $subscription = dunningPaying(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-10-08 23:59:59']);
    dunningRunAt('2026-10-09 09:00:00');
    dunningRunAt('2026-10-12 09:00:00');

    $asManager()->get(route('manager.subscriptions.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('subscriptions.data.0.id', $subscription->id)
            ->where('subscriptions.data.0.access_level', 'limited')
            ->where('subscriptions.data.0.days_overdue', 4)
            ->where('subscriptions.data.0.dunning_stage', 'limited')
            ->where('subscriptions.data.0.dunning_stage_label', __('manager_subscriptions.dunning_step.limited'))
            ->where('t.overdue_for', __('manager_subscriptions.overdue_for')));

    $steps = $asManager()->getJson(route('manager.subscriptions.show', $subscription))->assertOk()->json('data.dunning_steps');

    expect(collect($steps)->pluck('step')->all())->toBe(['limited', 'overdue'])
        ->and($steps[0]['due_on'])->toBe('2026-10-08')
        ->and($steps[0]['recipients_count'])->toBe(3)
        ->and($steps[0]['label'])->toBe(__('manager_subscriptions.dunning_step.limited'));
});

/** Cliente pagante em atraso desde 08/10 cuja cobrança do novo ciclo não chegou (webhook perdido). */
function dunningOverdueWithOnlyPaidInvoice(): Subscription
{
    $subscription = Subscription::factory()->gateway()->for(test()->clinic)->for(test()->plan)->create([
        'gateway_subscription_id' => 'sub_regua_002',
        'billing_cycle'           => BillingCycle::Monthly,
        'amount'                  => 299.90,
        'status'                  => SubscriptionStatus::PastDue,
        'last_payment_at'         => '2026-09-08 10:00:00',
        'past_due_at'             => '2026-10-08 23:59:59',
        'ends_at'                 => '2026-10-08 23:59:59',
        'next_billing_at'         => '2026-10-08 23:59:59',
    ]);

    // A fatura do ciclo anterior, já paga, com o link da cobrança quitada.
    $paid = Invoice::query()->create([
        'entity_id'       => $subscription->entity_id,
        'subscription_id' => $subscription->id,
        'plan_id'         => $subscription->plan_id,
        'gateway_code'    => 'asaas',
        'reference'       => 'INV-20260908-PAGA0001',
        'period_start'    => '2026-09-08',
        'period_end'      => '2026-10-08',
        'due_at'          => '2026-09-08 23:59:59',
        'paid_at'         => '2026-09-08 10:00:00',
        'amount'          => 199.90,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Paid->value,
        'payment_url'     => 'https://www.asaas.com/i/ja_paga',
    ]);
    $subscription->update(['current_invoice_id' => $paid->id]);

    return $subscription;
}

it('sem fatura em aberto do vencimento, o e-mail não leva o link da fatura já paga nem outro vencimento', function () {
    $subscription = dunningOverdueWithOnlyPaidInvoice();

    // Cobrança em aberto de OUTRO vencimento (o seguinte): não é a do aviso.
    Invoice::query()->create([
        'entity_id'       => $subscription->entity_id,
        'subscription_id' => $subscription->id,
        'plan_id'         => $subscription->plan_id,
        'gateway_code'    => 'asaas',
        'reference'       => 'INV-20261108-FUTURA01',
        'period_start'    => '2026-11-08',
        'period_end'      => '2026-12-08',
        'due_at'          => '2026-11-08 23:59:59',
        'amount'          => 299.90,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Pending->value,
        'payment_url'     => 'https://www.asaas.com/i/outro_vencimento',
    ]);

    dunningRunAt('2026-10-09 09:00:00');

    $notice = Notification::sent($this->admin, SubscriptionDunningNotification::class, fn ($n) => $n->step === DunningStep::Overdue)->first();
    $mail   = $notice->toMail($this->admin);

    expect($notice->context['payment_url'])->toBeNull()
        // Valor contratado, não o da fatura paga do ciclo anterior.
        ->and($notice->context['amount'])->toBe(299.90)
        ->and(SubscriptionDunningStep::where('subscription_id', $subscription->id)->sole()->invoice_id)->toBeNull()
        ->and($mail->actionUrl)->not->toContain('asaas.com')
        ->and($mail->actionText)->not->toBe(__('billing_dunning.pay_now'));
});

it('sem link de pagamento: sem botão "Pagar agora" — orienta onde pagar e aponta para o contato', function () {
    dunningOverdueWithOnlyPaidInvoice();

    $check = function (DunningStep $step) {
        $notice = Notification::sent($this->admin, SubscriptionDunningNotification::class, fn ($n) => $n->step === $step)->first();
        $mail   = $notice->toMail($this->admin);
        $text   = dunningText([...$mail->introLines, ...$mail->outroLines]);

        expect($notice->context['payment_url'])->toBeNull()
            ->and($mail->actionText)->toBe(__('billing_dunning.contact'))
            ->and($mail->actionUrl)->toBe(route('site.home') . '#contato')
            ->and($text)->toContain(__('billing_dunning.no_link'))
            ->and($text)->not->toContain(__('billing_dunning.overdue.retry'));
    };

    dunningRunAt('2026-10-09 09:00:00');
    $check(DunningStep::Overdue);

    dunningRunAt('2026-10-11 09:00:00');
    $check(DunningStep::Limited);
});

it('contratação nunca paga sem link: o aviso da 1ª cobrança vencida não mostra "Pagar agora"', function () {
    Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'status'                  => SubscriptionStatus::PastDue,
        'billing_state'           => 'pending_activation',
        'gateway_subscription_id' => 'sub_first_002',
        'amount'                  => 299.90,
        'last_payment_at'         => null,
        'next_billing_at'         => '2026-10-04 23:59:59',
        'ends_at'                 => '2026-10-04 23:59:59',
    ]);

    dunningRunAt('2026-10-05 09:00:00');

    $mail = Notification::sent($this->admin, SubscriptionDunningNotification::class)->first()->toMail($this->admin);

    expect($mail->actionText)->toBe(__('billing_dunning.contact'))
        ->and(dunningText($mail->introLines))->toContain(__('billing_dunning.no_link'));
});

it('o aviso de atraso traz a data em que o acesso fica limitado — a mesma do nível de acesso real', function () {
    $subscription = dunningPaying(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-10-08 23:59:59']);

    dunningRunAt('2026-10-09 09:00:00');

    $notice = Notification::sent($this->admin, SubscriptionDunningNotification::class)->first();
    $text   = dunningText($notice->toMail($this->admin)->introLines);

    expect($text)->toContain('Em 11/10/2026, IA e módulo financeiro serão bloqueados; em 15/10/2026, o acesso ao painel será suspenso.')
        ->and($text)->not->toContain('segue normal até');

    // A data do texto bate com a régua: 10/10 ainda total, 11/10 limitado, 15/10 sem acesso.
    $this->travelTo(CarbonImmutable::parse('2026-10-10 23:00:00'));
    expect($subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Full);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 00:01:00'));
    expect($subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Limited);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 00:01:00'));
    expect($subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::None);

    app()->setLocale('en');
    $english = dunningText($notice->toMail($this->admin)->introLines);
    app()->setLocale('pt_BR');

    expect($english)->toContain('On 10/11/2026, AI and the financial module will be blocked; on 10/15/2026, access to the panel will be suspended.');
});

describe('encerramento no D+7: o e-mail diz exatamente o que aconteceu com as cobranças', function () {
    $terminatedText = function (): string {
        $notice = Notification::sent(test()->admin, SubscriptionDunningNotification::class, fn ($n) => $n->step->terminates())->first();

        return dunningText([...$notice->toMail(test()->admin)->introLines, ...$notice->toMail(test()->admin)->outroLines]);
    };

    it('recorrência cancelada no gateway', function () use ($terminatedText) {
        dunningPaying(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-10-08 23:59:59']);

        dunningRunAt('2026-10-15 09:00:00');

        expect($terminatedText())->toContain(__('billing_dunning.charges.recurrence_cancelled'))
            ->not->toContain(__('billing_dunning.charges.open_charge'));
    });

    it('recorrência cujo cancelamento falhou agora: diz que está em andamento, sem afirmar o cancelamento', function () use ($terminatedText) {
        Queue::fake();
        // O cancelamento no Asaas falha (troca o fake do beforeEach, que
        // responde 200); a conferência do pagamento (GET) funciona — sem ela
        // a régua nem encerraria (adia a etapa).
        Http::swap(new Factory());
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['object' => 'list', 'id' => 'sub_x', 'status' => 'ACTIVE', 'deleted' => false, 'hasMore' => false, 'data' => []])
            : Http::response(['errors' => [['code' => 'unavailable']]], 503));
        dunningPaying(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-10-08 23:59:59']);

        dunningRunAt('2026-10-15 09:00:00');

        expect($terminatedText())->toContain(__('billing_dunning.charges.recurrence_cancelling'))
            ->not->toContain(__('billing_dunning.charges.recurrence_cancelled'));
        Queue::assertPushed(CancelGatewaySubscriptionJob::class);
    });

    it('renovação local com boleto/Pix emitido sem cancelamento pela API (InfinitePay): avisa que não pode ser cancelado, orienta a não pagar e a contratar de novo', function () use ($terminatedText) {
        $subscription = dunningPaying([
            'gateway'                 => 'infinitepay',
            'gateway_subscription_id' => null,
            'status'                  => SubscriptionStatus::PastDue,
            'past_due_at'             => '2026-10-08 23:59:59',
        ]);
        $subscription->invoices()->update(['gateway_code' => 'infinitepay', 'external_invoice_id' => 'ip_order_123']);

        dunningRunAt('2026-10-15 09:00:00');

        $text = $terminatedText();

        expect($text)->toContain(__('billing_dunning.charges.open_charge'))
            ->toContain(__('billing_dunning.terminated.next', ['app' => config('app.name')]))
            ->not->toContain(__('billing_dunning.charges.open_charge_cancelled'))
            ->not->toContain(__('billing_dunning.charges.recurrence_cancelled'))
            ->not->toContain(__('billing_dunning.charges.none'))
            ->and(BillingLog::query()->where('level', 'critical')->where('subscription_id', $subscription->id)->sole()->context['external_charge_ids'])
            ->toBe(['ip_order_123']);
        Http::assertNothingSent();
    });

    it('renovação local com Pix emitido no Mercado Pago: cancela a cobrança no gateway e o e-mail diz que foi cancelada', function () use ($terminatedText) {
        Http::fake(['https://api.mercadopago.com/v1/payments/1316372291' => Http::response(['id' => 1316372291, 'status' => 'cancelled', 'status_detail' => 'by_collector'])]);
        config(['billing.gateways.mercadopago.secret' => 'APP_USR-teste']);

        $subscription = dunningPaying([
            'gateway'                 => 'mercadopago',
            'gateway_subscription_id' => null,
            'status'                  => SubscriptionStatus::PastDue,
            'past_due_at'             => '2026-10-08 23:59:59',
        ]);
        $subscription->invoices()->update(['gateway_code' => 'mercadopago', 'external_invoice_id' => '1316372291']);
        $invoice = $subscription->invoices()->sole();
        $payment = Payment::query()->create([
            'entity_id'           => $subscription->entity_id,
            'invoice_id'          => $invoice->id,
            'subscription_id'     => $subscription->id,
            'gateway_code'        => 'mercadopago',
            'external_payment_id' => '1316372291',
            'status'              => PaymentStatus::Pending->value,
            'amount'              => 299.90,
            'currency'            => 'BRL',
        ]);

        dunningRunAt('2026-10-15 09:00:00');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'https://api.mercadopago.com/v1/payments/1316372291'
            && $r['status'] === 'cancelled');

        expect($terminatedText())->toContain(__('billing_dunning.charges.open_charge_cancelled'))
            ->not->toContain(__('billing_dunning.charges.open_charge'))
            ->and(BillingLog::query()->where('level', 'critical')->where('subscription_id', $subscription->id)->exists())->toBeFalse()
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Cancelled)
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and(SubscriptionDunningStep::query()->where('subscription_id', $subscription->id)->where('step', DunningStep::Terminated->value)->sole()->metadata['charges'])
            ->toBe(DunningService::CHARGES_OPEN_CHARGE_CANCELLED);
    });

    it('renovação local sem cobrança emitida: nenhuma nova cobrança', function () use ($terminatedText) {
        $subscription = dunningPaying([
            'gateway'                 => 'mercadopago',
            'gateway_subscription_id' => null,
            'status'                  => SubscriptionStatus::PastDue,
            'past_due_at'             => '2026-10-08 23:59:59',
        ]);
        $subscription->invoices()->update(['gateway_code' => 'mercadopago', 'external_invoice_id' => null, 'payment_url' => null]);

        dunningRunAt('2026-10-15 09:00:00');

        expect($terminatedText())->toContain(__('billing_dunning.charges.none'))
            ->not->toContain(__('billing_dunning.charges.open_charge'));
    });

    it('os textos existem nas duas línguas e nenhum afirma o cancelamento de cobrança avulsa', function () {
        foreach (['recurrence_cancelled', 'recurrence_cancelling', 'open_charge', 'open_charge_cancelled', 'none'] as $case) {
            expect(__("billing_dunning.charges.{$case}", [], 'pt_BR'))->not->toBe("billing_dunning.charges.{$case}")
                ->and(__("billing_dunning.charges.{$case}", [], 'en'))->not->toBe("billing_dunning.charges.{$case}");
        }

        expect(__('billing_dunning.terminated.line', [], 'pt_BR'))->not->toContain('foram canceladas')
            ->and(__('billing_dunning.terminated.line', [], 'en'))->not->toContain('have been cancelled');
    });
});

it('envio de verdade no idioma do destinatário: assunto, datas e valor em inglês', function () {
    $this->admin->update(['locale' => 'en']);
    dunningPaying();
    dunningRunAt('2026-10-03 09:00:00');

    // A notificação que a régua montou (com o locale do destinatário), enviada de verdade.
    $notification = Notification::sent($this->admin, SubscriptionDunningNotification::class)->first();
    $transport    = app('mailer')->getSymfonyTransport();
    $before       = count($transport->messages());

    (new ChannelManager(app()))->sendNow($this->admin, $notification);

    $messages = $transport->messages();
    expect(count($messages))->toBe($before + 1);

    $email = $messages[count($messages) - 1]->getOriginalMessage();
    $body  = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags((string) $email->getHtmlBody())));

    expect($email->getSubject())->toBe('Your ' . config('app.name') . ' subscription is due on 10/08/2026')
        ->and($body)->toContain('The next subscription charge for')
        ->and($body)->toContain('R$299.90')
        ->and($body)->toContain('Pay now')
        ->and(app()->getLocale())->toBe('pt_BR');
});

it('encerramento por inadimplência no D+7 entra como cancelamento (non_payment) no financeiro do SaaS', function () {
    $subscription = dunningPaying(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-10-08 23:59:59']);

    dunningRunAt('2026-10-15 09:00:00');

    $cancellation = Cancellation::query()->where('subscription_id', $subscription->id)->sole();
    $summary      = app(PlatformFinanceService::class)->summary(Carbon::parse('2026-10-01 00:00:00'), Carbon::parse('2026-10-31 23:59:59'));

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($cancellation->reason)->toBe(CancellationReason::NonPayment)
        ->and($cancellation->source)->toBe('dunning')
        ->and($cancellation->gateway_code)->toBe('asaas')
        ->and($summary['cancellations']['count'])->toBe(1)
        ->and($summary['cancellations']['by_reason']['non_payment'])->toBe(1);
});

it('--dry-run mostra o que a régua faria sem gravar, enviar nem cancelar no gateway', function () {
    $subscription = dunningPaying(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-10-08 23:59:59']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00'));

    expect(Artisan::call('billing:dunning', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())
        ->toContain($subscription->id)
        ->toContain(__('billing_console.dunning.action_terminate_and_cancel', ['gateway' => 'asaas']))
        ->toContain(__('billing_console.dunning.dry_run_done', ['count' => 1]));

    expect(SubscriptionDunningStep::count())->toBe(0)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('BILLING_DUNNING_ENABLED=false desliga a régua: nem aviso, nem encerramento, nem cancelamento no gateway', function () {
    config(['billing.dunning.enabled' => false]);
    $subscription = dunningPaying(['status' => SubscriptionStatus::PastDue, 'past_due_at' => '2026-10-08 23:59:59']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00'));

    $this->artisan('billing:dunning')
        ->expectsOutputToContain(__('billing_console.dunning.disabled'))
        ->assertSuccessful();

    expect(array_sum(app(DunningService::class)->run()))->toBe(0)
        ->and(SubscriptionDunningStep::count())->toBe(0)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    Notification::assertNothingSent();
    Http::assertNothingSent();

    // A simulação continua disponível com a régua desligada.
    $this->artisan('billing:dunning', ['--dry-run' => true])
        ->expectsOutputToContain($subscription->id)
        ->assertSuccessful();
});

it('assinatura do código anterior aguardando conciliação fica fora da régua: sem aviso, sem encerramento, sem cancelar no gateway', function () {
    // Pagante do Asaas no formato antigo: ends_at parado no fim do 1º ciclo.
    $legacy = dunningPaying([
        'ends_at'                      => '2026-07-10 23:59:59',
        'next_billing_at'              => null,
        'needs_billing_reconciliation' => true,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-15 00:10:00'));
    app(SubscriptionService::class)->markLapsedAsPastDue();

    expect(array_sum(dunningRunAt('2026-10-15 09:00:00')))->toBe(0)
        ->and(app(DunningService::class)->preview())->toBeEmpty()
        ->and($legacy->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($legacy->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::Full);

    Notification::assertNothingSent();
    Http::assertNothingSent();
});
