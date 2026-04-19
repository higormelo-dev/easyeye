<?php

namespace App\Services\Billing;

use App\DTOs\Billing\{CancelSubscriptionDTO, GatewayCallContext};
use App\Enums\Billing\{BillingEventType, CancellationReason};
use App\Enums\SubscriptionStatus;
use App\Jobs\Billing\CancelGatewaySubscriptionJob;
use App\Models\{Entity, Subscription, SubscriptionSetting};
use App\Models\Billing\{Cancellation, SubscriptionChange};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Serviço de cancelamento de assinaturas com integração ao gateway.
 *
 * Arquitetura em duas fases:
 *
 *   Fase 1 — DB (síncrono, sempre bem-sucedido):
 *     Cancela localmente, cria Cancellation + SubscriptionChange + FinancialEvent.
 *     O cliente perde acesso imediatamente (ou ao fim do grace period).
 *
 *   Fase 2 — Gateway (assíncrono, resiliente):
 *     Tenta cancelar no gateway na hora. Se falhar, agenda o job
 *     CancelGatewaySubscriptionJob com backoff exponencial (5min → 15min → 1h → 6h → 24h).
 *     Se todas as tentativas se esgotarem, emite Log::critical para intervenção manual.
 *
 * Por que não bloquear a Fase 1 na Fase 2?
 *   O cancelamento local é autoritativo — deve ser imediato independentemente
 *   da disponibilidade do gateway. Uma falha de rede no gateway não pode
 *   deixar um cliente "meio cancelado" no sistema.
 */
class BillingCancellationService
{
    public function __construct(
        private readonly GatewayRegistry $gatewayRegistry,
        private readonly FinancialEventService $financialEventService,
        private readonly BillingLogService $billingLogService,
    ) {
    }

    /**
     * Cancela uma assinatura.
     *
     * @param  bool  $cancelAtGateway  Deve cancelar também no gateway de pagamento?
     *                                 Use false em migrações de plano onde o novo plano
     *                                 imediatamente criará uma nova assinatura no gateway.
     */
    public function cancel(
        Subscription $subscription,
        Entity $entity,
        CancellationReason $reason,
        string $source = 'system',
        ?string $notes = null,
        bool $cancelAtGateway = true,
    ): Subscription {
        $correlationId = (string) Str::uuid();
        $graceDays     = SubscriptionSetting::gracePeriodDays();

        // ── Fase 1: DB — cancelamento local (sempre bem-sucedido) ────────────
        DB::transaction(function () use ($subscription, $entity, $reason, $source, $notes, $correlationId, $graceDays) {
            $subscription->update([
                'status'               => SubscriptionStatus::Cancelled,
                'cancelled_at'         => now(),
                'billing_state'        => 'cancelled',
                'grace_period_ends_at' => now()->addDays($graceDays),
                'last_billing_error'   => null,
            ]);

            SubscriptionChange::query()->create([
                'entity_id'       => $entity->id,
                'subscription_id' => $subscription->id,
                'change_type'     => 'cancelled',
                'reason'          => $reason->value,
                'correlation_id'  => $correlationId,
                'changed_by'      => null,
                'effective_at'    => now(),
                'metadata'        => [
                    'from_status' => $subscription->getOriginal('status'),
                    'to_status'   => SubscriptionStatus::Cancelled->value,
                    'source'      => $source,
                    'notes'       => $notes,
                    'grace_days'  => $graceDays,
                ],
            ]);

            Cancellation::query()->create([
                'entity_id'       => $entity->id,
                'subscription_id' => $subscription->id,
                'gateway_code'    => $subscription->gateway,
                'reason'          => $reason->value,
                'source'          => $source,
                'notes'           => $notes,
                'effective_at'    => now(),
                'metadata'        => [
                    'grace_period_ends_at' => now()->addDays($graceDays)->toIso8601String(),
                ],
                'correlation_id'  => $correlationId,
            ]);
        });

        $this->financialEventService->record(
            eventType: BillingEventType::SubscriptionCancelled,
            entityId: (string) $entity->id,
            subscription: $subscription,
            amount: null,
            currency: null,
            correlationId: $correlationId,
            source: $source,
            metadata: [
                'reason'     => $reason->value,
                'grace_days' => $graceDays,
            ],
        );

        // ── Fase 2: Gateway — cancelamento assíncrono e resiliente ────────────
        if ($cancelAtGateway && $subscription->gateway && $subscription->gateway_subscription_id) {
            $this->dispatchGatewayCancellation($subscription, $correlationId);
        }

        $this->billingLogService->log(
            level: 'info',
            message: 'Assinatura cancelada localmente. Cancelamento no gateway em andamento.',
            context: [
                'reason'         => $reason->value,
                'source'         => $source,
                'cancel_gateway' => $cancelAtGateway,
                'gateway'        => $subscription->gateway,
            ],
            entityId: (string) $entity->id,
            subscription: $subscription,
            gatewayCode: $subscription->gateway,
            correlationId: $correlationId,
        );

        return $subscription->fresh();
    }

