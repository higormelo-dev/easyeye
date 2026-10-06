<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Billing\{PaymentGatewayInterface, QueriesGatewayRecurrences};
use App\DTOs\Billing\{GatewayCallContext, HostedCheckoutDTO, NormalizedWebhookEventDTO};
use App\Enums\Billing\InvoiceStatus;
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\Billing\{CheckoutException, GatewayIntegrationException};
use App\Models\Billing\{HostedCheckout, Invoice};
use App\Models\{Entity, Subscription};
use App\Support\Billing\HostedCheckoutReference;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Support\Str;
use Throwable;

/**
 * Cartão pelo checkout hospedado do gateway (Asaas Checkout —
 * https://docs.asaas.com/docs/checkout-asaas). O EasyEye nunca vê o cartão:
 * a clínica é levada à página do Asaas e volta para Minha assinatura pelas
 * URLs de callback ("aguardando confirmação"); o pagamento só vale pelo
 * webhook (CHECKOUT_PAID / PAYMENT_CONFIRMED / PAYMENT_RECEIVED).
 *
 * Dois tipos:
 *  - recorrente (fatura de período da assinatura): o checkout cria uma
 *    assinatura no cartão no Asaas (chargeTypes RECURRENT —
 *    https://docs.asaas.com/docs/checkout-com-assinatura-recorrente) cuja 1ª
 *    cobrança paga a fatura. Troca segura: a recorrência anterior (boleto/Pix
 *    "pergunte ao cliente") só é cancelada DEPOIS que essa 1ª cobrança é
 *    confirmada — nunca ficam duas cobrando nem nenhuma. A cobrança antiga em
 *    aberto da fatura é cancelada (não é paga duas vezes). Paga a fatura por
 *    outra cobrança antes (Pix antigo), a recorrência nova é desfeita;
 *  - avulso (pacote de créditos de IA, diferença do upgrade, fatura que não
 *    vira recorrência): chargeTypes DETACHED.
 *
 * O Asaas liga assinatura e cobranças ao checkout pelo campo checkoutSession;
 * o processamento do webhook (ProcessWebhookEventService) chama
 * handleWebhook() dentro da transação dele (empresa e assinatura travadas) e
 * roda as chamadas ao gateway devolvidas em `deferred` depois do commit.
 */
