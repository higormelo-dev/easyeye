<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\DTOs\Billing\GatewayCallContext;
use App\Models\Billing\Invoice;
use App\Services\Billing\{BillingLogService, GatewayRegistry};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use RuntimeException;
use Throwable;

/**
 * Cancela no gateway uma cobrança que deixou de valer (a fatura foi paga por
 * outra cobrança dela) quando a tentativa na hora falhou — limite da API
 * (429), timeout, 5xx. Sem isso o cliente pode pagar a fatura duas vezes.
 * Tenta de novo com espera crescente (nunca na hora); esgotadas as
 * tentativas, alerta crítico para o time cancelar à mão.
 */
class CancelGatewayChargeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    /** @return list<int> */
    public function backoff(): array
    {
        return [120, 600, 1800, 7200, 21600];
    }

    public function __construct(
        public readonly string $gatewayCode,
        public readonly string $externalChargeId,
        public readonly ?string $invoiceId,
        public readonly string $correlationId,
    ) {
        $this->onQueue((string) config('billing.webhooks.queue', 'default'));
    }

    public function handle(GatewayRegistry $registry): void
    {
        if (! $registry->has($this->gatewayCode)) {
            return;
        }

        $cancelled = $registry->get($this->gatewayCode)
            ->withContext(new GatewayCallContext($this->correlationId, (string) Invoice::query()->whereKey($this->invoiceId)->value('entity_id')))
            ->cancelCharge($this->externalChargeId);

        if (! $cancelled) {
            throw new RuntimeException("[{$this->gatewayCode}] Cobrança {$this->externalChargeId} ainda não cancelada (tentativa {$this->attempts()}).");
        }
    }

    public function failed(Throwable $exception): void
    {
        $invoice = $this->invoiceId ? Invoice::query()->find($this->invoiceId) : null;

        app(BillingLogService::class)->log(
            level: 'critical',
            message: 'Fatura paga por uma das cobranças; outra cobrança dela segue aberta no gateway e não pôde ser cancelada — cancelar manualmente (se paga, estornar).',
            context: ['external_charge_id' => $this->externalChargeId, 'tries' => $this->tries, 'error' => mb_substr($exception->getMessage(), 0, 300)],
            entityId: $invoice?->entity_id,
            invoice: $invoice,
            gatewayCode: $this->gatewayCode,
            correlationId: $this->correlationId,
        );
    }
}
