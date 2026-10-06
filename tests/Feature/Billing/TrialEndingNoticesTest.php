<?php

declare(strict_types=1);

use App\Enums\{ClientRule, SubscriptionBillingMode, SubscriptionStatus};
use App\Jobs\WhatsApp\SendSaasWhatsAppNoticeJob;
use App\Models\Billing\SubscriptionTrialNotice;
use App\Models\{Entity, Plan, Subscription, User};
use App\Models\WhatsApp\WhatsAppSetting;
use App\Notifications\TrialEndingNotification;
use App\Services\Billing\TrialEndingNoticeService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Artisan, Http, Notification};

/**
 * Rodada 5 — A5a: fim do teste grátis. E-mail + WhatsApp (instância global
 * do SaaS) aos contatos de cobrança 3 dias antes, 1 dia antes e no dia, com
 * o link para contratar DENTRO do sistema. Uma vez por passo e data de fim;
 * não sai para quem contratou, cortesia ou trial estendido (recalcula pela
 * data atual de fim).
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 09:10:00'));
    Http::preventStrayRequests();

    $this->plan                  = Plan::factory()->create(['active' => true, 'name' => 'Pro']);
    $this->clinic                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => 'Clínica Teste Grátis']);
    $this->clinic->skipAutoTrial = true;
    $this->clinic->save();

    $this->owner = User::factory()->create(['phone' => '21977776666']);
    createEntityUser($this->clinic, $this->owner, ClientRule::Doctor->value, isOwner: true);
    $this->admin = User::factory()->create();
    createEntityUser($this->clinic, $this->admin, ClientRule::Admin->value);
    $this->doctor = User::factory()->create();
    createEntityUser($this->clinic, $this->doctor, ClientRule::Doctor->value);

    // Trial termina em 13/10 às 18h (3 dias).
    $this->trial = Subscription::factory()->for($this->clinic)->for($this->plan)->create([
        'status'        => SubscriptionStatus::Trial,
        'billing_mode'  => null,
        'trial_ends_at' => '2026-10-13 18:00:00',
        'ends_at'       => null,
        'starts_at'     => '2026-09-29 18:00:00',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function r5TrialRunAt(string $at): array
{
    test()->travelTo(CarbonImmutable::parse($at));
    Artisan::call('billing:trial-notices');

    return app(TrialEndingNoticeService::class)->run(dryRun: true);
}

/** @return list<string> passos recebidos pelo usuário */
function r5TrialStepsOf(User $user): array
{
    return Notification::sent($user, TrialEndingNotification::class)->map(fn (TrialEndingNotification $n) => $n->step)->values()->all();
}

it('3 dias antes, 1 dia antes e no dia — uma vez cada, só para os contatos de cobrança, com o link para contratar no sistema', function () {
    Notification::fake();
    r5TrialRunAt('2026-10-10 09:10:00');
    r5TrialRunAt('2026-10-10 15:00:00'); // rodar de novo no mesmo dia não repete
    r5TrialRunAt('2026-10-12 09:10:00');
    r5TrialRunAt('2026-10-13 09:10:00');
    r5TrialRunAt('2026-10-13 12:00:00');

    expect(r5TrialStepsOf($this->owner))->toBe(['three_days', 'one_day', 'today'])
        ->and(r5TrialStepsOf($this->admin))->toBe(['three_days', 'one_day', 'today'])
        ->and(r5TrialStepsOf($this->doctor))->toBe([])
        ->and(SubscriptionTrialNotice::query()->count())->toBe(3)
        ->and(SubscriptionTrialNotice::query()->where('step', 'three_days')->value('recipients_count'))->toBe(2);

    Notification::assertSentTo($this->admin, TrialEndingNotification::class, function (TrialEndingNotification $n, array $channels, User $user) {
        $mail = $n->toMail($user);

        return $mail->actionUrl === route('panel.my-subscription.index')
            && $channels === ['mail'] // sem WhatsApp verificado
            && ($n->step !== 'three_days' || $mail->subject === __('billing_trial.three_days.subject', ['app' => config('app.name'), 'days' => 3]))
            && str_contains(mb_strtoupper(implode(' ', $mail->introLines)), 'CLÍNICA TESTE GRÁTIS')
            && str_contains(implode(' ', $mail->introLines), '13/10/2026');
    });
});