class HostedCheckoutService
{
    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly BillingLogService $billingLog,
        private readonly BillingCancellationService $cancellation,
        private readonly GatewayAlertService $alerts,
    ) {
    }

    // ── Abertura (checkout da clínica) ────────────────────────────────────────

    /**
     * Pode virar recorrência no cartão: fatura de período da assinatura
     * cobrada pelo gateway, no valor e ciclo contratados (a 1ª cobrança da
     * recorrência nova é a própria fatura). Diferença de upgrade, pacote de
     * IA, mudança agendada com outros termos ou valor diferente: avulso.
     */
    public function qualifiesForRecurrence(?Subscription $subscription, Invoice $invoice, PaymentGatewayInterface $gateway): bool
    {
        if ($subscription === null || ! $gateway instanceof QueriesGatewayRecurrences) {
            return false;
        }

        $cycle  = $subscription->effectiveCycle();
        $amount = $subscription->recurringAmount();

        return $invoice->subscription_id === $subscription->id
            && ! $invoice->isPlanChange()
            && ! $invoice->isAiCreditPack()
            && data_get($invoice->metadata, 'plan_change') === null
            && $invoice->billing_reason !== SubscriptionCycleService::BILLING_REASON_UNAPPLIED
            && $cycle !== null && $cycle->months() > 0
            && $amount !== null && abs($amount - (float) $invoice->amount) < 0.005
            // Mudança de plano agendada com outros termos: a recorrência do
            // gateway já foi (ou será) refeita nos termos novos — uma
            // recorrência no cartão com os termos atuais cobraria errado.
            && ! $this->scheduledChangeDiverges($subscription, $amount, $cycle);
    }

    /** A mudança agendada (downgrade/troca de ciclo) tem valor ou ciclo diferentes destes. */
    private function scheduledChangeDiverges(Subscription $subscription, float $amount, BillingCycle $cycle): bool
    {
        $change = $subscription->scheduledChange();

        if ($change === null || ($change['terms_applied'] ?? false)) {
            return false;
        }

        return (isset($change['amount']) && abs((float) $change['amount'] - $amount) >= 0.005)
            || (isset($change['billing_cycle']) && (string) $change['billing_cycle'] !== $cycle->value);
    }

    /**
     * Abre (ou reaproveita, se ainda aberto e no mesmo valor) o checkout
     * hospedado para pagar a fatura no cartão. Chamada ao gateway fora de
     * transação; falha da API → CheckoutException gateway_error.
     *
     * @return array<string, mixed> resposta do checkout (mode hosted)
     */
    public function open(Entity $entity, Invoice $invoice, ?Subscription $subscription, PaymentGatewayInterface $gateway, string $customerId, bool $recurrent): array
    {
        // Já pago num checkout (CHECKOUT_PAID) aguardando a confirmação da
        // cobrança: outro checkout cobraria o cartão de novo (e, no
        // recorrente, criaria uma 2ª assinatura no cartão).
        if ($this->awaitingConfirmation($invoice)) {
            throw CheckoutException::make('card_awaiting_confirmation', 409);
        }

        $kind     = $recurrent ? HostedCheckout::KIND_RECURRENT : HostedCheckout::KIND_DETACHED;
        $existing = $this->usableFor($invoice, $gateway, $recurrent);

        if ($existing !== null) {
            return $this->response($existing, $gateway);
        }

        $cycle       = $recurrent ? $subscription?->effectiveCycle() : null;
        $firstDue    = $recurrent ? $this->firstDueDate($invoice) : null;
        $correlation = (string) Str::uuid();
        $context     = new GatewayCallContext($correlation, (string) $entity->id);

        try {
            $result = $gateway->withContext($context)->createHostedCheckout(new HostedCheckoutDTO(
                entityId: (string) $entity->id,
                invoiceId: (string) $invoice->id,
                subscriptionId: $subscription?->id ? (string) $subscription->id : null,
                customerId: $customerId !== '' ? $customerId : null,
                amount: (float) $invoice->amount,
                itemName: $this->itemName($invoice, $subscription),
                description: $this->description($invoice, $cycle),
                successUrl: $this->callbackUrl('success', $invoice),
                cancelUrl: $this->callbackUrl('cancel', $invoice),
                expiredUrl: $this->callbackUrl('expired', $invoice),
                externalReference: HostedCheckoutReference::make($subscription?->id ? (string) $subscription->id : null, (string) $invoice->id),
                cycle: $cycle?->value,
                nextDueDate: $firstDue?->toDateString(),
            ));
        } catch (GatewayIntegrationException $e) {
            Log::warning('Checkout hospedado: falha ao abrir no gateway.', ['invoice_id' => $invoice->id, 'gateway' => $gateway->code(), 'error' => $e->getMessage()]);

            throw CheckoutException::make('gateway_error', 502);
        }

        if (! $result->success || blank($result->externalCheckoutId) || blank($result->url)) {
            $this->billingLog->log(
                level: 'warning',
                message: 'Checkout hospedado do cartão não foi criado pelo gateway.',
                context: ['error' => mb_substr((string) $result->errorMessage, 0, 500), 'http_status' => $result->httpStatus, 'kind' => $kind],
                entityId: (string) $entity->id,
                subscription: $subscription,
                invoice: $invoice,
                gatewayCode: $gateway->code(),
                correlationId: $correlation,
            );

            throw CheckoutException::make('gateway_error', 502);
        }

        $checkout = HostedCheckout::query()->create([
            'entity_id'              => $entity->id,
            'subscription_id'        => $subscription?->id,
            'invoice_id'             => $invoice->id,
            'gateway_code'           => $gateway->code(),
            'external_checkout_id'   => $result->externalCheckoutId,
            'kind'                   => $kind,
            'status'                 => HostedCheckout::STATUS_ACTIVE,
            'url'                    => $result->url,
            'amount'                 => (float) $invoice->amount,
            'cycle'                  => $cycle?->value,
            'next_due_date'          => $firstDue?->toDateString(),
            'expires_at'             => now()->addMinutes(max(10, (int) ($result->minutesToExpire ?? 60))),
            'replaces_recurrence_id' => $recurrent ? $subscription?->gateway_subscription_id : null,
            'replaces_charge_id'     => $invoice->external_invoice_id,
            'correlation_id'         => $correlation,
        ]);

        $this->billingLog->log(
            level: 'info',
            message: $recurrent
                ? 'Checkout hospedado aberto: assinatura no cartão (a recorrência atual só é trocada depois do pagamento confirmado).'
                : 'Checkout hospedado aberto: cobrança avulsa no cartão.',
            context: ['external_checkout_id' => $checkout->external_checkout_id, 'kind' => $kind, 'first_due_date' => $checkout->next_due_date?->toDateString()],
            entityId: (string) $entity->id,
            subscription: $subscription,
            invoice: $invoice,
            gatewayCode: $gateway->code(),
            correlationId: $correlation,
        );

        return $this->response($checkout, $gateway);
    }

    /** Checkout já aberto para a fatura, ainda válido por alguns minutos e no mesmo valor. */
    public function usableFor(Invoice $invoice, PaymentGatewayInterface $gateway, bool $recurrent): ?HostedCheckout
    {
        return HostedCheckout::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway_code', $gateway->code())
            ->where('kind', $recurrent ? HostedCheckout::KIND_RECURRENT : HostedCheckout::KIND_DETACHED)
            ->where('status', HostedCheckout::STATUS_ACTIVE)
            ->latest()
            ->get()
            ->first(fn (HostedCheckout $c) => $c->isUsable()
                && $c->expires_at?->greaterThan(now()->addMinutes(2))
                && abs((float) $c->amount - (float) $invoice->amount) < 0.005);
    }

    /** Pago no checkout (CHECKOUT_PAID), aguardando a confirmação da cobrança. */
    public function awaitingConfirmation(Invoice $invoice): bool
    {
        return HostedCheckout::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', HostedCheckout::STATUS_PAID)
            ->exists();
    }

    /** @return array<string, mixed> */
    public function response(HostedCheckout $checkout, PaymentGatewayInterface $gateway): array
    {
        return [
            'mode'         => 'hosted',
            'method'       => 'credit_card',
            'gateway'      => $gateway->code(),
            'checkout_url' => $checkout->url,
            'payment_url'  => $checkout->url,
            'recurrent'    => $checkout->isRecurrent(),
            'expires_at'   => $checkout->expires_at?->toIso8601String(),
            // Recorrente: o Asaas cobra o cartão no vencimento da fatura (hoje,
            // se já venceu) — a tela avisa quando é numa data futura.
            'first_charge_on' => $checkout->isRecurrent() ? $checkout->next_due_date?->toDateString() : null,
        ];
    }

    /**
     * 1ª cobrança da recorrência no cartão: no vencimento da fatura (ou hoje,
     * se já venceu — o Asaas não aceita vencimento passado). Cartão com
     * vencimento hoje é cobrado na hora; futuro, no dia — e a recorrência já
     * nasce alinhada ao período da assinatura.
     */
    private function firstDueDate(Invoice $invoice): CarbonImmutable
    {
        $due = $invoice->period_start ?? $invoice->due_at ?? now();

        return CarbonImmutable::instance($due)->startOfDay()->max(CarbonImmutable::today());
    }

    private function callbackUrl(string $result, Invoice $invoice): string
    {
        return route('panel.my-subscription.index', ['checkout_return' => $result, 'checkout_invoice' => (string) $invoice->id]);
    }

    private function itemName(Invoice $invoice, ?Subscription $subscription): string
    {
        $name = $invoice->isAiCreditPack()
            ? __('checkout.hosted.item_ai_pack', ['credits' => (int) data_get($invoice->metadata, 'credits', 0)])
            : ($invoice->isPlanChange()
                ? __('checkout.hosted.item_upgrade', ['plan' => (string) data_get($invoice->metadata, 'plan_change.plan_name', '')])
                : __('checkout.hosted.item_subscription', ['plan' => (string) ($subscription?->plan?->name ?? '')]));

        return mb_substr(trim($name), 0, 30);
    }

    private function description(Invoice $invoice, ?BillingCycle $cycle): string
    {
        return mb_substr(__('checkout.hosted.description', [
            'reference' => (string) $invoice->reference,
            'cycle'     => $cycle?->label() ?? '',
        ]), 0, 150);
    }

    // ── Webhook ───────────────────────────────────────────────────────────────

    /**
     * Checkout do evento: pelo checkoutSession da cobrança/assinatura (ou o
     * id do checkout nos CHECKOUT_*); sem ele, pela recorrência criada pelo
     * checkout (external_subscription_id, gravado no SUBSCRIPTION_CREATED) —
     * a cobrança dela pode chegar sem o checkoutSession.
     */
    public function forEvent(NormalizedWebhookEventDTO $n): ?HostedCheckout
    {
        $session = $n->metadata['checkout_session'] ?? null;

        if (is_string($session) && $session !== '') {
            return HostedCheckout::query()
                ->where('gateway_code', $n->gatewayCode)
                ->where('external_checkout_id', $session)
                ->first();
        }

        if (blank($n->externalSubscriptionId)) {
            return null;
        }

        return HostedCheckout::query()
            ->where('gateway_code', $n->gatewayCode)
            ->where('kind', HostedCheckout::KIND_RECURRENT)
            ->where('external_subscription_id', $n->externalSubscriptionId)
            ->latest()
            ->first();
    }

    /**
     * Aplica o evento ao checkout (na transação do webhook, com a empresa
     * travada). Devolve:
     *  - outcome: resultado final (o evento para aqui) ou null (o
     *    processamento normal segue);
     *  - event: o evento, com a fatura/assinatura do checkout no metadata
     *    quando o processamento segue;
     *  - deferred: chamadas ao gateway para depois do commit.
     *
     * @return array{outcome: ?string, event: NormalizedWebhookEventDTO, deferred: list<Closure>}
     */
    public function handleWebhook(NormalizedWebhookEventDTO $n, string $eventType, HostedCheckout $checkout, string $correlationId): array
    {
        $deferred = [];

        // Ciclo de vida do checkout (a confirmação financeira é a da cobrança).
        if (str_starts_with($eventType, 'checkout_')) {
            $status = match ($eventType) {
                'checkout_paid'     => HostedCheckout::STATUS_PAID,
                'checkout_canceled' => HostedCheckout::STATUS_CANCELED,
                'checkout_expired'  => HostedCheckout::STATUS_EXPIRED,
                default             => null,
            };

            // Só o checkout ainda aberto muda (pago/adotado/desfeito não volta).
            if ($status !== null && $checkout->status === HostedCheckout::STATUS_ACTIVE) {
                $checkout->update(['status' => $status]);
            }

            return ['outcome' => $eventType, 'event' => $n, 'deferred' => []];
        }

        $completed = $checkout->status === HostedCheckout::STATUS_COMPLETED;

        // Eventos da assinatura criada pelo checkout.
        if (str_starts_with($eventType, 'subscription_') || $eventType === 'cancelled') {
            $externalSub = (string) $n->externalSubscriptionId;

            if ($completed) {
                // Já adotada: é a recorrência da assinatura — fluxo normal.
                return ['outcome' => null, 'event' => $n, 'deferred' => []];
            }

            if ($eventType === 'subscription_created' && $externalSub !== '') {
                $checkout->update(['external_subscription_id' => $checkout->external_subscription_id ?? $externalSub]);

                // A recorrência nova não vale: fatura já paga por outra
                // cobrança, checkout invalidado (assinatura cancelada,
                // encerrada, substituída, plano trocado) ou a assinatura não é
                // mais cobrada por este gateway — desfeita antes da 1ª cobrança.
                $reason = match (true) {
                    $checkout->status === HostedCheckout::STATUS_SUPERSEDED => 'superseded',
                    $checkout->invoice?->status === InvoiceStatus::Paid     => 'invoice_already_paid',
                    ! $this->governs($checkout, $checkout->subscription)    => 'subscription_not_billed',
                    default                                                 => null,
                };

                if ($reason !== null) {
                    $checkout->update(['status' => HostedCheckout::STATUS_SUPERSEDED]);
                    $deferred[] = $this->cancelNewRecurrence($checkout, $externalSub, $correlationId, $reason);
                }
            }

            return ['outcome' => 'checkout_' . $eventType, 'event' => $n, 'deferred' => $deferred];
        }

        $paymentId = (string) $n->externalPaymentId;

        // Cobranças seguintes da recorrência já adotada, ou de outro pagamento: fluxo normal.
        if ($completed && $paymentId !== '' && $paymentId !== (string) $checkout->external_payment_id) {
            return ['outcome' => null, 'event' => $n, 'deferred' => []];
        }

        $event = $this->withCheckoutReference($n, $checkout);

        if ($completed || in_array($eventType, ['refunded', 'chargeback', 'partially_refunded'], true)) {
            return ['outcome' => null, 'event' => $event, 'deferred' => []];
        }

        if (in_array($eventType, ['paid', 'authorized'], true)) {
            return $this->onPaid($event, $checkout, $correlationId);
        }

        // Recusada (análise de risco, captura) ou vencida antes de valer: a
        // fatura segue com a cobrança anterior; a recorrência nova é desfeita.
        if (in_array($eventType, ['failed', 'overdue'], true)) {
            $checkout->update([
                'status'              => HostedCheckout::STATUS_FAILED,
                'external_payment_id' => $paymentId !== '' ? $paymentId : $checkout->external_payment_id,
            ]);

            $externalSub = (string) ($n->externalSubscriptionId ?? $checkout->external_subscription_id);

            if ($checkout->isRecurrent() && $externalSub !== '') {
                $deferred[] = $this->cancelNewRecurrence($checkout, $externalSub, $correlationId, 'payment_failed');
            }

            $this->billingLog->log(
                level: 'warning',
                message: 'Checkout hospedado: a cobrança no cartão não foi aprovada — a fatura segue com a cobrança anterior.',
                context: ['external_checkout_id' => $checkout->external_checkout_id, 'external_payment_id' => $paymentId, 'event' => $eventType],
                entityId: (string) $checkout->entity_id,
                invoice: $checkout->invoice,
                gatewayCode: $checkout->gateway_code,
                correlationId: $correlationId,
            );

            return ['outcome' => 'checkout_charge_failed', 'event' => $event, 'deferred' => $deferred];
        }

        // Emitida/alterada/apagada antes de valer: não mexe na fatura (a
        // cobrança anterior dela segue valendo até a confirmação).
        if ($paymentId !== '' && blank($checkout->external_payment_id)) {
            $checkout->update(['external_payment_id' => $paymentId]);
        }

        return ['outcome' => 'checkout_charge_pending', 'event' => $event, 'deferred' => []];
    }

    /**
     * Cobrança do checkout confirmada. Avulso: segue para a fatura. Recorrente:
     * a recorrência nova passa a ser a da assinatura (a anterior é cancelada
     * no gateway depois do commit) — só com a assinatura governada, a fatura
     * a pagar e os mesmos termos (adoptionBlocker). Senão não adota: a
     * recorrência nova é desfeita e o pagamento segue o fluxo normal (fatura
     * já paga → pagamento em duplicidade, estornável pelo manager).
     *
     * @return array{outcome: ?string, event: NormalizedWebhookEventDTO, deferred: list<Closure>}
     */
    private function onPaid(NormalizedWebhookEventDTO $n, HostedCheckout $checkout, string $correlationId): array
    {
        $deferred  = [];
        $paymentId = (string) $n->externalPaymentId;

        if (! $checkout->isRecurrent()) {
            $checkout->update(['status' => HostedCheckout::STATUS_COMPLETED, 'external_payment_id' => $paymentId ?: null, 'completed_at' => now()]);

            return ['outcome' => null, 'event' => $n, 'deferred' => []];
        }

        // Mesma ordem de trava do resto do billing: empresa (já travada),
        // assinatura, fatura.
        $externalSub  = (string) ($n->externalSubscriptionId ?? $checkout->external_subscription_id);
        $subscription = $checkout->subscription_id
            ? Subscription::query()->whereKey($checkout->subscription_id)->lockForUpdate()->first()
            : null;
        $invoice = Invoice::query()->whereKey($checkout->invoice_id)->lockForUpdate()->first();

        // Só adota com tudo ainda valendo: assinatura governada por este
        // gateway (ativa/em atraso, cobrança automática), a mesma da fatura,
        // fatura ainda a pagar, checkout não invalidado e nos mesmos termos
        // (valor/ciclo) da assinatura hoje. Senão a recorrência nova é
        // desfeita (o Asaas seguiria cobrando o cartão) e o pagamento segue
        // para o fluxo normal (registrado; duplicidade/assinatura encerrada
        // viram alerta e podem ser estornados pelo manager).
        $reason = $this->adoptionBlocker($checkout, $subscription, $invoice, $externalSub);

        if ($reason !== null) {
            $checkout->update([
                'status'                   => HostedCheckout::STATUS_SUPERSEDED,
                'external_payment_id'      => $paymentId ?: null,
                'external_subscription_id' => $externalSub ?: $checkout->external_subscription_id,
                'metadata'                 => [...(array) ($checkout->metadata ?? []), 'not_adopted' => $reason],
            ]);

            if ($externalSub !== '' && $subscription !== null) {
                $deferred[] = $this->cancelNewRecurrence($checkout, $externalSub, $correlationId, $reason);
            }

            if ($reason === 'terms_changed' && $subscription !== null) {
                $this->alerts->alert(
                    gateway: $checkout->gateway_code,
                    kind: 'checkout_terms_changed',
                    params: ['entity' => (string) $subscription->entity?->name, 'subscription' => $externalSub, 'amount' => (string) $checkout->amount, 'cycle' => (string) $checkout->cycle],
                    message: "Checkout {$checkout->external_checkout_id} pago com os termos anteriores à troca de plano: a recorrência no cartão {$externalSub} foi desfeita e a vigente mantida — conferir a forma de pagamento com a clínica.",
                    level: 'critical',
                    throttleKey: (string) $checkout->id,
                    throttleMinutes: 60 * 24,
                    entityId: (string) $subscription->entity_id,
                );
            }

            return ['outcome' => null, 'event' => $n, 'deferred' => $deferred];
        }

        $previous = $subscription->gateway_subscription_id;
        $card     = (array) ($n->metadata['card'] ?? []);

        if (filled($previous) && $previous !== $externalSub) {
            Subscription::rememberRecurrenceCancelledByUs((string) $subscription->id, (string) $previous);
            $subscription->refresh();
        }

        // Cartão de novo na recorrência: o aviso "cadastre o cartão de novo"
        // (recorrência refeita sem cartão na troca de plano) sai.
        $payload = (array) ($subscription->gateway_payload ?? []);
        unset($payload['card_reregister_required']);

        $subscription->update(array_filter([
            'gateway_payload'         => $payload,
            'gateway_subscription_id' => $externalSub,
            'payment_method'          => 'credit_card',
            'gateway_card_id'         => null,
            'card_brand'              => $card['brand'] ?? null,
            'card_last4'              => $card['last4'] ?? null,
            'card_installments'       => 1,
            'correlation_id'          => $correlationId,
        ], fn ($value, $key) => $value !== null || $key === 'gateway_card_id', ARRAY_FILTER_USE_BOTH));

        $checkout->update([
            'status'                   => HostedCheckout::STATUS_COMPLETED,
            'external_payment_id'      => $paymentId ?: null,
            'external_subscription_id' => $externalSub,
            'replaces_recurrence_id'   => filled($previous) && $previous !== $externalSub ? $previous : $checkout->replaces_recurrence_id,
            'completed_at'             => now(),
            'metadata'                 => [...(array) ($checkout->metadata ?? []), 'card' => $card ?: null],
        ]);

        $this->billingLog->log(
            level: 'info',
            message: 'Checkout hospedado: 1ª cobrança da assinatura no cartão confirmada — a recorrência nova passa a ser a da assinatura.',
            context: ['external_checkout_id' => $checkout->external_checkout_id, 'new_recurrence' => $externalSub, 'previous_recurrence' => $previous],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            gatewayCode: $checkout->gateway_code,
            correlationId: $correlationId,
        );

        if (filled($previous) && $previous !== $externalSub) {
            $subscriptionId = (string) $subscription->id;
            $deferred[]     = function () use ($subscriptionId, $previous, $correlationId): void {
                $fresh = Subscription::query()->find($subscriptionId);

                if ($fresh !== null) {
                    $this->cancellation->cancelRecurrenceById($fresh, (string) $previous, $correlationId);
                }
            };
        }

        $deferred[] = fn () => $this->alignRecurrence((string) $checkout->id, $correlationId);

        return ['outcome' => null, 'event' => $n, 'deferred' => $deferred];
    }

    /**
     * Por que o checkout recorrente pago NÃO vira a recorrência da
     * assinatura (null = pode adotar).
     */
    private function adoptionBlocker(HostedCheckout $checkout, ?Subscription $subscription, ?Invoice $invoice, string $externalSub): ?string
    {
        return match (true) {
            $invoice === null || $invoice->status === InvoiceStatus::Paid                                               => 'invoice_already_paid',
            $subscription === null || $externalSub === ''                                                               => 'unidentified',
            $checkout->status === HostedCheckout::STATUS_FAILED                                                         => 'payment_failed',
            $checkout->status === HostedCheckout::STATUS_SUPERSEDED                                                     => 'superseded',
            ! $this->governs($checkout, $subscription)                                                                  => 'subscription_not_billed',
            $invoice->subscription_id !== $subscription->id                                                             => 'other_subscription',
            ! in_array($invoice->status, [InvoiceStatus::Pending, InvoiceStatus::Overdue, InvoiceStatus::Failed], true) => 'invoice_not_payable',
            ! $this->sameTerms($checkout, $subscription)                                                                => 'terms_changed',
            default                                                                                                     => null,
        };
    }

    /**
     * A assinatura do checkout ainda é cobrada automaticamente por este
     * gateway (ativa ou em atraso) — cancelada, expirada, cortesia, trial ou
     * substituída nunca ganha recorrência nova.
     */
    private function governs(HostedCheckout $checkout, ?Subscription $subscription): bool
    {
        return $subscription !== null
            && $subscription->billing_mode === SubscriptionBillingMode::Gateway
            && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)
            && (blank($subscription->gateway) || $subscription->gateway === $checkout->gateway_code);
    }

    /**
     * O checkout foi aberto nos termos que a assinatura tem hoje (valor e
     * ciclo, sem mudança agendada diferente): trocou o plano depois de abrir,
     * a recorrência do checkout cobraria os termos antigos.
     */
    private function sameTerms(HostedCheckout $checkout, Subscription $subscription): bool
    {
        $amount = $subscription->recurringAmount();
        $cycle  = $subscription->effectiveCycle();

        return $amount !== null && $cycle !== null
            && abs($amount - (float) $checkout->amount) < 0.005
            && (blank($checkout->cycle) || $checkout->cycle === $cycle->value)
            && ! $this->scheduledChangeDiverges($subscription, $amount, $cycle);
    }

    /**
     * A assinatura deixou de valer como estava quando a clínica abriu o
     * checkout (cancelada, encerrada pela régua, substituída pelo manager,
     * virou cortesia, trocou de plano): os checkouts dela deixam de valer —
     * o ainda aberto é cancelado no gateway e a recorrência que um checkout
     * já pago criou (aguardando a 1ª cobrança) é desfeita antes de cobrar o
     * cartão. Avulso já pago segue o fluxo normal (o pagamento é registrado).
     * O que ainda não chegou (assinatura criada depois) é desfeito no
     * SUBSCRIPTION_CREATED / na confirmação (status superseded).
     *
     * Pode ser chamado dentro de transação: as chamadas ao gateway rodam
     * depois do commit.
     *
     * @param bool $recurrentOnly só os recorrentes (troca de plano: o avulso do upgrade segue)
     *
     * @return int checkouts invalidados
     */
    public function supersedeForSubscription(Subscription $subscription, string $reason, ?string $correlationId = null, bool $recurrentOnly = false): int
    {
        $correlationId ??= (string) Str::uuid();

        $calls = DB::transaction(function () use ($subscription, $reason, $correlationId, $recurrentOnly): array {
            $calls = [];

            HostedCheckout::query()
                ->where('subscription_id', $subscription->id)
                ->whereIn('status', [HostedCheckout::STATUS_ACTIVE, HostedCheckout::STATUS_PAID])
                ->when($recurrentOnly, fn ($q) => $q->where('kind', HostedCheckout::KIND_RECURRENT))
                ->lockForUpdate()
                ->get()
                ->each(function (HostedCheckout $checkout) use ($reason, $correlationId, &$calls): void {
                    $wasActive = $checkout->status === HostedCheckout::STATUS_ACTIVE;

                    // Avulso já pago: o dinheiro entrou — o webhook registra.
                    if (! $wasActive && ! $checkout->isRecurrent()) {
                        return;
                    }

                    $checkout->update([
                        'status'   => HostedCheckout::STATUS_SUPERSEDED,
                        'metadata' => [...(array) ($checkout->metadata ?? []), 'superseded_reason' => $reason],
                    ]);

                    if ($wasActive && $this->registry->has($checkout->gateway_code)) {
                        $gatewayCode = $checkout->gateway_code;
                        $externalId  = (string) $checkout->external_checkout_id;
                        $calls[]     = fn () => $this->registry->get($gatewayCode)->cancelHostedCheckout($externalId);
                    }

                    if ($checkout->isRecurrent() && filled($checkout->external_subscription_id)) {
                        $calls[] = $this->cancelNewRecurrence($checkout, (string) $checkout->external_subscription_id, $correlationId, $reason);
                    }
                });

            return $calls;
        });

        foreach ($calls as $call) {
            DB::afterCommit(function () use ($call): void {
                try {
                    $call();
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        if ($calls !== []) {
            $this->billingLog->log(
                level: 'info',
                message: 'Checkouts de cartão da assinatura invalidados (a assinatura mudou): abertos cancelados no gateway e recorrência nova desfeita.',
                context: ['reason' => $reason, 'recurrent_only' => $recurrentOnly],
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                gatewayCode: $subscription->gateway,
                correlationId: $correlationId,
            );
        }

        return count($calls);
    }

    /**
     * A recorrência do checkout nasce com a 1ª cobrança em next_due_date; a
     * seguinte vem um ciclo depois. Se a assinatura (paga até next_billing_at)
     * espera outra data (pagou atrasado), muda o vencimento da próxima no
     * Asaas (PUT /v3/subscriptions/{id}). Sem como mudar (no cartão a doc
     * exige a tokenização habilitada), o time é avisado para ajustar no painel.
     */
    public function alignRecurrence(string $checkoutId, string $correlationId): void
    {
        $checkout     = HostedCheckout::query()->find($checkoutId);
        $subscription = $checkout?->subscription;

        if ($checkout === null || $subscription === null || $checkout->next_due_date === null || blank($checkout->external_subscription_id)
            || $subscription->gateway_subscription_id !== $checkout->external_subscription_id || $subscription->next_billing_at === null) {
            return;
        }

        $cycle    = BillingCycle::tryFrom((string) $checkout->cycle);
        $expected = $subscription->next_billing_at->toDateString();
        $asaas    = $cycle ? CarbonImmutable::instance($checkout->next_due_date)->addMonthsNoOverflow($cycle->months())->toDateString() : null;

        if ($asaas === null || $asaas === $expected || ! $this->registry->has($checkout->gateway_code)) {
            return;
        }

        $gateway = $this->registry->get($checkout->gateway_code)->withContext(new GatewayCallContext($correlationId, (string) $subscription->entity_id));
        $updated = false;

        try {
            $updated = $gateway instanceof QueriesGatewayRecurrences
                && $gateway->updateRecurrenceNextDueDate((string) $checkout->external_subscription_id, $expected);
        } catch (Throwable) {
            $updated = false;
        }

        if ($updated) {
            $this->billingLog->log(
                level: 'info',
                message: 'Recorrência no cartão alinhada ao período da assinatura (próximo vencimento ajustado no gateway).',
                context: ['external_subscription_id' => $checkout->external_subscription_id, 'from' => $asaas, 'to' => $expected],
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                gatewayCode: $checkout->gateway_code,
                correlationId: $correlationId,
            );

            return;
        }

        $this->alerts->alert(
            gateway: $checkout->gateway_code,
            kind: 'recurrence_alignment',
            params: ['subscription' => (string) $checkout->external_subscription_id, 'from' => $asaas, 'to' => $expected, 'entity' => (string) $subscription->entity?->name],
            message: "Recorrência no cartão {$checkout->external_subscription_id}: o próximo vencimento no gateway ({$asaas}) não pôde ser alinhado ao da assinatura ({$expected}) — ajustar no painel do Asaas.",
            level: 'critical',
            throttleKey: (string) $checkout->external_subscription_id,
            throttleMinutes: 60 * 24,
            entityId: (string) $subscription->entity_id,
        );
    }

    /**
     * A fatura foi paga (por qualquer cobrança): checkouts dela ainda abertos
     * ou com recorrência criada e não adotada deixam de valer — abertos são
     * cancelados no gateway; recorrência nova (ainda não a da assinatura) é
     * desfeita. Na transação do webhook; HTTP depois do commit.
     *
     * @return list<Closure>
     */
    public function supersedeOpenCheckouts(Invoice $invoice, ?string $paidChargeId, string $correlationId): array
    {
        $deferred = [];

        HostedCheckout::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', [HostedCheckout::STATUS_ACTIVE, HostedCheckout::STATUS_PAID])
            ->lockForUpdate()
            ->get()
            ->each(function (HostedCheckout $checkout) use ($paidChargeId, $correlationId, &$deferred): void {
                if (filled($paidChargeId) && $checkout->external_payment_id === $paidChargeId) {
                    return;
                }

                $wasActive = $checkout->status === HostedCheckout::STATUS_ACTIVE;
                $checkout->update(['status' => HostedCheckout::STATUS_SUPERSEDED]);

                if ($wasActive && $this->registry->has($checkout->gateway_code)) {
                    $gatewayCode = $checkout->gateway_code;
                    $externalId  = (string) $checkout->external_checkout_id;
                    $deferred[]  = fn () => $this->registry->get($gatewayCode)->cancelHostedCheckout($externalId);
                }

                if ($checkout->isRecurrent() && filled($checkout->external_subscription_id)) {
                    $deferred[] = $this->cancelNewRecurrence($checkout, (string) $checkout->external_subscription_id, $correlationId, 'superseded');
                }
            });

        return $deferred;
    }

    /** Desfaz no gateway a recorrência criada pelo checkout que não valeu (nunca a vigente). */
    private function cancelNewRecurrence(HostedCheckout $checkout, string $externalSubscriptionId, string $correlationId, string $reason): Closure
    {
        $subscriptionId = (string) $checkout->subscription_id;
        $checkoutId     = (string) $checkout->id;

        return function () use ($subscriptionId, $externalSubscriptionId, $correlationId, $reason, $checkoutId): void {
            $subscription = Subscription::query()->find($subscriptionId);

            if ($subscription === null || $subscription->gateway_subscription_id === $externalSubscriptionId) {
                return;
            }

            $done = $this->cancellation->cancelRecurrenceById($subscription, $externalSubscriptionId, $correlationId);

            $this->billingLog->log(
                level: $done ? 'info' : 'warning',
                message: $done
                    ? 'Checkout hospedado: a recorrência criada pelo checkout foi desfeita no gateway (não valeu).'
                    : 'Checkout hospedado: a recorrência criada pelo checkout não pôde ser desfeita agora — nova tentativa agendada.',
                context: ['external_subscription_id' => $externalSubscriptionId, 'reason' => $reason, 'checkout_id' => $checkoutId],
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                gatewayCode: $subscription->gateway,
                correlationId: $correlationId,
            );
        };
    }

    /** O evento com a fatura e a assinatura do checkout (a cobrança dele pode não trazer a nossa referência). */
    private function withCheckoutReference(NormalizedWebhookEventDTO $n, HostedCheckout $checkout): NormalizedWebhookEventDTO
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
            metadata: array_filter([
                ...$n->metadata,
                'invoice_id'         => (string) $checkout->invoice_id,
                'subscription_id'    => $checkout->subscription_id ? (string) $checkout->subscription_id : null,
                'hosted_checkout_id' => (string) $checkout->id,
            ], fn ($value) => $value !== null),
            rawPayload: $n->rawPayload,
            occurredAt: $n->occurredAt,
            dueDate: $n->dueDate,
            paymentUrl: $n->paymentUrl,
        );
    }
}
