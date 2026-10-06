<?php

declare(strict_types=1);

use App\Enums\Billing\{DunningStep, InvoiceStatus};
use App\Enums\{BillingCycle, ClientRule, SubscriptionAccessLevel};
use App\Jobs\WhatsApp\SendSaasWhatsAppNoticeJob;
use App\Models\Billing\{Invoice, SubscriptionDunningStep};
use App\Models\{Entity, Plan, Subscription, User};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use App\Notifications\Channels\SaasWhatsAppChannel;
use App\Notifications\SubscriptionDunningNotification;
use App\Services\Billing\{ClinicServiceGate, DunningService};
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\{WhatsAppService, WhatsAppTemplates};
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Event, Http, Queue};

/**
 * Rodada 5 — A5b: os passos da régua (D-5, D+1, D+3, D+7…) também saem
 * pelo WhatsApp — template aprovado (Gupshup), pelo app GLOBAL do EasyEye
 * (nunca o da clínica) — para o contato de cobrança com telefone verificado,
 * com o link para pagar DENTRO do sistema no botão do template. Sem telefone verificado: só e-mail. Falha no WhatsApp não
 * impede o e-mail nem a régua; clínica bloqueada (ClinicServiceGate) continua
 * recebendo estes avisos. Fora do horário comercial, espera a janela.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-01 09:00:00'));
    Http::preventStrayRequests();
    // Antes de limitar/encerrar, a régua confere o pagamento no Asaas: a
    // recorrência sem cobrança paga (não pago conclusivo).
    Http::fake(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), 'https://api.asaas.com/v3/subscriptions/')
        ? Http::response(['id' => 'sub_zap_001', 'status' => 'ACTIVE', 'deleted' => false, 'hasMore' => false, 'data' => []])
        : null);
    useGupshupDriver();
    config(['billing.enforce_subscription_access' => true]);

    $this->global = WhatsAppSetting::create([
        'entity_id'     => null,
        'active'        => true,
        'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'app_id'        => 'GLOBAL-SAAS',
    ]);

    $this->plan                  = Plan::factory()->create(['active' => true, 'name' => 'Pro']);
    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Régua Zap']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    // A clínica tem número próprio — os avisos do SaaS NUNCA usam este app.
    WhatsAppSetting::create([
        'entity_id'     => $this->clinic->id,
        'active'        => true,
        'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'app_id'        => 'CLINIC-OWN',
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

function r5ZapOk(): void
{
    fakeGupshup(['partner.gupshup.io/partner/app/*/v3/message' => Http::response(['messages' => [['id' => 'gs-1']]])]);
}

it('D+1: template pelo app global ao contato com telefone verificado, com o link para pagar a fatura no sistema', function () {
    r5ZapOk();

    r5ZapRunAt('2026-10-09 09:00:00');

    $messages = gupshupSentMessages();
    $template = gupshupTemplateOf($messages[0]);
    $payUrl   = route('panel.my-subscription.index', ['invoice' => $this->invoice->id]);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->url())->toBe('https://partner.gupshup.io/partner/app/GLOBAL-SAAS/v3/message')
        ->and($messages[0]['to'])->toBe('5511988887777')
        ->and($template['name'])->toBe('easyeye_cobranca_vencida')
        ->and($template['language'])->toBe('pt_BR')
        // {{1}} valor · {{2}} clínica · {{3}} vencimento · {{4}} limitação
        ->and($template['body'][0])->toContain('R$')
        ->and($template['body'][1])->toBe($this->clinic->name)
        ->and($template['body'][2])->toBe('08/10/2026')
        // Botão "Abrir o EasyEye": sufixo do link da fatura dentro do sistema.
        ->and($template['buttons'])->toBe([SendSaasWhatsAppNoticeJob::urlSuffix($payUrl)])
        ->and($template['buttons'][0])->toContain((string) $this->invoice->id)
        // Nunca o link do gateway.
        ->and(json_encode($messages[0]->data()))->not->toContain('asaas.com');

    $step = SubscriptionDunningStep::query()->where('step', DunningStep::Overdue->value)->sole();
    expect($step->recipients_count)->toBe(2)
        ->and($step->metadata['whatsapp_recipients'])->toBe(1);

    // Trilha/custo em whatsapp_messages, sem clínica (comunicação do SaaS).
    $logged = WhatsAppMessage::query()->where('kind', WhatsAppMessage::KIND_SAAS_NOTICE)->sole();
    expect($logged->entity_id)->toBeNull()
        ->and($logged->status)->toBe('sent')
        ->and($logged->provider_message_id)->toBe('gs-1')
        ->and($logged->template)->toBe('easyeye_cobranca_vencida')
        ->and($logged->body)->toContain($payUrl);
});

it('lembrete D-5 e acesso limitado D+3 também saem pelo WhatsApp (template da etapa)', function () {
    r5ZapOk();

    r5ZapRunAt('2026-10-03 09:00:00');
    r5ZapRunAt('2026-10-11 09:00:00');

    $templates = array_map(fn (Request $r) => gupshupTemplateOf($r), gupshupSentMessages());

    expect($templates)->toHaveCount(2)
        ->and($templates[0]['name'])->toBe('easyeye_cobranca_lembrete')
        ->and($templates[0]['body'][2])->toBe('08/10/2026')
        ->and($templates[1]['name'])->toBe('easyeye_cobranca_acesso_limitado');
});

