<?php

namespace App\Jobs\Billing;

use App\Services\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

/**
 * Fim de período sem renovação (diário): cortesia e liberações antigas
 * expiram; cobrança automática sem o pagamento da renovação entra em atraso
 * desde o fim do período (a régua de cobrança cuida do resto).
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
        $expired = $subscriptionService->expireOverdue();
        $pastDue = $subscriptionService->markLapsedAsPastDue();

        Log::info('[ExpireOverdueSubscriptionsJob] Assinaturas expiradas e em atraso.', [
            'count'    => $expired,
            'past_due' => $pastDue,
        ]);
    }
}