    /**
     * Expira imediatamente uma assinatura sem período de graça.
     * Usado em casos de fraude ou inadimplência grave após grace esgotado.
     */
    public function expire(
        Subscription $subscription,
        Entity $entity,
        CancellationReason $reason = CancellationReason::NonPayment,
        string $source = 'system',
    ): Subscription {
        $correlationId = (string) Str::uuid();

        DB::transaction(function () use ($subscription, $entity, $reason, $source, $correlationId) {
            $subscription->update([
                'status'        => SubscriptionStatus::Expired,
                'cancelled_at'  => now(),
                'billing_state' => 'expired',
            ]);

            SubscriptionChange::query()->create([
                'entity_id'       => $entity->id,
                'subscription_id' => $subscription->id,
                'change_type'     => 'expired',
                'reason'          => $reason->value,
                'correlation_id'  => $correlationId,
                'changed_by'      => null,
                'effective_at'    => now(),
                'metadata'        => [
                    'from_status' => $subscription->getOriginal('status'),
                    'to_status'   => SubscriptionStatus::Expired->value,
                    'source'      => $source,
                ],
            ]);
        });

        $this->financialEventService->record(
            eventType: BillingEventType::SubscriptionExpired,
            entityId: (string) $entity->id,
            subscription: $subscription,
            amount: null,
            currency: null,
            correlationId: $correlationId,
            source: $source,
        );

        // Expirações também devem cancelar no gateway para evitar cobranças futuras
        if ($subscription->gateway && $subscription->gateway_subscription_id) {
            $this->dispatchGatewayCancellation($subscription, $correlationId);
        }

        return $subscription->fresh();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Estratégia de dois estágios para cancelamento no gateway:
     *
     * 1. Tenta de forma síncrona na hora (caso mais comum funciona aqui mesmo).
     * 2. Se falhar, despacha CancelGatewaySubscriptionJob com backoff exponencial.
     *    O job tentará até 6 vezes ao longo de ~24 horas.
     *    Se esgotar todas as tentativas, emite Log::critical.
     */
    private function dispatchGatewayCancellation(Subscription $subscription, string $correlationId): void
    {
        // Tentativa síncrona imediata (evita overhead de queue para o caso feliz)
        $succeededSynchronously = $this->attemptSynchronousCancel($subscription, $correlationId);

        if ($succeededSynchronously) {
            return;
        }

        // Falhou na tentativa síncrona → delega ao job resiliente com backoff
        CancelGatewaySubscriptionJob::dispatch(
            subscriptionId: (string) $subscription->id,
            gatewayCode:    (string) $subscription->gateway,
            correlationId:  $correlationId,
        );

        Log::warning('[BillingCancellationService] Cancelamento síncrono no gateway falhou. Job agendado com backoff exponencial.', [
            'gateway'      => $subscription->gateway,
            'subscription' => $subscription->id,
            'correlation'  => $correlationId,
        ]);
    }

    /**
     * Tentativa síncrona de cancelamento no gateway.
     * Retorna true se bem-sucedido, false se falhou (sem lançar exceção).
     */
    private function attemptSynchronousCancel(Subscription $subscription, string $correlationId): bool
    {
        try {
            if (! $this->gatewayRegistry->has((string) $subscription->gateway)) {
                return true; // Gateway não integrado — não há nada a cancelar
            }

            $gateway = $this->gatewayRegistry->get((string) $subscription->gateway)
                ->withContext(new GatewayCallContext($correlationId, (string) $subscription->entity_id));

            $result = $gateway->cancelSubscription(new CancelSubscriptionDTO(
                subscriptionId: (string) $subscription->id,
                externalSubscriptionId: $subscription->gateway_subscription_id,
                externalCustomerId: $subscription->gateway_customer_id,
                entityId: (string) $subscription->entity_id,
                metadata: [],
            ));

            return $result->success;
        } catch (Throwable) {
            return false;
        }
    }
}