it('não envia se a clínica contratou, se é cortesia ou se o trial já acabou; trial estendido recalcula pela nova data', function () {
    Notification::fake();
    // Estendido para 20/10: no dia 10 faltam 10 dias — nada.
    $this->trial->update(['trial_ends_at' => '2026-10-20 18:00:00']);
    r5TrialRunAt('2026-10-10 09:10:00');
    expect(r5TrialStepsOf($this->admin))->toBe([]);

    // 17/10: 3 dias para a nova data.
    r5TrialRunAt('2026-10-17 09:10:00');
    expect(r5TrialStepsOf($this->admin))->toBe(['three_days'])
        ->and(SubscriptionTrialNotice::query()->sole()->trial_ends_on->toDateString())->toBe('2026-10-20');

    // Contratou (a vigente passa a ser a cobrança automática): nada mais sai.
    $this->travel(1)->minutes();
    Subscription::factory()->gateway('mercadopago')->for($this->clinic)->for($this->plan)->create([
        'gateway_subscription_id' => null,
        'status'                  => SubscriptionStatus::PastDue,
        'next_billing_at'         => now()->addDays(3),
    ]);
    r5TrialRunAt('2026-10-19 09:10:00');
    expect(r5TrialStepsOf($this->admin))->toBe(['three_days']);

    // Outra clínica em cortesia: nada.
    $other                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $other->skipAutoTrial = true;
    $other->save();
    $otherAdmin = User::factory()->create();
    createEntityUser($other, $otherAdmin, ClientRule::Admin->value);
    Subscription::factory()->complimentary()->for($other)->for($this->plan)->create(['ends_at' => now()->addDays(2)]);
    r5TrialRunAt('2026-10-19 10:00:00');
    expect(r5TrialStepsOf($otherAdmin))->toBe([]);
});

it('reconfere na hora do envio: contratou entre o comando e a fila, o aviso não sai', function () {
    $notification = new TrialEndingNotification($this->trial, 'three_days', '2026-10-13', 'Clínica Teste Grátis');

    expect($notification->shouldSend($this->admin, 'mail'))->toBeTrue();

    $this->trial->update(['status' => SubscriptionStatus::Active, 'billing_mode' => SubscriptionBillingMode::Gateway]);

    expect($notification->shouldSend($this->admin, 'mail'))->toBeFalse();
});

it('chave BILLING_TRIAL_NOTICES_ENABLED=false desliga os avisos', function () {
    Notification::fake();
    config(['billing.trial_notices.enabled' => false]);

    r5TrialRunAt('2026-10-10 09:10:00');

    expect(SubscriptionTrialNotice::query()->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('WhatsApp pelo app global (template) para o dono com telefone verificado (falha da Gupshup não impede o e-mail)', function () {
    useGupshupDriver();
    WhatsAppSetting::create([
        'entity_id'     => null,
        'active'        => true,
        'webhook_token' => WhatsAppSetting::generateWebhookToken(),
        'app_id'        => 'GLOBAL-TRIAL',
    ]);
    $this->owner->forceFill(['phone_verified_at' => now()])->save();

    fakeGupshup(['partner.gupshup.io/partner/app/*/v3/message' => Http::sequence()
        ->push(['status' => 'error', 'message' => 'Internal error'], 500)
        ->push(['status' => 'error', 'message' => 'Internal error'], 500)
        ->push(['status' => 'error', 'message' => 'Internal error'], 500)
        ->whenEmpty(Http::response(['messages' => [['id' => 'gs-trial']]]))]);
    r5TrialRunAt('2026-10-10 09:10:00');

    // Falhou o WhatsApp: o passo ficou registrado e o e-mail saiu (fila síncrona dos testes).
    $notice = SubscriptionTrialNotice::query()->sole();
    expect($notice->recipients_count)->toBe(2)
        ->and($notice->whatsapp_count)->toBe(1);

    r5TrialRunAt('2026-10-12 09:10:00');

    $last     = collect(gupshupSentMessages())->last();
    $template = gupshupTemplateOf($last);

    expect($last->url())->toBe('https://partner.gupshup.io/partner/app/GLOBAL-TRIAL/v3/message')
        ->and($last['to'])->toBe('5521977776666')
        ->and($template['name'])->toBe('easyeye_teste_termina_amanha')
        ->and($template['body'])->toContain('13/10/2026')
        ->and($template['buttons'])->toBe([SendSaasWhatsAppNoticeJob::urlSuffix(route('panel.my-subscription.index'))]);
});

it('o comando roda pelo agendador todo dia em horário comercial', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'billing:trial-notices'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('10 9 * * *');
});
