<?php

declare(strict_types=1);

use App\Enums\Billing\{DunningStep, InvoiceStatus};
use App\Enums\{BillingCycle, ClientRule, SubscriptionAccessLevel};
use App\Jobs\WhatsApp\SendSaasWhatsAppNoticeJob;
use App\Models\Billing\{Invoice, SubscriptionDunningStep};
use App\Models\{Entity, Plan, Subscription, User};
use App\Models\WhatsApp\WhatsAppSetting;
use App\Notifications\Channels\SaasWhatsAppChannel;
use App\Notifications\SubscriptionDunningNotification;
use App\Services\Billing\{ClinicServiceGate, DunningService};
use App\Services\WhatsApp\ZApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Event, Http, Queue};

/**
 * Rodada 5 — A5b: os passos da régua (D-5, D+1, D+3, D+7…) também saem
 * pelo WhatsApp, pela instância GLOBAL do SaaS (nunca a da clínica), para o
 * contato de cobrança com telefone verificado, com o link para pagar DENTRO
 * do sistema. Sem telefone verificado: só e-mail. Falha no WhatsApp não
 * impede o e-mail nem a régua; clínica bloqueada (ClinicServiceGate) continua
 * recebendo estes avisos. Fora do horário comercial, espera a janela.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-01 09:00:00'));
    Http::preventStrayRequests();
    config([
        'whatsapp.driver'                     => 'zapi',
        'whatsapp.zapi.base_url'              => 'https://api.z-api.io',
        'billing.enforce_subscription_access' => true,
    ]);

    WhatsAppSetting::create([
        'entity_id'     => null,
        'active'        => true,
        'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'credentials'   => ['instance_id' => 'GLOBAL-SAAS', 'instance_token' => 'TOKEN-SAAS', 'client_token' => 'CLIENT-SAAS'],
    ]);

    $this->plan                  = Plan::factory()->create(['active' => true, 'name' => 'Pro']);
    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Régua Zap']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    // A clínica tem número próprio — os avisos do SaaS NUNCA usam esta instância.
    WhatsAppSetting::create([
        'entity_id'     => $this->clinic->id,
        'active'        => true,
        'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'credentials'   => ['instance_id' => 'CLINIC-OWN', 'instance_token' => 'TOKEN-CLINIC', 'client_token' => 'CLIENT-CLINIC'],
    ]);

    $this->admin = User::factory()->create(['phone' => '(11) 98888-7777', 'phone_verified_at' => now()]);
    createEntityUser($this->clinic, $this->admin, ClientRule::Admin->value);
    // Financeiro sem WhatsApp verificado: só e-mail.
    $this->financial = User::factory()->create(['phone' => '11977776666']);
    createEntityUser($this->clinic, $this->financial, ClientRule::Financial->value);
    // Secretária com WhatsApp verificado não é contato de cobrança.
    $this->secretary = User::factory()->create(['phone' => '11966665555', 'phone_verified_at' => now()]);
    createEntityUser($this->clinic, $this->secretary, ClientRule::Secretary->value);

    $this->subscription = Subscription::factory()->gateway()->for($this->clinic)->for($this->plan)->create([
        'gateway_subscription_id' => 'sub_zap_001',
        'billing_cycle'           => BillingCycle::Monthly,
        'amount'                  => 299.90,
        'last_payment_at'         => '2026-09-08 10:00:00',
        'ends_at'                 => '2026-10-08 23:59:59',
        'next_billing_at'         => '2026-10-08 23:59:59',
    ]);

    $this->invoice = Invoice::query()->create([
        'entity_id'       => $this->clinic->id,
        'subscription_id' => $this->subscription->id,
        'plan_id'         => $this->plan->id,
        'gateway_code'    => 'asaas',
        'reference'       => 'INV-20261008-ZAP001',
        'period_start'    => '2026-10-08',
        'period_end'      => '2026-11-08',
        'due_at'          => '2026-10-08 23:59:59',
        'amount'          => 299.90,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Pending->value,
        'payment_url'     => 'https://www.asaas.com/i/pay_zap_001',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function r5ZapRunAt(string $at): array
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(DunningService::class)->run();
}

/** @return list<Request> mensagens enviadas à Z-API */
function r5ZapMessages(): array
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.z-api.io'))->map(fn ($pair) => $pair[0])->values()->all();
}

it('D+1: WhatsApp pela instância global ao contato com telefone verificado, com o link para pagar a fatura no sistema', function () {
    Http::fake(['https://api.z-api.io/*' => Http::response(['messageId' => 'm-1'])]);

    r5ZapRunAt('2026-10-09 09:00:00');

    $messages = r5ZapMessages();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->url())->toContain('/instances/GLOBAL-SAAS/token/TOKEN-SAAS/send-text')
        ->and($messages[0]->url())->not->toContain('CLINIC-OWN')
        ->and($messages[0]['phone'])->toBe('5511988887777')
        ->and($messages[0]['message'])->toContain(route('panel.my-subscription.index', ['invoice' => $this->invoice->id]))
        ->and($messages[0]['message'])->toContain('R$')
        ->and($messages[0]['message'])->toContain('08/10/2026')
        // Nunca o link do gateway.
        ->and($messages[0]['message'])->not->toContain('asaas.com');

    $step = SubscriptionDunningStep::query()->where('step', DunningStep::Overdue->value)->sole();
    expect($step->recipients_count)->toBe(2)
        ->and($step->metadata['whatsapp_recipients'])->toBe(1);
});

