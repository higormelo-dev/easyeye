<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PaymentGatewayInterface;
use App\DTOs\Billing\{GatewayWebhookInputDTO, NormalizedWebhookEventDTO};
use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentStatus, WebhookEventStatus};
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Jobs\Billing\CancelGatewayChargeJob;
use App\Models\Billing\{HostedCheckout, Invoice, Payment, WebhookEvent};
use App\Models\{Entity, Subscription};
use App\Support\Billing\PaymentUrl;
use Carbon\{CarbonImmutable, CarbonInterface};
use Closure;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Aplica o evento normalizado do gateway à cobrança e à assinatura.
 *
 * Resolução: a assinatura vem do id dela no gateway, do Payment já gravado
 * (pelo id externo da cobrança) ou da referência que enviamos (metadata
 * subscription_id/invoice_id); a fatura, do Payment, do id externo, da
 * referência ou do período que contém o vencimento da cobrança.
 *
 * Tudo roda numa transação que trava a empresa e a assinatura: o mesmo
 * pagamento nunca estende o período duas vezes, e evento velho (falha depois
 * do pagamento) não volta o estado. Assinatura que não é mais cobrada pelo
 * gateway (cortesia, trial, cancelada, expirada, substituída) nunca muda por
 * webhook: o pagamento é registrado com alerta.
 */
class ProcessWebhookEventService
{
    /** Normalized event types that indicate a successful payment */
    private const PAID_TYPES = ['paid', 'authorized'];

    /** Cobrança recusada ou vencida sem pagamento */
    private const UNPAID_TYPES = ['failed', 'overdue'];

    /**
     * A recorrência foi encerrada no gateway: nunca bloqueia nem cancela a
     * assinatura — a cobrança passa para a renovação local, com alerta ao
     * manager (GatewayRecurrenceLossService).
     */
    private const SUBSCRIPTION_CANCELLED_TYPES = ['cancelled'];

    /** Só a cobrança foi cancelada/apagada — a assinatura segue */
    private const PAYMENT_CANCELLED_TYPES = ['payment_cancelled'];

    /** Normalized event types that indicate a refund */
    private const REFUNDED_TYPES = ['refunded'];

    /** Normalized event types that indicate a chargeback */
    private const CHARGEBACK_TYPES = ['chargeback'];

    /** Cobrança emitida/alterada: guarda link e vencimento */
    private const CHARGE_CREATED_TYPES = ['created'];

    /** Estorno de parte do valor: registra o devolvido, sem mudar fatura nem acesso. */
    private const PARTIAL_REFUND_TYPES = ['partially_refunded'];

    /** Andamento/negativa do estorno pedido: só o pedido do manager muda (RefundService). */
    private const REFUND_STATUS_TYPES = ['refund_in_progress', 'refund_denied'];

    /**
     * Chamadas ao gateway decididas dentro da transação (desfazer recorrência
     * do checkout, cancelar a recorrência substituída, alinhar vencimento,
     * cancelar checkout aberto) — rodam depois do commit.
     *
     * @var list<Closure>
     */
    private array $deferred = [];

    public function __construct(
        private readonly GatewayRegistry $gatewayRegistry,
        private readonly BillingLogService $billingLogService,
        private readonly FinancialEventService $financialEventService,
        private readonly SubscriptionCycleService $cycles,
        private readonly AiCreditPackCheckoutService $aiPacks,
        private readonly GatewayRecurrenceLossService $recurrenceLoss,
        private readonly HostedCheckoutService $hostedCheckouts,
        private readonly RefundService $refunds,
        private readonly GatewayAlertService $alerts,
    ) {
    }

