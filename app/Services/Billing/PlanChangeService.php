<?php

namespace App\Services\Billing;

use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Enums\{BillingCycle, SubscriptionBillingMode, SubscriptionStatus};
use App\Exceptions\Billing\CheckoutException;
use App\Jobs\Billing\{RecreateGatewayRecurrenceJob, RenewSubscriptionJob};
use App\Models\Billing\{Invoice, Payment, SubscriptionChange};
use App\Models\{Entity, Plan, Subscription};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Support\Str;

/**
 * Troca de plano/ciclo de quem já tem um plano PAGO e vigente (decisão do
 * dono do produto):
 *
 *  - UPGRADE (valor mensal maior, ciclo igual ou mais longo): muda NA HORA,
 *    cobrando só a diferença proporcional ao período restante — no mesmo
 *    ciclo, (novo − atual) × fração restante, e o fim do período não muda;
 *    num ciclo mais longo, o novo ciclo começa hoje e o crédito do que
 *    sobrou do atual é descontado. A cobrança é uma fatura própria
 *    (billing_reason plan_change) da assinatura vigente; o plano só muda
 *    quando ela é paga (SubscriptionCycleService::confirmPayment). Não paga,
 *    a assinatura atual segue intacta.
 *  - DOWNGRADE (valor mensal menor, ou ciclo mais curto): nada é cobrado
 *    agora; a mudança fica agendada para o fim do período já pago
 *    (gateway_payload.scheduled_change). A cobrança daquele vencimento já
 *    sai no novo valor e ciclo (SubscriptionCycleService::periodInvoice) e o
 *    plano troca na data (applyDueScheduledChanges, ou no pagamento dessa
 *    cobrança, se for depois da data).
 *
 * Em nenhum caso a assinatura paga é cancelada antes do novo ser pago. Toda
 * troca entra no histórico (SubscriptionChange), com a origem (checkout da
 * clínica ou manager), quem pediu e a justificativa do manager. O manager
 * também pode desfazer uma mudança agendada antes da data
 * (cancelScheduled).
 */
class PlanChangeService
{
    public const TYPE_UPGRADE = 'upgrade';

    public const TYPE_SCHEDULED = 'scheduled';

    public function __construct(
        private readonly BillingLogService $billingLog,
        private readonly GatewayRegistry $registry,
    ) {
    }

    /** Plano pago e vigente da cobrança automática — troca vira upgrade/downgrade. */
    public static function isPaidInForce(?Subscription $subscription): bool
    {
        $cycle = $subscription?->effectiveCycle();

        return $subscription !== null
            && $subscription->billing_mode === SubscriptionBillingMode::Gateway
            && $subscription->status === SubscriptionStatus::Active
            && $subscription->hasBeenPaid()
            && blank($subscription->cancelled_reason)
            && filled($subscription->gateway)
            && ! $subscription->needsBillingReconciliation()
            && $subscription->ends_at !== null
            && $subscription->ends_at->isFuture()
            && $cycle !== null
            && $cycle->months() > 0
            && (float) $subscription->recurringAmount() > 0;
    }

