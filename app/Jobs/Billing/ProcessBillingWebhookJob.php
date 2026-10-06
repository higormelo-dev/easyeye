<?php

namespace App\Jobs\Billing;

use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Billing\WebhookEvent;
use App\Services\Billing\ProcessWebhookEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Throwable;

class ProcessBillingWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(
        public readonly string $webhookEventId,
    ) {
        $this->onQueue((string) config('billing.webhooks.queue', 'default'));
    }

    public function handle(ProcessWebhookEventService $service): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if (! $event) {
            return;
        }

        try {
            $service->process($event);
        } catch (GatewayIntegrationException $e) {
            // 429 numa consulta ao gateway: reprocessa depois do tempo pedido
            // pela API (RateLimit-Reset/Retry-After), nunca na hora.
            if ($e->isRateLimit()) {
                $this->release(max(1, (int) $e->retryAfter()));

                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        WebhookEvent::query()
            ->where('id', $this->webhookEventId)
            ->update([
                'status'     => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
    }
}