it('lembrete D-5 e acesso limitado D+3 também saem pelo WhatsApp (texto curto da etapa)', function () {
    Http::fake(['https://api.z-api.io/*' => Http::response(['messageId' => 'm-1'])]);

    r5ZapRunAt('2026-10-03 09:00:00');
    r5ZapRunAt('2026-10-11 09:00:00');

    $texts = array_map(fn (Request $r) => (string) $r['message'], r5ZapMessages());

    expect($texts)->toHaveCount(2)
        ->and($texts[0])->toContain('vence em 08/10/2026')
        ->and($texts[1])->toContain('IA e financeiro estão bloqueados');
});

it('falha na Z-API não impede o e-mail nem a régua (sem exceção, etapa registrada)', function () {
    Http::fake(['https://api.z-api.io/*' => Http::response(['error' => 'offline'], 500)]);
    $mails = 0;
    Event::listen(MessageSent::class, function () use (&$mails) {
        $mails++;
    });

    $stats = r5ZapRunAt('2026-10-09 09:00:00');

    expect($stats[DunningStep::Overdue->value])->toBe(1)
        ->and(SubscriptionDunningStep::query()->where('step', DunningStep::Overdue->value)->value('recipients_count'))->toBe(2)
        ->and($mails)->toBe(2);
});

it('clínica bloqueada (D+7): o aviso do SaaS sai pelo WhatsApp mesmo com o ClinicServiceGate barrando as automações dela', function () {
    Http::fake([
        'https://api.z-api.io/*'                             => Http::response(['messageId' => 'm-1']),
        'https://api.asaas.com/v3/subscriptions/sub_zap_001' => Http::response(['deleted' => true, 'id' => 'sub_zap_001']),
    ]);

    r5ZapRunAt('2026-10-15 09:00:00');

    $gate = app(ClinicServiceGate::class);
    $gate->forget();

    expect($this->subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::None)
        ->and($gate->allowsAutomation($this->clinic))->toBeFalse();

    $messages = r5ZapMessages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->url())->toContain('GLOBAL-SAAS')
        ->and((string) $messages[0]['message'])->toContain(route('panel.my-subscription.index'));
});

it('fora do horário comercial o WhatsApp espera o início da janela (08h)', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 21:30:00'));

    $notification = new SubscriptionDunningNotification($this->subscription, DunningStep::Overdue, '2026-10-08', ['entity' => 'Clínica', 'amount' => 299.9, 'invoice_id' => $this->invoice->id]);

    (new SaasWhatsAppChannel())->send($this->admin, $notification);
    (new SaasWhatsAppChannel())->send($this->financial, $notification); // sem WhatsApp verificado

    Queue::assertPushed(SendSaasWhatsAppNoticeJob::class, 1);
    Queue::assertPushed(SendSaasWhatsAppNoticeJob::class, fn (SendSaasWhatsAppNoticeJob $job) => $job->userId === $this->admin->id
        && CarbonImmutable::instance($job->delay)->equalTo(CarbonImmutable::parse('2026-10-10 08:00:00')));
});

it('a régua só manda WhatsApp para contato de cobrança e respeita BILLING_NOTICES_WHATSAPP_ENABLED', function () {
    Http::fake(['https://api.z-api.io/*' => Http::response(['messageId' => 'm-1'])]);
    config(['billing.notices.whatsapp_enabled' => false]);

    r5ZapRunAt('2026-10-09 09:00:00');

    expect(r5ZapMessages())->toBe([])
        ->and(SubscriptionDunningStep::query()->sole()->metadata['whatsapp_recipients'])->toBe(0);
});

it('job do WhatsApp: falha da Z-API volta para a fila com espera (até 3 tentativas); na última desiste sem exceção', function () {
    Http::fake(['https://api.z-api.io/*' => Http::response(['error' => 'offline'], 500)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00:00'));

    $notification = new SubscriptionDunningNotification($this->subscription, DunningStep::Overdue, '2026-10-08', ['entity' => 'Clínica', 'amount' => 299.9, 'invoice_id' => $this->invoice->id]);
    $notification->shouldSend($this->admin, 'mail'); // etapa vale (D+1)

    $job = (new SendSaasWhatsAppNoticeJob((string) $this->admin->id, $notification, 'pt_BR'))->withFakeQueueInteractions();
    $job->handle(app(ZApiClient::class));
    $job->assertReleased(60);

    expect($job->tries)->toBe(3);
});
