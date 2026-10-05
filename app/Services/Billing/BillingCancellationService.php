<?php

namespace App\Services\Billing;

use App\DTOs\Billing\{CancelSubscriptionDTO, GatewayCallContext};
use App\Enums\Billing\{BillingEventType, CancellationReason, InvoiceStatus, PaymentStatus};
use App\Enums\SubscriptionStatus;
use App\Jobs\Billing\CancelGatewaySubscriptionJob;
use App\Models\Billing\{Cancellation, Invoice, Payment, SubscriptionChange};
use App\Models\{Entity, Subscription};
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{DB, Log};
use Throwable;

/**
 * Serviço de cancelamento de assinaturas com integração ao gateway.
 *
 * Arquitetura em duas fases:
 *
 *   Fase 1 — DB (síncrono, sempre bem-sucedido):
 *     Cancela localmente, cria Cancellation + SubscriptionChange + FinancialEvent.
 *     O cliente perde acesso imediatamente (não há período de graça).
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
     * @param bool        $cancelAtGateway Deve cancelar também no gateway de pagamento?
     *                                     Use false em migrações de plano onde o novo plano
     *                                     imediatamente criará uma nova assinatura no gateway.
     * @param string|null $changedBy       quem cancelou (ex.: admin do manager) — vai para o
     *                                     histórico da empresa; null = o próprio sistema
     */
    public function cancel(
        Subscription $subscription,
        Entity $entity,
        CancellationReason $reason,
        string $source = 'system',
        ?string $notes = null,
        bool $cancelAtGateway = true,
        ?string $changedBy = null,
    ): Subscription {
        $correlationId = (string) Str::uuid();

        // ── Fase 1: DB — cancelamento local (sempre bem-sucedido) ────────────
        DB::transaction(function () use ($subscription, $entity, $reason, $source, $notes, $correlationId, $changedBy) {
            $subscription->update([
                'status'             => SubscriptionStatus::Cancelled,
                'cancelled_at'       => now(),
                'billing_state'      => 'cancelled',
                'last_billing_error' => null,
                // Cancelada não aguarda mais conciliação (linha do código anterior).
                'needs_billing_reconciliation' => false,
            ]);

            SubscriptionChange::query()->create([
                'entity_id'       => $entity->id,
                'subscription_id' => $subscription->id,
                'change_type'     => 'cancelled',
                'reason'          => $reason->value,
                'correlation_id'  => $correlationId,
                'changed_by'      => $changedBy,
                'effective_at'    => now(),
                'metadata'        => [
                    'from_status' => $subscription->getOriginal('status'),
                    'to_status'   => SubscriptionStatus::Cancelled->value,
                    'source'      => $source,
                    'notes'       => $notes,
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
                'reason' => $reason->value,
            ],
        );

        // ── Fase 2: Gateway — cancelamento assíncrono e resiliente ────────────
        if ($cancelAtGateway && $subscription->gateway && $subscription->gateway_subscription_id) {
            $this->dispatchGatewayCancellation($subscription, $correlationId);
        }

        // Renovação local: a cobrança avulsa já emitida (boleto/Pix/fatura em
        // aberto) é cancelada no gateway — senão o cliente paga uma assinatura
        // cancelada. Com recorrência, o cancelamento dela remove as pendentes.
        if ($cancelAtGateway && $subscription->gateway && ! $subscription->gateway_subscription_id) {
            $this->cancelOpenCharges($subscription, correlationId: $correlationId);
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
     * Expira imediatamente uma assinatura (fraude ou inadimplência grave).
     *
     * @param bool $cancelAtGateway false quando o chamador está numa transação
     *                              e para a recorrência depois do commit
     *                              (cancelGatewayRecurrence) — HTTP fora da
     *                              transação.
     */
    public function expire(
        Subscription $subscription,
        Entity $entity,
        CancellationReason $reason = CancellationReason::NonPayment,
        string $source = 'system',
        bool $cancelAtGateway = true,
        ?string $correlationId = null,
    ): Subscription {
        $correlationId ??= (string) Str::uuid();

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

            // Encerramento forçado (ex.: inadimplência no D+7 da régua) é
            // cancelamento para o financeiro do SaaS (PlatformFinanceService).
            Cancellation::query()->create([
                'entity_id'       => $entity->id,
                'subscription_id' => $subscription->id,
                'gateway_code'    => $subscription->gateway,
                'reason'          => $reason->value,
                'source'          => $source,
                'effective_at'    => now(),
                'correlation_id'  => $correlationId,
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
        if ($cancelAtGateway && $subscription->gateway && $subscription->gateway_subscription_id) {
            $this->dispatchGatewayCancellation($subscription, $correlationId);
        }

        return $subscription->fresh();
    }

    /**
     * Cancela só a cobrança recorrente no gateway — a assinatura local segue
     * (ex.: virou cortesia ou foi substituída por outra). Sem isso o
     * gateway continua cobrando o cliente.
     */
    public function cancelGatewayRecurrence(Subscription $subscription, ?string $correlationId = null): void
    {
        $this->stopGatewayRecurrence($subscription, $correlationId);
    }

    /**
     * Igual a cancelGatewayRecurrence(), dizendo o que aconteceu: null = não
     * há recorrência no gateway; true = cancelada agora; false = a tentativa
     * falhou e o cancelamento segue no job, com novas tentativas.
     */
    public function stopGatewayRecurrence(Subscription $subscription, ?string $correlationId = null): ?bool
    {
        if (! $subscription->gateway || ! $subscription->gateway_subscription_id) {
            return null;
        }

        return $this->dispatchGatewayCancellation($subscription, $correlationId ?? (string) Str::uuid());
    }

    /**
     * Cancela no gateway as cobranças avulsas em aberto da assinatura
     * (PaymentGatewayInterface::cancelCharge) e marca as canceladas (fatura e
     * pagamento) como canceladas. As que o gateway não cancela (sem API — ex.:
     * InfinitePay, PagBank — ou recusa) voltam em `open` e ficam num alerta
     * crítico para o time cancelar à mão. HTTP: chamar fora de transação.
     *
     * @param Collection<int, Invoice>|null $invoices  faturas a cancelar (padrão:
     *                                                 as pendentes/vencidas da assinatura com cobrança emitida)
     * @param bool                          $alertOpen false quando o chamador registra o próprio alerta
     *
     * @return array{cancelled: list<string>, open: list<string>} ids externos das cobranças
     */
    public function cancelOpenCharges(Subscription $subscription, ?Collection $invoices = null, ?string $correlationId = null, bool $alertOpen = true): array
    {
        $result = ['cancelled' => [], 'open' => []];
        $correlationId ??= (string) Str::uuid();

        $invoices = ($invoices ?? $subscription->invoices()
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value])
            ->get())
            ->filter(fn (Invoice $invoice) => filled($invoice->external_invoice_id)
                && ! in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Refunded], true));

        foreach ($invoices as $invoice) {
            $externalId  = (string) $invoice->external_invoice_id;
            $gatewayCode = (string) ($invoice->gateway_code ?: $subscription->gateway);

            if (! $this->cancelChargeAtGateway($gatewayCode, $externalId, $subscription, $correlationId)) {
                $result['open'][] = $externalId;

                continue;
            }

            $result['cancelled'][] = $externalId;

            DB::transaction(function () use ($invoice, $gatewayCode, $externalId): void {
                Payment::query()
                    ->where('gateway_code', $gatewayCode)
                    ->where('external_payment_id', $externalId)
                    ->whereNotIn('status', [PaymentStatus::Paid->value, PaymentStatus::Refunded->value, PaymentStatus::Chargeback->value])
                    ->update(['status' => PaymentStatus::Cancelled->value]);

                $fresh = $invoice->fresh();

                if ($fresh && ! in_array($fresh->status, [InvoiceStatus::Paid, InvoiceStatus::Refunded], true)) {
                    $fresh->update(['status' => InvoiceStatus::Cancelled->value]);
                }
            });
        }

        $alert = $alertOpen && $result['open'] !== [];

        if ($alert || $result['cancelled'] !== []) {
            $this->billingLogService->log(
                level: $alert ? 'critical' : 'info',
                message: $alert
                    ? 'Cobrança avulsa em aberto não pôde ser cancelada no gateway — cancelar manualmente (se for paga, o webhook registra sem dar acesso).'
                    : 'Cobrança avulsa em aberto cancelada no gateway.',
                context: [
                    'gateway'                    => $subscription->gateway,
                    'cancelled_external_charges' => $result['cancelled'],
                    'open_external_charges'      => $result['open'],
                ],
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                gatewayCode: $subscription->gateway,
                correlationId: $correlationId,
            );
        }

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function cancelChargeAtGateway(string $gatewayCode, string $externalId, Subscription $subscription, string $correlationId): bool
    {
        try {
            if ($gatewayCode === '' || ! $this->gatewayRegistry->has($gatewayCode)) {
                return false;
            }

            return $this->gatewayRegistry->get($gatewayCode)
                ->withContext(new GatewayCallContext($correlationId, (string) $subscription->entity_id))
                ->cancelCharge($externalId);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Estratégia de dois estágios para cancelamento no gateway:
     *
     * 1. Tenta de forma síncrona na hora (caso mais comum funciona aqui mesmo).
     * 2. Se falhar, despacha CancelGatewaySubscriptionJob com backoff exponencial.
     *    O job tentará até 6 vezes ao longo de ~24 horas.
     *    Se esgotar todas as tentativas, emite Log::critical.
     *
     * @return bool true se cancelou na hora; false se ficou para o job
     */
    private function dispatchGatewayCancellation(Subscription $subscription, string $correlationId): bool
    {
        // Tentativa síncrona imediata (evita overhead de queue para o caso feliz)
        $succeededSynchronously = $this->attemptSynchronousCancel($subscription, $correlationId);

        if ($succeededSynchronously) {
            return true;
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

        return false;
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

            // Cancelamento nosso: o aviso do gateway que vem depois não é alerta.
            Subscription::rememberRecurrenceCancelledByUs((string) $subscription->id, $subscription->gateway_subscription_id);

            $result = $gateway->cancelSubscription(new CancelSubscriptionDTO(
                subscriptionId: (string) $subscription->id,
                externalSubscriptionId: $subscription->gateway_subscription_id,
                entityId: (string) $subscription->entity_id,
                metadata: [],
            ));

            return $result->success;
        } catch (Throwable) {
            return false;
        }
    }
}