    public function process(WebhookEvent $webhookEvent): void
    {
        if ($webhookEvent->status === WebhookEventStatus::Processed) {
            return;
        }

        $correlationId = $webhookEvent->correlation_id ?: (string) Str::uuid();

        $webhookEvent->update([
            'status'         => 'processing',
            'attempts'       => $webhookEvent->attempts + 1,
            'correlation_id' => $correlationId,
        ]);

        try {
            $gateway = $this->gatewayRegistry->get($webhookEvent->gateway_code);

            $normalized = $gateway->parseWebhook(new GatewayWebhookInputDTO(
                gatewayCode: $webhookEvent->gateway_code,
                headers: $webhookEvent->headers ?? [],
                body: json_encode($webhookEvent->payload ?? [], JSON_UNESCAPED_UNICODE) ?: '{}',
                payload: $webhookEvent->payload ?? [],
                externalEventId: $webhookEvent->external_event_id,
                signature: $webhookEvent->signature,
                receivedAt: $webhookEvent->received_at?->toIso8601String(),
            ));

            $eventType  = strtolower($normalized->eventType);
            $normalized = $this->withRefundedTotal($gateway, $normalized, $eventType);

            [$subscription, $invoice, $payment, $outcome] = $this->run($gateway, $normalized, $eventType, $correlationId);

            if (! $webhookEvent->entity_id && ($subscription || $invoice)) {
                $webhookEvent->entity_id = $subscription?->entity_id ?? $invoice->entity_id;
            }

            $webhookEvent->update([
                'event_type'         => $normalized->eventType,
                'normalized_payload' => [
                    'event_type'               => $normalized->eventType,
                    'status'                   => $normalized->status,
                    'external_subscription_id' => $normalized->externalSubscriptionId,
                    'external_payment_id'      => $normalized->externalPaymentId,
                    'external_invoice_id'      => $normalized->externalInvoiceId,
                    'amount'                   => $normalized->amount,
                    'currency'                 => $normalized->currency,
                    'due_date'                 => $normalized->dueDate,
                    'outcome'                  => $outcome,
                ],
                'status'       => 'processed',
                'processed_at' => now(),
                'last_error'   => null,
            ]);

            $this->billingLogService->log(
                level: str_starts_with($outcome, 'alert_') ? 'warning' : 'info',
                message: 'Webhook processado.',
                context: [
                    'event_type' => $normalized->eventType,
                    'status'     => $normalized->status,
                    'outcome'    => $outcome,
                ],
                entityId: $subscription?->entity_id ?? $webhookEvent->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                webhookEvent: $webhookEvent,
                gatewayCode: $normalized->gatewayCode,
                correlationId: $correlationId,
            );
        } catch (Throwable $e) {
            $webhookEvent->update([
                'status'     => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            $this->billingLogService->log(
                level: 'error',
                message: 'Falha ao processar webhook.',
                context: ['error' => $e->getMessage()],
                entityId: $webhookEvent->entity_id,
                webhookEvent: $webhookEvent,
                gatewayCode: $webhookEvent->gateway_code,
                correlationId: $correlationId,
            );

            throw $e;
        }
    }

    /**
     * Aplica um evento normalizado que não veio de uma notificação do
     * gateway — o pagamento conferido na API (régua antes de encerrar,
     * billing:reconcile-overdue) ou o estorno confirmado na resposta do
     * pedido do manager — pelo MESMO caminho do webhook (idempotente: o
     * webhook que chegar depois é duplicado).
     */
    public function applyNormalized(NormalizedWebhookEventDTO $normalized, string $source = 'reconcile'): string
    {
        $correlationId = (string) Str::uuid();
        $gateway       = $this->gatewayRegistry->get($normalized->gatewayCode);
        $eventType     = strtolower($normalized->eventType);

        [$subscription, $invoice, $payment, $outcome] = $this->run($gateway, $this->withRefundedTotal($gateway, $normalized, $eventType), $eventType, $correlationId);

        $this->billingLogService->log(
            level: str_starts_with($outcome, 'alert_') ? 'warning' : 'info',
            message: 'Evento conferido no gateway aplicado (sem webhook).',
            context: ['event_type' => $normalized->eventType, 'outcome' => $outcome, 'source' => $source, 'external_payment_id' => $normalized->externalPaymentId],
            entityId: $subscription?->entity_id ?? $invoice?->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            gatewayCode: $normalized->gatewayCode,
            correlationId: $correlationId,
        );

        return $outcome;
    }

    /**
     * Transação (empresa e assinatura travadas) e, depois do commit, as
     * chamadas ao gateway: recorrência das substituídas, cobranças que
     * deixaram de valer e as decididas pelo checkout hospedado.
     *
     * @return array{0: ?Subscription, 1: ?Invoice, 2: ?Payment, 3: string}
     */
    private function run(PaymentGatewayInterface $gateway, NormalizedWebhookEventDTO $normalized, string $eventType, string $correlationId): array
    {
        $this->deferred = [];

        [$subscription, $invoice, $payment, $outcome, $replaced, $staleCharges] = DB::transaction(
            fn (): array => $this->apply($normalized, $eventType, $correlationId),
        );

        $deferred       = $this->deferred;
        $this->deferred = [];

        // Cobrança recorrente das assinaturas substituídas: fora da transação (HTTP).
        $this->cycles->stopRecurrences($replaced, $correlationId);

        foreach ($deferred as $call) {
            try {
                $call();
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Paga por uma das cobranças da fatura: as outras (a vigente que o
        // checkout desligou, o boleto/Pix da outra forma) deixam de valer.
        $this->cancelStaleCharges($gateway, $invoice, $staleCharges, $correlationId);

        return [$subscription, $invoice, $payment, $outcome];
    }

    /**
     * Estorno parcial sem o total devolvido na notificação: consulta a
     * cobrança no gateway (fora da transação) para saber quanto já voltou.
     */
    private function withRefundedTotal(PaymentGatewayInterface $gateway, NormalizedWebhookEventDTO $n, string $eventType): NormalizedWebhookEventDTO
    {
        if (! in_array($eventType, self::PARTIAL_REFUND_TYPES, true) || isset($n->metadata['refunded_total']) || blank($n->externalPaymentId)) {
            return $n;
        }

        try {
            $current = $gateway->paymentStatusEvent((string) $n->externalPaymentId);
        } catch (Throwable) {
            $current = null;
        }

        $total = $current?->metadata['refunded_total'] ?? null;

        return is_numeric($total) ? $this->withMetadata($n, ['refunded_total' => (float) $total]) : $n;
    }

    /** @param array<string, mixed> $extra */
    private function withMetadata(NormalizedWebhookEventDTO $n, array $extra): NormalizedWebhookEventDTO
    {
        return new NormalizedWebhookEventDTO(
            gatewayCode: $n->gatewayCode,
            eventType: $n->eventType,
            externalEventId: $n->externalEventId,
            externalSubscriptionId: $n->externalSubscriptionId,
            externalPaymentId: $n->externalPaymentId,
            externalInvoiceId: $n->externalInvoiceId,
            status: $n->status,
            amount: $n->amount,
            currency: $n->currency,
            metadata: [...$n->metadata, ...$extra],
            rawPayload: $n->rawPayload,
            occurredAt: $n->occurredAt,
            dueDate: $n->dueDate,
            paymentUrl: $n->paymentUrl,
        );
    }

    /**
     * Resolve e aplica o evento com a empresa e a assinatura travadas.
     *
     * @return array{0: ?Subscription, 1: ?Invoice, 2: ?Payment, 3: string, 4: Collection<int, Subscription>, 5: list<string>}
     */
    private function apply(NormalizedWebhookEventDTO $n, string $eventType, string $correlationId): array
    {
        // Chave de API perto de expirar, desabilitada, expirada ou removida
        // (ACCESS_TOKEN_* do Asaas): alerta ao time, nada mais muda.
        if ($eventType === 'access_token_alert') {
            return [null, null, null, $this->alertAccessToken($n), collect(), []];
        }

        // Valor/vencimento alterados ou registro do boleto cancelado: o
        // Pix/linha digitável guardados dessa cobrança não valem mais.
        if (($n->metadata['invalidate_instructions'] ?? false) === true) {
            $this->invalidateInstructions($n);
        }

        if ($eventType === 'instructions_invalidated') {
            return [null, null, $this->findPayment($n), 'instructions_invalidated', collect(), []];
        }

        // Andamento do estorno pedido (PAYMENT_REFUND_IN_PROGRESS) ou estorno
        // negado (PAYMENT_REFUND_DENIED — boleto): só o pedido do manager
        // muda; pagamento, fatura e assinatura ficam como estão.
        if (in_array($eventType, self::REFUND_STATUS_TYPES, true)) {
            $payment = $this->findPayment($n);

            return [null, $payment?->invoice_id ? Invoice::query()->find($payment->invoice_id) : null, $payment, $this->refunds->applyGatewayRefundStatus($payment, $eventType, $n, $correlationId), collect(), []];
        }

        // Checkout hospedado (Asaas Checkout): ciclo de vida, assinatura
        // criada por ele e a 1ª cobrança — que só passa para a fatura quando
        // é confirmada (a cobrança anterior da fatura vale até lá).
        if (($checkout = $this->hostedCheckouts->forEvent($n)) !== null) {
            // Empresa primeiro (mesma ordem do resto do billing), depois o checkout.
            Entity::query()->whereKey($checkout->entity_id)->lockForUpdate()->first();
            $checkout = HostedCheckout::query()->whereKey($checkout->id)->lockForUpdate()->firstOrFail();

            $handled        = $this->hostedCheckouts->handleWebhook($n, $eventType, $checkout, $correlationId);
            $this->deferred = [...$this->deferred, ...$handled['deferred']];
            $n              = $handled['event'];

            if ($handled['outcome'] !== null) {
                return [$checkout->subscription, $checkout->invoice, $this->findPayment($n), $handled['outcome'], collect(), []];
            }
        }

        $knownPayment = $this->findPayment($n);

        // Cobrança de pacote de créditos de IA (fatura sem assinatura): o
        // serviço do pacote aplica — credita, estorna — sem tocar assinatura.
        if (($pack = $this->aiCreditPackInvoice($n, $knownPayment)) !== null) {
            [$invoice, $payment, $outcome, $stale] = $this->aiPacks->applyWebhook($n, $eventType, $pack, $correlationId);

            if ($outcome === 'ai_credits_granted') {
                $this->deferred = [...$this->deferred, ...$this->hostedCheckouts->supersedeOpenCheckouts($invoice, $n->externalPaymentId, $correlationId)];
            }

            return [null, $invoice, $payment, $outcome, collect(), $stale];
        }

        $subscription = $this->resolveSubscription($n, $knownPayment);

        if ($subscription) {
            Entity::query()->whereKey($subscription->entity_id)->lockForUpdate()->first();
            $subscription = Subscription::query()->with('plan')->whereKey($subscription->id)->lockForUpdate()->first();
        }

        $governed = $subscription !== null && $this->isGovernedBy($subscription, $n->gatewayCode);
        // Relido depois da trava: outro processamento do mesmo pagamento já terminou.
        $payment = $this->findPayment($n);
        $invoice = $this->resolveInvoice($n, $subscription, $payment);

        // Cobrança gerada pela recorrência do gateway (ex.: 2ª parcela do
        // Asaas) ainda sem fatura: cria a do período que começa no vencimento.
        // Nunca para cobrança de outra recorrência (duplicada/órfã).
        if (! $invoice && $governed && $n->dueDate !== null && $subscription->hasGatewayRecurrence()
            && ! $this->isForeignRecurrence($n, $subscription)
            && in_array($eventType, [...self::PAID_TYPES, ...self::UNPAID_TYPES, ...self::CHARGE_CREATED_TYPES], true)) {
            $invoice = $this->cycles->periodInvoice($subscription, $this->dueDateOf($n), $correlationId);
        }

        $isPaidEvent = in_array($eventType, self::PAID_TYPES, true);
        $isReversal  = in_array($eventType, [...self::REFUNDED_TYPES, ...self::CHARGEBACK_TYPES], true);

        // Estorno/chargeback já aplicado a este pagamento (reentrega depois
        // da retenção de webhook_events): sem novo evento financeiro, log ou
        // atraso.
        if ($isReversal && $payment !== null
            && $payment->status === (in_array($eventType, self::REFUNDED_TYPES, true) ? PaymentStatus::Refunded : PaymentStatus::Chargeback)) {
            return [$subscription, $invoice, $payment, 'duplicate', collect(), []];
        }

        // Estorno da cobrança que QUITOU a fatura: segue o estorno normal da
        // assinatura, mesmo que ela esteja entre as desligadas pelo checkout.
        $settledByThis = $isReversal && $invoice && $invoice->isSettledByCharge($n->externalPaymentId, $payment);

        // Estorno do pagamento em DUPLICIDADE (a fatura foi quitada por outra
        // cobrança, que segue paga): só esse pagamento — fatura e assinatura
        // ficam como estão (nada de reembolsada nem de atraso).
        if ($isReversal && $invoice && ! $settledByThis && $invoice->isSettledByAnotherCharge($n->externalPaymentId)) {
            return [$subscription, $invoice, $this->reverseDuplicatePayment($subscription, $invoice, $payment, $n, $eventType, $correlationId), in_array($eventType, self::REFUNDED_TYPES, true) ? 'duplicate_payment_refunded' : 'duplicate_payment_chargeback', collect(), []];
        }

        // Cobrança que o checkout substituiu (outra forma de pagamento) ou
        // tentativa de cartão recusada: falha, cancelamento ou emissão dela
        // não mexem na fatura, que já tem outra cobrança vigente. Pagamento
        // dela (o cliente pagou o Pix antigo) vale normalmente.
        if (! $isPaidEvent && ! $settledByThis && $invoice && $this->isDetachedCharge($invoice, $n)) {
            $dead = in_array($eventType, [...self::UNPAID_TYPES, ...self::PAYMENT_CANCELLED_TYPES], true);

            $payment?->update(array_filter([
                'status' => $dead && $payment->status !== PaymentStatus::Paid
                    ? PaymentStatus::Cancelled->value
                    : null,
            ]));

            // Cobrança mantida pelo checkout (outra forma) que expirou/foi
            // cancelada: as instruções dela saem da fatura.
            if ($dead) {
                $this->forgetCharges($invoice, [(string) $n->externalPaymentId]);
            }

            return [$subscription, $invoice, $payment, 'ignored_replaced_charge', collect(), []];
        }

        // Pagamento de assinatura que este gateway não cobra mais (substituída,
        // cancelada, expirada, cortesia com a recorrência antiga) sem fatura
        // identificável: fatura de registro para o dinheiro entrar na trilha —
        // Payment, evento de alerta e estorno. Nunca muda a assinatura (ver
        // applyPaid). Na assinatura vigente, a cobrança sem fatura segue só no
        // alerta: um reenvio, já com a fatura ligada, ainda pode aplicá-la.
        if (! $invoice && ! $payment && $subscription && ! $governed && $isPaidEvent && filled($n->externalPaymentId)) {
            $invoice = $this->unappliedPaymentInvoice($subscription, $n, $correlationId);
        }

        if (! $payment && $subscription && $invoice && $this->isChargeEvent($eventType)
            && ! ($isPaidEvent && $invoice->status === InvoiceStatus::Paid)) {
            $payment = $this->createPayment($n, $subscription, $invoice);
        }

        if ($invoice && $this->isChargeEvent($eventType)) {
            $this->syncInvoiceWithCharge($invoice, $n, $subscription);
        }

        // Cobrança apagada e restaurada no gateway (PAYMENT_RESTORED do
        // Asaas): volta a ser cobrança em aberto.
        if (in_array($eventType, self::CHARGE_CREATED_TYPES, true) && ($n->metadata['restored'] ?? false) === true) {
            $this->restoreCancelledCharge($invoice, $payment, $governed);
        }

        $replaced = collect();
        $outcome  = 'ignored';
        $stale    = [];

        if ($isPaidEvent) {
            [$outcome, $replaced] = $this->applyPaid($subscription, $invoice, $payment, $n, $governed, $correlationId);

            if ($invoice && in_array($outcome, ['activated', 'renewed'], true)) {
                $stale          = $this->retireOtherCharges($invoice->refresh(), (string) $n->externalPaymentId);
                $this->deferred = [...$this->deferred, ...$this->hostedCheckouts->supersedeOpenCheckouts($invoice, $n->externalPaymentId, $correlationId)];
            }
        } elseif (in_array($eventType, self::PARTIAL_REFUND_TYPES, true)) {
            $outcome = $this->applyPartialRefund($subscription, $invoice, $payment, $n, $governed, $correlationId);
        } elseif ($eventType === 'subscription_updated') {
            $outcome = $this->checkRecurrenceDivergence($subscription, $n, $governed, $correlationId);
        } elseif (in_array($eventType, self::UNPAID_TYPES, true)) {
            $outcome = $this->applyUnpaid($subscription, $invoice, $payment, $n, $eventType, $governed, $correlationId);
        } elseif (in_array($eventType, self::SUBSCRIPTION_CANCELLED_TYPES, true)) {
            $outcome = $this->recurrenceLoss->handle($subscription, $n, $governed, $correlationId);
        } elseif (in_array($eventType, self::PAYMENT_CANCELLED_TYPES, true)) {
            $outcome = $this->applyPaymentCancelled($invoice, $payment);
        } elseif (in_array($eventType, self::REFUNDED_TYPES, true)) {
            $outcome = $this->applyRefunded($subscription, $invoice, $payment, $correlationId);

            if ($payment !== null) {
                $this->refunds->markFullyRefunded($payment);
            }
        } elseif (in_array($eventType, self::CHARGEBACK_TYPES, true)) {
            $outcome = $this->applyChargeback($subscription, $invoice, $payment, $governed, $correlationId);
        } elseif (in_array($eventType, self::CHARGE_CREATED_TYPES, true)) {
            $outcome = $invoice ? 'charge_synced' : 'ignored';
        }
        // 'unknown' e tipos não reconhecidos: só registro, sem mudança de estado

        return [$subscription, $invoice, $payment, $outcome, $replaced, $stale];
    }

    /**
     * A fatura foi paga por esta cobrança: ela passa a ser a da fatura (a
     * vigente, se era outra, fica desligada) e as demais que ainda valiam —
     * a vigente de outra forma, o boleto/Pix mantido ao alternar — são
     * marcadas canceladas e devolvidas para cancelar no gateway depois do
     * commit (senão o cliente pode pagar duas vezes).
     *
     * @return list<string>
     */
    private function retireOtherCharges(Invoice $invoice, string $paidChargeId): array
    {
        if ($paidChargeId === '') {
            return [];
        }

        $stale    = $invoice->liveChargeIds($paidChargeId);
        $metadata = (array) ($invoice->metadata ?? []);
        $previous = $invoice->external_invoice_id;

        if ($stale === [] && $previous === $paidChargeId) {
            return [];
        }

        if (filled($previous) && $previous !== $paidChargeId) {
            $metadata['detached_charges'] = array_values(array_unique([...(array) ($metadata['detached_charges'] ?? []), (string) $previous]));
        }

        $metadata['cancelled_charges'] = array_values(array_unique([...(array) ($metadata['cancelled_charges'] ?? []), ...$stale]));
        $metadata['paid_by_charge']    = $paidChargeId;

        $invoice->forceFill([
            'external_invoice_id' => Invoice::query()->where('gateway_code', $invoice->gateway_code)->where('external_invoice_id', $paidChargeId)->whereKeyNot($invoice->id)->exists()
                ? $invoice->external_invoice_id
                : $paidChargeId,
            'payment_instructions' => null,
            'metadata'             => $metadata,
        ])->save();

        if ($stale !== []) {
            Payment::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('external_payment_id', $stale)
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Cancelled->value]);
        }

        return $stale;
    }

    /** Tira da fatura as instruções das cobranças que não valem mais. */
    private function forgetCharges(Invoice $invoice, array $chargeIds): void
    {
        $entries = array_filter(
            (array) ($invoice->payment_instructions ?? []),
            fn ($entry) => ! in_array((string) data_get($entry, 'charge_id', ''), $chargeIds, true),
        );
        $metadata                      = (array) ($invoice->metadata ?? []);
        $metadata['cancelled_charges'] = array_values(array_unique([...(array) ($metadata['cancelled_charges'] ?? []), ...$chargeIds]));

        $invoice->forceFill(['payment_instructions' => $entries !== [] ? $entries : null, 'metadata' => $metadata])->save();
    }

    /** @param list<string> $chargeIds */
    private function cancelStaleCharges(PaymentGatewayInterface $gateway, ?Invoice $invoice, array $chargeIds, string $correlationId): void
    {
        foreach ($chargeIds as $chargeId) {
            try {
                $cancelled = $gateway->cancelCharge($chargeId);
            } catch (Throwable) {
                $cancelled = false;
            }

            // Falhou agora (limite da API, timeout, 5xx): o job tenta de novo
            // com espera; só esgotado vira alerta crítico (cancelar à mão).
            if (! $cancelled) {
                try {
                    CancelGatewayChargeJob::dispatch($gateway->code(), $chargeId, $invoice?->id ? (string) $invoice->id : null, $correlationId)
                        ->delay(now()->addMinutes(2));
                } catch (Throwable $e) {
                    // Fila síncrona: a tentativa já rodou (e alertou se esgotou).
                    report($e);
                }

                $this->billingLogService->log(
                    level: 'warning',
                    message: 'Fatura paga por uma das cobranças; outra cobrança dela não pôde ser cancelada agora no gateway — nova tentativa agendada.',
                    context: ['external_charge_id' => $chargeId],
                    entityId: $invoice?->entity_id,
                    invoice: $invoice,
                    gatewayCode: $gateway->code(),
                    correlationId: $correlationId,
                );
            }
        }
    }

    // ── Resolução ────────────────────────────────────────────────────────────

    private function findPayment(NormalizedWebhookEventDTO $n): ?Payment
    {
        if (blank($n->externalPaymentId)) {
            return null;
        }

        return Payment::query()
            ->where('gateway_code', $n->gatewayCode)
            ->where('external_payment_id', $n->externalPaymentId)
            ->first();
    }

    /**
     * Fatura de pacote de créditos de IA desta cobrança: pelo Payment já
     * gravado, pelo id externo ligado à fatura ou pela referência que
     * enviamos (metadata invoice_id).
     */
    private function aiCreditPackInvoice(NormalizedWebhookEventDTO $n, ?Payment $payment): ?Invoice
    {
        $candidates = [];

        if ($payment?->invoice_id) {
            $candidates[] = fn () => Invoice::query()->find($payment->invoice_id);
        }

        foreach (array_filter([$n->externalInvoiceId, $n->externalPaymentId]) as $externalId) {
            $candidates[] = fn () => Invoice::query()->where('gateway_code', $n->gatewayCode)->where('external_invoice_id', $externalId)->first();
        }

        if (($invoiceId = $this->uuidFrom($n->metadata['invoice_id'] ?? null)) !== null) {
            $candidates[] = fn () => Invoice::query()->find($invoiceId);
        }

        foreach ($candidates as $find) {
            $invoice = $find();

            if ($invoice !== null) {
                return $invoice->isAiCreditPack() ? $invoice : null;
            }
        }

        return null;
    }

    private function resolveSubscription(NormalizedWebhookEventDTO $n, ?Payment $payment): ?Subscription
    {
        if (filled($n->externalSubscriptionId)) {
            $subscription = Subscription::query()
                ->where('gateway', $n->gatewayCode)
                ->where('gateway_subscription_id', $n->externalSubscriptionId)
                ->first();

            if ($subscription) {
                return $subscription;
            }
        }

        if ($payment?->subscription_id) {
            return Subscription::query()->find($payment->subscription_id);
        }

        $subscriptionId = $this->uuidFrom($n->metadata['subscription_id'] ?? null);

        if ($subscriptionId && ($subscription = Subscription::query()->find($subscriptionId))) {
            return $subscription;
        }

        $invoiceId = $this->uuidFrom($n->metadata['invoice_id'] ?? null);

        if ($invoiceId && ($invoice = Invoice::query()->find($invoiceId))) {
            return Subscription::query()->find($invoice->subscription_id);
        }

        return null;
    }

    private function resolveInvoice(NormalizedWebhookEventDTO $n, ?Subscription $subscription, ?Payment $payment): ?Invoice
    {
        $belongs = fn (?Invoice $invoice): bool => $invoice !== null
            && ($subscription === null || $invoice->subscription_id === $subscription->id);

        if ($payment && $belongs($invoice = Invoice::query()->find($payment->invoice_id))) {
            return $invoice;
        }

        foreach (array_filter([$n->externalInvoiceId, $n->externalPaymentId]) as $externalId) {
            $invoice = Invoice::query()
                ->where('gateway_code', $n->gatewayCode)
                ->where('external_invoice_id', $externalId)
                ->first();

            if ($belongs($invoice)) {
                return $invoice;
            }
        }

        $invoiceId = $this->uuidFrom($n->metadata['invoice_id'] ?? null);

        if ($invoiceId && $belongs($invoice = Invoice::query()->find($invoiceId))) {
            return $invoice;
        }

        if (! $subscription) {
            return null;
        }

        // Evento de outra recorrência (duplicada ou órfã — mesma referência,
        // outro id no gateway): só a cobrança já ligada a uma fatura (acima)
        // vale. Pelo vencimento ou pela 1ª fatura, cancelaria/venceria a
        // fatura da assinatura vigente.
        if ($this->isForeignRecurrence($n, $subscription)) {
            return null;
        }

        // Cobrança da recorrência: a fatura do período que contém o vencimento.
        if ($n->dueDate !== null) {
            $due     = $this->dueDateOf($n)->toDateString();
            $invoice = $subscription->invoices()
                ->forBillingPeriod()
                ->whereDate('period_start', '<=', $due)
                ->whereDate('period_end', '>', $due)
                ->orderByDesc('period_start')
                ->first();

            if ($invoice) {
                return $invoice;
            }
        }

        // Contratação que nunca pagou: a cobrança ainda não ligada (ou a mesma)
        // que vence antes do fim do 1º período é a da fatura do 1º período.
        // Quem já pagou nunca cai aqui — o pagamento da renovação não pode ir
        // para a fatura do ciclo anterior.
        if ($subscription->hasBeenPaid() || ! $subscription->current_invoice_id) {
            return null;
        }

        $first = Invoice::query()->find($subscription->current_invoice_id);

        $sameCharge = $first !== null
            && (blank($first->external_invoice_id) || $first->external_invoice_id === $n->externalPaymentId);
        $withinFirstPeriod = $n->dueDate === null
            || $first?->period_end === null
            || $this->dueDateOf($n)->lessThan($first->period_end);

        return $sameCharge && $withinFirstPeriod ? $first : null;
    }

    /** Cobrança desligada da fatura pelo checkout (invoices.metadata.detached_charges). */
    private function isDetachedCharge(Invoice $invoice, NormalizedWebhookEventDTO $n): bool
    {
        $detached = (array) data_get($invoice->metadata, 'detached_charges', []);

        return filled($n->externalPaymentId)
            && $n->externalPaymentId !== $invoice->external_invoice_id
            && in_array($n->externalPaymentId, $detached, true);
    }

    private function createPayment(NormalizedWebhookEventDTO $n, Subscription $subscription, Invoice $invoice): ?Payment
    {
        if (blank($n->externalPaymentId)) {
            return null;
        }

        return Payment::query()->create([
            'entity_id'           => $subscription->entity_id,
            'invoice_id'          => $invoice->id,
            'subscription_id'     => $subscription->id,
            'gateway_code'        => $n->gatewayCode,
            'external_payment_id' => $n->externalPaymentId,
            'status'              => PaymentStatus::Pending->value,
            'amount'              => $n->amount ?? (float) $invoice->amount,
            'currency'            => $n->currency ?? $invoice->currency,
            'idempotency_key'     => 'webhook:' . $n->gatewayCode . ':' . $n->externalPaymentId,
            'metadata'            => ['source' => 'webhook'],
        ]);
    }

    /** Liga a fatura à cobrança do gateway e guarda link e vencimento. */
    private function syncInvoiceWithCharge(Invoice $invoice, NormalizedWebhookEventDTO $n, ?Subscription $subscription): void
    {
        $updates = [];

        if (blank($invoice->gateway_code)) {
            $updates['gateway_code'] = $n->gatewayCode;
        }

        if (filled($n->externalPaymentId) && blank($invoice->external_invoice_id)
            && ! Invoice::query()->where('gateway_code', $n->gatewayCode)->where('external_invoice_id', $n->externalPaymentId)->exists()) {
            $updates['external_invoice_id'] = $n->externalPaymentId;
        }

        // Só link http(s) (vira href no painel e no e-mail); fora disso, descartado.
        if (($paymentUrl = PaymentUrl::safe($n->paymentUrl)) !== null) {
            $updates['payment_url'] = $paymentUrl;
        }

        $unpaid = ! in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Refunded], true);

        if ($unpaid && $n->dueDate !== null) {
            $updates['due_at'] = $this->dueDateOf($n)->endOfDay();
        }

        if ($invoice->status === InvoiceStatus::Draft) {
            $updates['status'] = InvoiceStatus::Pending->value;
        }

        if ($updates !== []) {
            $invoice->update($updates);
        }

        // Contratação: o acesso vale até o vencimento real da 1ª cobrança —
        // nunca o da cobrança reemitida pelo checkout (pagar depois do
        // vencimento não devolve acesso antes do pagamento).
        if ($subscription && $unpaid && $n->dueDate !== null && $subscription->isAwaitingFirstPayment()
            && $invoice->id === $subscription->current_invoice_id
            && ! data_get($invoice->metadata, 'checkout.reissued')) {
            $dueAt = $this->dueDateOf($n)->endOfDay();

            $subscription->update(['next_billing_at' => $dueAt, 'ends_at' => $dueAt]);
        }
    }

    // ── Estados ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: Collection<int, Subscription>}
     */
    private function applyPaid(
        ?Subscription $subscription,
        ?Invoice $invoice,
        ?Payment $payment,
        NormalizedWebhookEventDTO $n,
        bool $governed,
        string $correlationId,
    ): array {
        // Idempotência por pagamento: confirmado de novo (ex.: CONFIRMED e
        // depois RECEIVED no Asaas) não estende de novo.
        if (in_array($payment?->status, [PaymentStatus::Paid, PaymentStatus::Duplicate], true)) {
            return ['duplicate', collect()];
        }

        // Fatura já quitada por OUTRA cobrança: o dinheiro entrou de novo
        // (ex.: dois checkouts de cartão, Pix antigo pago depois). Registrado
        // como pagamento em duplicidade (não quita nada — a cobrança que
        // quitou segue sendo a única "paga"), com alerta, para o manager
        // estornar pela tela.
        if ($invoice?->status === InvoiceStatus::Paid) {
            $payment = $this->recordDuplicatePayment($invoice, $payment, $n);

            $this->alert($subscription, $invoice, $payment, 'Pagamento para fatura já paga — registrado em duplicidade; estornar pelo manager (Assinaturas → faturas).', $n, $correlationId);

            return ['alert_invoice_already_paid', collect()];
        }

        if (! $governed || ! $invoice || $this->isUnappliedRecord($invoice)) {
            // Cancelada, expirada, substituída, cortesia ou sem fatura: o
            // dinheiro entrou (registrado na fatura de registro quando não há
            // outra), mas a assinatura não muda — alerta para o time.
            $this->markPaymentPaid($payment, $invoice);

            if ($subscription && $payment) {
                $this->financialEventService->record(
                    eventType: BillingEventType::PaymentSucceeded,
                    entityId: (string) $subscription->entity_id,
                    subscription: $subscription,
                    invoice: $invoice,
                    payment: $payment,
                    amount: (float) $payment->amount,
                    currency: $payment->currency,
                    metadata: ['alert' => 'subscription_not_billed_by_gateway', 'subscription_status' => $subscription->status->value],
                    correlationId: $correlationId,
                    source: 'webhook',
                );
            }

            // Sem Payment (sem assinatura, sem id da cobrança ou cobrança da
            // assinatura vigente sem fatura): o log crítico guarda valor,
            // gateway e ids para o time conferir e registrar ou estornar à mão.
            $payment
                ? $this->alert($subscription, $invoice, $payment, 'Pagamento recebido para assinatura que não está sendo cobrada por este gateway — nenhuma mudança de acesso.', $n, $correlationId)
                : $this->alert($subscription, $invoice, null, 'Pagamento recebido sem assinatura ou cobrança identificável — não registrado; conferir no gateway e registrar ou estornar manualmente.', $n, $correlationId, 'critical');

            return ['alert_payment_not_applied', collect()];
        }

        $wasAwaitingFirst = $subscription->isAwaitingFirstPayment();
        $replaced         = $this->cycles->confirmPayment(
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            dueDate: $this->paidDueDate($n, $invoice, $subscription),
            correlationId: $correlationId,
            source: 'webhook',
        );

        return [$wasAwaitingFirst ? 'activated' : 'renewed', $replaced];
    }

    /**
     * Pagamento em duplicidade: o Payment da cobrança (criado se ainda não
     * existe) fica com status duplicate e a data do pagamento — nunca paid
     * (Invoice::isSettledByCharge/isSettledByAnotherCharge seguem apontando
     * a cobrança que quitou). Estornável pelo manager (RefundService).
     */
    private function recordDuplicatePayment(Invoice $invoice, ?Payment $payment, NormalizedWebhookEventDTO $n): ?Payment
    {
        if (blank($n->externalPaymentId)) {
            return $payment;
        }

        $payment ??= Payment::query()->create([
            'entity_id'           => $invoice->entity_id,
            'invoice_id'          => $invoice->id,
            'subscription_id'     => $invoice->subscription_id,
            'gateway_code'        => $n->gatewayCode,
            'external_payment_id' => $n->externalPaymentId,
            'status'              => PaymentStatus::Pending->value,
            'amount'              => $n->amount ?? (float) $invoice->amount,
            'currency'            => $n->currency ?? $invoice->currency,
            'idempotency_key'     => 'webhook:' . $n->gatewayCode . ':' . $n->externalPaymentId,
            'metadata'            => ['source' => 'webhook'],
        ]);

        $payment->update([
            'status'   => PaymentStatus::Duplicate->value,
            'paid_at'  => now(),
            'metadata' => [...(array) ($payment->metadata ?? []), 'duplicate_payment' => true, 'paid_by_charge' => data_get($invoice->metadata, 'paid_by_charge')],
        ]);

        return $payment;
    }

    private function applyUnpaid(
        ?Subscription $subscription,
        ?Invoice $invoice,
        ?Payment $payment,
        NormalizedWebhookEventDTO $n,
        string $eventType,
        bool $governed,
        string $correlationId,
    ): string {
        // Evento velho depois do pagamento não volta o estado.
        if ($payment?->status === PaymentStatus::Paid || $invoice?->status === InvoiceStatus::Paid) {
            return 'stale';
        }

        $overdue = $eventType === 'overdue';

        $payment?->update([
            'status'    => PaymentStatus::Failed->value,
            'failed_at' => now(),
        ]);

        // Só a fatura desta cobrança — nunca a paga do ciclo anterior.
        $invoice?->update([
            'status' => $overdue ? InvoiceStatus::Overdue->value : InvoiceStatus::Failed->value,
        ]);

        if (! $governed) {
            return 'ignored_not_billed_by_gateway';
        }

        // Diferença do upgrade não paga: a assinatura paga segue como está
        // (sem atraso nem régua) — só a fatura do upgrade fica em aberto.
        if ($invoice?->isPlanChange()) {
            return 'plan_change_unpaid';
        }

        // Cobrança que não conseguimos identificar não muda a assinatura.
        if (! $invoice) {
            return 'ignored_unknown_charge';
        }

        $error = __($overdue ? 'manager_subscriptions.billing_errors.payment_overdue' : 'manager_subscriptions.billing_errors.payment_failed');

        $this->financialEventService->record(
            eventType: $overdue ? BillingEventType::InvoiceOverdue : BillingEventType::PaymentFailed,
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            amount: $payment ? (float) $payment->amount : (float) $invoice->amount,
            currency: $payment?->currency ?? $invoice->currency,
            correlationId: $correlationId,
            source: 'webhook',
        );

        // Contratação: segue pendente, sem régua — o acesso acaba no fim do
        // dia do vencimento da 1ª cobrança.
        if (! $subscription->hasBeenPaid()) {
            $subscription->update([
                'billing_state'      => 'payment_failed',
                'last_billing_error' => $error,
            ]);

            return 'first_payment_unpaid';
        }

        $due = $this->chargeDueDate($n, $invoice);

        // Período desta cobrança já está pago (ex.: outra cobrança do mesmo ciclo).
        $periodEnd = $due ? $subscription->periodEndFrom($due) : null;

        if ($periodEnd && $subscription->ends_at && $subscription->ends_at->greaterThanOrEqualTo($periodEnd)) {
            return 'period_already_paid';
        }

        $dueEnd = $due ? CarbonImmutable::instance($due)->endOfDay() : null;

        // Recusada antes do vencimento (ex.: cartão): ainda não está em atraso
        // e o período pago segue; vencido sem pagamento, a expiração diária
        // põe em atraso desde o fim do período.
        if ($dueEnd && $dueEnd->isFuture()) {
            $subscription->update([
                'billing_state'      => 'payment_failed',
                'last_billing_error' => $error,
            ]);

            return 'payment_failed_before_due';
        }

        // Em atraso desde o vencimento da cobrança (fim do dia), ou agora.
        $this->cycles->markPastDue(
            subscription: $subscription,
            since: $dueEnd ?? now(),
            billingState: 'past_due',
            error: $error,
            correlationId: $correlationId,
            source: 'webhook',
            invoice: $invoice,
        );

        return 'past_due';
    }

    private function applyPaymentCancelled(?Invoice $invoice, ?Payment $payment): string
    {
        if ($payment?->status === PaymentStatus::Paid || $invoice?->status === InvoiceStatus::Paid) {
            return 'stale';
        }

        $payment?->update(['status' => PaymentStatus::Cancelled->value]);
        $invoice?->update(['status' => InvoiceStatus::Cancelled->value]);

        return $invoice || $payment ? 'payment_cancelled' : 'ignored';
    }

    private function applyRefunded(?Subscription $subscription, ?Invoice $invoice, ?Payment $payment, string $correlationId): string
    {
        $payment?->update([
            'status'      => PaymentStatus::Refunded->value,
            'refunded_at' => now(),
        ]);

        $invoice?->update([
            'status' => InvoiceStatus::Refunded->value,
        ]);

        if ($subscription) {
            $this->financialEventService->record(
                eventType: BillingEventType::PaymentRefunded,
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                amount: $payment ? (float) $payment->amount : null,
                currency: $payment?->currency,
                correlationId: $correlationId,
                source: 'webhook',
            );
        }

        return $invoice || $payment ? 'refunded' : 'ignored';
    }

    /**
     * Estorno/chargeback do pagamento em duplicidade (a fatura foi quitada
     * por outra cobrança, que segue paga): só esse pagamento, o evento
     * financeiro e o alerta. A fatura segue paga e a assinatura não muda.
     */
    private function reverseDuplicatePayment(?Subscription $subscription, Invoice $invoice, ?Payment $payment, NormalizedWebhookEventDTO $n, string $eventType, string $correlationId): ?Payment
    {
        $refund = in_array($eventType, self::REFUNDED_TYPES, true);

        $payment ??= filled($n->externalPaymentId) ? Payment::query()->create([
            'entity_id'           => $invoice->entity_id,
            'invoice_id'          => $invoice->id,
            'subscription_id'     => $invoice->subscription_id,
            'gateway_code'        => $n->gatewayCode,
            'external_payment_id' => $n->externalPaymentId,
            'status'              => PaymentStatus::Pending->value,
            'amount'              => $n->amount ?? (float) $invoice->amount,
            'currency'            => $n->currency ?? $invoice->currency,
            'idempotency_key'     => 'webhook:' . $n->gatewayCode . ':' . $n->externalPaymentId,
            'metadata'            => ['source' => 'webhook', 'duplicate_payment' => true],
        ]) : null;

        $payment?->update($refund
            ? ['status' => PaymentStatus::Refunded->value, 'refunded_at' => now()]
            : ['status' => PaymentStatus::Chargeback->value, 'chargeback_at' => now()]);

        // Estorno pedido pelo manager para a duplicidade: concluído.
        if ($refund && $payment !== null) {
            $this->refunds->markFullyRefunded($payment, touchInvoice: false);
        }

        $this->financialEventService->record(
            eventType: $refund ? BillingEventType::PaymentRefunded : BillingEventType::ChargebackReceived,
            entityId: (string) $invoice->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            amount: $payment ? (float) $payment->amount : $n->amount,
            currency: $payment?->currency ?? $n->currency,
            metadata: ['duplicate_payment' => true, 'paid_by_charge' => data_get($invoice->metadata, 'paid_by_charge')],
            correlationId: $correlationId,
            source: 'webhook',
        );

        $this->alert(
            $subscription,
            $invoice,
            $payment,
            $refund
                ? 'Estorno de pagamento em duplicidade — a fatura segue paga pela outra cobrança; assinatura sem mudança.'
                : 'Chargeback de pagamento em duplicidade — a fatura segue paga pela outra cobrança; assinatura sem mudança.',
            $n,
            $correlationId,
        );

        return $payment;
    }

    private function applyChargeback(?Subscription $subscription, ?Invoice $invoice, ?Payment $payment, bool $governed, string $correlationId): string
    {
        $payment?->update([
            'status'        => PaymentStatus::Chargeback->value,
            'chargeback_at' => now(),
        ]);

        $invoice?->update([
            'status' => InvoiceStatus::Failed->value,
        ]);

        if (! $subscription) {
            return 'ignored';
        }

        $this->financialEventService->record(
            eventType: BillingEventType::ChargebackReceived,
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            amount: $payment ? (float) $payment->amount : null,
            currency: $payment?->currency,
            correlationId: $correlationId,
            source: 'webhook',
        );

        if (! $governed) {
            return 'alert_payment_not_applied';
        }

        // Em disputa: atraso desde agora (a régua decide o acesso).
        $this->cycles->markPastDue(
            subscription: $subscription,
            since: now(),
            billingState: 'chargeback',
            error: __('manager_subscriptions.billing_errors.chargeback'),
            correlationId: $correlationId,
            source: 'webhook',
            invoice: $invoice,
        );

        return 'chargeback';
    }

    /**
     * Estorno parcial da cobrança que pagou a fatura: só registra o valor
     * devolvido (RefundService) — fatura paga e acesso como estão. Pagamento
     * que ainda não estava aplicado (o aviso do pago se perdeu e chegou só o
     * do estorno parcial) é aplicado antes.
     */
    private function applyPartialRefund(?Subscription $subscription, ?Invoice $invoice, ?Payment $payment, NormalizedWebhookEventDTO $n, bool $governed, string $correlationId): string
    {
        if ($invoice === null) {
            $this->alert($subscription, null, $payment, 'Estorno parcial de cobrança sem fatura identificável — conferir no gateway.', $n, $correlationId);

            return 'alert_partial_refund_unknown';
        }

        if ($payment !== null && $payment->status !== PaymentStatus::Paid && $invoice->status !== InvoiceStatus::Paid) {
            $this->applyPaid($subscription, $invoice, $payment, $n, $governed, $correlationId);
            $payment->refresh();
            $invoice->refresh();
        }

        return $this->refunds->applyPartialRefund($invoice, $payment, $subscription, $n, $correlationId);
    }

    /**
     * SUBSCRIPTION_UPDATED da recorrência vigente: valor, ciclo ou vencimento
     * alterados direto no painel do gateway. Não muda a assinatura aqui (o que
     * a clínica paga é o contratado) — alerta o time sobre a divergência.
     */
    private function checkRecurrenceDivergence(?Subscription $subscription, NormalizedWebhookEventDTO $n, bool $governed, string $correlationId): string
    {
        if ($subscription === null || ! $governed || $this->isForeignRecurrence($n, $subscription) || blank($n->externalSubscriptionId)) {
            return 'ignored';
        }

        $recurrence = (array) ($n->metadata['recurrence'] ?? []);
        $change     = $subscription->scheduledChange();
        $amounts    = array_filter([$subscription->recurringAmount(), isset($change['amount']) ? (float) $change['amount'] : null], fn ($v) => $v !== null);
        $cycles     = array_filter([$subscription->effectiveCycle()?->toAsaas(), isset($change['billing_cycle']) ? BillingCycle::tryFrom((string) $change['billing_cycle'])?->toAsaas() : null]);
        $issues     = [];

        if (isset($recurrence['value']) && $amounts !== [] && ! collect($amounts)->contains(fn ($a) => abs((float) $a - (float) $recurrence['value']) < 0.005)) {
            $issues['value'] = ['gateway' => (float) $recurrence['value'], 'expected' => array_values($amounts)];
        }

        if (isset($recurrence['cycle']) && $cycles !== [] && ! in_array(strtoupper((string) $recurrence['cycle']), $cycles, true)) {
            $issues['cycle'] = ['gateway' => (string) $recurrence['cycle'], 'expected' => array_values($cycles)];
        }

        // A próxima a ser gerada: o próximo vencimento nosso ou um ciclo
        // inteiro depois (a do próximo período já pode ter sido gerada).
        if (isset($recurrence['next_due_date']) && $subscription->next_billing_at !== null && ($months = $subscription->effectiveCycle()?->months()) > 0) {
            $next     = CarbonImmutable::instance($subscription->next_billing_at)->startOfDay();
            $accepted = collect(range(0, 2))->map(fn (int $k) => $next->addMonthsNoOverflow($k * $months)->toDateString());

            if (! $accepted->contains((string) $recurrence['next_due_date'])) {
                $issues['next_due_date'] = ['gateway' => (string) $recurrence['next_due_date'], 'expected' => $next->toDateString()];
            }
        }

        if ($issues === []) {
            return 'recurrence_in_sync';
        }

        $this->alerts->alert(
            gateway: $n->gatewayCode,
            kind: 'recurrence_diverged',
            params: ['entity' => (string) $subscription->entity?->name, 'subscription' => (string) $n->externalSubscriptionId, 'fields' => implode(', ', array_keys($issues))],
            message: 'A recorrência no gateway foi alterada fora do EasyEye (valor, ciclo ou vencimento diferentes da assinatura) — conferir e ajustar no painel do gateway.',
            level: 'critical',
            throttleKey: (string) $n->externalSubscriptionId . ':' . md5((string) json_encode($issues)),
            throttleMinutes: 60 * 24,
            entityId: (string) $subscription->entity_id,
        );

        $this->alert($subscription, null, null, 'Recorrência alterada no gateway diverge da assinatura: ' . json_encode($issues), $n, $correlationId);

        return 'alert_recurrence_diverged';
    }

    /** ACCESS_TOKEN_* (https://docs.asaas.com/docs/eventos-para-chaves-de-api): alerta ao time. */
    private function alertAccessToken(NormalizedWebhookEventDTO $n): string
    {
        $token = (array) ($n->metadata['access_token'] ?? []);

        $this->alerts->alert(
            gateway: $n->gatewayCode,
            kind: 'access_token',
            params: [
                'event'   => (string) ($token['event'] ?? ''),
                'name'    => (string) ($token['name'] ?? ''),
                'reason'  => (string) ($token['disable_reason'] ?? ''),
                'expires' => (string) ($token['expires_by_lack'] ?? $token['expiration_date'] ?? ''),
            ],
            message: 'Chave de API do gateway: ' . ($token['event'] ?? 'evento') . ' — trocar ou reativar antes que as cobranças parem.',
            level: 'critical',
            throttleKey: (string) ($token['event'] ?? '') . ':' . (string) ($token['id'] ?? ''),
            throttleMinutes: 60 * 12,
        );

        return 'alert_access_token';
    }

    /**
     * Tira das faturas o Pix/boleto guardados desta cobrança (valor ou
     * vencimento mudou; boleto vencido cancelado): a próxima abertura do
     * checkout consulta o gateway de novo. A cobrança segue valendo.
     */
    private function invalidateInstructions(NormalizedWebhookEventDTO $n): void
    {
        $chargeId = (string) $n->externalPaymentId;

        if ($chargeId === '') {
            return;
        }

        Invoice::query()
            ->where('gateway_code', $n->gatewayCode)
            ->whereNotNull('payment_instructions')
            ->where(fn ($q) => $q->where('external_invoice_id', $chargeId)
                ->orWhereIn('id', Payment::query()->where('gateway_code', $n->gatewayCode)->where('external_payment_id', $chargeId)->select('invoice_id')))
            ->lockForUpdate()
            ->get()
            ->each(function (Invoice $invoice) use ($chargeId): void {
                $entries = array_filter(
                    (array) ($invoice->payment_instructions ?? []),
                    fn ($entry) => (string) data_get($entry, 'charge_id', '') !== $chargeId,
                );

                $invoice->forceFill(['payment_instructions' => $entries !== [] ? $entries : null])->save();
            });
    }

    // ── Apoio ────────────────────────────────────────────────────────────────

    /**
     * Só a assinatura cobrada por este gateway e ainda vigente (ativa ou em
     * atraso) muda por webhook. Cortesia, trial, cancelada, expirada ou
     * substituída: nunca reativa nem bloqueia.
     */
    private function isGovernedBy(Subscription $subscription, string $gatewayCode): bool
    {
        return $subscription->billing_mode === SubscriptionBillingMode::Gateway
            && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
            && (blank($subscription->gateway) || $subscription->gateway === $gatewayCode);
    }

    /**
     * O evento vem de uma recorrência do gateway diferente da que a assinatura
     * acompanha (gateway_subscription_id preenchido e outro id no evento).
     */
    private function isForeignRecurrence(NormalizedWebhookEventDTO $n, Subscription $subscription): bool
    {
        return filled($n->externalSubscriptionId)
            && filled($subscription->gateway_subscription_id)
            && $n->externalSubscriptionId !== $subscription->gateway_subscription_id;
    }

    /**
     * Cobrança cancelada que o gateway restaurou: pagamento e fatura
     * cancelados voltam a pendente (a fatura só na assinatura que o gateway
     * ainda cobra — a encerrada não reabre).
     */
    private function restoreCancelledCharge(?Invoice $invoice, ?Payment $payment, bool $governed): void
    {
        if ($payment?->status === PaymentStatus::Cancelled) {
            $payment->update(['status' => PaymentStatus::Pending->value]);
        }

        if ($governed && $invoice?->status === InvoiceStatus::Cancelled) {
            $invoice->update(['status' => InvoiceStatus::Pending->value]);
        }
    }

    private function isChargeEvent(string $eventType): bool
    {
        return in_array($eventType, [
            ...self::PAID_TYPES,
            ...self::UNPAID_TYPES,
            ...self::PAYMENT_CANCELLED_TYPES,
            ...self::REFUNDED_TYPES,
            ...self::CHARGEBACK_TYPES,
            ...self::CHARGE_CREATED_TYPES,
            ...self::PARTIAL_REFUND_TYPES,
        ], true);
    }

    private function markPaymentPaid(?Payment $payment, ?Invoice $invoice): void
    {
        $payment?->update([
            'status'  => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);

        if ($invoice && $invoice->status !== InvoiceStatus::Paid) {
            $invoice->update([
                'status'  => InvoiceStatus::Paid->value,
                'paid_at' => now(),
            ]);
        }
    }

    /**
     * Vencimento que conta para o ciclo: o início do período da fatura (1º
     * período ou ciclo). O período não anda quando a cobrança é reemitida com
     * outro vencimento (nova tentativa depois do vencimento, vencimento
     * alterado no gateway) — senão quem paga atrasado ganha os dias de
     * atraso e o atraso recomeçaria a cada reemissão. Sem período (fatura
     * antiga), o vencimento informado pelo gateway ou o da fatura. O
     * vencimento do gateway segue valendo para due_at e para o acesso da
     * contratação ainda não paga (syncInvoiceWithCharge).
     */
    private function chargeDueDate(NormalizedWebhookEventDTO $n, ?Invoice $invoice): ?CarbonInterface
    {
        if ($invoice?->period_start !== null) {
            return CarbonImmutable::instance($invoice->period_start)->startOfDay();
        }

        if ($n->dueDate !== null) {
            return $this->dueDateOf($n);
        }

        return $invoice?->due_at;
    }

    /** Vencimento da cobrança paga — a nova vigência conta dele (sem ganhar nem perder dias). */
    private function paidDueDate(NormalizedWebhookEventDTO $n, ?Invoice $invoice, Subscription $subscription): CarbonInterface
    {
        return $this->chargeDueDate($n, $invoice)
            ?? $subscription->next_billing_at
            ?? now();
    }

    private function dueDateOf(NormalizedWebhookEventDTO $n): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $n->dueDate)->startOfDay();
    }