    /**
     * O que acontece se a clínica trocar para o plano/ciclo: upgrade com o
     * valor proporcional cobrado agora, ou mudança agendada para a data.
     * O cliente vê isto antes de confirmar.
     *
     * @return array<string, mixed>
     */
    public function quote(Subscription $current, Plan $plan, BillingCycle $cycle, float $newAmount): array
    {
        $oldCycle  = $current->effectiveCycle();
        $oldMonths = max(1, (int) $oldCycle?->months());
        $newMonths = max(1, $cycle->months());
        $oldAmount = (float) $current->recurringAmount();
        $endsAt    = CarbonImmutable::instance($current->ends_at);
        $today     = CarbonImmutable::today();

        // Fração restante do período pago, em dias (o dia de hoje já foi usado).
        $periodStart = $endsAt->startOfDay()->subMonthsNoOverflow($oldMonths);
        $periodDays  = max(1, (int) round($periodStart->diffInDays($endsAt->startOfDay())));
        $remaining   = min($periodDays, max(0, (int) round($today->diffInDays($endsAt->startOfDay()))));
        $fraction    = $remaining / $periodDays;
        $credit      = round($oldAmount * $fraction, 2);

        $oldMonthly = $oldAmount / $oldMonths;
        $newMonthly = $newAmount / $newMonths;
        $sameCycle  = $newMonths === $oldMonths;
        $upgrade    = $newMonthly - $oldMonthly > 0.004 && $newMonths >= $oldMonths;

        $base = [
            'plan'           => ['id' => (string) $plan->id, 'name' => $plan->name],
            'cycle'          => $cycle->value,
            'new_amount'     => round($newAmount, 2),
            'current'        => ['plan_id' => (string) $current->plan_id, 'cycle' => $oldCycle?->value, 'amount' => round($oldAmount, 2), 'ends_at' => $endsAt->toIso8601String()],
            'remaining_days' => $remaining,
            'period_days'    => $periodDays,
        ];

        if ($upgrade) {
            $due       = $sameCycle ? round(($newAmount - $oldAmount) * $fraction, 2) : round($newAmount - $credit, 2);
            $periodEnd = $sameCycle ? $endsAt : $today->addMonthsNoOverflow($newMonths)->endOfDay();

            if ($due >= (float) config('billing.checkout.min_proration_amount', 5)) {
                return [
                    ...$base,
                    'type'           => self::TYPE_UPGRADE,
                    'reason'         => 'upgrade',
                    'amount_now'     => $due,
                    'credit'         => $sameCycle ? round($oldAmount * $fraction, 2) : $credit,
                    'effective_at'   => CarbonImmutable::now()->toIso8601String(),
                    'period_end'     => $periodEnd->toIso8601String(),
                    'next_charge_at' => $periodEnd->toDateString(),
                ];
            }
        }

        return [
            ...$base,
            'type'           => self::TYPE_SCHEDULED,
            'reason'         => $upgrade ? 'small_difference' : 'downgrade',
            'amount_now'     => 0.0,
            'credit'         => 0.0,
            'effective_at'   => $endsAt->toIso8601String(),
            'period_end'     => null,
            'next_charge_at' => $endsAt->toDateString(),
        ];
    }

