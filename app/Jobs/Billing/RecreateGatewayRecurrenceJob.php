<?php

namespace App\Jobs\Billing;

use App\DTOs\Billing\{CancelSubscriptionDTO, CreateSubscriptionDTO, GatewayCallContext};
use App\Enums\BillingCycle;
use App\Exceptions\Billing\GatewayIntegrationException;
use App\Models\{Plan, Subscription};
use App\Services\Billing\{BillingLogService, GatewayAlertService, GatewayRegistry};
use App\Support\Billing\BillingCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use RuntimeException;

/**
 * Troca de plano/ciclo numa assinatura com recorrência no gateway (Asaas):
 * cria a recorrência nova nos termos novos, com a 1ª cobrança no próximo
 * vencimento (fim do período pago), e cancela a antiga pelo id dela — nunca
 * pelo gateway_subscription_id da linha, que já aponta para a nova.
 *
 * Termos: os agendados (scheduled_change, downgrade) ou os vigentes (upgrade
 * já aplicado). Retomável: criada a nova (id gravado), a próxima tentativa
 * só cancela a antiga. Falha final → alerta crítico para o time.
 *
 * Criação: POST /v3/subscriptions (https://docs.asaas.com/reference/criar-nova-assinatura);
 * cancelamento: DELETE /v3/subscriptions/{id} (https://docs.asaas.com/reference/remover-assinatura).
 */
class RecreateGatewayRecurrenceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $previousRecurrenceId,
        public readonly string $firstDueDate,
        public readonly string $correlationId,
    ) {
        $this->onQueue((string) config('billing.webhooks.queue', 'default'));
    }

    public function handle(GatewayRegistry $registry, BillingLogService $billingLog): void
    {
        try {
            $this->recreate($registry, $billingLog);
        } catch (GatewayIntegrationException $e) {
            // 429: tenta de novo depois do tempo pedido pela API, não na hora.
            if ($e->isRateLimit()) {
                $this->release(max(1, (int) $e->retryAfter()));

                return;
            }

            throw $e;
        }
    }

    private function recreate(GatewayRegistry $registry, BillingLogService $billingLog): void
    {
        $subscription = Subscription::query()->with(['entity', 'plan'])->find($this->subscriptionId);

        if (! $subscription || blank($subscription->gateway) || ! $registry->has((string) $subscription->gateway)) {
            return;
        }

        $gateway = $registry->get((string) $subscription->gateway)
            ->withContext(new GatewayCallContext($this->correlationId, (string) $subscription->entity_id));

        // 1) A nova recorrência (só se ainda não foi criada por uma tentativa anterior).
        if ($subscription->gateway_subscription_id === $this->previousRecurrenceId) {
            $terms  = $subscription->scheduledChange();
            $cycle  = BillingCycle::tryFrom((string) ($terms['billing_cycle'] ?? '')) ?? $subscription->effectiveCycle();
            $amount = (float) ($terms['amount'] ?? $subscription->recurringAmount());
            $plan   = isset($terms['plan_id']) ? (Plan::withTrashed()->find($terms['plan_id']) ?? $subscription->plan) : $subscription->plan;

            if ($cycle === null || $cycle->months() <= 0 || $amount <= 0) {
                return;
            }

            $created = $gateway->createSubscription(new CreateSubscriptionDTO(
                entityId: (string) $subscription->entity_id,
                subscriptionId: (string) $subscription->id,
                planId: (string) ($plan?->id ?? $subscription->plan_id),
                customerId: (string) $subscription->gateway_customer_id,
                amount: $amount,
                currency: 'BRL',
                interval: $cycle->intervalName(),
                intervalCount: $cycle->intervalCount(),
                description: 'Assinatura ' . ($plan?->name ?? ''),
                metadata: BillingCustomer::chargeMetadata($subscription->entity),
                firstDueDate: $this->firstDueDate,
            ));

            if (! $created->success || blank($created->externalSubscriptionId)) {
                $this->giveUp($billingLog, $subscription, 'Troca de plano: a nova recorrência não foi criada no gateway — '
                    . mb_substr((string) $created->errorMessage, 0, 300));

                return;
            }

            Subscription::query()->whereKey($subscription->id)->update(['gateway_subscription_id' => $created->externalSubscriptionId]);

            if ($subscription->payment_method === 'credit_card') {
                $this->dropCard($subscription->fresh(['entity']) ?? $subscription, (string) $created->externalSubscriptionId, $billingLog);
            }
        }

        // 2) A antiga deixa de cobrar (cancelamento nosso: o aviso do gateway não é alerta).
        Subscription::rememberRecurrenceCancelledByUs((string) $subscription->id, $this->previousRecurrenceId);

        $cancelled = $gateway->cancelSubscription(new CancelSubscriptionDTO(
            entityId: (string) $subscription->entity_id,
            subscriptionId: (string) $subscription->id,
            externalSubscriptionId: $this->previousRecurrenceId,
        ));

        if (! $cancelled->success) {
            $this->giveUp($billingLog, $subscription, 'Troca de plano: a recorrência antiga não foi cancelada no gateway — cancelar manualmente para não cobrar duas vezes.');

            return;
        }

        $billingLog->log(
            level: 'info',
            message: 'Troca de plano: recorrência do gateway refeita nos novos termos.',
            context: ['previous_recurrence' => $this->previousRecurrenceId, 'first_due_date' => $this->firstDueDate],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            gatewayCode: $subscription->gateway,
            correlationId: $this->correlationId,
        );
    }

    /**
     * A recorrência estava no cartão (criada pelo Asaas Checkout) e a nova
     * nasce sem ele: a API só cria assinatura no cartão com os dados ou o
     * token do cartão, que o EasyEye nunca vê (PCI —
     * https://docs.asaas.com/docs/pci-dss-1). A nova é "pergunte ao cliente"
     * (UNDEFINED): ninguém é cobrado no cartão sem saber. Os dados do cartão
     * saem da assinatura, a clínica vê em Minha assinatura que precisa pagar
     * a próxima fatura no cartão para voltar à recorrência no cartão, e o
     * time é avisado.
     */
    private function dropCard(Subscription $subscription, string $newRecurrenceId, BillingLogService $billingLog): void
    {
        $payload                             = (array) ($subscription->gateway_payload ?? []);
        $payload['card_reregister_required'] = [
            'since'               => now()->toIso8601String(),
            'previous_recurrence' => $this->previousRecurrenceId,
            'new_recurrence'      => $newRecurrenceId,
            'card'                => array_filter(['brand' => $subscription->card_brand, 'last4' => $subscription->card_last4]),
        ];

        $subscription->update([
            'gateway_payload'   => $payload,
            'payment_method'    => null,
            'gateway_card_id'   => null,
            'card_brand'        => null,
            'card_last4'        => null,
            'card_installments' => null,
        ]);

        $billingLog->log(
            level: 'warning',
            message: 'Troca de plano: a recorrência no cartão foi refeita sem o cartão (boleto/Pix/cartão pela fatura) — a clínica precisa pagar a próxima fatura no cartão para voltar à recorrência no cartão.',
            context: ['previous_recurrence' => $this->previousRecurrenceId, 'new_recurrence' => $newRecurrenceId],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            gatewayCode: $subscription->gateway,
            correlationId: $this->correlationId,
        );

        app(GatewayAlertService::class)->alert(
            gateway: (string) $subscription->gateway,
            kind: 'card_reregister',
            params: ['entity' => (string) $subscription->entity?->name, 'subscription' => $newRecurrenceId],
            message: "Troca de plano de {$subscription->entity?->name}: a recorrência nova ({$newRecurrenceId}) nasceu sem o cartão — a clínica foi avisada para pagar a próxima fatura no cartão.",
            level: 'warning',
            throttleKey: $newRecurrenceId,
            throttleMinutes: 60 * 24,
            entityId: (string) $subscription->entity_id,
        );
    }

    private function giveUp(BillingLogService $billingLog, Subscription $subscription, string $message): void
    {
        if ($this->attempts() < $this->tries) {
            throw new RuntimeException($message);
        }

        $billingLog->log(
            level: 'critical',
            message: $message,
            context: ['previous_recurrence' => $this->previousRecurrenceId, 'first_due_date' => $this->firstDueDate],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            gatewayCode: $subscription->gateway,
            correlationId: $this->correlationId,
        );
    }
}