it('cada aviso do SaaS tem template configurado com as variáveis que o corpo usa', function () {
    $templates = app(WhatsAppTemplates::class);

    foreach (array_keys((array) config('whatsapp.templates')) as $key) {
        $config = $templates->config($key);

        foreach ($config['body'] as $variable) {
            expect(__("whatsapp.templates.{$key}", [], 'pt_BR'))->toContain(":{$variable}")
                ->and(__("whatsapp.templates.{$key}", [], 'en'))->toContain(":{$variable}");
        }
    }
});

it('contato que respondeu SAIR ao número do EasyEye não recebe o aviso (o e-mail segue)', function () {
    r5ZapOk();
    app(WhatsAppService::class)->optOut($this->global, '5511988887777');

    r5ZapRunAt('2026-10-09 09:00:00');

    expect(gupshupSentMessages())->toBe([])
        ->and(WhatsAppOptOut::query()->count())->toBe(1);
});

it('falha na Gupshup não impede o e-mail nem a régua (sem exceção, etapa registrada)', function () {
    fakeGupshup(['partner.gupshup.io/partner/app/*/v3/message' => Http::response(['status' => 'error', 'message' => 'offline'], 500)]);
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
    fakeGupshup([
        'partner.gupshup.io/partner/app/*/v3/message'        => Http::response(['messages' => [['id' => 'gs-1']]]),
        'https://api.asaas.com/v3/subscriptions/sub_zap_001' => Http::response(['deleted' => true, 'id' => 'sub_zap_001']),
    ]);

    r5ZapRunAt('2026-10-15 09:00:00');

    $gate = app(ClinicServiceGate::class);
    $gate->forget();

    expect($this->subscription->fresh()->accessLevel())->toBe(SubscriptionAccessLevel::None)
        ->and($gate->allowsAutomation($this->clinic))->toBeFalse();

    $messages = gupshupSentMessages();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->url())->toContain('/partner/app/GLOBAL-SAAS/')
        ->and(gupshupTemplateOf($messages[0])['buttons'][0])->toBe(SendSaasWhatsAppNoticeJob::urlSuffix(route('panel.my-subscription.index')));
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
    r5ZapOk();
    config(['billing.notices.whatsapp_enabled' => false]);

    r5ZapRunAt('2026-10-09 09:00:00');

    expect(gupshupSentMessages())->toBe([])
        ->and(SubscriptionDunningStep::query()->sole()->metadata['whatsapp_recipients'])->toBe(0);
});

it('job do WhatsApp: falha transitória da Gupshup volta para a fila com espera (até 3 tentativas)', function () {
    fakeGupshup(['partner.gupshup.io/partner/app/*/v3/message' => Http::response(['status' => 'error', 'message' => 'offline'], 500)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00:00'));

    $notification = new SubscriptionDunningNotification($this->subscription, DunningStep::Overdue, '2026-10-08', ['entity' => 'Clínica', 'amount' => 299.9, 'invoice_id' => $this->invoice->id]);
    $notification->shouldSend($this->admin, 'mail'); // etapa vale (D+1)

    $job = (new SendSaasWhatsAppNoticeJob((string) $this->admin->id, $notification, 'pt_BR'))->withFakeQueueInteractions();
    $job->handle(app(WhatsAppProvider::class), app(WhatsAppService::class), app(WhatsAppTemplates::class));
    $job->assertReleased(60);

    // A linha da trilha nasce antes da chamada (sem duplicidade em
    // timeout) e fica pending, esperando a nova tentativa do mesmo job.
    expect($job->tries)->toBe(3)
        ->and(WhatsAppMessage::query()->sole()->status)->toBe('pending')
        ->and(WhatsAppMessage::query()->sole()->error_code)->toBe('http_500');
});

it('job do WhatsApp: falha permanente (template inexistente) desiste na hora, registrada como falha', function () {
    fakeGupshup(['partner.gupshup.io/partner/app/*/v3/message' => Http::response(['error' => ['code' => 132001, 'message' => 'Template name does not exist in the translation']], 400)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00:00'));

    $notification = new SubscriptionDunningNotification($this->subscription, DunningStep::Overdue, '2026-10-08', ['entity' => 'Clínica', 'amount' => 299.9, 'invoice_id' => $this->invoice->id]);

    $job = (new SendSaasWhatsAppNoticeJob((string) $this->admin->id, $notification, 'pt_BR'))->withFakeQueueInteractions();
    $job->handle(app(WhatsAppProvider::class), app(WhatsAppService::class), app(WhatsAppTemplates::class));
    $job->assertNotReleased();

    $logged = WhatsAppMessage::query()->sole();
    expect($logged->status)->toBe('failed')
        ->and($logged->error_code)->toBe('meta_132001');
});

it('aviso em inglês usa o template "en" só quando aprovado (WHATSAPP_TEMPLATE_LANGUAGES)', function () {
    r5ZapOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00:00'));
    $notification = new SubscriptionDunningNotification($this->subscription, DunningStep::Overdue, '2026-10-08', ['entity' => 'Clinic', 'amount' => 299.9, 'invoice_id' => $this->invoice->id]);

    SendSaasWhatsAppNoticeJob::dispatchSync((string) $this->admin->id, $notification, 'en');
    config(['whatsapp.template_languages' => ['pt_BR', 'en']]);
    SendSaasWhatsAppNoticeJob::dispatchSync((string) $this->admin->id, $notification, 'en');

    $languages = array_map(fn (Request $r) => gupshupTemplateOf($r)['language'], gupshupSentMessages());
    expect($languages)->toBe(['pt_BR', 'en']);
});
