<?php

use App\Jobs\Billing\{ExpireOverdueSubscriptionsJob, RenewSubscriptionJob, RetryFailedPaymentJob};
use App\Models\Billing\BillingRetrySchedule;
use App\Models\Subscription;
use App\Services\TrialService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\{Artisan, Schedule};

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
