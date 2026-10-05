<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\NormalizedWebhookEventDTO;
use App\Enums\{SaasRule};
use App\Models\Billing\SubscriptionChange;
use App\Models\{Subscription, User};
use App\Notifications\GatewayRecurrenceLostNotification;
use App\Support\Billing\NoticeLocale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * O gateway desativou SOZINHO a recorrência da assinatura vigente (Asaas:
 * SUBSCRIPTION_INACTIVATED / SUBSCRIPTION_DELETED; Stripe:
 * customer.subscription.deleted; Mercado Pago: preapproval cancelado) —
 * decisão do dono do produto: avisar o manager e seguir pela régua, sem
 * bloquear na hora.
 *
 *  - A assinatura NÃO muda de situação: quem pagou segue com acesso até o fim
 *    do período pago (ends_at). Nenhuma clínica que pagou é bloqueada por
 *    ação do gateway.
 *  - A cobrança passa para a renovação local (sem recorrência nativa:
 *    gateway_subscription_id nulo — o mesmo modelo dos gateways sem
 *    recorrência): a próxima fatura sai do subscriptions:renew
 *    (RenewSubscriptionJob) e é paga no checkout do sistema; sem
 *    pagamento, a régua normal (D-5, D+1, D+3, D+7).
 *  - Alerta para o time do SaaS: aviso na tela de Assinaturas do manager
 *    (recurrence_alert_at, até alguém marcar como visto), log de billing e
 *    e-mail para os usuários admin/financeiro/dono do SaaS.
 *  - Não é alerta: assinatura que não é a vigente/cobrada pelo gateway
 *    (substituída, cancelada, cortesia), evento de outra recorrência e
 *    recorrência que o PRÓPRIO sistema cancelou (rememberRecurrenceCancelledByUs)
 *    — só o registro do webhook (log).
 *  - Idempotente: a mesma recorrência só gera um alerta (o Asaas manda
 *    INACTIVATED e depois DELETED; reentregas).
 *
 * Roda dentro da transação do webhook, com a empresa e a assinatura
 * travadas (ProcessWebhookEventService); o e-mail sai depois do commit.
 */
class GatewayRecurrenceLossService
{
    public const CHANGE_TYPE = 'gateway_recurrence_lost';

    public function __construct(
        private readonly BillingLogService $billingLog,
    ) {
    }

