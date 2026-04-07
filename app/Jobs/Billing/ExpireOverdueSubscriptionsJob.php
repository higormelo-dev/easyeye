<?php

namespace App\Jobs\Billing;

use App\Services\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

/**
 * Expires all active subscriptions whose ends_at has passed.
 * Called daily by the scheduler.
 */
class ExpireOverdueSubscriptionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->onQueue((string) config('billing.webhooks.queue', 'default'));
    }

    public function handle(SubscriptionService $subscriptionService): void
    {
        $count = $subscriptionService->expireOverdue();

        Log::info('[ExpireOverdueSubscriptionsJob] Assinaturas expiradas.', ['count' => $count]);
    }
}