    private function uuidFrom(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    /**
     * Fatura de registro de um pagamento que não se liga a nenhuma fatura da
     * assinatura: só guarda o dinheiro recebido (Payment) — sem período, não
     * entra no ciclo, na régua nem na renovação.
     */
    private function unappliedPaymentInvoice(Subscription $subscription, NormalizedWebhookEventDTO $n, string $correlationId): Invoice
    {
        $date = $n->dueDate !== null ? $this->dueDateOf($n) : CarbonImmutable::today();

        return Invoice::query()->create([
            'entity_id'       => $subscription->entity_id,
            'subscription_id' => $subscription->id,
            'plan_id'         => $subscription->plan_id,
            'gateway_code'    => $n->gatewayCode,
            'reference'       => 'INV-' . $date->format('Ymd') . '-' . strtoupper(Str::random(8)),
            'due_at'          => $date->endOfDay(),
            'amount'          => $n->amount ?? $subscription->recurringAmount() ?? 0,
            'currency'        => $n->currency ?? 'BRL',
            'status'          => InvoiceStatus::Pending->value,
            'billing_reason'  => SubscriptionCycleService::BILLING_REASON_UNAPPLIED,
            'metadata'        => ['alert' => 'subscription_not_billed_by_gateway', 'subscription_status' => $subscription->status->value],
            'correlation_id'  => $correlationId,
            'idempotency_key' => "unapplied:{$n->gatewayCode}:{$n->externalPaymentId}",
        ]);
    }

    private function isUnappliedRecord(Invoice $invoice): bool
    {
        return $invoice->billing_reason === SubscriptionCycleService::BILLING_REASON_UNAPPLIED;
    }

    private function alert(?Subscription $subscription, ?Invoice $invoice, ?Payment $payment, string $message, NormalizedWebhookEventDTO $n, string $correlationId, string $level = 'warning'): void
    {
        $this->billingLogService->log(
            level: $level,
            message: $message,
            context: [
                'external_payment_id'      => $n->externalPaymentId,
                'external_subscription_id' => $n->externalSubscriptionId,
                'amount'                   => $n->amount,
                'currency'                 => $n->currency,
                'due_date'                 => $n->dueDate,
                'subscription_status'      => $subscription?->status?->value,
                'billing_mode'             => $subscription?->billing_mode?->value,
            ],
            entityId: $subscription?->entity_id ?? $invoice?->entity_id ?? $payment?->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            gatewayCode: $n->gatewayCode,
            correlationId: $correlationId,
        );
    }
}