    /**
     * @param bool $governed a assinatura é cobrada por este gateway e vigente (ativa ou em atraso)
     */
    public function handle(?Subscription $subscription, NormalizedWebhookEventDTO $n, bool $governed, string $correlationId): string
    {
        $externalId = (string) $n->externalSubscriptionId;

        if ($subscription === null) {
            // 2º aviso da mesma recorrência (já passada para a renovação local).
            return $externalId !== '' && $this->alreadyHandled($n->gatewayCode, $externalId) ? 'duplicate' : 'ignored';
        }

        if (! $governed || ! $subscription->isCurrentOfEntity()) {
            return 'ignored_not_current';
        }

        // Evento de outra recorrência (ou sem id): não é a que cobra esta assinatura.
        if ($externalId === '' || $externalId !== (string) $subscription->gateway_subscription_id) {
            return 'ignored_not_current_recurrence';
        }

        $cancelledByUs = $subscription->recurrenceCancelledByUs($externalId);
        $previous      = [
            'gateway_subscription_id' => $subscription->gateway_subscription_id,
            'next_billing_at'         => $subscription->next_billing_at?->toIso8601String(),
            'ends_at'                 => $subscription->ends_at?->toIso8601String(),
            'status'                  => $subscription->status->value,
        ];

        $subscription->update([
            'gateway_subscription_id' => null,
            // Já pagou: a próxima cobrança é no fim do período pago.
            'next_billing_at'     => $subscription->next_billing_at ?? ($subscription->hasBeenPaid() ? $subscription->ends_at : null),
            'recurrence_alert_at' => $cancelledByUs ? $subscription->recurrence_alert_at : now(),
            'correlation_id'      => $correlationId,
        ]);

        SubscriptionChange::query()->create([
            'entity_id'             => $subscription->entity_id,
            'subscription_id'       => $subscription->id,
            'previous_plan_id'      => $subscription->plan_id,
            'new_plan_id'           => $subscription->plan_id,
            'previous_gateway_code' => $subscription->gateway,
            'new_gateway_code'      => $subscription->gateway,
            'change_type'           => self::CHANGE_TYPE,
            'reason'                => $cancelledByUs ? 'cancelled_by_system' : 'gateway_inactivated',
            'changed_by'            => null,
            'effective_at'          => now(),
            'correlation_id'        => $correlationId,
            'metadata'              => [
                'gateway'                  => $n->gatewayCode,
                'external_subscription_id' => $externalId,
                'gateway_event'            => (string) data_get($n->rawPayload, 'event', data_get($n->rawPayload, 'type', $n->eventType)),
                'previous'                 => $previous,
                'billing'                  => 'local_renewal',
                'next_billing_at'          => $subscription->nextBillingDate()?->toIso8601String(),
                'access_until'             => $subscription->ends_at?->toIso8601String(),
                'alerted'                  => ! $cancelledByUs,
            ],
        ]);

        if ($cancelledByUs) {
            return 'recurrence_cancelled_by_us';
        }

        $this->billingLog->log(
            level: 'warning',
            message: 'O gateway desativou a recorrência da assinatura vigente: a cobrança passou para a renovação local (fatura emitida pelo sistema e paga no checkout). Acesso mantido até o fim do período pago.',
            context: [
                'external_subscription_id' => $externalId,
                'gateway_event'            => (string) data_get($n->rawPayload, 'event', $n->eventType),
                'next_billing_at'          => $subscription->nextBillingDate()?->toDateString(),
            ],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            gatewayCode: $n->gatewayCode,
            correlationId: $correlationId,
        );

        $subscriptionId = (string) $subscription->id;

        DB::afterCommit(fn () => $this->notifyStaff($subscriptionId));

        return 'alert_gateway_recurrence_lost';
    }

    /** Esta recorrência já foi tratada (passada para a renovação local). */
    public function alreadyHandled(string $gatewayCode, string $externalSubscriptionId): bool
    {
        return SubscriptionChange::query()
            ->where('change_type', self::CHANGE_TYPE)
            ->where('new_gateway_code', $gatewayCode)
            ->where('metadata->external_subscription_id', $externalSubscriptionId)
            ->exists();
    }

    /** Marca o aviso como visto (manager). */
    public function acknowledge(Subscription $subscription): void
    {
        $subscription->update(['recurrence_alert_at' => null]);
    }

    /**
     * Quem recebe o alerta: admin, financeiro e dono do SaaS (vínculo ativo
     * numa empresa que não é cliente), com e-mail verificado.
     *
     * @return Collection<int, User>
     */
    public function staffRecipients(): Collection
    {
        return User::query()
            ->whereNotNull('users.email_verified_at')
            ->whereHas('entityUsers', fn ($q) => $q->where('active', true)
                ->whereHas('entity', fn ($e) => $e->where('is_client', false))
                ->where(fn ($role) => $role->whereIn('rule', [SaasRule::Admin->value, SaasRule::Financial->value])->orWhere('is_owner', true)))
            ->orderBy('users.id')
            ->get();
    }

    private function notifyStaff(string $subscriptionId): void
    {
        $subscription = Subscription::query()->with(['entity', 'plan'])->find($subscriptionId);

        if ($subscription === null) {
            return;
        }

        foreach ($this->staffRecipients() as $user) {
            try {
                $user->notify((new GatewayRecurrenceLostNotification($subscription))->locale(NoticeLocale::for($user)));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