    /**
     * Agenda a mudança para o fim do período pago (sem cobrar agora). A
     * cobrança do próximo vencimento, se já emitida com o valor antigo, é
     * refeita no novo valor; o upgrade pendente (não pago) é desfeito.
     *
     * @param array<string, mixed> $quote
     */
    public function schedule(Subscription $current, Plan $plan, BillingCycle $cycle, array $quote, string $correlationId, string $source = 'checkout', ?string $justification = null): Subscription
    {
        [$subscription, $toCancel, $reissue] = DB::transaction(function () use ($current, $plan, $cycle, $quote, $correlationId, $source, $justification): array {
            Entity::query()->whereKey($current->entity_id)->lockForUpdate()->first();
            $locked = Subscription::query()->with('plan')->whereKey($current->id)->lockForUpdate()->firstOrFail();

            $this->assertQuoteStillValid($locked, $quote);

            $effective = CarbonImmutable::instance($locked->ends_at);
            $change    = [
                'plan_id'       => (string) $plan->id,
                'plan_name'     => $plan->name,
                'billing_cycle' => $cycle->value,
                'amount'        => (float) $quote['new_amount'],
                'effective_at'  => $effective->toIso8601String(),
                'reason'        => $quote['reason'],
                'requested_at'  => now()->toIso8601String(),
                'requested_by'  => Auth::id(),
                'source'        => $source,
                'justification' => $justification,
            ];

            $payload                     = $locked->gateway_payload ?? [];
            $payload['scheduled_change'] = $change;
            $locked->update(['gateway_payload' => $payload]);

            $toCancel = $this->voidOpenPlanChanges($locked);
            $reissue  = false;

            // Cobrança do próximo vencimento já emitida no valor antigo
            // (renovação local): volta a rascunho no novo valor e é reemitida.
            $next = $locked->invoices()
                ->forBillingPeriod()
                ->whereDate('period_start', $effective->toDateString())
                ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value, InvoiceStatus::Failed->value])
                ->lockForUpdate()
                ->first();

            if ($next !== null && ! $locked->hasGatewayRecurrence()) {
                $metadata = (array) ($next->metadata ?? []);

                if (filled($next->external_invoice_id)) {
                    $toCancel[]                   = (string) $next->external_invoice_id;
                    $metadata['detached_charges'] = array_values(array_unique([...(array) ($metadata['detached_charges'] ?? []), (string) $next->external_invoice_id]));

                    Payment::query()
                        ->where('gateway_code', $locked->gateway)
                        ->where('external_payment_id', $next->external_invoice_id)
                        ->where('status', PaymentStatus::Pending->value)
                        ->update(['status' => PaymentStatus::Cancelled->value]);
                }

                $metadata['plan_change'] = [...$change, 'type' => self::TYPE_SCHEDULED];

                $next->forceFill([
                    'plan_id'              => $plan->id,
                    'amount'               => $change['amount'],
                    'period_end'           => $effective->startOfDay()->addMonthsNoOverflow($cycle->months())->toDateString(),
                    'external_invoice_id'  => null,
                    'payment_url'          => null,
                    'payment_method'       => null,
                    'payment_instructions' => null,
                    'status'               => InvoiceStatus::Draft->value,
                    'metadata'             => $metadata,
                ])->save();

                $reissue = true;
            }

            $this->record($locked, 'downgrade_scheduled', $plan, $cycle, $effective, $correlationId, ['quote' => $quote], $source, $justification);

            return [$locked->fresh('plan'), $toCancel, $reissue];
        });

        $this->cancelCharges($subscription, $toCancel, $correlationId);

        // Checkout de cartão recorrente aberto/pago com os termos atuais: a
        // recorrência dele cobraria os termos antigos depois da troca.
        app(BillingCancellationService::class)->supersedeHostedCheckouts($subscription, 'plan_changed', $correlationId, recurrentOnly: true);

        if ($subscription->hasGatewayRecurrence()) {
            // Recorrência do gateway (Asaas): a próxima cobrança dela já sai
            // no novo valor/ciclo, a partir do fim do período pago.
            RecreateGatewayRecurrenceJob::dispatch(
                (string) $subscription->id,
                (string) $subscription->gateway_subscription_id,
                CarbonImmutable::instance($subscription->ends_at)->toDateString(),
                $correlationId,
            );
        } elseif ($reissue) {
            RenewSubscriptionJob::dispatch((string) $subscription->id);
        }

        return $subscription;
    }

    /**
     * Desfaz a mudança agendada (downgrade) antes da data — pedido do
     * manager. A cobrança do próximo vencimento que já tinha saído nos
     * termos novos volta aos termos atuais (rascunho reemitido); na
     * recorrência do gateway (Asaas), ela é refeita nos termos atuais. Tarde
     * demais (a data chegou ou a cobrança nova já foi paga): 409.
     */
    public function cancelScheduled(Subscription $current, string $correlationId, string $source = 'manager', ?string $justification = null): Subscription
    {
        [$subscription, $toCancel, $reissue, $change] = DB::transaction(function () use ($current, $correlationId, $source, $justification): array {
            Entity::query()->whereKey($current->entity_id)->lockForUpdate()->first();
            $locked = Subscription::query()->with('plan')->whereKey($current->id)->lockForUpdate()->firstOrFail();
            $change = $locked->scheduledChange();

            if ($change === null || ($change['terms_applied'] ?? false)
                || CarbonImmutable::parse($change['effective_at'])->lessThanOrEqualTo(now())) {
                throw CheckoutException::make('scheduled_change_not_cancellable', 409);
            }

            $effective = CarbonImmutable::parse($change['effective_at']);
            $next      = $locked->invoices()
                ->forBillingPeriod()
                ->whereDate('period_start', $effective->toDateString())
                ->lockForUpdate()
                ->first();
            $converted = $next !== null && data_get($next->metadata, 'plan_change.type') === self::TYPE_SCHEDULED;

            if ($converted && $next->status === InvoiceStatus::Paid) {
                throw CheckoutException::make('scheduled_change_not_cancellable', 409);
            }

            $payload = $locked->gateway_payload ?? [];
            unset($payload['scheduled_change']);
            $locked->update(['gateway_payload' => $payload]);

            $toCancel = [];
            $reissue  = false;

            // Cobrança do vencimento já emitida nos termos novos: volta aos atuais.
            if ($converted && ! $locked->hasGatewayRecurrence()) {
                $metadata = (array) ($next->metadata ?? []);
                unset($metadata['plan_change']);

                if (filled($next->external_invoice_id)) {
                    $toCancel[]                   = (string) $next->external_invoice_id;
                    $metadata['detached_charges'] = array_values(array_unique([...(array) ($metadata['detached_charges'] ?? []), (string) $next->external_invoice_id]));

                    Payment::query()
                        ->where('gateway_code', $locked->gateway)
                        ->where('external_payment_id', $next->external_invoice_id)
                        ->where('status', PaymentStatus::Pending->value)
                        ->update(['status' => PaymentStatus::Cancelled->value]);
                }

                $next->forceFill([
                    'plan_id'              => $locked->plan_id,
                    'amount'               => (float) $locked->recurringAmount(),
                    'period_end'           => $locked->periodEndFrom($effective)?->toDateString() ?? $next->period_end,
                    'external_invoice_id'  => null,
                    'payment_url'          => null,
                    'payment_method'       => null,
                    'payment_instructions' => null,
                    'status'               => InvoiceStatus::Draft->value,
                    'metadata'             => $metadata !== [] ? $metadata : null,
                ])->save();

                $reissue = true;
            }

            $plan = Plan::withTrashed()->find($change['plan_id']) ?? $locked->plan;

            $this->record(
                $locked,
                'downgrade_cancelled',
                $plan,
                BillingCycle::tryFrom((string) ($change['billing_cycle'] ?? '')) ?? $locked->effectiveCycle() ?? BillingCycle::Monthly,
                CarbonImmutable::now(),
                $correlationId,
                ['cancelled_change' => $change],
                $source,
                $justification,
            );

            return [$locked->fresh('plan'), $toCancel, $reissue, $change];
        });

        $this->cancelCharges($subscription, $toCancel, $correlationId);

        app(BillingCancellationService::class)->supersedeHostedCheckouts($subscription, 'plan_change_undone', $correlationId, recurrentOnly: true);

        if ($subscription->hasGatewayRecurrence()) {
            // A recorrência já refeita nos termos novos volta aos atuais.
            RecreateGatewayRecurrenceJob::dispatch(
                (string) $subscription->id,
                (string) $subscription->gateway_subscription_id,
                CarbonImmutable::instance($subscription->ends_at)->toDateString(),
                $correlationId,
            );
        } elseif ($reissue) {
            RenewSubscriptionJob::dispatch((string) $subscription->id);
        }

        $this->billingLog->log(
            level: 'info',
            message: 'Mudança de plano agendada desfeita.',
            context: ['cancelled_change' => $change, 'source' => $source],
            entityId: (string) $subscription->entity_id,
            subscription: $subscription,
            gatewayCode: $subscription->gateway,
            correlationId: $correlationId,
        );

        return $subscription;
    }

    /**
     * Fatura da diferença proporcional do upgrade (em aberto, sem cobrança
     * ainda). A mesma troca pedida de novo reaproveita a fatura; outra troca
     * desfaz a anterior.
     *
     * @param array<string, mixed> $quote
     *
     * @return array{0: Invoice, 1: list<string>} fatura e cobranças a cancelar no gateway
     */
    public function upgradeInvoice(Subscription $current, Plan $plan, BillingCycle $cycle, array $quote, CarbonImmutable $dueDate, string $correlationId, string $source = 'checkout', ?string $justification = null): array
    {
        return DB::transaction(function () use ($current, $plan, $cycle, $quote, $dueDate, $correlationId, $source, $justification): array {
            Entity::query()->whereKey($current->entity_id)->lockForUpdate()->first();
            $locked = Subscription::query()->with('plan')->whereKey($current->id)->lockForUpdate()->firstOrFail();

            $this->assertQuoteStillValid($locked, $quote);

            $same = Invoice::query()
                ->where('subscription_id', $locked->id)
                ->where('billing_reason', Invoice::BILLING_REASON_PLAN_CHANGE)
                ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value, InvoiceStatus::Failed->value])
                ->get()
                ->first(fn (Invoice $invoice) => data_get($invoice->metadata, 'plan_change.plan_id') === (string) $plan->id
                    && data_get($invoice->metadata, 'plan_change.billing_cycle') === $cycle->value
                    && abs((float) $invoice->amount - (float) $quote['amount_now']) < 0.005
                    && self::isPlanChangeValid($locked, $invoice));

            $toCancel = $this->voidOpenPlanChanges($locked, $same?->id);

            if ($same !== null) {
                return [$same, $toCancel];
            }

            $invoice = Invoice::query()->create([
                'entity_id'       => $locked->entity_id,
                'subscription_id' => $locked->id,
                'plan_id'         => $plan->id,
                'gateway_code'    => $locked->gateway,
                'reference'       => 'UPG-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6)),
                'amount'          => $quote['amount_now'],
                'currency'        => 'BRL',
                'period_start'    => CarbonImmutable::today()->toDateString(),
                'period_end'      => CarbonImmutable::parse($quote['period_end'])->toDateString(),
                'status'          => InvoiceStatus::Pending->value,
                'due_at'          => $dueDate->endOfDay(),
                'billing_reason'  => Invoice::BILLING_REASON_PLAN_CHANGE,
                'correlation_id'  => $correlationId,
                'idempotency_key' => 'plan-change:' . $locked->id . ':' . Str::uuid(),
                'metadata'        => ['plan_change' => [
                    'type'          => self::TYPE_UPGRADE,
                    'plan_id'       => (string) $plan->id,
                    'plan_name'     => $plan->name,
                    'billing_cycle' => $cycle->value,
                    'amount'        => (float) $quote['new_amount'],
                    'amount_now'    => (float) $quote['amount_now'],
                    'credit'        => (float) $quote['credit'],
                    'period_end'    => $quote['period_end'],
                    'previous'      => $quote['current'],
                    'requested_by'  => Auth::id(),
                    'source'        => $source,
                    'justification' => $justification,
                ]],
            ]);

            // Pedido pelo manager: o histórico registra quem pediu e por quê
            // (a troca em si entra quando a fatura for paga).
            if ($source === 'manager') {
                $this->record($locked, 'upgrade_requested', $plan, $cycle, CarbonImmutable::now(), $correlationId, ['quote' => $quote, 'invoice_id' => $invoice->id], $source, $justification);
            }

            $this->billingLog->log(
                level: 'info',
                message: 'Upgrade pedido: fatura da diferença proporcional emitida (o plano muda quando ela for paga).',
                context: ['plan_id' => $plan->id, 'cycle' => $cycle->value, 'amount_now' => $quote['amount_now']],
                entityId: (string) $locked->entity_id,
                subscription: $locked,
                invoice: $invoice,
                gatewayCode: $locked->gateway,
                correlationId: $correlationId,
            );

            return [$invoice, $toCancel];
        });
    }

    /**
     * A fatura do upgrade ainda vale: a assinatura segue paga e vigente no
     * plano, ciclo e período em que o valor foi calculado.
     */
    public static function isPlanChangeValid(Subscription $subscription, Invoice $invoice): bool
    {
        $previous = (array) data_get($invoice->metadata, 'plan_change.previous', []);

        return self::isPaidInForce($subscription)
            && ($previous['plan_id'] ?? null) === (string) $subscription->plan_id
            && ($previous['cycle'] ?? null) === $subscription->effectiveCycle()?->value
            && isset($previous['ends_at'])
            && CarbonImmutable::parse($previous['ends_at'])->equalTo(CarbonImmutable::instance($subscription->ends_at));
    }

    /** Cancela no gateway (melhor esforço) as cobranças que deixaram de valer. */
    public function cancelCharges(Subscription $subscription, array $externalIds, string $correlationId): void
    {
        $externalIds = array_values(array_unique(array_filter($externalIds)));

        if ($externalIds === [] || ! $this->registry->has((string) $subscription->gateway)) {
            return;
        }

        $gateway = $this->registry->get((string) $subscription->gateway);

        foreach ($externalIds as $id) {
            if (! $gateway->cancelCharge($id)) {
                $this->billingLog->log(
                    level: 'warning',
                    message: 'Troca de plano: cobrança que deixou de valer não pôde ser cancelada no gateway — cancelar manualmente (se paga, entra como crédito a estornar).',
                    context: ['external_charge_id' => $id],
                    entityId: (string) $subscription->entity_id,
                    subscription: $subscription,
                    gatewayCode: $subscription->gateway,
                    correlationId: $correlationId,
                );
            }
        }
    }

    /**
     * Desfaz as faturas de upgrade em aberto (outra troca foi pedida, ou a
     * renovação chegou): canceladas, com as cobranças devolvidas para o
     * chamador cancelar no gateway depois do commit.
     *
     * @return list<string>
     */
    public function voidOpenPlanChanges(Subscription $subscription, ?string $except = null): array
    {
        $ids = [];

        Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('billing_reason', Invoice::BILLING_REASON_PLAN_CHANGE)
            ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Pending->value, InvoiceStatus::Overdue->value, InvoiceStatus::Failed->value])
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->lockForUpdate()
            ->get()
            ->each(function (Invoice $invoice) use (&$ids): void {
                foreach ([$invoice->external_invoice_id, ...array_column((array) ($invoice->payment_instructions ?? []), 'charge_id')] as $id) {
                    if (filled($id)) {
                        $ids[] = (string) $id;
                    }
                }

                Payment::query()
                    ->where('invoice_id', $invoice->id)
                    ->where('status', PaymentStatus::Pending->value)
                    ->update(['status' => PaymentStatus::Cancelled->value]);

                $invoice->update(['status' => InvoiceStatus::Cancelled->value]);
            });

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $quote */
    private function assertQuoteStillValid(Subscription $subscription, array $quote): void
    {
        $current = (array) ($quote['current'] ?? []);

        if (! self::isPaidInForce($subscription)
            || ($current['plan_id'] ?? null) !== (string) $subscription->plan_id
            || ($current['cycle'] ?? null) !== $subscription->effectiveCycle()?->value
            || ! CarbonImmutable::parse((string) ($current['ends_at'] ?? 'now'))->equalTo(CarbonImmutable::instance($subscription->ends_at))) {
            throw CheckoutException::make('busy', 409);
        }
    }

    /** @param array<string, mixed> $extra */
    private function record(Subscription $subscription, string $type, Plan $plan, BillingCycle $cycle, CarbonImmutable $effectiveAt, string $correlationId, array $extra = [], string $source = 'checkout', ?string $justification = null): void
    {
        SubscriptionChange::query()->create([
            'entity_id'             => $subscription->entity_id,
            'subscription_id'       => $subscription->id,
            'previous_plan_id'      => $subscription->plan_id,
            'new_plan_id'           => $plan->id,
            'previous_gateway_code' => $subscription->gateway,
            'new_gateway_code'      => $subscription->gateway,
            'change_type'           => $type,
            'changed_by'            => Auth::id(),
            'effective_at'          => $effectiveAt,
            'correlation_id'        => $correlationId,
            'metadata'              => array_filter([
                'source'      => $source,
                'reason_text' => $justification,
                'previous'    => SubscriptionManagementService::snapshot($subscription),
                'new'         => ['plan_id' => (string) $plan->id, 'billing_cycle' => $cycle->value],
                ...$extra,
            ], fn ($value) => $value !== null),
        ]);
    }
}
