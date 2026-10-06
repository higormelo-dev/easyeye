<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Domains\AI\Models\AiCreditPurchase;
use App\Domains\AI\Services\AiCreditPurchaseService;
use App\DTOs\Billing\{GatewayCallContext, NormalizedWebhookEventDTO, RefundRequestDTO, RefundResultDTO};
use App\Enums\Billing\{BillingEventType, PaymentStatus};
use App\Exceptions\Billing\{BillingException, GatewayIntegrationException};
use App\Jobs\Billing\SendBillingRefundJob;
use App\Models\Billing\{BillingRefund, Invoice, Payment};
use App\Models\{Subscription, User};
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;
use Throwable;

/**
 * Estorno de pagamento pedido pelo manager (Assinaturas → detalhe → faturas
 * → Estornar) e o registro dos estornos parciais vindos do gateway.
 *
 *  - Pedido: total ou parcial (o gateway precisa aceitar parcial), com
 *    justificativa; fica "solicitado" até o gateway confirmar. Asaas:
 *    concluído só com refunds[].status DONE ou o webhook PAYMENT_REFUNDED /
 *    PAYMENT_PARTIALLY_REFUNDED (https://docs.asaas.com/docs/estornos);
 *    boleto devolve o link em que o pagador informa a conta
 *    (https://docs.asaas.com/reference/estornar-boleto). Estorno "total"
 *    depois de um parcial vai sempre com o valor restante.
 *  - Um pedido por vez por pagamento (trava + lockForUpdate): sem estorno
 *    concorrente em dobro.
 *  - Sem resposta definitiva (timeout, conexão, 5xx): o pedido fica
 *    "solicitado" e inconclusivo — conferido no gateway (refundStatus) antes
 *    de liberar outro; 429: enviado depois por job com a MESMA chave de
 *    idempotência (SendBillingRefundJob).
 *  - Parado em "solicitado": a ação "Conferir" do manager e o
 *    billing:check-refunds conferem no gateway; negado/cancelado ou
 *    inexistente lá libera um novo pedido. PAYMENT_REFUND_DENIED marca
 *    negado; PAYMENT_REFUND_IN_PROGRESS segue solicitado.
 *  - Efeito financeiro: SEMPRE pelo mesmo caminho do webhook
 *    (ProcessWebhookEventService) — estorno total segue o fluxo de estorno
 *    da fatura; parcial só registra o valor devolvido (refunded_amount,
 *    evento financeiro e alerta), sem mudar o acesso. Pacote de IA: créditos
 *    proporcionais revertidos (arredondado para baixo), sem saldo negativo.
 *  - Pagamento em duplicidade (status duplicate — fatura já quitada por
 *    outra cobrança) também é estornável; o estorno dele não mexe na fatura.
 */
