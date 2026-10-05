<?php

namespace App\Services\Billing;

use App\Enums\Billing\{BillingEventType, InvoiceStatus, PaymentStatus, SubscriptionCancelledReason};
use App\Enums\BillingCycle;
use App\Enums\{SubscriptionBillingMode, SubscriptionStatus};
use App\Events\Billing\InvoicePaid;
use App\Jobs\Billing\{RecreateGatewayRecurrenceJob, RenewSubscriptionJob};
use App\Models\Billing\{Invoice, Payment, SubscriptionChange};
use App\Models\Subscription;
use App\Support\Billing\DunningSchedule;
use Carbon\{CarbonImmutable, CarbonInterface};
use Illuminate\Support\{Carbon, Collection, Str};
use Illuminate\Support\Facades\{Auth, DB};

/**
 * Ciclo de cobrança de uma assinatura do gateway — regra única para a
 * ativação (BillingSubscriptionOrchestrator), os webhooks
 * (ProcessWebhookEventService), a renovação local (RenewSubscriptionJob) e a
 * expiração diária (SubscriptionService). Datas:
 *
 *  - next_billing_at: vencimento da próxima cobrança;
 *  - ends_at: "pago até" (= próximo vencimento depois de pago). Numa
 *    contratação ainda não paga, o fim do dia do vencimento da 1ª cobrança;
 *  - past_due_at: desde quando está em atraso (vencimento não pago);
 *  - last_payment_at: último pagamento confirmado (nulo = nunca pagou).
 *
 * Os métodos que gravam rodam na transação do chamador, que trava a empresa
 * (Entity) e depois a assinatura — mesma ordem do SubscriptionManagementService.
 * Chamadas ao gateway (stopRecurrences) ficam fora da transação.
 */
class SubscriptionCycleService
{
    public const BILLING_REASON_CREATE = 'subscription_create';

    public const BILLING_REASON_CYCLE = 'subscription_cycle';

    /** Fatura de registro de pagamento que não se aplica a nenhum período (webhook). */
    public const BILLING_REASON_UNAPPLIED = 'unapplied_payment';

    public function __construct(
        private readonly FinancialEventService $financialEvents,
        private readonly BillingLogService $billingLog,
        private readonly BillingCancellationService $cancellation,
    ) {
    }

