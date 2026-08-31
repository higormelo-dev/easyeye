<?php

use App\Jobs\Billing\{ExpireOverdueSubscriptionsJob, RenewSubscriptionJob, RetryFailedPaymentJob};
use App\Models\Billing\BillingRetrySchedule;
use App\Models\Subscription;
use App\Services\{ReportSettingService, TrialService};
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\{Artisan, Schedule};

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// F4d — Backfill: sincroniza contents/variables das cópias adoptadas com os
// templates globais. Idempotente. Roda em deploy quando há novos seeds.
Artisan::command('reports:sync-adopted', function () {
    $stats = app(ReportSettingService::class)->syncAdoptedContentsWithGlobal();

    $this->info(sprintf(
        'Sync concluído: %d settings com mudanças | %d contents criados | %d contents atualizados',
        $stats['settings_synced'],
        $stats['contents_created'],
        $stats['contents_updated'],
    ));
})->purpose('Sincroniza contents/variables das cópias adoptadas com os templates globais (F4d backfill)');

// Expira trials vencidos diariamente
Schedule::call(function () {
    app(TrialService::class)->expireOverdueTrials();
})->dailyAt('00:05')->name('trials:expire')->withoutOverlapping();

// Expira assinaturas pagas vencidas (via Job para garantir observers)
Schedule::job(new ExpireOverdueSubscriptionsJob())
    ->dailyAt('00:10')
    ->name('subscriptions:expire')
    ->withoutOverlapping();

// Processa renovações de assinaturas com next_billing_at vencido
Schedule::call(function () {
    Subscription::query()
        ->where('status', 'active')
        ->whereNotNull('next_billing_at')
        ->where('next_billing_at', '<=', now())
        ->select('id')
        ->each(fn ($sub) => RenewSubscriptionJob::dispatch($sub->id));
})->dailyAt('01:00')->name('subscriptions:renew')->withoutOverlapping();

// Processa retries de pagamentos falhos (2× ao dia)
Schedule::call(function () {
    BillingRetrySchedule::due()
        ->select('id')
        ->each(fn ($schedule) => RetryFailedPaymentJob::dispatch($schedule->id));
})->twiceDaily(9, 15)->name('billing:retry')->withoutOverlapping();

// Onda 4, C5 — Notifica médicos sobre runs em WaitingApproval há >24h.
Schedule::command('ai:notify-waiting-approval')
    ->dailyAt('07:00')
    ->name('ai:notify-waiting-approval')
    ->withoutOverlapping();

// Onda 4, C6 — Purga feedbacks antigos para conformidade LGPD (>90 dias).
Schedule::command('ai:purge-feedbacks')
    ->weeklyOn(0, '03:00')
    ->name('ai:purge-feedbacks')
    ->withoutOverlapping();

// Runs de IA presos (worker parado/job perdido) prendem créditos reservados —
// expira e devolve a reserva (compensateFailedRun é idempotente).
Schedule::command('ai:expire-stale-runs')
    ->hourly()
    ->name('ai:expire-stale-runs')
    ->withoutOverlapping();

// WhatsApp (Z-API) — confirmação de consulta: roda de hora em hora dentro do
// horário comercial; a idempotência é do banco (1 confirmação por consulta),
// então repetição nunca duplica mensagem. Horário restrito por respeito ao
// paciente (nada de mensagem de madrugada).
Schedule::command('whatsapp:send-confirmations')
    ->hourly()
    ->between('8:00', '20:00')
    ->name('whatsapp:send-confirmations')
    ->withoutOverlapping();

// WhatsApp (Z-API) — pesquisa de satisfação pós-atendimento (delay por
// clínica; max_age_days evita spam retroativo ao ativar a feature).
Schedule::command('whatsapp:send-surveys')
    ->hourly()
    ->between('9:00', '20:00')
    ->name('whatsapp:send-surveys')
    ->withoutOverlapping();
