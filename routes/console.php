<?php

use App\Services\SubscriptionService;
use App\Services\TrialService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expira trials e assinaturas vencidas diariamente à meia-noite
Schedule::call(function () {
    app(TrialService::class)->expireOverdueTrials();
    app(SubscriptionService::class)->expireOverdue();
})->dailyAt('00:05')->name('subscriptions:expire')->withoutOverlapping();
