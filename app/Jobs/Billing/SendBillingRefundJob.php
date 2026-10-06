<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\Billing\BillingRefund;
use App\Services\Billing\{BillingLogService, RefundService};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Throwable;

/**
 * Envia ao gateway o pedido de estorno que ficou na fila porque o gateway
 * pediu para esperar (429 — https://docs.asaas.com/reference/rate-e-quota-limit):
 * o MESMO pedido, com a mesma chave de idempotência. Novo 429: espera o
 * tempo pedido (release), nunca repete na hora. Esgotadas as tentativas, o
 * pedido fica recusado e o time é avisado (o manager pode pedir de novo).
 */
class SendBillingRefundJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    public function __construct(public readonly string $refundId)
    {
        $this->onQueue((string) config('billing.webhooks.queue', 'default'));
    }

    public function handle(RefundService $refunds): void
    {
        $refund = BillingRefund::query()->find($this->refundId);

        if ($refund === null || $refund->status !== BillingRefund::STATUS_REQUESTED || $refund->gateway_state !== BillingRefund::STATE_QUEUED) {
            return;
        }

        try {
            $refunds->send($refund, fromJob: true);
        } catch (GatewayIntegrationException $e) {
            if (! $e->isRateLimit()) {
                throw $e;
            }

            $this->release(max(1, (int) ($e->retryAfter() ?? 60)));
        }
    }

    public function failed(Throwable $exception): void
    {
        $refund = BillingRefund::query()->find($this->refundId);

        if ($refund === null || $refund->status !== BillingRefund::STATUS_REQUESTED || $refund->gateway_state !== BillingRefund::STATE_QUEUED) {
            return;
        }

        $refund->update([
            'status'        => BillingRefund::STATUS_FAILED,
            'check_note'    => 'not_sent',
            'error_message' => __('manager_subscriptions.refund.errors.rate_limited'),
            'completed_at'  => now(),
        ]);

        app(BillingLogService::class)->log(
            level: 'critical',
            message: 'Estorno pedido pelo manager não pôde ser enviado ao gateway (limite da API) depois de várias tentativas — pedir de novo.',
            context: ['refund_id' => $refund->id, 'error' => mb_substr($exception->getMessage(), 0, 500)],
            entityId: (string) $refund->entity_id,
            gatewayCode: $refund->gateway_code,
        );
    }
}
