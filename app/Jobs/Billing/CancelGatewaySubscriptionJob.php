<?php

namespace App\Jobs\Billing;

use App\DTOs\Billing\CancelSubscriptionDTO;
use App\Enums\Billing\BillingEventType;
use App\Models\Subscription;
use App\Services\Billing\{BillingLogService, FinancialEventService, GatewayRegistry};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancela a assinatura no gateway de pagamento de forma assíncrona e resiliente.
 *
 * Contexto:
 *   O cancelamento local (DB) já ocorreu em BillingCancellationService.
 *   Este job é responsável exclusivamente pelo cancelamento no gateway.
 *   Se o gateway retornar erro, o job é recolocado na fila com backoff exponencial.
 *   Se todas as tentativas se esgotarem, um alerta crítico é emitido para intervenção manual.
 *
 * Por que um job separado?
 *   - Cancelamento local ≠ cancelamento no gateway — são responsabilidades distintas.
 *   - Falha de rede ou indisponibilidade do gateway não deve bloquear a UX do admin.
 *   - Eventual consistency: o gateway DEVE ser notificado eventualmente para evitar
 *     cobranças indevidas ao cliente após o cancelamento.
 */
class CancelGatewaySubscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Número máximo de tentativas antes de ir para failed().
     * Total de janelas: imediato → 5min → 15min → 1h → 6h → 24h
     */
    public int $tries = 6;

    /**
     * Backoff exponencial em segundos entre tentativas.
     * [5min, 15min, 1h, 6h, 24h]
     *
     * @return array<int>
     */
    public function backoff(): array
    {
        return [300, 900, 3600, 21600, 86400];
    }

    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $gatewayCode,
        public readonly string $correlationId,
    ) {
        $this->onQueue((string) config('billing.webhooks.queue', 'default'));
    }

    public function handle(
        GatewayRegistry $gatewayRegistry,
        FinancialEventService $financialEventService,
        BillingLogService $billingLogService,
    ): void {
        $subscription = Subscription::query()->find($this->subscriptionId);

        // Assinatura removida ou sem ID externo — nada a fazer no gateway
        if (! $subscription || ! $subscription->gateway_subscription_id) {
            return;
        }

        // Gateway não registrado — não há como cancelar, emite alerta e abandona
        if (! $gatewayRegistry->has($this->gatewayCode)) {
            Log::critical('[CancelGatewaySubscriptionJob] Gateway não registrado. Cancelamento no gateway impossível.', [
                'subscription' => $this->subscriptionId,
                'gateway'      => $this->gatewayCode,
                'correlation'  => $this->correlationId,
            ]);
            return;
        }

        $gateway = $gatewayRegistry->get($this->gatewayCode);
        $gateway->withCorrelationId($this->correlationId)
                ->withEntityId((string) $subscription->entity_id);

        $result = $gateway->cancelSubscription(new CancelSubscriptionDTO(
            subscriptionId: (string) $subscription->id,
            externalSubscriptionId: $subscription->gateway_subscription_id,
            externalCustomerId: $subscription->gateway_customer_id,
            entityId: (string) $subscription->entity_id,
            metadata: [],
        ));

        if (! $result->success) {
            $attempt = $this->attempts();

            $billingLogService->log(
                level: 'warning',
                message: "Tentativa {$attempt}/{$this->tries} de cancelamento no gateway falhou.",
                context: [
                    'gateway'  => $this->gatewayCode,
                    'attempt'  => $attempt,
                    'error'    => $result->errorMessage,
                ],
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                gatewayCode: $this->gatewayCode,
                correlationId: $this->correlationId,
            );

            // Relança a exceção para que o Laravel recoloque na fila com backoff
            throw new \RuntimeException(
                "[{$this->gatewayCode}] Cancelamento no gateway falhou (tentativa {$attempt}): {$result->errorMessage}"
            );
        }

        // Sucesso: registra evento financeiro confirmando cancelamento no gateway
        $financialEventService->record(
            eventType: BillingEventType::SubscriptionCancelled,
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            amount: null,
            currency: null,
            correlationId: $this->correlationId,
            source: 'gateway_cancel_job',
            metadata: ['gateway' => $this->gatewayCode, 'confirmed_at_gateway' => true],
        );

        $billingLogService->log(
            level: 'info',
            message: 'Cancelamento confirmado no gateway.',
            context: ['gateway' => $this->gatewayCode, 'attempt' => $this->attempts()],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            gatewayCode: $this->gatewayCode,
            correlationId: $this->correlationId,
        );
    }

    /**
     * Chamado pelo Laravel quando todas as tentativas se esgotaram.
     *
     * Neste ponto, a assinatura está cancelada localmente mas o gateway
     * não foi notificado. Requer intervenção manual para evitar cobranças indevidas.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('[CancelGatewaySubscriptionJob] TODAS AS TENTATIVAS ESGOTADAS. Cancelamento no gateway não confirmado. Requer intervenção manual.', [
            'subscription_id' => $this->subscriptionId,
            'gateway'         => $this->gatewayCode,
            'correlation'     => $this->correlationId,
            'tries'           => $this->tries,
            'error'           => $exception->getMessage(),
            'action_required' => 'Cancelar manualmente no painel do gateway: ' . $this->gatewayCode,
        ]);

        // Marca a assinatura para investigação manual
        Subscription::query()
            ->where('id', $this->subscriptionId)
            ->update(['last_billing_error' => 'GATEWAY_CANCEL_FAILED: cancelamento não confirmado no gateway após ' . $this->tries . ' tentativas.']);
    }
}