class RefundService
{
    /** Pagamento que o manager pode estornar: o que quitou e o em duplicidade. */
    private const REFUNDABLE_STATUSES = [PaymentStatus::Paid, PaymentStatus::Duplicate];

    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly BillingLogService $billingLog,
        private readonly FinancialEventService $financialEvents,
        private readonly AiCreditPurchaseService $purchases,
        private readonly GatewayAlertService $alerts,
    ) {
    }

    /**
     * O que a tela do manager pode oferecer para o pagamento: estorno (total
     * e/ou parcial), quanto ainda pode ser devolvido e o pedido em andamento.
     *
     * @return array{can_refund: bool, partial: bool, remaining: float, refunded: float, pending: ?array<string, mixed>}
     */
    public function options(Payment $payment): array
    {
        $gateway = filled($payment->gateway_code) && $this->registry->has((string) $payment->gateway_code)
            ? $this->registry->get((string) $payment->gateway_code)
            : null;
        $refunded  = round((float) $payment->refunded_amount, 2);
        $remaining = max(0.0, round((float) $payment->amount - $refunded, 2));
        $pending   = $payment->relationLoaded('refunds')
            ? $payment->refunds->firstWhere('status', BillingRefund::STATUS_REQUESTED)
            : BillingRefund::query()->where('payment_id', $payment->id)->where('status', BillingRefund::STATUS_REQUESTED)->latest()->first();

        $can = $gateway !== null
            && $gateway->supportsRefund()
            && in_array($payment->status, self::REFUNDABLE_STATUSES, true)
            && filled($payment->external_payment_id)
            && $remaining > 0.004
            && $pending === null;

        return [
            'can_refund' => $can,
            'partial'    => $can && $gateway->supportsPartialRefund(),
            'remaining'  => $remaining,
            'refunded'   => $refunded,
            'pending'    => $pending ? $this->row($pending) : null,
        ];
    }

    /**
     * Pede o estorno ao gateway. $amount nulo = o que ainda falta devolver
     * (total, se nada foi estornado antes). Recusa definitiva da API: o
     * pedido fica como falho e a mensagem volta para o manager. Sem resposta
     * definitiva: "solicitado" inconclusivo (conferir). 429: enviado depois.
     *
     * @throws BillingException validação (valor, gateway sem estorno, pedido em andamento) ou recusa
     */
    public function request(Payment $payment, ?float $amount, string $reason, ?User $by): BillingRefund
    {
        // Dois pedidos ao mesmo tempo (duplo clique, duas abas): só um passa.
        $lock = Cache::lock('billing:refund:payment:' . $payment->id, 120);

        if (! $lock->get()) {
            throw new BillingException(__('manager_subscriptions.refund.errors.pending'));
        }

        try {
            $refund = DB::transaction(function () use ($payment, $amount, $reason, $by): BillingRefund {
                $locked  = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $options = $this->options($locked);

                if (! $options['can_refund']) {
                    throw new BillingException(__($options['pending'] ? 'manager_subscriptions.refund.errors.pending' : 'manager_subscriptions.refund.errors.not_refundable'));
                }

                $remaining = $options['remaining'];
                $amount    = $amount !== null ? round($amount, 2) : null;

                if ($amount !== null && ($amount <= 0 || $amount > $remaining + 0.004)) {
                    throw new BillingException(__('manager_subscriptions.refund.errors.amount_invalid', ['max' => number_format($remaining, 2, ',', '.')]));
                }

                // Tudo o que falta e nada devolvido antes = estorno total.
                $partial = $amount !== null && ! ($options['refunded'] <= 0.004 && abs($amount - $remaining) < 0.005);

                if ($partial && ! $options['partial']) {
                    throw new BillingException(__('manager_subscriptions.refund.errors.partial_unsupported'));
                }

                return BillingRefund::query()->create([
                    'entity_id'           => $locked->entity_id,
                    'payment_id'          => $locked->id,
                    'invoice_id'          => $locked->invoice_id,
                    'subscription_id'     => $locked->subscription_id,
                    'gateway_code'        => (string) $locked->gateway_code,
                    'external_payment_id' => (string) $locked->external_payment_id,
                    'amount'              => $partial ? $amount : $remaining,
                    'partial'             => $partial,
                    'status'              => BillingRefund::STATUS_REQUESTED,
                    'reason'              => $reason,
                    'requested_by'        => $by?->id,
                    'requested_at'        => now(),
                    // Uma chave por pedido: o reenvio do MESMO pedido (job do
                    // 429) repete a chave — o gateway com idempotência
                    // (Mercado Pago) devolve o mesmo estorno.
                    'idempotency_key' => 'refund:' . $locked->id . ':' . Str::uuid(),
                ]);
            });

            return $this->send($refund);
        } finally {
            $lock->release();
        }
    }

    /**
     * Envia ao gateway o pedido já gravado (pelo request() ou, depois de um
     * 429, pelo SendBillingRefundJob com a mesma chave).
     *
     * @param bool $fromJob true: o 429 volta para o job (release) e a recusa
     *                      não lança (ninguém esperando a resposta)
     *
     * @throws BillingException            recusa definitiva do gateway (só fora do job)
     * @throws GatewayIntegrationException 429 dentro do job
     */
    public function send(BillingRefund $refund, bool $fromJob = false): BillingRefund
    {
        $payment = Payment::query()->find($refund->payment_id);

        if ($payment === null || $refund->status !== BillingRefund::STATUS_REQUESTED) {
            return $refund;
        }

        $invoice = $payment->invoice_id ? Invoice::query()->find($payment->invoice_id) : null;
        $gateway = $this->registry->get((string) $refund->gateway_code)
            ->withContext(new GatewayCallContext((string) Str::uuid(), (string) $refund->entity_id));
        // Valor sempre que o pedido não é o pagamento inteiro: o "total"
        // depois de um parcial é o restante (sem value, o Asaas estornaria o
        // valor integral — https://docs.asaas.com/reference/estornar-cobranca).
        $sendAmount = $refund->partial || abs((float) $refund->amount - (float) $payment->amount) >= 0.005
            ? round((float) $refund->amount, 2)
            : null;

        try {
            $result = $gateway->refund(new RefundRequestDTO(
                externalPaymentId: (string) $refund->external_payment_id,
                amount: $sendAmount,
                description: mb_substr((string) $refund->reason, 0, 500),
                idempotencyKey: (string) $refund->idempotency_key,
            ));
        } catch (GatewayIntegrationException $e) {
            if ($e->isRateLimit()) {
                return $this->queue($refund, $payment, $invoice, $e, $fromJob);
            }

            $result = $e->isInconclusive()
                ? RefundResultDTO::inconclusive($e->getMessage(), $e->httpStatus())
                : RefundResultDTO::failed(__('manager_subscriptions.refund.errors.gateway'), $e->httpStatus());
        } catch (Throwable $e) {
            report($e);
            // Erro inesperado no meio do envio: pode ter saído — conferir.
            $result = RefundResultDTO::inconclusive($e->getMessage());
        }

        if ($result->inconclusive) {
            $refund->update([
                'gateway_state' => BillingRefund::STATE_INCONCLUSIVE,
                'error_message' => mb_substr((string) $result->errorMessage, 0, 2000),
            ]);

            $this->billingLog->log(
                level: 'warning',
                message: 'Estorno pedido pelo manager sem resposta definitiva do gateway (timeout/5xx) — fica solicitado e é conferido no gateway antes de liberar outro pedido.',
                context: ['refund_id' => $refund->id, 'amount' => (float) $refund->amount, 'error' => mb_substr((string) $result->errorMessage, 0, 500)],
                entityId: (string) $refund->entity_id,
                invoice: $invoice,
                payment: $payment,
                gatewayCode: $refund->gateway_code,
            );

            return $refund->fresh();
        }

        if (! $result->success) {
            $refund->update([
                'status'           => BillingRefund::STATUS_FAILED,
                'error_message'    => mb_substr((string) $result->errorMessage, 0, 2000),
                'gateway_response' => $result->rawResponse,
            ]);

            $this->billingLog->log(
                level: 'warning',
                message: 'Estorno pedido pelo manager foi recusado pelo gateway.',
                context: ['refund_id' => $refund->id, 'amount' => (float) $refund->amount, 'partial' => (bool) $refund->partial, 'error' => mb_substr((string) $result->errorMessage, 0, 500)],
                entityId: (string) $refund->entity_id,
                invoice: $invoice,
                payment: $payment,
                gatewayCode: $refund->gateway_code,
            );

            if ($fromJob) {
                return $refund->fresh();
            }

            throw new BillingException(__('manager_subscriptions.refund.errors.refused', ['error' => mb_substr((string) $result->errorMessage, 0, 300)]));
        }

        $refund->update([
            'gateway_state'      => BillingRefund::STATE_SENT,
            'error_message'      => null,
            'external_refund_id' => $result->externalRefundId,
            'request_url'        => $result->requestUrl,
            'gateway_response'   => $result->rawResponse,
        ]);

        $this->financialEvents->record(
            eventType: BillingEventType::RefundRequested,
            entityId: (string) $refund->entity_id,
            subscription: $payment->subscription_id ? Subscription::query()->find($payment->subscription_id) : null,
            invoice: $invoice,
            payment: $payment,
            amount: (float) $refund->amount,
            currency: $payment->currency,
            metadata: ['refund_id' => (string) $refund->id, 'partial' => (bool) $refund->partial, 'requested_by' => $refund->requested_by],
            source: 'manager',
        );

        $this->billingLog->log(
            level: 'info',
            message: $refund->partial ? 'Estorno parcial pedido pelo manager (aguardando confirmação do gateway).' : 'Estorno total pedido pelo manager (aguardando confirmação do gateway).',
            context: ['refund_id' => $refund->id, 'amount' => (float) $refund->amount, 'status' => $result->status, 'request_url' => $result->requestUrl !== null],
            entityId: (string) $refund->entity_id,
            invoice: $invoice,
            payment: $payment,
            gatewayCode: $refund->gateway_code,
        );

        // O gateway já confirmou na resposta: aplica pelo mesmo caminho do
        // webhook (idempotente — o webhook que chega depois não repete).
        if ($result->status === RefundResultDTO::STATUS_DONE) {
            $this->confirm($refund->fresh(), $payment->fresh());
        }

        return $refund->fresh();
    }

    /**
     * 429 (nada foi feito no gateway): o pedido fica na fila e o job envia
     * depois do tempo pedido, com a mesma chave.
     */
    private function queue(BillingRefund $refund, Payment $payment, ?Invoice $invoice, GatewayIntegrationException $e, bool $fromJob): BillingRefund
    {
        $refund->update(['gateway_state' => BillingRefund::STATE_QUEUED, 'error_message' => mb_substr($e->getMessage(), 0, 2000)]);

        if ($fromJob) {
            throw $e;
        }

        SendBillingRefundJob::dispatch((string) $refund->id)->delay(now()->addSeconds(max(1, (int) ($e->retryAfter() ?? 60))));

        $this->billingLog->log(
            level: 'warning',
            message: 'Estorno pedido pelo manager: o gateway pediu para esperar (429) — enviado automaticamente depois, com a mesma chave.',
            context: ['refund_id' => $refund->id, 'retry_after' => $e->retryAfter()],
            entityId: (string) $refund->entity_id,
            invoice: $invoice,
            payment: $payment,
            gatewayCode: $refund->gateway_code,
        );

        return $refund->fresh();
    }

    /** Estorno confirmado pelo gateway (resposta da API ou conferência) → mesmo caminho do webhook. */
    private function confirm(BillingRefund $refund, Payment $payment): void
    {
        $full = ! $refund->partial && round((float) $payment->refunded_amount + (float) $refund->amount, 2) >= round((float) $payment->amount, 2) - 0.004;

        $this->applyConfirmed($payment, ! $full, $full ? (float) $payment->amount : round((float) $payment->refunded_amount + (float) $refund->amount, 2));

        // O webhook aplica depois se a aplicação falhou; o pedido em si está confirmado.
        $fresh = $refund->fresh();

        if ($fresh !== null && $fresh->status === BillingRefund::STATUS_REQUESTED) {
            $fresh->update(['status' => BillingRefund::STATUS_DONE, 'completed_at' => now()]);
        }
    }

    /** Estorno confirmado na resposta da API → mesmo caminho do webhook. */
    private function applyConfirmed(Payment $payment, bool $partial, float $refundedTotal): void
    {
        try {
            app(ProcessWebhookEventService::class)->applyNormalized(new NormalizedWebhookEventDTO(
                gatewayCode: (string) $payment->gateway_code,
                eventType: $partial ? 'partially_refunded' : 'refunded',
                externalEventId: null,
                externalSubscriptionId: null,
                externalPaymentId: (string) $payment->external_payment_id,
                externalInvoiceId: null,
                status: $partial ? 'paid' : 'refunded',
                amount: (float) $payment->amount,
                currency: $payment->currency,
                metadata: ['refunded_total' => round($refundedTotal, 2), 'source' => 'manager_refund'],
                rawPayload: [],
                occurredAt: now()->toIso8601String(),
            ), 'manager_refund');
        } catch (Throwable $e) {
            // O webhook do gateway aplica depois.
            report($e);
        }
    }

    /**
     * Confere no gateway um pedido parado em "solicitado" (ação "Conferir"
     * do manager e billing:check-refunds):
     *  - devolvido → aplicado (mesmo caminho do webhook) e concluído;
     *  - em andamento (ex.: boleto aguardando a conta do pagador) → segue
     *    solicitado, com a situação anotada;
     *  - negado/cancelado → falho; inexistente lá (nunca chegou) →
     *    cancelado: libera um novo pedido;
     *  - ainda não enviado (429) → cancelado (nada saiu);
     *  - consulta falhou ou gateway sem conferência → nada muda.
     *
     * @return 'done'|'pending'|'failed'|'cancelled'|'unavailable'|'unverifiable'|'not_pending'
     */
    public function check(BillingRefund $refund): string
    {
        $refund = BillingRefund::query()->find($refund->id) ?? $refund;

        if ($refund->status !== BillingRefund::STATUS_REQUESTED) {
            return 'not_pending';
        }

        if ($refund->gateway_state === BillingRefund::STATE_QUEUED) {
            $refund->update(['status' => BillingRefund::STATUS_CANCELLED, 'last_checked_at' => now(), 'check_note' => 'not_sent', 'completed_at' => now()]);
            $this->logCheck($refund, 'cancelled');

            return 'cancelled';
        }

        $payment = Payment::query()->find($refund->payment_id);

        if ($payment === null || ! $this->registry->has((string) $refund->gateway_code)) {
            return 'unverifiable';
        }

        $gateway = $this->registry->get((string) $refund->gateway_code)
            ->withContext(new GatewayCallContext((string) Str::uuid(), (string) $refund->entity_id));

        try {
            $result = $gateway->refundStatus(
                (string) $refund->external_payment_id,
                (float) $refund->amount,
                $refund->external_refund_id,
                ($refund->requested_at ?? $refund->created_at)->toIso8601String(),
            );
        } catch (Throwable $e) {
            $refund->update(['last_checked_at' => now()]);

            return 'unavailable';
        }

        if ($result === null) {
            $refund->update(['last_checked_at' => now()]);

            return 'unverifiable';
        }

        $outcome = match ($result->status) {
            RefundResultDTO::STATUS_DONE      => 'done',
            RefundResultDTO::STATUS_REQUESTED => 'pending',
            RefundResultDTO::STATUS_FAILED    => 'failed',
            default                           => 'cancelled',
        };

        match ($outcome) {
            'done' => (function () use ($refund, $payment, $result): void {
                $refund->update(['last_checked_at' => now(), 'check_note' => null, 'gateway_state' => BillingRefund::STATE_SENT, 'external_refund_id' => $refund->external_refund_id ?? $result->externalRefundId]);
                $this->confirm($refund->fresh(), $payment);
            })(),
            'pending' => $refund->update(['last_checked_at' => now(), 'check_note' => $result->note ?? 'in_progress', 'gateway_state' => BillingRefund::STATE_SENT]),
            'failed'  => $refund->update(['status' => BillingRefund::STATUS_FAILED, 'last_checked_at' => now(), 'check_note' => $result->note ?? 'denied', 'error_message' => __('manager_subscriptions.refund.errors.denied'), 'completed_at' => now()]),
            default   => $refund->update(['status' => BillingRefund::STATUS_CANCELLED, 'last_checked_at' => now(), 'check_note' => 'not_found', 'error_message' => __('manager_subscriptions.refund.errors.not_found'), 'completed_at' => now()]),
        };

        $this->logCheck($refund, $outcome);

        return $outcome;
    }

    /**
     * Pedidos parados em "solicitado" conferidos sozinhos (billing:check-refunds):
     * os inconclusivos depois de alguns minutos, os enfileirados esquecidos
     * e qualquer um com mais de expire_days (padrão 30) — nunca libera sem
     * conferir; em andamento (boleto aguardando a conta do pagador) segue.
     *
     * @return array<string, int> desfechos
     */
    public function checkStale(?int $limit = null): array
    {
        $expireDays = max(1, (int) config('billing.refunds.expire_days', 30));
        $stats      = [];

        BillingRefund::query()
            ->where('status', BillingRefund::STATUS_REQUESTED)
            ->where(fn ($q) => $q
                ->where(fn ($i) => $i->where('gateway_state', BillingRefund::STATE_INCONCLUSIVE)->where('requested_at', '<=', now()->subMinutes(10)))
                ->orWhere(fn ($i) => $i->where('gateway_state', BillingRefund::STATE_QUEUED)->where('requested_at', '<=', now()->subDay()))
                ->orWhere('requested_at', '<=', now()->subDays($expireDays)))
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subHours(6)))
            ->orderBy('requested_at')
            ->limit(max(1, $limit ?? (int) config('billing.refunds.check_per_run', 50)))
            ->get()
            ->each(function (BillingRefund $refund) use (&$stats): void {
                $outcome         = $this->check($refund);
                $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;

                if (in_array($outcome, ['unavailable', 'unverifiable'], true)) {
                    $this->alerts->alert(
                        gateway: (string) $refund->gateway_code,
                        kind: 'refund_unconfirmed',
                        params: ['amount' => number_format((float) $refund->amount, 2, ',', '.'), 'payment' => (string) $refund->external_payment_id],
                        message: "Estorno {$refund->id} segue \"solicitado\" e não pôde ser conferido no gateway — conferir no painel do gateway.",
                        level: 'critical',
                        throttleKey: (string) $refund->id,
                        throttleMinutes: 60 * 24,
                        entityId: (string) $refund->entity_id,
                    );
                }
            });

        return $stats;
    }

    /**
     * PAYMENT_REFUND_DENIED (boleto) / PAYMENT_REFUND_IN_PROGRESS do Asaas
     * (https://docs.asaas.com/docs/webhook-para-cobrancas): negado → os
     * pedidos em aberto do pagamento viram falhos (libera um novo); em
     * andamento → seguem solicitados, com a situação anotada.
     */
    public function applyGatewayRefundStatus(?Payment $payment, string $eventType, NormalizedWebhookEventDTO $n, string $correlationId): string
    {
        if ($payment === null) {
            return 'ignored';
        }

        $denied = $eventType === 'refund_denied';
        $rows   = BillingRefund::query()
            ->where('payment_id', $payment->id)
            ->where('status', BillingRefund::STATUS_REQUESTED)
            ->lockForUpdate()
            ->get();

        $rows->each(fn (BillingRefund $refund) => $refund->update($denied
            ? ['status' => BillingRefund::STATUS_FAILED, 'check_note' => 'denied', 'error_message' => __('manager_subscriptions.refund.errors.denied'), 'completed_at' => now()]
            : ['check_note' => 'in_progress', 'gateway_state' => BillingRefund::STATE_SENT]));

        if ($denied) {
            $this->billingLog->log(
                level: 'warning',
                message: 'O gateway negou o estorno do pagamento — o pedido do manager ficou como recusado (um novo pode ser feito).',
                context: ['refund_ids' => $rows->pluck('id')->map(fn ($id) => (string) $id)->all(), 'external_payment_id' => $n->externalPaymentId],
                entityId: (string) $payment->entity_id,
                payment: $payment,
                gatewayCode: $n->gatewayCode,
                correlationId: $correlationId,
            );
        }

        return $denied ? 'refund_denied' : 'refund_in_progress';
    }

    /**
     * Estorno PARCIAL confirmado (webhook ou resposta da API), na transação
     * do webhook: registra o total devolvido no pagamento e na fatura, o
     * evento financeiro e o alerta. A fatura segue paga e o acesso não muda.
     * Pacote de IA: créditos proporcionais revertidos. Idempotente pelo total.
     * Pagamento em duplicidade: só o pagamento (a fatura e os créditos são
     * da cobrança que quitou).
     *
     * @return string outcome
     */
    public function applyPartialRefund(Invoice $invoice, ?Payment $payment, ?Subscription $subscription, NormalizedWebhookEventDTO $n, string $correlationId): string
    {
        $total = $n->metadata['refunded_total'] ?? null;

        if ($payment === null || ! is_numeric($total)) {
            $this->billingLog->log(
                level: 'critical',
                message: 'Estorno parcial recebido do gateway sem o valor devolvido ou sem o pagamento registrado — conferir no gateway e registrar à mão.',
                context: ['external_payment_id' => $n->externalPaymentId, 'refunded_total' => $total],
                entityId: (string) $invoice->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                gatewayCode: $n->gatewayCode,
                correlationId: $correlationId,
            );

            return 'alert_partial_refund_unknown';
        }

        $total = round(min((float) $total, (float) $payment->amount), 2);
        $delta = round($total - (float) $payment->refunded_amount, 2);

        if ($delta <= 0.004) {
            $this->syncRequested($payment, $total, false);

            return 'duplicate';
        }

        $duplicate = $payment->status === PaymentStatus::Duplicate;

        $payment->update(['refunded_amount' => $total]);

        if (! $duplicate) {
            $invoice->update(['refunded_amount' => round(min((float) $invoice->amount, (float) $invoice->refunded_amount + $delta), 2)]);
        }

        $this->financialEvents->record(
            eventType: BillingEventType::PaymentPartiallyRefunded,
            entityId: (string) $invoice->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            amount: $delta,
            currency: $payment->currency,
            metadata: ['refunded_total' => $total, 'paid_amount' => (float) $payment->amount, 'source' => $n->metadata['source'] ?? 'webhook', 'duplicate_payment' => $duplicate ?: null],
            correlationId: $correlationId,
            source: 'webhook',
        );

        $credits = null;

        if (! $duplicate && $invoice->isAiCreditPack() && ($purchase = $this->purchaseOf($invoice)) !== null) {
            $credits = $this->purchases->revokeProportionallyFromGateway($purchase, $total, (float) $payment->amount, 'gateway_partial_refund');

            if ($credits['shortfall'] > 0) {
                $this->alerts->alert(
                    gateway: (string) $n->gatewayCode,
                    kind: 'refund_credits',
                    params: ['entity' => (string) $invoice->entity?->name, 'reference' => (string) $invoice->reference, 'shortfall' => $credits['shortfall'], 'revoked' => $credits['revoked']],
                    message: "Estorno parcial do pacote de IA {$invoice->reference}: {$credits['shortfall']} créditos não puderam ser retirados (a clínica já usou) — conferir.",
                    level: 'critical',
                    throttleKey: (string) $invoice->id . ':' . $total,
                    throttleMinutes: 60 * 24 * 30,
                    entityId: (string) $invoice->entity_id,
                );
            }
        }

        $this->billingLog->log(
            level: 'warning',
            message: $duplicate
                ? 'Estorno parcial do pagamento em duplicidade confirmado pelo gateway: valor devolvido registrado (a fatura segue paga pela outra cobrança).'
                : 'Estorno parcial confirmado pelo gateway: valor devolvido registrado; a fatura segue paga e o acesso não muda.',
            context: ['refunded_now' => $delta, 'refunded_total' => $total, 'paid_amount' => (float) $payment->amount, 'ai_credits' => $credits],
            entityId: (string) $invoice->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            gatewayCode: $n->gatewayCode,
            correlationId: $correlationId,
        );

        $this->syncRequested($payment, $total, false);

        return 'partially_refunded';
    }

    /**
     * Pedidos de estorno do manager cobertos pelo que o gateway já devolveu
     * viram concluídos (estorno total: todos). Na transação do webhook.
     */
    public function syncRequested(Payment $payment, float $refundedTotal, bool $full): void
    {
        $done = (float) BillingRefund::query()
            ->where('payment_id', $payment->id)
            ->where('status', BillingRefund::STATUS_DONE)
            ->sum('amount');

        BillingRefund::query()
            ->where('payment_id', $payment->id)
            ->where('status', BillingRefund::STATUS_REQUESTED)
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get()
            ->each(function (BillingRefund $refund) use (&$done, $refundedTotal, $full): void {
                if (! $full && $done + (float) $refund->amount > $refundedTotal + 0.004) {
                    return;
                }

                $done += (float) $refund->amount;
                $refund->update(['status' => BillingRefund::STATUS_DONE, 'completed_at' => now()]);
            });
    }

    /**
     * Estorno total confirmado: o pagamento inteiro foi devolvido.
     *
     * @param bool $touchInvoice false no pagamento em duplicidade (a fatura é da cobrança que quitou)
     */
    public function markFullyRefunded(Payment $payment, bool $touchInvoice = true): void
    {
        DB::transaction(function () use ($payment, $touchInvoice): void {
            $payment->update(['refunded_amount' => $payment->amount]);

            if ($touchInvoice && $payment->invoice_id) {
                Invoice::query()->whereKey($payment->invoice_id)->update(['refunded_amount' => DB::raw('amount')]);
            }

            $this->syncRequested($payment, (float) $payment->amount, true);
        });
    }

    /** @return array<string, mixed> */
    public function row(BillingRefund $refund): array
    {
        return [
            'id'              => (string) $refund->id,
            'status'          => $refund->status,
            'gateway_state'   => $refund->gateway_state,
            'check_note'      => $refund->check_note,
            'amount'          => (float) $refund->amount,
            'partial'         => (bool) $refund->partial,
            'reason'          => $refund->reason,
            'request_url'     => $refund->request_url,
            'error'           => $refund->error_message,
            'requested_at'    => $refund->requested_at?->toIso8601String(),
            'completed_at'    => $refund->completed_at?->toIso8601String(),
            'last_checked_at' => $refund->last_checked_at?->toIso8601String(),
            'requested_by'    => $refund->requester?->name,
            // "Conferir no gateway": só o pedido ainda em aberto.
            'can_check' => $refund->status === BillingRefund::STATUS_REQUESTED,
        ];
    }

    private function logCheck(BillingRefund $refund, string $outcome): void
    {
        $this->billingLog->log(
            level: in_array($outcome, ['done', 'pending'], true) ? 'info' : 'warning',
            message: 'Pedido de estorno conferido no gateway.',
            context: ['refund_id' => $refund->id, 'outcome' => $outcome, 'note' => $refund->fresh()?->check_note],
            entityId: (string) $refund->entity_id,
            gatewayCode: $refund->gateway_code,
        );
    }

    private function purchaseOf(Invoice $invoice): ?AiCreditPurchase
    {
        $id = data_get($invoice->metadata, 'ai_credit_purchase_id');

        return is_string($id) && Str::isUuid($id) ? AiCreditPurchase::query()->find($id) : null;
    }
}
