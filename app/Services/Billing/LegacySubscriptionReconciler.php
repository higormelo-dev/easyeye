<?php

namespace App\Services\Billing;

use App\Contracts\Billing\QueriesGatewayRecurrences;
use App\DTOs\Billing\{GatewayCallContext, GatewayRecurrenceChargeDTO, GatewayRecurrenceDTO};
use App\Enums\Billing\{CancellationReason, InvoiceStatus};
use App\Enums\{SubscriptionBillingMode, SubscriptionStatus};
use App\Models\Billing\{Invoice, Payment, SubscriptionChange};
use App\Models\{Entity, Subscription};
use App\Support\Billing\PaymentUrl;
use Carbon\{CarbonImmutable, CarbonInterface};
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Conciliação das assinaturas de cobrança automática criadas pelo código
 * anterior (needs_billing_reconciliation) com o que o gateway de fato cobra
 * — comando billing:reconcile-legacy. Consulta a recorrência e as cobranças
 * dela quando o gateway tem API de consulta (hoje o Asaas) e classifica:
 *
 *  - recorrência ativa e em dia → ativa, com término e próximo vencimento no
 *    vencimento da próxima cobrança (fim do dia), último pagamento, ciclo e
 *    valor que o gateway cobra (inclusive a expirada só pelo job antigo);
 *  - recorrência ativa com cobrança vencida → em atraso; a régua conta a
 *    partir da conciliação (past_due_at = agora), para a clínica receber os
 *    avisos antes de qualquer bloqueio — o vencimento real fica no histórico
 *    (metadata.original_due_date) e no relatório;
 *  - recorrência ativa que nunca foi paga → contratação aguardando o 1º
 *    pagamento; com a cobrança já vencida, o prazo recomeça como numa
 *    contratação feita na conciliação (SubscriptionCycleService::firstDueDate);
 *  - linha que não é a vigente da empresa com a recorrência ativa →
 *    recorrência duplicada: revisão manual, sem ação automática;
 *  - recorrência inativa, removida ou inexistente, gateway sem API de
 *    consulta, cobrança nunca emitida (ou recorrência órfã achada pela
 *    referência), cobrança vencida com pagamento posterior, ciclo que o
 *    produto não vende → não mexe: fica para revisão manual (segue marcada).
 *
 * As cobranças em aberto viram a fatura do período (com o link de
 * pagamento), para o aviso e o "Pagar agora". Cada conciliação grava um
 * SubscriptionChange com antes/depois e tira a marcação. Sem `apply`, só
 * classifica (simulação) — nada é gravado.
 *
 * A revisão manual termina por close(): cancelar (local + recorrência no
 * gateway) ou "pago até" uma data (ativa, segue o fluxo novo).
 */
class LegacySubscriptionReconciler
{
    public const OUTCOME_ACTIVE = 'active';

    public const OUTCOME_PAST_DUE = 'past_due';

    public const OUTCOME_AWAITING = 'awaiting_payment';

    public const OUTCOME_MANUAL = 'manual';

    public const OUTCOME_ERROR = 'error';

    /** Resultados que a conciliação grava. */
    public const APPLICABLE = [self::OUTCOME_ACTIVE, self::OUTCOME_PAST_DUE, self::OUTCOME_AWAITING];

    /** Saídas da revisão manual (billing:reconcile-legacy --close=ID --action=...). */
    public const CLOSE_CANCEL = 'cancel';

    public const CLOSE_PAID_UNTIL = 'paid-until';

    public const CLOSE_ACTIONS = [self::CLOSE_CANCEL, self::CLOSE_PAID_UNTIL];

    public function __construct(
        private readonly GatewayRegistry $gatewayRegistry,
        private readonly SubscriptionCycleService $cycles,
        private readonly BillingCancellationService $cancellation,
    ) {
    }