    /** Vencimento da 1ª cobrança de uma contratação feita hoje. */
    public function firstDueDate(): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays(max(0, (int) config('billing.first_charge_due_days', 3)));
    }

    /**
     * Agendador da renovação local: assinaturas de gateway sem recorrência
     * própria, já pagas antes, com o próximo vencimento dentro da
     * antecedência (DunningSchedule::renewalWindowEnd — por dia, um dia antes
     * do lembrete da régua) e sem a cobrança desse período emitida. Gateways
     * com recorrência nativa (Asaas) não passam por aqui.
     *
     * @return int quantas renovações foram despachadas
     */
    public function dispatchDueRenewals(): int
    {
        $until      = DunningSchedule::renewalWindowEnd();
        $dispatched = 0;

        Subscription::query()
            ->dueForLocalRenewal($until)
            ->each(function (Subscription $subscription) use (&$dispatched): void {
                // Já emitida — salvo o cartão recusado com Pix/boleto aberto
                // pelo cliente: a renovação segue tentando o cartão.
                if ($subscription->hasOpenInvoiceForNextBilling() && ! $subscription->cardRetryPending($subscription->nextBillingInvoice())) {
                    return;
                }

                RenewSubscriptionJob::dispatch((string) $subscription->id);
                $dispatched++;
            });

        return $dispatched;
    }

    /**
     * Pagamento confirmado de uma cobrança da assinatura:
     *
     *  - 1º pagamento (contratação): vira ativa; vigência = vencimento pago +
     *    ciclo contratado; o que ainda estiver vigente da empresa é substituído;
     *  - renovação: ends_at = max(ends_at atual, vencimento pago + ciclo).
     *
     * Nos dois casos next_billing_at = ends_at e o atraso é zerado. Fatura já
     * paga não estende de novo (a idempotência pelo pagamento fica com o
     * chamador, que trava a assinatura).
     *
     * @return Collection<int, Subscription> substituídas — o chamador para a
     *                                       recorrência delas depois do commit
     */
    public function confirmPayment(
        Subscription $subscription,
        Invoice $invoice,
        ?Payment $payment,
        CarbonInterface $dueDate,
        string $correlationId,
        string $source,
    ): Collection {
        if ($invoice->status === InvoiceStatus::Paid) {
            $this->billingLog->log(
                level: 'warning',
                message: 'Pagamento confirmado para uma fatura já paga — o período não foi estendido de novo. Conferir duplicidade no gateway.',
                context: ['external_payment_id' => $payment?->external_payment_id, 'source' => $source],
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                gatewayCode: $subscription->gateway,
                correlationId: $correlationId,
            );

            return collect();
        }

        // Diferença proporcional do upgrade: muda o plano (não estende período).
        if ($invoice->isPlanChange()) {
            return $this->applyPaidPlanChange($subscription, $invoice, $payment, $correlationId, $source);
        }

        $paidAt = now();

        if ($payment && $payment->status !== PaymentStatus::Paid) {
            $payment->update([
                'status'     => PaymentStatus::Paid->value,
                'paid_at'    => $paidAt,
                'invoice_id' => $invoice->id,
            ]);
        }

        $invoice->update([
            'status'  => InvoiceStatus::Paid->value,
            'paid_at' => $paidAt,
        ]);

        $firstPayment = ! $subscription->hasBeenPaid();
        $wasActive    = $subscription->status === SubscriptionStatus::Active;
        // Cobrança no novo valor/ciclo de uma mudança agendada (downgrade):
        // os termos novos valem para este período (fim calculado na fatura).
        $scheduled = data_get($invoice->metadata, 'plan_change.type') === PlanChangeService::TYPE_SCHEDULED
            ? (array) data_get($invoice->metadata, 'plan_change')
            : null;
        $endsAt = $scheduled !== null && $invoice->period_end !== null
            ? $invoice->period_end->copy()->endOfDay()
            : $subscription->periodEndFrom($dueDate);

        // Nunca encurta o que já foi pago (pagamento atrasado de um período antigo).
        if ($endsAt === null || ($subscription->ends_at && $subscription->ends_at->greaterThan($endsAt))) {
            $endsAt = $subscription->ends_at?->copy();
        }

        $subscription->update([
            'status'             => SubscriptionStatus::Active,
            'billing_state'      => 'paid',
            'last_payment_at'    => $paidAt,
            'renewed_at'         => $firstPayment ? $subscription->renewed_at : $paidAt,
            'ends_at'            => $endsAt,
            'next_billing_at'    => $endsAt,
            'past_due_at'        => null,
            'last_billing_error' => null,
            'current_invoice_id' => $invoice->id,
        ]);

        $this->applyPendingCard($subscription, $invoice);

        // Renovou: upgrade pedido antes (calculado sobre o período anterior)
        // deixa de valer — fatura cancelada e cobrança cancelada no gateway.
        if (! $firstPayment) {
            $this->voidStalePlanChanges($subscription, $correlationId);
        }

        if ($scheduled !== null) {
            $this->applyScheduledTerms($subscription, $scheduled, $correlationId);
        }

        $amount   = $payment ? (float) $payment->amount : (float) $invoice->amount;
        $currency = $payment?->currency ?? $invoice->currency;

        if ($payment) {
            $this->recordEvent(BillingEventType::PaymentSucceeded, $subscription, $invoice, $payment, $amount, $currency, $correlationId, $source);
        }

        $this->recordEvent(BillingEventType::InvoicePaid, $subscription, $invoice, $payment, $amount, $currency, $correlationId, $source);

        if (! $wasActive) {
            $this->recordEvent(BillingEventType::SubscriptionActivated, $subscription, $invoice, $payment, $amount, $currency, $correlationId, $source);
        }

        // Tela do checkout (Pix/boleto) libera sozinha — só depois do commit.
        event(InvoicePaid::fromInvoice($invoice, SubscriptionStatus::Active->value, $endsAt?->toIso8601String()));

        // A emissão já substitui as vigentes anteriores; aqui só sobra o que
        // ainda estiver vigente (ex.: trial criado depois por engano).
        return $firstPayment
            ? $this->replacePrevious($subscription, $correlationId, $source)
            : collect();
    }

    /**
     * A nova assinatura assume o lugar das vigentes anteriores da empresa
     * (trial, ativa ou em atraso): elas viram canceladas ('replaced'), uma a
     * uma (observers e auditoria), e a troca entra no histórico como no
     * SubscriptionManagementService. O chamador já travou a empresa.
     *
     * Linhas antigas aguardando conciliação (inclusive a expirada que o
     * gateway segue cobrando) deixam de aguardar e voltam junto com as
     * substituídas: o chamador para a recorrência de todas depois do commit
     * (stopRecurrences).
     *
     * @param bool $alwaysRecord registra a ativação mesmo sem nada a substituir
     *
     * @return Collection<int, Subscription>
     */
    public function replacePrevious(Subscription $new, string $correlationId, string $source, bool $alwaysRecord = false): Collection
    {
        $replaced = Subscription::query()
            ->forEntity((string) $new->entity_id)
            ->inForce()
            ->whereKeyNot($new->id)
            ->where('created_at', '<=', $new->created_at)
            ->currentFirst()
            ->with('plan')
            ->lockForUpdate()
            ->get();

        // Assinatura antiga (inclusive expirada) aguardando conciliação deixa
        // de aguardar: a nova é a que vale, e a recorrência dela para.
        $cleared = Subscription::clearReconciliationOfOthers($new);

        if ($replaced->isEmpty() && $cleared->isEmpty() && ! $alwaysRecord) {
            return $cleared;
        }

        $previous = $replaced->first() ? SubscriptionManagementService::snapshot($replaced->first()) : null;

        $replaced->each(function (Subscription $old) use ($correlationId): void {
            $old->update([
                'status'           => SubscriptionStatus::Cancelled,
                'cancelled_at'     => now(),
                'cancelled_reason' => SubscriptionCancelledReason::Replaced->value,
                'billing_state'    => $old->billing_mode === SubscriptionBillingMode::Gateway
                    ? 'cancelled'
                    : $old->billing_state,
                'correlation_id' => $correlationId,
            ]);
        });

        SubscriptionChange::query()->create([
            'entity_id'             => $new->entity_id,
            'subscription_id'       => $new->id,
            'previous_plan_id'      => $previous['plan_id'] ?? null,
            'new_plan_id'           => $new->plan_id,
            'previous_gateway_code' => $replaced->first()?->gateway,
            'new_gateway_code'      => $new->gateway,
            'change_type'           => 'activation',
            'changed_by'            => Auth::id(),
            'effective_at'          => now(),
            'correlation_id'        => $correlationId,
            'metadata'              => [
                'source'                    => $source,
                'previous'                  => $previous,
                'new'                       => SubscriptionManagementService::snapshot($new),
                'replaced_subscription_ids' => $replaced->pluck('id')->all(),
                'legacy_review_closed_ids'  => $cleared->pluck('id')->all(),
            ],
        ]);

        return $replaced->merge($cleared)->unique('id')->values();
    }

    /**
     * Para a cobrança das substituídas no gateway — fora de transação (HTTP),
     * senão o cliente segue pagando as duas: a recorrência (quando há) é
     * cancelada; na renovação local, a cobrança avulsa em aberto (boleto/Pix
     * do próximo ciclo ou da contratação substituída) é cancelada quando o
     * gateway permite (BillingCancellationService::cancelOpenCharges).
     *
     * @param Collection<int, Subscription> $replaced
     */
    public function stopRecurrences(Collection $replaced, string $correlationId): void
    {
        $replaced->each(function (Subscription $old) use ($correlationId): void {
            if ($old->hasGatewayRecurrence()) {
                $this->cancellation->cancelGatewayRecurrence($old, $correlationId);

                return;
            }

            if (filled($old->gateway)) {
                $this->cancellation->cancelOpenCharges($old, correlationId: $correlationId);
            }
        });
    }

    /**
     * Fatura do período que começa no vencimento informado: a existente do
     * mesmo período ou uma nova no valor contratado (grandfathering).
     */
    public function periodInvoice(
        Subscription $subscription,
        CarbonInterface $dueDate,
        string $correlationId,
        InvoiceStatus $status = InvoiceStatus::Pending,
        string $idempotencyPrefix = 'cycle',
    ): Invoice {
        $periodStart = CarbonImmutable::instance($dueDate)->startOfDay();

        $existing = $subscription->invoices()
            ->forBillingPeriod()
            ->whereDate('period_start', $periodStart->toDateString())
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        // Mudança agendada (downgrade) que já vale neste período: a cobrança
        // sai no novo plano, valor e ciclo.
        $change = $subscription->scheduledChange();
        $terms  = $change !== null && $periodStart->greaterThanOrEqualTo(CarbonImmutable::parse($change['effective_at'])->startOfDay())
            ? $change
            : null;
        $months = $terms !== null ? (int) (BillingCycle::tryFrom((string) $terms['billing_cycle'])?->months() ?? 0) : 0;

        return Invoice::query()->create([
            'entity_id'       => $subscription->entity_id,
            'subscription_id' => $subscription->id,
            'plan_id'         => $terms['plan_id'] ?? $subscription->plan_id,
            'gateway_code'    => $subscription->gateway,
            'reference'       => 'INV-' . $periodStart->format('Ymd') . '-' . strtoupper(Str::random(8)),
            'period_start'    => $periodStart->toDateString(),
            'period_end'      => $months > 0
                ? $periodStart->addMonthsNoOverflow($months)->toDateString()
                : $subscription->periodEndFrom($periodStart)?->toDateString(),
            'due_at'          => $periodStart->endOfDay(),
            'amount'          => $terms !== null ? (float) $terms['amount'] : ($subscription->recurringAmount() ?? 0),
            'currency'        => 'BRL',
            'status'          => $status->value,
            'billing_reason'  => self::BILLING_REASON_CYCLE,
            'correlation_id'  => $correlationId,
            'idempotency_key' => "{$idempotencyPrefix}:{$subscription->id}:{$periodStart->toDateString()}",
            'metadata'        => $terms !== null ? ['plan_change' => [...$terms, 'type' => PlanChangeService::TYPE_SCHEDULED]] : null,
        ]);
    }

    /**
     * Mudanças agendadas (downgrade) cuja data chegou: o plano, o ciclo e o
     * valor da assinatura passam a ser os novos (agendador diário). A
     * cobrança daquele vencimento já saiu nos termos novos (periodInvoice).
     *
     * @return int quantas foram aplicadas
     */
    public function applyDueScheduledChanges(): int
    {
        $applied = 0;

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->whereNotNull('gateway_payload')
            ->get()
            ->filter(fn (Subscription $subscription) => ($change = $subscription->scheduledChange()) !== null
                && CarbonImmutable::parse($change['effective_at'])->lessThanOrEqualTo(now()))
            ->each(function (Subscription $subscription) use (&$applied): void {
                DB::transaction(function () use ($subscription, &$applied): void {
                    $locked = Subscription::query()->with('plan')->whereKey($subscription->id)->lockForUpdate()->first();
                    $change = $locked?->scheduledChange();

                    if ($change === null || CarbonImmutable::parse($change['effective_at'])->greaterThan(now())) {
                        return;
                    }

                    $this->applyScheduledTerms($locked, $change, (string) Str::uuid());
                    $applied++;
                });
            });

        return $applied;
    }

    /**
     * Termos da mudança agendada na assinatura: valor e ciclo já (próximas
     * cobranças), plano na data — antes dela o cliente segue com o plano que
     * pagou. Aplicado o plano, o agendamento sai e a troca entra no histórico.
     *
     * @param array<string, mixed> $change
     */
    private function applyScheduledTerms(Subscription $subscription, array $change, string $correlationId): void
    {
        $cycle   = BillingCycle::tryFrom((string) ($change['billing_cycle'] ?? ''));
        $payload = $subscription->gateway_payload ?? [];
        $due     = CarbonImmutable::parse($change['effective_at'])->lessThanOrEqualTo(now());
        $before  = SubscriptionManagementService::snapshot($subscription);

        $updates = array_filter([
            'billing_cycle'     => $cycle,
            'amount'            => isset($change['amount']) ? (float) $change['amount'] : null,
            'card_installments' => $cycle !== null && ! in_array($cycle->value, (array) config('billing.checkout.installment_cycles', ['yearly']), true) && $subscription->card_installments > 1 ? 1 : null,
        ], fn ($value) => $value !== null);

        if ($due) {
            $updates['plan_id'] = $change['plan_id'];
            unset($payload['scheduled_change']);
        } else {
            $payload['scheduled_change'] = [...$change, 'terms_applied' => true];
        }

        $subscription->update([...$updates, 'gateway_payload' => $payload]);

        if (! $due) {
            return;
        }

        SubscriptionChange::query()->create([
            'entity_id'             => $subscription->entity_id,
            'subscription_id'       => $subscription->id,
            'previous_plan_id'      => $before['plan_id'],
            'new_plan_id'           => $change['plan_id'],
            'previous_gateway_code' => $subscription->gateway,
            'new_gateway_code'      => $subscription->gateway,
            'change_type'           => 'downgrade',
            'changed_by'            => $change['requested_by'] ?? null,
            'effective_at'          => now(),
            'correlation_id'        => $correlationId,
            'metadata'              => ['source' => 'scheduled_change', 'previous' => $before, 'new' => SubscriptionManagementService::snapshot($subscription->fresh('plan')), 'scheduled' => $change],
        ]);
    }

    /**
     * Upgrade pago: o plano/ciclo/valor novos valem já, o período segue até
     * o fim calculado no pedido (mesmo ciclo: o fim do período atual; ciclo
     * mais longo: hoje + o novo ciclo). Se a assinatura mudou desde o pedido
     * (renovou, trocou, encerrou), o pagamento fica registrado sem aplicar e
     * o time é avisado (crédito/estorno).
     *
     * @return Collection<int, Subscription>
     */
    private function applyPaidPlanChange(Subscription $subscription, Invoice $invoice, ?Payment $payment, string $correlationId, string $source): Collection
    {
        $paidAt = now();

        if ($payment && $payment->status !== PaymentStatus::Paid) {
            $payment->update(['status' => PaymentStatus::Paid->value, 'paid_at' => $paidAt, 'invoice_id' => $invoice->id]);
        }

        $invoice->update(['status' => InvoiceStatus::Paid->value, 'paid_at' => $paidAt]);

        $amount   = $payment ? (float) $payment->amount : (float) $invoice->amount;
        $currency = $payment?->currency ?? $invoice->currency;

        if ($payment) {
            $this->recordEvent(BillingEventType::PaymentSucceeded, $subscription, $invoice, $payment, $amount, $currency, $correlationId, $source);
        }

        $this->recordEvent(BillingEventType::InvoicePaid, $subscription, $invoice, $payment, $amount, $currency, $correlationId, $source);

        if (! PlanChangeService::isPlanChangeValid($subscription, $invoice)) {
            $this->billingLog->log(
                level: 'critical',
                message: 'Upgrade pago, mas a assinatura mudou desde o pedido (renovou, trocou ou encerrou) — o plano NÃO foi trocado. Conferir e estornar ou aplicar à mão.',
                context: ['plan_change' => data_get($invoice->metadata, 'plan_change'), 'source' => $source],
                entityId: (string) $subscription->entity_id,
                subscription: $subscription,
                invoice: $invoice,
                payment: $payment,
                gatewayCode: $subscription->gateway,
                correlationId: $correlationId,
            );

            event(InvoicePaid::fromInvoice($invoice, (string) $subscription->status?->value, $subscription->ends_at?->toIso8601String()));

            return collect();
        }

        $change  = (array) data_get($invoice->metadata, 'plan_change');
        $cycle   = BillingCycle::from((string) $change['billing_cycle']);
        $endsAt  = Carbon::parse($change['period_end']);
        $before  = SubscriptionManagementService::snapshot($subscription);
        $payload = $subscription->gateway_payload ?? [];
        unset($payload['scheduled_change']);

        $subscription->update([
            'plan_id'           => $change['plan_id'],
            'billing_cycle'     => $cycle,
            'amount'            => (float) $change['amount'],
            'ends_at'           => $endsAt,
            'next_billing_at'   => $endsAt,
            'last_payment_at'   => $paidAt,
            'card_installments' => ! in_array($cycle->value, (array) config('billing.checkout.installment_cycles', ['yearly']), true) && $subscription->card_installments > 1 ? 1 : $subscription->card_installments,
            'gateway_payload'   => $payload,
        ]);

        $this->applyPendingCard($subscription, $invoice);

        SubscriptionChange::query()->create([
            'entity_id'             => $subscription->entity_id,
            'subscription_id'       => $subscription->id,
            'previous_plan_id'      => $before['plan_id'],
            'new_plan_id'           => $change['plan_id'],
            'previous_gateway_code' => $subscription->gateway,
            'new_gateway_code'      => $subscription->gateway,
            'change_type'           => 'upgrade',
            'changed_by'            => $change['requested_by'] ?? Auth::id(),
            'effective_at'          => $paidAt,
            'correlation_id'        => $correlationId,
            'metadata'              => [
                'source' => $source,
                // Pedido pelo manager (ou pela clínica no checkout) e a justificativa.
                'requested_via' => $change['source'] ?? 'checkout',
                'reason_text'   => $change['justification'] ?? null,
                'invoice_id'    => $invoice->id,
                'amount_now'    => (float) $invoice->amount,
                'credit'        => $change['credit'] ?? null,
                'previous'      => $before,
                'new'           => SubscriptionManagementService::snapshot($subscription->fresh('plan')),
            ],
        ]);

        // Recorrência do gateway (Asaas): refeita nos novos termos, com a
        // próxima cobrança no novo fim de período — só depois do commit.
        if ($subscription->hasGatewayRecurrence()) {
            RecreateGatewayRecurrenceJob::dispatch(
                (string) $subscription->id,
                (string) $subscription->gateway_subscription_id,
                $endsAt->toDateString(),
                $correlationId,
            )->afterCommit();
        }

        event(InvoicePaid::fromInvoice($invoice, SubscriptionStatus::Active->value, $endsAt->toIso8601String()));

        return collect();
    }

    /** Faturas de upgrade em aberto que a renovação tornou inválidas. */
    private function voidStalePlanChanges(Subscription $subscription, string $correlationId): void
    {
        $open = Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('billing_reason', Invoice::BILLING_REASON_PLAN_CHANGE)
            ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value, InvoiceStatus::Failed->value])
            ->get();

        if ($open->isEmpty()) {
            return;
        }

        $charges = $open->flatMap(fn (Invoice $invoice) => $invoice->liveChargeIds())->unique()->values()->all();
        $open->each(fn (Invoice $invoice) => $invoice->update(['status' => InvoiceStatus::Cancelled->value]));
        Payment::query()->whereIn('invoice_id', $open->pluck('id'))->where('status', PaymentStatus::Pending->value)->update(['status' => PaymentStatus::Cancelled->value]);

        $gatewayCode = (string) $subscription->gateway;

        DB::afterCommit(function () use ($charges, $gatewayCode, $subscription, $correlationId): void {
            $registry = app(GatewayRegistry::class);

            if ($charges === [] || ! $registry->has($gatewayCode)) {
                return;
            }

            foreach ($charges as $charge) {
                if (! $registry->get($gatewayCode)->cancelCharge($charge)) {
                    $this->billingLog->log(
                        level: 'warning',
                        message: 'Renovação paga: a cobrança do upgrade pedido antes não pôde ser cancelada no gateway — cancelar manualmente.',
                        context: ['external_charge_id' => $charge],
                        entityId: (string) $subscription->entity_id,
                        subscription: $subscription,
                        gatewayCode: $gatewayCode,
                        correlationId: $correlationId,
                    );
                }
            }
        });
    }

    /**
     * Cartão de um pagamento que precisou de confirmação (3DS/análise): só
     * vira o cartão da renovação quando o pagamento é confirmado.
     */
    private function applyPendingCard(Subscription $subscription, Invoice $invoice): void
    {
        $card = data_get($invoice->metadata, 'checkout.pending_card');

        if (! is_array($card) || blank($card['id'] ?? null)) {
            return;
        }

        $payload         = $subscription->gateway_payload ?? [];
        $payload['card'] = (array) ($card['references'] ?? []);

        $subscription->update([
            'payment_method'    => 'credit_card',
            'gateway_card_id'   => $card['id'],
            'card_brand'        => $card['brand'] ?? null,
            'card_last4'        => $card['last4'] ?? null,
            'card_installments' => max(1, (int) ($card['installments'] ?? 1)),
            'gateway_payload'   => $payload,
        ]);

        $metadata = (array) ($invoice->metadata ?? []);
        unset($metadata['checkout']['pending_card']);
        $invoice->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * Cobrança de quem já pagou antes ficou sem pagamento: entra em atraso
     * desde o vencimento (o mais antigo, se já estava em atraso). O acesso
     * a partir daí é da régua de cobrança.
     */
    public function markPastDue(
        Subscription $subscription,
        CarbonInterface $since,
        string $billingState,
        ?string $error,
        string $correlationId,
        string $source,
        ?Invoice $invoice = null,
    ): void {
        $since         = Carbon::instance($since);
        $becamePastDue = $subscription->status !== SubscriptionStatus::PastDue;
        // Já em atraso: o relógio da régua não muda. Reemissão (vencimento
        // novo) não reinicia o atraso, e evento atrasado de cobrança antiga
        // não o antecipa — a linha legada conciliada começa a régua na
        // conciliação (past_due_at = agora) e não pode ser bloqueada sem aviso.
        $pastDueAt = ! $becamePastDue && $subscription->past_due_at
            ? $subscription->past_due_at
            : $since;

        $subscription->update([
            'status'             => SubscriptionStatus::PastDue,
            'billing_state'      => $billingState,
            'past_due_at'        => $pastDueAt,
            'last_billing_error' => $error,
        ]);

        if ($becamePastDue) {
            $this->recordEvent(BillingEventType::SubscriptionPastDue, $subscription, $invoice, null, null, null, $correlationId, $source);
        }
    }

    private function recordEvent(
        BillingEventType $type,
        Subscription $subscription,
        ?Invoice $invoice,
        ?Payment $payment,
        ?float $amount,
        ?string $currency,
        string $correlationId,
        string $source,
    ): void {
        $this->financialEvents->record(
            eventType: $type,
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            invoice: $invoice,
            payment: $payment,
            amount: $amount,
            currency: $currency,
            correlationId: $correlationId,
            source: $source,
        );
    }
}