    /**
     * Classifica (e, com `$apply`, grava) cada assinatura marcada.
     *
     * @return Collection<int, array<string, mixed>> uma linha por assinatura
     */
    public function run(bool $apply): Collection
    {
        return Subscription::query()
            ->needingBillingReconciliation()
            ->with('entity')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (Subscription $subscription) use ($apply): array {
                $row = $this->classify($subscription);

                $row['applied'] = $apply && in_array($row['outcome'], self::APPLICABLE, true)
                    && $this->apply($subscription, $row);

                return $row;
            });
    }

    /**
     * Encerra a revisão manual de uma linha com uma saída explícita:
     *
     *  - cancel: cancela localmente e para a recorrência no gateway (mesmo
     *    cancelamento resiliente do restante do billing);
     *  - paid-until: ativa, paga até o fim do dia `$until` (hoje ou depois),
     *    com o próximo vencimento nesse dia — daí em diante segue o fluxo
     *    novo (renovação, lembrete e régua). Só a vigente da empresa.
     *
     * Antes de liberar a linha sem recorrência registrada, confere no gateway
     * se ficou uma recorrência órfã criada com a referência dela. Fica no
     * histórico (antes/depois, saída e motivo).
     */
    public function close(string $subscriptionId, string $action, string $reason, ?CarbonInterface $until = null): Subscription
    {
        $reason = trim($reason);

        if (! in_array($action, self::CLOSE_ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => __('billing_console.reconcile.action_required')]);
        }

        if ($action === self::CLOSE_PAID_UNTIL && $until === null) {
            throw ValidationException::withMessages(['until' => __('billing_console.reconcile.until_required')]);
        }

        $candidate = Subscription::query()->find($subscriptionId);

        if (! $candidate || ! $candidate->needsBillingReconciliation()) {
            throw ValidationException::withMessages([
                'subscription' => __('billing_console.reconcile.not_flagged', ['id' => $subscriptionId]),
            ]);
        }

        // Consulta ao gateway fora da transação (HTTP).
        if (blank($candidate->gateway_subscription_id)) {
            $this->assertNoOrphanRecurrence($candidate);
        }

        // "Pago até" só segue a cobrança automática se há recorrência ativa
        // no gateway para cobrar depois da data; sem ela, nada seria cobrado e
        // a régua encerraria a clínica — vira cortesia até a data (D1/D3).
        $liveRecurrence = $action === self::CLOSE_PAID_UNTIL && $this->hasLiveRecurrence($candidate);

        $correlationId = (string) Str::uuid();

        [$subscription, $stopRecurrence] = DB::transaction(function () use ($candidate, $action, $reason, $until, $correlationId, $liveRecurrence): array {
            $entity       = Entity::query()->whereKey($candidate->entity_id)->lockForUpdate()->first();
            $subscription = Subscription::query()->with('plan')->whereKey($candidate->id)->lockForUpdate()->first();

            if (! $entity || ! $subscription || ! $subscription->needsBillingReconciliation()) {
                throw ValidationException::withMessages([
                    'subscription' => __('billing_console.reconcile.not_flagged', ['id' => $candidate->id]),
                ]);
            }

            $previous      = $this->snapshot($subscription);
            $hadRecurrence = $subscription->hasGatewayRecurrence();

            if ($action === self::CLOSE_CANCEL) {
                $this->cancellation->cancel(
                    subscription: $subscription,
                    entity: $entity,
                    reason: CancellationReason::AdminAction,
                    source: 'billing:reconcile-legacy',
                    notes: $reason,
                    cancelAtGateway: false,
                );
            } else {
                $this->markPaidUntil($subscription, CarbonImmutable::instance($until), $correlationId, $liveRecurrence);
            }

            $this->record($subscription, 'legacy_review_closed', $previous, mb_substr($reason, 0, 255), [
                'action'                       => $action,
                'paid_until'                   => $action === self::CLOSE_PAID_UNTIL ? $until?->toDateString() : null,
                'as_complimentary'             => $action === self::CLOSE_PAID_UNTIL && ! $liveRecurrence,
                'reason_text'                  => $reason,
                'gateway_recurrence_cancelled' => $action === self::CLOSE_CANCEL && $hadRecurrence,
            ], $correlationId);

            return [$subscription->fresh(['plan']), $action === self::CLOSE_CANCEL && $hadRecurrence];
        });

        // A recorrência para no gateway só depois do commit.
        if ($stopRecurrence) {
            $this->cancellation->cancelGatewayRecurrence($subscription, $correlationId);
        }

        return $subscription;
    }

    /**
     * "Pago até": ativa até o fim do dia informado, que vira o próximo
     * vencimento. Data passada bloquearia a clínica na hora — recusada. Linha
     * que não é a vigente da empresa não volta a valer (seriam duas).
     */
    private function markPaidUntil(Subscription $subscription, CarbonImmutable $until, string $correlationId, bool $liveRecurrence): void
    {
        $currentId = $this->currentIdOf($subscription);

        if ($currentId !== $subscription->id) {
            throw ValidationException::withMessages([
                'subscription' => __('billing_console.reconcile.not_current', ['id' => $subscription->id, 'current' => $currentId ?? '-']),
            ]);
        }

        $end = $until->endOfDay();

        if ($end->lessThan(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'until' => __('billing_console.reconcile.until_past', ['date' => $until->toDateString()]),
            ]);
        }

        if (! $liveRecurrence) {
            $subscription->update([
                'status'                       => SubscriptionStatus::Active,
                'billing_mode'                 => SubscriptionBillingMode::Complimentary,
                'billing_cycle'                => null,
                'amount'                       => null,
                'billing_state'                => null,
                'ends_at'                      => $end,
                'next_billing_at'              => null,
                'past_due_at'                  => null,
                'cancelled_at'                 => null,
                'cancelled_reason'             => null,
                'last_billing_error'           => null,
                'needs_billing_reconciliation' => false,
                'correlation_id'               => $correlationId,
            ]);

            return;
        }

        $subscription->update([
            'status'          => SubscriptionStatus::Active,
            'billing_state'   => 'paid',
            'ends_at'         => $end,
            'next_billing_at' => $end,
            'past_due_at'     => null,
            // O time confirmou o pagamento até a data: quem nunca tinha
            // pagamento registrado passa a ser pagante (régua, não 1ª cobrança).
            'last_payment_at'              => $subscription->last_payment_at ?? now(),
            'cancelled_at'                 => null,
            'cancelled_reason'             => null,
            'last_billing_error'           => null,
            'needs_billing_reconciliation' => false,
            'correlation_id'               => $correlationId,
        ]);
    }

    // ── Classificação (só leitura) ───────────────────────────────────────────

    /** @return array<string, mixed> */
    private function classify(Subscription $subscription): array
    {
        $currentId = $this->currentIdOf($subscription);

        $row = [
            'subscription' => $subscription,
            'outcome'      => self::OUTCOME_MANUAL,
            'reason'       => null,
            'detail'       => null,
            'changes'      => [],
            'open_charges' => [],
            'recurrence'   => null,
            // Vencimento real da cobrança em atraso (relatório e histórico).
            'unpaid_due' => null,
            // A vigente da empresa, quando esta linha não é ela.
            'current_id' => $currentId !== $subscription->id ? $currentId : null,
        ];

        if (blank($subscription->gateway)) {
            return $this->withOrphanCheck($subscription, [...$row, 'reason' => 'never_issued']);
        }

        try {
            $gateway = $this->gatewayRegistry->get((string) $subscription->gateway);
        } catch (Throwable) {
            return [...$row, 'reason' => 'no_query_api'];
        }

        if (! $gateway instanceof QueriesGatewayRecurrences) {
            return [...$row, 'reason' => 'no_query_api'];
        }

        if (blank($subscription->gateway_subscription_id)) {
            return $this->withOrphanCheck($subscription, [...$row, 'reason' => 'no_recurrence']);
        }

        try {
            $recurrence = $gateway->withContext(new GatewayCallContext((string) Str::uuid(), (string) $subscription->entity_id))
                ->fetchRecurrence((string) $subscription->gateway_subscription_id);
        } catch (Throwable $e) {
            return [...$row, 'outcome' => self::OUTCOME_ERROR, 'reason' => 'gateway_error', 'detail' => mb_substr($e->getMessage(), 0, 300)];
        }

        if ($recurrence === null) {
            return [...$row, 'reason' => 'recurrence_not_found'];
        }

        $row['recurrence'] = $recurrence;

        if (! $recurrence->active) {
            return [...$row, 'reason' => 'recurrence_inactive', 'detail' => $recurrence->status];
        }

        // Linha antiga (já substituída) com a recorrência viva: o cliente pode
        // estar pagando duas vezes. Nada automático — revisão manual.
        if ($row['current_id'] !== null) {
            return [...$row, 'reason' => 'duplicated_recurrence'];
        }

        if ($recurrence->cycle === null) {
            return [...$row, 'reason' => 'unsupported_cycle', 'detail' => $recurrence->rawCycle];
        }

        return $this->classifyActiveRecurrence($subscription, $recurrence, $row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function classifyActiveRecurrence(Subscription $subscription, GatewayRecurrenceDTO $recurrence, array $row): array
    {
        $charges = collect($recurrence->charges);
        $paid    = $charges->where('status', GatewayRecurrenceChargeDTO::PAID);
        $overdue = $charges->where('status', GatewayRecurrenceChargeDTO::OVERDUE)->sortBy('dueDate')->values();
        $pending = $charges->where('status', GatewayRecurrenceChargeDTO::PENDING)->sortBy('dueDate')->values();

        $lastPaidOn  = $paid->map(fn (GatewayRecurrenceChargeDTO $c) => $c->paidOn ?? $c->dueDate)->sort()->last();
        $lastPayment = $lastPaidOn !== null
            ? CarbonImmutable::parse($lastPaidOn)->startOfDay()
            : ($subscription->last_payment_at ? CarbonImmutable::instance($subscription->last_payment_at) : null);

        $terms = [
            'billing_cycle'   => $recurrence->cycle,
            'amount'          => $recurrence->amount ?? $subscription->amount,
            'last_payment_at' => $lastPayment,
        ];

        $row['open_charges'] = [...$overdue->all(), ...$pending->all()];

        $firstOverdue = $overdue->first();

        if ($firstOverdue !== null) {
            // Pagou cobrança mais nova que a vencida: o gateway pode ter só a
            // cobrança antiga esquecida — a régua encerraria quem paga.
            if ($paid->contains(fn (GatewayRecurrenceChargeDTO $c) => $c->dueDate > $firstOverdue->dueDate)) {
                return [...$row, 'outcome' => self::OUTCOME_MANUAL, 'reason' => 'overdue_with_later_payment', 'detail' => $firstOverdue->dueDate, 'open_charges' => []];
            }

            $due               = CarbonImmutable::parse($firstOverdue->dueDate)->endOfDay();
            $row['unpaid_due'] = $due;

            // Contratação nunca paga com a cobrança vencida (o código anterior
            // dava acesso): o prazo recomeça como numa contratação feita hoje —
            // acesso até o fim do dia do novo prazo, com o aviso no painel.
            if ($lastPayment === null) {
                $deadline = $this->cycles->firstDueDate()->endOfDay();

                return [...$row,
                    'outcome' => self::OUTCOME_AWAITING,
                    'reason'  => 'overdue_never_paid',
                    'changes' => [
                        ...$terms,
                        'status'          => SubscriptionStatus::PastDue,
                        'billing_state'   => 'pending_activation',
                        'past_due_at'     => null,
                        'ends_at'         => $deadline,
                        'next_billing_at' => $deadline,
                    ],
                ];
            }

            // Pagante em atraso: a régua (avisos, limitado no D+3, encerramento
            // no D+7) conta a partir de agora — nunca bloqueia sem aviso.
            return [...$row,
                'outcome' => self::OUTCOME_PAST_DUE,
                'reason'  => 'overdue',
                'changes' => [
                    ...$terms,
                    'status'          => SubscriptionStatus::PastDue,
                    'billing_state'   => 'past_due',
                    'past_due_at'     => CarbonImmutable::now(),
                    'ends_at'         => $due,
                    'next_billing_at' => $due,
                ],
            ];
        }

        $next = $pending->first()?->dueDate ?? $recurrence->nextDueDate;

        if ($next === null) {
            return [...$row, 'reason' => 'no_next_due', 'open_charges' => []];
        }

        $nextDue = CarbonImmutable::parse($next)->endOfDay();

        if ($lastPayment === null) {
            return [...$row,
                'outcome' => self::OUTCOME_AWAITING,
                'reason'  => 'awaiting_first_payment',
                'changes' => [
                    ...$terms,
                    'status'          => SubscriptionStatus::PastDue,
                    'billing_state'   => 'pending_activation',
                    'past_due_at'     => null,
                    'ends_at'         => $nextDue,
                    'next_billing_at' => $nextDue,
                ],
            ];
        }

        return [...$row,
            'outcome' => self::OUTCOME_ACTIVE,
            'reason'  => $subscription->status === SubscriptionStatus::Expired ? 'reactivated' : 'in_good_standing',
            'changes' => [
                ...$terms,
                'status'          => SubscriptionStatus::Active,
                'billing_state'   => 'paid',
                'past_due_at'     => null,
                'ends_at'         => $nextDue,
                'next_billing_at' => $nextDue,
            ],
        ];
    }

    /**
     * Linha sem recorrência registrada (cobrança nunca emitida ou renovação
     * local): o código anterior enviava externalReference = id da assinatura,
     * e uma criação cuja resposta se perdeu deixa a recorrência cobrando sem
     * o id aqui. Só leitura: achou → revisão manual com os ids.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withOrphanCheck(Subscription $subscription, array $row): array
    {
        try {
            $ids = $this->orphanRecurrenceIds($subscription);
        } catch (Throwable $e) {
            return [...$row, 'outcome' => self::OUTCOME_ERROR, 'reason' => 'gateway_error', 'detail' => mb_substr($e->getMessage(), 0, 300)];
        }

        return $ids === [] ? $row : [...$row, 'reason' => 'orphan_recurrence', 'detail' => implode(', ', $ids)];
    }

    /** Recusa liberar a linha enquanto houver (ou não der para conferir) recorrência órfã. */
    private function assertNoOrphanRecurrence(Subscription $subscription): void
    {
        try {
            $ids = $this->orphanRecurrenceIds($subscription);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'subscription' => __('billing_console.reconcile.orphan_check_failed', ['id' => $subscription->id, 'error' => mb_substr($e->getMessage(), 0, 200)]),
            ]);
        }

        if ($ids !== []) {
            throw ValidationException::withMessages([
                'subscription' => __('billing_console.reconcile.orphan_found', ['id' => $subscription->id, 'ids' => implode(', ', $ids)]),
            ]);
        }
    }

    /**
     * Recorrências (não removidas) com a referência da linha no gateway dela
     * — ou, sem gateway registrado (emissão que falhou), no fixado/padrão.
     * Gateway sem API de consulta: nada a conferir.
     *
     * @return list<string>
     */
    private function orphanRecurrenceIds(Subscription $subscription): array
    {
        $code = (string) ($subscription->gateway ?: ($subscription->pinned_gateway ?: config('billing.default_gateway')));

        if ($code === '' || ! $this->gatewayRegistry->has($code)) {
            return [];
        }

        try {
            $gateway = $this->gatewayRegistry->get($code);
        } catch (Throwable) {
            return [];
        }

        if (! $gateway instanceof QueriesGatewayRecurrences) {
            return [];
        }

        return $gateway->withContext(new GatewayCallContext((string) Str::uuid(), (string) $subscription->entity_id))
            ->findRecurrenceIdsByReference((string) $subscription->id);
    }

    /** Id da assinatura vigente da empresa (Subscription::scopeLatestPerEntity). */
    /**
     * Há recorrência ativa no gateway que vai cobrar depois do "pago até"?
     * Falha na consulta recusa o --close (rodar de novo), nunca adivinha.
     */
    private function hasLiveRecurrence(Subscription $subscription): bool
    {
        $row = $this->classify($subscription);

        if ($row['outcome'] === self::OUTCOME_ERROR) {
            throw ValidationException::withMessages([
                'subscription' => __('billing_console.reconcile.close_gateway_error', ['id' => $subscription->id]),
            ]);
        }

        if ($row['current_id'] !== null) {
            return false;
        }

        if ($row['recurrence']?->active ?? false) {
            return true;
        }

        // Renovação local (gateway sem recorrência nativa): o EasyEye emite a
        // cobrança do próximo vencimento sozinho, basta o cliente no gateway.
        if (blank($subscription->gateway) || filled($subscription->gateway_subscription_id) || blank($subscription->gateway_customer_id)) {
            return false;
        }

        try {
            return ! $this->gatewayRegistry->get((string) $subscription->gateway)->subscriptionIssuesFirstCharge();
        } catch (Throwable) {
            return false;
        }
    }

    private function currentIdOf(Subscription $subscription): ?string
    {
        $id = Subscription::query()->forEntity((string) $subscription->entity_id)->latestPerEntity()->value('id');

        return $id !== null ? (string) $id : null;
    }

    // ── Gravação ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function apply(Subscription $subscription, array $row): bool
    {
        return DB::transaction(function () use ($subscription, $row): bool {
            // Mesma ordem de travas do restante do billing: empresa, depois a assinatura.
            Entity::query()->whereKey($subscription->entity_id)->lockForUpdate()->first();

            $locked = Subscription::query()->with('plan')->whereKey($subscription->id)->lockForUpdate()->first();

            // Conciliada ou resolvida por outro caminho (ou substituída)
            // enquanto o gateway respondia.
            if (! $locked || ! $locked->needsBillingReconciliation() || ! $locked->isCurrentOfEntity()) {
                return false;
            }

            $correlationId = (string) Str::uuid();
            $previous      = $this->snapshot($locked);

            $locked->update([
                ...$row['changes'],
                'needs_billing_reconciliation' => false,
                'last_billing_error'           => null,
                'correlation_id'               => $correlationId,
            ]);

            foreach ($row['open_charges'] as $charge) {
                $this->linkInvoice($locked, $charge, $correlationId);
            }

            $recurrence = $row['recurrence'];

            // Sem motivo cru no histórico: o resultado vai em reason_key
            // (traduzido na tela) e a origem em metadata.source.
            $this->record($locked, 'legacy_reconciled', $previous, null, [
                'outcome'           => $row['outcome'],
                'reason_key'        => $row['reason'],
                'original_due_date' => $row['unpaid_due']?->toDateString(),
                'recurrence'        => $recurrence ? [
                    'id'            => $recurrence->id,
                    'status'        => $recurrence->status,
                    'cycle'         => $recurrence->rawCycle,
                    'amount'        => $recurrence->amount,
                    'next_due_date' => $recurrence->nextDueDate,
                ] : null,
            ], $correlationId);

            return true;
        });
    }

    /**
     * Fatura do período da cobrança em aberto, ligada a ela (link e
     * vencimento): a já ligada à cobrança, a do Payment que o código anterior
     * gravou para ela (sem período nem id externo na fatura) ou uma nova.
     */
    private function linkInvoice(Subscription $subscription, GatewayRecurrenceChargeDTO $charge, string $correlationId): void
    {
        $due    = CarbonImmutable::parse($charge->dueDate);
        $status = $charge->status === GatewayRecurrenceChargeDTO::OVERDUE ? InvoiceStatus::Overdue : InvoiceStatus::Pending;

        $invoice = Invoice::query()
            ->where('gateway_code', $subscription->gateway)
            ->where('external_invoice_id', $charge->id)
            ->first()
            ?? $this->legacyPaymentInvoice($subscription, $charge)
            ?? $this->cycles->periodInvoice($subscription, $due, $correlationId, $status, 'reconcile');

        if ($invoice->subscription_id !== $subscription->id) {
            return;
        }

        $unpaid = ! in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Refunded], true);

        $invoice->update(array_filter([
            'gateway_code'             => $subscription->gateway,
            'external_invoice_id'      => blank($invoice->external_invoice_id) ? $charge->id : null,
            'external_subscription_id' => $subscription->gateway_subscription_id,
            'period_start'             => blank($invoice->period_start) ? $due->toDateString() : null,
            'period_end'               => blank($invoice->period_end) ? $subscription->periodEndFrom($due)?->toDateString() : null,
            'payment_url'              => PaymentUrl::safe($charge->paymentUrl),
            'due_at'                   => $unpaid ? $due->endOfDay() : null,
            'status'                   => $unpaid ? $status->value : null,
        ], fn ($value) => $value !== null));
    }

    /** Fatura do Payment que o código anterior gravou para a cobrança (só payments.external_payment_id). */
    private function legacyPaymentInvoice(Subscription $subscription, GatewayRecurrenceChargeDTO $charge): ?Invoice
    {
        $invoiceId = Payment::query()
            ->where('subscription_id', $subscription->id)
            ->where('gateway_code', $subscription->gateway)
            ->where('external_payment_id', $charge->id)
            ->whereNotNull('invoice_id')
            ->value('invoice_id');

        return $invoiceId ? Invoice::query()->where('subscription_id', $subscription->id)->find($invoiceId) : null;
    }

    /** @return array<string, mixed> condições e datas para o histórico (antes → depois) */
    private function snapshot(Subscription $subscription): array
    {
        return [
            ...SubscriptionManagementService::snapshot($subscription),
            'next_billing_at' => $subscription->next_billing_at?->toIso8601String(),
            'last_payment_at' => $subscription->last_payment_at?->toIso8601String(),
            'past_due_at'     => $subscription->past_due_at?->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $extra
     */
    private function record(Subscription $subscription, string $type, array $previous, ?string $reason, array $extra = [], ?string $correlationId = null): void
    {
        SubscriptionChange::query()->create([
            'entity_id'        => $subscription->entity_id,
            'subscription_id'  => $subscription->id,
            'previous_plan_id' => $subscription->plan_id,
            'new_plan_id'      => $subscription->plan_id,
            'new_gateway_code' => $subscription->gateway,
            'change_type'      => $type,
            'reason'           => $reason,
            'changed_by'       => null,
            'effective_at'     => now(),
            'correlation_id'   => $correlationId ?? (string) Str::uuid(),
            'metadata'         => [
                'source'   => 'billing:reconcile-legacy',
                'previous' => $previous,
                'new'      => $this->snapshot($subscription->fresh(['plan'])),
                ...$extra,
            ],
        ]);
    }
}
